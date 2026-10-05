# AWS operations

Single-box deploy of the platform compose stack to two AWS accounts (dev and prod). Architecture background: [`platform/docs/architecture.md`](../platform/docs/architecture.md).

## Prerequisites

- Two AWS accounts with root MFA
- A domain. Prod account hosts the apex Route53 zone and delegates `dev.<domain>` to the dev account
- Terraform >= 1.5, AWS CLI v2, Docker
- GitHub CLI against github.com: `gh --hostname github.com auth login` (local `gh` may point at another host)

## One-time bootstrap (per account)

From a machine with admin credentials for that account:

```sh
cd infra/terraform/bootstrap
cp terraform.tfvars.example terraform.tfvars
# set environment = "dev" or "prod"
terraform init
terraform apply
terraform output
```

Record:

| Output | Use |
|--------|-----|
| `state_bucket` / `lock_table` | Uncomment the S3 backend in `envs/<env>/versions.tf` |
| `github_deploy_role_arn` | GitHub Actions variable `DEV_AWS_ROLE_ARN` or `PROD_AWS_ROLE_ARN` |
| `aws_account_id` | `DEV_AWS_ACCOUNT_ID` / `PROD_AWS_ACCOUNT_ID` |

Then in `envs/dev` and `envs/prod`:

1. Uncomment the `backend "s3"` block and fill bucket / table
2. `cp terraform.tfvars.example terraform.tfvars` and set `domain`, `alert_email`, `prod_account_id` (dev only)
3. `terraform init` and a first `terraform apply` (or let the PR workflow do it after variables exist)

### GitHub settings

Create environments `dev` (no protection) and `prod` (required reviewer: you).

Repository variables:

| Variable | Example |
|----------|---------|
| `AWS_REGION` | `us-east-1` |
| `DEV_AWS_ROLE_ARN` | from bootstrap |
| `PROD_AWS_ROLE_ARN` | from bootstrap |
| `DEV_AWS_ACCOUNT_ID` | 12 digits |
| `PROD_AWS_ACCOUNT_ID` | 12 digits |
| `DEV_ARTIFACTS_BUCKET` | from `terraform output artifacts_bucket` (dev) |
| `PROD_ARTIFACTS_BUCKET` | from prod output |
| `DEV_DOMAIN` | `dev.example.com` |
| `PROD_DOMAIN` | `example.com` |
| `ALERT_EMAIL` | ops inbox |

No AWS access keys in GitHub. Deploy uses OIDC only.

Branch protection on `main`: require a PR; require status checks `ruff`, `contracts`, `deploy-dev`; no force push.

## Test locally

```sh
cd platform
make env-copy
make build-app
make up
make test
make lint
make pilots
```

Optional CDP (local only):

```sh
make -C ../cdp up
# smoke from cdp/README.md
```

Infra checks without AWS credentials:

```sh
terraform fmt -check -recursive infra/terraform
(cd infra/terraform/bootstrap && terraform init -backend=false && terraform validate)
(cd infra/terraform/envs/dev && terraform init -backend=false && terraform validate)
(cd infra/terraform/envs/prod && terraform init -backend=false && terraform validate)
shellcheck infra/box/*.sh
docker compose \
  -f platform/docker-compose.yml \
  -f platform/docker-compose.aws.yml \
  --env-file platform/envs/aws-dev.env \
  config -q
```

## Deploy to dev (PR)

1. Open a PR to `main` that touches `platform/`, `app/`, or `infra/`
2. Workflow [`.github/workflows/aws-deploy.yml`](../.github/workflows/aws-deploy.yml):
   - **build:** three images → dev ECR as `sha-<short7>`, Trivy CRITICAL gate, `git archive` → artifacts bucket
   - **deploy-dev:** `terraform plan` (commented on the PR), `apply`, SSM `deploy.sh`, SSM `smoke.sh`
3. Smoke provisions Taconazo + Pizza Rin on `*.DEV_DOMAIN`, checks HTTPS 200, and asserts the HTML has no RudderStack snippet
4. Job summary lists storefront and admin URLs

### Reach private UIs (SSM port-forward)

```sh
INSTANCE_ID=$(cd infra/terraform/envs/dev && terraform output -raw instance_id)
# Control plane UI
aws ssm start-session --target "$INSTANCE_ID" \
  --document-name AWS-StartPortForwardingSession \
  --parameters '{"portNumber":["8099"],"localPortNumber":["8099"]}'
# then open http://127.0.0.1:8099/

# Mailpit
aws ssm start-session --target "$INSTANCE_ID" \
  --document-name AWS-StartPortForwardingSession \
  --parameters '{"portNumber":["8026"],"localPortNumber":["8026"]}'
```

Admin password after pilots (through the control-plane forward):

```sh
CONTROL_PLANE_URL=http://127.0.0.1:8099 \
  platform/scripts/print-admin-password.sh taconazo.dev.example.com
```

Logs: CloudWatch log groups `/dilibori/dev/platform` and `/dilibori/dev/caddy`.

## Deploy to prod

On the same PR run, approve the GitHub Environment `prod` when prompted.

`deploy-prod`:

- Pulls images and the release tarball from the **dev** account, pushes them into **prod** ECR / artifacts
- `terraform apply` in `envs/prod`
- SSM `deploy.sh` + `smoke.sh` (no pilots; checks `/healthz`, control plane `/health`, every active tenant hostname)

Rolling existing tenants onto the new image stays on the control plane release API (`POST /releases`, then `/deployments/{id}/deploy`). `deploy.sh` only updates the agent allowlist so **new** tenants use the new tag.

## Runbooks

### Rollback

Actions → AWS deploy → Run workflow → choose environment and `image_tag=sha-<previous>`.

### Replace the EC2 instance

```sh
cd infra/terraform/envs/<env>
terraform taint module.box.aws_instance.box
terraform apply
```

The data volume reattaches. User-data reads `/dilibori/<env>/release_sha` from SSM and redeploys that SHA.

### Restore a tenant database

1. Download a dump from `s3://dilibori-backups-<env>-*/db/<env>/mariadb-*.sql.gz`
2. SSM into the box, `gunzip` and load into the tenant schema (or restore all databases from the dump)
3. Or restore the whole data volume from a DLM snapshot

### Rotate the platform secret

Update values in Secrets Manager (`dilibori/<env>/platform`), then SSM `deploy.sh <current-sha>` so `/etc/dilibori/box.env` and `platform/.env` are rewritten. Rotate tenant DB passwords separately through the control plane if needed.

### Grow instance size

Set `instance_type` in the env `terraform.tfvars` (for example `t3a.large`) and apply via PR.

### Alarms

| Alarm | Meaning | First check |
|-------|---------|-------------|
| status-check | EC2 failed | Instance console / replace host |
| memory / disk | Resource pressure | `docker stats`, prune images, grow volume |
| health-* | Compose service down | `docker compose ps`, container logs |
| caddy-5xx | Bad responses | Caddy access log in CloudWatch |
| backup-missing | Nightly dump did not run | `backup.sh` cron log on the box |

## Growing out of the single box

Apply each step to **dev first** through a PR, then prod.

1. **MariaDB → RDS** (~$25/mo): dump/restore, point ProxySQL and agent `MARIADB_HOST` at the endpoint, drop the container. Gains PITR / Multi-AZ.
2. **Postgres → RDS** (~$13/mo): same pattern; keep `seed.sh` / migrations.
3. **ALB + ACM wildcard** (~$18/mo): put ALB in front of Caddy; snippets can return to `http://` site blocks; unlocks WAF and ALB metrics.
4. **Control plane → Fargate** (~$9/mo): EFS for `SECRETS_PATH`, Cloud Map DNS to the agent; needs a multi-worker PHP image.
5. **Second cell:** another `box` module, `POST /cells`, capacity-aware schedule.
6. **Queue → SQS** per tenant when database polling load bites (`platform/docs/scale-findings.md`).

## Adding RudderStack later

Out of scope for the first AWS cut. Prerequisites: a real destination (S3 or a warehouse), and a decision on write-key rotation.

### Phase A (on the box)

1. Add write keys and `JOBS_DB_PASSWORD` to Secrets Manager
2. `deploy.sh` generates `workspaceConfig.json` and runs `make -C cdp up`
3. Caddy site `cdp.<domain>` → `rudder-server:8080`; DNS A record
4. Agent settings for CDP URLs and write keys replace the local hardcoded `_cdp_env()` values; set `CDP_ENABLED=true`
5. Smoke posts `POST /v1/track`; health metrics include `rudder-server`
6. Inspector stays localhost-only (SSM port-forward)
7. Expect ~1 GB more RAM → `instance_type = t3a.large` when needed
8. Point `workspaceConfig.json` at the real destination instead of `event-sink`

### Phase B (off the box)

RudderStack on Fargate (server + transformer + config-init), jobsdb on RDS Postgres, ALB rule for `cdp.<domain>`. Worth it after MariaDB/Postgres or ALB steps exist.

Tenants already stamp `TENANT_ID`. Event names: [`app/extensions/dilibori/cdp/EVENTS.md`](../app/extensions/dilibori/cdp/EVENTS.md).
