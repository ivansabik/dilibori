# Dilibori monorepo

Local workspace for the Dilibori stack and related data tools.

| Folder | Purpose |
|--------|---------|
| [`app/`](app/) | TastyIgniter 4 storefront (Docker) |

## Storefront (quick start)

```sh
cd app
make up
make seed
```

Or from the repo root: `make -C app up` / `make up` (root Makefile proxies into `app/`).

- Store: http://127.0.0.1:8001
- Admin: http://127.0.0.1:8001/admin

See [`app/README.md`](app/README.md) for credentials and seed details.
