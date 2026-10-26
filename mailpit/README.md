# Dilibori Mailpit

Local SMTP catcher. Messages stay on this machine.

The storefront sends order, reservation, and staff mail here during local development. The app image and seed are in [app/README.md](../app/README.md). The repo overview is in the [root README](../README.md).

## Quick start

```sh
make up
```

| Service | URL |
|---------|-----|
| Web UI | http://127.0.0.1:8025 |
| SMTP (from the app container) | `mailpit:1025` |
| SMTP (from the host) | localhost:1025 |

From the repo root: `make mailpit-up`.

App PHP sends to `mailpit:1025` on the shared `dilibori` Docker network. Start Mailpit, then the storefront, then seed so TastyIgniter stores the SMTP settings:

```sh
make up
cd ../app
make up
make seed
```

If the app was already running, recreate it so it picks up the mail env: `cd ../app && docker compose up -d --force-recreate app`. Then `make seed` again.

After seed, the From name is El Taconazo Taqueria and the address is `hola@eltaconazo.example`.

## Smoke test

Requires Mailpit and the app container, after `make seed`:

```sh
make smoke
```

From the repo root: `make mailpit-smoke`.

That sends a uniquely tagged message from inside the app container and checks the Mailpit API for From name El Taconazo Taqueria.

Manual check: Admin, Manage, Settings, Mail, Send test email. Then open http://127.0.0.1:8025.

## Make targets

```sh
make help
make up / make down
make health / make logs / make ps
make smoke
make clean   # wipe captured mail in ./data
```
