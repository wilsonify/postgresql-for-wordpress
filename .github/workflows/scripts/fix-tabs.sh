#!/usr/bin/env bash
cd "/mnt/c/Users/toman/AppData/Local/Temp/opencode/postgresql-for-wordpress" || exit 1
# Convert tabs to 4 spaces in pg4wp/ and tests/ PHP files
for f in $(find pg4wp/ tests/ -name "*.php" -type f); do
  if grep -q $'\t' "$f"; then
    echo "Fixing tabs: $f"
    sed -i 's/\t/    /g' "$f"
  fi
done
echo "Done"
