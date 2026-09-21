from __future__ import annotations

import pymysql
from pymysql.cursors import DictCursor

from app.config import Settings
from app.utils.naming import validate_sql_identifier


class ProxySqlService:
    """Register tenant MySQL users so clients can authenticate through ProxySQL."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def _connect(self):
        return pymysql.connect(
            host=self._settings.proxysql_admin_host,
            port=self._settings.proxysql_admin_port,
            user=self._settings.proxysql_admin_user,
            password=self._settings.proxysql_admin_password,
            charset="utf8mb4",
            cursorclass=DictCursor,
            autocommit=True,
        )

    def upsert_mysql_user(
        self,
        *,
        username: str,
        password: str,
        max_connections: int,
        default_hostgroup: int = 0,
    ) -> None:
        username = validate_sql_identifier(username, "username")
        max_connections = max(1, int(max_connections))

        with self._connect() as connection:
            with connection.cursor() as cursor:
                cursor.execute(
                    "DELETE FROM mysql_users WHERE username = %s",
                    (username,),
                )
                cursor.execute(
                    """
                    INSERT INTO mysql_users (
                        username, password, default_hostgroup, max_connections, active
                    ) VALUES (%s, %s, %s, %s, 1)
                    """,
                    (username, password, int(default_hostgroup), max_connections),
                )
                cursor.execute("LOAD MYSQL USERS TO RUNTIME")
                cursor.execute("SAVE MYSQL USERS TO DISK")

    def delete_mysql_user(self, *, username: str) -> None:
        username = validate_sql_identifier(username, "username")

        with self._connect() as connection:
            with connection.cursor() as cursor:
                cursor.execute("DELETE FROM mysql_users WHERE username = %s", (username,))
                cursor.execute("LOAD MYSQL USERS TO RUNTIME")
                cursor.execute("SAVE MYSQL USERS TO DISK")
