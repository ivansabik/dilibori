# Proxy common targets into app/ (TastyIgniter Docker stack).
.DEFAULT_GOAL := help

.PHONY: help up down stop start build rebuild logs shell ps passwd reset clean wait seed

help:
	@echo ""
	@echo "El Taconazo monorepo"
	@echo ""
	@echo "  app/              TastyIgniter storefront (make targets below)"
	@echo "  data_warehouse/   Warehouse"
	@echo "  cdp/              Customer data platform"
	@echo "  dashboarding/     Dashboards"
	@echo ""
	@$(MAKE) -C app help

up down stop start build rebuild logs shell ps passwd reset clean wait seed:
	@$(MAKE) -C app $@ $(filter-out $@,$(MAKECMDGOALS))

# Absorb extra goals passed through (e.g. PASSWORD=...)
%:
	@:
