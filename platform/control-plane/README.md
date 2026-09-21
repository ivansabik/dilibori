# Control plane

Small PHP service that tracks tenants, deployments, and releases for a multi-tenant TastyIgniter proof of concept. It stores desired state in PostgreSQL and hands off work to a separate runtime agent over HTTP.

No Laravel install. Plain PHP 8.2+, PDO, and a PSR-4 autoloader in `bootstrap.php`. Zero Composer dependencies at runtime.

## Quick start

```bash
# Schema
psql "$DATABASE_URL" -f database/schema.sql
psql "$DATABASE_URL" -f database/seed.sql

# Local profiles (Docker uses /opt/platform/profiles)
export PROFILES_PATH="$(pwd)/config/profiles"
export APP_SECRET_KEY="$(openssl rand -base64 32)"
export DATABASE_URL=postgres://postgres:postgres@127.0.0.1:5432/control_plane
export RUNTIME_AGENT_URL=http://127.0.0.1:8100
export PROFILES_PATH="$(pwd)/../profiles"

php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/` for the admin UI, or `GET /health` for a JSON probe.

Preferred path: `make -C platform up` (control plane on host port `8099`, agent on `8100`).

### Docker

```bash
docker build -t dilibori-control-plane .
docker run --rm -p 8080:8080 \
  -e DATABASE_URL=postgres://postgres:postgres@host.docker.internal:5432/control_plane \
  -e APP_SECRET_KEY=your-32-byte-secret-key-here-minimum \
  -e RUNTIME_AGENT_URL=http://host.docker.internal:8100 \
  -e RUNTIME_AGENT_TOKEN=platform-agent-token-change-me \
  -v "$(pwd)/../profiles:/opt/platform/profiles:ro" \
  dilibori-control-plane
```

For php-fpm, point the vhost document root at `public/` and route all requests to `index.php`.

## Environment

| Variable | Purpose |
| --- | --- |
| `DATABASE_URL` | PostgreSQL DSN (preferred) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Fallback when `DATABASE_URL` is unset |
| `APP_SECRET_KEY` | AES-256-GCM key for on-disk secrets (32+ bytes) |
| `RUNTIME_AGENT_URL` | Base URL for the runtime agent |
| `RUNTIME_AGENT_TOKEN` | Optional bearer token sent to the agent |
| `PROFILES_PATH` | Directory with `{environment}.json` config profiles |
| `SECRETS_PATH` | Root for encrypted secret files (default `/var/lib/dilibori/secrets`) |
| `DEFAULT_ACTOR` | Audit log actor when `X-Actor` header is missing |

## API

All responses are JSON unless noted. Pass `X-Actor: your-name` on mutating calls for audit attribution.

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/` | HTML admin dashboard |
| GET | `/health` | Liveness + DB check |
| GET | `/tenants` | List tenants (includes `noindex`) |
| POST | `/tenants` | Body: `{ "slug", "name", "noindex?" }` (`noindex` defaults false; blocks production indexing; development and staging are always blocked at ingress) |
| GET | `/tenants/{id}` | Tenant by UUID or slug, includes deployments |
| PATCH | `/tenants/{id}` | Body: `{ "noindex": true or false }`; rewrites Caddy routes for live deployments (effective noindex also depends on deployment environment) |
| POST | `/tenants/{id}/deployments` | Body: `{ "environment", "seed_bundle", "hostname?" }`. Reuses the live row for that tenant+environment when one already exists. |

| GET | `/deployments/{id}` | Deployment record |
| GET | `/deployments/{id}/status` | Desired state + runtime observation + secret metadata (no values) |
| POST | `/deployments/{id}/provision` | Run provisioning state machine |
| POST | `/deployments/{id}/suspend` | Suspend workload |
| POST | `/deployments/{id}/resume` | Resume workload |
| POST | `/deployments/{id}/deploy` | Body: `{ "release_id" }` |
| POST | `/deployments/{id}/rollback` | Roll back to previous release |
| POST | `/deployments/{id}/backup` | Trigger backup |
| POST | `/deployments/{id}/restore` | Body: `{ "snapshot_id?" }` |
| POST | `/deployments/{id}/support-access` | Body: `{ "reason", "ttl_seconds" }` |
| POST | `/reconcile` | Compare desired vs observed, repair safe drift |
| GET | `/cells` | List capacity cells |
| GET | `/releases` | List image releases |
| POST | `/releases` | Body: `{ "image_digest", "image_tag", "notes?" }` |

Example:

```bash
curl -s localhost:8080/tenants -H 'Content-Type: application/json' \
  -d '{"slug":"demo","name":"Demo Taqueria"}'

curl -s localhost:8080/tenants/demo/deployments -H 'Content-Type: application/json' \
  -d '{"environment":"development","seed_bundle":"taconazo"}'

curl -s -X POST localhost:8080/deployments/{id}/provision
```

## Runtime agent contract

The control plane does not run containers or databases itself. It calls the runtime agent at `RUNTIME_AGENT_URL` with a bearer token when configured.

### Enqueue task

```
POST {RUNTIME_AGENT_URL}/deployments/{deployment_id}/tasks
Authorization: Bearer {RUNTIME_AGENT_TOKEN}
Content-Type: application/json

{
  "action": "create_database",
  "payload": { ... }
}
```

Actions the provisioner emits in order:

1. `create_database`
2. `create_secrets`
3. `install_release`
4. `create_runtime`
5. `configure_route`
6. `health_check`

Lifecycle actions: `suspend`, `resume`, `deploy_release`, `rollback_release`, `backup`, `restore`, `support_access`, `apply_config`.

The payload includes resolved config (non-secret), checksum, hostname, database name, seed bundle, and desired release when set.

### Read observed state

```
GET {RUNTIME_AGENT_URL}/deployments/{deployment_id}/state
```

Expected shape (agent-defined, reconciler reads these keys):

```json
{
  "release_id": "uuid",
  "config_checksum": "sha256hex",
  "status": "running"
}
```

### Health

```
GET {RUNTIME_AGENT_URL}/deployments/{deployment_id}/health
```

Provisioner requires `{ "healthy": true }` before marking a deployment `active`.

If the agent is down, enqueue calls return `{ "ok": false, "error": "runtime agent unreachable" }` and provisioning moves to `failed`.

## Internals

### Provisioning state machine

States persisted on `deployments.status`:

`reserved` → `creating_db` → `creating_secrets` → `installing` → `creating_runtime` → `configuring_route` → `health_check` → `active`

Failures land in `failed`. Each step enqueues the matching runtime action, then advances synchronously (PoC simplicity).

At `creating_secrets`, the plane generates `DB_PASSWORD` and `APP_KEY`, encrypts them with `APP_SECRET_KEY`, and writes files under `SECRETS_PATH/{deployment_id}/`. Only metadata (`secrets_meta`) is exposed via API.

### Config resolver

Merge order:

1. Application defaults
2. Profile file `{PROFILES_PATH}/{environment}.json`
3. Cell defaults (from `cells.capacity_metadata` when present)
4. Allowlisted tenant overrides (`STORE_NAME`, `STORE_URL`, `DEFAULT_LOCALE`, `TIMEZONE`)

Production rejects `APP_DEBUG=true` and similar flags. Output includes a SHA-256 checksum stored on the deployment.

### Reconciler

`POST /reconcile` loads active deployments and:

1. Re-applies each deployment's Redis ACL user from stored secrets (covers Redis restarts before `ACL SAVE` lands on disk).
2. Fetches observed state from the agent and flags drift (release mismatch, config checksum mismatch, unhealthy runtime).

Safe repairs enqueue `deploy_release` or `apply_config`. Unhealthy or unreachable runtimes are reported but not auto-healed. `make -C platform up` calls reconcile after compose is up.

At most one live deployment exists per tenant and environment (`failed` / `suspended` excluded). Becoming `active` suspends any siblings in that pair. `make pilots` reuses those rows instead of creating duplicates.

### Audit log

Mutations write to `audit_events` with actor, action, optional reason, and JSON payload. No secret values are logged.

## Layout

```
public/index.php          Front controller + router
src/Http/Controllers/     JSON and admin handlers
src/Services/             Provisioning, config, secrets, reconcile
database/schema.sql       PostgreSQL DDL
config/profiles/          Example environment profiles
```

UUIDs are v4 from `random_bytes()` (no Ramsey dependency).
