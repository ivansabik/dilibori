"""host_bind_ready: Docker Desktop host paths vs agent compose mirrors."""

from __future__ import annotations

import tempfile
from pathlib import Path


def host_bind_ready(host_path: str, agent_mirror: Path) -> bool:
    """Mirror of docker_service.host_bind_ready (no docker import)."""
    if not host_path.strip():
        return False
    return Path(host_path).exists() or agent_mirror.exists()


def test_empty_host_path_is_not_ready() -> None:
    assert host_bind_ready("", Path("/tmp")) is False
    assert host_bind_ready("   ", Path("/tmp")) is False


def test_ready_when_host_path_exists() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        host = Path(tmp) / "seeds"
        host.mkdir()
        assert host_bind_ready(str(host), Path("/no/such/mirror")) is True


def test_ready_when_only_agent_mirror_exists() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        mirror = Path(tmp) / "opt-seeds"
        mirror.mkdir()
        assert host_bind_ready("/Users/nobody/platform/seeds", mirror) is True


def test_not_ready_when_neither_exists() -> None:
    assert host_bind_ready("/Users/nobody/missing-seeds", Path("/no/such/mirror")) is False


if __name__ == "__main__":
    test_empty_host_path_is_not_ready()
    test_ready_when_host_path_exists()
    test_ready_when_only_agent_mirror_exists()
    test_not_ready_when_neither_exists()
    print("OK: host_bind_ready")
