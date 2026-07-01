#!/usr/bin/env bash
set -e
host="${1:-localhost}"
user="${2:-pg4wp}"
for i in $(seq 1 30); do
  pg_isready -h "$host" -U "$user" && exit 0
  sleep 2
done
echo "FAIL: PostgreSQL did not become ready"
exit 1
