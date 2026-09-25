#!/usr/bin/env python3
"""Adversarial isolation contract tests (no live Docker required)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "runtime-agent"))

from app.services.allowlist import (  # noqa: E402
    AllowlistError,
    validate_env,
    validate_image,
)
from app.services.caddy_snippet import (  # noqa: E402
    effective_noindex,
    render_tenant_snippet,
)


class FakeSettings:
    tenant_image = "dilibori/tastyigniter:poc"
    allowed_images = "dilibori/tastyigniter:poc"

    @property
    def allowed_image_set(self):
        return {"dilibori/tastyigniter:poc"}


def main() -> int:
    settings = FakeSettings()

    try:
        validate_image("evil/image:latest", settings)  # type: ignore[arg-type]
        print("FAIL: unapproved image accepted")
        return 1
    except AllowlistError:
        pass

    # Spoofed tenant identity keys are allowlisted for injection by platform
    # only; client headers are stripped in PHP middleware (tested by presence
    # of middleware file).
    middleware = (
        ROOT.parent
        / "app/extensions/dilibori/platform/src/Http/Middleware"
        / "VerifyExpectedHost.php"
    )
    text = middleware.read_text()
    assert "X-Tenant-Id" in text
    assert "remove" in text

    block = (
        ROOT.parent
        / "app/extensions/dilibori/platform/src/Http/Middleware"
        / "BlockTenantCodeInstall.php"
    )
    assert "extensions/install" in block.read_text()

    # Production cannot enable debug
    try:
        validate_env(
            {"APP_DEBUG": "true", "APP_ENV": "production"},
            environment_profile="production",
        )
        print("FAIL: production APP_DEBUG accepted")
        return 1
    except AllowlistError:
        pass

    filtered = validate_env(
        {
            "APP_DEBUG": "false",
            "APP_ENV": "production",
            "APP_URL": "https://live.example",
            "TENANT_ID": "t1",
            "UNKNOWN_KEY": "x",
        },
        environment_profile="production",
    )
    assert "UNKNOWN_KEY" not in filtered
    assert filtered["APP_DEBUG"] == "false"
    assert filtered["APP_ENV"] == "production"

    local_http = validate_env(
        {
            "APP_DEBUG": "false",
            "APP_ENV": "production",
            "APP_URL": "http://taconazo.prod.localhost:8088",
        },
        environment_profile="production",
    )
    assert local_http["APP_ENV"] == "staging"
    assert local_http["APP_DEBUG"] == "false"

    # Tenant Redis ACL users must land on a writable path (ACL SAVE).
    redis_conf = (ROOT / "redis" / "redis.conf").read_text(encoding="utf-8")
    assert "aclfile /data/users.acl" in redis_conf, "redis aclfile must be under /data"

    # Demo/noindex tenants get X-Robots-Tag + disallow-all robots.txt at ingress.
    blocked = render_tenant_snippet(
        hostname="demo.example",
        container_name="dilibori-tenant-abc123",
        noindex=True,
    )
    assert 'X-Robots-Tag "noindex, nofollow"' in blocked
    assert "Disallow: /" in blocked
    assert "handle /robots.txt" in blocked
    assert "User-agent: *" in blocked

    open_snippet = render_tenant_snippet(
        hostname="live.example",
        container_name="dilibori-tenant-abc123",
        noindex=False,
    )
    assert "X-Robots-Tag" not in open_snippet
    assert "Disallow" not in open_snippet
    assert "reverse_proxy dilibori-tenant-abc123:80" in open_snippet

    # Effective noindex: only production + tenant flag off can stay indexable.
    cases = [
        (False, "production", False),
        (True, "production", True),
        (False, "development", True),
        (False, "staging", True),
        (False, "", True),
        (False, "unknown", True),
    ]
    for tenant_flag, environment, expect_blocked in cases:
        effective = effective_noindex(tenant_flag, environment)
        assert effective is expect_blocked, (tenant_flag, environment)
        snippet = render_tenant_snippet(
            hostname="case.example",
            container_name="dilibori-tenant-abc123",
            noindex=effective,
        )
        if expect_blocked:
            assert 'X-Robots-Tag "noindex, nofollow"' in snippet
            assert "Disallow: /" in snippet
        else:
            assert "X-Robots-Tag" not in snippet
            assert "Disallow" not in snippet

    print("OK: isolation contract tests passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
