#!/usr/bin/env python3
"""Contract checks for cell migrate / drain (no live Docker required)."""

from __future__ import annotations

import shutil
import sys
import tarfile
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _tar_roundtrip(source: Path, archive: Path) -> list[str]:
    archive.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(archive, "w:gz") as tar:
        for child in sorted(source.iterdir()):
            tar.add(child, arcname=child.name)
    out = archive.parent / "_unpacked"
    if out.exists():
        shutil.rmtree(out)
    out.mkdir()
    with tarfile.open(archive, "r:gz") as tar:
        names = tar.getnames()
        tar.extractall(out)
    return names


def main() -> int:
    schema = (ROOT / "control-plane/database/schema.sql").read_text(encoding="utf-8")
    assert "agent_url" in schema
    assert "schedulable" in schema
    assert "'migrating'" in schema

    migration = (
        ROOT / "control-plane/database/migrations/002_cell_migrate.sql"
    ).read_text(encoding="utf-8")
    assert "agent_url" in migration
    assert "migrating" in migration

    index = (ROOT / "control-plane/public/index.php").read_text(encoding="utf-8")
    assert "/deployments/{id}/migrate" in index
    assert "/cells/{id}/drain" in index
    assert "post('/cells'" in index or 'post("/cells"' in index

    controller = (
        ROOT / "control-plane/src/Http/Controllers/DeploymentController.php"
    ).read_text(encoding="utf-8")
    assert "schedulable = TRUE" in controller
    assert "max_deployments" in controller
    assert "No schedulable cell with free capacity" in controller

    app_php = (ROOT / "control-plane/src/Application.php").read_text(encoding="utf-8")
    assert "runtimeAgentForCell" in app_php
    assert "MigrationStateMachine" in app_php

    migrate_php = (
        ROOT / "control-plane/src/Services/MigrationStateMachine.php"
    ).read_text(encoding="utf-8")
    assert "export_snapshot" in migrate_php
    assert "import_snapshot" in migrate_php
    assert "purge" in migrate_php
    assert "function drain" in migrate_php

    tasks = (ROOT / "runtime-agent/app/routers/tasks.py").read_text(encoding="utf-8")
    for action in (
        "export_snapshot",
        "import_snapshot",
        "purge",
        "remove_runtime",
        "stop",
    ):
        assert f'action == "{action}"' in tasks, f"missing task action {action}"

    tenants = (ROOT / "runtime-agent/app/routers/tenants.py").read_text(
        encoding="utf-8"
    )
    assert "/{deployment_id}/snapshot" in tenants

    docker_src = (ROOT / "runtime-agent/app/services/docker_service.py").read_text(
        encoding="utf-8"
    )
    assert "shared_network" in docker_src
    assert "on_shared" in docker_src

    assert (ROOT / "docker-compose.cell-b.yml").is_file()
    cell_b = (ROOT / "docker-compose.cell-b.yml").read_text(encoding="utf-8")
    assert "dilibori-cell-b-shared" in cell_b
    assert "dilibori-dev-control" in cell_b
    assert "CELL_B_RUNTIME_AGENT_PORT" in cell_b

    snapshot_src = (ROOT / "runtime-agent/app/services/snapshot_service.py").read_text(
        encoding="utf-8"
    )
    assert "pack_dirs_roundtrip" in snapshot_src
    assert "database.sql.gz" in snapshot_src

    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = Path(tmp)
        source = tmp_path / "src"
        (source / "storage").mkdir(parents=True)
        (source / "storage" / "file.txt").write_text("hello", encoding="utf-8")
        (source / "media").mkdir()
        archive = tmp_path / "snap.tar.gz"
        names = _tar_roundtrip(source, archive)
        assert "storage" in names
        assert "media" in names
        assert archive.is_file() and archive.stat().st_size > 0

    print("OK: migrate contract checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
