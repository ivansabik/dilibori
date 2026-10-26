#!/bin/bash
# Nightly MariaDB dump to the backups bucket.
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

REPO_ROOT="${OPT_ROOT}/releases/current"

cd "${REPO_ROOT}"
docker compose -f app/docker-compose.aws.yml --env-file "${OPT_ROOT}/app.env" \
  exec -T db \
  mariadb-dump -uroot -p"${MARIADB_ROOT_PASSWORD}" --all-databases --single-transaction \
  | gzip >"${TMPDIR}/mariadb-${STAMP}.sql.gz"

aws s3 cp "${TMPDIR}/mariadb-${STAMP}.sql.gz" \
  "s3://${BACKUPS_BUCKET}/db/${PLATFORM_BOX_ENV}/mariadb-${STAMP}.sql.gz"

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
