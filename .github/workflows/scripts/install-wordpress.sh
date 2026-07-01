#!/usr/bin/env bash
set -e
WP_VERSION="$1"
[ "$WP_VERSION" = "latest" ] && WP_VERSION=""
wp core download --version="$WP_VERSION" --path=/tmp/wordpress --allow-root
cp -r "$GITHUB_WORKSPACE" /tmp/wordpress/wp-content/plugins/pg4wp
cd /tmp/wordpress
wp config create \
  --dbname=wordpress \
  --dbuser=pg4wp \
  --dbpass=pg4wp \
  --dbhost=localhost \
  --allow-root
echo "define('WP_DEBUG', true);" >> wp-config.php
echo "define('WP_DEBUG_LOG', true);" >> wp-config.php
cp wp-content/plugins/pg4wp/db.php wp-content/db.php
mkdir -p wp-content/pg4wp/logs
wp core install \
  --url=http://localhost:8080 \
  --title="PG4WP Test" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=test@example.com \
  --allow-root 2>&1 || true
echo "--- PG4WP Error Log ---"
cat wp-content/pg4wp/logs/pg4wp_errors.log 2>/dev/null || echo "No PG4WP error log found"
echo "--- PHP Error Log ---"
tail -50 wp-content/debug.log 2>/dev/null || echo "No debug log found"
