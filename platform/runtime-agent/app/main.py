from fastapi import FastAPI

from app.routers import capacity, health, tasks, tenants

app = FastAPI(
    title="Dilibori runtime agent",
    description="Privileged Docker runtime for multi-tenant TastyIgniter PoC",
    version="0.1.0",
)

app.include_router(health.router)
app.include_router(capacity.router)
app.include_router(tenants.router)
app.include_router(tasks.router)
