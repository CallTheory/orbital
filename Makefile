# Orbital stack control.
#
# Wraps `docker compose` with a graceful stop timeout so the stateful
# tiers shut down cleanly instead of getting SIGKILLed mid-write. The
# compose default (10s) is too short for Valkey to fsync its AOF and
# for Postgres/Patroni to checkpoint, which is what corrupts state and
# turns the next `up` into a crash-loop cascade.
#
# COMPOSE_FILE in .env already lists all overlays (base + the 6 HA
# files), so plain `docker compose` picks them all up — no -f needed.

# Graceful stop window (seconds). Valkey needs this to flush its AOF.
STOP_TIMEOUT ?= 60
COMPOSE      ?= docker compose

.DEFAULT_GOAL := help
.PHONY: help up down stop start restart status health logs ps fix-valkey

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## Start the whole stack (detached). depends_on ordering brings the data tier up first.
	$(COMPOSE) up -d

down: ## Graceful stop + remove containers (volumes kept).
	$(COMPOSE) down -t $(STOP_TIMEOUT)

stop: ## Graceful stop, keep containers.
	$(COMPOSE) stop -t $(STOP_TIMEOUT)

start: ## Start previously-stopped containers.
	$(COMPOSE) start

restart: stop up ## Graceful stop, then start.

status ps: ## Show every service and its state.
	$(COMPOSE) ps

health: ## List anything NOT up/healthy (empty = all good).
	@bad=$$($(COMPOSE) ps -a --format '{{.Name}}\t{{.Status}}' \
		| grep -iE 'restart|unhealthy|exited|created' || true); \
	if [ -z "$$bad" ]; then echo "all services up"; else echo "$$bad"; fi

logs: ## Tail logs for all services (Ctrl-C to stop). Use `make logs S=valkey-1` for one.
	$(COMPOSE) logs -f --tail 100 $(S)

fix-valkey: ## Repair a corrupted Valkey AOF (run if valkey crash-loops on "Bad file format").
	./bin/fix-valkey-aof.sh
