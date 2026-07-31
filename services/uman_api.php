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

/**
 * Add the Phase-2 custody-tracking columns to the CPRF-side facility_equipment
 * join table + create the chain-of-custody event log. Runs idempotently.
 */
function frs_ensure_facility_equipment_schema_v2(PDO $pdo): void
{
    if (!frs_uman_tables_exist($pdo)) {
        return;
    }

    $addCol = static function (string $definition) use ($pdo): void {
        try {
            $pdo->exec('ALTER TABLE facility_equipment ADD COLUMN ' . $definition);
        } catch (Throwable $e) {
            // duplicate column / already exists
        }
    };

    // ── custody lifecycle columns ─────────────────────────────────────
    $addCol("`status` ENUM('active','return_pending','replacement_in_transit','archived','decommissioned')
                NOT NULL DEFAULT 'active' AFTER `assigned_at`");
    $addCol("`assigned_source` ENUM('UMAN_DIRECT','UMAN_REQUEST_FULFILLED','UMAN_REASSIGNED_DEPRECATED','UMAN_WEBHOOK_RECALL','UMAN_REPLACEMENT_SHIPMENT')
                NOT NULL DEFAULT 'UMAN_DIRECT' AFTER `status`");
    $addCol("`assigned_by_user_id` INT NULL AFTER `assigned_source`");
    $addCol("`assigned_event_ref` VARCHAR(60) NULL AFTER `assigned_by_user_id`");
    $addCol("`return_requested_at` TIMESTAMP NULL AFTER `assigned_event_ref`");
    $addCol("`return_requested_by` VARCHAR(150) NULL AFTER `return_requested_at`");
    $addCol("`return_type` ENUM('RETURN_ONLY','RETURN_AND_REPLACE','RETURN_DECOMMISSION') NULL AFTER `return_requested_by`");
    $addCol("`return_condition` VARCHAR(100) NULL AFTER `return_type`");
    $addCol("`return_reason` TEXT NULL AFTER `return_condition`");
    $addCol("`accepted_return_ref` VARCHAR(60) NULL AFTER `return_reason`");
    $addCol("`accepted_return_by` VARCHAR(150) NULL AFTER `accepted_return_ref`");
    $addCol("`linked_replacement_asset_id` INT NULL AFTER `accepted_return_by`");
    $addCol("`disposal_ref` VARCHAR(60) NULL AFTER `linked_replacement_asset_id`");
    $addCol("`archived_at` TIMESTAMP NULL AFTER `disposal_ref`");
    try {
        $pdo->exec('ALTER TABLE facility_equipment
            ADD UNIQUE KEY uk_fe_facility_asset (facility_id, uman_asset_id),
            ADD INDEX idx_fe_status (status),
            ADD INDEX idx_fe_return_requested (return_requested_at),
            ADD INDEX idx_fe_archived (archived_at),
            ADD INDEX idx_fe_replacement (linked_replacement_asset_id)');
    } catch (Throwable $e) {
        // indexes already exist
    }

    // ── chain-of-custody event log (COA compliant) ────────────────────
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `facility_equipment_events` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `event_ref` VARCHAR(60) NOT NULL UNIQUE,
              `facility_id` INT NOT NULL,
              `uman_asset_id` INT NOT NULL,
              `event_type` ENUM('UMAN_ASSIGN','UMAN_UNASSIGN','UMAN_RETURN_ACCEPTED',
                                 'CPRF_RETURN_REQUESTED','CPRF_RETURN_CANCELLED',
                                 'UMAN_DECOMMISSIONED','UMAN_REPLACEMENT_SHIPPED',
                                 'UMAN_RETURN_TRIGGERED','CPRF_REPLACEMENT_RECEIVED') NOT NULL,
              `actor_system` ENUM('CPRF','UMAN') NOT NULL,
              `actor_user_label` VARCHAR(150) NOT NULL,
              `return_type` ENUM('RETURN_ONLY','RETURN_AND_REPLACE','RETURN_DECOMMISSION') NULL,
              `condition_reported` VARCHAR(50) NULL,
              `event_notes` TEXT NULL,
              `linked_request_ref` VARCHAR(50) NULL,
              `linked_disposal_ref` VARCHAR(50) NULL,
              `linked_asset_id` INT NULL COMMENT 'peer asset for replacement correlation',
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_fee_facility` (`facility_id`),
              INDEX `idx_fee_asset` (`uman_asset_id`),
              INDEX `idx_fee_type` (`event_type`),
              INDEX `idx_fee_created` (`created_at`),
              INDEX `idx_fee_linked_asset` (`linked_asset_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // event table exists: noop
    }
    // Idempotent widening for legacy event tables that already exist without
    // CPRF_REPLACEMENT_RECEIVED ENUM or linked_asset_id column.
    try {
        $pdo->exec("ALTER TABLE facility_equipment_events MODIFY COLUMN event_type
            ENUM('UMAN_ASSIGN','UMAN_UNASSIGN','UMAN_RETURN_ACCEPTED',
                 'CPRF_RETURN_REQUESTED','CPRF_RETURN_CANCELLED',
                 'UMAN_DECOMMISSIONED','UMAN_REPLACEMENT_SHIPPED',
                 'UMAN_RETURN_TRIGGERED','CPRF_REPLACEMENT_RECEIVED')
            NOT NULL");
    } catch (Throwable $e) { /* noop */ }
    try {
        $pdo->exec("ALTER TABLE facility_equipment_events
            ADD COLUMN `linked_asset_id` INT NULL COMMENT 'peer asset for replacement correlation'
            AFTER `linked_disposal_ref`,
            ADD INDEX `idx_fee_linked_asset` (`linked_asset_id`)");
    } catch (Throwable $e) { /* noop */ }
}

/**
 * Write a single chain-of-custody event row.
 *
 * @param array{event_type:string,actor_system:string,actor_user_label:string,
 *              return_type?:?string,condition_reported?:?string,event_notes?:?string,
 *              linked_request_ref?:?string,linked_disposal_ref?:?string} $data
 */
function frs_uman_write_custody_event(PDO $pdo, int $facilityId, int $umanAssetId, array $data): string
{
    frs_ensure_facility_equipment_schema_v2($pdo);

    $eventRef = (string)($data['event_ref'] ?? '');
    if ($eventRef === '') {
        $eventRef = 'FEE-' . date('YmdHis') . '-' . substr(uniqid('', true), -6, 6);
    }
    $allowedTypes = [
        'UMAN_ASSIGN', 'UMAN_UNASSIGN', 'UMAN_RETURN_ACCEPTED',
        'CPRF_RETURN_REQUESTED', 'CPRF_RETURN_CANCELLED',
        'UMAN_DECOMMISSIONED', 'UMAN_REPLACEMENT_SHIPPED',
        'UMAN_RETURN_TRIGGERED',
    ];
    $eventType = (string)($data['event_type'] ?? 'UMAN_ASSIGN');
    if (!in_array($eventType, $allowedTypes, true)) {
        $eventType = 'UMAN_ASSIGN';
    }
    $actorSystem = ($data['actor_system'] ?? 'UMAN') === 'CPRF' ? 'CPRF' : 'UMAN';
    $actorLabel = (string)($data['actor_user_label'] ?? ($data['actor_user'] ?? ($actorSystem . ' user')));
    $condition = !empty($data['condition_reported']) ? trim((string)$data['condition_reported'])
                 : (!empty($data['return_condition']) ? trim((string)$data['return_condition']) : null);
    $notes     = !empty($data['event_notes']) ? trim((string)$data['event_notes'])
                 : (!empty($data['notes']) ? trim((string)$data['notes']) : null);
    $linkedReq = !empty($data['linked_request_ref']) ? trim((string)$data['linked_request_ref'])
                 : (!empty($data['cprf_event_ref']) ? trim((string)$data['cprf_event_ref']) : null);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO facility_equipment_events
                (event_ref, facility_id, uman_asset_id, event_type, actor_system,
                 actor_user_label, return_type, condition_reported,
                 event_notes, linked_request_ref, linked_disposal_ref, linked_asset_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $eventRef,
            $facilityId,
            $umanAssetId,
            $eventType,
            $actorSystem,
            $actorLabel,
            in_array(($data['return_type'] ?? null), ['RETURN_ONLY','RETURN_AND_REPLACE','RETURN_DECOMMISSION'], true)
                ? (string)$data['return_type']
                : null,
            $condition,
            $notes,
            $linkedReq,
            !empty($data['linked_disposal_ref']) ? trim((string)$data['linked_disposal_ref']) : null,
            !empty($data['linked_asset_id']) ? (int)$data['linked_asset_id'] : null,
        ]);
    } catch (Throwable $e) {
        // duplicate event_ref: return existing ref, don't blow up
    }

    return $eventRef;
}

/**
 * UMAN → CPRF webhook: UMAN has assigned an asset to a Barangay facility
 * (either via the new cprf_facility_assignments page, or via the existing
 * "Fulfill request" action on external_asset_requests.php).
 *
 * The call is idempotent: if the (facility_id, uman_asset_id) unique key
 * already exists, we just update columns + write an event rather than
 * failing with a duplicate.
 *
 * @return array{ok:bool, facility_equipment_id:?int, event_ref:?string, error:?string}
 */
function frs_uman_webhook_equipment_assigned(
    PDO $pdo,
    int $facilityId,
    int $assetId,
    array $meta,
    string $assignedBy,
    string $assignedAt,
    string $eventRef,
    string $sourceFlag
): array {
    try {
        frs_ensure_facility_equipment_schema_v2($pdo);

        $code = trim((string)($meta['asset_code'] ?? ($meta['uman_asset_code'] ?? '')));
        $name = trim((string)($meta['name'] ?? ($meta['asset_name'] ?? '')));
        $type = trim((string)($meta['asset_type'] ?? ''));
        $cond = trim((string)($meta['condition_status'] ?? ''));
        $linkedReq = trim((string)($meta['linked_request_ref'] ?? ''));
        $notes    = trim((string)($meta['assignment_notes'] ?? ''));

        if ($code === '') $code = 'AUTO-' . $assetId;
        if ($name === '') $name = 'UMAN Asset #' . $assetId;

        $assignedTs = null;
        if ($assignedAt !== '') {
            $parsed = date_parse($assignedAt);
            if ($parsed !== false && empty($parsed['errors'])) {
                $assignedTs = sprintf(
                    '%04d-%02d-%02d %02d:%02d:%02d',
                    $parsed['year'], $parsed['month'], $parsed['day'],
                    $parsed['hour'] ?? 0, $parsed['minute'] ?? 0, $parsed['second'] ?? 0
                );
            }
        }
        if ($assignedTs === null) $assignedTs = date('Y-m-d H:i:s');

        // Idempotent upsert via unique key (facility_id,uman_asset_id)
        $stmtSel = $pdo->prepare('
            SELECT id FROM facility_equipment
            WHERE facility_id = ? AND uman_asset_id = ?
            LIMIT 1
        ');
        $stmtSel->execute([$facilityId, $assetId]);
        $existingId = (int)($stmtSel->fetchColumn() ?: 0);
        $feId = $existingId;

        if ($existingId > 0) {
            $stmtUp = $pdo->prepare("
                UPDATE facility_equipment SET
                    uman_asset_code = ?, asset_name = ?, asset_type = ?, condition_status = ?,
                    status = 'active', assigned_source = ?, notes = ?,
                    assigned_at = COALESCE(assigned_at, ?)
                WHERE id = ?
            ");
            $stmtUp->execute([$code, $name, $type, $cond, $sourceFlag, $notes ?: null, $assignedTs, $existingId]);
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO facility_equipment
                    (facility_id, uman_asset_id, uman_asset_code, asset_name, asset_type,
                     condition_status, status, assigned_source, notes, assigned_at)
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)
            ");
            $stmtIns->execute([$facilityId, $assetId, $code, $name, $type, $cond, $sourceFlag, $notes ?: null, $assignedTs]);
            $feId = (int)$pdo->lastInsertId();
        }

        $custodyEventRef = frs_uman_write_custody_event($pdo, $facilityId, $assetId, [
            'event_ref'          => $eventRef !== '' ? $eventRef : null,
            'event_type'         => 'UMAN_ASSIGN',
            'actor_system'       => 'UMAN',
            'actor_user_label'   => $assignedBy,
            'event_notes'        => $notes,
            'linked_request_ref' => $linkedReq !== '' ? $linkedReq : null,
        ]);

        if (function_exists('logAudit')) {
            logAudit('UMAN assignment webhook applied', 'FacilityEquipment',
                "facility {$facilityId} asset {$assetId} => {$name} ({$code}) by {$assignedBy}");
        }

        return ['ok' => true, 'facility_equipment_id' => $feId, 'event_ref' => $custodyEventRef, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'facility_equipment_id' => null, 'event_ref' => null, 'error' => $e->getMessage()];
    }
}

/**
 * UMAN → CPRF webhook: UMAN has recalled / unassigned an asset from a
 * facility (returns to warehouse, or marks it as reassigned to another
 * barangay). Idempotent: duplicate calls do not throw.
 *
 * @return array{ok:bool, rows_removed:int, event_ref:?string, error:?string}
 */
function frs_uman_webhook_equipment_unassigned(
    PDO $pdo,
    int $facilityId,
    int $assetId,
    string $reason,
    string $actor,
    string $at,
    string $eventRef
): array {
    $rowsRemoved = 0;
    try {
        frs_ensure_facility_equipment_schema_v2($pdo);

        $stmtDel = $pdo->prepare('DELETE FROM facility_equipment WHERE facility_id = ? AND uman_asset_id = ?');
        $stmtDel->execute([$facilityId, $assetId]);
        $rowsRemoved = $stmtDel->rowCount();

        $custodyEventRef = frs_uman_write_custody_event($pdo, $facilityId, $assetId, [
            'event_ref'        => $eventRef !== '' ? $eventRef : null,
            'event_type'       => 'UMAN_UNASSIGN',
            'actor_system'     => 'UMAN',
            'actor_user_label' => $actor,
            'event_notes'      => $reason,
        ]);

        if (function_exists('logAudit')) {
            logAudit('UMAN unassignment webhook applied', 'FacilityEquipment',
                "facility {$facilityId} asset {$assetId} removed: {$reason}");
        }

        return ['ok' => true, 'rows_removed' => $rowsRemoved, 'event_ref' => $custodyEventRef, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'rows_removed' => $rowsRemoved, 'event_ref' => null, 'error' => $e->getMessage()];
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
 * IMPORTANT SAFETY GATES (both Phase 2 + Phase 3 combined):
 *
 * 1. Allow-list INTERSECTION: Each submitted ID MUST be present in
 *    `frs_uman_allowed_assets_for_facility()` for this specific `$facilityId`.
 *    IDs outside that allow-list (stale checkboxes / crafted POSTs / equipment
 *    approved only for a DIFFERENT facility are silently DROPPED BEFORE the
 *    rewrite so bad data never lands in `facility_equipment`.
 *
 * 2. Lifecycle row PROTECTION (Phase 3 + 3c COA / DILG data integrity):
 *    - Rows whose status is return_pending, replacement_in_transit, archived,
 *      or decommissioned are immutable during a regular facility save.
 *    - Their custody metadata must survive the DELETE+INSERT rewrite because
 *      disabled/absent checkboxes won't appear in POST equipment_ids[].
 *
 * This closes the old pre-Phase-3 bug where every facility-form
 * silently lost all their return-pending state and auditability
 * custody every time a user saved the facility name or capacity of the facility.
 */
function frs_save_facility_equipment(PDO $pdo, int $facilityId, array $selectedIds, array $catalog): void
{
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0) {
        return;
    }

    frs_ensure_facility_equipment_schema_v2($pdo);
    frs_ensure_uman_requests_schema_v2($pdo);
    $allowList = frs_uman_allowed_assets_for_facility($pdo, $facilityId, $catalog);

    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds))));
    $selectedIds = array_values(array_filter(
        $selectedIds,
        static fn(int $id): bool => $id > 0 && isset($allowList[$id])
    ));

    // ── Phase-3 safety: snapshot immutable lifecycle rows ────────────────
    // return_pending / replacement_in_transit / archived / decommissioned rows
    // must survive the DELETE+INSERT rewrite because their checkboxes are
    // disabled (or absent) and won't appear in POST equipment_ids[].
    $protectedSnapshot = [];
    try {
        $snap = $pdo->prepare("
            SELECT uman_asset_id, uman_asset_code, asset_name, asset_type,
                   condition_status, notes, status, assigned_source,
                   assigned_by_user_id, assigned_event_ref,
                   return_requested_at, return_requested_by,
                   return_type, return_condition, return_reason,
                   accepted_return_ref, accepted_return_by,
                   linked_replacement_asset_id, disposal_ref, archived_at
            FROM facility_equipment
            WHERE facility_id = ?
              AND status IN ('return_pending','replacement_in_transit','archived','decommissioned')
        ");
        $snap->execute([$facilityId]);
        $protectedSnapshot = $snap->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $protectedSnapshot = [];
    }
    $protectedIds = [];
    foreach ($protectedSnapshot as $rw) {
        $protectedIds[(int)$rw['uman_asset_id']] = (int)$rw['uman_asset_id'];
    }

    // Rewrite
    $pdo->prepare('DELETE FROM facility_equipment WHERE facility_id = ?')->execute([$facilityId]);

    // Merge: (a) allow-listed checkbox selections (minus protected rows restored
    // from snapshot), and (b) ALL protected lifecycle rows with full metadata.
    $insert = $pdo->prepare("
        INSERT INTO facility_equipment
            (facility_id, uman_asset_id, uman_asset_code, asset_name,
             asset_type, condition_status, notes,
             status, assigned_source, assigned_by_user_id, assigned_event_ref,
             return_requested_at, return_requested_by,
             return_type, return_condition, return_reason,
             accepted_return_ref, accepted_return_by,
             linked_replacement_asset_id, disposal_ref, archived_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($selectedIds as $assetId) {
        if (isset($protectedIds[$assetId])) continue;
        $allowed = $allowList[$assetId] ?? null;
        $a = $catalog[$assetId] ?? [];
        $code = (string)($allowed['asset_code'] ?? ($a['asset_code'] ?? $a['asset_id'] ?? ('AST-' . $assetId)));
        $name = (string)($allowed['asset_name'] ?? ($a['name'] ?? 'Asset'));
        $type = (string)($allowed['asset_type'] ?? ($a['asset_type'] ?? ''));
        $cond = (string)($allowed['condition_status'] ?? ($a['condition_status'] ?? ''));
        $src  = (string)($allowed['source'] ?? 'assigned') === 'fulfilled_req' ? 'UMAN_REQUEST_FULFILLED' : 'UMAN_DIRECT';
        $insert->execute([
            $facilityId, $assetId, $code, $name, $type, $cond, null,
            'active', $src, null, null,
            null, null, null, null, null,
            null, null, null, null, null,
        ]);
    }
    foreach ($protectedSnapshot as $rw) {
        $insert->execute([
            $facilityId,
            (int)$rw['uman_asset_id'],
            (string)$rw['uman_asset_code'],
            (string)$rw['asset_name'],
            (string)($rw['asset_type'] ?? ''),
            (string)($rw['condition_status'] ?? ''),
            isset($rw['notes']) ? (string)$rw['notes'] : null,
            (string)($rw['status'] ?? 'return_pending'),
            (string)($rw['assigned_source'] ?? 'UMAN_DIRECT'),
            !empty($rw['assigned_by_user_id']) ? (int)$rw['assigned_by_user_id'] : null,
            !empty($rw['assigned_event_ref']) ? (string)$rw['assigned_event_ref'] : null,
            !empty($rw['return_requested_at']) ? (string)$rw['return_requested_at'] : null,
            !empty($rw['return_requested_by']) ? (string)$rw['return_requested_by'] : null,
            !empty($rw['return_type']) ? (string)$rw['return_type'] : null,
            !empty($rw['return_condition']) ? (string)$rw['return_condition'] : null,
            !empty($rw['return_reason']) ? (string)$rw['return_reason'] : null,
            !empty($rw['accepted_return_ref']) ? (string)$rw['accepted_return_ref'] : null,
            !empty($rw['accepted_return_by']) ? (string)$rw['accepted_return_by'] : null,
            !empty($rw['linked_replacement_asset_id']) ? (int)$rw['linked_replacement_asset_id'] : null,
            !empty($rw['disposal_ref']) ? (string)$rw['disposal_ref'] : null,
            !empty($rw['archived_at']) ? (string)$rw['archived_at'] : null,
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
            SELECT uman_asset_id, uman_asset_code, asset_name, asset_type, condition_status,
                   status, assigned_source, assigned_event_ref,
                   return_type, return_requested_at, return_requested_by,
                   return_condition, return_reason,
                   accepted_return_ref, accepted_return_by,
                   linked_replacement_asset_id, disposal_ref, archived_at
            FROM facility_equipment
            WHERE facility_id = ?
        ');
        $stmt->execute([$facilityId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aid = (int)$row['uman_asset_id'];
            if ($aid <= 0) continue;
            $allowed[$aid] = [
                'uman_asset_id'              => $aid,
                'asset_code'                 => (string)($row['uman_asset_code'] ?? ''),
                'asset_name'                 => (string)$row['asset_name'],
                'asset_type'                 => (string)($row['asset_type'] ?? ''),
                'condition_status'           => (string)($row['condition_status'] ?? ''),
                'source'                     => (string)($row['assigned_source'] ?? 'assigned'),
                'status'                     => (string)($row['status'] ?? 'active'),
                'assigned_event_ref'         => isset($row['assigned_event_ref']) ? (string)$row['assigned_event_ref'] : null,
                'return_type'                => isset($row['return_type']) ? (string)$row['return_type'] : null,
                'return_requested_at'        => isset($row['return_requested_at']) ? (string)$row['return_requested_at'] : null,
                'return_requested_by'        => isset($row['return_requested_by']) ? (string)$row['return_requested_by'] : null,
                'return_condition'           => isset($row['return_condition']) ? (string)$row['return_condition'] : null,
                'return_reason'              => isset($row['return_reason']) ? (string)$row['return_reason'] : null,
                'accepted_return_ref'        => isset($row['accepted_return_ref']) ? (string)$row['accepted_return_ref'] : null,
                'accepted_return_by'         => isset($row['accepted_return_by']) ? (string)$row['accepted_return_by'] : null,
                'linked_replacement_asset_id'=> !empty($row['linked_replacement_asset_id']) ? (int)$row['linked_replacement_asset_id'] : null,
                'disposal_ref'               => isset($row['disposal_ref']) ? (string)$row['disposal_ref'] : null,
                'archived_at'                => isset($row['archived_at']) ? (string)$row['archived_at'] : null,
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
                'uman_asset_id'       => $aid,
                'asset_code'          => (string)($row['requested_asset_code'] ?? ($catalogMatch['asset_code'] ?? '')),
                'asset_name'          => (string)($catalogMatch['name'] ?? ('UMAN Asset #' . $aid)),
                'asset_type'          => (string)($row['asset_type'] ?? ($catalogMatch['asset_type'] ?? '')),
                'condition_status'    => (string)($catalogMatch['condition_status'] ?? ''),
                'source'              => 'fulfilled_req',
                'status'              => 'active',
                'return_type'         => null,
                'return_requested_at' => null,
                'return_requested_by' => null,
                'return_condition'    => null,
                'return_reason'       => null,
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

/**
 * Phase 3 — CPRF-initiated return request (Return Slip annex, COA 2023-004).
 *
 * Three flavors (mirrors UMAN api/facility-equipment.php request_return verb):
 *   - RETURN_ONLY           : broken / surplus, send back to UMAN warehouse
 *   - RETURN_AND_REPLACE    : unit defective, UMAN will ship a replacement
 *   - RETURN_DECOMMISSION   : WMR — condemn/dispose, no return to stock
 *
 * Local effect before webhook:
 *   1. UPDATE facility_equipment.status = 'return_pending'
 *   2. SET return_requested_at / return_requested_by / return_type /
 *      return_condition / return_reason
 *   3. WRITE CPRF_RETURN_REQUESTED chain-of-custody event
 *
 * Remote effect:
 *   4. POST to UMAN /api/facility-equipment.php action=request_return
 *      (UMAN then sets cprf_custody_status = LOAN_RETURN_PENDING and returns
 *      pickup instructions + replacement asset_id if applicable)
 *
 * Returns a structured result with both the local commit status AND the
 * webhook result so callers can surface a partial-success warning when UMAN
 * is offline (the return will be reconciled on next sync).
 *
 * @return array{ok:bool, local_ok:bool, webhook_ok:bool, event_ref:?string, error:?string, pickup_instructions:?string, replacement_asset_id:?int}
 */
function frs_uman_request_return(
    PDO $pdo,
    int $facilityId,
    int $assetId,
    string $returnType,
    string $condition,
    string $reason,
    int $byUserId = 0
): array {
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0 || $assetId <= 0) {
        return ['ok' => false, 'local_ok' => false, 'webhook_ok' => false, 'event_ref' => null,
                'error' => 'Invalid facility/asset IDs or tables missing.',
                'pickup_instructions' => null, 'replacement_asset_id' => null];
    }

    if (!in_array($returnType, ['RETURN_ONLY', 'RETURN_AND_REPLACE', 'RETURN_DECOMMISSION'], true)) {
        return ['ok' => false, 'local_ok' => false, 'webhook_ok' => false, 'event_ref' => null,
                'error' => 'Invalid return_type.',
                'pickup_instructions' => null, 'replacement_asset_id' => null];
    }

    frs_ensure_facility_equipment_schema_v2($pdo);

    $localOk = false;
    $eventRef = null;
    $error = null;

    try {
        $pdo->beginTransaction();

        $row = $pdo->prepare("
            SELECT id, status FROM facility_equipment
            WHERE facility_id = ? AND uman_asset_id = ? LIMIT 1
        ");
        $row->execute([$facilityId, $assetId]);
        $existing = $row->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new RuntimeException('No active custody link for this facility + asset.');
        }
        $currentStatus = (string)($existing['status'] ?? 'active');
        if ($currentStatus === 'return_pending') {
            throw new RuntimeException('Return already pending for this asset.');
        }

        $byLabel = '';
        if ($byUserId > 0) {
            try {
                $usr = $pdo->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
                $usr->execute([$byUserId]);
                $u = $usr->fetch(PDO::FETCH_ASSOC) ?: [];
                $byLabel = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($byLabel === '') $byLabel = (string)($u['username'] ?? ('user_' . $byUserId));
            } catch (Throwable $e) { $byLabel = 'user_' . $byUserId; }
        }
        if ($byLabel === '') $byLabel = 'CPRF staff';

        $update = $pdo->prepare("
            UPDATE facility_equipment
            SET status = 'return_pending',
                return_requested_at = NOW(),
                return_requested_by = ?,
                return_type = ?,
                return_condition = ?,
                return_reason = ?
            WHERE facility_id = ? AND uman_asset_id = ?
        ");
        $update->execute([$byLabel, $returnType, $condition, $reason, $facilityId, $assetId]);

        $eventRef = frs_uman_write_custody_event($pdo, $facilityId, $assetId, [
            'event_type'        => 'CPRF_RETURN_REQUESTED',
            'actor_system'      => 'CPRF',
            'actor_user'        => $byLabel,
            'actor_user_id'     => $byUserId > 0 ? $byUserId : null,
            'occurred_at'       => date('Y-m-d H:i:s'),
            'return_type'       => $returnType,
            'return_condition'  => $condition,
            'notes'             => $reason,
        ]);

        $pdo->commit();
        $localOk = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    if (!$localOk) {
        return ['ok' => false, 'local_ok' => false, 'webhook_ok' => false, 'event_ref' => null,
                'error' => $error, 'pickup_instructions' => null, 'replacement_asset_id' => null];
    }

    $webhookResult = uman_api_post('/api/facility-equipment.php', [
        'action'        => 'request_return',
        'facility_id'   => $facilityId,
        'uman_asset_id' => $assetId,
        'return_type'   => $returnType,
        'condition'     => $condition,
        'reason'        => $reason,
        'requested_by'  => $byLabel,
        'cprf_event_ref'=> $eventRef,
    ]);

    $whOk       = !empty($webhookResult['success']) || !empty($webhookResult['ok']);
    $whError    = null;
    $pickup     = null;
    $replacementId = null;

    if (!$whOk) {
        $whError = (string)($webhookResult['error'] ?? ($webhookResult['message'] ?? 'UMAN webhook request_return failed (offline or timeout). Return will reconcile on next sync.'));
    } else {
        if (isset($webhookResult['pickup_instructions_for_uman']) && is_array($webhookResult['pickup_instructions_for_uman'])) {
            $pickup = (string)($webhookResult['pickup_instructions_for_uman']['instructions'] ??
                               ($webhookResult['pickup_instructions_for_uman']['summary'] ?? ''));
            if (isset($webhookResult['pickup_instructions_for_uman']['replacement_asset_id'])) {
                $replacementId = (int)$webhookResult['pickup_instructions_for_uman']['replacement_asset_id'];
            }
        }
        if (isset($webhookResult['replacement_asset_id'])) {
            $replacementId = (int)$webhookResult['replacement_asset_id'];
        }
    }

    $combinedError = $whError;

    return [
        'ok'                    => $localOk,
        'local_ok'              => true,
        'webhook_ok'            => $whOk,
        'event_ref'             => $eventRef,
        'error'                 => $combinedError,
        'pickup_instructions'   => $pickup,
        'replacement_asset_id'  => $replacementId,
    ];
}

/**
 * Phase 3 — Cancel a CPRF-initiated return request before UMAN accepts it.
 *
 * Reverts facility_equipment.status back to 'active' and logs a
 * CPRF_RETURN_CANCELLED custody event. Does NOT POST back to UMAN because
 * our webhook contract is push-sync (best-effort), but next time UMAN loads
 * the facility from the live CPRF status feed it will see the asset is no
 * longer in return_pending and can ignore the stale return flag on their
 * side (or we can add a cancel webhook later if needed).
 *
 * @return array{ok:bool, event_ref:?string, error:?string}
 */
function frs_uman_cancel_return(
    PDO $pdo,
    int $facilityId,
    int $assetId,
    string $cancelReason = '',
    int $byUserId = 0
): array {
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0 || $assetId <= 0) {
        return ['ok' => false, 'event_ref' => null, 'error' => 'Invalid facility/asset IDs or tables missing.'];
    }

    frs_ensure_facility_equipment_schema_v2($pdo);

    try {
        $pdo->beginTransaction();

        $row = $pdo->prepare("
            SELECT id, status FROM facility_equipment
            WHERE facility_id = ? AND uman_asset_id = ? LIMIT 1
        ");
        $row->execute([$facilityId, $assetId]);
        $existing = $row->fetch(PDO::FETCH_ASSOC);
        if (!$existing || (string)$existing['status'] !== 'return_pending') {
            throw new RuntimeException('No pending return to cancel.');
        }

        $byLabel = '';
        if ($byUserId > 0) {
            try {
                $usr = $pdo->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
                $usr->execute([$byUserId]);
                $u = $usr->fetch(PDO::FETCH_ASSOC) ?: [];
                $byLabel = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($byLabel === '') $byLabel = (string)($u['username'] ?? ('user_' . $byUserId));
            } catch (Throwable $e) { $byLabel = 'user_' . $byUserId; }
        }
        if ($byLabel === '') $byLabel = 'CPRF staff';

        $pdo->prepare("
            UPDATE facility_equipment
            SET status = 'active',
                return_requested_at = NULL,
                return_requested_by = NULL,
                return_type = NULL,
                return_condition = NULL,
                return_reason = NULL
            WHERE facility_id = ? AND uman_asset_id = ?
        ")->execute([$facilityId, $assetId]);

        $eventRef = frs_uman_write_custody_event($pdo, $facilityId, $assetId, [
            'event_type'    => 'CPRF_RETURN_CANCELLED',
            'actor_system'  => 'CPRF',
            'actor_user'    => $byLabel,
            'actor_user_id' => $byUserId > 0 ? $byUserId : null,
            'occurred_at'   => date('Y-m-d H:i:s'),
            'notes'         => $cancelReason,
        ]);

        $pdo->commit();
        return ['ok' => true, 'event_ref' => $eventRef, 'error' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'event_ref' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Phase 3 — Inbound webhook handler for UMAN → CPRF return-requested sync.
 *
 * Normally CPRF pushes TO UMAN via frs_uman_request_return(), but there are
 * two legitimate cases where UMAN pushes IN THE OPPOSITE DIRECTION:
 *   1. UMAN staff directly flagged a unit for return from their side (e.g.
 *      a recall notice on defective equipment, COA spot-audit requirement)
 *   2. UMAN's request_return endpoint triggered a LOAN_RETURN_PENDING write
 *      and their webhook delivery is "replaying" to CPRF after a retried
 *      batch (idempotent upsert-safe).
 *
 * Behavior:
 *   - Idempotent via UNIQUE (facility_id, uman_asset_id) — upserts the join
 *     row if missing (edge case where CPRF was offline when the original
 *     assign happened).
 *   - Sets status = 'return_pending' with the UMAN-provided metadata.
 *   - Logs event_type = UMAN_RETURN_TRIGGERED to distinguish from CPRF-origin
 *     returns in the COA audit log.
 *
 * @return array{ok:bool, event_ref:?string, facility_equipment_id:?int, error:?string}
 */
function frs_uman_webhook_equipment_return_requested(
    PDO $pdo,
    int $facilityId,
    int $assetId,
    string $returnType,
    string $condition,
    string $reason,
    string $triggeredBy,
    string $triggeredAt = '',
    string $eventRef = ''
): array {
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0 || $assetId <= 0) {
        return ['ok' => false, 'event_ref' => null, 'facility_equipment_id' => null,
                'error' => 'Invalid facility/asset IDs or tables missing.'];
    }

    if (!in_array($returnType, ['RETURN_ONLY', 'RETURN_AND_REPLACE', 'RETURN_DECOMMISSION'], true)) {
        $returnType = 'RETURN_ONLY';
    }

    frs_ensure_facility_equipment_schema_v2($pdo);

    try {
        $pdo->beginTransaction();

        $lookup = $pdo->prepare("
            SELECT id FROM facility_equipment WHERE facility_id = ? AND uman_asset_id = ? LIMIT 1
        ");
        $lookup->execute([$facilityId, $assetId]);
        $fid = $lookup->fetchColumn();

        if (!$fid) {
            $pdo->prepare("
                INSERT INTO facility_equipment
                    (facility_id, uman_asset_id, uman_asset_code, asset_name, asset_type, condition_status, notes,
                     status, assigned_source, assigned_by_user_id, assigned_event_ref)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'return_pending', 'UMAN_WEBHOOK_RECALL', 0, ?)
            ")->execute([
                $facilityId, $assetId,
                'AST-' . $assetId, 'Recalled asset', '', $condition, $reason,
                $eventRef ?: ('webhook-recall-' . date('YmdHis')),
            ]);
            $fid = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("
                UPDATE facility_equipment
                SET status = 'return_pending',
                    return_requested_at = NOW(),
                    return_requested_by = ?,
                    return_type = ?,
                    return_condition = ?,
                    return_reason = ?
                WHERE id = ?
            ")->execute([$triggeredBy, $returnType, $condition, $reason, (int)$fid]);
        }

        $newEventRef = frs_uman_write_custody_event($pdo, $facilityId, $assetId, [
            'event_type'        => 'UMAN_RETURN_TRIGGERED',
            'actor_system'      => 'UMAN',
            'actor_user'        => $triggeredBy,
            'occurred_at'       => $triggeredAt !== '' ? $triggeredAt : date('Y-m-d H:i:s'),
            'return_type'       => $returnType,
            'return_condition'  => $condition,
            'notes'             => $reason,
            'linked_request_ref'=> $eventRef ?: null,
        ]);

        $pdo->commit();
        return ['ok' => true, 'event_ref' => $newEventRef, 'facility_equipment_id' => (int)$fid, 'error' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'event_ref' => null, 'facility_equipment_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Phase 3c — Inbound webhook: UMAN has accepted a return (pickup or delivery verified).
 *
 * Behavior per return_type:
 *   - RETURN_ONLY            → soft-archive the join row (status=archived, archived_at=NOW),
 *                              keep row so COA auditors can query "what used to be at this facility"
 *   - RETURN_DECOMMISSION    → status=decommissioned, disposal_ref populated, archived_at=NOW
 *   - RETURN_AND_REPLACE     → archive the OLD row with linked_replacement_asset_id set; the
 *                              replacement will arrive via the separate replacement-shipped webhook
 *
 * Writes distinct event_type UMAN_RETURN_ACCEPTED or UMAN_DECOMMISSIONED so COA §6.2 queries
 * can quickly filter disposals vs normal warehouse returns.
 *
 * @return array{ok:bool, event_ref:?string, error:?string, new_status:?string}
 */
function frs_uman_webhook_equipment_return_accepted(
    PDO $pdo,
    int $facilityId,
    int $assetId,
    string $returnType,
    string $acceptedBy,
    string $acceptedAt = '',
    string $eventRef = '',
    string $disposalRef = '',
    string $linkedRequestRef = '',
    int $replacementAssetId = 0,
    string $conditionAfter = '',
    string $notes = ''
): array {
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0 || $assetId <= 0) {
        return ['ok' => false, 'event_ref' => null, 'error' => 'Invalid facility/asset IDs or tables missing.', 'new_status' => null];
    }
    if (!in_array($returnType, ['RETURN_ONLY','RETURN_AND_REPLACE','RETURN_DECOMMISSION'], true)) {
        $returnType = 'RETURN_ONLY';
    }

    frs_ensure_facility_equipment_schema_v2($pdo);

    try {
        $pdo->beginTransaction();
        $lookup = $pdo->prepare('SELECT id, status FROM facility_equipment WHERE facility_id = ? AND uman_asset_id = ? LIMIT 1');
        $lookup->execute([$facilityId, $assetId]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);

        $newStatus = $returnType === 'RETURN_DECOMMISSION' ? 'decommissioned' : 'archived';
        $evType    = $returnType === 'RETURN_DECOMMISSION' ? 'UMAN_DECOMMISSIONED' : 'UMAN_RETURN_ACCEPTED';

        $mergedNotes = trim(($notes !== '' ? $notes . ' ' : '')
            . ($conditionAfter !== '' ? ' Condition after return: ' . $conditionAfter . '.' : ''));

        if (!$row) {
            // Edge case: CPRF never saw the original assign webhook. Still accept so
            // the 7-year disposal log is complete and consistent with UMAN's books.
            $pdo->prepare("
                INSERT INTO facility_equipment
                    (facility_id, uman_asset_id, uman_asset_code, asset_name, status, return_type,
                     assigned_source, accepted_return_ref, accepted_return_by,
                     linked_replacement_asset_id, disposal_ref, archived_at)
                VALUES (?, ?, ?, ?, ?, ?, 'UMAN_WEBHOOK_RECALL', ?, ?, ?, ?, NOW())
            ")->execute([
                $facilityId, $assetId,
                'AST-' . $assetId,
                $returnType === 'RETURN_DECOMMISSION' ? 'Decommissioned asset' : 'Returned asset',
                $newStatus, $returnType,
                $eventRef ?: null, $acceptedBy,
                $replacementAssetId > 0 ? $replacementAssetId : null,
                $disposalRef !== '' ? $disposalRef : null,
            ]);
        } else {
            $pdo->prepare("
                UPDATE facility_equipment
                SET status = ?,
                    archived_at = NOW(),
                    accepted_return_ref = ?,
                    accepted_return_by = ?,
                    linked_replacement_asset_id = ?,
                    disposal_ref = ?
                WHERE id = ?
            ")->execute([
                $newStatus,
                $eventRef !== '' ? $eventRef : null,
                $acceptedBy,
                $replacementAssetId > 0 ? $replacementAssetId : null,
                $disposalRef !== '' ? $disposalRef : null,
                (int)$row['id'],
            ]);
        }

        $newEventRef = frs_uman_write_custody_event($pdo, $facilityId, $assetId, [
            'event_type'            => $evType,
            'actor_system'          => 'UMAN',
            'actor_user'            => $acceptedBy,
            'occurred_at'           => $acceptedAt !== '' ? $acceptedAt : date('Y-m-d H:i:s'),
            'return_type'           => $returnType,
            'return_condition'      => $conditionAfter,
            'notes'                 => $mergedNotes,
            'linked_request_ref'    => $linkedRequestRef !== '' ? $linkedRequestRef : null,
            'linked_disposal_ref'   => $disposalRef !== '' ? $disposalRef : null,
            'linked_asset_id'       => $replacementAssetId > 0 ? $replacementAssetId : null,
        ]);

        $pdo->commit();
        return ['ok' => true, 'event_ref' => $newEventRef, 'error' => null, 'new_status' => $newStatus];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'event_ref' => null, 'error' => $e->getMessage(), 'new_status' => null];
    }
}

/**
 * Phase 3c — Inbound webhook: UMAN has shipped a replacement unit to CPRF facility.
 *
 * Inserts a NEW facility_equipment row for replacement_asset_id with
 * status='replacement_in_transit' and links to the original (now archived)
 * asset via linked_asset_id bidirectional references. Then on CPRF side the
 * "Mark as Received" button in the facility modal will flip to active.
 *
 * Idempotent via UNIQUE (facility_id, uman_asset_id) — if the replacement
 * already exists in the join table, we UPDATE the shipment metadata only
 * instead of throwing a duplicate-key error.
 *
 * @return array{ok:bool, event_ref:?string, facility_equipment_id:?int, error:?string}
 */
function frs_uman_webhook_equipment_replacement_shipped(
    PDO $pdo,
    int $facilityId,
    int $originalAssetId,
    int $replacementAssetId,
    string $shippedBy,
    string $shippedAt = '',
    string $trackingNumber = '',
    string $eventRef = '',
    string $linkedRequestRef = '',
    string $conditionStatus = '',
    string $assetCode = '',
    string $assetName = '',
    string $assetType = '',
    string $notes = ''
): array {
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0 || $replacementAssetId <= 0) {
        return ['ok' => false, 'event_ref' => null, 'facility_equipment_id' => null, 'error' => 'Invalid facility/replacement IDs.'];
    }

    frs_ensure_facility_equipment_schema_v2($pdo);

    try {
        $pdo->beginTransaction();

        // Upsert the NEW replacement row
        $lookup = $pdo->prepare('SELECT id FROM facility_equipment WHERE facility_id = ? AND uman_asset_id = ? LIMIT 1');
        $lookup->execute([$facilityId, $replacementAssetId]);
        $existingJoinId = (int)($lookup->fetchColumn() ?: 0);

        if ($existingJoinId > 0) {
            $pdo->prepare("
                UPDATE facility_equipment
                SET status = 'replacement_in_transit',
                    assigned_source = 'UMAN_REPLACEMENT_SHIPMENT',
                    assigned_event_ref = ?,
                    linked_replacement_asset_id = ?,
                    condition_status = ?
                WHERE id = ?
            ")->execute([
                $eventRef !== '' ? $eventRef : null,
                $originalAssetId > 0 ? $originalAssetId : null,
                $conditionStatus,
                $existingJoinId,
            ]);
            $joinId = $existingJoinId;
        } else {
            $name = $assetName !== '' ? $assetName : ('Replacement Asset #' . $replacementAssetId . ($originalAssetId > 0 ? ' (for #' . $originalAssetId . ')' : ''));
            $pdo->prepare("
                INSERT INTO facility_equipment
                    (facility_id, uman_asset_id, uman_asset_code, asset_name, asset_type, condition_status,
                     status, assigned_source, assigned_event_ref, linked_replacement_asset_id,
                     return_type, notes)
                VALUES (?, ?, ?, ?, ?, ?, 'replacement_in_transit', 'UMAN_REPLACEMENT_SHIPMENT', ?, ?, 'RETURN_AND_REPLACE', ?)
            ")->execute([
                $facilityId,
                $replacementAssetId,
                $assetCode !== '' ? $assetCode : ('AST-' . $replacementAssetId),
                $name,
                $assetType,
                $conditionStatus,
                $eventRef !== '' ? $eventRef : null,
                $originalAssetId > 0 ? $originalAssetId : null,
                $notes,
            ]);
            $joinId = (int)$pdo->lastInsertId();
        }

        // Optionally mark the ORIGINAL archived/decommissioned row with a
        // back-reference to the replacement asset so COA can join return →
        // replacement pairs in one query.
        if ($originalAssetId > 0) {
            try {
                $pdo->prepare("
                    UPDATE facility_equipment
                       SET linked_replacement_asset_id = ?
                     WHERE facility_id = ? AND uman_asset_id = ? AND linked_replacement_asset_id IS NULL
                ")->execute([$replacementAssetId, $facilityId, $originalAssetId]);
            } catch (Throwable $e) { /* cosmetic only — don't roll back shipment record */ }
        }

        $newEventRef = frs_uman_write_custody_event($pdo, $facilityId, $replacementAssetId, [
            'event_type'            => 'UMAN_REPLACEMENT_SHIPPED',
            'actor_system'          => 'UMAN',
            'actor_user'            => $shippedBy,
            'occurred_at'           => $shippedAt !== '' ? $shippedAt : date('Y-m-d H:i:s'),
            'return_type'           => 'RETURN_AND_REPLACE',
            'return_condition'      => $conditionStatus,
            'notes'                 => trim(($notes ? $notes . ' ' : '') . ($trackingNumber ? 'Tracking: ' . $trackingNumber : '')),
            'linked_request_ref'    => $linkedRequestRef !== '' ? $linkedRequestRef : null,
            'linked_asset_id'       => $originalAssetId > 0 ? $originalAssetId : null,
        ]);

        $pdo->commit();
        return ['ok' => true, 'event_ref' => $newEventRef, 'facility_equipment_id' => $joinId, 'error' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'event_ref' => null, 'facility_equipment_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Phase 3c — CPRF-side action: "Mark replacement as received" in the facility
 * management edit modal. Flips status from replacement_in_transit → active
 * and writes CPRF_REPLACEMENT_RECEIVED event to close the lifecycle loop on
 * the COA audit trail.
 *
 * @return array{ok:bool, event_ref:?string, error:?string}
 */
function frs_uman_mark_replacement_received(
    PDO $pdo,
    int $facilityId,
    int $replacementAssetId,
    string $conditionOnReceipt,
    string $notes = '',
    int $byUserId = 0
): array {
    if (!frs_uman_tables_exist($pdo) || $facilityId <= 0 || $replacementAssetId <= 0) {
        return ['ok' => false, 'event_ref' => null, 'error' => 'Invalid facility/replacement IDs.'];
    }
    frs_ensure_facility_equipment_schema_v2($pdo);

    try {
        $pdo->beginTransaction();

        $lookup = $pdo->prepare("
            SELECT id, status FROM facility_equipment
             WHERE facility_id = ? AND uman_asset_id = ? LIMIT 1
        ");
        $lookup->execute([$facilityId, $replacementAssetId]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Replacement asset not on file for this facility.');
        }
        $status = (string)($row['status'] ?? '');
        if ($status !== 'replacement_in_transit') {
            throw new RuntimeException('Replacement is not in transit (current status=' . $status . ').');
        }

        $byLabel = '';
        if ($byUserId > 0) {
            try {
                $usr = $pdo->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
                $usr->execute([$byUserId]);
                $u = $usr->fetch(PDO::FETCH_ASSOC) ?: [];
                $byLabel = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($byLabel === '') $byLabel = (string)($u['username'] ?? ('user_' . $byUserId));
            } catch (Throwable $e) { $byLabel = 'user_' . $byUserId; }
        }
        if ($byLabel === '') $byLabel = 'CPRF staff';

        $pdo->prepare("
            UPDATE facility_equipment
               SET status = 'active',
                   condition_status = ?,
                   return_requested_at = NULL,
                   return_requested_by = NULL,
                   return_type = NULL,
                   return_condition = NULL,
                   return_reason = NULL
             WHERE id = ?
        ")->execute([$conditionOnReceipt, (int)$row['id']]);

        $eventRef = frs_uman_write_custody_event($pdo, $facilityId, $replacementAssetId, [
            'event_type'       => 'CPRF_REPLACEMENT_RECEIVED',
            'actor_system'     => 'CPRF',
            'actor_user'       => $byLabel,
            'actor_user_id'    => $byUserId > 0 ? $byUserId : null,
            'occurred_at'      => date('Y-m-d H:i:s'),
            'return_condition' => $conditionOnReceipt,
            'notes'            => $notes,
        ]);

        $pdo->commit();
        return ['ok' => true, 'event_ref' => $eventRef, 'error' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'event_ref' => null, 'error' => $e->getMessage()];
    }
}
