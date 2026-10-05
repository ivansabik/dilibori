#!/bin/bash
# Post-deploy smoke checks. Dev provisions pilots; prod checks live hosts.
set -euo pipefail

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
. /etc/dilibori/box.env
set +a

OPT_ROOT=/opt/dilibori
REPO_ROOT="${OPT_ROOT}/releases/current"
PLATFORM_DIR="${REPO_ROOT}/platform"
ENV_NAME="aws-${PLATFORM_BOX_ENV}"
DOMAIN="${DOMAIN:?DOMAIN required}"
CP_PORT=8099
AGENT_PORT=8100

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
. "${PLATFORM_DIR}/envs/${ENV_NAME}.env"
set +a

CP_URL="http://127.0.0.1:${CONTROL_PLANE_PORT:-$CP_PORT}"

echo "Waiting for control plane at ${CP_URL}..."
for _ in $(seq 1 60); do
  if curl -sf "${CP_URL}/health" >/dev/null; then
    break
  fi
  sleep 2
done
curl -sf "${CP_URL}/health" | tee /tmp/dilibori-cp-health.json
echo

echo "Checking ingress /healthz..."
curl -sf "http://127.0.0.1/healthz" | grep -q ok
echo "ingress ok"

if [ "${PLATFORM_BOX_ENV}" = "dev" ]; then
  echo "Provisioning pilots..."
  CONTROL_PLANE_URL="${CP_URL}" \
  RUNTIME_AGENT_URL="http://127.0.0.1:${RUNTIME_AGENT_PORT:-$AGENT_PORT}" \
  PLATFORM_ENV=dev \
  DEPLOYMENT_ENVIRONMENT=development \
  INGRESS_HTTP_PORT=443 \
  PILOT_DOMAIN="${DOMAIN}" \
  "${PLATFORM_DIR}/scripts/provision-pilots.sh"

  for host in "taconazo.${DOMAIN}" "pizza-rin.${DOMAIN}"; do
    echo "Checking https://${host}/ ..."
    code="$(curl -sS -o /tmp/dilibori-smoke.html -w '%{http_code}' --max-time 30 "https://${host}/" || true)"
    if [ "$code" != "200" ]; then
      echo "FAIL: ${host} returned HTTP ${code}" >&2
      exit 1
    fi
    if grep -qiE 'rudderanalytics|rudderstack|cdn\.rudderlabs' /tmp/dilibori-smoke.html; then
      echo "FAIL: ${host} HTML contains RudderStack snippet (CDP should be off)" >&2
      exit 1
    fi
    admin_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "https://${host}/admin/login" || true)"
    if [ "$admin_code" != "200" ]; then
      echo "FAIL: ${host}/admin/login returned HTTP ${admin_code}" >&2
      exit 1
    fi
    echo "ok ${host}"
  done
else
  echo "Prod smoke: checking active tenant hostnames..."
  hosts="$(
    curl -sf "${CP_URL}/tenants" | python3 -c '
import json,sys,urllib.request
base="'"${CP_URL}"'"
tenants=json.load(sys.stdin).get("tenants",[])
for t in tenants:
  detail=json.load(urllib.request.urlopen(base+"/tenants/"+t["id"]))
  for d in detail.get("deployments") or []:
    if d.get("status")=="active" and d.get("hostname"):
      print(d["hostname"])
'
  )"
  if [ -z "$hosts" ]; then
    echo "No active deployments; /healthz and control plane health already passed."
  else
    while IFS= read -r host; do
      [ -n "$host" ] || continue
      code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "https://${host}/" || true)"
      if [ "$code" != "200" ]; then
        echo "FAIL: ${host} returned HTTP ${code}" >&2
        exit 1
      fi
      echo "ok ${host}"
    done <<<"$hosts"
  fi
fi

echo "smoke ok env=${PLATFORM_BOX_ENV}"
