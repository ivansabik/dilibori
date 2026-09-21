<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Response;
use Dilibori\ControlPlane\Models\Deployment;

final class AdminController
{
    public function __construct(private Application $app)
    {
    }

    public function dashboard(): Response
    {
        $db = $this->app->db();
        $tenants = $db->query(
            'SELECT t.id, t.slug, t.name, t.noindex, t.created_at,
                    COUNT(d.id) AS deployment_count
             FROM tenants t
             LEFT JOIN deployments d ON d.tenant_id = t.id
             GROUP BY t.id
             ORDER BY t.created_at DESC',
        )->fetchAll();

        $deployments = $db->query(
            "SELECT d.*, t.slug AS tenant_slug, t.name AS tenant_name
             FROM deployments d
             JOIN tenants t ON t.id = d.tenant_id
             ORDER BY
                CASE d.status
                    WHEN 'active' THEN 0
                    WHEN 'failed' THEN 1
                    WHEN 'suspended' THEN 2
                    ELSE 3
                END,
                d.updated_at DESC
             LIMIT 50",
        )->fetchAll();

        $normalized = [];
        foreach ($deployments as $row) {
            $public = Deployment::toPublic(Deployment::fromRow($row));
            $public['tenant_slug'] = (string) ($row['tenant_slug'] ?? '');
            $normalized[] = $public;
        }
        $deployments = $normalized;

        $releases = $db->query(
            'SELECT id, image_tag, image_digest, created_at FROM releases ORDER BY created_at DESC LIMIT 10',
        )->fetchAll();

        $html = $this->render($tenants, $deployments, $releases);

        return Response::html($html);
    }

    /** @param list<array<string, mixed>> $tenants
     * @param list<array<string, mixed>> $deployments
     * @param list<array<string, mixed>> $releases
     */
    private function render(array $tenants, array $deployments, array $releases): string
    {
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Control plane</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; line-height: 1.5; color: #1a1a1a; }
        h1, h2 { margin-top: 2rem; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 1.5rem; }
        th, td { border: 1px solid #ddd; padding: 0.5rem 0.75rem; text-align: left; vertical-align: top; }
        th { background: #f5f5f5; }
        form { display: grid; gap: 0.5rem; max-width: 32rem; margin-bottom: 2rem; padding: 1rem; border: 1px solid #ddd; }
        label { font-weight: 600; }
        input, select, textarea, button { font: inherit; padding: 0.4rem 0.6rem; }
        button { cursor: pointer; width: fit-content; }
        .muted { color: #666; font-size: 0.9rem; }
        code { font-size: 0.85rem; }
        .actions form { display: inline-grid; border: none; padding: 0; margin: 0; max-width: none; }
        .actions button { font-size: 0.85rem; }
    </style>
</head>
<body>
    <h1>Control plane</h1>
    <p class="muted">Pilot admin for tenants and deployments. JSON API lives under the same host.</p>

    <h2>Create tenant</h2>
    <form id="create-tenant">
        <label for="tenant-slug">Slug</label>
        <input id="tenant-slug" name="slug" required pattern="[a-z0-9-]+" placeholder="el-taconazo">

        <label for="tenant-name">Name</label>
        <input id="tenant-name" name="name" required placeholder="El Taconazo">

        <label>
            <input type="checkbox" name="noindex" value="1">
            Block search indexing (noindex)
        </label>

        <button type="submit">Create tenant</button>
    </form>

    <h2>Tenants</h2>
    <table>
        <thead>
            <tr><th>Slug</th><th>Name</th><th>Noindex</th><th>Deployments</th><th>Created</th><th>ID</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($tenants as $tenant): ?>
            <?php
            $isNoindex = in_array(
                strtolower((string) ($tenant['noindex'] ?? '')),
                ['1', 't', 'true', 'yes', 'on'],
                true,
            ) || ($tenant['noindex'] ?? false) === true;
            ?>
            <tr>
                <td><?= htmlspecialchars((string) $tenant['slug']) ?></td>
                <td><?= htmlspecialchars((string) $tenant['name']) ?></td>
                <td><?= $isNoindex ? 'yes' : 'no' ?></td>
                <td><?= (int) $tenant['deployment_count'] ?></td>
                <td><?= htmlspecialchars((string) $tenant['created_at']) ?></td>
                <td><code><?= htmlspecialchars((string) $tenant['id']) ?></code></td>
                <td class="actions">
                    <button
                        type="button"
                        data-tenant-noindex="<?= htmlspecialchars((string) $tenant['id']) ?>"
                        data-noindex="<?= $isNoindex ? '0' : '1' ?>"
                    ><?= $isNoindex ? 'Allow indexing' : 'Block indexing' ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($tenants === []): ?>
            <tr><td colspan="7" class="muted">No tenants yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2>Create deployment</h2>
    <form id="create-deployment">
        <label for="deployment-tenant">Tenant ID</label>
        <input id="deployment-tenant" name="tenant_id" required placeholder="tenant uuid">

        <label for="deployment-env">Environment</label>
        <select id="deployment-env" name="environment">
            <option value="development">development</option>
            <option value="staging">staging</option>
            <option value="production">production</option>
        </select>

        <label for="deployment-hostname">Hostname</label>
        <input id="deployment-hostname" name="hostname" placeholder="optional">

        <label for="deployment-seed">Seed bundle</label>
        <input id="deployment-seed" name="seed_bundle" value="default">

        <button type="submit">Create deployment</button>
    </form>

    <h2>Deployments</h2>
    <table>
        <thead>
            <tr>
                <th>Tenant</th><th>Env</th><th>Hostname</th><th>Status</th><th>Updated</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($deployments as $deployment): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($deployment['tenant_slug'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) $deployment['environment']) ?></td>
                <td><?= htmlspecialchars((string) ($deployment['hostname'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) $deployment['status']) ?></td>
                <td><?= htmlspecialchars((string) $deployment['updated_at']) ?></td>
                <td class="actions">
                    <button type="button" data-action="provision" data-id="<?= htmlspecialchars($deployment['id']) ?>">Provision</button>
                    <button type="button" data-action="suspend" data-id="<?= htmlspecialchars($deployment['id']) ?>">Suspend</button>
                    <button type="button" data-action="resume" data-id="<?= htmlspecialchars($deployment['id']) ?>">Resume</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($deployments === []): ?>
            <tr><td colspan="6" class="muted">No deployments yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2>Recent releases</h2>
    <table>
        <thead><tr><th>Tag</th><th>Digest</th><th>Created</th><th>ID</th></tr></thead>
        <tbody>
        <?php foreach ($releases as $release): ?>
            <tr>
                <td><?= htmlspecialchars((string) $release['image_tag']) ?></td>
                <td><code><?= htmlspecialchars(substr((string) $release['image_digest'], 0, 19)) ?>…</code></td>
                <td><?= htmlspecialchars((string) $release['created_at']) ?></td>
                <td><code><?= htmlspecialchars((string) $release['id']) ?></code></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($releases === []): ?>
            <tr><td colspan="4" class="muted">No releases registered.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <script>
        async function sendJson(method, url, body) {
            const response = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-Actor': 'admin-ui' },
                body: body ? JSON.stringify(body) : '{}',
            });
            const data = await response.json();
            if (!response.ok) {
                alert(data.error || 'Request failed');
                return;
            }
            location.reload();
        }

        async function postJson(url, body) {
            return sendJson('POST', url, body);
        }

        document.getElementById('create-tenant').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.target;
            postJson('/tenants', {
                slug: form.slug.value,
                name: form.name.value,
                noindex: form.noindex.checked,
            });
        });

        document.getElementById('create-deployment').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.target;
            postJson('/tenants/' + encodeURIComponent(form.tenant_id.value) + '/deployments', {
                environment: form.environment.value,
                hostname: form.hostname.value || undefined,
                seed_bundle: form.seed_bundle.value,
            });
        });

        document.querySelectorAll('[data-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.id;
                const action = button.dataset.action;
                postJson('/deployments/' + encodeURIComponent(id) + '/' + action);
            });
        });

        document.querySelectorAll('[data-tenant-noindex]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.tenantNoindex;
                const noindex = button.dataset.noindex === '1';
                sendJson('PATCH', '/tenants/' + encodeURIComponent(id), { noindex });
            });
        });
    </script>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }
}
