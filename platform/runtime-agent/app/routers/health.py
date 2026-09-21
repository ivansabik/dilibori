import logging

from fastapi import APIRouter, Depends

from app.auth import require_token
from app.config import Settings, get_settings
from app.models.schemas import OkResponse
from app.services.docker_service import DockerService

logger = logging.getLogger(__name__)
router = APIRouter(tags=["health"])


@router.get("/health", response_model=OkResponse)
def health(
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    docker_ok = False
    try:
        DockerService(settings).ping()
        docker_ok = True
    except Exception:
        logger.exception("docker ping failed")
        docker_ok = False

    return OkResponse(
        data={
            "status": "ok" if docker_ok else "degraded",
            "docker": docker_ok,
        }
    )
