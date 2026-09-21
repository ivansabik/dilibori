-- Idempotent: one live deployment per tenant + environment.
CREATE UNIQUE INDEX IF NOT EXISTS deployments_one_live_per_tenant_env_idx
    ON deployments (tenant_id, environment)
    WHERE status NOT IN ('failed', 'suspended');
