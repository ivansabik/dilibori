"""Task dispatcher matching the control-plane RuntimeAgentClient contract."""

from __future__ import annotations

import secrets
import string
from typing import Any

import docker
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from app.auth import require_token
from app.config import Settings, get_settings
from app.services.caddy_service import CaddyService
from app.services.caddy_snippet import effective_noindex
from app.services.docker_service import DockerService
from app.services.mariadb_service import MariaDbService
from app.services.minio_service import MinioService
from app.services.proxysql_service import ProxySqlService
from app.services.purge_service import PurgeService
from app.services.redis_service import RedisService
from app.services.snapshot_service import SnapshotService
from app.utils.naming import (
    default_network_name,
    deployment_id_short,
    tenant_container_name,
    validate_deployment_id,
)

router = APIRouter(prefix="/deployments", tags=["deployments"])


class TaskRequest(BaseModel):
    action: str
    payload: dict[str, Any] = Field(default_factory=dict)


def _password(length: int = 24) -> str:
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def _db_user(deployment_id: str) -> str:
    return f"t_{deployment_id_short(deployment_id)}"


def _map_profile(environment: str) -> str:
    if environment == "production":
        return "production"
    return "development"


def _public_app_url(hostname: str) -> str:
    hostname = hostname.strip() or "localhost"
    # Browser hits ingress on a non-80 port; absolute asset URLs must include it.
    port = (get_settings().ingress_http_port or "").strip()
    if port and port not in {"80", "443"}:
        return f"http://{hostname}:{port}"
    return f"http://{hostname}"


@router.post("/{deployment_id}/tasks")
def enqueue_task(
    deployment_id: str,
    body: TaskRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> dict[str, Any]:
    validate_deployment_id(deployment_id)
    payload = body.payload or {}
    action = body.action
    docker_svc = DockerService(settings)
    environment = str(payload.get("environment") or "development")
    profile = _map_profile(environment)

    try:
        if action == "create_database":
            database_name = (
                payload.get("database_name")
                or f"ti_{deployment_id_short(deployment_id)}"
            )
            username = _db_user(deployment_id)
            password = str(
                payload.get("db_password") or payload.get("DB_PASSWORD") or _password()
            )
            max_conn = int(
                (payload.get("config") or {}).get("MAX_USER_CONNECTIONS") or 5
            )
            MariaDbService(settings).create_database_user(
                database_name=database_name,
                username=username,
                password=password,
                max_user_connections=max_conn,
            )
            docker_svc.create_network(
                deployment_id=deployment_id,
                network_name=default_network_name(deployment_id),
            )
            return {
                "ok": True,
                "database_name": database_name,
                "username": username,
                "password": password,
            }

        if action == "ensure_redis_acl":
            username = str(payload.get("REDIS_USERNAME") or "")
            password = str(payload.get("REDIS_PASSWORD") or "")
            prefix = str(payload.get("REDIS_PREFIX") or "")
            if not username or not password or not prefix:
                raise HTTPException(
                    status_code=400,
                    detail="REDIS_USERNAME, REDIS_PASSWORD, and REDIS_PREFIX are required",
                )
            RedisService(settings).create_acl_user(
                username=username,
                password=password,
                key_prefix=prefix,
            )
            return {"ok": True}

        if action == "ensure_proxysql_user":
            username = str(payload.get("DB_USERNAME") or _db_user(deployment_id))
            password = str(payload.get("DB_PASSWORD") or "")
            max_conn = int(payload.get("MAX_USER_CONNECTIONS") or 10)
            if not password:
                raise HTTPException(status_code=400, detail="DB_PASSWORD is required")
            ProxySqlService(settings).upsert_mysql_user(
                username=username,
                password=password,
                max_connections=max_conn,
            )
            return {"ok": True, "username": username}

        if action == "create_secrets":
            prefix = str(
                payload.get("REDIS_PREFIX")
                or f"ti:{deployment_id_short(deployment_id)}:"
            )
            redis_user = str(
                payload.get("REDIS_USERNAME")
                or f"r_{deployment_id_short(deployment_id)}"
            )
            redis_password = str(payload.get("REDIS_PASSWORD") or _password())
            RedisService(settings).create_acl_user(
                username=redis_user,
                password=redis_password,
                key_prefix=prefix,
            )
            access_key = str(
                payload.get("MINIO_ACCESS_KEY")
                or f"AK{deployment_id_short(deployment_id).upper()}"
            )
            secret_key = str(payload.get("MINIO_SECRET_KEY") or _password(32))
            media_prefix = str(
                payload.get("MINIO_PREFIX")
                or f"tenants/{payload.get('tenant_id', deployment_id)}/"
            )
            MinioService(settings).create_tenant_credentials(
                deployment_id=deployment_id,
                access_key=access_key,
                secret_key=secret_key,
                bucket="dilibori-media",
                prefix=media_prefix,
            )
            return {
                "ok": True,
                "redis_user": redis_user,
                "redis_password": redis_password,
                "redis_prefix": prefix,
                "minio_access_key": access_key,
                "minio_secret_key": secret_key,
                "minio_prefix": media_prefix,
            }

        if action == "install_release":
            env = _tenant_env(settings, deployment_id, payload)
            data = docker_svc.provision_install(
                deployment_id=deployment_id,
                image=settings.tenant_image,
                env=env,
                network_name=default_network_name(deployment_id),
                environment_profile=profile,
                tenant_id=str(payload.get("tenant_id") or ""),
                config_version=str(payload.get("config_checksum") or "1"),
            )
            return {"ok": True, "data": data}

        if action == "create_runtime":
            env = _tenant_env(settings, deployment_id, payload)
            cfg = payload.get("config") or {}
            memory = int(
                cfg.get("MEMORY_LIMIT_MB") or (384 if profile == "development" else 256)
            )
            cpu = float(
                cfg.get("CPU_LIMIT") or (0.5 if profile == "development" else 0.25)
            )
            data = docker_svc.deploy(
                deployment_id=deployment_id,
                image=settings.tenant_image,
                env=env,
                hostname_label=str(payload.get("hostname") or "localhost"),
                memory_limit=f"{memory}m",
                cpu_limit=cpu,
                network_name=default_network_name(deployment_id),
                environment_profile=profile,
                tenant_id=str(payload.get("tenant_id") or ""),
                config_version=str(payload.get("config_checksum") or "1"),
            )
            return {"ok": True, "data": data}

        if action == "ensure_cdp":
            # Recreate from the live container env so existing tenants pick up CDP
            # without the control plane re-sending secrets.
            client = docker.from_env()
            name = tenant_container_name(deployment_id)
            try:
                container = client.containers.get(name)
            except docker.errors.NotFound as exc:
                raise HTTPException(
                    status_code=404,
                    detail=f"runtime not found: {name}",
                ) from exc

            env: dict[str, str] = {}
            for item in container.attrs.get("Config", {}).get("Env") or []:
                if "=" not in item:
                    continue
                key, value = item.split("=", 1)
                env[key] = value
            env.update(_cdp_env())

            labels = container.labels or {}
            hostname = str(
                payload.get("hostname")
                or labels.get("dilibori.hostname_label")
                or env.get("EXPECTED_HOST")
                or "localhost"
            )
            tenant_id = str(
                payload.get("tenant_id") or labels.get("dilibori.tenant_id") or ""
            )
            default_mem = 384 if profile == "development" else 256
            default_cpu = 0.5 if profile == "development" else 0.25
            memory = int(
                payload.get("MEMORY_LIMIT_MB")
                or env.get("MEMORY_LIMIT_MB")
                or default_mem
            )
            cpu = float(payload.get("CPU_LIMIT") or env.get("CPU_LIMIT") or default_cpu)
            data = docker_svc.deploy(
                deployment_id=deployment_id,
                image=settings.tenant_image,
                env=env,
                hostname_label=hostname,
                memory_limit=f"{memory}m",
                cpu_limit=cpu,
                network_name=default_network_name(deployment_id),
                environment_profile=profile,
                tenant_id=tenant_id,
                config_version=str(payload.get("config_checksum") or "cdp-ensure"),
            )
            return {"ok": True, "data": data}

        if action == "configure_route":
            client = docker.from_env()
            noindex = payload.get("noindex")
            if isinstance(noindex, str):
                noindex = noindex.lower() in {"1", "true", "yes", "on"}
            else:
                noindex = bool(noindex)
            CaddyService(settings, client).configure_route(
                deployment_id=deployment_id,
                hostname=str(payload.get("hostname") or ""),
                container_name=tenant_container_name(deployment_id),
                noindex=effective_noindex(noindex, environment),
            )
            return {"ok": True}

        if action == "health_check":
            observed = docker_svc.observe(deployment_id)
            healthy = bool(observed.get("running"))
            return {"ok": True, "healthy": healthy, "observed": observed}

        if action == "stop":
            data = docker_svc.stop(deployment_id)
            return {"ok": True, "data": data}

        if action == "remove_runtime":
            data = docker_svc.remove(deployment_id)
            return {"ok": True, "data": data}

        if action == "export_snapshot":
            data = SnapshotService(settings).export_snapshot(
                deployment_id=deployment_id,
                database_name=str(payload.get("database_name") or ""),
                tenant_id=str(payload.get("tenant_id") or deployment_id),
                minio_prefix=payload.get("MINIO_PREFIX") or payload.get("minio_prefix"),
            )
            return {"ok": True, **data}

        if action == "import_snapshot":
            source_url = str(payload.get("source_url") or "")
            source_token = str(payload.get("source_token") or "")
            if not source_url or not source_token:
                raise HTTPException(
                    status_code=400, detail="source_url and source_token are required"
                )
            data = SnapshotService(settings).import_snapshot(
                deployment_id=deployment_id,
                database_name=str(payload.get("database_name") or ""),
                tenant_id=str(payload.get("tenant_id") or deployment_id),
                source_url=source_url,
                source_token=source_token,
                minio_prefix=payload.get("MINIO_PREFIX") or payload.get("minio_prefix"),
            )
            return {"ok": True, **data}

        if action == "purge":
            data = PurgeService(settings).purge(
                deployment_id=deployment_id,
                database_name=str(payload.get("database_name") or ""),
                hostname=str(payload.get("hostname") or ""),
                redis_username=str(payload.get("REDIS_USERNAME") or ""),
                minio_access_key=str(payload.get("MINIO_ACCESS_KEY") or ""),
                minio_prefix=str(payload.get("MINIO_PREFIX") or ""),
                tenant_id=str(payload.get("tenant_id") or ""),
                remove_runtime=bool(payload.get("remove_runtime", True)),
            )
            return {"ok": True, **data}

        if action == "remove_route":
            client = docker.from_env()
            data = CaddyService(settings, client).remove_route(
                hostname=str(payload.get("hostname") or ""),
            )
            return {"ok": True, "data": data}

        raise HTTPException(status_code=400, detail=f"unknown action: {action}")
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc


def _tenant_env(
    settings: Settings, deployment_id: str, payload: dict[str, Any]
) -> dict[str, str]:
    cfg = dict(payload.get("config") or {})
    # Flatten nested profile config if present
    if "config" in cfg and isinstance(cfg["config"], dict):
        cfg = {**cfg, **cfg["config"]}

    env: dict[str, str] = {
        "TENANT_ID": str(payload.get("tenant_id") or ""),
        "DEPLOYMENT_ID": deployment_id,
        "CELL_ID": str(payload.get("cell_id") or "local-cell"),
        "DEPLOYMENT_ENVIRONMENT": str(payload.get("environment") or "development"),
        # Capacity budgets assume ProxySQL; register users there in MariaDbService.
        "DB_CONNECTION": "mysql",
        "DB_HOST": settings.proxysql_host,
        "DB_PORT": str(settings.proxysql_mysql_port),
        "DB_DATABASE": str(payload.get("database_name") or ""),
        "DB_USERNAME": _db_user(deployment_id),
        "SEED_MANIFEST": f"/opt/platform/seeds/{payload.get('seed_bundle', 'taconazo')}/manifest.json",
        "SEED_MODE": "initial",
        "EXPECTED_HOST": str(payload.get("hostname") or ""),
        "APP_URL": _public_app_url(str(payload.get("hostname") or "localhost")),
        "MAIL_HOST": "mailpit",
        "MAIL_PORT": "1025",
        "FORBID_MARKETPLACE_INSTALL": "true",
        "FORBID_SELF_UPDATE": "true",
        "CACHE_DRIVER": "redis",
        "REDIS_HOST": "redis",
        "QUEUE_CONNECTION": "redis",
    }

    for key, value in cfg.items():
        if isinstance(value, bool):
            env[str(key)] = "true" if value else "false"
        elif value is not None and not isinstance(value, (dict, list)):
            env[str(key)] = str(value)

    for key in (
        "DB_PASSWORD",
        "APP_KEY",
        "REDIS_PASSWORD",
        "ADMIN_PASSWORD",
        "REDIS_PREFIX",
        "REDIS_USERNAME",
    ):
        if payload.get(key):
            env[key] = str(payload[key])

    # Always last: every tenant (new and recreated) ships CDP to the local plane.
    env.update(_cdp_env())

    return env


def _cdp_env() -> dict[str, str]:
    return {
        "EVENT_CLIENT_DRIVER": "rudderstack",
        "CDP_ENABLED": "true",
        "RUDDERSTACK_DATA_PLANE_URL": "http://host.docker.internal:8080",
        "RUDDERSTACK_JS_DATA_PLANE_URL": "http://127.0.0.1:8080",
        "RUDDERSTACK_JS_CONFIG_URL": "http://127.0.0.1:8090",
        "RUDDERSTACK_HTTP_WRITE_KEY": "dilibori_http_write_key_local",
        "RUDDERSTACK_JS_WRITE_KEY": "dilibori_js_write_key_local",
    }


@router.get("/{deployment_id}/state")
def observed_state(
    deployment_id: str,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> dict[str, Any]:
    validate_deployment_id(deployment_id)
    try:
        return {"ok": True, **DockerService(settings).observe(deployment_id)}
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc


@router.get("/{deployment_id}/health")
def health(
    deployment_id: str,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> dict[str, Any]:
    validate_deployment_id(deployment_id)
    try:
        observed = DockerService(settings).observe(deployment_id)
        return {
            "ok": True,
            "healthy": bool(observed.get("running")),
            "observed": observed,
        }
    except Exception as exc:
        return {"ok": False, "healthy": False, "error": str(exc)}
