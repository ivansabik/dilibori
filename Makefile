# Proxy common targets into app/ or platform/.
.DEFAULT_GOAL := help

.PHONY: help up down stop start build rebuild logs shell ps passwd reset clean wait seed \
	cdp-up cdp-down cdp-logs cdp-health cdp-ps \
	mailpit-up mailpit-down mailpit-logs mailpit-ps mailpit-smoke \
	platform-up platform-down platform-test platform-pilots platform-build

help:
	@echo ""
	@echo "Dilibori monorepo"
	@echo ""
	@echo "  platform/         Multi-tenant PoC (preferred)"
	@echo "  app/              Single-tenant TastyIgniter storefront"
	@echo "  cdp/              RudderStack data plane + event inspector"
	@echo "  mailpit/          Local SMTP catcher"
	@echo ""
	@echo "Platform:"
	@echo "  make platform-build   Build immutable TI image"
	@echo "  make platform-up      Start shared cell + control plane"
	@echo "  make platform-test    Run contract tests"
	@echo "  make platform-pilots  Provision Taconazo + Pizza Rin pilots"
	@echo ""
	@$(MAKE) -C app help

platform-build:
	@$(MAKE) -C platform build-app

platform-up:
	@$(MAKE) -C platform up

platform-down:
	@$(MAKE) -C platform down

platform-test:
	@$(MAKE) -C platform test

platform-pilots:
	@$(MAKE) -C platform pilots

up down stop start build rebuild logs shell ps passwd reset clean wait seed:
	@$(MAKE) -C app $@ $(filter-out $@,$(MAKECMDGOALS))

cdp-up:
	@$(MAKE) -C cdp up

cdp-down:
	@$(MAKE) -C cdp down

cdp-logs:
	@$(MAKE) -C cdp logs

cdp-health:
	@$(MAKE) -C cdp health

cdp-ps:
	@$(MAKE) -C cdp ps

mailpit-up:
	@$(MAKE) -C mailpit up

mailpit-down:
	@$(MAKE) -C mailpit down

mailpit-logs:
	@$(MAKE) -C mailpit logs

mailpit-ps:
	@$(MAKE) -C mailpit ps

mailpit-smoke:
	@$(MAKE) -C mailpit smoke

%:
	@:
