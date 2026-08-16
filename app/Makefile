# Default admin credentials (override: make passwd PASSWORD='newpass')
ADMIN_EMAIL ?= operations@taconazotaqueria.com
ADMIN_PASSWORD ?= 12345678abc!
APP_URL ?= http://127.0.0.1:8001

.DEFAULT_GOAL := help

.PHONY: help up down stop start build rebuild logs shell ps passwd reset clean wait seed network

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nTastyIgniter local Docker\n\nUsage:\n  make \033[36m<target>\033[0m\n\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@printf "\nAdmin login:  %s / %s\nStorefront:   %s\nAdmin:        %s/admin\n\n" "$(ADMIN_EMAIL)" "$(ADMIN_PASSWORD)" "$(APP_URL)" "$(APP_URL)"

network: ## Create shared Docker network for CDP (idempotent)
	@docker network inspect dilibori >/dev/null 2>&1 || docker network create dilibori

up: network ## Build (if needed) and start everything
	docker compose up db redis -d
	@echo "Waiting for database..."
	@$(MAKE) --no-print-directory wait
	docker compose up --build -d
	@echo ""
	@echo "Ready → $(APP_URL)/admin"
	@echo "Login  → $(ADMIN_EMAIL) / $(ADMIN_PASSWORD)"

start: up ## Alias for up

down: ## Stop containers (keeps DB + media)
	docker compose down

stop: down ## Alias for down

build: ## Build the app image
	docker compose build

rebuild: ## Rebuild image with no cache
	docker compose build --no-cache

logs: ## Tail app logs
	docker compose logs -f app

ps: ## Show container status
	docker compose ps

shell: ## Open a shell in the app container
	docker compose exec app bash

passwd: ## Set admin password (PASSWORD='...' EMAIL='...') — updates first admin email if needed
	docker compose exec -e ADMIN_EMAIL="$(or $(EMAIL),$(ADMIN_EMAIL))" -e ADMIN_PASSWORD="$(or $(PASSWORD),$(ADMIN_PASSWORD))" -u www-data app php -r '\
require "/var/www/html/vendor/autoload.php"; \
$$app = require "/var/www/html/bootstrap/app.php"; \
$$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \
$$email = getenv("ADMIN_EMAIL"); \
$$pass = getenv("ADMIN_PASSWORD"); \
$$user = Igniter\User\Models\User::query()->where("email", $$email)->first() \
    ?? Igniter\User\Models\User::query()->orderBy("user_id")->first(); \
if (!$$user) { fwrite(STDERR, "No admin user\n"); exit(1); } \
$$user->email = $$email; \
$$user->password = $$pass; \
$$user->save(); \
echo "Admin login ready\n"; \
'
	@echo "Password set for $(or $(EMAIL),$(ADMIN_EMAIL))"

seed: ## Load El Taconazo demo (El Paso USD + Cd Juárez MXN)
	docker compose exec -u www-data app php /opt/seed/el-taconazo.php
	@# Root-owned laravel.log from prior exec breaks Apache logging/reservations
	docker compose exec app bash -lc 'chown -R www-data:www-data /var/www/html/storage/logs && chmod -R ug+rwX /var/www/html/storage/logs'

wait: ## Wait until MariaDB accepts connections
	@i=0; \
	until docker compose exec -T db mariadb -utastyigniter -ptastyigniter -e "SELECT 1" >/dev/null 2>&1; do \
		i=$$((i+1)); \
		if [ $$i -ge 30 ]; then echo "Database not ready"; exit 1; fi; \
		sleep 2; \
	done
	@echo "Database is ready"

reset: network ## Wipe DB + recreate containers and reinstall (dev only)
	docker compose down -v --remove-orphans --rmi local || true
	rm -rf db
	mkdir -p db media
	touch db/.gitkeep media/.gitkeep
	docker compose up db redis -d
	@echo "Waiting for database..."
	@$(MAKE) --no-print-directory wait
	docker compose up --build -d --force-recreate
	@echo "Waiting for install + seed to finish..."
	@i=0; \
	until docker compose exec -T app test -f /var/www/html/public/index.php 2>/dev/null; do \
		i=$$((i+1)); \
		if [ $$i -ge 60 ]; then echo "App not ready"; exit 1; fi; \
		sleep 3; \
	done
	@# Wait until seeder created the super admin (entrypoint seeds on first boot)
	@i=0; \
	until docker compose exec -T app php -r 'require "/var/www/html/vendor/autoload.php"; $$a=require "/var/www/html/bootstrap/app.php"; $$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(Igniter\User\Models\User::query()->exists()?0:1);' 2>/dev/null; do \
		i=$$((i+1)); \
		if [ $$i -ge 40 ]; then echo "Seed/admin not ready — running make seed"; $(MAKE) --no-print-directory seed; break; fi; \
		sleep 3; \
	done
	@$(MAKE) --no-print-directory passwd
	@$(MAKE) --no-print-directory seed
	@echo ""
	@echo "Fresh install ready → $(APP_URL)/admin"
	@echo "Login → $(ADMIN_EMAIL) / $(ADMIN_PASSWORD)"
	@echo "Menu  → El Taconazo tacos seeded"

clean: ## Stop and delete DB data + local images (keeps media/)
	docker compose down -v --remove-orphans --rmi local || true
	rm -rf db
	mkdir -p db
	@echo "Cleaned. Run: make up"
