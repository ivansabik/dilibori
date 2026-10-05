# Admin email for legacy single-tenant (password is random; see admin-pass).
ADMIN_EMAIL ?= operations@taconazotaqueria.com
APP_URL ?= http://127.0.0.1:8001
ADMIN_PASSWORD_FILE := /var/www/html/storage/dilibori-admin-password

.DEFAULT_GOAL := help

.PHONY: help up down stop start build rebuild logs shell ps passwd admin-pass reset clean wait seed network

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nTastyIgniter local Docker\n\nUsage:\n  make \033[36m<target>\033[0m\n\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@printf "\nAdmin email:  %s\nPassword:     make admin-pass (random per install)\nStorefront:   %s\nAdmin:        %s/admin\n\n" "$(ADMIN_EMAIL)" "$(APP_URL)" "$(APP_URL)"

network: ## Create shared Docker network for CDP (idempotent)
	@docker network inspect dilibori >/dev/null 2>&1 || docker network create dilibori

up: network ## Build (if needed) and start everything
	docker compose up db -d
	@echo "Waiting for database..."
	@$(MAKE) --no-print-directory wait
	docker compose up --build -d
	@echo ""
	@echo "Ready → $(APP_URL)/admin"
	@echo "Email  → $(ADMIN_EMAIL)"
	@echo "Pass   → make admin-pass  (after first provision finishes)"

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

admin-pass: ## Print the provisioned admin password
	@docker compose exec -T app bash -lc '\
		f="$(ADMIN_PASSWORD_FILE)"; \
		p="/var/www/persistent-media/.dilibori-admin-password"; \
		if [ -s "$$f" ]; then cat "$$f"; \
		elif [ -s "$$p" ]; then cat "$$p"; \
		else echo "No stored password yet. Wait for provision, or: make passwd" >&2; exit 1; fi' \
		| { read -r pass; echo "Email:     $(ADMIN_EMAIL)"; echo "Admin URL: $(APP_URL)/admin"; echo "Password:  $$pass"; }

passwd: ## Set admin password (omit PASSWORD= to generate random)
	@pass="$(PASSWORD)"; \
	if [ -z "$$pass" ]; then pass=$$(openssl rand -hex 12); echo "Generated password: $$pass"; fi; \
	docker compose exec -e ADMIN_EMAIL="$(or $(EMAIL),$(ADMIN_EMAIL))" -e ADMIN_PASSWORD="$$pass" -u www-data app php -r '\
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
$$file = base_path("storage/dilibori-admin-password"); \
file_put_contents($$file, $$pass); \
chmod($$file, 0600); \
$$persistent = "/var/www/persistent-media/.dilibori-admin-password"; \
if (is_dir("/var/www/persistent-media") && is_writable("/var/www/persistent-media")) { \
    file_put_contents($$persistent, $$pass); \
    chmod($$persistent, 0600); \
} \
echo "Admin login ready\n"; \
'
	@echo "Password set for $(or $(EMAIL),$(ADMIN_EMAIL)) (make admin-pass to reprint)"

seed: ## Load El Taconazo demo via tenant manifest (menu + shared activity)
	docker compose exec -u www-data -e SEED_MODE=initial app \
		php /opt/platform/seeds/common/seed-from-manifest.php /opt/seed-manifest/manifest.json
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
	docker compose up db -d
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
	@$(MAKE) --no-print-directory seed
	@echo ""
	@echo "Fresh install ready → $(APP_URL)/admin"
	@echo "Email → $(ADMIN_EMAIL)"
	@$(MAKE) --no-print-directory admin-pass
	@echo "Menu  → El Taconazo tacos seeded"

clean: ## Stop and delete DB data + local images (keeps media/)
	docker compose down -v --remove-orphans --rmi local || true
	rm -rf db
	mkdir -p db
	@echo "Cleaned. Run: make up"
