import re

DEPLOYMENT_ID_PATTERN = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$")
SQL_IDENTIFIER_PATTERN = re.compile(r"^[a-zA-Z0-9_]{1,64}$")
HOSTNAME_PATTERN = re.compile(
    r"^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$"
)


def validate_deployment_id(deployment_id: str) -> str:
    if not DEPLOYMENT_ID_PATTERN.match(deployment_id):
        raise ValueError("deployment_id must be 1-63 alphanumeric characters, hyphens, or underscores")
    return deployment_id


def deployment_id_short(deployment_id: str) -> str:
    validate_deployment_id(deployment_id)
    sanitized = re.sub(r"[^a-zA-Z0-9]", "", deployment_id)
    return sanitized[:12] or deployment_id[:12]


def tenant_container_name(deployment_id: str) -> str:
    return f"dilibori-tenant-{deployment_id_short(deployment_id)}"


def default_network_name(deployment_id: str) -> str:
    return f"dilibori-net-{deployment_id_short(deployment_id)}"


def validate_sql_identifier(value: str, field_name: str) -> str:
    if not SQL_IDENTIFIER_PATTERN.match(value):
        raise ValueError(f"{field_name} must be 1-64 alphanumeric characters or underscores")
    return value


def validate_hostname(value: str) -> str:
    if not HOSTNAME_PATTERN.match(value):
        raise ValueError("hostname is not valid")
    return value.lower()
