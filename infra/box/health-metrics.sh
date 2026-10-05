#!/bin/bash
# Publish 1/0 dilibori/health metrics per compose service.
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
COMPOSE_PROJECT="dilibori-${ENV_NAME}"

if [ ! -d "${PLATFORM_DIR}" ]; then
  exit 0
fi

cd "${PLATFORM_DIR}"
PS_JSON="$(
  docker compose -p "${COMPOSE_PROJECT}" \
    -f docker-compose.yml -f docker-compose.aws.yml \
    --env-file .env --env-file "envs/${ENV_NAME}.env" \
    ps --format json 2>/dev/null || echo ""
)"

export PS_JSON
python3 - <<'PY'
import json
import os
import subprocess
import urllib.request

env = os.environ["PLATFORM_BOX_ENV"]
region = os.environ["AWS_REGION"]
raw = os.environ.get("PS_JSON", "").strip()
items = []
if raw:
    if raw.startswith("["):
        items = json.loads(raw)
    else:
        for line in raw.splitlines():
            line = line.strip()
            if line:
                items.append(json.loads(line))

wanted = [
    "mariadb",
    "proxysql",
    "postgres",
    "control-plane",
    "runtime-agent",
    "ingress",
]
by_service = {}
for item in items:
    name = item.get("Service") or ""
    if name in wanted:
        state = (item.get("Health") or item.get("State") or "").lower()
        by_service[name] = 1 if state in ("healthy", "running") else 0

try:
    urllib.request.urlopen(
        f"http://127.0.0.1:{os.environ.get('CONTROL_PLANE_PORT', '8099')}/health",
        timeout=2,
    )
    by_service["control-plane"] = 1
except Exception:
    by_service.setdefault("control-plane", 0)

metric_data = [
    {
        "MetricName": name,
        "Value": float(by_service.get(name, 0)),
        "Unit": "None",
        "Dimensions": [{"Name": "Environment", "Value": env}],
    }
    for name in wanted
]

subprocess.run(
    [
        "aws",
        "cloudwatch",
        "put-metric-data",
        "--region",
        region,
        "--namespace",
        "dilibori/health",
        "--metric-data",
        json.dumps(metric_data),
    ],
    check=False,
)
PY
