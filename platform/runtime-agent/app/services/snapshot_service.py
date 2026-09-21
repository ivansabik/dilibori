from __future__ import annotations

import shutil
import tarfile
import urllib.request
from datetime import UTC, datetime
from pathlib import Path

from app.config import Settings
from app.services.backup_service import BackupService
from app.services.minio_service import MinioService
from app.utils.naming import validate_deployment_id


class SnapshotService:
    """Pack / unpack tenant state for stop-copy-start cell migrate."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def snapshot_dir(self, deployment_id: str) -> Path:
        path = Path(self._settings.data_dir) / "snapshots" / deployment_id
        path.mkdir(parents=True, exist_ok=True)
        return path

    def snapshot_path(self, deployment_id: str, filename: str) -> Path:
        validate_deployment_id(deployment_id)
        if "/" in filename or ".." in filename or not filename.endswith(".tar.gz"):
            raise ValueError("snapshot filename must be a .tar.gz basename")
        base = self.snapshot_dir(deployment_id).resolve()
        path = (base / filename).resolve()
        if base not in path.parents and path != base:
            raise ValueError("snapshot path escapes snapshot directory")
        return path

    def export_snapshot(
        self,
        *,
        deployment_id: str,
        database_name: str,
        tenant_id: str,
        minio_prefix: str | None = None,
        bucket: str = "dilibori-media",
    ) -> dict:
        validate_deployment_id(deployment_id)
        work = self.snapshot_dir(deployment_id) / "_work"
        if work.exists():
            shutil.rmtree(work)
        work.mkdir(parents=True)

        dump = BackupService(self._settings).backup_database(
            deployment_id=deployment_id,
            database_name=database_name,
        )
        dump_src = Path(dump["path"])
        dump_dest = work / "database.sql.gz"
        shutil.copy2(dump_src, dump_dest)

        tenant_root = Path(self._settings.data_dir) / "tenants" / deployment_id
        for folder in ("storage", "media"):
            src = tenant_root / folder
            if src.is_dir():
                shutil.copytree(src, work / folder, dirs_exist_ok=True)

        prefix = (minio_prefix or f"tenants/{tenant_id}/").strip("/")
        objects_dir = work / "minio"
        object_count = MinioService(self._settings).export_prefix(
            bucket=bucket,
            prefix=prefix,
            dest_dir=objects_dir,
        )

        timestamp = datetime.now(UTC).strftime("%Y%m%dT%H%M%SZ")
        filename = f"snapshot-{timestamp}.tar.gz"
        archive = self.snapshot_dir(deployment_id) / filename
        with tarfile.open(archive, "w:gz") as tar:
            for child in work.iterdir():
                tar.add(child, arcname=child.name)

        shutil.rmtree(work, ignore_errors=True)

        return {
            "snapshot_file": filename,
            "path": str(archive),
            "size_bytes": archive.stat().st_size,
            "minio_objects": object_count,
            "database_backup": dump["backup_file"],
        }

    def import_snapshot(
        self,
        *,
        deployment_id: str,
        database_name: str,
        tenant_id: str,
        source_url: str,
        source_token: str,
        minio_prefix: str | None = None,
        bucket: str = "dilibori-media",
    ) -> dict:
        validate_deployment_id(deployment_id)
        work = self.snapshot_dir(deployment_id) / "_import"
        if work.exists():
            shutil.rmtree(work)
        work.mkdir(parents=True)

        archive = self.snapshot_dir(deployment_id) / "imported.tar.gz"
        request = urllib.request.Request(
            source_url,
            headers={
                "Authorization": f"Bearer {source_token}",
                "Accept": "application/octet-stream",
            },
            method="GET",
        )
        with urllib.request.urlopen(request, timeout=900) as response:
            with archive.open("wb") as out:
                shutil.copyfileobj(response, out)

        with tarfile.open(archive, "r:gz") as tar:
            tar.extractall(work)

        dump = work / "database.sql.gz"
        if not dump.is_file():
            raise FileNotFoundError("snapshot missing database.sql.gz")

        backup_dir = BackupService(self._settings).backup_dir(deployment_id)
        local_dump = backup_dir / "migrate-import.sql.gz"
        shutil.copy2(dump, local_dump)
        BackupService(self._settings).restore_database(
            deployment_id=deployment_id,
            database_name=database_name,
            backup_file=local_dump.name,
        )

        tenant_root = Path(self._settings.data_dir) / "tenants" / deployment_id
        for folder in ("storage", "media"):
            src = work / folder
            if src.is_dir():
                dest = tenant_root / folder
                if dest.exists():
                    shutil.rmtree(dest)
                shutil.copytree(src, dest)

        prefix = (minio_prefix or f"tenants/{tenant_id}/").strip("/")
        objects_dir = work / "minio"
        object_count = MinioService(self._settings).import_prefix(
            bucket=bucket,
            prefix=prefix,
            source_dir=objects_dir,
        )

        shutil.rmtree(work, ignore_errors=True)
        archive.unlink(missing_ok=True)

        return {
            "restored_database": database_name,
            "minio_objects": object_count,
            "tenant_id": tenant_id,
        }

    def pack_dirs_roundtrip(self, source: Path, dest: Path) -> list[str]:
        """Test helper: tar then untar directory names. Returns member names."""
        dest.parent.mkdir(parents=True, exist_ok=True)
        with tarfile.open(dest, "w:gz") as tar:
            for child in sorted(source.iterdir()):
                tar.add(child, arcname=child.name)
        out = dest.parent / "_unpacked"
        if out.exists():
            shutil.rmtree(out)
        out.mkdir()
        with tarfile.open(dest, "r:gz") as tar:
            names = tar.getnames()
            tar.extractall(out)
        return names
