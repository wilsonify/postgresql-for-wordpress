#!/usr/bin/env bash
#
# Acquire the test-only dependencies (WordPress core fixtures and PHPUnit).
# Run from a clean checkout before the test suite; safe to re-run.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
"$SCRIPT_DIR/fetch-wordpress-core.sh"
"$SCRIPT_DIR/fetch-phpunit.sh"
