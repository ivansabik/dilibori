from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

import docker
from docker.errors import APIError, NotFound
from docker.models.containers import Container

from app.config import Settings
from app.services.allowlist import (
    PROVISION_INSTALL_COMMAND,
    validate_cpu_limit,
    validate_env,
    validate_hostname_label,
    validate_image,
    validate_memory_limit,
    validate_network_name,
)
from app.utils.naming import (
    default_network_name,
    deployment_id_short,
    tenant_container_name,
    validate_deployment_id,
)

logger = logging.getLogger(__name__)

LABEL_PREFIX = "dilibori."


class DockerService:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._client = docker.from_env()

    @property
    def client(self) -> docker.DockerClient:
        return self._client

    def ping(self) -> bool:
        self._client.ping()
        return True

    def create_network(self, *, deployment_id: str, network_name: str) -> dict:
        validate_deployment_id(deployment_id)
        network_name = validate_network_name(network_name)
        expected = default_network_name(deployment_id)
        if network_name != expected:
            raise ValueError(f"network_name must be {expected}")

        # PoC: reuse the shared cell network. Per-tenant bridges exhaust Docker
        # Desktop's default IPv4 pools after a few failed provision attempts.
        self._ensure_shared_network()
        network = self._client.networks.get(self._settings.shared_network)
        return {
            "network_id": network.id,
            "created": False,
            "network_name": self._settings.shared_network,
            "requested_network_name": network_name,
        }

    def _tenant_data_paths(self, deployment_id: str) -> dict[str, Path]:
        # When the agent runs in Docker, DATA_DIR is the in-container path, but bind
        # mounts for new containers must use the host path (HOST_DATA_PATH).
        data_root = Path(self._settings.host_data_path or self._settings.data_dir)
        base = data_root / "tenants" / deployment_id
        paths = {
            "storage": base / "storage",
            "cache": base / "bootstrap-cache",
            "media": base / "media",
        }
        # Ensure directories exist on the agent-visible filesystem too
        visible = Path(self._settings.data_dir) / "tenants" / deployment_id
        for name in ("storage", "bootstrap-cache", "media"):
            (visible / name).mkdir(parents=True, exist_ok=True)
        return paths

    def _tenant_labels(
        self,
        *,
        deployment_id: str,
        tenant_id: str | None,
        environment_profile: str,
        config_version: str,
        image: str,
        hostname_label: str | None = None,
    ) -> dict[str, str]:
        labels = {
            f"{LABEL_PREFIX}deployment_id": deployment_id,
            f"{LABEL_PREFIX}tenant_id": tenant_id or deployment_id,
            f"{LABEL_PREFIX}environment": environment_profile,
            f"{LABEL_PREFIX}config_version": config_version,
            f"{LABEL_PREFIX}managed_by": "runtime-agent",
        }
        if hostname_label:
            labels[f"{LABEL_PREFIX}hostname_label"] = hostname_label

        try:
            image_ref = self._client.images.get(image)
            digests = image_ref.attrs.get("RepoDigests") or []
            if digests:
                labels[f"{LABEL_PREFIX}image_digest"] = digests[0].split("@")[-1]
            else:
                image_id = image_ref.id.removeprefix("sha256:")
                labels[f"{LABEL_PREFIX}image_digest"] = image_id[:64]
        except NotFound:
            labels[f"{LABEL_PREFIX}image_digest"] = "unknown"

        return labels

    def _networks_for_tenant(self, deployment_network: str) -> dict[str, Any]:
        validate_network_name(deployment_network)
        return {
            deployment_network: {},
            self._settings.shared_network: {},
        }

    def _host_config(
        self,
        *,
        environment_profile: str,
        memory_limit: str,
        cpu_limit: float,
        deployment_id: str,
    ) -> dict[str, Any]:
        memory_limit = validate_memory_limit(memory_limit)
        cpu_limit = validate_cpu_limit(cpu_limit)
        paths = self._tenant_data_paths(deployment_id)

        host_config: dict[str, Any] = {
            "mem_limit": memory_limit,
            "nano_cpus": int(cpu_limit * 1_000_000_000),
            "cap_drop": ["ALL"],
            "security_opt": ["no-new-privileges:true"],
            "mounts": [
                docker.types.Mount(
                    target="/var/www/html/storage",
                    source=str(paths["storage"]),
                    type="bind",
                    read_only=False,
                ),
                docker.types.Mount(
                    target="/var/www/html/bootstrap/cache",
                    source=str(paths["cache"]),
                    type="bind",
                    read_only=False,
                ),
                docker.types.Mount(
                    target="/var/www/persistent-media",
                    source=str(paths["media"]),
                    type="bind",
                    read_only=False,
                ),
            ],
        }

        seeds_host = self._settings.host_seeds_path or ""
        extensions_host = self._settings.host_extensions_path or ""
        app_seed_host = self._settings.host_app_seed_path or ""
        if seeds_host and Path(seeds_host).exists():
            host_config["mounts"].append(
                docker.types.Mount(
                    target="/opt/platform/seeds",
                    source=seeds_host,
                    type="bind",
                    read_only=True,
                )
            )
        if extensions_host and Path(extensions_host).exists():
            host_config["mounts"].append(
                docker.types.Mount(
                    target="/opt/extensions",
                    source=extensions_host,
                    type="bind",
                    read_only=True,
                )
            )
        if app_seed_host and Path(app_seed_host).exists():
            host_config["mounts"].append(
                docker.types.Mount(
                    target="/opt/seed",
                    source=app_seed_host,
                    type="bind",
                    read_only=True,
                )
            )

        # PoC: entrypoint still rewrites Apache conf at boot; keep rootfs writable.
        # Re-enable read_only once apache config is baked into the image.
        host_config["read_only"] = False

        return host_config

    def _run_one_off(
        self,
        *,
        deployment_id: str,
        image: str,
        env: dict[str, str],
        network_name: str,
        environment_profile: str,
        tenant_id: str | None,
        config_version: str,
        command: list[str],
    ) -> dict:
        name = f"dilibori-provision-{deployment_id_short(deployment_id)}"
        self._remove_container_if_exists(name)

        host_config = self._host_config(
            environment_profile=environment_profile,
            memory_limit="512m",
            cpu_limit=1.0,
            deployment_id=deployment_id,
        )
        # One-shot install must write the app tree even for production tenants.
        host_config["read_only"] = False
        host_config.pop("tmpfs", None)
        mounts = host_config.pop("mounts")

        container = self._client.containers.run(
            image=image,
            name=name,
            command=command,
            environment=env,
            network=self._settings.shared_network,
            detach=True,
            remove=False,
            labels=self._tenant_labels(
                deployment_id=deployment_id,
                tenant_id=tenant_id,
                environment_profile=environment_profile,
                config_version=config_version,
                image=image,
            ),
            mounts=mounts,
            **host_config,
        )

        result = container.wait(timeout=900)
        logs = container.logs(tail=200).decode("utf-8", errors="replace")
        status_code = result.get("StatusCode", 1)
        container.remove(force=True)

        if status_code != 0:
            raise RuntimeError(f"provision container exited {status_code}: {logs[-2000:]}")

        return {"exit_code": status_code, "logs_tail": logs[-2000:]}

    def provision_install(
        self,
        *,
        deployment_id: str,
        image: str | None,
        env: dict[str, str],
        network_name: str,
        environment_profile: str,
        tenant_id: str | None,
        config_version: str,
    ) -> dict:
        validate_deployment_id(deployment_id)
        image = validate_image(image or self._settings.tenant_image, self._settings)
        env = validate_env(env, environment_profile=environment_profile)
        self._ensure_shared_network()

        return self._run_one_off(
            deployment_id=deployment_id,
            image=image,
            env=env,
            network_name=network_name,
            environment_profile=environment_profile,
            tenant_id=tenant_id,
            config_version=config_version,
            command=PROVISION_INSTALL_COMMAND,
        )

    def deploy(
        self,
        *,
        deployment_id: str,
        image: str | None,
        env: dict[str, str],
        hostname_label: str,
        memory_limit: str,
        cpu_limit: float,
        network_name: str,
        environment_profile: str,
        tenant_id: str | None,
        config_version: str,
    ) -> dict:
        validate_deployment_id(deployment_id)
        image = validate_image(image or self._settings.tenant_image, self._settings)
        env = validate_env(env, environment_profile=environment_profile)
        validate_network_name(network_name)
        validate_hostname_label(hostname_label)
        self._ensure_shared_network()

        name = tenant_container_name(deployment_id)
        self._remove_container_if_exists(name)

        host_config = self._host_config(
            environment_profile=environment_profile,
            memory_limit=memory_limit,
            cpu_limit=cpu_limit,
            deployment_id=deployment_id,
        )
        mounts = host_config.pop("mounts")

        container = self._client.containers.run(
            image=image,
            name=name,
            environment=env,
            detach=True,
            labels=self._tenant_labels(
                deployment_id=deployment_id,
                tenant_id=tenant_id,
                environment_profile=environment_profile,
                config_version=config_version,
                image=image,
                hostname_label=hostname_label,
            ),
            network=self._settings.shared_network,
            restart_policy={"Name": "unless-stopped"},
            mounts=mounts,
            **host_config,
        )

        return {
            "container_id": container.id,
            "container_name": name,
            "networks": [self._settings.shared_network],
        }

    def _connect_container_network(self, container_or_name: str | Container, network_name: str) -> None:
        try:
            network = self._client.networks.get(network_name)
        except NotFound as exc:
            raise RuntimeError(f"required network {network_name} not found") from exc

        if isinstance(container_or_name, str):
            container = self._client.containers.get(container_or_name)
        else:
            container = container_or_name

        try:
            network.connect(container)
        except APIError as exc:
            if "already exists" not in str(exc).lower():
                raise

    def _ensure_shared_network(self) -> None:
        try:
            self._client.networks.get(self._settings.shared_network)
        except NotFound as exc:
            raise RuntimeError(
                f"shared network {self._settings.shared_network} must exist before tenant ops"
            ) from exc

    def _get_tenant_container(self, deployment_id: str) -> Container:
        validate_deployment_id(deployment_id)
        name = tenant_container_name(deployment_id)
        return self._client.containers.get(name)

    def stop(self, deployment_id: str) -> dict:
        container = self._get_tenant_container(deployment_id)
        container.stop(timeout=30)
        return {"container_name": container.name, "status": "stopped"}

    def start(self, deployment_id: str) -> dict:
        container = self._get_tenant_container(deployment_id)
        container.start()
        return {"container_name": container.name, "status": "started"}

    def remove(self, deployment_id: str) -> dict:
        name = tenant_container_name(deployment_id)
        self._remove_container_if_exists(name)
        return {"container_name": name, "status": "removed"}

    def _remove_container_if_exists(self, name: str) -> None:
        try:
            container = self._client.containers.get(name)
        except NotFound:
            return
        container.remove(force=True)

    def observe(self, deployment_id: str) -> dict:
        container = self._get_tenant_container(deployment_id)
        container.reload()
        state = container.attrs.get("State", {})
        host_config = container.attrs.get("HostConfig", {})
        labels = container.labels or {}

        networks = list((container.attrs.get("NetworkSettings", {}).get("Networks") or {}).keys())

        health = state.get("Health", {}).get("Status") if state.get("Health") else None
        if health is None:
            health = "healthy" if state.get("Running") and state.get("Status") == "running" else state.get("Status")

        return {
            "deployment_id": deployment_id,
            "container_name": container.name,
            "image": container.image.tags[0] if container.image.tags else container.image.id,
            "image_digest": labels.get(f"{LABEL_PREFIX}image_digest"),
            "health": health,
            "running": bool(state.get("Running")),
            "config_version": labels.get(f"{LABEL_PREFIX}config_version"),
            "environment": labels.get(f"{LABEL_PREFIX}environment"),
            "networks": networks,
            "memory_limit": host_config.get("Memory"),
            "cpu_quota": host_config.get("CpuQuota"),
            "nano_cpus": host_config.get("NanoCpus"),
            "status": state.get("Status"),
        }

    def capacity(self) -> dict:
        tenants = self._client.containers.list(
            all=True,
            filters={"label": f"{LABEL_PREFIX}managed_by=runtime-agent"},
        )
        tenant_containers = [
            container
            for container in tenants
            if container.name.startswith("dilibori-tenant-")
        ]

        memory_bytes = 0
        running = 0
        for container in tenant_containers:
            container.reload()
            state = container.attrs.get("State", {})
            if state.get("Running"):
                running += 1
            mem = container.attrs.get("HostConfig", {}).get("Memory") or 0
            if mem:
                memory_bytes += mem

        return {
            "tenant_container_count": len(tenant_containers),
            "running_tenant_count": running,
            "estimated_memory_limit_bytes": memory_bytes,
            "estimated_memory_limit_gib": round(memory_bytes / (1024**3), 2),
        }
