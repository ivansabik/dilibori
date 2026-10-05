#!/bin/bash
# Nightly MariaDB + Postgres dumps to the backups bucket.
set -euo pipefail

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
. /etc/dilibori/box.env
set +a

OPT_ROOT=/opt/dilibori
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

ENV_NAME="aws-${PLATFORM_BOX_ENV}"
COMPOSE_PROJECT="dilibori-${ENV_NAME}"
REPO_ROOT="${OPT_ROOT}/releases/current"
PLATFORM_DIR="${REPO_ROOT}/platform"

cd "${PLATFORM_DIR}"
docker compose -p "${COMPOSE_PROJECT}" \
  -f docker-compose.yml -f docker-compose.aws.yml \
  --env-file .env --env-file "envs/${ENV_NAME}.env" \
  exec -T mariadb \
  mysqldump -uroot -p"${MARIADB_ROOT_PASSWORD}" --all-databases --single-transaction \
  | gzip >"${TMPDIR}/mariadb-${STAMP}.sql.gz"

docker compose -p "${COMPOSE_PROJECT}" \
  -f docker-compose.yml -f docker-compose.aws.yml \
  --env-file .env --env-file "envs/${ENV_NAME}.env" \
  exec -T postgres \
  pg_dumpall -U controlplane \
  | gzip >"${TMPDIR}/postgres-${STAMP}.sql.gz"

aws s3 cp "${TMPDIR}/mariadb-${STAMP}.sql.gz" \
  "s3://${BACKUPS_BUCKET}/db/${PLATFORM_BOX_ENV}/mariadb-${STAMP}.sql.gz"
aws s3 cp "${TMPDIR}/postgres-${STAMP}.sql.gz" \
  "s3://${BACKUPS_BUCKET}/db/${PLATFORM_BOX_ENV}/postgres-${STAMP}.sql.gz"

# Keep two days of local copies for emergency restore.
mkdir -p "${OPT_ROOT}/backups"
cp "${TMPDIR}/"*.gz "${OPT_ROOT}/backups/"
find "${OPT_ROOT}/backups" -type f -mtime +2 -delete

echo "backup-ok stamp=${STAMP}"
aws cloudwatch put-metric-data \
  --region "${AWS_REGION}" \
  --namespace dilibori/backups \
  --metric-name BackupOk \
  --value 1 \
  --dimensions "Environment=${PLATFORM_BOX_ENV}" || true
