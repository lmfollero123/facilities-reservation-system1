<?php
/**
 * JSON API for operational occupancy snapshot (all authenticated users).
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';

$role = $_SESSION['role'] ?? '';
$isStaff = in_array($role, ['Admin', 'Staff'], true);

try {
    $snapshot = frs_build_operational_occupancy_snapshot(db());
    if (!$isStaff) {
        $snapshot = frs_sanitize_occupancy_snapshot_for_public($snapshot);
    }
    echo json_encode(['success' => true, 'snapshot' => $snapshot], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('occupancy_live_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load occupancy data']);
}
