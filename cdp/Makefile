.DEFAULT_GOAL := help

.PHONY: help up down stop logs health ps network clean

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nDilibori CDP (RudderStack data plane)\n\nUsage:\n  make \033[36m<target>\033[0m\n\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@printf "\nData plane:  http://127.0.0.1:8080\nInspector:   http://127.0.0.1:8090\nHealth:      curl http://127.0.0.1:8080/health\n\n"

network: ## Create shared Docker network (idempotent)
	@docker network inspect dilibori >/dev/null 2>&1 || docker network create dilibori
	@echo "Network dilibori ready"

up: network ## Start RudderStack data plane + event inspector
	docker compose up --build -d
	@echo ""
	@echo "Waiting for health..."
	@$(MAKE) --no-print-directory health
	@echo "Inspector → http://127.0.0.1:8090"

down: ## Stop CDP containers (keeps Postgres + event data)
	docker compose down

stop: down ## Alias for down

logs: ## Tail CDP logs
	docker compose logs -f rudder-server event-sink

ps: ## Show CDP container status
	docker compose ps

health: ## Wait until data plane /health responds
	@i=0; \
	until curl -sf http://127.0.0.1:8080/health >/dev/null 2>&1; do \
		i=$$((i+1)); \
		if [ $$i -ge 40 ]; then echo "RudderStack not healthy"; docker compose ps; exit 1; fi; \
		sleep 2; \
	done
	@curl -s http://127.0.0.1:8080/health; echo
	@echo "OK"

clean: ## Stop and remove CDP volumes (jobsdb + events)
	docker compose down -v --remove-orphans || true
	rm -rf data/jobsdb data/events
	mkdir -p data/jobsdb data/events
	@echo "Cleaned. Run: make up"
