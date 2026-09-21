from __future__ import annotations

import re

NETWORK_NAME_PATTERN = re.compile(r"^dilibori-net-[a-zA-Z0-9]{1,12}$")
BACKUP_FILE_PATTERN = re.compile(r"^[a-zA-Z0-9._-]+\.sql(?:\.gz)?$")

BASE_ENV_KEYS = frozenset(
    {
        "APP_ENV",
        "APP_KEY",
        "APP_URL",
        "APP_DEBUG",
        "LOG_LEVEL",
        "DB_CONNECTION",
        "DB_HOST",
        "DB_PORT",
        "DB_DATABASE",
        "DB_USERNAME",
        "DB_PASSWORD",
        "CACHE_DRIVER",
        "REDIS_HOST",
        "REDIS_PORT",
        "REDIS_DB",
        "REDIS_USERNAME",
        "REDIS_PASSWORD",
        "REDIS_PREFIX",
        "AWS_ACCESS_KEY_ID",
        "AWS_SECRET_ACCESS_KEY",
        "AWS_DEFAULT_REGION",
        "AWS_BUCKET",
        "AWS_ENDPOINT",
        "AWS_USE_PATH_STYLE_ENDPOINT",
        "MAIL_MAILER",
        "MAIL_HOST",
        "MAIL_PORT",
        "MAIL_USERNAME",
        "MAIL_PASSWORD",
        "MAIL_ENCRYPTION",
        "MAIL_FROM_ADDRESS",
        "MAIL_FROM_NAME",
        "ADMIN_EMAIL",
        "ADMIN_PASSWORD",
        "FILESYSTEM_DISK",
        "SESSION_DRIVER",
        "SESSION_SECURE_COOKIE",
        "SESSION_SAME_SITE",
        "QUEUE_CONNECTION",
        "TENANT_ID",
        "DEPLOYMENT_ID",
        "CELL_ID",
        "DEPLOYMENT_ENVIRONMENT",
        "EXPECTED_HOST",
        "SEED_MANIFEST",
        "SEED_MODE",
        "EVENT_CLIENT_DRIVER",
        "CDP_ENABLED",
        "CDP_ANONYMOUS_ID_COOKIE",
        "FORBID_MARKETPLACE_INSTALL",
        "FORBID_SELF_UPDATE",
        "APACHE_MAX_REQUEST_WORKERS",
        "QUEUE_WORKER_SLEEP",
        "TRUSTED_PROXIES",
        "MEMORY_LIMIT_MB",
        "CPU_LIMIT",
        "MAX_USER_CONNECTIONS",
        "ALLOW_SOURCE_MOUNTS",
        "READ_ONLY_ROOTFS",
        "LOG_STACK_TRACES",
        "SESSION_HTTP_ONLY",
    }
)

DEBUG_ENV_KEYS = frozenset({"APP_DEBUG", "LOG_LEVEL", "LOG_STACK_TRACES"})

PROVISION_INSTALL_COMMAND = ["provision"]


class AllowlistError(ValueError):
    pass


def validate_image(image: str, settings) -> str:
    image = image.strip()
    if not image:
        raise AllowlistError("image is required")
    if image not in settings.allowed_image_set:
        raise AllowlistError(f"image {image!r} is not allowlisted")
    return image


def validate_network_name(network_name: str) -> str:
    if not NETWORK_NAME_PATTERN.match(network_name):
        raise AllowlistError("network_name must match dilibori-net-{deployment_id_short}")
    return network_name


def validate_env(
    env: dict[str, str],
    *,
    environment_profile: str,
) -> dict[str, str]:
    allowed = set(BASE_ENV_KEYS)

    filtered: dict[str, str] = {}
    for key, value in env.items():
        if key not in allowed:
            # Drop unknown keys rather than failing hard on profile extras
            continue
        if environment_profile == "production" and key in DEBUG_ENV_KEYS:
            if key == "APP_DEBUG" and str(value).lower() in {"1", "true", "yes", "on"}:
                raise AllowlistError(f"env key {key!r} is not allowed in production profile")
            if key == "APP_DEBUG":
                filtered[key] = "false"
                continue
        if not isinstance(value, str):
            raise AllowlistError(f"env value for {key!r} must be a string")
        filtered[key] = value

    if environment_profile == "production":
        filtered["APP_DEBUG"] = "false"
        filtered["APP_ENV"] = "production"

    return filtered


def validate_memory_limit(memory_limit: str) -> str:
    memory_limit = memory_limit.strip()
    if not re.fullmatch(r"\d+[bkmgBKMG]?", memory_limit):
        raise AllowlistError("memory_limit must look like 512m or 1g")
    return memory_limit


def validate_cpu_limit(cpu_limit: float) -> float:
    if cpu_limit <= 0 or cpu_limit > 16:
        raise AllowlistError("cpu_limit must be between 0 and 16")
    return cpu_limit


def validate_backup_file(backup_file: str) -> str:
    if not BACKUP_FILE_PATTERN.match(backup_file):
        raise AllowlistError("backup_file must be a .sql or .sql.gz filename without path segments")
    if "/" in backup_file or ".." in backup_file:
        raise AllowlistError("backup_file must not contain path segments")
    return backup_file


def validate_hostname_label(hostname_label: str) -> str:
    label = hostname_label.strip().lower()
    # Accept a single label or a simple FQDN used as Expected Host
    if not re.fullmatch(
        r"[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*",
        label,
    ):
        raise AllowlistError("hostname_label must be a valid hostname")
    return label
