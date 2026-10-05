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
# Make ENV selects aws-dev/aws-prod compose flags; PLATFORM_ENV inside those
# files is still dev|prod so compose data paths stay ./data/dev|prod.
ENV_NAME="aws-${PLATFORM_BOX_ENV}"
DATA_ENV="${PLATFORM_BOX_ENV}"
ACCOUNT_ID="$(aws sts get-caller-identity --query Account --output text)"
ECR="${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
TAG="sha-${SHA:0:7}"

aws s3 cp "s3://${ARTIFACTS_BUCKET}/releases/${SHA}.tar.gz" /tmp/dilibori-release.tar.gz
mkdir -p "${OPT_ROOT}/releases/${SHA}"
tar -xzf /tmp/dilibori-release.tar.gz -C "${OPT_ROOT}/releases/${SHA}"
ln -sfn "${OPT_ROOT}/releases/${SHA}" "${OPT_ROOT}/releases/current"
ln -sfn "${OPT_ROOT}/releases/current/infra/box" /usr/local/lib/dilibori/box

REPO_ROOT="${OPT_ROOT}/releases/current"
PLATFORM_DIR="${REPO_ROOT}/platform"

mkdir -p "${OPT_ROOT}/platform-data/${DATA_ENV}"/{ingress/tenants,minio,secrets,tenants,snapshots}
mkdir -p "${PLATFORM_DIR}/data"
ln -sfn "${OPT_ROOT}/platform-data/${DATA_ENV}" "${PLATFORM_DIR}/data/${DATA_ENV}"

if [ -f "${REPO_ROOT}/infra/box/cloudwatch-agent.json" ]; then
  sed "s|\${environment}|${PLATFORM_BOX_ENV}|g" \
    "${REPO_ROOT}/infra/box/cloudwatch-agent.json" \
    >/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json
  /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl \
    -a fetch-config -m ec2 -s \
    -c file:/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json || true
fi

{
  echo "MARIADB_ROOT_PASSWORD=${MARIADB_ROOT_PASSWORD}"
  echo "PROXYSQL_ADMIN_PASSWORD=${PROXYSQL_ADMIN_PASSWORD}"
  echo "POSTGRES_PASSWORD=${POSTGRES_PASSWORD}"
  echo "RUNTIME_AGENT_TOKEN=${RUNTIME_AGENT_TOKEN}"
  echo "APP_SECRET_KEY=${APP_SECRET_KEY}"
  echo "TENANT_IMAGE=${ECR}/dilibori/tastyigniter:${TAG}"
  echo "ALLOWED_IMAGES=${ECR}/dilibori/tastyigniter:${TAG}"
  echo "CONTROL_PLANE_IMAGE=${ECR}/dilibori/control-plane:${TAG}"
  echo "RUNTIME_AGENT_IMAGE=${ECR}/dilibori/runtime-agent:${TAG}"
  echo "HOST_SEEDS_PATH=${PLATFORM_DIR}/seeds"
  echo "HOST_EXTENSIONS_PATH=${REPO_ROOT}/app/extensions"
  echo "HOST_DATA_PATH=${PLATFORM_DIR}/data/${DATA_ENV}"
  echo "HOST_APP_SEED_PATH=${REPO_ROOT}/app/seed"
  echo "MEDIA_BUCKET=${MEDIA_BUCKET}"
  echo "OBJECT_STORE_IDENTITY=iam"
  echo "OBJECT_STORE_IAM_PATH=dilibori/${PLATFORM_BOX_ENV}/tenants/"
  echo "MINIO_ENDPOINT=s3.${AWS_REGION}.amazonaws.com"
  echo "MINIO_SECURE=true"
  echo "MINIO_ROOT_USER="
  echo "MINIO_ROOT_PASSWORD="
  echo "INGRESS_SCHEME=https"
  echo "CDP_ENABLED=false"
  echo "DOMAIN=${DOMAIN}"
  # Override aws-*.env placeholder so seed/migrate use the real domain.
  echo "INGRESS_BASE_URL=https://${DOMAIN}"
  echo "PILOT_DOMAIN=${DOMAIN}"
  if [ -n "${TENANT_MAIL_HOST:-}" ]; then
    echo "TENANT_MAIL_HOST=${TENANT_MAIL_HOST}"
    echo "TENANT_MAIL_PORT=${TENANT_MAIL_PORT:-587}"
    echo "TENANT_MAIL_USERNAME=${TENANT_MAIL_USERNAME:-}"
    echo "TENANT_MAIL_PASSWORD=${TENANT_MAIL_PASSWORD:-}"
    echo "TENANT_MAIL_ENCRYPTION=${TENANT_MAIL_ENCRYPTION:-tls}"
  fi
} >"${PLATFORM_DIR}/.env"
chmod 0600 "${PLATFORM_DIR}/.env"

aws ecr get-login-password --region "${AWS_REGION}" \
  | docker login --username AWS --password-stdin "${ECR}"

cd "${PLATFORM_DIR}"
make up ENV="${ENV_NAME}" COMPOSE_UP_FLAGS="--no-build --pull always"
make migrate-schema ENV="${ENV_NAME}" || true

for _ in $(seq 1 60); do
  if curl -sf http://127.0.0.1/healthz >/dev/null 2>&1; then
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

echo "deployed sha=${SHA} tag=${TAG} env=${ENV_NAME}"
