#!/usr/bin/env bash
#
# Fetch the pinned PHPUnit phar used by the test suite and CI.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=versions.sh
. "$SCRIPT_DIR/versions.sh"

DEST="$ROOT/tests/tools/phpunit.phar"
CACHE="$ROOT/deps/cache"
PHAR="$CACHE/phpunit-${PHPUNIT_VERSION}.phar"
URL="https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar"

die() {
  printf 'fetch-phpunit: %s\n' "$*" >&2
  exit 1
}

for tool in curl sha256sum; do
  command -v "$tool" >/dev/null 2>&1 || die "$tool is required"
done

mkdir -p "$CACHE" "$(dirname "$DEST")"

if [ ! -f "$PHAR" ] || ! printf '%s  %s\n' "$PHPUNIT_SHA256" "$PHAR" | sha256sum -c - >/dev/null 2>&1; then
  rm -f "$PHAR"
  printf 'Downloading PHPUnit %s\n' "$PHPUNIT_VERSION"
  curl -fL --retry 3 --retry-delay 2 -o "$PHAR.tmp" "$URL"
  mv "$PHAR.tmp" "$PHAR"
fi

printf '%s  %s\n' "$PHPUNIT_SHA256" "$PHAR" | sha256sum -c -
cp "$PHAR" "$DEST.tmp"
chmod +x "$DEST.tmp"
mv "$DEST.tmp" "$DEST"

printf 'PHPUnit %s ready at %s\n' "$PHPUNIT_VERSION" "$DEST"
