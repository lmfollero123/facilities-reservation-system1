<?php
/**
 * API Endpoint for Manual CIMM Maintenance Sync
 * 
 * This endpoint allows Admin/Staff users to manually trigger CIMM maintenance sync
 * via the Maintenance Integration page "Sync Now" button.
 * 
 * Authentication: Admin/Staff session required
 * Methods: POST
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// Load dependencies
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/cimm_api.php';

// Session check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin/Staff authentication check
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
    $result = frs_cimm_run_sync($pdo);

    if (!$result['success']) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch CIMM schedules.',
            'error' => $result['error'] ?? 'Unknown error',
            'fetched' => 0,
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'CIMM maintenance sync completed.',
        'fetched' => $result['fetched'],
        'mapped' => $result['mapped'],
        'summary' => $result['summary'],
        'ran_at' => $result['ran_at'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'CIMM maintenance sync crashed.',
        'error' => $e->getMessage(),
    ]);
}
