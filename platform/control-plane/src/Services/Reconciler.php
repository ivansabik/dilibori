<?php

namespace Dilibori\ControlPlane\Services;

use Dilibori\ControlPlane\Contracts\RuntimeAgentClientInterface;
use Dilibori\ControlPlane\Models\Tenant;
use Dilibori\ControlPlane\Support\Database;
use PDO;

final class Reconciler
{
    public function __construct(
        private PDO $db,
        private RuntimeAgentClientInterface $runtimeAgent,
        private SecretStore $secretStore,
        private AuditLogger $audit,
    ) {
    }

    public static function make(
        RuntimeAgentClientInterface $runtimeAgent,
        SecretStore $secretStore,
        AuditLogger $audit,
    ): self {
        return new self(Database::connection(), $runtimeAgent, $secretStore, $audit);
    }

    /** @return array{checked: int, repaired: int, drift: list<array<string, mixed>>} */
    public function reconcile(?string $actor = null): array
    {
        $statement = $this->db->query(
            "SELECT d.id, d.tenant_id, d.desired_release, d.config_checksum, d.status, d.runtime_refs,
                    d.hostname, d.environment, t.noindex AS tenant_noindex
             FROM deployments d
             JOIN tenants t ON t.id = d.tenant_id
             WHERE d.status IN ('active', 'health_check', 'configuring_route')",
        );

        $deployments = $statement->fetchAll();
        $repaired = 0;
        $drift = [];

        foreach ($deployments as $deployment) {
            if ($this->ensureRedisAcl($deployment, $actor)) {
                $repaired++;
            }

            if ($this->ensureIngressNoindex($deployment, $actor)) {
                $repaired++;
            }

            $observed = $this->runtimeAgent->observedState($deployment['id']);
            if (($observed['ok'] ?? true) === false) {
                $drift[] = [
                    'deployment_id' => $deployment['id'],
                    'issue' => 'runtime_unreachable',
                    'detail' => $observed['error'] ?? 'unknown',
                ];

                continue;
            }

            $issues = $this->detectDrift($deployment, $observed);
            if ($issues === []) {
                continue;
            }

            foreach ($issues as $issue) {
                $drift[] = array_merge(['deployment_id' => $deployment['id']], $issue);
                if ($this->repairSafe($deployment, $issue, $actor)) {
                    $repaired++;
                }
            }
        }

        $this->audit->log('reconcile.completed', null, null, null, [
            'checked' => count($deployments),
            'repaired' => $repaired,
            'drift_count' => count($drift),
        ], $actor);

        return [
            'checked' => count($deployments),
            'repaired' => $repaired,
            'drift' => $drift,
        ];
    }

    /** @param array<string, mixed> $deployment */
    private function ensureRedisAcl(array $deployment, ?string $actor): bool
    {
        try {
            $payload = [
                'REDIS_USERNAME' => $this->secretStore->decrypt($deployment['id'], 'REDIS_USERNAME'),
                'REDIS_PASSWORD' => $this->secretStore->decrypt($deployment['id'], 'REDIS_PASSWORD'),
                'REDIS_PREFIX' => $this->secretStore->decrypt($deployment['id'], 'REDIS_PREFIX'),
            ];
        } catch (\Throwable) {
            return false;
        }

        $response = $this->runtimeAgent->enqueue('ensure_redis_acl', $deployment['id'], $payload);
        if (($response['ok'] ?? true) === false) {
            $this->audit->log(
                'reconcile.redis_acl_failed',
                $deployment['tenant_id'],
                $deployment['id'],
                (string) ($response['error'] ?? 'ensure_redis_acl failed'),
                [],
                $actor,
            );

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $deployment */
    private function ensureIngressNoindex(array $deployment, ?string $actor): bool
    {
        $hostname = trim((string) ($deployment['hostname'] ?? ''));
        if ($hostname === '') {
            return false;
        }

        $response = $this->runtimeAgent->enqueue('configure_route', $deployment['id'], [
            'hostname' => $hostname,
            'environment' => (string) ($deployment['environment'] ?? ''),
            'noindex' => Tenant::asBool($deployment['tenant_noindex'] ?? false),
        ]);
        if (($response['ok'] ?? true) === false) {
            $this->audit->log(
                'reconcile.ingress_noindex_failed',
                $deployment['tenant_id'],
                $deployment['id'],
                (string) ($response['error'] ?? 'configure_route failed'),
                [],
                $actor,
            );

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $deployment
     * @param array<string, mixed> $observed
     * @return list<array<string, mixed>>
     */
    private function detectDrift(array $deployment, array $observed): array
    {
        $issues = [];

        $desiredRelease = $deployment['desired_release'];
        $runningRelease = $observed['release_id'] ?? null;
        if ($desiredRelease && $runningRelease && $desiredRelease !== $runningRelease) {
            $issues[] = [
                'issue' => 'release_mismatch',
                'desired' => $desiredRelease,
                'observed' => $runningRelease,
            ];
        }

        $desiredChecksum = $deployment['config_checksum'];
        $observedChecksum = $observed['config_checksum'] ?? null;
        if ($desiredChecksum && $observedChecksum && $desiredChecksum !== $observedChecksum) {
            $issues[] = [
                'issue' => 'config_checksum_mismatch',
                'desired' => $desiredChecksum,
                'observed' => $observedChecksum,
            ];
        }

        $desiredStatus = $deployment['status'];
        $observedStatus = $observed['status'] ?? null;
        if ($observedStatus === 'unhealthy' && $desiredStatus === 'active') {
            $issues[] = ['issue' => 'unhealthy_runtime'];
        }

        return $issues;
    }

    /** @param array<string, mixed> $deployment
     * @param array<string, mixed> $issue
     */
    private function repairSafe(array $deployment, array $issue, ?string $actor): bool
    {
        $repairable = ['config_checksum_mismatch', 'release_mismatch'];
        if (!in_array($issue['issue'], $repairable, true)) {
            return false;
        }

        $action = $issue['issue'] === 'release_mismatch' ? 'deploy_release' : 'apply_config';
        $payload = $issue['issue'] === 'release_mismatch'
            ? ['release_id' => $deployment['desired_release']]
            : ['config_checksum' => $deployment['config_checksum']];

        $response = $this->runtimeAgent->enqueue($action, $deployment['id'], $payload);
        if (($response['ok'] ?? true) === false) {
            return false;
        }

        $this->audit->log(
            'reconcile.repair',
            $deployment['tenant_id'],
            $deployment['id'],
            (string) $issue['issue'],
            $issue,
            $actor,
        );

        return true;
    }
}
