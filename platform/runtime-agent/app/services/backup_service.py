from __future__ import annotations

import gzip
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path

from app.config import Settings
from app.services.allowlist import validate_backup_file
from app.utils.naming import validate_sql_identifier


class BackupService:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def backup_dir(self, deployment_id: str) -> Path:
        path = Path(self._settings.data_dir) / "backups" / deployment_id
        path.mkdir(parents=True, exist_ok=True)
        return path

    def backup_database(self, *, deployment_id: str, database_name: str) -> dict:
        database_name = validate_sql_identifier(database_name, "database_name")
        timestamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        filename = f"{database_name}-{timestamp}.sql.gz"
        output_path = self.backup_dir(deployment_id) / filename

        dump_cmd = [
            "mysqldump",
            f"-h{self._settings.mariadb_host}",
            f"-P{self._settings.mariadb_port}",
            f"-u{self._settings.mariadb_admin_user}",
            f"-p{self._settings.mariadb_admin_password}",
            "--single-transaction",
            "--routines",
            "--triggers",
            database_name,
        ]

        with subprocess.Popen(dump_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE) as process:
            assert process.stdout is not None
            with gzip.open(output_path, "wb") as gz_file:
                shutil.copyfileobj(process.stdout, gz_file)
            stderr = process.stderr.read().decode("utf-8") if process.stderr else ""
            return_code = process.wait()

        if return_code != 0:
            output_path.unlink(missing_ok=True)
            raise RuntimeError(f"mysqldump failed: {stderr.strip() or return_code}")

        return {"backup_file": filename, "path": str(output_path)}

    def restore_database(
        self,
        *,
        deployment_id: str,
        database_name: str,
        backup_file: str,
    ) -> dict:
        database_name = validate_sql_identifier(database_name, "database_name")
        backup_file = validate_backup_file(backup_file)
        backup_path = self.backup_dir(deployment_id) / backup_file
        if not backup_path.is_file():
            raise FileNotFoundError(f"backup file not found: {backup_file}")
        resolved = backup_path.resolve()
        if self.backup_dir(deployment_id).resolve() not in resolved.parents:
            raise ValueError("backup path escapes backup directory")

        mysql_cmd = [
            "mysql",
            f"-h{self._settings.mariadb_host}",
            f"-P{self._settings.mariadb_port}",
            f"-u{self._settings.mariadb_admin_user}",
            f"-p{self._settings.mariadb_admin_password}",
            database_name,
        ]

        if backup_file.endswith(".gz"):
            decompress = subprocess.Popen(
                ["gzip", "-dc", str(backup_path)],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            assert decompress.stdout is not None
            restore = subprocess.Popen(
                mysql_cmd,
                stdin=decompress.stdout,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            decompress.stdout.close()
            stderr = restore.stderr.read().decode("utf-8") if restore.stderr else ""
            return_code = restore.wait()
            decompress.wait()
        else:
            with backup_path.open("rb") as sql_file:
                restore = subprocess.run(
                    mysql_cmd,
                    stdin=sql_file,
                    capture_output=True,
                    check=False,
                )
                stderr = restore.stderr.decode("utf-8")
                return_code = restore.returncode

        if return_code != 0:
            raise RuntimeError(f"mysql restore failed: {stderr.strip() or return_code}")

        return {"restored_from": backup_file, "database_name": database_name}
