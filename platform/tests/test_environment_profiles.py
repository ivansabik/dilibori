#!/usr/bin/env python3
"""Configuration policy tests for environment profiles."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PROFILES = ROOT / "profiles"


def load(name: str) -> dict:
    return json.loads((PROFILES / f"{name}.json").read_text())


def resolve(profile: dict, tenant_overrides: dict) -> dict:
    allow = set(profile.get("allowlisted_tenant_overrides", []))
    config = dict(profile["config"])
    for key, value in tenant_overrides.items():
        if key not in allow:
            raise AssertionError(f"override {key} is not allowlisted")
        config[key] = value
    return config


def enforce_invariants(profile: dict, config: dict) -> None:
    invariants = profile.get("invariants", {})
    if invariants.get("APP_DEBUG") is False and config.get("APP_DEBUG") is not False:
        raise AssertionError("production-like forbids APP_DEBUG=true")
    if (
        invariants.get("APP_ENV") == "production"
        and config.get("APP_ENV") != "production"
    ):
        raise AssertionError("production-like requires APP_ENV=production")
    if invariants.get("forbid_source_mounts") and config.get("ALLOW_SOURCE_MOUNTS"):
        raise AssertionError("production-like forbids source mounts")
    if invariants.get("require_secure_cookies") and not config.get(
        "SESSION_SECURE_COOKIE"
    ):
        raise AssertionError("production-like requires secure cookies")


def main() -> int:
    development = load("development")
    production = load("production-like")

    assert development["config"]["APP_DEBUG"] is True
    assert production["config"]["APP_DEBUG"] is False

    # Allowlisted override ok
    resolve(development, {"MAIL_FROM_NAME": "Demo"})

    # Disallowed override rejected
    try:
        resolve(production, {"APP_DEBUG": True})
        print("FAIL: production allowed APP_DEBUG override")
        return 1
    except AssertionError:
        pass

    # Even if somehow injected, invariant catches it
    bad = dict(production["config"])
    bad["APP_DEBUG"] = True
    try:
        enforce_invariants(production, bad)
        print("FAIL: invariant missed APP_DEBUG")
        return 1
    except AssertionError:
        pass

    enforce_invariants(production, production["config"])
    print("OK: environment profile policy tests passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
