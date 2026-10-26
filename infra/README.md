# AWS operations

One EC2 box per environment runs the El Taconazo app and MariaDB. Dev and prod are separate AWS accounts. A pull request builds the app image, deploys it to dev, and smoke-tests the storefront. Prod runs only after the `prod` GitHub Environment is approved on that same workflow.

Local architecture stays in the [root README](../README.md) and [app/README.md](../app/README.md).

## What runs on the box

| Piece | Role |
|---|---|
| Caddy | TLS on 443, reverse proxy to the app |
| App container | TastyIgniter storefront and `/admin` |
| MariaDB 10.11 | The restaurant database |
| Data volume | Database files, uploaded media, Caddy certificates |

The app uses file cache and a database queue. RudderStack is not started. `CDP_ENABLED=false`. Dev mail uses the `log` mailer. Prod mail is SES SMTP after you verify the domain and leave the sandbox.

Locations stay path-based (`/el-taconazo-alameda-ave/menus`). DNS is one A record for the environment domain, not a wildcard.

## Prerequisites

- Two AWS accounts with root MFA
- A domain. The prod account hosts the apex Route53 zone and delegates `dev.<domain>` to the dev account
- Terraform >= 1.5, AWS CLI v2, Docker
- GitHub CLI against github.com: `gh --hostname github.com auth login`

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
|---|---|
| `state_bucket` / `lock_table` | Uncomment the S3 backend in `envs/<env>/versions.tf` |
| `github_deploy_role_arn` | GitHub Actions variable `DEV_AWS_ROLE_ARN` or `PROD_AWS_ROLE_ARN` |
| `aws_account_id` | `DEV_AWS_ACCOUNT_ID` / `PROD_AWS_ACCOUNT_ID` |

Then in `envs/dev` and `envs/prod`:

1. Uncomment the `backend "s3"` block and fill bucket / table
2. `cp terraform.tfvars.example terraform.tfvars` and set `domain`, `alert_email`, `prod_account_id` (dev only)
3. `terraform init` and a first `terraform apply` (or let the PR workflow do it after variables exist)

### GitHub settings

Create environments `dev` (no protection) and `prod` (required reviewer: you).

| Variable | Example |
|---|---|
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

Branch protection on `main`: require a PR; require status checks `ruff`, `terraform`, `box-scripts`, `deploy-dev`; no force push.

## Test locally

```sh
make up
make seed
make admin-pass
```

Optional CDP and mail (local only):

```sh
make cdp-up
make mailpit-up
```

Infra checks without AWS credentials:

```sh
terraform fmt -check -recursive infra/terraform
(cd infra/terraform/bootstrap && terraform init -backend=false && terraform validate)
(cd infra/terraform/envs/dev && terraform init -backend=false && terraform validate)
(cd infra/terraform/envs/prod && terraform init -backend=false && terraform validate)
shellcheck infra/box/*.sh
docker compose \
  -f app/docker-compose.aws.yml \
  --env-file app/aws.env.example \
  config -q
```

## Deploy to dev (PR)

1. Open a PR to `main` that touches `app/`, `infra/`, or `.github/workflows/aws-deploy.yml`
2. Workflow [`.github/workflows/aws-deploy.yml`](../.github/workflows/aws-deploy.yml):
   - **build:** app image to dev ECR as `sha-<short7>`, Trivy CRITICAL gate, `git archive` to the artifacts bucket
   - **deploy-dev:** `terraform plan` (commented on the PR), `apply`, SSM `deploy.sh`, SSM `smoke.sh`
3. Smoke checks `https://DEV_DOMAIN/` for HTTP 200 and no RudderStack snippet, then `/admin`
4. The job summary lists the storefront and admin URLs

First boot runs `provision.sh` (`AUTO_PROVISION=true`) and loads the El Taconazo seed from the image.

Admin password (SSM session on the box):

```sh
docker compose -f /opt/dilibori/releases/current/app/docker-compose.aws.yml \
  --env-file /opt/dilibori/app.env \
  exec -T app cat /var/www/html/storage/dilibori-admin-password
```

Logs: CloudWatch groups `/dilibori/dev/app`, `/dilibori/dev/db`, `/dilibori/dev/caddy`, `/dilibori/dev/box`.

## Deploy to prod

On the same PR run, approve the GitHub Environment `prod` when prompted.

`deploy-prod` copies the app image and release tarball from the dev account into prod, applies `envs/prod`, then runs `deploy.sh` and `smoke.sh`. It does not create a second restaurant.

## Runbooks

### Rollback

Actions, AWS deploy, Run workflow. Choose the environment and `image_tag=sha-<previous>`.

### Replace the EC2 instance

```sh
cd infra/terraform/envs/<env>
terraform taint module.box.aws_instance.box
terraform apply
```

The data volume reattaches. User-data reads `/dilibori/<env>/release_sha` from SSM and redeploys that SHA.

### Restore the database

1. Download a dump from `s3://dilibori-backups-<env>-*/db/<env>/mariadb-*.sql.gz`
2. SSM into the box, `gunzip`, and load it with `mariadb` as root
3. Or restore the data volume from a DLM snapshot

### Rotate the platform secret

Update values in Secrets Manager (`dilibori/<env>/platform`), then SSM `deploy.sh <current-sha>` so `/etc/dilibori/box.env` and `/opt/dilibori/app.env` are rewritten. The admin password lives on the data volume, not in that secret.

### Grow instance size

Set `instance_type` in the env `terraform.tfvars` (for example `t3a.large`) and apply via PR.

### Alarms

| Alarm | Meaning | First check |
|---|---|---|
| status-check | EC2 failed | Instance console / replace host |
| memory / disk | Resource pressure | `docker stats`, prune images, grow volume |
| health-app / health-db / health-caddy | Container down | `docker compose ps`, container logs |
| caddy-5xx | Bad responses | Caddy access log in CloudWatch |
| backup-missing | Nightly dump did not run | `backup.sh` cron log on the box |

## Growing out of the single box

Apply each step to dev first through a PR, then prod.

1. **MariaDB to RDS** (~$25/mo): dump/restore, point `DB_HOST` at the endpoint, drop the `db` container. Gains point-in-time restore and a Multi-AZ option.
2. **ALB + ACM certificate** (~$18/mo): put an ALB in front of Caddy. Unlocks WAF and ALB metrics.
3. **Queue to SQS** when the database jobs table is busy. The app uses `QUEUE_CONNECTION=database` today.

## Adding RudderStack later

Out of scope for this cut. Needs a real destination (S3 or a warehouse) and a decision on write-key rotation.

1. Add write keys to Secrets Manager
2. Start `cdp/docker-compose.yml` on the box and set `CDP_ENABLED=true` on the app
3. Point Caddy at `cdp.<domain>` if the data plane should be public
4. Smoke one `POST /v1/track`
5. Expect about 1 GB more RAM. Move `instance_type` to `t3a.large` if the box is tight

Event names: [cdp/README.md](../cdp/README.md).
