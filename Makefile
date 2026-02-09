# WordPress Plugin Testing Infrastructure
# ========================================
#
# Usage:
#   make start          - Start Docker environment
#   make stop           - Stop Docker environment
#   make clean          - Stop and remove volumes
#   make test           - Run all tests
#   make test-unit      - Run unit tests only
#   make test-integration - Run integration tests only
#   make test-plugin PLUGIN=name - Run tests for a single plugin
#   make lint           - Run PHPCS on all plugins
#   make lint-fix       - Auto-fix PHPCS issues
#   make lint-plugin PLUGIN=name - Lint a single plugin
#   make pot            - Regenerate .pot translation templates
#   make shell          - Open shell in WordPress container
#   make mysql-shell    - Open MySQL shell

DOCKER_COMPOSE = docker compose -f docker/docker-compose.yml
CONTAINER = $(DOCKER_COMPOSE) exec -T wordpress
PLUGIN ?=

.PHONY: start stop clean restart test test-unit test-integration test-plugin \
        lint lint-fix lint-plugin pot shell mysql-shell setup-tests logs

# ── Docker Lifecycle ──────────────────────────────────────────

start:
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

# ── Tests ─────────────────────────────────────────────────────

test:
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml

test-unit:
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml --testsuite "Unit Tests"

test-integration:
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml --testsuite "Integration Tests"

test-plugin:
ifndef PLUGIN
	@echo "Usage: make test-plugin PLUGIN=plugin-name"
	@echo "Available plugins:"
	@echo "  cloudflare-ip-blacklist"
	@echo "  edit-usernames"
	@echo "  expire-passwords"
	@echo "  protect-the-children"
	@echo "  send-emails-with-mandrill"
	@echo "  wooflare"
	@exit 1
endif
	$(CONTAINER) phpunit --configuration /var/www/tests/phpunit.xml /var/www/tests/unit/$(PLUGIN)/

# ── Linting ───────────────────────────────────────────────────

PLUGIN_DIRS = /var/www/html/wp-content/plugins/ip-blacklist-for-cloudflare \
              /var/www/html/wp-content/plugins/edit-usernames \
              /var/www/html/wp-content/plugins/expire-user-passwords \
              /var/www/html/wp-content/plugins/protect-the-children \
              /var/www/html/wp-content/plugins/send-emails-with-mandrill \
              /var/www/html/wp-content/plugins/wooflare

lint:
	$(CONTAINER) phpcs --standard=/var/www/phpcs.xml $(PLUGIN_DIRS)

lint-fix:
	$(CONTAINER) phpcbf --standard=/var/www/phpcs.xml $(PLUGIN_DIRS)

lint-plugin:
ifndef PLUGIN
	@echo "Usage: make lint-plugin PLUGIN=plugin-name"
	@exit 1
endif
	$(CONTAINER) phpcs --standard=/var/www/phpcs.xml /var/www/html/wp-content/plugins/$(PLUGIN)/

# ── i18n ─────────────────────────────────────────────────────

pot:
	$(CONTAINER) wp --allow-root i18n make-pot /var/www/html/wp-content/plugins/ip-blacklist-for-cloudflare/ /var/www/html/wp-content/plugins/ip-blacklist-for-cloudflare/languages/ip-blacklist-cloudflare.pot --domain=ip-blacklist-cloudflare
	$(CONTAINER) wp --allow-root i18n make-pot /var/www/html/wp-content/plugins/edit-usernames/ /var/www/html/wp-content/plugins/edit-usernames/languages/edit-usernames.pot --domain=edit-usernames
	$(CONTAINER) wp --allow-root i18n make-pot /var/www/html/wp-content/plugins/expire-user-passwords/ /var/www/html/wp-content/plugins/expire-user-passwords/languages/expire-user-passwords.pot --domain=expire-user-passwords
	$(CONTAINER) wp --allow-root i18n make-pot /var/www/html/wp-content/plugins/protect-the-children/ /var/www/html/wp-content/plugins/protect-the-children/languages/protect-the-children.pot --domain=protect-the-children
	$(CONTAINER) wp --allow-root i18n make-pot /var/www/html/wp-content/plugins/send-emails-with-mandrill/ /var/www/html/wp-content/plugins/send-emails-with-mandrill/languages/send-emails-with-mandrill.pot --domain=send-emails-with-mandrill
	$(CONTAINER) wp --allow-root i18n make-pot /var/www/html/wp-content/plugins/wooflare/ /var/www/html/wp-content/plugins/wooflare/languages/wooflare.pot --domain=wooflare
	@echo "All .pot files regenerated."

# ── Shell Access ──────────────────────────────────────────────

shell:
	$(DOCKER_COMPOSE) exec wordpress bash

mysql-shell:
	$(DOCKER_COMPOSE) exec db mysql -uwordpress -pwordpress wordpress
