#!/usr/bin/env python3
"""Assert-based checks for AWS-facing runtime-agent settings."""

from __future__ import annotations

import ast
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "runtime-agent"))

from app.services.caddy_snippet import render_tenant_snippet  # noqa: E402


def main() -> int:
    https = render_tenant_snippet(
        hostname="taconazo.dev.example.com",
        container_name="dilibori-tenant-abc123abcdef",
        noindex=True,
        scheme="https",
    )
    assert https.startswith("taconazo.dev.example.com {"), https
    assert "http://" not in https
    assert 'X-Robots-Tag "noindex, nofollow"' in https

    http = render_tenant_snippet(
        hostname="taconazo.dev.localhost",
        container_name="dilibori-tenant-abc123abcdef",
        noindex=False,
        scheme="http",
    )
    assert http.startswith("http://taconazo.dev.localhost {"), http

    config_src = (ROOT / "runtime-agent/app/config.py").read_text(encoding="utf-8")
    for alias in (
        "OBJECT_STORE_IDENTITY",
        "CDP_ENABLED",
        "INGRESS_SCHEME",
        "TENANT_MAIL_HOST",
        "MEDIA_BUCKET",
    ):
        assert f'alias="{alias}"' in config_src, alias

    tasks_path = ROOT / "runtime-agent/app/routers/tasks.py"
    tasks_src = tasks_path.read_text(encoding="utf-8")
    assert 'return {"CDP_ENABLED": "false"}' in tasks_src
    assert "settings.tenant_mail_host" in tasks_src
    assert 'scheme == "https"' in tasks_src
    assert (
        'created.get("access_key"' in tasks_src
        or "created.get('access_key'" in tasks_src
    )

    minio_src = (ROOT / "runtime-agent/app/services/minio_service.py").read_text(
        encoding="utf-8"
    )
    assert "object_store_identity" in minio_src
    assert 'identity == "iam"' in minio_src
    assert "IamAwsProvider" in minio_src
    assert "create_access_key" in minio_src
    assert "ponytail:" in minio_src
    assert "AssumeRole" in minio_src

    # Parse minio_service to ensure it is valid Python.
    ast.parse(minio_src)
    ast.parse(config_src)
    ast.parse(tasks_src)

    req = (ROOT / "runtime-agent/requirements.txt").read_text(encoding="utf-8")
    assert "boto3==" in req

    print("OK: aws agent settings tests passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
