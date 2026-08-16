# Dilibori CDP

Self-hosted RudderStack data plane for El Taconazo / TastyIgniter. No RudderStack Cloud account. Sources and destinations live in [`workspaceConfig.json`](workspaceConfig.json).

## Quick start

```sh
make up
```

| Service | URL |
|---------|-----|
| Data plane | http://127.0.0.1:8080 |
| Health | http://127.0.0.1:8080/health |
| Event inspector | http://127.0.0.1:8090 |
| Jobs DB (optional) | localhost:6432 |

From the repo root: `make cdp-up`.

## Test a track event

### Curl (data plane only)

```sh
curl -u dilibori_http_write_key_local: -X POST http://127.0.0.1:8080/v1/track \
  -H 'Content-Type: application/json' \
  -d '{
    "anonymousId": "local-test",
    "event": "Product Added",
    "properties": { "product_id": "taco-1", "name": "Suadero", "price": 3.5 }
  }'
```

Open http://127.0.0.1:8090. The webhook destination should show the event within a few seconds.

### From the app (`app/`)

Start CDP first (`make up` here), then the storefront:

```sh
cd ../app
make up
make seed   # if you need demo data / customer login
```

1. Open the inspector: http://127.0.0.1:8090
2. Browse the store: http://127.0.0.1:8001/el-taconazo-alameda-ave/menus  
   Expect **page** + **Product List Viewed** (JS)
3. Add a taco to the cart  
   Expect **Product Added** (PHP)
4. Log in as `juanagallo@gmail.com` / `customer123!`  
   Expect **identify** + **Account Logged In** (PHP); later events should share the same `userId`
5. Go through checkout and place an order (cash / COD is fine on the demo)  
   Expect **Checkout Started** then **Order Completed** (PHP)

Event names, hooks, and properties: [`../app/extensions/dilibori/cdp/EVENTS.md`](../app/extensions/dilibori/cdp/EVENTS.md).

Storefront failures (out of stock, Livewire 500s, JS errors) emit **Application Error** — see the [errors section](../app/extensions/dilibori/cdp/EVENTS.md#application-error).

If the app was already running before CDP came up, recreate it so it joins the `dilibori` network: `cd ../app && docker compose up -d --force-recreate app`.

## Write keys

Match [`workspaceConfig.json`](workspaceConfig.json) and the app env:

| Source | Write key | Used by |
|--------|-----------|---------|
| Javascript | `dilibori_js_write_key_local` | Browser SDK |
| HTTP | `dilibori_http_write_key_local` | TastyIgniter PHP |

## Architecture

- `rudder-server`: gateway (file-based config)
- `rudder-transformer`: destination transforms
- `jobsdb`: Postgres job queue
- `event-sink`: local webhook inspector (dev only). Point RudderStack at a warehouse later if you need one.

App and CDP share the external Docker network `dilibori` so PHP can POST to `http://rudder-server:8080`.

## Make targets

```sh
make help
make up / make down
make health / make logs / make ps
make clean   # wipe jobsdb + inspector events
```
