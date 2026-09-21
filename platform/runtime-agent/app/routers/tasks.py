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
from app.services.redis_service import RedisService
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
    if environment in ("production", "staging"):
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
            database_name = payload.get("database_name") or f"ti_{deployment_id_short(deployment_id)}"
            username = _db_user(deployment_id)
            password = str(payload.get("db_password") or _password())
            max_conn = int((payload.get("config") or {}).get("MAX_USER_CONNECTIONS") or 5)
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

        if action == "create_secrets":
            prefix = f"ti:{deployment_id_short(deployment_id)}:"
            redis_user = f"r_{deployment_id_short(deployment_id)}"
            redis_password = _password()
            RedisService(settings).create_acl_user(
                username=redis_user,
                password=redis_password,
                key_prefix=prefix,
            )
            access_key = f"AK{deployment_id_short(deployment_id).upper()}"
            secret_key = _password(32)
            media_prefix = f"tenants/{payload.get('tenant_id', deployment_id)}/"
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
            memory = int(cfg.get("MEMORY_LIMIT_MB") or (384 if profile == "development" else 256))
            cpu = float(cfg.get("CPU_LIMIT") or (0.5 if profile == "development" else 0.25))
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

        raise HTTPException(status_code=400, detail=f"unknown action: {action}")
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc


def _tenant_env(settings: Settings, deployment_id: str, payload: dict[str, Any]) -> dict[str, str]:
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
        "EVENT_CLIENT_DRIVER": "structured-log",
        "CDP_ENABLED": "true",
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

    for key in ("DB_PASSWORD", "APP_KEY", "REDIS_PASSWORD", "ADMIN_PASSWORD", "REDIS_PREFIX", "REDIS_USERNAME"):
        if payload.get(key):
            env[key] = str(payload[key])

    return env


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
        return {"ok": True, "healthy": bool(observed.get("running")), "observed": observed}
    except Exception as exc:
        return {"ok": False, "healthy": False, "error": str(exc)}
