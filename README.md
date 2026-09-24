# Dilibori monorepo

Local workspace for the Dilibori stack and related data tools.

| Folder | Purpose |
|--------|---------|
| [`platform/`](platform/) | Multi-tenant PoC: control plane, runtime agent, shared cell, pilot seeds |
| [`app/`](app/) | TastyIgniter 4 image, shared extensions, legacy single-tenant compose |
| [`cdp/`](cdp/) | Optional RudderStack data plane + event inspector |
| [`mailpit/`](mailpit/) | Standalone Mailpit (also included under `platform/`) |

## Multi-tenant platform PoC

```sh
cd platform
make env-copy
make build-app
make up
make test
make pilots
```

Two pilots on ingress http://127.0.0.1:8088:

| Tenant | Locales | Currency | Example host |
|--------|---------|----------|--------------|
| El Taconazo | `en` + `es_MX` | USD / MXN | http://taconazo.dev.localhost:8088 |
| Pizza Rin | `es_MX` only | MXN | http://pizza-rin.prod.localhost:8088 |

Add hosts from [`platform/README.md`](platform/README.md). Control plane: http://127.0.0.1:8099.

## Standalone storefront (legacy single-tenant)

El Taconazo only (no Pizza Rin):

```sh
cd app
make up
make seed
```

- Store: http://127.0.0.1:8001
- Admin: http://127.0.0.1:8001/admin

See [`app/README.md`](app/README.md).
