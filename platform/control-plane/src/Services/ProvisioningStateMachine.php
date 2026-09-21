<?php

namespace Dilibori\ControlPlane\Services;

use Dilibori\ControlPlane\Contracts\RuntimeAgentClientInterface;
use Dilibori\ControlPlane\Models\Deployment;
use Dilibori\ControlPlane\Models\Tenant;
use Dilibori\ControlPlane\Support\Database;
use PDO;
use RuntimeException;

final class ProvisioningStateMachine
{
    /** @var list<string> */
    private const FLOW = [
        'reserved',
        'creating_db',
        'creating_secrets',
        'installing',
        'creating_runtime',
        'configuring_route',
        'health_check',
        'active',
    ];

    /** @var array<string, string> */
    private const RUNTIME_ACTIONS = [
        'creating_db' => 'create_database',
        'creating_secrets' => 'create_secrets',
        'installing' => 'install_release',
        'creating_runtime' => 'create_runtime',
        'configuring_route' => 'configure_route',
        'health_check' => 'health_check',
    ];

    public function __construct(
        private PDO $db,
        private RuntimeAgentClientInterface $runtimeAgent,
        private SecretStore $secretStore,
        private ConfigResolver $configResolver,
        private AuditLogger $audit,
    ) {
    }

    public static function make(
        RuntimeAgentClientInterface $runtimeAgent,
        SecretStore $secretStore,
        ConfigResolver $configResolver,
        AuditLogger $audit,
    ): self {
        return new self(Database::connection(), $runtimeAgent, $secretStore, $configResolver, $audit);
    }

    /** @return array<string, mixed> */
    public function start(string $deploymentId, ?string $actor = null): array
    {
        $deployment = $this->findDeployment($deploymentId);
        if ($deployment['status'] !== 'reserved' && $deployment['status'] !== 'failed') {
            throw new RuntimeException('Deployment is not eligible for provisioning');
        }

        $this->setStatus($deploymentId, 'creating_db');
        $this->audit->log('deployment.provision.started', $deployment['tenant_id'], $deploymentId, null, [], $actor);

        return $this->advance($deploymentId, $actor);
    }

    /** @return array<string, mixed> */
    public function advance(string $deploymentId, ?string $actor = null): array
    {
        $deployment = $this->findDeployment($deploymentId);
        $status = $deployment['status'];

        if ($status === 'active' || $status === 'failed' || $status === 'suspended') {
            return Deployment::toPublic($deployment);
        }

        try {
            if ($status === 'creating_secrets') {
                $this->bootstrapSecrets($deployment);
            }

            $config = $this->configResolver->resolve(
                $deployment['environment'],
                json_decode($deployment['cell_capacity'] ?? '{}', true) ?: [],
                [],
                (int) $deployment['config_version'],
            );

            $this->updateConfig($deploymentId, $config['checksum'], $config['version']);

            if (isset(self::RUNTIME_ACTIONS[$status])) {
                $action = self::RUNTIME_ACTIONS[$status];
                $payload = [
                    'environment' => $deployment['environment'],
                    'hostname' => $deployment['hostname'],
                    'database_name' => $deployment['database_name'],
                    'seed_bundle' => $deployment['seed_bundle'],
                    'desired_release' => $deployment['desired_release'],
                    'tenant_id' => $deployment['tenant_id'],
                    'cell_id' => $deployment['cell_id'],
                    'config' => $config['effective'],
                    'config_checksum' => $config['checksum'],
                    'noindex' => Tenant::asBool($deployment['tenant_noindex'] ?? false),
                ];

                foreach (['DB_PASSWORD', 'APP_KEY', 'ADMIN_PASSWORD', 'REDIS_PASSWORD', 'REDIS_PREFIX', 'REDIS_USERNAME'] as $secretKey) {
                    try {
                        $payload[$secretKey] = $this->secretStore->decrypt($deploymentId, $secretKey);
                    } catch (\Throwable) {
                        // optional until created
                    }
                }

                $response = $this->runtimeAgent->enqueue($action, $deploymentId, $payload);

                if (($response['ok'] ?? true) === false) {
                    throw new RuntimeException((string) ($response['error'] ?? $response['detail'] ?? 'runtime agent rejected task'));
                }

                // Persist credentials returned by create_database / create_secrets
                if ($action === 'create_database' && !empty($response['password'])) {
                    $this->secretStore->store($deploymentId, 'DB_PASSWORD', (string) $response['password']);
                    if (!empty($response['database_name'])) {
                        $stmt = $this->db->prepare('UPDATE deployments SET database_name = :db, updated_at = NOW() WHERE id = :id');
                        $stmt->execute(['db' => $response['database_name'], 'id' => $deploymentId]);
                    }
                }
                if ($action === 'create_secrets') {
                    foreach ([
                        'redis_password' => 'REDIS_PASSWORD',
                        'redis_prefix' => 'REDIS_PREFIX',
                        'redis_user' => 'REDIS_USERNAME',
                        'minio_access_key' => 'MINIO_ACCESS_KEY',
                        'minio_secret_key' => 'MINIO_SECRET_KEY',
                        'minio_prefix' => 'MINIO_PREFIX',
                    ] as $from => $to) {
                        if (!empty($response[$from])) {
                            $this->secretStore->store($deploymentId, $to, (string) $response[$from]);
                        }
                    }
                }
            }

            $next = $this->nextStatus($status);
            if ($next === null) {
                throw new RuntimeException("Unknown status: {$status}");
            }

            if ($next === 'active' && $status === 'health_check') {
                $health = $this->runtimeAgent->health($deploymentId);
                if (($health['healthy'] ?? false) !== true) {
                    throw new RuntimeException('Health check failed');
                }
            }

            $this->setStatus($deploymentId, $next);
            $this->audit->log(
                'deployment.provision.step',
                $deployment['tenant_id'],
                $deploymentId,
                null,
                ['from' => $status, 'to' => $next],
                $actor,
            );

            if ($next === 'active') {
                $this->supersedeSiblingDeployments($deployment);
            }

            if ($next !== 'active') {
                return $this->advance($deploymentId, $actor);
            }

            $this->audit->log('deployment.provision.completed', $deployment['tenant_id'], $deploymentId, null, [], $actor);
        } catch (\Throwable $exception) {
            $this->setStatus($deploymentId, 'failed');
            $this->audit->log(
                'deployment.provision.failed',
                $deployment['tenant_id'],
                $deploymentId,
                $exception->getMessage(),
                ['status' => $status],
                $actor,
            );

            throw $exception;
        }

        return Deployment::toPublic($this->findDeployment($deploymentId));
    }

    /** @param array<string, mixed> $deployment */
    private function bootstrapSecrets(array $deployment): void
    {
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $adminPassword = bin2hex(random_bytes(12));

        $this->secretStore->store($deployment['id'], 'APP_KEY', $appKey);
        $this->secretStore->store($deployment['id'], 'ADMIN_PASSWORD', $adminPassword);
        // DB_PASSWORD is created during create_database and stored from the agent response.
    }

    private function nextStatus(string $current): ?string
    {
        $index = array_search($current, self::FLOW, true);
        if ($index === false || !isset(self::FLOW[$index + 1])) {
            return null;
        }

        return self::FLOW[$index + 1];
    }

    /** @param array<string, mixed> $deployment */
    private function supersedeSiblingDeployments(array $deployment): void
    {
        $statement = $this->db->prepare(
            "UPDATE deployments
             SET status = 'suspended',
                 suspension_state = 'suspended',
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id
               AND environment = :environment
               AND id <> :id
               AND status NOT IN ('failed', 'suspended')",
        );
        $statement->execute([
            'tenant_id' => $deployment['tenant_id'],
            'environment' => $deployment['environment'],
            'id' => $deployment['id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function findDeployment(string $deploymentId): array
    {
        $statement = $this->db->prepare(
            'SELECT d.*, c.capacity_metadata AS cell_capacity, t.noindex AS tenant_noindex
             FROM deployments d
             LEFT JOIN cells c ON c.id = d.cell_id
             JOIN tenants t ON t.id = d.tenant_id
             WHERE d.id = :id',
        );
        $statement->execute(['id' => $deploymentId]);
        $row = $statement->fetch();

        if (!$row) {
            throw new RuntimeException('Deployment not found');
        }

        return Deployment::fromRow($row);
    }

    private function setStatus(string $deploymentId, string $status): void
    {
        if (!in_array($status, Deployment::STATUSES, true)) {
            throw new RuntimeException("Invalid status: {$status}");
        }

        $statement = $this->db->prepare(
            'UPDATE deployments SET status = :status, updated_at = NOW() WHERE id = :id',
        );
        $statement->execute(['status' => $status, 'id' => $deploymentId]);
    }

    private function updateConfig(string $deploymentId, string $checksum, int $version): void
    {
        $statement = $this->db->prepare(
            'UPDATE deployments SET config_checksum = :checksum, config_version = :version, updated_at = NOW() WHERE id = :id',
        );
        $statement->execute([
            'checksum' => $checksum,
            'version' => $version,
            'id' => $deploymentId,
        ]);
    }
}
