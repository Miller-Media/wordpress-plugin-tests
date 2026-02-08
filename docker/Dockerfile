FROM wordpress:6.9.1-php8.2

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

# Install WP-CLI
RUN curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Install PHPUnit 9
RUN curl -L -o /usr/local/bin/phpunit https://phar.phpunit.de/phpunit-9.phar \
    && chmod +x /usr/local/bin/phpunit

# Install PHPCS, WordPress Coding Standards, and PHPUnit Polyfills
RUN composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer global require --dev \
    "squizlabs/php_codesniffer=*" \
    "wp-coding-standards/wpcs=*" \
    "phpcompatibility/phpcompatibility-wp=*" \
    "dealerdirect/phpcodesniffer-composer-installer=*" \
    "yoast/phpunit-polyfills=*" \
    && ln -s /root/.composer/vendor/bin/phpcs /usr/local/bin/phpcs \
    && ln -s /root/.composer/vendor/bin/phpcbf /usr/local/bin/phpcbf

# Create tests directory
RUN mkdir -p /var/www/tests

# Custom entrypoint to install WooCommerce and set up WP
COPY docker-entrypoint-custom.sh /usr/local/bin/docker-entrypoint-custom.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-custom.sh

ENTRYPOINT ["docker-entrypoint-custom.sh"]
CMD ["apache2-foreground"]
