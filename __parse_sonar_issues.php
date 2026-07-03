<?php
$data = json_decode(file_get_contents('php://stdin'), true);
foreach ($data['issues'] as $issue) {
    $comp = $issue['component'];
    if (strpos($comp, 'wp-includes/') !== false || strpos($comp, 'wp-admin/') !== false) continue;
    echo $issue['rule'] . ' | ' . $comp . ':' . $issue['line'] . ' | ' . $issue['message'] . "\n";
}
echo "\n---\nTotal HIGH issues in PG4WP files: " . count(array_filter($data['issues'], function($i) {
    $c = $i['component'];
    return strpos($c, 'wp-includes/') === false && strpos($c, 'wp-admin/') === false;
})) . "\n";
