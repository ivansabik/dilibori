# Runtime agent

Privileged sidecar for the Dilibori multi-tenant TastyIgniter PoC. The control plane calls this HTTP API; only the runtime agent mounts the Docker socket and runs containers.

## What it does

- Creates per-deployment Docker networks and tenant containers
- Provisions MariaDB databases, Redis ACL users, and MinIO prefix policies
- Writes Caddy tenant snippets under `DATA_DIR/ingress/tenants/`
- Runs mysqldump backups to `DATA_DIR/backups/{deployment_id}/`
- Exposes observe and capacity endpoints for the control plane

Every request needs `Authorization: Bearer $RUNTIME_AGENT_TOKEN`.

## Layout

```text
platform/runtime-agent/
  app/
    main.py
    config.py
    auth.py
    routers/      # HTTP routes
    services/     # Docker, MariaDB, Redis, MinIO, Caddy, backups
    models/       # Pydantic request bodies
  Dockerfile
  requirements.txt
```

Tenant containers are named `dilibori-tenant-{deployment_id_short}`. Each tenant
gets its own bridge (`dilibori-net-{short}` in `10.200.0.0/16`). Ingress, ProxySQL,
Redis, MinIO, and Mailpit are attached to that bridge with DNS aliases. Tenants
never join `dilibori-shared` with each other, and never attach to the control-plane
network. Failed installs remove the bridge so the address pool does not leak.

## Security model

The agent rejects anything outside fixed allowlists:

- **Images:** `ALLOWED_IMAGES` plus `TENANT_IMAGE`
- **Env keys:** Laravel/TastyIgniter keys only; `APP_DEBUG` blocked in `production`
- **Networks:** `dilibori-net-{deployment_id_short}` pattern only
- **Commands:** install uses fixed `["provision"]` (image entrypoint; no arbitrary shell)
- **Mounts and caps:** defined by the agent; callers cannot pass host paths, capabilities, or extra volumes
- **Backups:** restore filenames must stay inside the deployment backup directory

Production profile: read-only rootfs, tmpfs on `/tmp`, bind mounts for storage, cache, and media. Development profile: writable rootfs and debug env allowed.

## API

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Agent and Docker socket check |
| GET | `/capacity` | Tenant container counts and memory sum |
| POST | `/tenants/create-network` | Deployment bridge network |
| POST | `/tenants/create-database` | MariaDB DDL (direct to MariaDB, not ProxySQL) |
| POST | `/tenants/create-redis-acl` | Redis ACL user scoped to a key prefix |
| POST | `/tenants/create-minio-creds` | Create MinIO user + attach prefix policy |
| POST | `/tenants/provision-install` | One-off install container, then remove |
| POST | `/tenants/deploy` | Long-running tenant container |
| POST | `/tenants/stop` | Stop tenant |
| POST | `/tenants/start` | Start tenant |
| POST | `/tenants/remove` | Remove tenant container |
| GET | `/tenants/{deployment_id}/observe` | Labels, digest, health, limits |
| POST | `/tenants/configure-route` | Caddy snippet + reload signal |
| POST | `/tenants/backup` | gzip mysqldump |
| POST | `/tenants/restore` | Restore from backup file name |
| GET | `/tenants/{deployment_id}/snapshot` | Download migrate snapshot (`?file=*.tar.gz`) |
| POST | `/deployments/{id}/tasks` | Control-plane task dispatcher (`create_database`, `create_secrets`, `export_snapshot`, `import_snapshot`, `purge`, …) |
| GET | `/deployments/{id}/state` | Observed container state |
| GET | `/deployments/{id}/health` | Running health for provisioning |

Prefer `make -C platform up` so host bind paths and port `8100` are wired correctly.

## Environment

Copy `.env.example` and set at least `RUNTIME_AGENT_TOKEN`. Shared service hosts must resolve from the agent container (same Docker host or attached networks).

| Variable | Role |
| --- | --- |
| `RUNTIME_AGENT_TOKEN` | Bearer token for all routes |
| `DATA_DIR` | State root (default `/var/lib/dilibori`) |
| `TENANT_IMAGE` / `ALLOWED_IMAGES` | Tenant image allowlist |
| `SHARED_NETWORK` | Shared services network (default `dilibori-shared`; compose sets `dilibori-{env}-shared`). Used to pick the right compose services when multiple stacks share a Docker daemon. |
| `MARIADB_*` | Admin connection for DDL and backups |
| `REDIS_*` | Admin Redis for ACL |
| `MINIO_*` | Root MinIO credentials |
| `CADDY_CONTAINER_NAME` | Container to signal after snippet write |

## Run locally

Build and run with the Docker socket and data directory mounted:

```bash
cd platform/runtime-agent
docker build -t dilibori/runtime-agent:local .
docker run --rm -p 8100:8100 \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v /var/lib/dilibori:/var/lib/dilibori \
  --env-file .env \
  dilibori/runtime-agent:local
```

Health check:

```bash
curl -s -H "Authorization: Bearer $RUNTIME_AGENT_TOKEN" http://127.0.0.1:8100/health
```

## Caddy ingress

The agent writes `{DATA_DIR}/ingress/tenants/{deployment_id}.caddy`. Your main Caddyfile should import that directory, for example:

```caddyfile
import /var/lib/dilibori/ingress/tenants/*.caddy
```

After each route update the agent sends `SIGUSR1` or `SIGHUP` to `CADDY_CONTAINER_NAME` when that container exists.

## MinIO credentials

`create-minio-creds` creates a MinIO user (access key / secret), writes a
prefix-scoped IAM policy, and attaches that policy to the user via `mc admin`.
Policy JSON is also kept under `{DATA_DIR}/minio-policies/{deployment_id}.json`
for audit. The runtime-agent image includes the MinIO client (`mc`).

## Typical provision flow

1. `create-network`
2. `create-database`, `create-redis-acl`, `create-minio-creds`
3. `provision-install` with tenant env (DB, Redis, S3, mail)
4. `deploy` with resource limits and hostname label
5. `configure-route` with public hostname and container name

Labels on tenant containers: `dilibori.deployment_id`, `dilibori.tenant_id`, `dilibori.environment`, `dilibori.config_version`, `dilibori.image_digest`.
