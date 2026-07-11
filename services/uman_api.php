<?php
/**
 * UMAN (Utilities Management) API integration for CPRF.
 * Base URL: https://uman.infragovservices.com
 */

declare(strict_types=1);

function uman_api_base_url(): string
{
    $url = trim((string)(function_exists('env_value') ? env_value('UMAN_API_URL', '') : (getenv('UMAN_API_URL') ?: '')));
    if ($url === '') {
        $url = 'https://uman.infragovservices.com';
    }
    return rtrim($url, '/');
}

function uman_api_key(): string
{
    return trim((string)(function_exists('env_value') ? env_value('UMAN_API_KEY', '') : (getenv('UMAN_API_KEY') ?: '')));
}

/**
 * @return array{data: array, error: ?string, http_code: int}
 */
function uman_api_get(string $path, array $query = []): array
{
    $apiKey = uman_api_key();
    if ($apiKey === '') {
        return ['data' => [], 'error' => 'UMAN API key is not configured (set UMAN_API_KEY in .env).', 'http_code' => 0];
    }

    $query['key'] = $apiKey;
    $url = uman_api_base_url() . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: CPRF-Facilities-Reservation/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        $msg = 'Connection failed: ' . ($curlError ?: 'Unable to reach UMAN API');
        error_log('UMAN API Error: ' . $msg);
        return ['data' => [], 'error' => $msg, 'http_code' => $httpCode];
    }

    if ($httpCode === 401) {
        return ['data' => [], 'error' => 'Unauthorized: UMAN API key may be incorrect', 'http_code' => $httpCode];
    }

    if ($httpCode === 404) {
        return ['data' => [], 'error' => 'UMAN API endpoint not found — deploy api/assets.php on UMAN server', 'http_code' => $httpCode];
    }

    if ($httpCode !== 200) {
        return ['data' => [], 'error' => 'HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200), 'http_code' => $httpCode];
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        return ['data' => [], 'error' => 'Invalid JSON from UMAN', 'http_code' => $httpCode];
    }

    if (empty($json['success'])) {
        return ['data' => [], 'error' => (string)($json['error'] ?? $json['message'] ?? 'UMAN request failed'), 'http_code' => $httpCode];
    }

    return ['data' => $json['data'] ?? [], 'error' => null, 'http_code' => $httpCode];
}

/**
 * @return array{data: array, error: ?string}
 */
function uman_api_post(string $path, array $body): array
{
    $apiKey = uman_api_key();
    if ($apiKey === '') {
        return ['data' => [], 'error' => 'UMAN API key is not configured (set UMAN_API_KEY in .env).'];
    }

    $url = uman_api_base_url() . $path . '?key=' . urlencode($apiKey);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: CPRF-Facilities-Reservation/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return ['data' => [], 'error' => 'Connection failed: ' . ($curlError ?: 'Unable to reach UMAN')];
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        return ['data' => [], 'error' => 'Invalid JSON from UMAN (HTTP ' . $httpCode . ')'];
    }

    if (empty($json['success'])) {
        return ['data' => [], 'error' => (string)($json['error'] ?? $json['message'] ?? 'UMAN request failed')];
    }

    return ['data' => $json, 'error' => null];
}

function fetchUMANAssets(bool $availableOnly = true): array
{
    $query = $availableOnly ? ['available' => '1'] : [];
    $result = uman_api_get('/api/assets.php', $query);
    return ['data' => $result['data'], 'error' => $result['error']];
}

function fetchUMANAssetRequests(?string $status = null, ?int $facilityId = null): array
{
    $query = [];
    if ($status !== null && $status !== '') {
        $query['status'] = $status;
    }
    if ($facilityId !== null && $facilityId > 0) {
        $query['cprf_facility_id'] = (string)$facilityId;
    }
    $result = uman_api_get('/api/asset-requests.php', $query);
    return ['data' => $result['data'], 'error' => $result['error']];
}

function submitUMANAssetRequest(int $facilityId, string $facilityName, string $assetType, int $quantity = 1, string $notes = ''): array
{
    return uman_api_post('/api/asset-requests.php', [
        'cprf_facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'asset_type' => $assetType,
        'quantity' => max(1, $quantity),
        'notes' => $notes,
    ]);
}

function frs_uman_tables_exist(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'facility_equipment'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function frs_get_facility_equipment(PDO $pdo, int $facilityId): array
{
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM facility_equipment WHERE facility_id = ? ORDER BY asset_name ASC');
    $stmt->execute([$facilityId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<int, array<string, mixed>> $catalog keyed by uman_asset_id
 */
function frs_save_facility_equipment(PDO $pdo, int $facilityId, array $selectedIds, array $catalog): void
{
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0) {
        return;
    }

    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds))));
    $pdo->prepare('DELETE FROM facility_equipment WHERE facility_id = ?')->execute([$facilityId]);

    if ($selectedIds === []) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO facility_equipment
            (facility_id, uman_asset_id, uman_asset_code, asset_name, asset_type, condition_status, notes)
        VALUES (?, ?, ?, ?, ?, ?, NULL)
    ");

    foreach ($selectedIds as $assetId) {
        if (!isset($catalog[$assetId])) {
            continue;
        }
        $a = $catalog[$assetId];
        $insert->execute([
            $facilityId,
            $assetId,
            (string)($a['asset_code'] ?? $a['asset_id'] ?? ('AST-' . $assetId)),
            (string)($a['name'] ?? 'Asset'),
            (string)($a['asset_type'] ?? ''),
            (string)($a['condition_status'] ?? ''),
        ]);
    }
}

function frs_get_facility_equipment_map(PDO $pdo, array $facilityIds): array
{
    if (!frs_uman_tables_exist($pdo) || $facilityIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($facilityIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM facility_equipment WHERE facility_id IN ($placeholders) ORDER BY asset_name ASC");
    $stmt->execute(array_values($facilityIds));
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fid = (int)$row['facility_id'];
        $map[$fid][] = $row;
    }
    return $map;
}

function frs_record_uman_asset_request(PDO $pdo, int $facilityId, string $assetType, int $quantity, string $notes, string $requestRef, string $status = 'pending'): void
{
    if (!frs_uman_tables_exist($pdo)) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO uman_asset_requests (facility_id, asset_type, quantity, notes, uman_request_ref, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$facilityId, $assetType, max(1, $quantity), $notes ?: null, $requestRef, $status]);
}

function frs_sync_local_uman_requests(PDO $pdo): int
{
    if (!frs_uman_tables_exist($pdo)) {
        return 0;
    }

    $remote = fetchUMANAssetRequests();
    if (!empty($remote['error']) || empty($remote['data'])) {
        return 0;
    }

    $updated = 0;
    $stmt = $pdo->prepare('UPDATE uman_asset_requests SET status = ?, updated_at = NOW() WHERE uman_request_ref = ?');

    foreach ($remote['data'] as $row) {
        $ref = (string)($row['request_ref'] ?? '');
        $status = (string)($row['status'] ?? '');
        if ($ref === '' || $status === '') {
            continue;
        }
        $stmt->execute([$status, $ref]);
        $updated += $stmt->rowCount();
    }

    return $updated;
}

function frs_index_uman_assets(array $assets): array
{
    $indexed = [];
    foreach ($assets as $asset) {
        $id = (int)($asset['id'] ?? 0);
        if ($id > 0) {
            $indexed[$id] = $asset;
        }
    }
    return $indexed;
}
