# Dilibori 🍽️

Dilibori is an open-source online ordering site and restaurant back office built on [TastyIgniter](https://tastyigniter.com/), with [RudderStack](https://www.rudderstack.com/) for storefront and order events. One restaurant gets a branded storefront and an admin for orders, menus, pickup and delivery 🛵, reservations, customer accounts, and coupons. Several locations can share that admin and one database. Each location keeps its own hours, delivery zones, and currency.

Marketplace apps such as Uber Eats and DoorDash take a commission on every ticket and list nearby competitors next to your dishes. Phone and WhatsApp orders get retyped by hand. A menu change at one location misses the other. Friday reservations sit in a notebook and double-book. The customer list stays with the marketplace.

On a Dilibori site the guest orders from you and sees only your menu. Staff work one queue: accept, reject, or delay. Coupons and table bookings live in the same admin. Optional local [RudderStack](https://www.rudderstack.com/) records browser activity and backend events (order placed, payment started, order completed) on your machine. [Mailpit](https://mailpit.axllent.org/) catches mail during local tests.

> **Project status:** Dilibori runs locally with Docker Compose. AWS deployment is coming soon.

## Who it is for

Restaurant owners: the storefront and admin are described in the product guides, with screenshots of the El Taconazo demo.

- [Customer experience](docs/product/customer-features.md)
- [Owner and staff tools](docs/product/owner-admin-features.md)
- [Product overview](docs/product/README.md)

Engineers and consultants: the app image, analytics, and mail catcher each have their own guide.

- [App image, seed, and Compose](app/README.md)
- [Local RudderStack and the event catalog](cdp/README.md)
- [Mailpit](mailpit/README.md)

## How it fits together

| Piece | Role |
|---|---|
| TastyIgniter 4 | Storefront and `/admin` |
| PHP 8.3 and Apache | Application runtime |
| MariaDB 10.11 | Restaurant, customer, and order data |
| RudderStack | Optional event pipeline |
| Mailpit | Local SMTP catcher |

The app uses file caching and a database-backed queue. The storefront, RudderStack, and Mailpit are separate Compose projects on a shared Docker network named `dilibori`.

## Quick start

Requirements: Docker with Docker Compose, and Make.

From the repository root:

```sh
make up
make seed
make admin-pass
```

| Resource | Address or credentials |
|---|---|
| Storefront | http://127.0.0.1:8001 |
| Admin | http://127.0.0.1:8001/admin |
| Admin email | `operations@taconazotaqueria.com` |
| Admin password | Generated on first provision. Print it with `make admin-pass` |
| Demo customer | `juanagallo@gmail.com` / `customer123!` |

`make reset` replaces the local database, keeps uploaded media, then provisions and seeds again. Ports, password storage, and rotation are in [app/README.md](app/README.md).

## Optional analytics and email

Start RudderStack and Mailpit before the app so the containers share the `dilibori` network:

```sh
make cdp-up
make mailpit-up
make up
make seed
```

| Service | URL |
|---|---|
| RudderStack data plane | http://127.0.0.1:8080 |
| Event inspector | http://127.0.0.1:8090 |
| Mailpit inbox | http://127.0.0.1:8025 |

The storefront runs without either service. A test event and the event catalog are in [cdp/README.md](cdp/README.md). SMTP hostnames and the smoke test are in [mailpit/README.md](mailpit/README.md).

## Demo: El Taconazo 😋

`make seed` loads a two-location restaurant:

- Alameda Ave in El Paso, USD
- Tecnológico in Ciudad Juárez, MXN

The storefront is English by default, with Spanish (Mexico) in the header. Guests can order for pickup or delivery 🛵, apply a coupon, create an account, and book a table. The seeded menu 🌮🥤, hours, zones, coupons, and demo history are listed in [app/README.md](app/README.md#what-make-seed-loads).

## Repository layout

| Path | Contents |
|---|---|
| [`app/`](app/) | TastyIgniter image, Compose stack, extensions, locale packs, demo seed |
| [`docs/product/`](docs/product/) | Feature guides for customers and owners |
| [`cdp/`](cdp/) | Optional RudderStack data plane and local event inspector |
| [`mailpit/`](mailpit/) | Local SMTP catcher |

## Common commands

```sh
make help
make up            # storefront and database
make down
make seed
make admin-pass
make logs
make reset
make cdp-up
make mailpit-up
```

The app Makefile has the rest (`make shell`, `make passwd`, and others). See [app/README.md](app/README.md#make-commands).

## FAQ

### Is Dilibori ready for production?

Not yet. The checked-in deployment runs locally with Docker Compose. AWS deployment is coming soon.

### Does one installation support multiple restaurants?

No. One installation serves one restaurant and one database. That restaurant can have several locations under the same admin.

### What is a CDP? What is RudderStack?

A customer data platform (CDP) collects events so the funnel and the failures sit in one place. Dilibori's optional analytics stack is that layer.

Events come from the browser (page views, product lists) and from PHP (order placed, payment started, order completed, status changes, refunds, coupons, logins). Names and properties are in the [event catalog](cdp/README.md#event-catalog).

RudderStack receives those events. `make cdp-up` starts a local data plane. No RudderStack Cloud account is required. The inspector at http://127.0.0.1:8090 shows the traffic.

### Does analytics require RudderStack Cloud?

No. The included data plane runs locally. You can leave analytics off.

### Do I need a payment processor such as Stripe?

No. Checkout works with cash on delivery. The El Taconazo demo is seeded that way. Stripe, PayPal Express, Square, Mollie, and Authorize.Net appear in admin after you add credentials. See [payments](docs/product/owner-admin-features.md#payments).

### Do the storefront and admin support multiple languages?

Yes. El Taconazo ships English (default) and Spanish (Mexico). The header picker switches storefront chrome, dish names, and legal pages. Each staff user picks an admin language under Profile. Seeded staff starts in English. See [language and currency](docs/product/customer-features.md#language-and-currency) and the [app multilingual notes](app/README.md#multilingual-notes).

### Can I customize the look and feel of my store? 🍽️

Yes. Admin can change theme colors, logo, header accent, homepage slider, banners, and footer social links. The storefront uses TastyIgniter's Orange theme. El Taconazo ships with its own colors and banners. See [look and feel](docs/product/customer-features.md#look-and-feel).

### Does the demo send email?

Messages go to Mailpit. Nothing reaches a real inbox. See [mailpit/README.md](mailpit/README.md).

### Where is the generated admin password?

Run `make admin-pass`. The password is created on first provision and is not in `docker-compose.yml`. Storage and rotation are in [app/README.md](app/README.md#admin-password).

### Where is the feature list?

Start with the [product overview](docs/product/README.md), then the customer and owner guides.

## Contributing

Bug reports, documentation fixes, and focused pull requests are welcome. For code changes, describe the behavior you are changing and include the smallest check that fails if that behavior breaks.

`make install-hooks` points git at `scripts/git-hooks`. The pre-commit hook runs Ruff on `cdp/event-sink`.

## License

Dilibori-authored code is available under the [MIT License](LICENSE).

The services and application framework used by the local stack keep their own licenses:

- [TastyIgniter](https://github.com/tastyigniter/TastyIgniter/blob/4.x/LICENSE.md): MIT
- [Mailpit](https://github.com/axllent/mailpit/blob/master/LICENSE): MIT
- [MariaDB Server](https://github.com/MariaDB/server/blob/main/COPYING): GPL-2.0-only
- [RudderStack Server](https://github.com/rudderlabs/rudder-server/blob/master/LICENSE) and [RudderStack Transformer](https://github.com/rudderlabs/rudder-transformer/blob/develop/LICENSE): Elastic License 2.0

The Dilibori license does not replace those terms. Elastic License 2.0 restricts offering RudderStack itself as a hosted or managed service. Review the upstream licenses before you distribute images or run those components as part of a commercial service.
