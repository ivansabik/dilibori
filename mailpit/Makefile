.DEFAULT_GOAL := help

.PHONY: help up down stop logs health ps network smoke clean

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nDilibori Mailpit (local SMTP catcher)\n\nUsage:\n  make \033[36m<target>\033[0m\n\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@printf "\nUI:     http://127.0.0.1:8025\nSMTP:   mailpit:1025 (Docker) / localhost:1025 (host)\nSmoke:  make smoke  (app + Mailpit must be up; seed first)\n\n"

network: ## Create shared Docker network (idempotent)
	@docker network inspect dilibori >/dev/null 2>&1 || docker network create dilibori
	@echo "Network dilibori ready"

up: network ## Start Mailpit
	docker compose up -d
	@echo ""
	@echo "Waiting for health..."
	@$(MAKE) --no-print-directory health
	@echo "UI → http://127.0.0.1:8025"

down: ## Stop Mailpit (keeps captured mail in ./data)
	docker compose down

stop: down ## Alias for down

logs: ## Tail Mailpit logs
	docker compose logs -f mailpit

ps: ## Show Mailpit container status
	docker compose ps

health: ## Wait until Mailpit /readyz responds
	@i=0; \
	until curl -sf http://127.0.0.1:8025/readyz >/dev/null 2>&1; do \
		i=$$((i+1)); \
		if [ $$i -ge 40 ]; then echo "Mailpit not healthy"; docker compose ps; exit 1; fi; \
		sleep 2; \
	done
	@echo "OK"

smoke: ## Send a test message from the app and assert From in Mailpit
	@./smoke.sh

clean: ## Stop Mailpit and wipe captured mail
	docker compose down -v --remove-orphans || true
	rm -rf data
	mkdir -p data
	touch data/.gitkeep
	@echo "Cleaned. Run: make up"
