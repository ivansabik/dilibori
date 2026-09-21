#!/usr/bin/env bash
# Provision both pilot tenants (dev + production each). Reuses live deployments.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

CP_URL="${CONTROL_PLANE_URL:-http://127.0.0.1:8099}"

echo "Waiting for control plane at ${CP_URL}..."
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
  local alias_slug="${3:-}"
  local existing
  existing="$(curl -sf "${CP_URL}/tenants" | python3 -c "import sys,json; data=json.load(sys.stdin); items=data.get('tenants', data if isinstance(data,list) else []);
slugs=['${slug}'] + [s for s in '${alias_slug}'.split(',') if s]
print(next((t['id'] for t in items if t.get('slug') in slugs), ''))")"
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
    # Hostname may have changed (norte → pizza-rin); re-apply ingress.
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

TACONAZO_ID="$(ensure_tenant taconazo 'El Taconazo')"
PIZZA_ID="$(ensure_tenant pizza-rin 'Pizza Rin' taqueria-norte)"

provision_deployment "$TACONAZO_ID" development taconazo taconazo.dev.localhost
provision_deployment "$TACONAZO_ID" production taconazo taconazo.prod.localhost
provision_deployment "$PIZZA_ID" development pizza-rin pizza-rin.dev.localhost
provision_deployment "$PIZZA_ID" production pizza-rin pizza-rin.prod.localhost

echo ""
echo "Pilots requested. Add to /etc/hosts if needed:"
echo "  127.0.0.1 taconazo.dev.localhost taconazo.prod.localhost pizza-rin.dev.localhost pizza-rin.prod.localhost"
echo "Ingress: http://127.0.0.1:8088 (Host header selects tenant)"
echo "Control plane: ${CP_URL}"
