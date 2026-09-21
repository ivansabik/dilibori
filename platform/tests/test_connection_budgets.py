#!/usr/bin/env python3
"""Connection budget checks for MariaDB tenants and control-plane PostgreSQL."""

from __future__ import annotations

import sys


def tenant_budget(
    tenants: int, per_tenant: int, proxy_budget: int, reserved: int = 10
) -> dict:
    usable = proxy_budget - reserved
    needed = tenants * per_tenant
    max_tenants = usable // per_tenant if per_tenant else 0
    return {
        "needed": needed,
        "usable": usable,
        "max_tenants": max_tenants,
        "ok": needed <= usable,
    }


def main() -> int:
    # production-like: MAX_USER_CONNECTIONS=5, ProxySQL max_connections=80, reserve 10
    mariadb = tenant_budget(tenants=12, per_tenant=5, proxy_budget=80, reserved=10)
    assert mariadb["ok"], mariadb
    assert mariadb["max_tenants"] == 14

    # control plane: PgBouncer DEFAULT_POOL_SIZE=10, keep API+worker under budget
    postgres = tenant_budget(tenants=1, per_tenant=8, proxy_budget=10, reserved=2)
    assert postgres["ok"], postgres

    # over-subscription rejected
    over = tenant_budget(tenants=20, per_tenant=5, proxy_budget=80, reserved=10)
    assert not over["ok"]

    print("OK: connection budget tests passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
