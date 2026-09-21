<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Request;
use Dilibori\ControlPlane\Http\Response;
use Dilibori\ControlPlane\Models\Deployment;
use Dilibori\ControlPlane\Models\Tenant;
use Dilibori\ControlPlane\Support\Uuid;
use PDO;

final class DeploymentController
{
    private PDO $db;

    public function __construct(private Application $app)
    {
        $this->db = $app->db();
    }

    public function storeForTenant(Request $request): Response
    {
        $tenantId = $request->routeParams['id'];
        $tenant = $this->findTenant($tenantId);
        if ($tenant === null) {
            return Response::error('Tenant not found', 404);
        }

        $environment = (string) $request->input('environment', 'development');
        if (!in_array($environment, ['development', 'staging', 'production'], true)) {
            return Response::error('Invalid environment');
        }

        $hostname = trim((string) $request->input('hostname', ''));
        $seedBundle = trim((string) $request->input('seed_bundle', 'default'));

        if ($hostname === '') {
            $hostname = sprintf('%s-%s.%s.local', $tenant['slug'], $environment, 'dilibori');
        }

        // Reuse the live row for this tenant+env so re-runs do not stack actives.
        $existing = $this->findLiveDeployment($tenant['id'], $environment);
        if ($existing !== null) {
            $hostnameChanged = (string) ($existing['hostname'] ?? '') !== $hostname;
            $statement = $this->db->prepare(
                'UPDATE deployments
                 SET hostname = :hostname,
                     seed_bundle = :seed_bundle,
                     updated_at = NOW()
                 WHERE id = :id
                 RETURNING *',
            );
            $statement->execute([
                'hostname' => $hostname,
                'seed_bundle' => $seedBundle !== '' ? $seedBundle : ($existing['seed_bundle'] ?? 'default'),
                'id' => $existing['id'],
            ]);
            $row = $statement->fetch();
            $deployment = Deployment::toPublic(Deployment::fromRow($row));
            $this->app->audit()->log('deployment.reused', $tenant['id'], $deployment['id'], null, [
                'environment' => $environment,
                'hostname' => $hostname,
            ]);

            if ($hostnameChanged && in_array((string) ($row['status'] ?? ''), ['active', 'health_check', 'configuring_route'], true)) {
                $this->app->runtimeAgent()->enqueue('configure_route', $deployment['id'], [
                    'hostname' => $hostname,
                    'environment' => $environment,
                    'noindex' => Tenant::asBool($tenant['noindex']),
                ]);
            }

            return Response::json(['deployment' => $deployment, 'reused' => true]);
        }

        $cell = $this->pickCell();
        $deploymentId = $this->allocateUniqueDeploymentId();
        $databaseName = 'ti_' . str_replace('-', '_', $tenant['slug']) . '_' . substr(str_replace('-', '', $deploymentId), 0, 8);

        try {
            $statement = $this->db->prepare(
                'INSERT INTO deployments (
                    id, tenant_id, environment, cell_id, hostname, database_name, seed_bundle, status
                 ) VALUES (
                    :id, :tenant_id, :environment, :cell_id, :hostname, :database_name, :seed_bundle, :status
                 ) RETURNING *',
            );
            $statement->execute([
                'id' => $deploymentId,
                'tenant_id' => $tenant['id'],
                'environment' => $environment,
                'cell_id' => $cell['id'] ?? null,
                'hostname' => $hostname,
                'database_name' => $databaseName,
                'seed_bundle' => $seedBundle,
                'status' => 'reserved',
            ]);
        } catch (\PDOException $exception) {
            if (str_contains($exception->getMessage(), 'deployments_one_live_per_tenant_env')) {
                return Response::error('A live deployment already exists for this tenant and environment', 409);
            }

            throw $exception;
        }

        $deployment = Deployment::toPublic(Deployment::fromRow($statement->fetch()));
        $this->app->audit()->log('deployment.created', $tenant['id'], $deployment['id'], null, [
            'environment' => $environment,
            'hostname' => $hostname,
        ]);

        return Response::json(['deployment' => $deployment], 201);
    }

    public function show(Request $request): Response
    {
        $deployment = $this->findDeployment($request->routeParams['id']);
        if ($deployment === null) {
            return Response::error('Deployment not found', 404);
        }

        return Response::json(['deployment' => $deployment]);
    }

    public function status(Request $request): Response
    {
        $deployment = $this->findDeploymentRow($request->routeParams['id']);
        if ($deployment === null) {
            return Response::error('Deployment not found', 404);
        }

        $runtime = $this->app->runtimeAgent()->observedState($deployment['id']);
        $secretsMeta = $this->app->secretStore()->listMeta($deployment['id']);

        return Response::json([
            'deployment' => Deployment::toPublic($deployment),
            'runtime' => $runtime,
            'secrets_meta' => $secretsMeta,
        ]);
    }

    public function provision(Request $request): Response
    {
        return $this->wrapAction($request, function (array $deployment) use ($request) {
            $result = $this->app->provisioning()->start($deployment['id'], $this->actor($request));

            return Response::json(['deployment' => $result]);
        });
    }

    public function suspend(Request $request): Response
    {
        return $this->mutateLifecycle($request, 'suspend', 'pending_suspend', 'suspended');
    }

    public function resume(Request $request): Response
    {
        return $this->mutateLifecycle($request, 'resume', 'none', 'active');
    }

    public function deploy(Request $request): Response
    {
        return $this->wrapAction($request, function (array $deployment) use ($request) {
            $releaseId = (string) $request->input('release_id', '');
            if ($releaseId === '') {
                return Response::error('release_id is required');
            }

            if (!$this->releaseExists($releaseId)) {
                return Response::error('Release not found', 404);
            }

            $statement = $this->db->prepare(
                'UPDATE deployments SET desired_release = :release_id, updated_at = NOW() WHERE id = :id',
            );
            $statement->execute(['release_id' => $releaseId, 'id' => $deployment['id']]);

            $response = $this->app->runtimeAgent()->enqueue('deploy_release', $deployment['id'], [
                'release_id' => $releaseId,
            ]);

            $this->app->audit()->log('deployment.deploy', $deployment['tenant_id'], $deployment['id'], null, [
                'release_id' => $releaseId,
                'runtime' => $response,
            ], $this->actor($request));

            return Response::json([
                'deployment' => $this->findDeployment($deployment['id']),
                'runtime' => $response,
            ]);
        });
    }

    public function rollback(Request $request): Response
    {
        return $this->wrapAction($request, function (array $deployment) use ($request) {
            $response = $this->app->runtimeAgent()->enqueue('rollback_release', $deployment['id']);
            $this->app->audit()->log('deployment.rollback', $deployment['tenant_id'], $deployment['id'], null, [
                'runtime' => $response,
            ], $this->actor($request));

            return Response::json(['deployment' => $this->findDeployment($deployment['id']), 'runtime' => $response]);
        });
    }

    public function backup(Request $request): Response
    {
        return $this->wrapAction($request, function (array $deployment) use ($request) {
            $this->setBackupState($deployment['id'], 'in_progress');
            $response = $this->app->runtimeAgent()->enqueue('backup', $deployment['id']);
            $state = ($response['ok'] ?? true) === false ? 'failed' : 'completed';
            $this->setBackupState($deployment['id'], $state);

            $this->app->audit()->log('deployment.backup', $deployment['tenant_id'], $deployment['id'], null, [
                'state' => $state,
            ], $this->actor($request));

            return Response::json([
                'deployment' => $this->findDeployment($deployment['id']),
                'runtime' => $response,
            ]);
        });
    }

    public function restore(Request $request): Response
    {
        return $this->wrapAction($request, function (array $deployment) use ($request) {
            $snapshotId = $request->input('snapshot_id');
            $response = $this->app->runtimeAgent()->enqueue('restore', $deployment['id'], [
                'snapshot_id' => $snapshotId,
            ]);

            $this->app->audit()->log('deployment.restore', $deployment['tenant_id'], $deployment['id'], null, [
                'snapshot_id' => $snapshotId,
                'runtime' => $response,
            ], $this->actor($request));

            return Response::json(['deployment' => $this->findDeployment($deployment['id']), 'runtime' => $response]);
        });
    }

    public function supportAccess(Request $request): Response
    {
        return $this->wrapAction($request, function (array $deployment) use ($request) {
            $reason = trim((string) $request->input('reason', ''));
            $ttl = (int) $request->input('ttl_seconds', 3600);

            if ($reason === '') {
                return Response::error('reason is required');
            }

            if ($ttl < 60 || $ttl > 86400) {
                return Response::error('ttl_seconds must be between 60 and 86400');
            }

            $actor = $this->actor($request);
            $id = Uuid::v4();
            $statement = $this->db->prepare(
                'INSERT INTO support_access (id, deployment_id, actor, reason, expires_at)
                 VALUES (:id, :deployment_id, :actor, :reason, NOW() + (:ttl || \' seconds\')::interval)
                 RETURNING id, deployment_id, actor, reason, expires_at, created_at',
            );
            $statement->execute([
                'id' => $id,
                'deployment_id' => $deployment['id'],
                'actor' => $actor,
                'reason' => $reason,
                'ttl' => (string) $ttl,
            ]);

            $grant = $statement->fetch();
            $runtime = $this->app->runtimeAgent()->enqueue('support_access', $deployment['id'], [
                'grant_id' => $grant['id'],
                'expires_at' => $grant['expires_at'],
                'actor' => $actor,
            ]);

            $this->app->audit()->log(
                'deployment.support_access',
                $deployment['tenant_id'],
                $deployment['id'],
                $reason,
                ['grant_id' => $grant['id'], 'ttl_seconds' => $ttl],
                $actor,
            );

            return Response::json(['grant' => $grant, 'runtime' => $runtime], 201);
        });
    }

    /** @param callable(array<string, mixed>): Response $callback */
    private function wrapAction(Request $request, callable $callback): Response
    {
        $deployment = $this->findDeploymentRow($request->routeParams['id']);
        if ($deployment === null) {
            return Response::error('Deployment not found', 404);
        }

        try {
            return $callback($deployment);
        } catch (\Throwable $exception) {
            return Response::error($exception->getMessage(), 500);
        }
    }

    private function mutateLifecycle(Request $request, string $action, string $suspensionState, string $status): Response
    {
        return $this->wrapAction($request, function (array $deployment) use ($request, $action, $suspensionState, $status) {
            $statement = $this->db->prepare(
                'UPDATE deployments
                 SET suspension_state = :suspension_state, status = :status, updated_at = NOW()
                 WHERE id = :id',
            );
            $statement->execute([
                'suspension_state' => $suspensionState,
                'status' => $status,
                'id' => $deployment['id'],
            ]);

            $runtime = $this->app->runtimeAgent()->enqueue($action, $deployment['id']);
            $this->app->audit()->log(
                'deployment.' . $action,
                $deployment['tenant_id'],
                $deployment['id'],
                null,
                ['runtime' => $runtime],
                $this->actor($request),
            );

            return Response::json([
                'deployment' => $this->findDeployment($deployment['id']),
                'runtime' => $runtime,
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    private function findLiveDeployment(string $tenantId, string $environment): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM deployments
             WHERE tenant_id = :tenant_id
               AND environment = :environment
               AND status NOT IN ('failed', 'suspended')
             ORDER BY updated_at DESC
             LIMIT 1",
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'environment' => $environment,
        ]);
        $row = $statement->fetch();

        return $row ? Deployment::fromRow($row) : null;
    }

    /** @return array<string, mixed>|null */
    private function findDeployment(string $id): ?array
    {
        $row = $this->findDeploymentRow($id);

        return $row ? Deployment::toPublic($row) : null;
    }

    /** @return array<string, mixed>|null */
    private function findDeploymentRow(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM deployments WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row ? Deployment::fromRow($row) : null;
    }

    /** @return array<string, mixed>|null */
    private function findTenant(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, slug, name, noindex FROM tenants WHERE id = :id OR slug = :slug');
        $statement->execute(['id' => $id, 'slug' => $id]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function pickCell(): ?array
    {
        $row = $this->db->query('SELECT id, name, provider FROM cells ORDER BY created_at ASC LIMIT 1')->fetch();

        return $row ?: null;
    }

    /**
     * Mint a deployment UUID whose 12-char short form (alphanumeric, no hyphens)
     * does not collide with an existing deployment. Short ids name containers,
     * networks, DB users, Redis ACL users, and MinIO keys and cannot be renamed.
     */
    private function allocateUniqueDeploymentId(): string
    {
        $statement = $this->db->prepare(
            "SELECT 1 FROM deployments WHERE left(replace(id::text, '-', ''), 12) = :short LIMIT 1",
        );

        for ($attempt = 0; $attempt < 16; $attempt++) {
            $id = Uuid::v4();
            $short = substr(str_replace('-', '', $id), 0, 12);
            $statement->execute(['short' => $short]);
            if (!$statement->fetchColumn()) {
                return $id;
            }
        }

        throw new \RuntimeException('Could not allocate a unique deployment short id');
    }

    private function releaseExists(string $releaseId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM releases WHERE id = :id');
        $statement->execute(['id' => $releaseId]);

        return (bool) $statement->fetchColumn();
    }

    private function setBackupState(string $deploymentId, string $state): void
    {
        $statement = $this->db->prepare(
            'UPDATE deployments SET backup_state = :state, updated_at = NOW() WHERE id = :id',
        );
        $statement->execute(['state' => $state, 'id' => $deploymentId]);
    }

    private function actor(Request $request): string
    {
        $header = $_SERVER['HTTP_X_ACTOR'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return (string) $this->app->config()['default_actor'];
    }
}
