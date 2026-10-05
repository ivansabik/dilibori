#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== environment profiles =="
python3 tests/test_environment_profiles.py

echo "== seed manifests =="
python3 tests/test_seed_manifests.py

echo "== isolation contracts =="
python3 tests/test_isolation_contracts.py

echo "== aws agent settings =="
python3 tests/test_aws_agent_settings.py

echo "== scale one-way doors =="
python3 tests/test_scale_one_way_doors.py

echo "== migrate contracts =="
python3 tests/test_migrate_contracts.py

echo "== one live deployment =="
python3 tests/test_one_live_deployment.py

echo "== per-env stacks =="
python3 tests/test_env_stacks.py

echo "== connection budgets =="
python3 tests/test_connection_budgets.py

echo "== event envelope =="
python3 tests/test_event_envelope.py

echo "== config resolver =="
python3 - <<'PY'
from pathlib import Path
from scripts.config_resolver import ConfigResolver
r = ConfigResolver(Path('profiles'))
cfg, checksum = r.resolve('development', tenant_overrides={'MAIL_FROM_NAME': 'X'})
assert cfg['APP_DEBUG'] is True
assert checksum
try:
    r.resolve('production', tenant_overrides={'APP_DEBUG': True})
    raise SystemExit('should reject APP_DEBUG override')
except ValueError:
    pass
print('OK: config resolver')
PY

echo "== capacity model =="
python3 scripts/capacity_model.py --tenants 2

echo "All platform unit tests passed"
