# Proxy common targets into app/ (TastyIgniter Docker stack).
.DEFAULT_GOAL := help

.PHONY: help up down stop start build rebuild logs shell ps passwd reset clean wait seed \
	cdp-up cdp-down cdp-logs cdp-health cdp-ps

help:
	@echo ""
	@echo "El Taconazo monorepo"
	@echo ""
	@echo "  app/              TastyIgniter storefront (make targets below)"
	@echo "  cdp/              RudderStack data plane + event inspector"
	@echo ""
	@echo "CDP:"
	@echo "  make cdp-up       Start RudderStack (http://127.0.0.1:8080)"
	@echo "  make cdp-health   Check data plane /health"
	@echo "  make cdp-down     Stop CDP"
	@echo "  Inspector         http://127.0.0.1:8090"
	@echo ""
	@$(MAKE) -C app help

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

# Absorb extra goals passed through (e.g. PASSWORD=...)
%:
	@:
