INSERT INTO cells (id, name, provider, capacity_metadata, agent_url, schedulable)
VALUES (
    '11111111-1111-4111-8111-111111111111',
    'local-dev',
    'docker',
    '{"region":"local","max_deployments":50,"mysql_proxy_budget":80,"postgres_pool_budget":20,"ingress_base_url":"http://127.0.0.1:8088"}'::jsonb,
    'http://runtime-agent:8100',
    TRUE
)
ON CONFLICT (id) DO UPDATE SET
    agent_url = COALESCE(NULLIF(cells.agent_url, ''), EXCLUDED.agent_url),
    capacity_metadata = cells.capacity_metadata || EXCLUDED.capacity_metadata;
