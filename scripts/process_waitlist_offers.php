<?php
/**
 * Expires stale waitlist offers past their claim window and rolls each freed
 * slot to the next waitlisted entry.
 *
 * Usage: php scripts/process_waitlist_offers.php
 * Cron (hourly): 0 * * * * cd /path && php scripts/process_waitlist_offers.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/waitlist_helpers.php';

if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
        die('Access denied. Admin/Staff only.');
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre>';
}

echo "Processing expired waitlist offers...\n\n";

$pdo = db();
$count = frs_waitlist_expire_stale_offers($pdo);

echo "Done! Expired {$count} stale waitlist offer(s).\n";

if (php_sapi_name() !== 'cli') {
    echo '</pre>';
    echo '<p><a href="' . base_path() . '/dashboard/book-facility">Go to Book Facility</a></p>';
}
