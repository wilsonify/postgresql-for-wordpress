<?php
$data = json_decode(file_get_contents('/tmp/sonar_output.json'), true);
$count = 0;
foreach ($data['issues'] as $issue) {
    $comp = $issue['component'];
    if (strpos($comp, 'wp-includes/') !== false || strpos($comp, 'wp-admin/') !== false) continue;
    $count++;
    echo $issue['rule'] . ' | ' . $comp . ':' . $issue['line'] . ' | ' . $issue['message'] . "\n";
}
echo "\nTotal: $count HIGH issues in PG4WP files\n";
