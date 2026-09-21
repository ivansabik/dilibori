-- Cell registry + migrate status for existing control-plane volumes.

ALTER TABLE cells ADD COLUMN IF NOT EXISTS agent_url VARCHAR(512);
ALTER TABLE cells ADD COLUMN IF NOT EXISTS schedulable BOOLEAN NOT NULL DEFAULT TRUE;

UPDATE cells
SET agent_url = COALESCE(NULLIF(agent_url, ''), 'http://runtime-agent:8100')
WHERE name = 'local-dev';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_enum e
        JOIN pg_type t ON t.oid = e.enumtypid
        WHERE t.typname = 'deployment_status' AND e.enumlabel = 'migrating'
    ) THEN
        ALTER TYPE deployment_status ADD VALUE 'migrating';
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS deployments_cell_id_idx ON deployments (cell_id);
