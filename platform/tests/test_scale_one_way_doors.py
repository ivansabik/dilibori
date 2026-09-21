#!/usr/bin/env python3
"""Checks for one-way door scale fixes (no live Docker required)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "runtime-agent"))

from app.utils.naming import (  # noqa: E402
    deployment_id_short,
    ensure_unique_short_id,
    short_ids_collide,
)


def main() -> int:
    tasks_path = ROOT / "runtime-agent/app/routers/tasks.py"
    tasks_src = tasks_path.read_text(encoding="utf-8")
    assert "settings.proxysql_host" in tasks_src
    assert "settings.proxysql_mysql_port" in tasks_src
    assert 'DB_HOST": "mariadb"' not in tasks_src

    left = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"
    right = "aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff"
    assert deployment_id_short(left) == "aaaaaaaabbbb"
    assert short_ids_collide(left, right)
    try:
        ensure_unique_short_id(right, [left])
        print("FAIL: colliding short id accepted")
        return 1
    except ValueError:
        pass
    assert ensure_unique_short_id(left, []) == "aaaaaaaabbbb"

    other = "bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee"
    assert not short_ids_collide(left, other)
    assert ensure_unique_short_id(other, [left]) == deployment_id_short(other)

    minio_src = (ROOT / "runtime-agent/app/services/minio_service.py").read_text(
        encoding="utf-8"
    )
    assert 'admin", "user", "add"' in minio_src or '"user", "add"' in minio_src
    assert "policy" in minio_src and "attach" in minio_src
    assert "must be created with mc admin user add" not in minio_src

    docker_src = (ROOT / "runtime-agent/app/services/docker_service.py").read_text(
        encoding="utf-8"
    )
    assert '_TENANT_SUBNET_PREFIX = "10.200"' in docker_src
    assert "IPAMPool" in docker_src
    assert '"network_name": self._settings.shared_network' not in docker_src
    assert "remove_network" in docker_src

    mariadb_src = (ROOT / "runtime-agent/app/services/mariadb_service.py").read_text(
        encoding="utf-8"
    )
    assert "ProxySqlService" in mariadb_src
    assert (ROOT / "runtime-agent/app/services/proxysql_service.py").is_file()

    controller = (
        ROOT / "control-plane/src/Http/Controllers/DeploymentController.php"
    ).read_text(encoding="utf-8")
    assert "allocateUniqueDeploymentId" in controller
    assert "left(replace(id::text" in controller

    assert (ROOT / "docs/scale-findings.md").is_file()

    dockerfile = (ROOT / "runtime-agent/Dockerfile").read_text(encoding="utf-8")
    assert "mc" in dockerfile and "min.io" in dockerfile

    print("OK: scale one-way door checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
