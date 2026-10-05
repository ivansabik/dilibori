# Multi-tenant platform PoC

Local Docker stacks that provision isolated TastyIgniter tenants. Each environment (dev, prod) is a full stack: control plane, Postgres, runtime agent, MariaDB/ProxySQL, Redis, MinIO, Caddy, and Mailpit. One immutable app image is shared.

## What you get

- Per-env stack: Caddy ingress, MariaDB, ProxySQL, Redis ACLs, MinIO, PostgreSQL, PgBouncer, Mailpit, control plane, runtime agent
- Control plane API/UI for tenants, deployments, releases, backup, support access, reconcile
- Privileged runtime agent (Docker socket) that applies desired state with allowlisted operations
- Two pilot seed profiles (see Pilots below)
- Development and production-like environment profiles with validated invariants
- Tenant-aware CDP events when RudderStack is up (`cdp/`) → http://127.0.0.1:8090

Billing, AWS, and payment orchestration are out of scope.

## Quick start

```sh
cd platform
make env-copy
make build-app
make up              # ENV=dev (default): ports 8099 / 8088 / 8100
make up ENV=prod     # separate stack: ports 8199 / 8188 / 8200
make test
make pilots          # pilots on the current ENV
make pilots ENV=prod
```

Cutover from the old single-stack layout: `make down` once (or remove the old `dilibori-platform` project), then bring up each env and re-run pilots. Old `platform/data/*` contents and named volumes are orphaned.

One-time on a clone: `make install-hooks` so commits that touch platform CI paths run `make lint` and `make test` before push.

| Service | Dev | Prod |
|---------|-----|------|
| Control plane | http://127.0.0.1:8099 | http://127.0.0.1:8199 |
| Ingress | http://127.0.0.1:8088 | http://127.0.0.1:8188 |
| Runtime agent | http://127.0.0.1:8100 | http://127.0.0.1:8200 |
| Mailpit | http://127.0.0.1:8026 | http://127.0.0.1:8126 |
| MinIO console | http://127.0.0.1:9001 | http://127.0.0.1:9101 |
| CDP inspector (optional) | http://127.0.0.1:8090 | same |

Ports live in `envs/dev.env` and `envs/prod.env`. Shared local secrets stay in `.env`.

Add pilot hosts (required so the names resolve to this machine):

```text
127.0.0.1 taconazo.dev.localhost pizza-rin.dev.localhost
127.0.0.1 taconazo.prod.localhost pizza-rin.prod.localhost
```

Use the ingress port for that env (`:8088` for dev, `:8188` for prod). URLs without the port refuse to connect.

`make pilots` (or `make pilots ENV=prod`) creates both tenants with one deployment on that stack. The Host header selects the tenant.

## Pilots

| | El Taconazo | Pizza Rin |
|--|-------------|-----------|
| Seed bundle | `seeds/taconazo/` | `seeds/pizza-rin/` |
| Dev storefront | http://taconazo.dev.localhost:**8088**/ | http://pizza-rin.dev.localhost:**8088**/ |
| Prod storefront | http://taconazo.prod.localhost:**8188**/ | http://pizza-rin.prod.localhost:**8188**/ |
| Locales | English (default) + `es_MX`, header picker | `es_MX` only (no picker) |
| Currency | USD (El Paso) + MXN (Juárez) | MXN only |
| Timezone | `America/Denver` | `America/Ciudad_Juarez` |
| Locations | Alameda Ave (TX), Tecnológico (Juárez) | Centro, Pradera (Juárez) |
| Admin email | `operations@taconazotaqueria.com` | `ops@pizzarin.example` |
| Admin password | random per deployment (never in compose) | same |

Taconazo reuses the rich catalog from `app/seed/el-taconazo.php` via the seed bundle. Pizza Rin seeds from `seeds/pizza-rin/menu.json` (flyer catalog, Spanish copy).

Both pilots enable `demo` in their manifest. After the menu seed, `seeds/common/seed-demo-activity.php` builds shared demo activity (customers, coupons, orders, reservations, reviews, subscribers) from the tenant's own menu and a per-locale persona pack under `seeds/common/demo/personas/`. A future French tenant only needs a `fr_FR.json` persona and a `demo` block in its manifest.

Storefront UI chrome translations (`en` / `es_MX` checkout labels, etc.) live in the shared extension `app/extensions/taconazo/locale/`. Every tenant container mounts that tree. Menu and page text stay in each tenant database.

Example URLs after pilots:

- http://taconazo.dev.localhost:8088/el-taconazo-alameda-ave/menus
- http://pizza-rin.prod.localhost:8188/pizza-rin-centro/menus
- Admin: http://taconazo.prod.localhost:8188/admin

### Admin login

Every deployment (Taconazo, Pizza Rin, and any later tenant) gets a random `ADMIN_PASSWORD` at provision time. Nothing is hardcoded in compose or seed manifests. The control plane stores the secret encrypted under `data/{env}/secrets/{deployment_id}/ADMIN_PASSWORD.json` and never returns it from the API.

After `make pilots` (or any new deployment), print credentials:

```sh
# Dev (default ENV=dev, ingress :8088)
make admin-pass HOST=taconazo.dev.localhost
make admin-pass HOST=pizza-rin.dev.localhost

# Prod stack
make admin-pass HOST=taconazo.prod.localhost ENV=prod
make admin-pass HOST=pizza-rin.prod.localhost ENV=prod

# Same script by hostname or deployment UUID
./scripts/print-admin-password.sh taconazo.prod.localhost
./scripts/print-admin-password.sh 48926f5b-2d1f-4d73-aed1-c47deb6682c4
```

Needs the control plane up (`make up`) so the script can resolve hostname → deployment id. Decryption uses `APP_SECRET_KEY` from `platform/.env` (same default as `make env-copy`).

Example output:

```text
Hostname:  pizza-rin.dev.localhost
Admin URL: http://pizza-rin.dev.localhost:8088/admin
Email:     ops@pizzarin.example
Password:  <24 hex chars>
```

Admin emails come from each seed manifest (`seeds/*/manifest.json` → `admin.email`). Re-seed without `ADMIN_PASSWORD` in the container env does not rotate the password.

If you get connection refused on the storefront, you omitted the ingress port or the hosts line above. If you get **502**, the tenant container is down (`docker ps` / `make pilots` again).

CDP smoke: `make -C ../cdp up`, then browse a pilot storefront and watch http://127.0.0.1:8090. Event names and hooks: [`../app/extensions/dilibori/cdp/EVENTS.md`](../app/extensions/dilibori/cdp/EVENTS.md).

## Layout

| Path | Role |
|------|------|
| `docker-compose.yml` | Full stack, parameterized by `PLATFORM_ENV` |
| `envs/dev.env`, `envs/prod.env` | Ports and deployment environment |
| `control-plane/` | Tenant lifecycle API |
| `runtime-agent/` | Docker apply agent |
| `profiles/` | Environment policies |
| `seeds/` | Tenant manifests, menu seeds, shared demo activity (`common/seed-demo-activity.php`) |
| `contracts/` | JSON schemas |
| `scripts/` | Tests, capacity model, pilot provisioning |
| `tests/` | Contract tests |

## Environments

Dev and prod are separate compose projects (`dilibori-dev`, `dilibori-prod`). Each control plane is pinned to one `DEPLOYMENT_ENVIRONMENT` and rejects the other. Configuration order inside a stack:

1. Application defaults
2. Environment profile (`development` or `production-like`)
3. Cell defaults
4. Allowlisted tenant overrides
5. Secrets (referenced, never returned by the API)

Production-like forbids `APP_DEBUG`, source mounts, and marketplace installs.

## Security boundaries

- One MariaDB database and least-privilege user per deployment
- Tenant DB traffic through ProxySQL (connection budgets enforced)
- Redis ACL user limited to a key prefix
- MinIO user limited to a tenant prefix (policy attached at provision)
- Isolated Docker bridge per deployment (`10.200.0.0/16`); shared services and ingress attach to that bridge
- Runtime agent requires a bearer token and rejects non-allowlisted images/env/mounts
- Tenant admins cannot install extensions or themes

## Capacity

```sh
make capacity
```

Idle memory per tenant is the primary density limit for the $49 plan. Connection budgets are tracked separately for MariaDB and PostgreSQL.

For whether that cell model reaches ~1000 tenants, see [docs/scale-findings.md](docs/scale-findings.md).

## Second cell (migrate / drain)

Cell B is a dev-only migrate fixture. Start the dev stack first:

```sh
make up ENV=dev
make cell-b-up   # data plane only on ports 8101 / 8089
make migrate-schema ENV=dev   # if the Postgres volume predates agent_url / migrating
```

Register cell B, then move a deployment:

```sh
curl -s localhost:8099/cells -H 'Content-Type: application/json' -d '{
  "name":"local-b",
  "agent_url":"http://dilibori-runtime-agent-b:8100",
  "capacity_metadata":{"max_deployments":16,"ingress_base_url":"http://127.0.0.1:8089"}
}'

curl -s -X POST localhost:8099/deployments/{id}/migrate \
  -H 'Content-Type: application/json' \
  -d '{"target_cell_id":"<cell-b-uuid>"}'
```

Drain empties a cell into another: `POST /cells/{id}/drain` with `target_cell_id`.
The source cell is marked unschedulable so new reserves do not refill it.
