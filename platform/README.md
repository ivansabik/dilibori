# Multi-tenant platform PoC

Local Docker cell that provisions isolated TastyIgniter tenants from one control plane and one immutable app image.

## What you get

- Shared cell: Caddy ingress, MariaDB, ProxySQL, Redis ACLs, MinIO, PostgreSQL, PgBouncer, Mailpit
- Control plane API/UI for tenants, deployments, environments, releases, backup, support access, reconcile
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
make up
make test
make pilots
```

| Service | URL |
|---------|-----|
| Control plane | http://127.0.0.1:8099 |
| Ingress | http://127.0.0.1:8088 |
| Mailpit | http://127.0.0.1:8026 |
| MinIO console | http://127.0.0.1:9001 |
| CDP inspector (optional) | http://127.0.0.1:8090 |

Add pilot hosts:

```text
127.0.0.1 taconazo.dev.localhost taconazo.prod.localhost pizza-rin.dev.localhost pizza-rin.prod.localhost
```

`make pilots` creates both tenants with a **development** and **production** deployment each. Host header selects the tenant on port 8088.

## Pilots

| | El Taconazo | Pizza Rin |
|--|-------------|-----------|
| Seed bundle | `seeds/taconazo/` | `seeds/pizza-rin/` |
| Hosts | `taconazo.{dev,prod}.localhost:8088` | `pizza-rin.{dev,prod}.localhost:8088` |
| Locales | English (default) + `es_MX`, header picker | `es_MX` only (no picker) |
| Currency | USD (El Paso) + MXN (Juárez) | MXN only |
| Timezone | `America/Denver` | `America/Ciudad_Juarez` |
| Locations | Alameda Ave (TX), Tecnológico (Juárez) | Centro, Pradera (Juárez) |
| Admin email | `operations@taconazotaqueria.com` | `ops@pizzarin.example` |
| Admin password | `ChangeMe-LocalOnly!` (unless `ADMIN_PASSWORD` is set) | same |

Taconazo reuses the rich catalog from `app/seed/el-taconazo.php` via the seed bundle. Pizza Rin seeds from `seeds/pizza-rin/menu.json` (flyer catalog, Spanish copy).

Storefront UI chrome translations (`en` / `es_MX` checkout labels, etc.) live in the shared extension `app/extensions/taconazo/locale/`. Every tenant container mounts that tree. Menu and page text stay in each tenant database.

Example URLs after pilots:

- http://taconazo.dev.localhost:8088/el-taconazo-alameda-ave/menus
- http://pizza-rin.prod.localhost:8088/pizza-rin-centro/menus
- Admin: `http://<host>:8088/admin`

CDP smoke: `make -C ../cdp up`, then browse a pilot storefront and watch http://127.0.0.1:8090. Event names and hooks: [`../app/extensions/dilibori/cdp/EVENTS.md`](../app/extensions/dilibori/cdp/EVENTS.md).

## Layout

| Path | Role |
|------|------|
| `docker-compose.yml` | Shared cell |
| `control-plane/` | Tenant lifecycle API |
| `runtime-agent/` | Docker apply agent |
| `profiles/` | Environment policies |
| `seeds/` | Tenant manifests + seed runner (`common/seed-from-manifest.php`) |
| `contracts/` | JSON schemas |
| `scripts/` | Tests, capacity model, pilot provisioning |
| `tests/` | Contract tests |

## Environments

Each tenant can have isolated deployments (`development`, `staging`, `production`). Configuration order:

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

```sh
make up          # control plane + cell A
make cell-b-up   # data plane only on ports 8101 / 8089
make migrate-schema   # if the Postgres volume predates agent_url / migrating
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
