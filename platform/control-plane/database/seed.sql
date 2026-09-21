INSERT INTO cells (id, name, provider, capacity_metadata)
VALUES (
    '11111111-1111-4111-8111-111111111111',
    'local-dev',
    'docker',
    '{"region":"local","max_deployments":50,"mysql_proxy_budget":80,"postgres_pool_budget":20}'::jsonb
)
ON CONFLICT (id) DO NOTHING;
