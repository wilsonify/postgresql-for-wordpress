<?php
define('ABSPATH', __DIR__ . '/');
define('WPINC', 'wp-includes');
require_once __DIR__ . '/pg4wp/db.php';

$wpdb = new stdClass();
$wpdb->prefix = 'wp_';
$wpdb->posts = 'wp_posts';
$wpdb->comments = 'wp_comments';
$wpdb->terms = 'wp_terms';
$wpdb->term_relationships = 'wp_term_relationships';

$files = glob('tests/stubs/select-cou_*.txt');
$count = 0;
foreach ($files as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['mysql'])) continue;
    $rewritten = pg4wp_rewrite($data['mysql']);
    if ($rewritten !== null && $rewritten !== $data['postgresql']) {
        $data['postgresql'] = $rewritten;
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
        echo "UPDATED: " . basename($file) . "\n";
        $count++;
    }
}
echo "DONE: $count stubs updated\n";
