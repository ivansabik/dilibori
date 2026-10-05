from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    runtime_agent_token: str = Field(alias="RUNTIME_AGENT_TOKEN")
    data_dir: str = Field(default="/var/lib/dilibori", alias="DATA_DIR")
    tenant_image: str = Field(default="dilibori/tastyigniter:poc", alias="TENANT_IMAGE")
    allowed_images: str = Field(
        default="dilibori/tastyigniter:poc",
        alias="ALLOWED_IMAGES",
    )
    shared_network: str = Field(default="dilibori-shared", alias="SHARED_NETWORK")
    caddy_container_name: str = Field(default="dilibori-caddy", alias="CADDY_CONTAINER_NAME")

    mariadb_host: str = Field(default="mariadb", alias="MARIADB_HOST")
    mariadb_admin_user: str = Field(default="root", alias="MARIADB_ADMIN_USER")
    mariadb_admin_password: str = Field(default="", alias="MARIADB_ADMIN_PASSWORD")
    mariadb_port: int = Field(default=3306, alias="MARIADB_PORT")

    # Tenant app containers connect here (MySQL protocol). Admin API is separate.
    proxysql_host: str = Field(default="proxysql", alias="PROXYSQL_HOST")
    proxysql_mysql_port: int = Field(default=6033, alias="PROXYSQL_MYSQL_PORT")
    proxysql_admin_host: str = Field(default="proxysql", alias="PROXYSQL_ADMIN_HOST")
    proxysql_admin_port: int = Field(default=6032, alias="PROXYSQL_ADMIN_PORT")
    proxysql_admin_user: str = Field(default="radmin", alias="PROXYSQL_ADMIN_USER")
    proxysql_admin_password: str = Field(
        default="platform-proxysql-admin",
        alias="PROXYSQL_ADMIN_PASSWORD",
    )

    minio_endpoint: str = Field(default="minio:9000", alias="MINIO_ENDPOINT")
    minio_root_user: str = Field(default="minioadmin", alias="MINIO_ROOT_USER")
    minio_root_password: str = Field(default="minioadmin", alias="MINIO_ROOT_PASSWORD")
    minio_secure: bool = Field(default=False, alias="MINIO_SECURE")
    # minio = local mc admin users; iam = AWS IAM users + inline prefix policy.
    object_store_identity: str = Field(default="minio", alias="OBJECT_STORE_IDENTITY")
    media_bucket: str = Field(default="dilibori-media", alias="MEDIA_BUCKET")
    # IAM user path prefix, e.g. dilibori/dev/tenants/
    object_store_iam_path: str = Field(
        default="dilibori/local/tenants/",
        alias="OBJECT_STORE_IAM_PATH",
    )

    host: str = Field(default="0.0.0.0", alias="RUNTIME_AGENT_HOST")
    port: int = Field(default=8100, alias="RUNTIME_AGENT_PORT")
    host_seeds_path: str = Field(default="", alias="HOST_SEEDS_PATH")
    host_extensions_path: str = Field(default="", alias="HOST_EXTENSIONS_PATH")
    host_data_path: str = Field(default="", alias="HOST_DATA_PATH")
    # Rich demo catalog (el-taconazo.php + images) for the taconazo seed bundle.
    host_app_seed_path: str = Field(default="", alias="HOST_APP_SEED_PATH")
    ingress_http_port: str = Field(default="8088", alias="INGRESS_HTTP_PORT")
    ingress_scheme: str = Field(default="http", alias="INGRESS_SCHEME")
    cdp_enabled: bool = Field(default=True, alias="CDP_ENABLED")

    tenant_mail_host: str = Field(default="mailpit", alias="TENANT_MAIL_HOST")
    tenant_mail_port: str = Field(default="1025", alias="TENANT_MAIL_PORT")
    tenant_mail_username: str = Field(default="", alias="TENANT_MAIL_USERNAME")
    tenant_mail_password: str = Field(default="", alias="TENANT_MAIL_PASSWORD")
    tenant_mail_encryption: str = Field(default="", alias="TENANT_MAIL_ENCRYPTION")

    @property
    def allowed_image_set(self) -> set[str]:
        images = {part.strip() for part in self.allowed_images.split(",") if part.strip()}
        images.add(self.tenant_image)
        return images


@lru_cache
def get_settings() -> Settings:
    return Settings()
