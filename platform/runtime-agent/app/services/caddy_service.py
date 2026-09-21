from __future__ import annotations

import logging
from pathlib import Path

import docker
from docker.errors import NotFound

from app.config import Settings
from app.services.caddy_snippet import render_tenant_snippet
from app.utils.naming import validate_hostname, validate_deployment_id

logger = logging.getLogger(__name__)


class CaddyService:
    def __init__(self, settings: Settings, docker_client: docker.DockerClient) -> None:
        self._settings = settings
        self._docker = docker_client

    def configure_route(
        self,
        *,
        deployment_id: str,
        hostname: str,
        container_name: str,
        noindex: bool = False,
    ) -> dict:
        validate_deployment_id(deployment_id)
        hostname = validate_hostname(hostname)
        if not container_name.startswith("dilibori-tenant-"):
            raise ValueError("container_name must be a dilibori tenant container")

        snippet_dir = Path(self._settings.data_dir) / "ingress" / "tenants"
        snippet_dir.mkdir(parents=True, exist_ok=True)

        # One site block per hostname. Older runs used deployment_id filenames and
        # left duplicate host blocks that make Caddy refuse to start.
        safe_name = hostname.replace(".", "_")
        snippet_path = snippet_dir / f"{safe_name}.caddy"
        for stale in snippet_dir.glob("*.caddy"):
            try:
                text = stale.read_text(encoding="utf-8")
            except OSError:
                continue
            if stale == snippet_path:
                continue
            if text.lstrip().startswith(f"{hostname} ") or text.lstrip().startswith(f"{hostname}{{") or f"{hostname} {{" in text:
                stale.unlink(missing_ok=True)

        snippet_path.write_text(
            render_tenant_snippet(
                hostname=hostname,
                container_name=container_name,
                noindex=noindex,
            ),
            encoding="utf-8",
        )

        reload_result = self._signal_reload()
        return {
            "snippet_path": str(snippet_path),
            "hostname": hostname,
            "container_name": container_name,
            "noindex": noindex,
            "reload": reload_result,
        }

    def _signal_reload(self) -> dict:
        name = self._settings.caddy_container_name
        try:
            container = self._docker.containers.get(name)
        except NotFound:
            logger.warning("Caddy container %s not found; snippet written only", name)
            return {"attempted": False, "reason": "caddy container not found"}

        # Caddy Alpine builds log SIGUSR1 as "not implemented"; use the CLI instead.
        try:
            exit_code, output = container.exec_run(
                ["caddy", "reload", "--config", "/etc/caddy/Caddyfile"],
            )
            if exit_code == 0:
                return {"attempted": True, "method": "caddy reload"}
            detail = output.decode("utf-8", errors="replace") if isinstance(output, (bytes, bytearray)) else str(output)
            logger.warning("caddy reload failed (%s): %s", exit_code, detail)
            return {"attempted": True, "reason": detail.strip() or f"exit {exit_code}"}
        except Exception as exc:
            logger.warning("Failed to reload Caddy: %s", exc)
            return {"attempted": True, "reason": str(exc)}
