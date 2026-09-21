#!/usr/bin/env python3
"""Assert schema keeps one live deployment per tenant+environment."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
schema = (ROOT / "control-plane" / "database" / "schema.sql").read_text(
    encoding="utf-8"
)
assert "deployments_one_live_per_tenant_env_idx" in schema
assert "status NOT IN ('failed', 'suspended')" in schema
print("OK: one-live-deployment index present")
