#!/usr/bin/env bash
set -e
WC_VERSION="$1"
cd /tmp/wordpress
[ "$WC_VERSION" = "latest" ] && WC_VERSION=""
if wp core is-installed --allow-root 2>/dev/null; then
  if [ -n "$WC_VERSION" ]; then
    wp plugin install woocommerce --version="$WC_VERSION" --activate --allow-root
  else
    wp plugin install woocommerce --activate --allow-root
  fi
  wp plugin list --allow-root
else
  echo "WordPress not installed — skipping WooCommerce"
fi
