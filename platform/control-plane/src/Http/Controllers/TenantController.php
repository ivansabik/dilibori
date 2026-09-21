<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Request;
use Dilibori\ControlPlane\Http\Response;
use Dilibori\ControlPlane\Models\Tenant;
use Dilibori\ControlPlane\Support\Uuid;
use PDO;

final class TenantController
{
    private PDO $db;

    public function __construct(private Application $app)
    {
        $this->db = $app->db();
    }

    public function index(): Response
    {
        $rows = $this->db->query(
            'SELECT id, slug, name, noindex, created_at FROM tenants ORDER BY created_at DESC',
        )->fetchAll();

        return Response::json([
            'tenants' => array_map(static fn (array $row) => Tenant::toPublic($row), $rows),
        ]);
    }

    public function show(Request $request): Response
    {
        $tenant = $this->findTenant($request->routeParams['id']);
        if ($tenant === null) {
            return Response::error('Tenant not found', 404);
        }

        $deployments = $this->db->prepare(
            'SELECT id, environment, hostname, status, created_at FROM deployments WHERE tenant_id = :tenant_id ORDER BY created_at DESC',
        );
        $deployments->execute(['tenant_id' => $tenant['id']]);

        return Response::json([
            'tenant' => Tenant::toPublic($tenant),
            'deployments' => $deployments->fetchAll(),
        ]);
    }

    public function store(Request $request): Response
    {
        $slug = trim((string) $request->input('slug', ''));
        $name = trim((string) $request->input('name', ''));
        $noindex = Tenant::asBool($request->input('noindex', false));

        if ($slug === '' || $name === '') {
            return Response::error('slug and name are required');
        }

        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return Response::error('slug must be lowercase alphanumeric with hyphens');
        }

        $id = Uuid::v4();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO tenants (id, slug, name, noindex)
                 VALUES (:id, :slug, :name, :noindex)
                 RETURNING id, slug, name, noindex, created_at',
            );
            $statement->execute([
                'id' => $id,
                'slug' => $slug,
                'name' => $name,
                'noindex' => $noindex ? 't' : 'f',
            ]);
            $tenant = $statement->fetch();
        } catch (\PDOException $exception) {
            if (str_contains($exception->getMessage(), 'unique')) {
                return Response::error('Tenant slug already exists', 409);
            }

            throw $exception;
        }

        $this->app->audit()->log('tenant.created', $tenant['id'], null, null, [
            'slug' => $slug,
            'noindex' => $noindex,
        ]);

        return Response::json(['tenant' => Tenant::toPublic($tenant)], 201);
    }

    public function update(Request $request): Response
    {
        $tenant = $this->findTenant($request->routeParams['id']);
        if ($tenant === null) {
            return Response::error('Tenant not found', 404);
        }

        if (!is_array($request->json) || $request->json === []) {
            return Response::error('JSON body required');
        }

        if (!array_key_exists('noindex', $request->json)
            && !array_key_exists('slug', $request->json)
            && !array_key_exists('name', $request->json)
        ) {
            return Response::error('noindex, slug, or name is required');
        }

        $noindex = array_key_exists('noindex', $request->json)
            ? Tenant::asBool($request->json['noindex'])
            : Tenant::asBool($tenant['noindex']);

        $slug = array_key_exists('slug', $request->json)
            ? trim((string) $request->json['slug'])
            : (string) $tenant['slug'];
        $name = array_key_exists('name', $request->json)
            ? trim((string) $request->json['name'])
            : (string) $tenant['name'];

        if ($slug === '' || $name === '') {
            return Response::error('slug and name cannot be empty');
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return Response::error('slug must be lowercase alphanumeric with hyphens');
        }

        try {
            $statement = $this->db->prepare(
                'UPDATE tenants SET slug = :slug, name = :name, noindex = :noindex WHERE id = :id
                 RETURNING id, slug, name, noindex, created_at',
            );
            $statement->execute([
                'id' => $tenant['id'],
                'slug' => $slug,
                'name' => $name,
                'noindex' => $noindex ? 't' : 'f',
            ]);
            $updated = $statement->fetch();
        } catch (\PDOException $exception) {
            if (str_contains($exception->getMessage(), 'unique')) {
                return Response::error('Tenant slug already exists', 409);
            }

            throw $exception;
        }

        $this->app->audit()->log('tenant.updated', $updated['id'], null, null, [
            'slug' => $slug,
            'name' => $name,
            'noindex' => $noindex,
        ]);

        $reapplied = $this->reapplyIngressRoutes($updated['id'], $noindex);

        return Response::json([
            'tenant' => Tenant::toPublic($updated),
            'routes_reapplied' => $reapplied,
        ]);
    }

    /**
     * Live deployments pick up the new noindex without a full re-provision.
     *
     * @return list<array{deployment_id: string, hostname: string, ok: bool}>
     */
    private function reapplyIngressRoutes(string $tenantId, bool $noindex): array
    {
        $statement = $this->db->prepare(
            "SELECT id, hostname, environment, status FROM deployments
             WHERE tenant_id = :tenant_id
               AND status IN ('active', 'health_check', 'configuring_route')
               AND hostname IS NOT NULL
               AND hostname <> ''",
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $deployments = $statement->fetchAll();

        $results = [];
        foreach ($deployments as $deployment) {
            $response = $this->app->runtimeAgent()->enqueue('configure_route', $deployment['id'], [
                'hostname' => $deployment['hostname'],
                'environment' => $deployment['environment'],
                'noindex' => $noindex,
            ]);
            $results[] = [
                'deployment_id' => $deployment['id'],
                'hostname' => $deployment['hostname'],
                'ok' => ($response['ok'] ?? true) !== false,
            ];
        }

        return $results;
    }

    /** @return array<string, mixed>|null */
    private function findTenant(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, slug, name, noindex, created_at FROM tenants
             WHERE id::text = :id OR slug = :slug',
        );
        $statement->execute(['id' => $id, 'slug' => $id]);

        $row = $statement->fetch();

        return $row ?: null;
    }
}
