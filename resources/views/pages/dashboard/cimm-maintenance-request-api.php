<?php
/**
 * AJAX: submit predictive maintenance request to CIMM.
 */
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/predictive_maintenance.php';

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? 'Resident';
if (!frs_can_create($role, 'maintenance') && !frs_can_update($role, 'maintenance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to submit maintenance requests.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

frs_reject_invalid_csrf_json();

$userId = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

$result = frs_submit_maintenance_request($pdo, $_POST, $userId);

if (!empty($result['success'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Maintenance request sent to CIMM for review.',
        'request_id' => $result['request_id'] ?? null,
        'cimm_reference' => $result['cimm_reference'] ?? null,
    ]);
    exit;
}

http_response_code(422);
echo json_encode([
    'success' => false,
    'error' => $result['error'] ?? 'Unable to submit maintenance request.',
    'request_id' => $result['request_id'] ?? null,
]);
