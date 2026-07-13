<?php
declare(strict_types=1);

/**
 * Inbound LGU integration API (webhooks + read-only status).
 * Authenticate with header: X-API-Key: {INTEGRATIONS_INBOUND_KEY}
 */
require_once __DIR__ . '/../../../../../config/app.php';
require_once __DIR__ . '/../../../../../config/database.php';
require_once __DIR__ . '/../../../../../config/blackout_dates.php';
require_once __DIR__ . '/../../../../../config/audit.php';

header('Content-Type: application/json; charset=utf-8');

function frs_integrations_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function frs_integrations_auth_ok(): bool
{
    $expected = trim((string)(function_exists('env_value') ? env_value('INTEGRATIONS_INBOUND_KEY', '') : ''));
    if ($expected === '') {
        $expected = trim((string)(function_exists('env_value') ? env_value('FACILITIES_API_KEY', '') : ''));
    }
    if ($expected === '') {
        return false;
    }
    $provided = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($provided === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($auth, 'Bearer ') === 0) {
            $provided = trim(substr($auth, 7));
        }
    }
    return $provided !== '' && hash_equals($expected, $provided);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
$path = preg_replace('#^.*/api/integrations/?#', '', $path);
$path = trim((string)$path, '/');

if ($path === '' && $method === 'GET') {
    frs_integrations_json(200, [
        'success' => true,
        'service' => 'CPRF Integrations API',
        'version' => '1.0',
        'routes' => [
            'GET facilities/status',
            'GET reservations/analytics',
            'POST maintenance/schedule',
            'POST projects/timeline',
            'POST utilities/outage',
        ],
        'auth' => 'X-API-Key or Authorization: Bearer',
    ]);
}

if (!frs_integrations_auth_ok()) {
    frs_integrations_json(401, [
        'success' => false,
        'error' => 'unauthorized',
        'message' => 'Set INTEGRATIONS_INBOUND_KEY in .env and send X-API-Key header.',
    ]);
}

$pdo = db();
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        frs_integrations_json(400, ['success' => false, 'error' => 'invalid_json']);
    }
    $body = $decoded;
}

if ($path === 'facilities/status' && $method === 'GET') {
    $rows = $pdo->query(
        'SELECT id, name, status, capacity, updated_at FROM facilities ORDER BY name'
    )->fetchAll(PDO::FETCH_ASSOC);
    frs_integrations_json(200, ['success' => true, 'facilities' => $rows]);
}

if ($path === 'reservations/analytics' && $method === 'GET') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS cnt FROM reservations
         WHERE reservation_date BETWEEN :f AND :t GROUP BY status'
    );
    $stmt->execute(['f' => $from, 't' => $to]);
    frs_integrations_json(200, ['success' => true, 'from' => $from, 'to' => $to, 'by_status' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

$facilityId = (int)($body['facility_id'] ?? 0);
$date = trim((string)($body['date'] ?? ''));
$endDate = trim((string)($body['end_date'] ?? $date));
$reason = trim((string)($body['reason'] ?? ''));

if ($path === 'maintenance/schedule' && $method === 'POST') {
    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and date required']);
    }
    $label = $reason !== '' ? $reason : 'External maintenance schedule';
    $result = frs_add_blackout_date($pdo, $facilityId, $date, 'CIMM Sync: ' . $label, null);
    if (function_exists('logAudit')) {
        logAudit('Integration maintenance blackout', 'Integrations', "facility {$facilityId} date {$date}");
    }
    frs_integrations_json(200, ['success' => true, 'blackout' => $result]);
}

if ($path === 'projects/timeline' && $method === 'POST') {
    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and date required']);
    }
    $label = $reason !== '' ? $reason : 'Planned construction (Brgy Culiat)';
    if ($endDate !== $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $result = frs_add_blackout_date_range($pdo, $facilityId, $date, $endDate, 'Infrastructure Sync: ' . $label, null);
    } else {
        $result = frs_add_blackout_date($pdo, $facilityId, $date, 'Infrastructure Sync: ' . $label, null);
    }
    if (function_exists('logAudit')) {
        logAudit('Integration infrastructure blackout', 'Integrations', "facility {$facilityId} {$date}–{$endDate}");
    }
    frs_integrations_json(200, ['success' => true, 'blackout' => $result]);
}

if ($path === 'utilities/outage' && $method === 'POST') {
    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and date required']);
    }
    $label = $reason !== '' ? $reason : 'Utility outage';
    if ($endDate !== $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $result = frs_add_blackout_date_range($pdo, $facilityId, $date, $endDate, 'UMAN Outage Sync: ' . $label, null);
    } else {
        $result = frs_add_blackout_date($pdo, $facilityId, $date, 'UMAN Outage Sync: ' . $label, null);
    }
    if (function_exists('logAudit')) {
        logAudit('Integration utility outage blackout', 'Integrations', "facility {$facilityId} {$date}–{$endDate}");
    }
    frs_integrations_json(200, ['success' => true, 'blackout' => $result]);
}

frs_integrations_json(404, ['success' => false, 'error' => 'not_found', 'path' => $path]);
