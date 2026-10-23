# WordPress Plugin Testing Infrastructure
# ========================================
#
# Driven by plugins.json via bin/manifest.php — there are no hardcoded
# plugin lists here. Add a plugin by adding one entry to plugins.json.
#
# Docker usage:
#   make start               - Start Docker environment (regenerates docker/docker-compose.plugins.yml)
#   make stop                - Stop Docker environment
#   make start-host-network  - Same, but with docker/docker-compose.host-network.yml layered
#                               on top (for boxes where bridge networking can't reach the DB/internet)
#   make stop-host-network   - Stop the host-network variant
#   make clean                - Stop and remove volumes
#   make test                 - Run all tests
#   make test-unit             - Run unit tests only
#   make test-integration      - Run integration tests only
#   make test-plugin PLUGIN=slug - Run tests for a single plugin
#   make lint                  - Run PHPCS on all plugins (report-only in CI)
#   make lint-fix               - Auto-fix PHPCS issues
#   make lint-plugin PLUGIN=slug - Lint a single plugin
#   make check-versions          - Verify version consistency for every plugin
#   make pot                     - Regenerate .pot translation templates
#   make shell                    - Open shell in WordPress container
#   make mysql-shell                - Open MySQL shell
#
# Native (no Docker) usage — see bin/setup-native.sh and CLAUDE.md:
#   make test-native WP_TESTS_DIR=... WP_CORE_DIR=...
#   make test-plugin-native PLUGIN=slug WP_TESTS_DIR=... WP_CORE_DIR=...
#   make lint-native

MANIFEST = bin/manifest.php

PHP_VERSION ?= $(shell php $(MANIFEST) default php_runtime)
WP_VERSION  ?= $(shell php $(MANIFEST) default wp_latest_tested)
export PHP_VERSION WP_VERSION

DOCKER_COMPOSE = docker compose -f docker/docker-compose.yml -f docker/docker-compose.plugins.yml
CONTAINER = $(DOCKER_COMPOSE) exec -T wordpress
PLUGIN ?=

# Host-network variant (docker/docker-compose.host-network.yml) — for boxes
# where Docker's bridge/user-defined networks can't reach the DB or the
# internet but host networking works. See CLAUDE.md "Docker host networking".
DOCKER_COMPOSE_HOST_NETWORK = $(DOCKER_COMPOSE) -f docker/docker-compose.host-network.yml
CONTAINER_HOST_NETWORK = $(DOCKER_COMPOSE_HOST_NETWORK) exec -T wordpress

# Native test environment (override via env vars or `make ... VAR=value`)
WP_TESTS_DIR ?= /tmp/wordpress-tests-lib
WP_CORE_DIR ?= /var/www/html
WP_TESTS_DB_HOST ?= localhost
WP_TESTS_DB_NAME ?= wordpress_test
WP_TESTS_DB_USER ?= wordpress
WP_TESTS_DB_PASS ?= wordpress
WP_TESTS_PHPUNIT_POLYFILLS_PATH ?= /opt/wp-tools/vendor/yoast/phpunit-polyfills
PHPCS_BIN ?= phpcs
PHPUNIT_BIN ?= phpunit
export WP_TESTS_DIR WP_CORE_DIR WP_TESTS_DB_HOST WP_TESTS_DB_NAME WP_TESTS_DB_USER WP_TESTS_DB_PASS WP_TESTS_PHPUNIT_POLYFILLS_PATH

# The native test targets do NOT inherit the Docker-oriented WP_VERSION
# default above — tests/bootstrap.php resolves the test library version
# from whatever core is actually at WP_CORE_DIR unless WP_VERSION is
# explicitly passed (e.g. `make test-native WP_VERSION=6.9.1`). Without
# this, the blanket default a few lines up would silently override that
# sniffing for every native run, which is exactly the "6.9.1 core vs.
# trunk test suite" class of mismatch this is meant to prevent.
test-native test-plugin-native: WP_VERSION :=

.PHONY: start stop clean restart logs compose setup-tests \
        test test-unit test-integration test-plugin \
        lint lint-fix lint-plugin check-versions pot shell mysql-shell \
        test-native test-plugin-native lint-native \
        start-host-network stop-host-network compose-host-network

# ── Docker Lifecycle ──────────────────────────────────────────

compose:
	php $(MANIFEST) compose > docker/docker-compose.plugins.yml

start: compose
	$(DOCKER_COMPOSE) up -d --build
	@echo ""
	@echo "WordPress is starting at http://localhost:8080"
	@echo "Run 'make setup-tests' once the container is ready."

stop:
	$(DOCKER_COMPOSE) down

clean:
	$(DOCKER_COMPOSE) down -v --remove-orphans
	@echo "Volumes removed."

restart: stop start

logs:
	$(DOCKER_COMPOSE) logs -f wordpress

# ── Docker Lifecycle (host networking) ──────────────────────────
#
# Same as `start`/`stop` above, but with docker/docker-compose.host-network.yml
# layered on top — use when bridge/user-defined Docker networks can't reach
# the DB container or the internet. DB is reachable at 127.0.0.1:3307 (dev)
# / 127.0.0.1:3308 (test) instead of the "db"/"db-test" hostnames.

compose-host-network:
	php $(MANIFEST) compose > docker/docker-compose.plugins.yml

start-host-network: compose-host-network
	$(DOCKER_COMPOSE_HOST_NETWORK) up -d --build
	@echo ""
	@echo "WordPress is starting at http://localhost:8080"
	@echo "Run 'make setup-tests' once the container is ready."

stop-host-network:
	$(DOCKER_COMPOSE_HOST_NETWORK) down

# ── Test Setup ────────────────────────────────────────────────

setup-tests:
	$(CONTAINER) bash -c "cd /var/www/tests && php bootstrap.php 2>&1 | head -5 || true"
	@echo "Test environment ready."

# ── Tests (Docker) ───────────────────────────────────────────

# test (the full suite) runs each plugin's suite in its own `phpunit`
# invocation, looping over `bin/manifest.php slugs` and aggregating the
# exit status (every plugin runs even if an earlier one fails) — see
# "Process isolation" in CLAUDE.md for why: sharing one process across all
# 9 plugins would let e.g. a WooCommerce class stub defined by one plugin's
# tests leak into another plugin's class_exists('WooCommerce') check. Each
# invocation sets MM_TESTS_PLUGIN (read by tests/bootstrap.php to load only
# that plugin) and MM_TESTS_WITH_WC derived from its manifest dependencies,
# mirroring the CI matrix — including a second, WC-active invocation for a
# plugin with a `wc_optional_leg`. test-unit/test-integration intentionally
# keep the old one-process-for-everything behavior (MM_TESTS_PLUGIN unset).
test: compose
	@status=0; \
	for slug in $$(php $(MANIFEST) slugs); do \
		echo "=== test ($$slug) ==="; \
		with_wc=$$(php $(MANIFEST) field $$slug dependencies | grep -qx woocommerce && echo 1 || echo ''); \
		tests_dir=$$(php $(MANIFEST) field $$slug tests_dir); \
		$(CONTAINER) env MM_TESTS_PLUGIN=$$slug MM_TESTS_WITH_WC=$$with_wc \
			phpunit --configuration /var/www/tests/phpunit.xml /var/www/tests/unit/$$tests_dir/ \
			|| status=1; \
		wc_leg=$$(php $(MANIFEST) field $$slug wc_optional_leg); \
		if [ -n "$$wc_leg" ]; then \
			echo "=== test ($$slug, +WC optional leg) ==="; \
			$(CONTAINER) env MM_TESTS_PLUGIN=$$slug MM_TESTS_WITH_WC=1 \
				phpunit --configuration /var/www/tests/phpunit.xml /var/www/tests/unit/$$tests_dir/ \
				|| status=1; \
		fi; \
	done; \
	exit $$status

test-unit: compose
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml --testsuite "Unit Tests"

test-integration: compose
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml --testsuite "Integration Tests"

test-plugin: compose
ifndef PLUGIN
	@echo "Usage: make test-plugin PLUGIN=plugin-slug"
	@echo "Available plugins:"
	@php $(MANIFEST) slugs
	@exit 1
endif
	@echo "=== test ($(PLUGIN)) ==="
	$(CONTAINER) env MM_TESTS_PLUGIN=$(PLUGIN) MM_TESTS_WITH_WC=$${MM_TESTS_WITH_WC:-$(shell php $(MANIFEST) field $(PLUGIN) dependencies | grep -qx woocommerce && echo 1)} \
		phpunit --configuration /var/www/tests/phpunit.xml /var/www/tests/unit/$(shell php $(MANIFEST) field $(PLUGIN) tests_dir)/

# ── Linting (Docker) ──────────────────────────────────────────

lint: compose
	$(CONTAINER) phpcs --standard=/var/www/phpcs.xml --ignore=$(shell php $(MANIFEST) lint-ignore) $(shell php $(MANIFEST) container-plugin-paths)

lint-fix: compose
	$(CONTAINER) phpcbf --standard=/var/www/phpcs.xml --ignore=$(shell php $(MANIFEST) lint-ignore) $(shell php $(MANIFEST) container-plugin-paths)

lint-plugin: compose
ifndef PLUGIN
	@echo "Usage: make lint-plugin PLUGIN=plugin-slug"
	@exit 1
endif
	$(CONTAINER) phpcs --standard=/var/www/phpcs.xml /var/www/html/wp-content/plugins/$(shell php $(MANIFEST) field $(PLUGIN) install_dir)/

# ── Versions ──────────────────────────────────────────────────

check-versions:
	@for slug in $$(php $(MANIFEST) slugs); do \
		php bin/check-versions.php $$slug || exit 1; \
	done

# ── i18n ─────────────────────────────────────────────────────

pot: compose
	@for slug in $$(php $(MANIFEST) slugs); do \
		install_dir=$$(php $(MANIFEST) field $$slug install_dir); \
		domain=$$(php $(MANIFEST) text-domains $$slug | head -1); \
		$(CONTAINER) wp --allow-root i18n make-pot \
			/var/www/html/wp-content/plugins/$$install_dir/ \
			/var/www/html/wp-content/plugins/$$install_dir/languages/$$domain.pot \
			--domain=$$domain; \
	done
	@echo "All .pot files regenerated."

# ── Shell Access ──────────────────────────────────────────────

shell:
	$(DOCKER_COMPOSE) exec wordpress bash

mysql-shell:
	$(DOCKER_COMPOSE) exec db mysql -uwordpress -pwordpress wordpress

# ── Native (no Docker) ─────────────────────────────────────────
#
# Set up the environment first with bin/setup-native.sh, e.g.:
#   bin/setup-native.sh 6.9.1
#   export WP_TESTS_DIR=/tmp/wp-native/tests-lib-6.9.1 WP_CORE_DIR=/tmp/wp-native/core-6.9.1
#   make test-native

# Same per-plugin process isolation as the Docker `test` target above — see
# the comment there.
test-native:
	@status=0; \
	for slug in $$(php $(MANIFEST) slugs); do \
		echo "=== test-native ($$slug) ==="; \
		with_wc=$$(php $(MANIFEST) field $$slug dependencies | grep -qx woocommerce && echo 1 || echo ''); \
		tests_dir=$$(php $(MANIFEST) field $$slug tests_dir); \
		MM_TESTS_PLUGIN=$$slug MM_TESTS_WITH_WC=$$with_wc \
			WP_TESTS_PHPUNIT_POLYFILLS_PATH=$(WP_TESTS_PHPUNIT_POLYFILLS_PATH) $(PHPUNIT_BIN) --configuration tests/phpunit.xml tests/unit/$$tests_dir/ \
			|| status=1; \
		wc_leg=$$(php $(MANIFEST) field $$slug wc_optional_leg); \
		if [ -n "$$wc_leg" ]; then \
			echo "=== test-native ($$slug, +WC optional leg) ==="; \
			MM_TESTS_PLUGIN=$$slug MM_TESTS_WITH_WC=1 \
				WP_TESTS_PHPUNIT_POLYFILLS_PATH=$(WP_TESTS_PHPUNIT_POLYFILLS_PATH) $(PHPUNIT_BIN) --configuration tests/phpunit.xml tests/unit/$$tests_dir/ \
				|| status=1; \
		fi; \
	done; \
	exit $$status

test-plugin-native:
ifndef PLUGIN
	@echo "Usage: make test-plugin-native PLUGIN=plugin-slug"
	@echo "Available plugins:"
	@php $(MANIFEST) slugs
	@exit 1
endif
	@echo "=== test-native ($(PLUGIN)) ==="
	MM_TESTS_PLUGIN=$(PLUGIN) MM_TESTS_WITH_WC=$${MM_TESTS_WITH_WC:-$(shell php $(MANIFEST) field $(PLUGIN) dependencies | grep -qx woocommerce && echo 1)} \
		WP_TESTS_PHPUNIT_POLYFILLS_PATH=$(WP_TESTS_PHPUNIT_POLYFILLS_PATH) $(PHPUNIT_BIN) --configuration tests/phpunit.xml tests/unit/$(shell php $(MANIFEST) field $(PLUGIN) tests_dir)/

lint-native:
	@for slug in $$(php $(MANIFEST) slugs); do \
		dir=$$(php $(MANIFEST) field $$slug dir); \
		echo "=== $$slug ==="; \
		$(PHPCS_BIN) --standard=phpcs.xml --ignore=$$(php $(MANIFEST) lint-ignore) "$$dir" || true; \
	done
