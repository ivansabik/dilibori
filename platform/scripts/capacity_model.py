#!/usr/bin/env python3
"""ponytail: O(n) capacity model for local PoC density estimates.

Upgrade path: replace with live Prometheus scrape + measured idle RSS.
"""

from __future__ import annotations

import argparse
import json
import sys


def estimate(
    tenants: int,
    idle_memory_mb: float,
    peak_memory_mb: float,
    connections_per_tenant: int,
    proxy_budget: int,
    host_memory_mb: float,
) -> dict:
    idle_total = tenants * idle_memory_mb
    peak_total = tenants * peak_memory_mb
    connections = tenants * connections_per_tenant
    max_by_mem = int(host_memory_mb // idle_memory_mb) if idle_memory_mb else 0
    max_by_conn = (
        proxy_budget // connections_per_tenant if connections_per_tenant else 0
    )
    return {
        "tenants": tenants,
        "idle_memory_mb_total": idle_total,
        "peak_memory_mb_total": peak_total,
        "connections_total": connections,
        "max_tenants_by_idle_memory": max_by_mem,
        "max_tenants_by_connections": max_by_conn,
        "recommended_cell_limit": min(max_by_mem, max_by_conn),
        "within_proxy_budget": connections <= proxy_budget,
        "within_memory_budget": idle_total <= host_memory_mb,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Estimate tenant density for a cell")
    parser.add_argument("--tenants", type=int, default=2)
    parser.add_argument("--idle-memory-mb", type=float, default=180)
    parser.add_argument("--peak-memory-mb", type=float, default=320)
    parser.add_argument("--connections-per-tenant", type=int, default=5)
    parser.add_argument("--proxy-budget", type=int, default=80)
    parser.add_argument("--host-memory-mb", type=float, default=8192)
    args = parser.parse_args()

    result = estimate(
        args.tenants,
        args.idle_memory_mb,
        args.peak_memory_mb,
        args.connections_per_tenant,
        args.proxy_budget,
        args.host_memory_mb,
    )
    print(json.dumps(result, indent=2))
    return 0 if result["within_proxy_budget"] and result["within_memory_budget"] else 1


if __name__ == "__main__":
    sys.exit(main())
