#!/bin/bash
set -e

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

# Activate all custom plugins
echo "Activating custom plugins..."
wp plugin activate ip-blacklist-for-cloudflare --path=/var/www/html --allow-root 2>/dev/null || true
wp plugin activate edit-usernames --path=/var/www/html --allow-root 2>/dev/null || true
wp plugin activate expire-user-passwords --path=/var/www/html --allow-root 2>/dev/null || true
wp plugin activate protect-the-children --path=/var/www/html --allow-root 2>/dev/null || true
wp plugin activate send-emails-with-mandrill --path=/var/www/html --allow-root 2>/dev/null || true
wp plugin activate wooflare --path=/var/www/html --allow-root 2>/dev/null || true

echo "All plugins activated."

# Wait for the Apache process
wait $WP_PID
