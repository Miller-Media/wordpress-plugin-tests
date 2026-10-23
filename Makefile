# WordPress Plugin Testing Infrastructure
# ========================================
#
# Driven by plugins.json via bin/manifest.php — there are no hardcoded
# plugin lists here. Add a plugin by adding one entry to plugins.json.
#
# Docker usage:
#   make start               - Start Docker environment (regenerates docker/docker-compose.plugins.yml)
#   make stop                - Stop Docker environment
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

.PHONY: start stop clean restart logs compose setup-tests \
        test test-unit test-integration test-plugin \
        lint lint-fix lint-plugin check-versions pot shell mysql-shell \
        test-native test-plugin-native lint-native

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

# ── Test Setup ────────────────────────────────────────────────

setup-tests:
	$(CONTAINER) bash -c "cd /var/www/tests && php bootstrap.php 2>&1 | head -5 || true"
	@echo "Test environment ready."

# ── Tests (Docker) ───────────────────────────────────────────

test: compose
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml

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
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml /var/www/tests/unit/$(shell php $(MANIFEST) field $(PLUGIN) tests_dir)/

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

test-native:
	WP_TESTS_PHPUNIT_POLYFILLS_PATH=$(WP_TESTS_PHPUNIT_POLYFILLS_PATH) $(PHPUNIT_BIN) --configuration tests/phpunit.xml

test-plugin-native:
ifndef PLUGIN
	@echo "Usage: make test-plugin-native PLUGIN=plugin-slug"
	@echo "Available plugins:"
	@php $(MANIFEST) slugs
	@exit 1
endif
	WP_TESTS_PHPUNIT_POLYFILLS_PATH=$(WP_TESTS_PHPUNIT_POLYFILLS_PATH) $(PHPUNIT_BIN) --configuration tests/phpunit.xml tests/unit/$(shell php $(MANIFEST) field $(PLUGIN) tests_dir)/

lint-native:
	@for slug in $$(php $(MANIFEST) slugs); do \
		dir=$$(php $(MANIFEST) field $$slug dir); \
		echo "=== $$slug ==="; \
		$(PHPCS_BIN) --standard=phpcs.xml --ignore=$$(php $(MANIFEST) lint-ignore) "$$dir" || true; \
	done
