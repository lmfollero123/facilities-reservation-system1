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
require_once __DIR__ . '/../../../../../services/uman_api.php';

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
        'version' => '1.1',
        'routes' => [
            'GET facilities/status',
            'GET reservations/analytics',
            'POST maintenance/schedule',
            'POST projects/timeline',
            'POST utilities/outage',
            'POST utilities/equipment/assigned',
            'POST utilities/equipment/unassigned',
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
        'SELECT id, name, status, location, capacity, amenities, description, updated_at FROM facilities ORDER BY name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $amenitiesRaw = (string)($r['amenities'] ?? '');
        $amenities = [];
        if ($amenitiesRaw !== '') {
            $dec = json_decode($amenitiesRaw, true);
            if (is_array($dec)) {
                $amenities = array_values(array_filter(array_map('strval', $dec), static fn($s) => $s !== ''));
            } else {
                $amenities = array_values(array_filter(array_map('trim', explode(',', $amenitiesRaw)), static fn($s) => $s !== ''));
            }
        }
        $status = (string)($r['status'] ?? 'available');
        $out[] = [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'status' => $status,
            'status_label' => match ($status) {
                'maintenance' => 'Under Maintenance',
                'offline'     => 'Offline / Not Operational',
                default       => 'Available',
            },
            'is_assignable' => in_array($status, ['available', 'maintenance'], true),
            'location'  => (string)($r['location'] ?? ''),
            'capacity'  => (string)($r['capacity'] ?? ''),
            'amenities' => $amenities,
            'description' => (string)($r['description'] ?? ''),
            'updated_at' => (string)($r['updated_at'] ?? ''),
        ];
    }
    // Optionally enrich with UMAN equipment counts per facility (helps UMAN staff).
    $equipCounts = [];
    if (function_exists('frs_uman_tables_exist') && frs_uman_tables_exist($pdo)) {
        try {
            $equipCounts = $pdo->query('
                SELECT facility_id, COUNT(*) AS equipment_count
                FROM facility_equipment
                WHERE status IS NULL OR status IN (\'active\',\'return_pending\',\'replacement_in_transit\')
                GROUP BY facility_id
            ')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $equipCounts = [];
        }
    }
    foreach ($out as &$row) {
        $row['assigned_equipment_count'] = (int)($equipCounts[$row['id']] ?? 0);
    }
    unset($row);

    frs_integrations_json(200, [
        'success' => true,
        'served_at' => date('c'),
        'facility_count' => count($out),
        'facilities' => $out,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// UMAN → CPRF custody webhooks (Phase 2 bidirectional assign/unassign)
// ─────────────────────────────────────────────────────────────────────────────
if ($path === 'utilities/equipment/assigned' && $method === 'POST') {
    $facilityId = (int)($body['facility_id'] ?? 0);
    $assetId    = (int)($body['uman_asset_id'] ?? 0);
    $meta       = is_array($body['meta'] ?? null) ? $body['meta'] : [];
    $assignedBy = trim((string)($body['assigned_by'] ?? 'UMAN staff'));
    $assignedAt = trim((string)($body['assigned_at'] ?? ''));
    $eventRef   = trim((string)($body['assignment_ref'] ?? ''));
    $sourceFlag = trim((string)($body['assignment_source'] ?? 'UMAN_DIRECT'));
    if (!in_array($sourceFlag, ['UMAN_DIRECT','UMAN_REQUEST_FULFILLED','UMAN_REASSIGNED_DEPRECATED'], true)) {
        $sourceFlag = 'UMAN_DIRECT';
    }
    if ($facilityId <= 0 || $assetId <= 0) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and uman_asset_id required']);
    }
    $result = frs_uman_webhook_equipment_assigned(
        $pdo, $facilityId, $assetId, $meta, $assignedBy, $assignedAt, $eventRef, $sourceFlag
    );
    if (function_exists('logAudit')) {
        logAudit(
            'UMAN webhook equipment assigned',
            'Integrations',
            "facility {$facilityId} asset {$assetId} by {$assignedBy}"
        );
    }
    frs_integrations_json($result['ok'] ? 200 : 500, [
        'success' => $result['ok'],
        'action'  => 'assigned',
        'facility_equipment_id' => $result['facility_equipment_id'] ?? null,
        'event_ref' => $result['event_ref'] ?? null,
        'error'   => $result['error'] ?? null,
    ]);
}

if ($path === 'utilities/equipment/unassigned' && $method === 'POST') {
    $facilityId = (int)($body['facility_id'] ?? 0);
    $assetId    = (int)($body['uman_asset_id'] ?? 0);
    $reason     = trim((string)($body['reason'] ?? ''));
    $actor      = trim((string)($body['unassigned_by'] ?? 'UMAN staff'));
    $at         = trim((string)($body['unassigned_at'] ?? ''));
    $eventRef   = trim((string)($body['event_ref'] ?? ''));
    if ($facilityId <= 0 || $assetId <= 0) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and uman_asset_id required']);
    }
    $result = frs_uman_webhook_equipment_unassigned(
        $pdo, $facilityId, $assetId, $reason, $actor, $at, $eventRef
    );
    if (function_exists('logAudit')) {
        logAudit(
            'UMAN webhook equipment unassigned',
            'Integrations',
            "facility {$facilityId} asset {$assetId} by {$actor}: {$reason}"
        );
    }
    frs_integrations_json($result['ok'] ? 200 : 500, [
        'success' => $result['ok'],
        'action'  => 'unassigned',
        'rows_removed' => $result['rows_removed'] ?? 0,
        'event_ref' => $result['event_ref'] ?? null,
        'error'   => $result['error'] ?? null,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// UMAN → CPRF custody webhook (Phase 3 return requested from UMAN side)
// ─────────────────────────────────────────────────────────────────────────────
if ($path === 'utilities/equipment/return-requested' && $method === 'POST') {
    $facilityId    = (int)($body['facility_id'] ?? 0);
    $assetId       = (int)($body['uman_asset_id'] ?? 0);
    $returnType    = trim((string)($body['return_type'] ?? 'RETURN_ONLY'));
    $condition     = trim((string)($body['condition'] ?? ($body['return_condition'] ?? '')));
    $reason        = trim((string)($body['reason'] ?? ($body['return_reason'] ?? '')));
    $triggeredBy   = trim((string)($body['triggered_by'] ?? ($body['requested_by'] ?? 'UMAN staff')));
    $triggeredAt   = trim((string)($body['triggered_at'] ?? ($body['requested_at'] ?? '')));
    $eventRef      = trim((string)($body['event_ref'] ?? ($body['uman_event_ref'] ?? '')));
    if ($facilityId <= 0 || $assetId <= 0) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and uman_asset_id required']);
    }
    $result = frs_uman_webhook_equipment_return_requested(
        $pdo, $facilityId, $assetId, $returnType, $condition, $reason,
        $triggeredBy, $triggeredAt, $eventRef
    );
    if (function_exists('logAudit')) {
        logAudit(
            'UMAN webhook equipment return-requested',
            'Integrations',
            "facility {$facilityId} asset {$assetId} type={$returnType} by {$triggeredBy}: {$reason}"
        );
    }
    frs_integrations_json($result['ok'] ? 200 : 500, [
        'success'               => $result['ok'],
        'action'                => 'return_requested',
        'facility_equipment_id' => $result['facility_equipment_id'] ?? null,
        'event_ref'             => $result['event_ref'] ?? null,
        'error'                 => $result['error'] ?? null,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// UMAN → CPRF custody webhook (Phase 3c return accepted / decommissioned)
// ─────────────────────────────────────────────────────────────────────────────
if ($path === 'utilities/equipment/return-accepted' && $method === 'POST') {
    $facilityId          = (int)($body['facility_id'] ?? 0);
    $assetId             = (int)($body['uman_asset_id'] ?? 0);
    $returnType          = trim((string)($body['return_type'] ?? 'RETURN_ONLY'));
    $acceptedBy          = trim((string)($body['accepted_by'] ?? ($body['actor'] ?? 'UMAN staff')));
    $acceptedAt          = trim((string)($body['accepted_at'] ?? ($body['timestamp'] ?? '')));
    $eventRef            = trim((string)($body['event_ref'] ?? ''));
    $disposalRef         = trim((string)($body['disposal_ref'] ?? ''));
    $linkedRequestRef    = trim((string)($body['linked_request_ref'] ?? ''));
    $replacementAssetId  = (int)($body['replacement_asset_id'] ?? 0);
    $conditionAfter      = trim((string)($body['condition_after_return'] ?? ($body['condition'] ?? '')));
    $notes               = trim((string)($body['notes'] ?? ($body['reason'] ?? '')));
    if ($facilityId <= 0 || $assetId <= 0) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and uman_asset_id required']);
    }
    $result = frs_uman_webhook_equipment_return_accepted(
        $pdo, $facilityId, $assetId, $returnType, $acceptedBy, $acceptedAt,
        $eventRef, $disposalRef, $linkedRequestRef, $replacementAssetId,
        $conditionAfter, $notes
    );
    if (function_exists('logAudit')) {
        logAudit(
            'UMAN webhook equipment return-accepted',
            'Integrations',
            "facility {$facilityId} asset {$assetId} type={$returnType} by {$acceptedBy}"
            . ($disposalRef !== '' ? " disposal={$disposalRef}" : '')
            . ($replacementAssetId > 0 ? " replacement=#{$replacementAssetId}" : '')
            . " : {$notes}"
        );
    }
    frs_integrations_json($result['ok'] ? 200 : 500, [
        'success'   => $result['ok'],
        'action'    => 'return_accepted',
        'event_ref' => $result['event_ref'] ?? null,
        'new_status'=> $result['new_status'] ?? null,
        'error'     => $result['error'] ?? null,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// UMAN → CPRF custody webhook (Phase 3c replacement shipped)
// ─────────────────────────────────────────────────────────────────────────────
if ($path === 'utilities/equipment/replacement-shipped' && $method === 'POST') {
    $facilityId         = (int)($body['facility_id'] ?? 0);
    $originalAssetId    = (int)($body['original_asset_id'] ?? 0);
    $replacementAssetId = (int)($body['replacement_asset_id'] ?? 0);
    $shippedBy          = trim((string)($body['shipped_by'] ?? ($body['assigned_by'] ?? 'UMAN staff')));
    $shippedAt          = trim((string)($body['shipped_at'] ?? ($body['timestamp'] ?? '')));
    $trackingNumber     = trim((string)($body['tracking_number'] ?? ($body['tracking'] ?? '')));
    $eventRef           = trim((string)($body['event_ref'] ?? ($body['assignment_ref'] ?? '')));
    $linkedRequestRef   = trim((string)($body['linked_request_ref'] ?? ''));
    $conditionStatus    = trim((string)($body['condition_status'] ?? ($body['condition'] ?? '')));
    $assetCode          = trim((string)($body['asset_code'] ?? ''));
    $assetName          = trim((string)($body['asset_name'] ?? ''));
    $assetType          = trim((string)($body['asset_type'] ?? ''));
    $notes              = trim((string)($body['notes'] ?? ''));
    if ($facilityId <= 0 || $replacementAssetId <= 0) {
        frs_integrations_json(422, ['success' => false, 'error' => 'facility_id and replacement_asset_id required']);
    }
    $result = frs_uman_webhook_equipment_replacement_shipped(
        $pdo, $facilityId, $originalAssetId, $replacementAssetId,
        $shippedBy, $shippedAt, $trackingNumber, $eventRef, $linkedRequestRef,
        $conditionStatus, $assetCode, $assetName, $assetType, $notes
    );
    if (function_exists('logAudit')) {
        logAudit(
            'UMAN webhook equipment replacement-shipped',
            'Integrations',
            "facility {$facilityId} original={$originalAssetId} replacement={$replacementAssetId} by {$shippedBy}"
            . ($trackingNumber !== '' ? " tracking={$trackingNumber}" : '')
            . " : {$notes}"
        );
    }
    frs_integrations_json($result['ok'] ? 200 : 500, [
        'success'               => $result['ok'],
        'action'                => 'replacement_shipped',
        'facility_equipment_id' => $result['facility_equipment_id'] ?? null,
        'event_ref'             => $result['event_ref'] ?? null,
        'error'                 => $result['error'] ?? null,
    ]);
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
