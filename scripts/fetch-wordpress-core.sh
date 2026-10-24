#!/usr/bin/env bash
#
# Fetch the WordPress core files required by pg4wp's test bootstrap.
#
# Only five wp-includes files are needed; the full WordPress tree is not
# committed. The tarball is checked against a pinned SHA-256 and each extracted
# file is checked against WordPress.org's published MD5 checksum.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=versions.sh
. "$SCRIPT_DIR/versions.sh"

DEST="$ROOT/wp-includes"
CACHE="$ROOT/deps/cache"
TARBALL="$CACHE/wordpress-${WORDPRESS_VERSION}.tar.gz"
URL="https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz"

die() {
  printf 'fetch-wordpress-core: %s\n' "$*" >&2
  exit 1
}

for tool in curl tar sha256sum md5sum; do
  command -v "$tool" >/dev/null 2>&1 || die "$tool is required"
done

mkdir -p "$CACHE"

if [ ! -f "$TARBALL" ] || ! printf '%s  %s\n' "$WORDPRESS_TARBALL_SHA256" "$TARBALL" | sha256sum -c - >/dev/null 2>&1; then
  rm -f "$TARBALL"
  printf 'Downloading WordPress %s\n' "$WORDPRESS_VERSION"
  curl -fL --retry 3 --retry-delay 2 -o "$TARBALL.tmp" "$URL"
  mv "$TARBALL.tmp" "$TARBALL"
fi

printf '%s  %s\n' "$WORDPRESS_TARBALL_SHA256" "$TARBALL" | sha256sum -c -

TMP="$(mktemp -d "${TMPDIR:-/tmp}/wordpress.XXXXXX")"
cleanup() {
  rm -rf "$TMP"
}
trap cleanup EXIT

FILES="version.php cache.php class-wp-object-cache.php class-wpdb.php l10n.php"
for file in $FILES; do
  tar -xzf "$TARBALL" -C "$TMP" "wordpress/wp-includes/$file"
done

mkdir -p "$DEST"
for file in $FILES; do
  cp "$TMP/wordpress/wp-includes/$file" "$DEST/$file"
done

# Published checksums for WordPress 6.4.1 (api.wordpress.org core checksums).
(
  cd "$DEST"
  md5sum -c - <<'EOF'
2d64ed1a8838649b9272dc2ea9ec95a1  version.php
27adab7ef39103c4a9ba42eb3cf90658  cache.php
ff42cddb49b7d1aaf20c8dbc8075c6f7  class-wp-object-cache.php
fd5d30769744d0d7a7eeeb499aed6af3  class-wpdb.php
ff3523c91d43542a272b59b102b6517a  l10n.php
EOF
)

printf 'WordPress %s test fixtures ready at %s\n' "$WORDPRESS_VERSION" "$DEST"
