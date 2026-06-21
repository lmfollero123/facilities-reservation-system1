<?php
/**
 * Legacy reservation QR link — redirects to Check In/Out page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';

$token = trim((string)($_GET['token'] ?? ''));

if (!($_SESSION['user_authenticated'] ?? false)) {
    $return = base_path() . '/dashboard/check-in' . ($token !== '' ? '?token=' . urlencode($token) : '');
    header('Location: ' . base_path() . '/login?next=' . urlencode($return));
    exit;
}

if ($token === '') {
    header('Location: ' . base_path() . '/dashboard/time-tracking');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$reservationId = frs_reservation_id_from_checkin_token(db(), $token, $userId);

if (!$reservationId) {
    $_SESSION['time_tracking_flash_error'] = 'Invalid or expired check-in link.';
    header('Location: ' . base_path() . '/dashboard/time-tracking');
    exit;
}

header('Location: ' . base_path() . '/dashboard/time-tracking?reservation_id=' . $reservationId);
exit;
