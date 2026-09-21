from __future__ import annotations

import shutil
from pathlib import Path

import docker

from app.config import Settings
from app.services.caddy_service import CaddyService
from app.services.docker_service import DockerService
from app.services.mariadb_service import MariaDbService
from app.services.minio_service import MinioService
from app.services.redis_service import RedisService
from app.utils.naming import deployment_id_short, validate_deployment_id


class PurgeService:
    """Remove all cell-local artifacts for a deployment (post-migrate or teardown)."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def purge(
        self,
        *,
        deployment_id: str,
        database_name: str,
        hostname: str = "",
        redis_username: str = "",
        minio_access_key: str = "",
        minio_prefix: str = "",
        tenant_id: str = "",
        remove_runtime: bool = True,
    ) -> dict:
        validate_deployment_id(deployment_id)
        short = deployment_id_short(deployment_id)
        steps: dict[str, object] = {}

        if remove_runtime:
            steps["runtime"] = DockerService(self._settings).remove(deployment_id)

        if database_name:
            username = f"t_{short}"
            MariaDbService(self._settings).drop_database_user(
                database_name=database_name,
                username=username,
            )
            steps["database"] = {
                "database_name": database_name,
                "username": username,
            }

        redis_user = redis_username or f"r_{short}"
        try:
            RedisService(self._settings).delete_acl_user(username=redis_user)
            steps["redis"] = {"username": redis_user}
        except Exception as exc:  # noqa: BLE001 — best-effort teardown
            steps["redis"] = {"error": str(exc)}

        access_key = minio_access_key or f"AK{short.upper()}"
        if minio_prefix:
            prefix = minio_prefix
        elif tenant_id:
            prefix = f"tenants/{tenant_id}/"
        else:
            prefix = f"tenants/{deployment_id}/"
        try:
            steps["minio"] = MinioService(self._settings).delete_tenant(
                deployment_id=deployment_id,
                access_key=access_key,
                bucket="dilibori-media",
                prefix=prefix,
            )
        except Exception as exc:  # noqa: BLE001
            steps["minio"] = {"error": str(exc)}

        if hostname:
            try:
                client = docker.from_env()
                caddy = CaddyService(self._settings, client)
                steps["ingress"] = caddy.remove_route(hostname=hostname)
            except Exception as exc:  # noqa: BLE001
                steps["ingress"] = {"error": str(exc)}

        tenant_dir = Path(self._settings.data_dir) / "tenants" / deployment_id
        if tenant_dir.exists():
            shutil.rmtree(tenant_dir, ignore_errors=True)
            steps["bind_mounts"] = {"removed": str(tenant_dir)}

        return {"deployment_id": deployment_id, "steps": steps}
