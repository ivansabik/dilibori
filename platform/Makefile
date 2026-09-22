.DEFAULT_GOAL := help
PLATFORM_DIR := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
REPO_ROOT := $(abspath $(PLATFORM_DIR)/..)

-include $(PLATFORM_DIR)/.env
export

TENANT_IMAGE ?= dilibori/tastyigniter:poc
HOST_SEEDS_PATH ?= $(PLATFORM_DIR)/seeds
HOST_EXTENSIONS_PATH ?= $(REPO_ROOT)/app/extensions
HOST_DATA_PATH ?= $(PLATFORM_DIR)/data
HOST_APP_SEED_PATH ?= $(REPO_ROOT)/app/seed

.PHONY: help build-app up down logs ps test lint pilots capacity env-copy reconcile cell-b-up cell-b-down migrate-schema

help:
	@echo ""
	@echo "Dilibori multi-tenant platform (local PoC)"
	@echo ""
	@echo "  make env-copy     Copy .env.example to .env"
	@echo "  make build-app    Build immutable TastyIgniter image"
	@echo "  make up           Start shared cell services"
	@echo "  make down         Stop shared cell services"
	@echo "  make cell-b-up    Start second data-plane cell (migrate tests)"
	@echo "  make cell-b-down  Stop second data-plane cell"
	@echo "  make migrate-schema  Apply control-plane SQL migrations"
	@echo "  make test         Run contract and policy tests"
	@echo "  make lint         Ruff check + format --check (platform + cdp)"
	@echo "  make pilots       Provision both pilot tenants via control plane"
	@echo "  make reconcile    Ask control plane to reconcile desired vs actual"
	@echo "  make capacity     Print density estimate for the local cell"
	@echo ""

env-copy:
	@test -f $(PLATFORM_DIR)/.env || cp $(PLATFORM_DIR)/.env.example $(PLATFORM_DIR)/.env
	@# Always rewrite host bind paths; empty values break tenant volume mounts.
	@grep -q '^HOST_SEEDS_PATH=' $(PLATFORM_DIR)/.env \
		&& sed -i.bak 's|^HOST_SEEDS_PATH=.*|HOST_SEEDS_PATH=$(HOST_SEEDS_PATH)|' $(PLATFORM_DIR)/.env \
		|| echo "HOST_SEEDS_PATH=$(HOST_SEEDS_PATH)" >> $(PLATFORM_DIR)/.env
	@grep -q '^HOST_EXTENSIONS_PATH=' $(PLATFORM_DIR)/.env \
		&& sed -i.bak 's|^HOST_EXTENSIONS_PATH=.*|HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH)|' $(PLATFORM_DIR)/.env \
		|| echo "HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH)" >> $(PLATFORM_DIR)/.env
	@grep -q '^HOST_DATA_PATH=' $(PLATFORM_DIR)/.env \
		&& sed -i.bak 's|^HOST_DATA_PATH=.*|HOST_DATA_PATH=$(HOST_DATA_PATH)|' $(PLATFORM_DIR)/.env \
		|| echo "HOST_DATA_PATH=$(HOST_DATA_PATH)" >> $(PLATFORM_DIR)/.env
	@grep -q '^HOST_APP_SEED_PATH=' $(PLATFORM_DIR)/.env \
		&& sed -i.bak 's|^HOST_APP_SEED_PATH=.*|HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH)|' $(PLATFORM_DIR)/.env \
		|| echo "HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH)" >> $(PLATFORM_DIR)/.env
	@grep -q '^HOST_CELL_B_DATA_PATH=' $(PLATFORM_DIR)/.env \
		&& sed -i.bak 's|^HOST_CELL_B_DATA_PATH=.*|HOST_CELL_B_DATA_PATH=$(PLATFORM_DIR)/data-cell-b|' $(PLATFORM_DIR)/.env \
		|| echo "HOST_CELL_B_DATA_PATH=$(PLATFORM_DIR)/data-cell-b" >> $(PLATFORM_DIR)/.env
	@rm -f $(PLATFORM_DIR)/.env.bak
	@echo "Wrote platform/.env"

build-app:
	docker build -t $(TENANT_IMAGE) -f $(REPO_ROOT)/app/Dockerfile $(REPO_ROOT)

up: env-copy
	@set -e; \
	HOST_SEEDS_PATH=$(HOST_SEEDS_PATH) \
	HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH) \
	HOST_DATA_PATH=$(HOST_DATA_PATH) \
	HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH) \
	TENANT_IMAGE=$(TENANT_IMAGE) \
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env up -d --build \
	|| ( \
		echo "Compose rename quirk (fixed container_name); recreating ingress and retrying..."; \
		docker rm -f dilibori-caddy >/dev/null 2>&1 || true; \
		HOST_SEEDS_PATH=$(HOST_SEEDS_PATH) \
		HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH) \
		HOST_DATA_PATH=$(HOST_DATA_PATH) \
		HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH) \
		TENANT_IMAGE=$(TENANT_IMAGE) \
		docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env up -d --build; \
	)
	@# Redis ACL users live in memory until SAVE; re-apply from control-plane secrets.
	@echo "Reconciling Redis ACLs for active tenants..."
	@for i in 1 2 3 4 5 6 7 8 9 10; do \
		curl -sf -X POST http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099}/reconcile \
			-H 'Content-Type: application/json' -d '{}' >/tmp/dilibori-reconcile.json && break; \
		sleep 2; \
	done
	@python3 -m json.tool </tmp/dilibori-reconcile.json 2>/dev/null || cat /tmp/dilibori-reconcile.json 2>/dev/null || true
	@echo "Control plane: http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099}"
	@echo "Ingress:       http://127.0.0.1:$${INGRESS_HTTP_PORT:-8088}"

down:
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env down

logs:
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env logs -f --tail=200

ps:
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env ps

test:
	chmod +x $(PLATFORM_DIR)/scripts/run-tests.sh $(PLATFORM_DIR)/scripts/*.py $(PLATFORM_DIR)/tests/*.py || true
	cd $(PLATFORM_DIR) && ./scripts/run-tests.sh

lint:
	cd $(REPO_ROOT) && python3 -m ruff check platform cdp/event-sink
	cd $(REPO_ROOT) && python3 -m ruff format --check platform cdp/event-sink

capacity:
	@python3 $(PLATFORM_DIR)/scripts/capacity_model.py --tenants 2 --idle-memory-mb 180 --proxy-budget 80
	@echo ""
	@echo "Recommended cell limit at 180MB idle / 5 conns / 80 proxy budget:"
	@python3 $(PLATFORM_DIR)/scripts/capacity_model.py --tenants 1 --idle-memory-mb 180 --connections-per-tenant 5 --proxy-budget 80 --host-memory-mb 8192 | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['recommended_cell_limit'], 'tenants/cell')"

pilots:
	chmod +x $(PLATFORM_DIR)/scripts/provision-pilots.sh
	CONTROL_PLANE_URL=http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099} $(PLATFORM_DIR)/scripts/provision-pilots.sh

reconcile:
	curl -sf -X POST http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099}/reconcile -H 'Content-Type: application/json' -d '{}' | python3 -m json.tool

cell-b-up: env-copy
	mkdir -p $(PLATFORM_DIR)/data-cell-b/ingress/tenants $(PLATFORM_DIR)/data-cell-b/redis $(PLATFORM_DIR)/data-cell-b/minio $(PLATFORM_DIR)/data-cell-b/tenants $(PLATFORM_DIR)/data-cell-b/snapshots
	HOST_SEEDS_PATH=$(HOST_SEEDS_PATH) \
	HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH) \
	HOST_CELL_B_DATA_PATH=$(PLATFORM_DIR)/data-cell-b \
	HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH) \
	TENANT_IMAGE=$(TENANT_IMAGE) \
	docker compose -f $(PLATFORM_DIR)/docker-compose.cell-b.yml --env-file $(PLATFORM_DIR)/.env up -d --build
	@echo "Cell B agent:   http://127.0.0.1:$${CELL_B_RUNTIME_AGENT_PORT:-8101}"
	@echo "Cell B ingress: http://127.0.0.1:$${CELL_B_INGRESS_HTTP_PORT:-8089}"
	@echo "Register with: POST /cells {\"name\":\"local-b\",\"agent_url\":\"http://dilibori-runtime-agent-b:8100\",\"capacity_metadata\":{\"max_deployments\":16,\"ingress_base_url\":\"http://127.0.0.1:8089\"}}"

cell-b-down:
	docker compose -f $(PLATFORM_DIR)/docker-compose.cell-b.yml --env-file $(PLATFORM_DIR)/.env down

migrate-schema:
	@echo "Applying control-plane migrations..."
	@docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env exec -T postgres \
		psql -U controlplane -d controlplane < $(PLATFORM_DIR)/control-plane/database/migrations/002_cell_migrate.sql
	@docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env exec -T postgres \
		psql -U controlplane -d controlplane -c "UPDATE cells SET agent_url = COALESCE(NULLIF(agent_url, ''), 'http://runtime-agent:8100'), capacity_metadata = capacity_metadata || '{\"ingress_base_url\":\"http://127.0.0.1:8088\"}'::jsonb WHERE name = 'local-dev';"
	@echo "Done."
