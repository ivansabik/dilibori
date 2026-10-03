# Scale findings: 1000 tenants

Sibling to [capacity-findings.md](capacity-findings.md). That doc sizes one cell.
This one asks whether the PoC can grow to about 1000 tenants, what breaks first,
and which fixes are one-way doors (bake into each tenant at provision) versus
two-way (control plane only, reversible later).

## Verdict

The isolation pattern is right: one MariaDB database and user per deployment,
Redis ACL prefix, MinIO prefix credentials, one container per deployment.
That pattern can hold at 1000 tenants.

The implementation cannot. Today it is a single cell with sequential control
loops and a few isolation gaps. Target shape: many small cells (about 16-30
tenants each), so roughly 40-65 cells, driven by one control plane.

## Math

| Knob | Value | Source |
|------|-------|--------|
| Recommended tenants per cell | ~16 | [capacity-findings.md](capacity-findings.md) |
| Binding constraint | ProxySQL backend budget 80, 5 conns/tenant, 10 reserved | same |
| Idle memory assumption | 180 MB/tenant (unmeasured) | same |
| Cells for 1000 tenants | ~63-72 | 1000 / 16 |
| Seeded `max_deployments` | 50 (unchecked at reserve) | `control-plane/database/seed.sh` |

`DeploymentController::pickCell()` picks the least-loaded schedulable cell under
`capacity_metadata.max_deployments` (default 16). Cells store `agent_url` so the
control plane can call more than one runtime agent. Local PoC still ships one
compose stack by default; `make cell-b-up` starts a second data plane for migrate
tests.

## Culprits (ranked)

Hard blockers on one host (fail around 20-50 tenants):

1. **Capacity-aware placement is in place**, but ProxySQL budget is still not
   checked at reserve (only `max_deployments` occupancy).
2. **Synchronous provisioning.** `ProvisioningStateMachine::advance()` walks
   the whole state machine inside one HTTP request, including a 900s install
   wait. Migrate is synchronous the same way. No queue, no parallelism across
   tenants.
3. **Sequential reconcile.** `Reconciler::reconcile()` does 2+ sync HTTP calls
   per active deployment (ACL ensure + observe), each with a 900s client
   timeout, plus Redis `ACL SAVE` per tenant per pass. At 1000 actives that is
   hours per pass.
4. **Shared Redis `maxmemory 256mb` with `allkeys-lru`.** One noisy tenant
   evicts everyone else's cache and sessions on that cell. Mitigate by
   migrating the noisy deployment to another cell.

Isolation gaps that get worse with tenant count:

5. **Tenants used to bypass ProxySQL** (`DB_HOST=mariadb`). The capacity model
   assumed ProxySQL enforcement the live path skipped. Fixed as a one-way door
   below (tenant env is baked into the container).
6. **Shared Docker network for all tenants.** Per-tenant bridges were dropped
   after Docker Desktop IPv4 pool exhaustion from leaked failed-provision
   networks. Tenants on `dilibori-shared` can reach each other by name. Fixed
   as a one-way door below.
7. **MinIO policies written to disk but not attached to users.** Incomplete
   isolation until credentials are real IAM users. Fixed as a one-way door.
8. **One shared agent bearer token** for every cell. Fine for one cell; needs
   per-cell credentials before multi-cell.

Operational friction:

9. **Caddy: one snippet file per hostname, full reload per route change.**
10. **Tenant state on local disk** (`data/tenants`, `data/secrets`,
    `data/backups`). Losing a cell host is data loss without a restore story.
11. **12-char short IDs** derived from deployment UUIDs name containers, DBs,
    and users. Names cannot change after provision. Collision guard added as a
    one-way door.

## One-way doors (implemented or required early)

These are baked into each tenant at provision. Retrofit cost grows with every
tenant already live.

| Door | Why early | Where |
|------|-----------|--------|
| `DB_HOST=proxysql` (port 6033) + ProxySQL `mysql_users` sync | Env baked into container; capacity math is meaningless without it | `runtime-agent` tasks + MariaDB/ProxySQL services |
| Per-deployment bridge; shared services and ingress join that bridge | Changing networks later means recreating every container | `docker_service.py` |
| MinIO admin user + policy attach (not disk-only JSON) | Credentials issued at provision; incomplete attach means fleet rotation | `minio_service.py` |
| Unique short IDs for container/DB/user names | Names are permanent | control-plane allocate + `naming.py` |

## Two-way doors

Some items below are implemented; the rest stay roadmap so we do not forget.

**Done (cell migrate cut):**

- Capacity-aware `pickCell()` using `max_deployments` (default 16); 409 when
  the fleet is full. See `DeploymentController.php`.
- `agent_url` (and `schedulable`) per cell; `Application::runtimeAgentForCell()`
  resolves the agent from `deployments.cell_id`.
- Operator migrate: `POST /deployments/{id}/migrate` (stop-copy-start, same
  deployment id). Drain: `POST /cells/{id}/drain` with a target cell.
- Local second cell: `make cell-b-up` (`docker-compose.cell-b.yml`).

**Still roadmap:**

- Replace the 180 MB idle assumption with live RSS from `make pilots` +
  `docker stats`, then re-run `make capacity`.
- Per-cell Redis sized to tenant count; drop shared 256 MB `allkeys-lru` as a
  cross-tenant eviction vector (`noeviction` or real per-tenant tracking).
- Queue-backed provisioning worker; API returns `reserved` immediately.
- Paginated, per-cell reconcile; ACL ensure only on drift; honest timeouts per
  operation type.
- Agent `observe()` returns `release_id` / `config_checksum` in the shape the
  reconciler compares so repair actually fires.
- Debounced Caddy reloads (or admin API), not N file writes × N full reloads.
- Per-cell agent tokens via `SecretStore`.
- Backups off local disk (MinIO/S3). Migrate today pulls a snapshot
  agent-to-agent; that path still assumes local dump + bind mounts.

## Non-goals (for now)

- No Kubernetes, service mesh, or multi-region. Cells of compose stay
  debuggable at this size.
- No per-tenant MariaDB or Redis instances. Per-cell shared instances with
  user/ACL isolation hold at 16-30 tenants per cell.
- No HA control plane yet. The data plane keeps serving when the control plane
  is down.

## Related

- Density knobs and connection math: [capacity-findings.md](capacity-findings.md)
- Cell layout and security boundaries: [../README.md](../README.md)
