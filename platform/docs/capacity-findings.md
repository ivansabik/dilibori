# PoC capacity findings

Measured from the connection and memory model (not live load yet).

## Defaults

| Knob | Value |
|------|-------|
| Idle memory / tenant | 180 MB (assumption until live RSS is collected) |
| Peak memory / tenant | 320 MB |
| Connections / tenant | 5 (`MAX_USER_CONNECTIONS`, production-like) |
| ProxySQL backend budget | 80 |
| Operator reserve | 10 connections |
| Host memory (example) | 8 GiB |

## Result

Recommended cell limit: **16 tenants** (connection budget binds before idle memory on an 8 GiB host).

Idle memory alone would allow about 45 tenants on 8 GiB. ProxySQL `max_connections=80` with 5 connections per tenant and 10 reserved is the tighter constraint.

## Follow-ups

1. After `make pilots`, sample container RSS with `docker stats` and replace the 180 MB assumption.
2. Raise ProxySQL budget or lower per-tenant Apache workers before packing more than 16 tenants into a cell.
3. Keep PostgreSQL (control plane) on its own PgBouncer pool; do not share it with tenant MariaDB math.
