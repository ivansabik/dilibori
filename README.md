# Dilibori monorepo

Local workspace for the Dilibori stack and related data tools.

| Folder | Purpose |
|--------|---------|
| [`app/`](app/) | TastyIgniter 4 storefront (Docker) |
| [`cdp/`](cdp/) | RudderStack data plane + local event inspector |

## Storefront

```sh
cd app
make up
make seed
```

Or from the repo root: `make -C app up` / `make up` (root Makefile proxies into `app/`).

- Store: http://127.0.0.1:8001
- Admin: http://127.0.0.1:8001/admin

See [`app/README.md`](app/README.md) for credentials and seed details.

## CDP (RudderStack)

```sh
make cdp-up
```

| Service | URL |
|---------|-----|
| Data plane | http://127.0.0.1:8080 |
| Event inspector | http://127.0.0.1:8090 |

App PHP posts to `http://rudder-server:8080` on the shared `dilibori` Docker network. Browser SDK uses `http://127.0.0.1:8080`.

Events go through `EVENT_CLIENT_DRIVER` (`rudderstack` locally). See [`app/extensions/dilibori/cdp`](app/extensions/dilibori/cdp) and [`cdp/README.md`](cdp/README.md).
