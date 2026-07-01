<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
$dir = dirname(__DIR__, 2) . '/tests/stubs';
require_once dirname(__DIR__, 2) . '/pg4wp/db.php';

$files = glob($dir . '/*.txt');
foreach ($files as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['mysql'])) {
        echo "SKIP " . basename($file) . " (invalid)\n";
        continue;
    }
    $rewritten = pg4wp_rewrite($data['mysql']);
    if ($rewritten === null) {
        echo "WARN " . basename($file) . " (null result, keeping)\n";
        continue;
    }
    $data['postgresql'] = $rewritten;
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        echo "FAIL " . basename($file) . " (json_encode error)\n";
        continue;
    }
    file_put_contents($file, $json . "\n");
    echo "OK " . basename($file) . "\n";
}
echo "ALL DONE\n";
