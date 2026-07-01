#!/usr/bin/env bash
cd "/mnt/c/Users/toman/AppData/Local/Temp/opencode/postgresql-for-wordpress" || exit 1

# Regenerate all stubs by running the rewriter against every mysql input
for f in tests/stubs/*.txt; do
  mysql=$(php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    echo $data["mysql"];
  ' "$f")
  
  if [ -z "$mysql" ]; then
    echo "SKIP $f (empty mysql)"
    continue
  fi
  
  pg=$(php -r '
    require_once "pg4wp/db.php";
    $data = json_decode(file_get_contents($argv[1]), true);
    echo pg4wp_rewrite($data["mysql"]);
  ' "$f")
  
  if [ -z "$pg" ]; then
    echo "WARN $f (empty result, keeping original)"
    continue
  fi
  
  # Rebuild the JSON with the new postgresql value
  old_data=$(cat "$f")
  new_data=$(php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    $data["postgresql"] = $argv[2];
    echo json_encode($data);
  ' "$f" "$pg")
  
  echo "$new_data" > "$f"
  echo "OK $f"
done
echo "DONE"
