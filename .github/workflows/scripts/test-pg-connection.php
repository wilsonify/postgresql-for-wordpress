<?php
$conn = @pg_connect(getenv('PG4WP_TEST_DSN'));
if (!$conn) { echo "FAIL: PG connection\n"; exit(1); }
echo "OK: PG " . pg_version($conn)['server'] . "\n";
pg_close($conn);
