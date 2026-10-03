-- Control plane schema (PostgreSQL)

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TYPE deployment_environment AS ENUM ('development', 'production');
CREATE TYPE deployment_status AS ENUM (
    'reserved',
    'creating_db',
    'creating_secrets',
    'installing',
    'creating_runtime',
    'configuring_route',
    'health_check',
    'active',
    'failed',
    'suspended',
    'migrating'
);
CREATE TYPE suspension_state AS ENUM ('none', 'suspended', 'pending_suspend');
CREATE TYPE backup_state AS ENUM ('none', 'in_progress', 'completed', 'failed');

CREATE TABLE cells (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    capacity_metadata JSONB NOT NULL DEFAULT '{}',
    agent_url VARCHAR(512),
    schedulable BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    noindex BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE releases (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    image_digest VARCHAR(255) NOT NULL,
    image_tag VARCHAR(128) NOT NULL,
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE deployments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants (id),
    environment deployment_environment NOT NULL,
    cell_id UUID REFERENCES cells (id),
    hostname VARCHAR(255),
    database_name VARCHAR(128),
    desired_release UUID REFERENCES releases (id),
    seed_bundle VARCHAR(128),
    config_version INTEGER NOT NULL DEFAULT 1,
    config_checksum VARCHAR(64),
    status deployment_status NOT NULL DEFAULT 'reserved',
    suspension_state suspension_state NOT NULL DEFAULT 'none',
    backup_state backup_state NOT NULL DEFAULT 'none',
    runtime_refs JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX deployments_tenant_id_idx ON deployments (tenant_id);
CREATE INDEX deployments_status_idx ON deployments (status);
CREATE INDEX deployments_cell_id_idx ON deployments (cell_id);

-- At most one live deployment per tenant + environment (failed/suspended excluded).
CREATE UNIQUE INDEX deployments_one_live_per_tenant_env_idx
    ON deployments (tenant_id, environment)
    WHERE status NOT IN ('failed', 'suspended');


CREATE TABLE secrets_meta (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    deployment_id UUID NOT NULL REFERENCES deployments (id) ON DELETE CASCADE,
    key_name VARCHAR(128) NOT NULL,
    secret_ref VARCHAR(512) NOT NULL,
    UNIQUE (deployment_id, key_name)
);

CREATE TABLE audit_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    actor VARCHAR(255) NOT NULL DEFAULT 'system',
    action VARCHAR(128) NOT NULL,
    tenant_id UUID REFERENCES tenants (id),
    deployment_id UUID REFERENCES deployments (id),
    reason TEXT,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX audit_events_deployment_id_idx ON audit_events (deployment_id);
CREATE INDEX audit_events_tenant_id_idx ON audit_events (tenant_id);

CREATE TABLE support_access (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    deployment_id UUID NOT NULL REFERENCES deployments (id) ON DELETE CASCADE,
    actor VARCHAR(255) NOT NULL,
    reason TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX support_access_deployment_id_idx ON support_access (deployment_id);
