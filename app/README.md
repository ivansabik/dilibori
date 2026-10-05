# Dilibori app

TastyIgniter v4.0.1 on Docker: PHP 8.3 + Apache, MariaDB, Redis.

This folder is the **immutable application image** (`Dockerfile`) plus extensions and the legacy **single-tenant** compose stack. For the multi-tenant control plane and the two pilots (El Taconazo + Pizza Rin), use [`../platform/`](../platform/).

Legacy single-tenant demo: **El Taconazo** only (El Paso USD + Juárez MXN). Pizza Rin exists only under `platform/seeds/pizza-rin/`.

## Install (legacy single-tenant)

From this directory (`app/`):

```sh
make up
make seed
```

From the repo root: `make -C app up` or `make up` (root Makefile proxies here).

Or full wipe + reinstall + seed:

```sh
make reset
```

| | URL / credentials |
|--|--|
| Store | http://127.0.0.1:8001 |
| Alameda Ave menus | http://127.0.0.1:8001/el-taconazo-alameda-ave/menus |
| Tecnológico menus | http://127.0.0.1:8001/el-taconazo-tecnologico/menus |
| Admin | http://127.0.0.1:8001/admin |
| Admin login | `operations@taconazotaqueria.com` / `12345678abc!` |
| Customer login | `juanagallo@gmail.com` / `customer123!` |

## Multi-tenant (preferred for pilots)

```sh
cd ../platform
make env-copy && make build-app && make up && make pilots
```

Hosts and tenant differences: [`../platform/README.md`](../platform/README.md#pilots).

## What `make seed` loads (legacy Taconazo)

`make seed` runs the tenant manifest seeder (`platform/seeds/common/seed-from-manifest.php`) with the Taconazo manifest. That loads the rich catalog from `seed/el-taconazo.php`, then shared demo activity from `platform/seeds/common/seed-demo-activity.php`.

Locations & ops
- **El Taconazo - Alameda Ave** @ 4500 Alameda Ave, El Paso, TX 79905. **USD**. Storefront photo + homepage slider; wordmark as header logo; black primary buttons
- **El Taconazo - Tecnológico** @ Av. Tecnológico 1545, Ciudad Juárez, Chihuahua. **MXN**. Same menu items, peso prices; night storefront photo
- Footer social: Instagram, Facebook, TikTok (`@eltaconazo`)
- Timezone `America/Denver`; currencies **USD** (default) + **MXN** (auto-selected on the Juárez location)
- Languages: **English** (default) + **Español (México)**; **EN | ES** picker in the header
- Working hours (opening / delivery / pickup) from 7am at both locations
- Delivery + pickup + future orders + table reservations enabled
- Delivery zones: El Paso (Central 4mi, Greater 10mi) + Juárez (Centro, Pronaf / Tecnológico)

Menu (separate EN + ES translations; duplicated per location for currency)
- Categories: Breakfast, Tacos, Combos, Drinks, Sides
- Breakfast (7-11am): Pambazo, Torta de Chilaquil
- Tacos: suadero, pastor en trompo, cabeza, costilla (+ combos, drinks, extras)
- Product photos on breakfast, tacos, and combos (items with options open the photo modal)
- Options, mealtimes, specials, allergens. One language at a time via the header picker
- CMS pages share slugs; About / Privacy / Terms switch with the locale

Marketing & guests (shared demo activity)
- Coupons from the manifest: El Paso `BIENVENIDO` / `TACOMIERCOLES` / `ENVIOGRATIS`; Juárez `BIENVENIDOJUAREZ` / `TACOMIERCOLESJRZ` / `ENVIOGRATISJRZ`
- ~40 demo customers (featured login `juanagallo@gmail.com` / `customer123!`)
- Curated edge-case orders plus ~60 days of history per location (delivery/pickup, every status, coupons, guest checkout)
- Reviews, newsletter subscribers, dining floor + Patio, reservations across statuses
- About / Privacy / Terms pages + homepage banners
- Mail SMTP → Mailpit (`mailpit:1025`); From **El Taconazo Taqueria** `<hola@eltaconazo.example>`

Re-run anytime: `make seed` (safe to repeat; `SEED_MODE=initial` rebuilds demo activity).

### Multilingual notes

TastyIgniter Translate (`tastyigniter/ti-ext-translate`) stores **separate** English and Spanish strings for menus, categories, options, mealtimes, ingredients, the location blurb, and CMS pages.

Storefront chrome (nav, footer, cart, checkout, login, reservation, cookie banner) uses lang packs under `extensions/taconazo/locale/lang/{en,es_MX}/`. Those files are **shared** by every platform tenant (copied from `/opt/extensions` at container start). Menu and CMS copy stay in each tenant DB.

Admin UI Spanish (`es_MX`) also lives in that extension (`igniter/admin.php`, `system.php`, plus extension packs). Strings come from the community translation project and are committed here, so no Carte key or `igniter:language-install` is required. Seeded staff language follows the tenant default locale (Pizza Rin → Spanish; El Taconazo → English). Switch later under Profile → My Account → Language.

- Storefront: **EN | ES** buttons in the header when the tenant enables both locales (session). Codes: `en` and `es_MX`.
- Admin content: edit a menu item and switch locale on the translatable fields (`trltext` / `trltextarea`).
- Browser auto-detect is **off** so the picker is not overridden (`es` from `Accept-Language` would not match `es_MX`). Turn it on under `Admin → Settings` if you want.

## Make commands

```sh
make help
make up / make down
make seed
make passwd                     # reset admin to default password
make passwd PASSWORD='newpass'
make logs / make shell / make ps
make reset                      # wipe DB, reinstall, seed
```

## Ports

| Service | Host |
|---------|------|
| App | http://127.0.0.1:8001 |
| MariaDB | localhost:3307 |
| RudderStack data plane (CDP) | http://127.0.0.1:8080 |
| CDP event inspector | http://127.0.0.1:8090 |
| Mailpit UI | http://127.0.0.1:8025 |

Inside Docker, DB host is `db` (user/pass/db: `tastyigniter`). CDP gateway hostname is `rudder-server`. Mail SMTP hostname is `mailpit:1025` (shared `dilibori` network).

Start CDP separately: `make -C ../cdp up` or from repo root `make cdp-up`.

Start Mailpit, then seed so TastyIgniter stores SMTP (From **El Taconazo Taqueria**):

```sh
make -C ../mailpit up
make up
make seed
make -C ../mailpit smoke
```

From the repo root: `make mailpit-up` then `make seed` then `make mailpit-smoke`. See [`../mailpit/README.md`](../mailpit/README.md).

## Analytics (CDP)

The `dilibori.cdp` extension sends storefront events through `EVENT_CLIENT_DRIVER` (RudderStack locally). Listeners do not call RudderStack APIs; change the driver in env.

Event catalog (names, hooks, properties): [`extensions/dilibori/cdp/EVENTS.md`](extensions/dilibori/cdp/EVENTS.md).

### Test events from this app

```sh
# Terminal 1: data plane + inspector
make -C ../cdp up

# Terminal 2: storefront (from app/)
make up
make seed
```

Watch http://127.0.0.1:8090 while you use the store:

| Action | Events to look for |
|--------|--------------------|
| Open a menus page | `page`, `Product List Viewed` |
| Add an item to cart | `Product Added` |
| Open `/cart` | `Cart Viewed` |
| Log in (`juanagallo@gmail.com` / `customer123!`) | `identify`, `Account Logged In` |
| Open `/checkout` with items in the cart | `Checkout Started` |
| Complete an order | `Order Completed` |

More detail: [`../cdp/README.md`](../cdp/README.md).

### Troubleshooting: “X is currently out of stock”

That is an `ApplicationException` from Igniter stock checks (not a CDP wiring failure). With `APP_DEBUG=true` you get a full error page. You should still see **Application Error** in the CDP inspector (`error_source=php`).

1. Note the Livewire `menuId` from the request body.
2. Inspect stock:

```sh
docker compose exec -T db mariadb -utastyigniter -ptastyigniter tastyigniter -e "
SELECT m.menu_id, m.menu_name, s.quantity, s.is_tracked
FROM menus m
LEFT JOIN stocks s ON s.stockable_type='menus' AND s.stockable_id=m.menu_id
WHERE m.menu_id = 9;
"
```

3. Tracked + `quantity` NULL/`<= 0` → out of stock. Full write-up: [`extensions/dilibori/cdp/EVENTS.md`](extensions/dilibori/cdp/EVENTS.md#debugging-storefront-exceptions).

## Layout

```
├── Makefile
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
├── seed/el-taconazo.php    # Taconazo catalog (menus/pages); activity via platform seeds
├── extensions/             # locale packs + CDP (mounted into platform tenants)
├── db/                     # MariaDB (gitignored)
└── media/                  # uploads (gitignored)
```

## Credits

- https://github.com/tastyigniter/TastyIgniter
- https://github.com/ThisIsQasim/TastyIgniter/pull/11
