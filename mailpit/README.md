# Dilibori Mailpit

Local SMTP catcher for El Taconazo / TastyIgniter. Nothing is delivered to a real inbox.

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

App PHP sends to `mailpit:1025` on the shared `dilibori` Docker network. Start this stack, then the storefront, then seed so TastyIgniter stores SMTP settings:

```sh
make up
cd ../app
make up
make seed
```

If the app was already running, recreate it so it joins the network with the new mail env: `cd ../app && docker compose up -d --force-recreate app`. Then `make seed` again.

Sender name after seed: **El Taconazo Taqueria**. Sender address: `hola@eltaconazo.example`.

## Smoke test

Requires Mailpit and the app container, after `make seed`:

```sh
make smoke
```

From the repo root: `make mailpit-smoke`.

That sends a uniquely tagged message from inside the app container and checks the Mailpit API for From name **El Taconazo Taqueria**.

Manual check: Admin → Manage → Settings → Mail → Send test email, then open http://127.0.0.1:8025.

## Make targets

```sh
make help
make up / make down
make health / make logs / make ps
make smoke
make clean   # wipe captured mail in ./data
```
