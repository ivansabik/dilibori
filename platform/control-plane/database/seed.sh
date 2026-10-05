#!/bin/bash
# Seed the local cell row. Reads PLATFORM_ENV and ingress URL from the
# postgres container environment (set by docker-compose).
set -euo pipefail

PLATFORM_ENV="${PLATFORM_ENV:-dev}"
INGRESS_HTTP_PORT="${INGRESS_HTTP_PORT:-8088}"
CELL_NAME="local-${PLATFORM_ENV}"
INGRESS_BASE_URL="${INGRESS_BASE_URL:-http://127.0.0.1:${INGRESS_HTTP_PORT}}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<EOSQL
INSERT INTO cells (id, name, provider, capacity_metadata, agent_url, schedulable)
VALUES (
    '11111111-1111-4111-8111-111111111111',
    '${CELL_NAME}',
    'docker',
    '{"region":"local","max_deployments":50,"mysql_proxy_budget":80,"postgres_pool_budget":20,"ingress_base_url":"${INGRESS_BASE_URL}"}'::jsonb,
    'http://runtime-agent:8100',
    TRUE
)
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    agent_url = COALESCE(NULLIF(cells.agent_url, ''), EXCLUDED.agent_url),
    capacity_metadata = cells.capacity_metadata || EXCLUDED.capacity_metadata;
EOSQL
