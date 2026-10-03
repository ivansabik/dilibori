#!/usr/bin/env python3
"""Config resolver used by control plane and tests."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


class ConfigResolver:
    def __init__(self, profiles_path: Path) -> None:
        self.profiles_path = profiles_path

    def load_profile(self, environment: str) -> dict:
        # Map deployment environment to profile file
        name = "production-like" if environment == "production" else "development"
        path = self.profiles_path / f"{name}.json"
        return json.loads(path.read_text())

    def resolve(
        self,
        environment: str,
        cell_defaults: dict | None = None,
        tenant_overrides: dict | None = None,
        secrets: dict | None = None,
    ) -> tuple[dict, str]:
        profile = self.load_profile(environment)
        config = dict(profile.get("config", {}))
        if cell_defaults:
            config.update(cell_defaults)

        allow = set(profile.get("allowlisted_tenant_overrides", []))
        for key, value in (tenant_overrides or {}).items():
            if key not in allow:
                raise ValueError(
                    f"tenant override '{key}' is not allowlisted for {environment}"
                )
            config[key] = value

        self._enforce_invariants(profile, config)

        # Secrets are referenced, never merged as plaintext into checksum
        # source beyond refs
        secret_refs = {k: f"secret://{k}" for k in (secrets or {})}
        effective = {**config, **secret_refs}
        checksum = hashlib.sha256(
            json.dumps(effective, sort_keys=True, default=str).encode()
        ).hexdigest()
        return effective, checksum

    def _enforce_invariants(self, profile: dict, config: dict) -> None:
        invariants = profile.get("invariants", {})
        if (
            invariants.get("APP_DEBUG") is False
            and config.get("APP_DEBUG") is not False
        ):
            raise ValueError("APP_DEBUG must be false for this environment profile")
        if invariants.get("APP_ENV") and config.get("APP_ENV") != invariants["APP_ENV"]:
            raise ValueError(f"APP_ENV must be {invariants['APP_ENV']}")
        if invariants.get("forbid_source_mounts") and config.get("ALLOW_SOURCE_MOUNTS"):
            raise ValueError("source mounts are forbidden")
        if invariants.get("require_secure_cookies") and not config.get(
            "SESSION_SECURE_COOKIE"
        ):
            raise ValueError("secure cookies required")
