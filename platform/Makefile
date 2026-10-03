.DEFAULT_GOAL := help
PLATFORM_DIR := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
REPO_ROOT := $(abspath $(PLATFORM_DIR)/..)

ENV ?= dev
-include $(PLATFORM_DIR)/.env
-include $(PLATFORM_DIR)/envs/$(ENV).env
export

TENANT_IMAGE ?= dilibori/tastyigniter:poc
HOST_SEEDS_PATH ?= $(PLATFORM_DIR)/seeds
HOST_EXTENSIONS_PATH ?= $(REPO_ROOT)/app/extensions
# Force per-env data dir so switching ENV= does not reuse a stale .env path.
HOST_DATA_PATH := $(PLATFORM_DIR)/data/$(ENV)
HOST_APP_SEED_PATH ?= $(REPO_ROOT)/app/seed
PLATFORM_ENV ?= $(ENV)
CELL_NAME := local-$(PLATFORM_ENV)

.PHONY: help build-app up down logs ps test lint pilots capacity env-copy reconcile cell-b-up cell-b-down migrate-schema install-hooks ensure-cdp admin-pass


help:
	@echo ""
	@echo "Dilibori multi-tenant platform (local PoC)"
	@echo ""
	@echo "  ENV=dev|prod selects a full stack (default: dev)."
	@echo ""
	@echo "  make env-copy     Copy .env.example to .env"
	@echo "  make build-app    Build immutable TastyIgniter image"
	@echo "  make up           Start stack for ENV (e.g. make up ENV=prod)"
	@echo "  make down         Stop stack for ENV"
	@echo "  make cell-b-up    Start second data-plane cell on dev (migrate tests)"
	@echo "  make cell-b-down  Stop second data-plane cell"
	@echo "  make migrate-schema  Apply control-plane SQL migrations for ENV"
	@echo "  make test         Run contract and policy tests"
	@echo "  make lint         Ruff check + format --check (platform + cdp)"
	@echo "  make install-hooks  Point git core.hooksPath at scripts/git-hooks"
	@echo "  make pilots       Provision pilot tenants on the ENV stack"
	@echo "  make admin-pass HOST=taconazo.prod.localhost"
	@echo "  make ensure-cdp   Recreate active runtimes so CDP env is present"
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
	@echo "Wrote platform/.env (HOST_DATA_PATH=$(HOST_DATA_PATH) for ENV=$(ENV))"

build-app:
	docker build -t $(TENANT_IMAGE) -f $(REPO_ROOT)/app/Dockerfile $(REPO_ROOT)

up: env-copy
	@test -f $(PLATFORM_DIR)/envs/$(ENV).env || (echo "Unknown ENV=$(ENV); expected envs/dev.env or envs/prod.env" >&2; exit 1)
	@mkdir -p $(HOST_DATA_PATH)/ingress/tenants $(HOST_DATA_PATH)/redis $(HOST_DATA_PATH)/minio $(HOST_DATA_PATH)/secrets $(HOST_DATA_PATH)/tenants $(HOST_DATA_PATH)/snapshots
	@set -e; \
	HOST_SEEDS_PATH=$(HOST_SEEDS_PATH) \
	HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH) \
	HOST_DATA_PATH=$(HOST_DATA_PATH) \
	HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH) \
	TENANT_IMAGE=$(TENANT_IMAGE) \
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/$(ENV).env up -d --build \
	|| ( \
		echo "Compose rename quirk (fixed container_name); recreating ingress and retrying..."; \
		docker rm -f dilibori-$(PLATFORM_ENV)-caddy >/dev/null 2>&1 || true; \
		HOST_SEEDS_PATH=$(HOST_SEEDS_PATH) \
		HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH) \
		HOST_DATA_PATH=$(HOST_DATA_PATH) \
		HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH) \
		TENANT_IMAGE=$(TENANT_IMAGE) \
		docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/$(ENV).env up -d --build; \
	)
	@# Redis ACL users live in memory until SAVE; re-apply from control-plane secrets.
	@echo "Reconciling Redis ACLs for active tenants..."
	@for i in 1 2 3 4 5 6 7 8 9 10; do \
		curl -sf -X POST http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099}/reconcile \
			-H 'Content-Type: application/json' -d '{}' >/tmp/dilibori-reconcile.json && break; \
		sleep 2; \
	done
	@python3 -m json.tool </tmp/dilibori-reconcile.json 2>/dev/null || cat /tmp/dilibori-reconcile.json 2>/dev/null || true
	@echo "ENV=$(ENV) stack: dilibori-$(PLATFORM_ENV)"
	@echo "Control plane: http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099}"
	@echo "Ingress:       http://127.0.0.1:$${INGRESS_HTTP_PORT:-8088}"

down:
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/$(ENV).env down

logs:
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/$(ENV).env logs -f --tail=200

ps:
	docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/$(ENV).env ps

test:
	chmod +x $(PLATFORM_DIR)/scripts/run-tests.sh $(PLATFORM_DIR)/scripts/*.py $(PLATFORM_DIR)/tests/*.py || true
	cd $(PLATFORM_DIR) && ./scripts/run-tests.sh

lint:
	cd $(REPO_ROOT) && python3 -m ruff check platform cdp/event-sink
	cd $(REPO_ROOT) && python3 -m ruff format --check platform cdp/event-sink

install-hooks:
	@chmod +x $(REPO_ROOT)/scripts/git-hooks/pre-commit
	@git -C $(REPO_ROOT) config core.hooksPath scripts/git-hooks
	@echo "git core.hooksPath -> scripts/git-hooks (pre-commit runs make lint + make test)"

capacity:
	@python3 $(PLATFORM_DIR)/scripts/capacity_model.py --tenants 2 --idle-memory-mb 180 --proxy-budget 80
	@echo ""
	@echo "Recommended cell limit at 180MB idle / 5 conns / 80 proxy budget:"
	@python3 $(PLATFORM_DIR)/scripts/capacity_model.py --tenants 1 --idle-memory-mb 180 --connections-per-tenant 5 --proxy-budget 80 --host-memory-mb 8192 | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['recommended_cell_limit'], 'tenants/cell')"

pilots:
	chmod +x $(PLATFORM_DIR)/scripts/provision-pilots.sh
	CONTROL_PLANE_URL=http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099} \
	RUNTIME_AGENT_URL=http://127.0.0.1:$${RUNTIME_AGENT_PORT:-8100} \
	PLATFORM_ENV=$(PLATFORM_ENV) \
	DEPLOYMENT_ENVIRONMENT=$(DEPLOYMENT_ENVIRONMENT) \
	INGRESS_HTTP_PORT=$(INGRESS_HTTP_PORT) \
	$(PLATFORM_DIR)/scripts/provision-pilots.sh

admin-pass:
	@test -n "$(HOST)" || (echo "Usage: make admin-pass HOST=taconazo.prod.localhost ENV=prod" >&2; exit 1)
	chmod +x $(PLATFORM_DIR)/scripts/print-admin-password.sh
	CONTROL_PLANE_URL=http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099} \
	PLATFORM_ENV=$(PLATFORM_ENV) \
	INGRESS_HTTP_PORT=$(INGRESS_HTTP_PORT) \
	$(PLATFORM_DIR)/scripts/print-admin-password.sh "$(HOST)"

ensure-cdp:
	chmod +x $(PLATFORM_DIR)/scripts/ensure-cdp-env.sh
	CONTROL_PLANE_URL=http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099} \
	RUNTIME_AGENT_URL=http://127.0.0.1:$${RUNTIME_AGENT_PORT:-8100} \
	$(PLATFORM_DIR)/scripts/ensure-cdp-env.sh

reconcile:
	curl -sf -X POST http://127.0.0.1:$${CONTROL_PLANE_PORT:-8099}/reconcile -H 'Content-Type: application/json' -d '{}' | python3 -m json.tool

cell-b-up: env-copy
	@test "$(ENV)" = "dev" || (echo "cell-b is a dev-only migrate fixture; use ENV=dev" >&2; exit 1)
	mkdir -p $(PLATFORM_DIR)/data-cell-b/ingress/tenants $(PLATFORM_DIR)/data-cell-b/redis $(PLATFORM_DIR)/data-cell-b/minio $(PLATFORM_DIR)/data-cell-b/tenants $(PLATFORM_DIR)/data-cell-b/snapshots
	HOST_SEEDS_PATH=$(HOST_SEEDS_PATH) \
	HOST_EXTENSIONS_PATH=$(HOST_EXTENSIONS_PATH) \
	HOST_CELL_B_DATA_PATH=$(PLATFORM_DIR)/data-cell-b \
	HOST_APP_SEED_PATH=$(HOST_APP_SEED_PATH) \
	TENANT_IMAGE=$(TENANT_IMAGE) \
	docker compose -f $(PLATFORM_DIR)/docker-compose.cell-b.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/dev.env up -d --build
	@echo "Cell B agent:   http://127.0.0.1:$${CELL_B_RUNTIME_AGENT_PORT:-8101}"
	@echo "Cell B ingress: http://127.0.0.1:$${CELL_B_INGRESS_HTTP_PORT:-8089}"
	@echo "Register with: POST /cells {\"name\":\"local-b\",\"agent_url\":\"http://dilibori-runtime-agent-b:8100\",\"capacity_metadata\":{\"max_deployments\":16,\"ingress_base_url\":\"http://127.0.0.1:8089\"}}"

cell-b-down:
	docker compose -f $(PLATFORM_DIR)/docker-compose.cell-b.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/dev.env down

migrate-schema:
	@echo "Applying control-plane migrations for ENV=$(ENV)..."
	@docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/$(ENV).env exec -T postgres \
		psql -U controlplane -d controlplane < $(PLATFORM_DIR)/control-plane/database/migrations/002_cell_migrate.sql
	@docker compose -f $(PLATFORM_DIR)/docker-compose.yml --env-file $(PLATFORM_DIR)/.env --env-file $(PLATFORM_DIR)/envs/$(ENV).env exec -T postgres \
		psql -U controlplane -d controlplane -c "UPDATE cells SET agent_url = COALESCE(NULLIF(agent_url, ''), 'http://runtime-agent:8100'), name = '$(CELL_NAME)', capacity_metadata = capacity_metadata || '{\"ingress_base_url\":\"http://127.0.0.1:$(INGRESS_HTTP_PORT)\"}'::jsonb WHERE id = '11111111-1111-4111-8111-111111111111' OR name LIKE 'local-%';"
	@echo "Done."
