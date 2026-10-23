#!/bin/bash
set -e

# Note: this script only installs WordPress core (via the stock entrypoint)
# and activates plugins — it never touches the PHPUnit test library. That's
# resolved separately by tests/bootstrap.php to match whatever core version
# ends up installed here (see the Dockerfile's WP_VERSION comment).

# Run the default WordPress entrypoint first
docker-entrypoint.sh apache2-foreground &
WP_PID=$!

# Wait for WordPress to be ready
echo "Waiting for WordPress to be ready..."
until wp core is-installed --path=/var/www/html --allow-root 2>/dev/null; do
    # Check if WordPress files exist but isn't installed yet
    if [ -f /var/www/html/wp-config.php ]; then
        # Try to install WordPress
        wp core install \
            --url="http://localhost:8080" \
            --title="WordPress Plugin Testing" \
            --admin_user=admin \
            --admin_password=admin \
            --admin_email=admin@example.com \
            --path=/var/www/html \
            --allow-root 2>/dev/null && break
    fi
    sleep 2
done

echo "WordPress is ready."

# Install and activate WooCommerce
if ! wp plugin is-installed woocommerce --path=/var/www/html --allow-root 2>/dev/null; then
    echo "Installing WooCommerce..."
    wp plugin install woocommerce --activate --path=/var/www/html --allow-root
else
    echo "Activating WooCommerce..."
    wp plugin activate woocommerce --path=/var/www/html --allow-root 2>/dev/null || true
fi

# Activate every plugin in the manifest (no hardcoded list — see plugins.json)
echo "Activating custom plugins..."
for install_dir in $(php /var/www/bin/manifest.php install-dirs); do
    wp plugin activate "$install_dir" --path=/var/www/html --allow-root 2>/dev/null || true
done

echo "All plugins activated."

# Wait for the Apache process
wait $WP_PID
