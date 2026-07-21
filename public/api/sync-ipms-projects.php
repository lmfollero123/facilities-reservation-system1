<?php
/**
 * API Endpoint for Manual IPMS Infrastructure Project Sync
 *
 * This endpoint allows Admin/Staff users to manually trigger an IPMS facility-status
 * sync via the Infrastructure Projects page "Sync Now" button. It only ever GETs from
 * IPMS (see services/ipms_api.php) — this endpoint itself does not accept or forward
 * any data to IPMS.
 *
 * Authentication: Admin/Staff session required
 * Methods: POST
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/ipms_api.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isStaff = ($_SESSION['user_authenticated'] ?? false)
    && in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);

if (!$isStaff) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Admin or Staff login required.'
    ]);
    exit;
}

try {
    $pdo = db();
    $result = frs_ipms_run_sync($pdo);

    if (!$result['success']) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch IPMS facility-status feed.',
            'error' => $result['error'] ?? 'Unknown error',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'IPMS project sync completed.',
        'active_count' => $result['active_count'],
        'upcoming_count' => $result['upcoming_count'],
        'matched' => $result['matched'],
        'summary' => $result['summary'],
        'ran_at' => $result['ran_at'],
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'IPMS project sync crashed.',
        'error' => $e->getMessage(),
    ]);
}
