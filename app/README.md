# Dilibori app

TastyIgniter v4.0.1 on Docker: PHP 8.3, Apache, MariaDB 10.11. File cache. Database queue.

This directory is the application image (`Dockerfile`), extensions, and the El Taconazo Compose stack. One app container, one database. Two locations: Alameda Ave (El Paso, USD) and Tecnológico (Ciudad Juárez, MXN).

What the restaurant gets is in the [product overview](../docs/product/README.md). License, status, and the short start path are in the [root README](../README.md). Analytics and mail each have their own guide: [cdp/README.md](../cdp/README.md), [mailpit/README.md](../mailpit/README.md).

## Install

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
| Admin email | `operations@taconazotaqueria.com` |
| Admin password | random on first provision (`make admin-pass`) |
| Customer login | `juanagallo@gmail.com` / `customer123!` |

### Admin password

First provision generates a random `ADMIN_PASSWORD`. It is not in `docker-compose.yml`. The value is stored under `media/.dilibori-admin-password` (persists across container recreates) and mirrored to `storage/dilibori-admin-password` in the container.

```sh
make admin-pass                 # print email + password
make passwd                     # rotate to a new random password
make passwd PASSWORD='newpass'  # set an explicit password
```

Re-running `make seed` does not change the password unless you pass `ADMIN_PASSWORD` into the container.

## What `make seed` loads

`make seed` runs `seed/common/seed-from-manifest.php` with `seed/taconazo/manifest.json`. That loads the catalog from `seed/el-taconazo.php`, then demo activity from `seed/common/seed-demo-activity.php`.

### Locations and hours

- El Taconazo - Alameda Ave, 4500 Alameda Ave, El Paso, TX 79905. USD. Storefront photo and homepage slider. Wordmark as the header logo. Black primary buttons.
- El Taconazo - Tecnológico, Av. Tecnológico 1545, Ciudad Juárez, Chihuahua. MXN. Same menu items at peso prices. Night storefront photo.
- Footer social: Instagram, Facebook, TikTok (`@eltaconazo`).
- Timezone `America/Denver`. Currencies USD (default) and MXN (selected with the Juárez location).
- Languages: English (default) and Spanish (Mexico). EN and ES buttons in the header.
- Opening, delivery, and pickup hours start at 7am at both locations.
- Delivery, pickup, future orders, and table reservations are on.
- Delivery zones: El Paso (Central 4mi, Greater 10mi) and Juárez (Centro, Pronaf / Tecnológico).

### Menu

English and Spanish are stored as separate strings. Items are duplicated per location so each currency has its own price.

- Categories: Breakfast, Tacos, Combos, Drinks, Sides.
- Breakfast (7-11am): Pambazo, Torta de Chilaquil.
- Tacos: suadero, pastor en trompo, cabeza, costilla, plus combos, drinks, and extras.
- Photos on breakfast, tacos, and combos. Items with options open the photo modal.
- Options, mealtimes, specials, and allergens. The header picker shows one language at a time.
- CMS pages share slugs. About, Privacy, and Terms follow the locale.

### Marketing and guests

Demo activity is shared across the seed, not copied per location file.

- Coupons from the manifest: El Paso `BIENVENIDO`, `TACOMIERCOLES`, `ENVIOGRATIS`. Juárez `BIENVENIDOJUAREZ`, `TACOMIERCOLESJRZ`, `ENVIOGRATISJRZ`.
- About 40 demo customers. Featured login: `juanagallo@gmail.com` / `customer123!`.
- A few hand-built orders, plus about 60 days of history per location (delivery and pickup, each status, coupons, guest checkout).
- Reviews, newsletter subscribers, a dining floor and a patio, reservations in more than one status.
- About, Privacy, and Terms pages, plus homepage banners.
- SMTP points at Mailpit (`mailpit:1025`). From: El Taconazo Taqueria `<hola@eltaconazo.example>`. See [mailpit/README.md](../mailpit/README.md).

Re-run anytime: `make seed` (safe to repeat; `SEED_MODE=initial` rebuilds demo activity).

### Multilingual notes

TastyIgniter Translate (`tastyigniter/ti-ext-translate`) stores separate English and Spanish strings for menus, categories, options, mealtimes, ingredients, the location blurb, and CMS pages.

Storefront chrome (nav, footer, cart, checkout, login, reservation, cookie banner) uses lang packs under `extensions/taconazo/locale/lang/{en,es_MX}/`. The entrypoint copies them from `/opt/extensions` at container start. Menu and CMS copy stay in the database.

Admin UI Spanish (`es_MX`) also lives in that extension (`igniter/admin.php`, `system.php`, plus extension packs). Strings come from the community translation project and are committed here, so no Carte key or `igniter:language-install` is required. Seeded staff language is English, the site default. Switch later under Profile → My Account → Language.

- Storefront: EN and ES buttons in the header (session). Codes: `en` and `es_MX`.
- Admin content: edit a menu item and switch locale on the translatable fields (`trltext` / `trltextarea`).
- Browser language detection is off, so the picker is not overridden (`es` from `Accept-Language` does not match `es_MX`). Turn it on under Manage, then Settings, if you want it.

## Make commands

```sh
make help
make up / make down
make seed
make admin-pass                 # print provisioned admin password
make passwd                     # rotate to a new random password
make passwd PASSWORD='newpass'  # set an explicit password
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

Inside Docker, the DB host is `db` (user, password, and database name are `tastyigniter`). The CDP gateway hostname is `rudder-server`. Mail SMTP is `mailpit:1025` on the shared `dilibori` network. After seed, the From name is El Taconazo Taqueria and the address is `hola@eltaconazo.example`.

Start CDP with `make -C ../cdp up`, or `make cdp-up` from the repo root. Start Mailpit first, then seed, so TastyIgniter stores SMTP. The order of commands and the smoke test are in [mailpit/README.md](../mailpit/README.md).

## Analytics (CDP)

The `dilibori.cdp` extension sends storefront events through `EVENT_CLIENT_DRIVER` (RudderStack locally). Listeners do not call RudderStack APIs. Change the driver in env. Driver status is in the [extension README](extensions/dilibori/cdp/README.md).

Names, hooks, and properties: [event catalog](../cdp/README.md#event-catalog). Click-path and the events to expect: [test a track event](../cdp/README.md#test-a-track-event).

### Troubleshooting: "X is currently out of stock"

That is an `ApplicationException` from Igniter stock checks. With `APP_DEBUG=true` Laravel shows a full error page. The CDP inspector still records Application Error with `error_source=php`. The longer write-up is in the [CDP guide](../cdp/README.md#debugging-storefront-exceptions).

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

3. Tracked with `quantity` NULL or `<= 0` means the item is out of stock.

## Layout

```
├── Makefile
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
├── seed/                   # manifest, catalog, and demo activity
├── extensions/             # locale packs + CDP (copied in at container start)
├── db/                     # MariaDB (gitignored)
└── media/                  # uploads (gitignored)
```

## Credits

- https://github.com/tastyigniter/TastyIgniter
- https://github.com/ThisIsQasim/TastyIgniter/pull/11
