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

Tenant containers are named `dilibori-tenant-{deployment_id_short}`. Each tenant joins its deployment network and the shared `dilibori-shared` network (ProxySQL, Redis, MinIO, Mailpit). Tenants never attach to the control-plane network.

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
| POST | `/tenants/create-minio-creds` | Prefix policy JSON on disk |
| POST | `/tenants/provision-install` | One-off install container, then remove |
| POST | `/tenants/deploy` | Long-running tenant container |
| POST | `/tenants/stop` | Stop tenant |
| POST | `/tenants/start` | Start tenant |
| POST | `/tenants/remove` | Remove tenant container |
| GET | `/tenants/{deployment_id}/observe` | Labels, digest, health, limits |
| POST | `/tenants/configure-route` | Caddy snippet + reload signal |
| POST | `/tenants/backup` | gzip mysqldump |
| POST | `/tenants/restore` | Restore from backup file name |
| POST | `/deployments/{id}/tasks` | Control-plane task dispatcher (`create_database`, `create_secrets`, `install_release`, …) |
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
| `SHARED_NETWORK` | Shared services network (default `dilibori-shared`) |
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

`create-minio-creds` writes `{DATA_DIR}/minio-policies/{deployment_id}.json` with a prefix-scoped policy document. Attach that policy to the access key with your MinIO operator workflow (`mc admin policy create`, IAM, etc.). Bucket creation is attempted when missing.

## Typical provision flow

1. `create-network`
2. `create-database`, `create-redis-acl`, `create-minio-creds`
3. `provision-install` with tenant env (DB, Redis, S3, mail)
4. `deploy` with resource limits and hostname label
5. `configure-route` with public hostname and container name

Labels on tenant containers: `dilibori.deployment_id`, `dilibori.tenant_id`, `dilibori.environment`, `dilibori.config_version`, `dilibori.image_digest`.
