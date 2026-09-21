# Dilibori monorepo

Local workspace for the Dilibori stack and related data tools.

| Folder | Purpose |
|--------|---------|
| [`platform/`](platform/) | Multi-tenant PoC: control plane, runtime agent, shared cell |
| [`app/`](app/) | TastyIgniter 4 immutable image + extensions |
| [`cdp/`](cdp/) | Optional RudderStack data plane (not part of the multi-tenant PoC) |
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

See [`platform/README.md`](platform/README.md).

## Standalone storefront (legacy single-tenant)

```sh
cd app
make up
make seed
```

- Store: http://127.0.0.1:8001
- Admin: http://127.0.0.1:8001/admin

See [`app/README.md`](app/README.md).
