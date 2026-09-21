from __future__ import annotations

import redis

from app.config import Settings
from app.utils.naming import validate_sql_identifier


class RedisService:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def _admin_client(self) -> redis.Redis:
        return redis.Redis(
            host=self._settings.redis_host,
            port=self._settings.redis_port,
            username="admin",
            password=self._settings.redis_admin_password or None,
            decode_responses=True,
        )

    def create_acl_user(self, *, username: str, password: str, key_prefix: str) -> None:
        username = validate_sql_identifier(username, "username")
        prefix = key_prefix.strip().strip("/")
        if not prefix or "/" in prefix or " " in prefix:
            raise ValueError("key_prefix must be a non-empty path segment without spaces")

        key_pattern = f"~{prefix}*"
        command_rules = (
            "+@read",
            "+@write",
            "+@hash",
            "+@string",
            "+@list",
            "+@set",
            "+@sortedset",
            "+@stream",
            "+select",
            "+ping",
            "+echo",
            "+info",
            "+eval",
            "+evalsha",
            "+script",
            "-@dangerous",
            "-FLUSHALL",
            "-KEYS",
            "-CONFIG",
        )

        client = self._admin_client()
        # Wire-form ACL (>) avoids redis-py password-prefix quirks with decode_responses.
        client.execute_command(
            "ACL",
            "SETUSER",
            username,
            "reset",
            "on",
            f">{password}",
            key_pattern,
            *command_rules,
        )
        # Persist to /data/users.acl so tenant users survive Redis restarts.
        client.execute_command("ACL", "SAVE")
