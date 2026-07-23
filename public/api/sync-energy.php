<?php
/**
 * API Endpoint for Manual Energy Integration Sync
 *
 * Lets Admin/Staff trigger the Energy push/pull sync via the Energy Efficiency
 * page or System Settings "Sync Now" button. Pushes pending manual meter
 * readings and pulls engineer-approved recommendations (see
 * config/energy_helper.php).
 *
 * Authentication: Admin/Staff session required
 * Methods: POST
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/energy_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isStaff = ($_SESSION['user_authenticated'] ?? false)
    && in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);

if (!$isStaff) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin or Staff login required.']);
    exit;
}

try {
    if (!energy_api_enabled()) {
        echo json_encode(['success' => false, 'message' => 'Energy sync is disabled (ENERGY_SYNC_ENABLED=false).']);
        exit;
    }

    $pdo = db();
    if (!frs_energy_tables_exist($pdo)) {
        echo json_encode(['success' => false, 'message' => 'Energy integration tables missing. Run database/migration_add_energy_integration.sql.']);
        exit;
    }
    $summary = frs_energy_run_sync($pdo);

    echo json_encode([
        'success' => $summary['success'],
        'message' => $summary['success'] ? 'Energy sync completed.' : 'Energy sync completed with errors.',
        'pushed' => $summary['pushed'],
        'push_failed' => $summary['push_failed'],
        'recommendations_upserted' => $summary['recommendations_upserted'],
        'errors' => $summary['errors'],
        'ran_at' => $summary['ran_at'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Energy sync crashed.', 'error' => $e->getMessage()]);
}
