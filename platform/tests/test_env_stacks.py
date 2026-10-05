#!/usr/bin/env python3
"""Assert compose and env files keep one full stack per environment."""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _parse_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip()
    return values


def main() -> int:
    compose = (ROOT / "docker-compose.yml").read_text(encoding="utf-8")
    makefile = (ROOT / "Makefile").read_text(encoding="utf-8")
    # Top-level compose `name:` breaks on Compose v2.7 (${VAR} → literal).
    assert not re.search(r"(?m)^name:\s*", compose)
    assert "COMPOSE_PROJECT_NAME := dilibori-$(ENV)" in makefile
    assert "dilibori-${PLATFORM_ENV" in compose
    assert "./data/${PLATFORM_ENV" in compose
    assert "DEPLOYMENT_ENVIRONMENT" in compose
    assert "seed.sh" in compose
    assert "seed.sql" not in compose

    # Fixed global names must not reappear without PLATFORM_ENV.
    for banned in (
        "container_name: dilibori-caddy\n",
        "name: dilibori-shared\n",
        "name: dilibori-control\n",
        "name: dilibori-ingress\n",
        "name: dilibori-platform\n",
    ):
        assert banned not in compose, f"banned fixed name still present: {banned!r}"

    # Any dilibori-* network/container assignment should interpolate PLATFORM_ENV.
    for match in re.finditer(
        r"(?:container_name|name):\s*(dilibori-[^\n]+)",
        compose,
    ):
        value = match.group(1).strip()
        if value.startswith("dilibori-${PLATFORM_ENV"):
            continue
        # Service-local network aliases use short keys; only project-level names matter.
        if "${PLATFORM_ENV" not in value:
            raise AssertionError(
                f"compose name/container without PLATFORM_ENV: {value}"
            )

    dev = _parse_env(ROOT / "envs" / "dev.env")
    prod = _parse_env(ROOT / "envs" / "prod.env")
    assert dev["PLATFORM_ENV"] == "dev"
    assert prod["PLATFORM_ENV"] == "prod"
    assert dev["DEPLOYMENT_ENVIRONMENT"] == "development"
    assert prod["DEPLOYMENT_ENVIRONMENT"] == "production"

    aws_dev = _parse_env(ROOT / "envs" / "aws-dev.env")
    aws_prod = _parse_env(ROOT / "envs" / "aws-prod.env")
    assert aws_dev["PLATFORM_ENV"] == "dev"
    assert aws_prod["PLATFORM_ENV"] == "prod"
    assert aws_dev["COMPOSE_EXTRA_FILE"] == "docker-compose.aws.yml"
    assert aws_prod["COMPOSE_EXTRA_FILE"] == "docker-compose.aws.yml"
    assert aws_dev["CDP_ENABLED"] == "false"
    assert (ROOT / "docker-compose.aws.yml").is_file()
    assert (ROOT / "ingress" / "Caddyfile.aws").is_file()
    makefile = (ROOT / "Makefile").read_text(encoding="utf-8")
    assert "COMPOSE_EXTRA_FILE" in makefile
    assert "COMPOSE_UP_FLAGS" in makefile

    port_keys = [
        "INGRESS_HTTP_PORT",
        "CONTROL_PLANE_PORT",
        "RUNTIME_AGENT_PORT",
        "MAILPIT_UI_PORT",
        "MINIO_CONSOLE_PORT",
        "PROXYSQL_ADMIN_PORT",
    ]
    for key in port_keys:
        assert key in dev and key in prod, key
        assert dev[key] != prod[key], f"{key} must differ between env files"

    controller = (
        ROOT / "control-plane/src/Http/Controllers/DeploymentController.php"
    ).read_text(encoding="utf-8")
    assert "DEPLOYMENT_ENVIRONMENT" in controller
    assert "getenv('DEPLOYMENT_ENVIRONMENT')" in controller

    schema = (ROOT / "control-plane/database/schema.sql").read_text(encoding="utf-8")
    assert "staging" not in schema

    seed = ROOT / "control-plane/database/seed.sh"
    assert seed.is_file()
    assert not (ROOT / "control-plane/database/seed.sql").exists()
    seed_src = seed.read_text(encoding="utf-8")
    assert "PLATFORM_ENV" in seed_src
    assert "INGRESS_HTTP_PORT" in seed_src

    docker_src = (ROOT / "runtime-agent/app/services/docker_service.py").read_text(
        encoding="utf-8"
    )
    assert "return matches[0]" not in docker_src

    print("OK: per-env stack contracts passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
