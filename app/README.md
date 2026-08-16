# Dilibori App 

Powered by TastyIgniter v4.0.1 deployed locally with Docker. One-command local stack: PHP 8.3 + Apache, MariaDB, Redis. Based on [PR #11](https://github.com/ThisIsQasim/TastyIgniter/pull/11).

Demo storefront: **El Taconazo** — El Paso, TX (USD) and Ciudad Juárez (MXN).

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
| Admin login | `operations@taconazotaqueria.com` / `12345678abc!` |
| Customer login | `juanagallo@gmail.com` / `customer123!` |

## What `make seed` loads

Locations & ops
- **El Taconazo - Alameda Ave** @ 4500 Alameda Ave, El Paso, TX 79905 — **USD** (storefront photo + homepage slider; wordmark as header logo; black primary buttons)
- **El Taconazo - Tecnológico** @ Av. Tecnológico 1545, Ciudad Juárez, Chihuahua — **MXN** (same menu items, peso prices; night storefront photo)
- Footer social: Instagram, Facebook, TikTok (`@eltaconazo`)
- Timezone `America/Denver`; currencies **USD** (default) + **MXN** (auto-selected on the Juárez location)
- Languages: **English** (default) + **Español (México)**; **EN | ES** picker in the header
- Working hours (opening / delivery / pickup) from 7am — both locations
- Delivery + pickup + future orders + table reservations enabled
- Delivery zones: El Paso (Central 4mi, Greater 10mi) + Juárez (Centro, Pronaf / Tecnológico)

Menu (separate EN + ES translations; duplicated per location for currency)
- Categories: Breakfast, Tacos, Combos, Drinks, Sides
- Breakfast (7–11am): Pambazo, Torta de Chilaquil
- Tacos: suadero, pastor en trompo, cabeza, costilla (+ combos, drinks, extras)
- Options, mealtimes, specials, allergens — one language at a time via the header picker
- CMS pages share slugs; About / Privacy / Terms switch with the locale

Marketing & guests
- Coupons: El Paso `BIENVENIDO` / `TACOMIERCOLES` / `ENVIOGRATIS`; Juárez `BIENVENIDOJUAREZ` / `TACOMIERCOLESJRZ` / `ENVIOGRATISJRZ`
- 3 customers with El Paso addresses
- 4 sample orders (received, in kitchen, completed, canceled)
- 3 published reviews
- About / Privacy / Terms pages
- Homepage banners + newsletter subscribers
- Dining floor + Patio section + sample reservation

Re-run anytime: `make seed` (safe to repeat).

### Multilingual notes

TastyIgniter Translate (`tastyigniter/ti-ext-translate`) stores **separate** English and Spanish strings for menus, categories, options, mealtimes, ingredients, the location blurb, and CMS pages.

Storefront chrome (nav, footer, cart, checkout, login, reservation, cookie banner) uses `es_MX` lang packs in `extensions/taconazo/locale/lang/es_MX/`. Footer About / Privacy titles are lang keys so they switch with the locale.

- Storefront: **EN | ES** buttons in the header (session). Locale codes: `en` and `es_MX`.
- Admin: edit a menu item and switch locale on the translatable fields (`trltext` / `trltextarea`).
- Browser auto-detect is **off** so the picker is not overridden (`es` from `Accept-Language` would not match `es_MX`). Turn it on under `Admin → Settings` if you want.
- Full **UI language packs** from the TastyIgniter marketplace need a Carte key (`IGNITER_CARTE_KEY`) via `php artisan igniter:language-install es_MX`.

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

Inside Docker, DB host is `db` (user/pass/db: `tastyigniter`).

## Layout

```
├── Makefile
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
├── seed/el-taconazo.php    # rich demo data
├── extensions/             # locale picker (copied into the container)
├── db/                     # MariaDB (gitignored)
└── media/                  # uploads (gitignored)
```

## Credits

- https://github.com/tastyigniter/TastyIgniter
- https://github.com/ThisIsQasim/TastyIgniter/pull/11
