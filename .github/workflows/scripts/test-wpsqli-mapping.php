<?php
$root = getenv('GITHUB_WORKSPACE');
if (!$root) { echo "FAIL: GITHUB_WORKSPACE not set\n"; exit(1); }
define('PG4WP_ROOT', $root . '/pg4wp');
define('PG4WP_DEBUG', false);
define('PG4WP_LOG_ERRORS', true);
define('PG4WP_LOG', PG4WP_ROOT . '/logs/');
require_once PG4WP_ROOT . '/driver_pgsql.php';
require_once PG4WP_ROOT . '/driver_pgsql_rewrite.php';

$conn = @pg_connect(getenv('PG4WP_TEST_DSN'));
if (!$conn) { echo "FAIL: connection\n"; exit(1); }

$r = wpsqli_query($conn, 'SELECT 1 AS test');
if ($r === false) { echo "FAIL: wpsqli_query\n"; exit(1); }

$row = wpsqli_fetch_assoc($r);
if ($row['test'] !== '1') { echo "FAIL: fetch_assoc\n"; exit(1); }

echo "OK: wpsqli_query + fetch_assoc\n";
wpsqli_free_result($r);
pg_close($conn);
echo "ALL PG INTEGRATION TESTS PASSED\n";
