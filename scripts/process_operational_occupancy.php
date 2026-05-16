<?php
/**
 * Operational occupancy cron: flag no-shows after grace period without Time In.
 *
 * Run every 5–10 minutes, e.g.:
 *   php scripts/process_operational_occupancy.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/occupancy_monitoring.php';

$pdo = db();
$now = new DateTime('now', frs_app_timezone());

echo "=== Operational occupancy (no-show processing) ===\n";
echo 'Now: ' . $now->format('Y-m-d H:i:s') . "\n\n";

$result = frs_process_operational_no_shows($pdo, $now);

echo 'Flagged: ' . (int)$result['flagged'] . "\n";
echo 'Skipped: ' . (int)$result['skipped'] . "\n";
echo "\nDone.\n";
