#!/usr/bin/env python3
"""Event envelope contract: tenant_id always present and IDs are namespaced."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
schema = json.loads((ROOT / "contracts" / "event-envelope.schema.json").read_text())


def envelope(tenant_id: str, local_user_id: str) -> dict:
    return {
        "tenant_id": tenant_id,
        "deployment_id": "00000000-0000-4000-8000-000000000001",
        "cell_id": "local-cell",
        "environment": "development",
        "event": "Product Viewed",
        "timestamp": "2026-01-01T00:00:00Z",
        "user_id": f"{tenant_id}:{local_user_id}",
        "anonymous_id": None,
        "properties": {
            "local_entity_type": "customer",
            "local_entity_id": local_user_id,
            "namespaced_entity_id": f"{tenant_id}:{local_user_id}",
        },
    }


def main() -> int:
    required = (
        "tenant_id",
        "deployment_id",
        "cell_id",
        "environment",
        "event",
        "timestamp",
        "properties",
    )
    for key in required:
        assert key in schema["required"]

    a = envelope("tenant-a", "42")
    b = envelope("tenant-b", "42")
    assert a["user_id"] != b["user_id"]
    assert (
        a["properties"]["namespaced_entity_id"]
        != b["properties"]["namespaced_entity_id"]
    )
    print("OK: event envelope tenant namespacing")
    return 0


if __name__ == "__main__":
    sys.exit(main())
