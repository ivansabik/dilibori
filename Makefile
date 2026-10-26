# Proxy common targets into app/.
.DEFAULT_GOAL := help

.PHONY: help up down stop start build rebuild logs shell ps passwd admin-pass reset clean wait seed \
	cdp-up cdp-down cdp-logs cdp-health cdp-ps \
	mailpit-up mailpit-down mailpit-logs mailpit-ps mailpit-smoke \
	install-hooks

help:
	@echo ""
	@echo "Dilibori monorepo"
	@echo ""
	@echo "  app/              El Taconazo storefront"
	@echo "  cdp/              RudderStack data plane + event inspector"
	@echo "  mailpit/          Local SMTP catcher"
	@echo ""
	@echo "  make install-hooks  Point git core.hooksPath at scripts/git-hooks"
	@echo ""
	@$(MAKE) -C app help

install-hooks:
	@git config core.hooksPath scripts/git-hooks
	@echo "git core.hooksPath -> scripts/git-hooks (pre-commit runs ruff on cdp/event-sink)"

up down stop start build rebuild logs shell ps passwd admin-pass reset clean wait seed:
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
