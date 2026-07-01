#!/usr/bin/env bash
set -e
host="${1:-localhost}"
user="${2:-pg4wp}"
if ! command -v pg_isready &>/dev/null; then
  apt-get update -qq && apt-get install -y -qq postgresql-client
fi
for i in $(seq 1 30); do
  pg_isready -h "$host" -U "$user" && exit 0
  sleep 2
done
echo "FAIL: PostgreSQL did not become ready"
exit 1
