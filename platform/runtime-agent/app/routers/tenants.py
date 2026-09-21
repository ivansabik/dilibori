from fastapi import APIRouter, Depends, HTTPException, status
from fastapi.responses import FileResponse

from app.auth import require_token
from app.config import Settings, get_settings
from app.models.schemas import (
    BackupRequest,
    ConfigureRouteRequest,
    CreateDatabaseRequest,
    CreateMinioCredsRequest,
    CreateNetworkRequest,
    CreateRedisAclRequest,
    DeploymentRequest,
    DeployRequest,
    OkResponse,
    ProvisionInstallRequest,
    RestoreRequest,
)
from app.services.allowlist import AllowlistError
from app.services.backup_service import BackupService
from app.services.caddy_service import CaddyService
from app.services.caddy_snippet import effective_noindex
from app.services.docker_service import DockerService
from app.services.mariadb_service import MariaDbService
from app.services.minio_service import MinioService
from app.services.redis_service import RedisService
from app.services.snapshot_service import SnapshotService
from app.utils.naming import validate_deployment_id

router = APIRouter(prefix="/tenants", tags=["tenants"])


def _bad_request(exc: Exception) -> HTTPException:
    return HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc))


def _server_error(exc: Exception) -> HTTPException:
    return HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(exc))


@router.get("/{deployment_id}/snapshot")
def download_snapshot(
    deployment_id: str,
    file: str,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> FileResponse:
    try:
        validate_deployment_id(deployment_id)
        path = SnapshotService(settings).snapshot_path(deployment_id, file)
        if not path.is_file():
            raise FileNotFoundError(f"snapshot not found: {file}")
    except (ValueError, FileNotFoundError) as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return FileResponse(
        path,
        media_type="application/gzip",
        filename=file,
    )


@router.post("/create-network", response_model=OkResponse)
def create_network(
    body: CreateNetworkRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        data = DockerService(settings).create_network(
            deployment_id=body.deployment_id,
            network_name=body.network_name,
        )
    except (AllowlistError, ValueError) as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/create-database", response_model=OkResponse)
def create_database(
    body: CreateDatabaseRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        MariaDbService(settings).create_database_user(
            database_name=body.database_name,
            username=body.username,
            password=body.password,
            max_user_connections=body.max_user_connections,
        )
    except ValueError as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(detail="database and user provisioned")


@router.post("/create-redis-acl", response_model=OkResponse)
def create_redis_acl(
    body: CreateRedisAclRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        RedisService(settings).create_acl_user(
            username=body.username,
            password=body.password,
            key_prefix=body.key_prefix,
        )
    except ValueError as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(detail="redis acl user created")


@router.post("/create-minio-creds", response_model=OkResponse)
def create_minio_creds(
    body: CreateMinioCredsRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        data = MinioService(settings).create_tenant_credentials(
            deployment_id=body.deployment_id,
            access_key=body.access_key,
            secret_key=body.secret_key,
            bucket=body.bucket,
            prefix=body.prefix,
        )
    except ValueError as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/provision-install", response_model=OkResponse)
def provision_install(
    body: ProvisionInstallRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        data = DockerService(settings).provision_install(
            deployment_id=body.deployment_id,
            image=body.image,
            env=body.env,
            network_name=body.network_name,
            environment_profile=body.environment_profile,
            tenant_id=body.tenant_id,
            config_version=body.config_version,
        )
    except (AllowlistError, ValueError) as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/deploy", response_model=OkResponse)
def deploy(
    body: DeployRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        data = DockerService(settings).deploy(
            deployment_id=body.deployment_id,
            image=body.image,
            env=body.env,
            hostname_label=body.hostname_label,
            memory_limit=body.memory_limit,
            cpu_limit=body.cpu_limit,
            network_name=body.network_name,
            environment_profile=body.environment_profile,
            tenant_id=body.tenant_id,
            config_version=body.config_version,
        )
    except (AllowlistError, ValueError) as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/stop", response_model=OkResponse)
def stop_tenant(
    body: DeploymentRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        data = DockerService(settings).stop(body.deployment_id)
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/start", response_model=OkResponse)
def start_tenant(
    body: DeploymentRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        data = DockerService(settings).start(body.deployment_id)
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/remove", response_model=OkResponse)
def remove_tenant(
    body: DeploymentRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        data = DockerService(settings).remove(body.deployment_id)
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.get("/{deployment_id}/observe", response_model=OkResponse)
def observe_tenant(
    deployment_id: str,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        data = DockerService(settings).observe(deployment_id)
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/configure-route", response_model=OkResponse)
def configure_route(
    body: ConfigureRouteRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        docker_service = DockerService(settings)
        data = CaddyService(settings, docker_service.client).configure_route(
            deployment_id=body.deployment_id,
            hostname=body.hostname,
            container_name=body.container_name,
            noindex=effective_noindex(body.noindex, body.environment),
        )
    except ValueError as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/backup", response_model=OkResponse)
def backup_tenant(
    body: BackupRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        data = BackupService(settings).backup_database(
            deployment_id=body.deployment_id,
            database_name=body.database_name,
        )
    except (ValueError, FileNotFoundError) as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)


@router.post("/restore", response_model=OkResponse)
def restore_tenant(
    body: RestoreRequest,
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    try:
        validate_deployment_id(body.deployment_id)
        data = BackupService(settings).restore_database(
            deployment_id=body.deployment_id,
            database_name=body.database_name,
            backup_file=body.backup_file,
        )
    except (ValueError, FileNotFoundError) as exc:
        raise _bad_request(exc) from exc
    except Exception as exc:
        raise _server_error(exc) from exc
    return OkResponse(data=data)
