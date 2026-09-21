from typing import Any, Literal

from pydantic import BaseModel, Field


class DeploymentRequest(BaseModel):
    deployment_id: str


class CreateNetworkRequest(DeploymentRequest):
    network_name: str


class CreateDatabaseRequest(DeploymentRequest):
    database_name: str
    username: str
    password: str
    max_user_connections: int = Field(default=10, ge=1, le=100)


class CreateRedisAclRequest(DeploymentRequest):
    username: str
    password: str
    key_prefix: str


class CreateMinioCredsRequest(DeploymentRequest):
    access_key: str
    secret_key: str
    bucket: str
    prefix: str


class ProvisionInstallRequest(DeploymentRequest):
    image: str | None = None
    env: dict[str, str] = Field(default_factory=dict)
    database_name: str
    network_name: str
    environment_profile: Literal["production", "development"] = "production"
    tenant_id: str | None = None
    config_version: str = "1"


class DeployRequest(DeploymentRequest):
    image: str | None = None
    env: dict[str, str] = Field(default_factory=dict)
    hostname_label: str
    memory_limit: str = "512m"
    cpu_limit: float = 1.0
    network_name: str
    environment_profile: Literal["production", "development"] = "production"
    tenant_id: str | None = None
    config_version: str = "1"


class ConfigureRouteRequest(DeploymentRequest):
    hostname: str
    container_name: str
    noindex: bool = False


class BackupRequest(DeploymentRequest):
    database_name: str


class RestoreRequest(DeploymentRequest):
    database_name: str
    backup_file: str


class OkResponse(BaseModel):
    ok: bool = True
    detail: str | None = None
    data: dict[str, Any] | None = None
