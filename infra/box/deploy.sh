#!/bin/bash
# Deploy a release SHA onto the box. Invoked via SSM or user-data.
# Usage: deploy.sh <sha>
set -euo pipefail

SHA="${1:-}"
if [ -z "$SHA" ]; then
  echo "usage: deploy.sh <sha>" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
. /etc/dilibori/box.env
set +a

OPT_ROOT=/opt/dilibori
ACCOUNT_ID="$(aws sts get-caller-identity --query Account --output text)"
ECR="${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
TAG="sha-${SHA:0:7}"

aws s3 cp "s3://${ARTIFACTS_BUCKET}/releases/${SHA}.tar.gz" /tmp/dilibori-release.tar.gz
mkdir -p "${OPT_ROOT}/releases/${SHA}"
tar -xzf /tmp/dilibori-release.tar.gz -C "${OPT_ROOT}/releases/${SHA}"
ln -sfn "${OPT_ROOT}/releases/${SHA}" "${OPT_ROOT}/releases/current"
ln -sfn "${OPT_ROOT}/releases/current/infra/box" /usr/local/lib/dilibori/box

REPO_ROOT="${OPT_ROOT}/releases/current"
mkdir -p "${OPT_ROOT}/data/media" "${OPT_ROOT}/data/db" "${OPT_ROOT}/caddy-data/logs"

if [ -f "${REPO_ROOT}/infra/box/cloudwatch-agent.json" ]; then
  sed "s|\${environment}|${PLATFORM_BOX_ENV}|g" \
    "${REPO_ROOT}/infra/box/cloudwatch-agent.json" \
    >/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json
  /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl \
    -a fetch-config -m ec2 -s \
    -c file:/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json || true
fi

{
  echo "DOMAIN=${DOMAIN}"
  echo "APP_IMAGE=${ECR}/dilibori/tastyigniter:${TAG}"
  echo "MARIADB_ROOT_PASSWORD=${MARIADB_ROOT_PASSWORD}"
  echo "DB_PASSWORD=${DB_PASSWORD}"
  echo "AWS_REGION=${AWS_REGION}"
  echo "PLATFORM_BOX_ENV=${PLATFORM_BOX_ENV}"
  echo "HOST_MEDIA_PATH=${OPT_ROOT}/data/media"
  echo "HOST_DB_PATH=${OPT_ROOT}/data/db"
  echo "HOST_CADDY_PATH=${OPT_ROOT}/caddy-data"
  echo "CDP_ENABLED=false"
  if [ -n "${MAIL_HOST:-}" ]; then
    echo "MAIL_MAILER=${MAIL_MAILER:-smtp}"
    echo "MAIL_HOST=${MAIL_HOST}"
    echo "MAIL_PORT=${MAIL_PORT:-587}"
    echo "MAIL_USERNAME=${MAIL_USERNAME:-}"
    echo "MAIL_PASSWORD=${MAIL_PASSWORD:-}"
    echo "MAIL_ENCRYPTION=${MAIL_ENCRYPTION:-tls}"
  fi
} >"${OPT_ROOT}/app.env"
chmod 0600 "${OPT_ROOT}/app.env"

aws ecr get-login-password --region "${AWS_REGION}" \
  | docker login --username AWS --password-stdin "${ECR}"

docker network inspect dilibori >/dev/null 2>&1 || docker network create dilibori

cd "${REPO_ROOT}"
docker compose -f app/docker-compose.aws.yml --env-file "${OPT_ROOT}/app.env" \
  up -d --pull always --no-build

for _ in $(seq 1 90); do
  if curl -sf -o /dev/null "http://127.0.0.1/"; then
    break
  fi
  sleep 5
done

aws ssm put-parameter \
  --region "${AWS_REGION}" \
  --name "${RELEASE_PARAM}" \
  --type String \
  --value "${SHA}" \
  --overwrite >/dev/null

echo "deployed sha=${SHA} tag=${TAG} domain=${DOMAIN}"
