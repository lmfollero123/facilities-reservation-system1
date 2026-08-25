<?php
/**
 * AJAX: on-demand AI (Gemini) explanation of a facility's maintenance
 * pressure score. Generated per click, not eagerly for every row, to avoid
 * firing an AI request per facility on every page load.
 */
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/predictive_maintenance.php';
require_once __DIR__ . '/../../../../config/gemini_predictive_maintenance.php';

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? 'Resident';
if (!frs_can_read($role, 'maintenance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to view this.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

frs_reject_invalid_csrf_json();

$facilityId = (int)($_POST['facility_id'] ?? 0);
if ($facilityId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid facility.']);
    exit;
}

$pdo = db();
$rows = frs_compute_predictive_maintenance_rows($pdo);
$context = null;
foreach ($rows as $row) {
    if ((int)($row['facility_id'] ?? 0) === $facilityId) {
        $context = $row;
        break;
    }
}

if ($context === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Facility not found.']);
    exit;
}

$explanation = geminiExplainMaintenancePressure($context);
$source = 'ai';
if ($explanation === null) {
    $explanation = frs_fallback_maintenance_pressure_explanation($context);
    $source = 'fallback';
}

echo json_encode([
    'success' => true,
    'explanation' => $explanation,
    'source' => $source,
]);
