# PHP_VERSION / WP_VERSION default to plugins.json defaults.php_runtime /
# defaults.wp_latest_tested — override with --build-arg or the Makefile's
# PHP_VERSION / WP_VERSION env vars (see `make compose`, `make start`).
#
# WP_VERSION only pins the base image tag (which may float to a newer patch
# release, e.g. "7.1" resolving to 7.1.3). It is intentionally NOT exported
# as a runtime WP_VERSION env var — tests/bootstrap.php instead sniffs the
# exact installed core version from wp-includes/version.php at test time and
# checks out the develop.svn test library for that exact version, so core
# and tests can never drift apart regardless of which patch the image tag
# resolved to.
ARG WP_VERSION=7.1
ARG PHP_VERSION=8.2

FROM wordpress:${WP_VERSION}-php${PHP_VERSION}

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    subversion \
    default-mysql-client \
    less \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install WP-CLI (pinned)
ARG WP_CLI_VERSION=2.11.0
RUN curl -L -o /usr/local/bin/wp "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
    && chmod +x /usr/local/bin/wp

# Install PHPUnit (pinned to the 9.6 line used by yoast/phpunit-polyfills 2.0.5)
RUN curl -L -o /usr/local/bin/phpunit https://phar.phpunit.de/phpunit-9.6.phar \
    && chmod +x /usr/local/bin/phpunit

# Install PHPCS, WordPress Coding Standards, and PHPUnit Polyfills (pinned exact versions)
RUN composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer global require --dev \
    "squizlabs/php_codesniffer:3.13.6" \
    "wp-coding-standards/wpcs:3.4.1" \
    "phpcompatibility/phpcompatibility-wp:2.1.8" \
    "dealerdirect/phpcodesniffer-composer-installer:1.2.1" \
    "yoast/phpunit-polyfills:2.0.5" \
    && ln -s /root/.composer/vendor/bin/phpcs /usr/local/bin/phpcs \
    && ln -s /root/.composer/vendor/bin/phpcbf /usr/local/bin/phpcbf

# Create tests directory
RUN mkdir -p /var/www/tests

# Custom entrypoint to install WooCommerce and set up WP
COPY docker-entrypoint-custom.sh /usr/local/bin/docker-entrypoint-custom.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-custom.sh

ENTRYPOINT ["docker-entrypoint-custom.sh"]
CMD ["apache2-foreground"]
