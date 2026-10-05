<?php

namespace Dilibori\ControlPlane\Services;

use Dilibori\ControlPlane\Contracts\RuntimeAgentClientInterface;
use Dilibori\ControlPlane\Models\Cell;
use Dilibori\ControlPlane\Models\Deployment;
use Dilibori\ControlPlane\Models\Tenant;
use Dilibori\ControlPlane\Support\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MigrationStateMachine
{
    /** @param callable(?string): RuntimeAgentClientInterface $agentResolver */
    public function __construct(
        private PDO $db,
        private $agentResolver,
        private SecretStore $secretStore,
        private ConfigResolver $configResolver,
        private AuditLogger $audit,
    ) {
    }

    /** @param callable(?string): RuntimeAgentClientInterface $agentResolver */
    public static function make(
        callable $agentResolver,
        SecretStore $secretStore,
        ConfigResolver $configResolver,
        AuditLogger $audit,
    ): self {
        return new self(Database::connection(), $agentResolver, $secretStore, $configResolver, $audit);
    }

    private function agent(?string $cellId): RuntimeAgentClientInterface
    {
        return ($this->agentResolver)($cellId);
    }

    /** @return array<string, mixed> */
    public function migrate(string $deploymentId, string $targetCellId, ?string $actor = null): array
    {
        $deployment = $this->findDeployment($deploymentId);
        $status = (string) $deployment['status'];
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException("Deployment must be active or suspended to migrate (got {$status})");
        }

        $sourceCellId = (string) ($deployment['cell_id'] ?? '');
        if ($sourceCellId === '') {
            throw new InvalidArgumentException('Deployment has no source cell');
        }
        if ($sourceCellId === $targetCellId) {
            throw new InvalidArgumentException('target_cell_id must differ from the current cell');
        }

        $sourceCell = $this->findCell($sourceCellId);
        $targetCell = $this->findCell($targetCellId);
        if ($sourceCell === null) {
            throw new RuntimeException('Source cell not found');
        }
        if ($targetCell === null) {
            throw new RuntimeException('Target cell not found');
        }
        if (!Cell::asBool($targetCell['schedulable'] ?? false)) {
            throw new RuntimeException('Target cell is not schedulable');
        }
        if (!$this->cellHasCapacity($targetCellId, excludeDeploymentId: $deploymentId)) {
            throw new RuntimeException('Target cell is at capacity');
        }

        $sourceAgent = $this->agent($sourceCellId);
        $destAgent = $this->agent($targetCellId);
        $destProbe = $destAgent->observedState($deploymentId);
        // Dest may report unreachable container (ok:false) or 404-ish; only reject total agent down.
        if (($destProbe['error'] ?? '') === 'runtime agent unreachable') {
            throw new RuntimeException('Target cell runtime agent unreachable');
        }

        $previousStatus = $status;
        $this->setStatus($deploymentId, 'migrating');
        $this->setMigrateRefs($deploymentId, [
            'source_cell_id' => $sourceCellId,
            'target_cell_id' => $targetCellId,
            'phase' => 'started',
            'previous_status' => $previousStatus,
        ]);
        $this->audit->log('deployment.migrate.started', $deployment['tenant_id'], $deploymentId, null, [
            'source_cell_id' => $sourceCellId,
            'target_cell_id' => $targetCellId,
        ], $actor);

        $snapshotFile = null;
        $destPrepared = false;

        try {
            $secrets = $this->loadSecrets($deploymentId);
            $basePayload = $this->basePayload($deployment, $secrets);

            $this->setMigratePhase($deploymentId, 'stopping_source');
            $this->enqueueOk($sourceAgent, 'stop', $deploymentId, $basePayload, tolerateErrors: true);

            $this->setMigratePhase($deploymentId, 'exporting');
            $export = $this->enqueueOk($sourceAgent, 'export_snapshot', $deploymentId, $basePayload);
            $snapshotFile = (string) ($export['snapshot_file'] ?? '');
            if ($snapshotFile === '') {
                throw new RuntimeException('export_snapshot did not return snapshot_file');
            }
            $this->setMigrateRefs($deploymentId, [
                'source_cell_id' => $sourceCellId,
                'target_cell_id' => $targetCellId,
                'phase' => 'exported',
                'snapshot_file' => $snapshotFile,
                'previous_status' => $previousStatus,
            ]);

            $this->setMigratePhase($deploymentId, 'removing_source_runtime');
            $this->enqueueOk($sourceAgent, 'remove_runtime', $deploymentId, $basePayload);

            $this->setMigratePhase($deploymentId, 'preparing_dest');
            $createDb = $basePayload;
            $createDb['db_password'] = $secrets['DB_PASSWORD'] ?? null;
            $this->enqueueOk($destAgent, 'create_database', $deploymentId, $createDb);
            $this->enqueueOk($destAgent, 'create_secrets', $deploymentId, $basePayload);
            $destPrepared = true;

            $sourceUrl = rtrim((string) ($sourceCell['agent_url'] ?? ''), '/');
            if ($sourceUrl === '') {
                throw new RuntimeException('Source cell has no agent_url');
            }
            $token = (string) ($this->configToken());
            $importPayload = array_merge($basePayload, [
                'source_url' => "{$sourceUrl}/tenants/{$deploymentId}/snapshot?file=" . rawurlencode($snapshotFile),
                'source_token' => $token,
            ]);
            $this->setMigratePhase($deploymentId, 'importing');
            $this->enqueueOk($destAgent, 'import_snapshot', $deploymentId, $importPayload);

            $this->setMigratePhase($deploymentId, 'starting_dest');
            $this->enqueueOk($destAgent, 'create_runtime', $deploymentId, $basePayload);
            $this->enqueueOk($destAgent, 'configure_route', $deploymentId, $basePayload);
            $health = $destAgent->health($deploymentId);
            if (($health['healthy'] ?? false) !== true) {
                throw new RuntimeException('Destination health check failed after migrate');
            }

            $this->setMigratePhase($deploymentId, 'cutover');
            $this->cutover($deploymentId, $targetCellId);

            $purgePayload = array_merge($basePayload, ['remove_runtime' => false]);
            $this->enqueueOk($sourceAgent, 'purge', $deploymentId, $purgePayload, tolerateErrors: true);

            $this->audit->log('deployment.migrate.completed', $deployment['tenant_id'], $deploymentId, null, [
                'source_cell_id' => $sourceCellId,
                'target_cell_id' => $targetCellId,
                'snapshot_file' => $snapshotFile,
            ], $actor);

            return Deployment::toPublic($this->findDeployment($deploymentId));
        } catch (\Throwable $exception) {
            $this->audit->log(
                'deployment.migrate.failed',
                $deployment['tenant_id'],
                $deploymentId,
                $exception->getMessage(),
                [
                    'source_cell_id' => $sourceCellId,
                    'target_cell_id' => $targetCellId,
                    'snapshot_file' => $snapshotFile,
                ],
                $actor,
            );

            $this->rollback(
                $deploymentId,
                $deployment,
                $sourceCellId,
                $targetCellId,
                $previousStatus,
                $destPrepared,
                $actor,
            );

            throw $exception;
        }
    }

    /**
     * Mark source unschedulable and migrate each live deployment to target.
     *
     * @return array{cell_id: string, schedulable: bool, results: list<array<string, mixed>>}
     */
    public function drain(string $sourceCellId, string $targetCellId, ?string $actor = null): array
    {
        $source = $this->findCell($sourceCellId);
        if ($source === null) {
            throw new RuntimeException('Source cell not found');
        }
        $target = $this->findCell($targetCellId);
        if ($target === null) {
            throw new RuntimeException('Target cell not found');
        }
        if ($source['id'] === $target['id']) {
            throw new InvalidArgumentException('target_cell_id must differ from the drained cell');
        }

        $statement = $this->db->prepare(
            'UPDATE cells SET schedulable = FALSE WHERE id = :id RETURNING id, schedulable',
        );
        $statement->execute(['id' => $source['id']]);
        $updated = $statement->fetch();

        $this->audit->log('cell.drain.started', null, null, null, [
            'source_cell_id' => $source['id'],
            'target_cell_id' => $target['id'],
        ], $actor);

        $live = $this->db->prepare(
            "SELECT id FROM deployments
             WHERE cell_id = :cell_id
               AND status IN ('active', 'suspended')
             ORDER BY created_at ASC",
        );
        $live->execute(['cell_id' => $source['id']]);
        $deploymentIds = array_column($live->fetchAll(), 'id');

        $results = [];
        foreach ($deploymentIds as $deploymentId) {
            try {
                $deployment = $this->migrate($deploymentId, $target['id'], $actor);
                $results[] = [
                    'deployment_id' => $deploymentId,
                    'ok' => true,
                    'status' => $deployment['status'] ?? 'active',
                    'cell_id' => $deployment['cell_id'] ?? $target['id'],
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'deployment_id' => $deploymentId,
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $this->audit->log('cell.drain.completed', null, null, null, [
            'source_cell_id' => $source['id'],
            'target_cell_id' => $target['id'],
            'results' => $results,
        ], $actor);

        return [
            'cell_id' => $source['id'],
            'schedulable' => Cell::asBool($updated['schedulable'] ?? false),
            'target_cell_id' => $target['id'],
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $deployment */
    private function rollback(
        string $deploymentId,
        array $deployment,
        string $sourceCellId,
        string $targetCellId,
        string $previousStatus,
        bool $destPrepared,
        ?string $actor,
    ): void {
        $secrets = $this->loadSecrets($deploymentId);
        $basePayload = $this->basePayload($deployment, $secrets);
        $sourceAgent = $this->agent($sourceCellId);
        $destAgent = $this->agent($targetCellId);

        if ($destPrepared) {
            $this->enqueueOk(
                $destAgent,
                'purge',
                $deploymentId,
                array_merge($basePayload, ['remove_runtime' => true]),
                tolerateErrors: true,
            );
        }

        try {
            $createDb = $basePayload;
            $createDb['db_password'] = $secrets['DB_PASSWORD'] ?? null;
            // Source DB/users/bind mounts were left intact; only recreate network + container + route.
            $this->enqueueOk($sourceAgent, 'create_database', $deploymentId, $createDb, tolerateErrors: true);
            $this->enqueueOk($sourceAgent, 'create_runtime', $deploymentId, $basePayload);
            $this->enqueueOk($sourceAgent, 'configure_route', $deploymentId, $basePayload);
            $this->setStatus($deploymentId, $previousStatus === 'suspended' ? 'suspended' : 'active');
            $this->clearMigrateRefs($deploymentId);
        } catch (\Throwable $rollbackError) {
            $this->setStatus($deploymentId, 'failed');
            $this->audit->log(
                'deployment.migrate.rollback_failed',
                $deployment['tenant_id'],
                $deploymentId,
                $rollbackError->getMessage(),
                [],
                $actor,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function enqueueOk(
        RuntimeAgentClientInterface $agent,
        string $action,
        string $deploymentId,
        array $payload,
        bool $tolerateErrors = false,
    ): array {
        $response = $agent->enqueue($action, $deploymentId, $payload);
        if (($response['ok'] ?? true) === false) {
            if ($tolerateErrors) {
                return $response;
            }
            throw new RuntimeException((string) ($response['error'] ?? "{$action} failed"));
        }

        return $response;
    }

    /** @param array<string, mixed> $deployment
     * @param array<string, string> $secrets
     * @return array<string, mixed>
     */
    private function basePayload(array $deployment, array $secrets): array
    {
        $cellCapacity = $deployment['cell_capacity'] ?? '{}';
        if (is_array($cellCapacity)) {
            $cellDefaults = $cellCapacity;
        } else {
            $cellDefaults = json_decode((string) $cellCapacity, true) ?: [];
        }

        $config = $this->configResolver->resolve(
            $deployment['environment'],
            $cellDefaults,
            [],
            (int) $deployment['config_version'],
        );

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

        foreach ($secrets as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function loadSecrets(string $deploymentId): array
    {
        $keys = [
            'DB_PASSWORD',
            'APP_KEY',
            'ADMIN_PASSWORD',
            'MINIO_ACCESS_KEY',
            'MINIO_SECRET_KEY',
            'MINIO_PREFIX',
        ];
        $out = [];
        foreach ($keys as $key) {
            try {
                $out[$key] = $this->secretStore->decrypt($deploymentId, $key);
            } catch (\Throwable) {
                // optional
            }
        }

        return $out;
    }

    private function cutover(string $deploymentId, string $targetCellId): void
    {
        $statement = $this->db->prepare(
            "UPDATE deployments
             SET cell_id = :cell_id,
                 status = 'active',
                 suspension_state = 'none',
                 runtime_refs = COALESCE(runtime_refs, '{}'::jsonb) - 'migrate',
                 updated_at = NOW()
             WHERE id = :id",
        );
        $statement->execute([
            'cell_id' => $targetCellId,
            'id' => $deploymentId,
        ]);
    }

    /** @param array<string, mixed> $refs */
    private function setMigrateRefs(string $deploymentId, array $refs): void
    {
        $statement = $this->db->prepare(
            "UPDATE deployments
             SET runtime_refs = jsonb_set(
                 COALESCE(runtime_refs, '{}'::jsonb),
                 '{migrate}',
                 :migrate::jsonb,
                 true
             ),
             updated_at = NOW()
             WHERE id = :id",
        );
        $statement->execute([
            'migrate' => json_encode($refs, JSON_THROW_ON_ERROR),
            'id' => $deploymentId,
        ]);
    }

    private function setMigratePhase(string $deploymentId, string $phase): void
    {
        $statement = $this->db->prepare(
            "UPDATE deployments
             SET runtime_refs = jsonb_set(
                 COALESCE(runtime_refs, '{}'::jsonb),
                 '{migrate,phase}',
                 to_jsonb(:phase::text),
                 true
             ),
             updated_at = NOW()
             WHERE id = :id",
        );
        $statement->execute(['phase' => $phase, 'id' => $deploymentId]);
    }

    private function clearMigrateRefs(string $deploymentId): void
    {
        $statement = $this->db->prepare(
            "UPDATE deployments
             SET runtime_refs = COALESCE(runtime_refs, '{}'::jsonb) - 'migrate',
                 updated_at = NOW()
             WHERE id = :id",
        );
        $statement->execute(['id' => $deploymentId]);
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

    private function cellHasCapacity(string $cellId, ?string $excludeDeploymentId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT capacity_metadata FROM cells WHERE id = :id',
        );
        $statement->execute(['id' => $cellId]);
        $row = $statement->fetch();
        if (!$row) {
            return false;
        }
        $meta = is_string($row['capacity_metadata'])
            ? json_decode($row['capacity_metadata'], true)
            : ($row['capacity_metadata'] ?? []);
        $max = (int) ($meta['max_deployments'] ?? 16);
        if ($max <= 0) {
            $max = 16;
        }

        $countSql = "SELECT COUNT(*) FROM deployments
                     WHERE cell_id = :cell_id
                       AND status NOT IN ('failed', 'suspended')";
        $params = ['cell_id' => $cellId];
        if ($excludeDeploymentId !== null) {
            $countSql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeDeploymentId;
        }
        $count = $this->db->prepare($countSql);
        $count->execute($params);

        return (int) $count->fetchColumn() < $max;
    }

    /** @return array<string, mixed> */
    private function findDeployment(string $deploymentId): array
    {
        $statement = $this->db->prepare(
            'SELECT d.*, c.capacity_metadata AS cell_capacity, c.agent_url AS cell_agent_url,
                    t.noindex AS tenant_noindex
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

    /** @return array<string, mixed>|null */
    private function findCell(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, provider, capacity_metadata, agent_url, schedulable, created_at
             FROM cells WHERE id = :id OR name = :name',
        );
        $statement->execute(['id' => $id, 'name' => $id]);
        $row = $statement->fetch();

        return $row ? Cell::fromRow($row) : null;
    }

    private function configToken(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return (string) ($config['runtime_agent_token'] ?? '');
    }
}
