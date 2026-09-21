# Multi-tenant platform PoC

Local Docker cell that provisions isolated TastyIgniter tenants from one control plane and one immutable app image.

## What you get

- Shared cell services: Caddy ingress, MariaDB, ProxySQL, Redis ACLs, MinIO, PostgreSQL, PgBouncer, Mailpit
- Control plane API/UI for tenants, deployments, environments, releases, backup, support access, reconcile
- Privileged runtime agent (Docker socket) that applies desired state with allowlisted operations
- Two pilot seed profiles: bilingual Taconazo (USD/MXN) and Spanish-only Pizza Rin (MXN)
- Development and production-like environment profiles with validated invariants
- Tenant-aware event envelope interface (structured-log publisher in the PoC)

Analytics delivery, AWS, billing, and payment orchestration are out of scope.

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

Add pilot hosts:

```text
127.0.0.1 taconazo.dev.localhost taconazo.prod.localhost pizza-rin.dev.localhost pizza-rin.prod.localhost
```

Then open `http://taconazo.dev.localhost:8088` or `http://pizza-rin.dev.localhost:8088`.

## Layout

| Path | Role |
|------|------|
| `docker-compose.yml` | Shared cell |
| `control-plane/` | Tenant lifecycle API |
| `runtime-agent/` | Docker apply agent |
| `profiles/` | Environment policies |
| `seeds/` | Tenant manifests + seed runner |
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
