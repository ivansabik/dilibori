#!/usr/bin/env bash
# Recreate active tenant runtimes so CDP env is present (existing + after profile changes).
# Requires a runtime-agent image that includes the ensure_cdp task.
set -euo pipefail

CP_URL="${CONTROL_PLANE_URL:-http://127.0.0.1:8099}"
AGENT_URL="${RUNTIME_AGENT_URL:-http://127.0.0.1:8100}"
TOKEN="${RUNTIME_AGENT_TOKEN:-platform-agent-token-change-me}"

active_ids="$(
  curl -sf "${CP_URL}/tenants" | python3 -c '
import json,sys,urllib.request
tenants=json.load(sys.stdin).get("tenants",[])
ids=[]
for t in tenants:
  detail=json.load(urllib.request.urlopen("'"${CP_URL}"'/tenants/"+t["id"]))
  for d in detail.get("deployments") or []:
    if d.get("status")=="active":
      ids.append(d["id"])
print("\n".join(ids))
'
)"

if [ -z "${active_ids}" ]; then
  echo "No active deployments"
  exit 0
fi

while IFS= read -r dep_id; do
  [ -n "${dep_id}" ] || continue
  full="$(curl -sf "${CP_URL}/deployments/${dep_id}")"
  hostname="$(echo "$full" | python3 -c 'import sys,json; print(json.load(sys.stdin)["deployment"]["hostname"])')"
  environment="$(echo "$full" | python3 -c 'import sys,json; print(json.load(sys.stdin)["deployment"]["environment"])')"
  echo "ensure_cdp ${hostname} (${dep_id})..."
  curl -sf -X POST "${AGENT_URL}/deployments/${dep_id}/tasks" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"action\":\"ensure_cdp\",\"payload\":{\"environment\":\"${environment}\",\"hostname\":\"${hostname}\",\"config_checksum\":\"cdp-ensure\"}}" \
    >/tmp/dilibori-ensure-cdp-"${dep_id}".json
  curl -sf -X POST "${AGENT_URL}/deployments/${dep_id}/tasks" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"action\":\"configure_route\",\"payload\":{\"environment\":\"${environment}\",\"hostname\":\"${hostname}\",\"noindex\":true}}" \
    >/dev/null
  echo "  ok"
done <<<"${active_ids}"

echo "CDP env ensured on active deployments. Watch http://127.0.0.1:8090/"
