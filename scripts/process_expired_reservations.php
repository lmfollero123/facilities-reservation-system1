<?php
/**
 * One-time script to process expired reservations
 * This can be run manually via command line or browser to immediately process all expired reservations
 * 
 * Usage: php scripts/process_expired_reservations.php
 * Or visit: http://your-domain/scripts/process_expired_reservations.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/reservation_helpers.php';

// Allow running from command line or browser
if (php_sapi_name() !== 'cli') {
    // Browser mode - require authentication
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
        die('Access denied. Admin/Staff only.');
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre>';
}

echo "Processing expired reservations...\n\n";

$count = autoDeclineExpiredReservations();

echo "Done! Auto-declined {$count} expired reservation(s).\n";

if (php_sapi_name() !== 'cli') {
    echo '</pre>';
    echo '<p><a href="' . base_path() . '/resources/views/pages/dashboard/reservations_manage.php">Go to Reservations Management</a></p>';
}
