from fastapi import APIRouter, Depends

from app.auth import require_token
from app.config import Settings, get_settings
from app.models.schemas import OkResponse
from app.services.docker_service import DockerService

router = APIRouter(prefix="/capacity", tags=["capacity"])


@router.get("", response_model=OkResponse)
def get_capacity(
    _: None = Depends(require_token),
    settings: Settings = Depends(get_settings),
) -> OkResponse:
    data = DockerService(settings).capacity()
    return OkResponse(data=data)
