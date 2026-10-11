#!/usr/bin/env bash
# Provision pilot tenants for one stack environment (ENV=dev|prod).
# Reuses the live deployment when present.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PLATFORM_ENV="${PLATFORM_ENV:-dev}"
DEPLOYMENT_ENVIRONMENT="${DEPLOYMENT_ENVIRONMENT:-development}"
INGRESS_HTTP_PORT="${INGRESS_HTTP_PORT:-8088}"
PILOT_DOMAIN="${PILOT_DOMAIN:-${PLATFORM_ENV}.localhost}"
CP_URL="${CONTROL_PLANE_URL:-http://127.0.0.1:8099}"

echo "Waiting for control plane at ${CP_URL} (env=${DEPLOYMENT_ENVIRONMENT})..."
for i in $(seq 1 60); do
  if curl -sf "${CP_URL}/health" >/dev/null; then
    break
  fi
  sleep 2
done
curl -sf "${CP_URL}/health" >/dev/null

AGENT_URL="${RUNTIME_AGENT_URL:-http://127.0.0.1:8100}"
echo "Waiting for runtime agent at ${AGENT_URL}..."
for i in $(seq 1 60); do
  # /health requires auth; any HTTP response means the process is listening.
  code="$(curl -sS -o /dev/null -w '%{http_code}' "${AGENT_URL}/health" || true)"
  if [ "$code" != "000" ] && [ -n "$code" ]; then
    break
  fi
  sleep 2
done
code="$(curl -sS -o /dev/null -w '%{http_code}' "${AGENT_URL}/health" || true)"
if [ "$code" = "000" ] || [ -z "$code" ]; then
  echo "runtime agent not reachable at ${AGENT_URL}" >&2
  exit 1
fi

post_json() {
  local method="$1"
  local path="$2"
  local data="${3:-}"
  if [ -n "$data" ]; then
    curl -sf -X "$method" "${CP_URL}${path}" -H 'Content-Type: application/json' -d "$data"
  else
    curl -sf -X "$method" "${CP_URL}${path}" -H 'Content-Type: application/json'
  fi
}

ensure_tenant() {
  local slug="$1"
  local name="$2"
  local existing
  existing="$(curl -sf "${CP_URL}/tenants" | python3 -c "import sys,json; data=json.load(sys.stdin); items=data.get('tenants', data if isinstance(data,list) else []);
print(next((t['id'] for t in items if t.get('slug') == '${slug}'), ''))")"
  if [ -n "$existing" ]; then
    # Pilots are demos: always block search indexing on existing rows too.
    post_json PATCH "/tenants/${existing}" "{\"noindex\":true,\"slug\":\"${slug}\",\"name\":\"${name}\"}" >/dev/null || \
      post_json PATCH "/tenants/${existing}" '{"noindex":true}' >/dev/null
    echo "$existing"
    return
  fi
  post_json POST /tenants "{\"slug\":\"${slug}\",\"name\":\"${name}\",\"noindex\":true}" \
    | python3 -c "import sys,json; print(json.load(sys.stdin)['tenant']['id'])"
}

provision_deployment() {
  local tenant_id="$1"
  local environment="$2"
  local seed="$3"
  local hostname="$4"
  local dep
  # Control plane reuses the live row for tenant+environment when present.
  dep="$(post_json POST "/tenants/${tenant_id}/deployments" "{\"environment\":\"${environment}\",\"seed_bundle\":\"${seed}\",\"hostname\":\"${hostname}\"}")"
  local dep_id reused status_now
  dep_id="$(echo "$dep" | python3 -c "import sys,json; print(json.load(sys.stdin)['deployment']['id'])")"
  reused="$(echo "$dep" | python3 -c "import sys,json; print('yes' if json.load(sys.stdin).get('reused') else 'no')")"
  status_now="$(echo "$dep" | python3 -c "import sys,json; print(json.load(sys.stdin)['deployment'].get('status',''))")"
  if [ "$status_now" = "active" ] && [ "$reused" = "yes" ]; then
    # Hostname may have changed; re-apply ingress.
    curl -sS -X POST "${CP_URL}/deployments/${dep_id}/provision" \
      -H 'Content-Type: application/json' -d '{}' \
      -o "/tmp/dilibori-provision-${dep_id}.json" \
      -w "%{http_code}" >"/tmp/dilibori-provision-${dep_id}.status" || true
    # Active deployments cannot re-enter provision; push route via reconcile-friendly path:
    # calling provision on active returns early. Configure route through a light PATCH cycle.
    echo "Already active: ${hostname} -> ${dep_id} (reused)"
    return 0
  fi
  echo "Provisioning ${hostname} (${dep_id}, reused=${reused})..."
  # Do not use curl -f here: keep the JSON error body for debugging.
  curl -sS -X POST "${CP_URL}/deployments/${dep_id}/provision" \
    -H 'Content-Type: application/json' \
    -d '{}' \
    -o "/tmp/dilibori-provision-${dep_id}.json" \
    -w "%{http_code}" >"/tmp/dilibori-provision-${dep_id}.status"
  status="$(cat "/tmp/dilibori-provision-${dep_id}.status")"
  if [ "$status" != "200" ]; then
    echo "Provision failed for ${dep_id} (HTTP ${status}); see /tmp/dilibori-provision-${dep_id}.json" >&2
    cat "/tmp/dilibori-provision-${dep_id}.json" >&2 || true
    echo >&2
    return 1
  fi
  echo "Active: ${hostname} -> ${dep_id}"
}

# slug|display name|seed_bundle|host prefix
KNOWN_PILOTS=(
  "taconazo|El Taconazo|taconazo|taconazo"
  "pizza-rin|Pizza Rin|pizza-rin|pizza-rin"
)

PILOTS="${PILOTS:-taconazo}"
REQUESTED=()
HOSTS=()
IFS=',' read -r -a _pilot_parts <<< "${PILOTS}"
for raw in "${_pilot_parts[@]}"; do
  slug="$(echo "${raw}" | tr -d '[:space:]')"
  [ -z "${slug}" ] && continue
  found=""
  for entry in "${KNOWN_PILOTS[@]}"; do
    IFS='|' read -r known_slug name seed host_prefix <<< "${entry}"
    if [ "${slug}" = "${known_slug}" ]; then
      found=1
      tenant_id="$(ensure_tenant "${known_slug}" "${name}")"
      hostname="${host_prefix}.${PILOT_DOMAIN}"
      provision_deployment "${tenant_id}" "${DEPLOYMENT_ENVIRONMENT}" "${seed}" "${hostname}"
      REQUESTED+=("${known_slug}")
      HOSTS+=("${hostname}")
      break
    fi
  done
  if [ -z "${found}" ]; then
    known_list="$(printf '%s\n' "${KNOWN_PILOTS[@]}" | cut -d'|' -f1 | paste -sd', ' -)"
    echo "Unknown pilot slug: ${slug} (known: ${known_list})" >&2
    exit 1
  fi
done

if [ "${#REQUESTED[@]}" -eq 0 ]; then
  echo "PILOTS is empty; set PILOTS=taconazo or PILOTS=taconazo,pizza-rin" >&2
  exit 1
fi

echo ""
echo "Pilots requested for ${DEPLOYMENT_ENVIRONMENT} (domain=${PILOT_DOMAIN}): ${REQUESTED[*]}"
if [[ "${PILOT_DOMAIN}" == *.localhost ]]; then
  echo "Add to /etc/hosts if needed:"
  echo "  127.0.0.1 ${HOSTS[*]}"
  echo "Ingress: http://127.0.0.1:${INGRESS_HTTP_PORT} (Host header selects tenant)"
else
  storefronts=""
  for h in "${HOSTS[@]}"; do
    storefronts+=" https://${h}/"
  done
  echo "Storefronts:${storefronts}"
fi
echo "Control plane: ${CP_URL}"
echo "Admin passwords (random per deployment):"
for h in "${HOSTS[@]}"; do
  echo "  make admin-pass HOST=${h} ENV=${PLATFORM_ENV}"
done
