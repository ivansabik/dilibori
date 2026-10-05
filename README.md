# Dilibori monorepo

Local workspace for the Dilibori stack and related data tools.

| Folder | Purpose |
|--------|---------|
| [`platform/`](platform/) | Multi-tenant PoC: per-env stacks (dev/prod), control plane, runtime agent, pilot seeds |
| [`app/`](app/) | TastyIgniter 4 image, shared extensions, legacy single-tenant compose |
| [`docs/product/`](docs/product/) | Sales-facing product docs for restaurant owners (features, benefits) |
| [`cdp/`](cdp/) | Optional RudderStack data plane + event inspector |
| [`mailpit/`](mailpit/) | Standalone Mailpit (also included under `platform/`) |

## Multi-tenant platform PoC

```sh
cd platform
make env-copy
make build-app
make up              # ENV=dev (default)
make up ENV=prod     # separate full stack
make test
make pilots
make pilots ENV=prod
```

Dev ingress **http://127.0.0.1:8088**, prod **http://127.0.0.1:8188** (port required; bare `:80` will refuse to connect):

| Tenant | Locales | Currency | Dev URL | Prod URL |
|--------|---------|----------|---------|----------|
| El Taconazo | `en` + `es_MX` | USD / MXN | http://taconazo.dev.localhost:8088 | http://taconazo.prod.localhost:8188 |
| Pizza Rin | `es_MX` only | MXN | http://pizza-rin.dev.localhost:8088 | http://pizza-rin.prod.localhost:8188 |

Add the hosts line from [`platform/README.md`](platform/README.md). Control planes: http://127.0.0.1:8099 (dev), http://127.0.0.1:8199 (prod).

Admin passwords are random per deployment. After pilots:

```sh
make -C platform admin-pass HOST=taconazo.dev.localhost
make -C platform admin-pass HOST=pizza-rin.dev.localhost
```

## Standalone storefront (legacy single-tenant)

El Taconazo only (no Pizza Rin):

```sh
cd app
make up
make seed
make admin-pass   # random password from first provision
```

- Store: http://127.0.0.1:8001
- Admin: http://127.0.0.1:8001/admin

See [`app/README.md`](app/README.md).
