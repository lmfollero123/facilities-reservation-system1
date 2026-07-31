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

/**
 * Shared key loader — accepts both CPRF's `UMAN_API_KEY` (primary on CPRF .env) and the UMAN
 * server's alternate `UMAN_INTEGRATION_API_KEY` name, plus env_value(), getenv(),
 * and $_ENV[] so every loading strategies covered no matter how the deploy injects them.
 *
 * Prevents the classic "CPRF sets env A, UMAN expects env B" 401 drift on live.
 */
function uman_api_key(): string
{
    $candidates = [];
    if (function_exists('env_value')) {
        $candidates[] = (string)env_value('UMAN_API_KEY', '');
        $candidates[] = (string)env_value('UMAN_INTEGRATION_API_KEY', '');
    }
    $candidates[] = (string)getenv('UMAN_API_KEY');
    $candidates[] = (string)getenv('UMAN_INTEGRATION_API_KEY');
    if (isset($_ENV['UMAN_API_KEY'])) $candidates[] = (string)$_ENV['UMAN_API_KEY'];
    if (isset($_ENV['UMAN_INTEGRATION_API_KEY'])) $candidates[] = (string)$_ENV['UMAN_INTEGRATION_API_KEY'];
    foreach ($candidates as $v) {
        $v = trim($v);
        if ($v !== '') return $v;
    }
    return '';
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
            'X-API-Key: ' . $apiKey,
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
        $decoded = json_decode((string)$response, true);
        $detail = is_array($decoded) && !empty($decoded['error']) ? (string)$decoded['error'] : '';
        return [
            'data' => [],
            'error' => 'Unauthorized: UMAN rejected the API key. ' . ($detail ?: 'Check that the same shared key is set on BOTH sides (CPRF .env UMAN_API_KEY and UMAN server env UMAN_INTEGRATION_API_KEY / UMAN_API_KEY).'),
            'http_code' => $httpCode,
        ];
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
            'X-API-Key: ' . $apiKey,
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

    if ($httpCode === 401 || empty($json['success'])) {
        $detail = (string)($json['error'] ?? $json['message'] ?? 'UMAN request failed');
        if ($httpCode === 401) {
            $detail = 'Unauthorized: UMAN rejected the API key. ' . $detail
                   . ' Verify both sides use the same shared key (CPRF UMAN_API_KEY ↔ UMAN server UMAN_INTEGRATION_API_KEY/UMAN_API_KEY).';
        }
        return ['data' => [], 'error' => $detail];
    }

    return ['data' => $json, 'error' => null];
}

function fetchUMANAssets(bool $availableOnly = true): array
{
    $query = $availableOnly ? ['available' => '1'] : [];
    $result = uman_api_get('/api/assets.php', $query);
    return ['data' => $result['data'], 'error' => $result['error']];
}

function fetchUMANAssetTypes(): array
{
    $result = uman_api_get('/api/asset-types.php');
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

/**
 * Submit an asset request to UMAN with all specific fields so UMAN staff can
 * route, schedule, and fulfill without ambiguity.
 *
 * @param int    $facilityId
 * @param string $facilityName
 * @param string $assetType              UMAN asset_types.name e.g. "Sound System"
 * @param int    $quantity
 * @param string $notes
 * @param array  $extras {
 *     asset_type_id?:        int         UMAN asset_types.id (links exact category row)
 *     requested_asset_code?: string      Specific utility_assets.asset_id (e.g. "PA-0042")
 *     exact_match?:          bool        If true + asset_code set, UMAN will not substitute
 *     urgency?:              string      'Routine' | 'Priority' | 'Emergency'
 *     date_needed?:          string      YYYY-MM-DD when asset must be at the facility
 *     booking_ref?:          string      CPRF booking/reservation reference (event link)
 *     event_purpose?:        string      e.g. "Barangay assembly"
 *     responsible_office?:   string      UMAN office routing (from catalog)
 * }
 * @return array{data: array, error: ?string}
 */
function submitUMANAssetRequest(
    int $facilityId,
    string $facilityName,
    string $assetType,
    int $quantity = 1,
    string $notes = '',
    array $extras = []
): array {
    $body = [
        'cprf_facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'asset_type' => $assetType,
        'quantity' => max(1, $quantity),
        'notes' => $notes,
    ];

    $allowedKeys = [
        'asset_type_id' => true,
        'requested_asset_code' => true,
        'exact_match' => true,
        'urgency' => true,
        'date_needed' => true,
        'booking_ref' => true,
        'event_purpose' => true,
        'responsible_office' => true,
    ];

    foreach ($extras as $k => $v) {
        if (!isset($allowedKeys[$k])) {
            continue;
        }
        if ($k === 'asset_type_id') {
            $body[$k] = is_numeric($v) ? (int)$v : null;
        } elseif ($k === 'exact_match') {
            $body[$k] = !empty($v);
        } elseif ($k === 'date_needed') {
            $parsed = is_string($v) ? date_parse($v) : false;
            $body[$k] = ($parsed !== false && empty($parsed['errors']))
                ? sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day'])
                : null;
        } else {
            $trimmed = is_scalar($v) ? trim((string)$v) : '';
            $body[$k] = $trimmed !== '' ? $trimmed : null;
        }
    }

    return uman_api_post('/api/asset-requests.php', $body);
}

/**
 * Idempotently upgrade local uman_asset_requests table to include the new
 * specific columns introduced with the v2 CPRF↔UMAN integration payload.
 * Safe to call on every page render — catches duplicate column exceptions.
 */
function frs_ensure_uman_requests_schema_v2(PDO $pdo): void
{
    try {
        $addCol = function (string $definition) use ($pdo): void {
            try {
                $pdo->exec("ALTER TABLE uman_asset_requests ADD COLUMN $definition");
            } catch (Throwable $e) {
                // duplicate column / already exists: noop
            }
        };
        $addCol("`asset_type_id` INT NULL AFTER `asset_type`");
        $addCol("`requested_asset_code` VARCHAR(50) NULL AFTER `asset_type_id`");
        $addCol("`exact_match` TINYINT(1) NOT NULL DEFAULT 0 AFTER `requested_asset_code`");
        $addCol("`urgency` VARCHAR(20) NOT NULL DEFAULT 'Routine' AFTER `quantity`");
        $addCol("`date_needed` DATE NULL AFTER `urgency`");
        $addCol("`booking_ref` VARCHAR(80) NULL AFTER `date_needed`");
        $addCol("`event_purpose` VARCHAR(200) NULL AFTER `booking_ref`");
        $addCol("`responsible_office` VARCHAR(100) NULL AFTER `event_purpose`");
        $addCol("`fulfilled_asset_id` INT NULL AFTER `responsible_office`");
        $addCol("`review_notes` TEXT NULL AFTER `fulfilled_asset_id`");
        try {
            $pdo->exec("ALTER TABLE uman_asset_requests
                ADD INDEX idx_uman_req_date_needed (date_needed),
                ADD INDEX idx_uman_req_urgency (urgency),
                ADD INDEX idx_uman_req_requested_asset (requested_asset_code)");
        } catch (Throwable $e) {
            // indexes already exist
        }
    } catch (Throwable $e) {
        // table may not exist yet; swallow — frs_record_uman_asset_request() already guards on frs_uman_tables_exist()
    }
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
 * Persist facility → equipment links from the Facility Management add/edit modals.
 *
 * IMPORTANT SAFETY GATE: Each submitted ID MUST be present in
 * `frs_uman_allowed_assets_for_facility()` for this specific `$facilityId`.
 * IDs outside that allow-list (stale checkboxes from old pages, crafted POST
 * payloads, equipment approved only for a DIFFERENT facility) are silently
 * dropped BEFORE the DELETE+INSERT so bad data never lands in
 * `facility_equipment`. This closes the admin-user-facing loophole where any
 * asset from the entire UMAN catalog could be attached to any facility.
 */
function frs_save_facility_equipment(PDO $pdo, int $facilityId, array $selectedIds, array $catalog): void
{
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0) {
        return;
    }

    // 1. Build the server-side allow-list for THIS facility
    frs_ensure_uman_requests_schema_v2($pdo);
    $allowList = frs_uman_allowed_assets_for_facility($pdo, $facilityId, $catalog);

    // 2. Normalize user input, then INTERSECT with the allow-list — anything
    //    not explicitly approved disappears.
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds))));
    $selectedIds = array_values(array_filter(
        $selectedIds,
        static fn(int $id): bool => $id > 0 && isset($allowList[$id])
    ));

    // 3. DELETE+INSERT (idempotent rewrite per facility)
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
        // Prefer allow-list data first (has source-of-truth info from the
        // sync job), fall back to catalog so denormalized columns are full.
        $allowed = $allowList[$assetId] ?? null;
        $a = $catalog[$assetId] ?? [];

        $code = (string)($allowed['asset_code'] ?? ($a['asset_code'] ?? $a['asset_id'] ?? ('AST-' . $assetId)));
        $name = (string)($allowed['asset_name'] ?? ($a['name'] ?? 'Asset'));
        $type = (string)($allowed['asset_type'] ?? ($a['asset_type'] ?? ''));
        $cond = (string)($allowed['condition_status'] ?? ($a['condition_status'] ?? ''));

        $insert->execute([$facilityId, $assetId, $code, $name, $type, $cond]);
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

/**
 * Persist a CPRF-side asset request record.
 *
 * @param array $fields {
 *     asset_type_id?:        ?int
 *     requested_asset_code?: ?string
 *     exact_match?:          bool|int
 *     urgency?:              string  'Routine'|'Priority'|'Emergency'
 *     date_needed?:          ?string YYYY-MM-DD
 *     booking_ref?:          ?string
 *     event_purpose?:        ?string
 *     responsible_office?:   ?string
 * }
 */
function frs_record_uman_asset_request(
    PDO $pdo,
    int $facilityId,
    string $assetType,
    int $quantity,
    string $notes,
    string $requestRef,
    string $status = 'pending',
    array $fields = []
): void {
    if (!frs_uman_tables_exist($pdo)) {
        return;
    }

    frs_ensure_uman_requests_schema_v2($pdo);

    $urgency = (string)($fields['urgency'] ?? 'Routine');
    if (!in_array($urgency, ['Routine', 'Priority', 'Emergency'], true)) {
        $urgency = 'Routine';
    }
    $dateNeeded = null;
    if (!empty($fields['date_needed']) && is_string($fields['date_needed'])) {
        $parsed = date_parse($fields['date_needed']);
        if ($parsed !== false && empty($parsed['errors'])) {
            $dateNeeded = sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day']);
        }
    }
    $assetTypeId = (!empty($fields['asset_type_id']) && is_numeric($fields['asset_type_id'])) ? (int)$fields['asset_type_id'] : null;
    $reqCode = (!empty($fields['requested_asset_code']) && is_scalar($fields['requested_asset_code'])) ? trim((string)$fields['requested_asset_code']) : null;
    if ($reqCode === '') $reqCode = null;
    $exactMatch = !empty($fields['exact_match']) ? 1 : 0;
    $bookingRef = (!empty($fields['booking_ref']) && is_scalar($fields['booking_ref'])) ? trim((string)$fields['booking_ref']) : null;
    if ($bookingRef === '') $bookingRef = null;
    $eventPurpose = (!empty($fields['event_purpose']) && is_scalar($fields['event_purpose'])) ? trim((string)$fields['event_purpose']) : null;
    if ($eventPurpose === '') $eventPurpose = null;
    $respOffice = (!empty($fields['responsible_office']) && is_scalar($fields['responsible_office'])) ? trim((string)$fields['responsible_office']) : null;
    if ($respOffice === '') $respOffice = null;

    $stmt = $pdo->prepare("
        INSERT INTO uman_asset_requests
            (facility_id, asset_type, asset_type_id, requested_asset_code, exact_match,
             quantity, urgency, date_needed, booking_ref, event_purpose,
             responsible_office, notes, uman_request_ref, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $facilityId,
        $assetType,
        $assetTypeId,
        $reqCode,
        $exactMatch,
        max(1, $quantity),
        $urgency,
        $dateNeeded,
        $bookingRef,
        $eventPurpose,
        $respOffice,
        $notes ?: null,
        $requestRef,
        $status,
    ]);
}

/**
 * Build an allow-list of UMAN asset IDs that the given facility is legally
 * allowed to "own" per the Request→UMAN-Approval→Fulfill flow.
 *
 * Combines two sources to stay robust even if auto-assign hasn't re-run yet
 * after a UMAN fulfill:
 *   1. facility_equipment rows already auto-assigned by a prior sync
 *   2. uman_asset_requests rows for this facility where the remote response
 *      (stored on the row itself if we synced fulfilled_asset_id) or the
 *      remote response from fetchUMANAssetRequests carries an
 *      approved/fulfilled status with a linked fulfilled_asset_id.
 *
 * Returns: array<int, array{uman_asset_id:int, asset_code:?string, asset_name:string, asset_type:?string, condition_status:?string}>
 *   Keyed by uman_asset_id for easy isset() / lookup.
 *
 * @return array<int, array<string, mixed>>
 */
function frs_uman_allowed_assets_for_facility(PDO $pdo, int $facilityId, array $fullCatalogIndexed): array
{
    $allowed = [];

    if ($facilityId <= 0 || !frs_uman_tables_exist($pdo)) {
        return $allowed;
    }

    // Source 1: any row already in facility_equipment (by the sync job that
    // ran after UMAN fulfillment, or by admins on earlier un-gated saves —
    // we keep those rows grandfathered to avoid mass disappearances post-fix)
    try {
        $stmt = $pdo->prepare('
            SELECT uman_asset_id, uman_asset_code, asset_name, asset_type, condition_status
            FROM facility_equipment
            WHERE facility_id = ?
        ');
        $stmt->execute([$facilityId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aid = (int)$row['uman_asset_id'];
            if ($aid <= 0) continue;
            $allowed[$aid] = [
                'uman_asset_id'    => $aid,
                'asset_code'       => (string)($row['uman_asset_code'] ?? ''),
                'asset_name'       => (string)$row['asset_name'],
                'asset_type'       => (string)($row['asset_type'] ?? ''),
                'condition_status' => (string)($row['condition_status'] ?? ''),
                'source'           => 'assigned',
            ];
        }
    } catch (Throwable $e) {
        // noop — allow list remains populated only by the next source
    }

    // Source 2: fulfilled / approved requests for this facility where we
    // already have a fulfilled_asset_id stored. Covers the "UMAN just set
    // fulfilled but sync hasn't run facility_equipment insert yet" window.
    try {
        $stmt = $pdo->prepare('
            SELECT fulfilled_asset_id, requested_asset_code, asset_type, event_purpose, status
            FROM uman_asset_requests
            WHERE facility_id = ?
              AND status IN (\'approved\', \'fulfilled\')
              AND fulfilled_asset_id IS NOT NULL
              AND fulfilled_asset_id > 0
        ');
        $stmt->execute([$facilityId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aid = (int)$row['fulfilled_asset_id'];
            if ($aid <= 0 || isset($allowed[$aid])) continue;
            $catalogMatch = $fullCatalogIndexed[$aid] ?? null;
            $allowed[$aid] = [
                'uman_asset_id'    => $aid,
                'asset_code'       => (string)($row['requested_asset_code'] ?? ($catalogMatch['asset_code'] ?? '')),
                'asset_name'       => (string)($catalogMatch['name'] ?? ('UMAN Asset #' . $aid)),
                'asset_type'       => (string)($row['asset_type'] ?? ($catalogMatch['asset_type'] ?? '')),
                'condition_status' => (string)($catalogMatch['condition_status'] ?? ''),
                'source'           => 'fulfilled_req',
            ];
        }
    } catch (Throwable $e) {
        // fulfilled_asset_id column may not exist yet on pre-schema-v2 DBs —
        // frs_ensure_uman_requests_schema_v2() will add it on next save.
    }

    return $allowed;
}

function frs_sync_local_uman_requests(PDO $pdo): int
{
    if (!frs_uman_tables_exist($pdo)) {
        return 0;
    }

    frs_ensure_uman_requests_schema_v2($pdo);

    $remote = fetchUMANAssetRequests();
    if (!empty($remote['error']) || empty($remote['data'])) {
        return 0;
    }

    $updated = 0;

    // 1. Sync status + fulfilled_asset_id + review_notes into local request rows
    $stmtStatus = $pdo->prepare("
        UPDATE uman_asset_requests
        SET status = ?,
            fulfilled_asset_id = ?,
            review_notes = ?,
            updated_at = NOW()
        WHERE uman_request_ref = ?
    ");

    // 2. When a request is fulfilled with a specific asset, auto-assign that
    //    asset to the facility's equipment locker (idempotent INSERT-SELECT —
    //    no duplicate even if sync runs 100x).
    $stmtAssignSel = $pdo->prepare('
        SELECT 1 FROM facility_equipment
        WHERE facility_id = ? AND uman_asset_id = ?
        LIMIT 1
    ');
    $stmtAssignIns = $pdo->prepare("
        INSERT INTO facility_equipment
            (facility_id, uman_asset_id, uman_asset_code, asset_name, asset_type, condition_status, notes)
        VALUES (?, ?, ?, ?, ?, ?, NULL)
    ");

    // Cache-lookup catalog info for the auto-assign INSERT so we populate the
    // denormalized columns (asset_name / code / type) used by equipment summary.
    $catalogIndexed = [];
    $catalogLoaded = false;
    $ensureCatalog = static function () use (&$catalogIndexed, &$catalogLoaded): void {
        if ($catalogLoaded) return;
        $cat = fetchUMANAssets(true);
        foreach (($cat['data'] ?? []) as $a) {
            $aid = (int)($a['id'] ?? 0);
            if ($aid > 0) $catalogIndexed[$aid] = $a;
        }
        $catalogLoaded = true;
    };

    $facilityIdsNeedle = [];
    foreach ($remote['data'] as $row) {
        $ref       = (string)($row['request_ref'] ?? '');
        $status    = (string)($row['status'] ?? '');
        $fid       = (int)($row['cprf_facility_id'] ?? 0);
        $fulfilled = !empty($row['fulfilled_asset_id']) ? (int)$row['fulfilled_asset_id'] : null;
        $review    = !empty($row['review_notes']) ? (string)$row['review_notes'] : null;
        if ($ref === '' || $status === '') continue;

        $stmtStatus->execute([$status, $fulfilled, $review, $ref]);
        $updated += $stmtStatus->rowCount();

        // Gate: auto-assign ONLY when (status=approved or fulfilled) AND
        // the fulfilled_asset_id is explicitly populated by UMAN staff on
        // their external_asset_requests.php UI. Rejected/pending items never
        // touch facility_equipment.
        if ($fid > 0 && $fulfilled !== null && $fulfilled > 0
            && in_array($status, ['approved', 'fulfilled'], true)) {
            $facilityIdsNeedle[$fid] = true;
            $stmtAssignSel->execute([$fid, $fulfilled]);
            if (!$stmtAssignSel->fetchColumn()) {
                $ensureCatalog();
                $meta = $catalogIndexed[$fulfilled] ?? null;
                $stmtAssignIns->execute([
                    $fid,
                    $fulfilled,
                    (string)($meta['asset_code'] ?? ('AUTO-' . $fulfilled)),
                    (string)($meta['name']       ?? ('UMAN Asset #' . $fulfilled)),
                    (string)($meta['asset_type'] ?? ''),
                    (string)($meta['condition_status'] ?? ''),
                ]);
                $updated += 1;
            }
        }
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
