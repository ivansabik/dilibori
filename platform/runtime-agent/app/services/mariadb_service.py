from __future__ import annotations

import pymysql
from pymysql.cursors import DictCursor

from app.config import Settings
from app.services.proxysql_service import ProxySqlService
from app.utils.naming import validate_sql_identifier


class MariaDbService:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def _connect(self):
        return pymysql.connect(
            host=self._settings.mariadb_host,
            port=self._settings.mariadb_port,
            user=self._settings.mariadb_admin_user,
            password=self._settings.mariadb_admin_password,
            charset="utf8mb4",
            cursorclass=DictCursor,
            autocommit=True,
        )

    def create_database_user(
        self,
        *,
        database_name: str,
        username: str,
        password: str,
        max_user_connections: int,
    ) -> None:
        database_name = validate_sql_identifier(database_name, "database_name")
        username = validate_sql_identifier(username, "username")

        with self._connect() as connection:
            with connection.cursor() as cursor:
                cursor.execute(f"CREATE DATABASE IF NOT EXISTS `{database_name}`")
                cursor.execute(
                    "SELECT COUNT(*) AS count FROM mysql.user WHERE User = %s AND Host = %s",
                    (username, "%"),
                )
                row = cursor.fetchone()
                # When the query includes %s placeholders, literal % hosts must be written as %%.
                if row and row["count"]:
                    cursor.execute(
                        f"ALTER USER `{username}`@`%%` IDENTIFIED BY %s",
                        (password,),
                    )
                    cursor.execute(
                        f"ALTER USER `{username}`@`%%` WITH MAX_USER_CONNECTIONS %s",
                        (int(max_user_connections),),
                    )
                else:
                    cursor.execute(
                        f"CREATE USER `{username}`@`%%` IDENTIFIED BY %s WITH MAX_USER_CONNECTIONS %s",
                        (password, int(max_user_connections)),
                    )
                cursor.execute(
                    f"GRANT ALL PRIVILEGES ON `{database_name}`.* TO `{username}`@`%`",
                )
                cursor.execute("FLUSH PRIVILEGES")

        # Tenant containers authenticate via ProxySQL; keep mysql_users in sync.
        ProxySqlService(self._settings).upsert_mysql_user(
            username=username,
            password=password,
            max_connections=max_user_connections,
        )

    def drop_database_user(self, *, database_name: str, username: str) -> None:
        database_name = validate_sql_identifier(database_name, "database_name")
        username = validate_sql_identifier(username, "username")

        with self._connect() as connection:
            with connection.cursor() as cursor:
                cursor.execute(f"DROP DATABASE IF EXISTS `{database_name}`")
                cursor.execute(f"DROP USER IF EXISTS `{username}`@`%%`")
                cursor.execute("FLUSH PRIVILEGES")

        ProxySqlService(self._settings).delete_mysql_user(username=username)