<?php
/**
 * LGU Energy Efficiency integration — business logic.
 *
 * Pure computation/matching functions live at the top (unit tested); PDO-backed
 * reading/mapping/sync functions follow (exercised via the module page and
 * sync script).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once dirname(__DIR__) . '/services/energy_api.php';

/** Minimum score for an automatic facility-name match suggestion. */
const FRS_ENERGY_MATCH_THRESHOLD = 60;

/**
 * Monthly consumption from two cumulative meter values. Null when either value
 * is non-numeric/negative or the meter appears to have gone backwards.
 */
function frs_energy_compute_consumption(mixed $previous, mixed $current): ?float
{
    if (!is_numeric($previous) || !is_numeric($current)) {
        return null;
    }
    $prev = (float)$previous;
    $curr = (float)$current;
    if ($prev < 0 || $curr < 0 || $curr < $prev) {
        return null;
    }
    return round($curr - $prev, 2);
}

function frs_energy_normalize_name(string $name): string
{
    $normalized = strtolower(trim($name));
    $normalized = (string)preg_replace('/[^a-z0-9 ]+/', ' ', $normalized);
    return trim((string)preg_replace('/\s+/', ' ', $normalized));
}

/**
 * Suggest the best Energy-system facility for a CPRF facility name.
 * Scores: 100 exact (normalized), 80 substring either way, else token overlap
 * percentage. Returns ['id', 'name', 'score'] or null when nothing clears
 * FRS_ENERGY_MATCH_THRESHOLD.
 *
 * @param array<int, array<string, mixed>> $energyFacilities rows with 'id' and 'name'
 */
function frs_energy_suggest_match(string $facilityName, array $energyFacilities): ?array
{
    $target = frs_energy_normalize_name($facilityName);
    if ($target === '') {
        return null;
    }

    $best = null;
    foreach ($energyFacilities as $remote) {
        $remoteName = frs_energy_normalize_name((string)($remote['name'] ?? ''));
        if ($remoteName === '' || !isset($remote['id'])) {
            continue;
        }

        if ($remoteName === $target) {
            $score = 100;
        } elseif (str_contains($remoteName, $target) || str_contains($target, $remoteName)) {
            $score = 80;
        } else {
            $targetTokens = explode(' ', $target);
            $remoteTokens = explode(' ', $remoteName);
            $common = array_intersect($targetTokens, $remoteTokens);
            $score = (int)round((count($common) / max(count($targetTokens), 1)) * 70);
        }

        if ($score >= FRS_ENERGY_MATCH_THRESHOLD && ($best === null || $score > $best['score'])) {
            $best = ['id' => (int)$remote['id'], 'name' => (string)$remote['name'], 'score' => $score];
        }
    }

    return $best;
}

/**
 * Map a local energy_meter_readings row to the push endpoint's request body.
 *
 * @param array<string, mixed> $reading local row (id, year, month, reading_date,
 *   previous_reading_kwh, current_reading_kwh, rate_per_kwh,
 *   optional notes/recorded_by_name/recorded_by_email)
 */
function frs_energy_build_reading_payload(array $reading, int $energyFacilityId): array
{
    $payload = [
        'facility_id' => $energyFacilityId,
        'year' => (int)$reading['year'],
        'month' => (int)$reading['month'],
        'previous_reading_kwh' => (float)$reading['previous_reading_kwh'],
        'current_reading_kwh' => (float)$reading['current_reading_kwh'],
        'reading_date' => (string)$reading['reading_date'],
        'rate_per_kwh' => (float)$reading['rate_per_kwh'],
        'energy_cost' => round((float)$reading['consumption_kwh'] * (float)$reading['rate_per_kwh'], 2),
        'external_ref' => 'CPRF-' . (int)$reading['id'],
    ];
    if (!empty($reading['notes'])) {
        $payload['notes'] = (string)$reading['notes'];
    }
    if (!empty($reading['recorded_by_name'])) {
        $payload['recorded_by_name'] = (string)$reading['recorded_by_name'];
    }
    if (!empty($reading['recorded_by_email'])) {
        $payload['recorded_by_email'] = (string)$reading['recorded_by_email'];
    }
    return $payload;
}

/**
 * Transform one facility-profiles API row into an energy_profile_cache
 * upsert-ready row. Pure — no PDO — so the field-mapping logic is unit
 * tested independently of the pull orchestration in frs_energy_pull_profiles().
 *
 * @param array<string, mixed> $row one row from GET /api/v1/cprf/facility-profiles
 * @return array{facility_id: int, main_meter_name: ?string, electric_meter_no: ?string, utility_provider: ?string,
 *   contract_account_no: ?string, main_energy_source: ?string, backup_power: ?string,
 *   transformer_capacity: ?string, number_of_meters: ?int, baseline_kwh: ?float,
 *   engineer_approved: bool, baseline_locked: bool, baseline_source: ?string,
 *   energy_updated_at: ?string}|null null when the row has no usable facility_external_ref
 */
function frs_energy_parse_profile_row(array $row): ?array
{
    if (!isset($row['facility_external_ref']) || !is_numeric($row['facility_external_ref'])) {
        return null;
    }

    // strtotime() returns false (not a warning) on an unparseable or empty
    // string; date() would then throw a TypeError under strict_types since
    // it expects ?int. Treat a malformed updated_at as absent rather than
    // crashing the whole sync cycle on one bad row from the partner API.
    $energyUpdatedAt = null;
    if (isset($row['updated_at']) && $row['updated_at'] !== null) {
        $ts = strtotime((string)$row['updated_at']);
        $energyUpdatedAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    return [
        'facility_id' => (int)$row['facility_external_ref'],
        'main_meter_name' => isset($row['main_meter_name']) && $row['main_meter_name'] !== null ? (string)$row['main_meter_name'] : null,
        'electric_meter_no' => isset($row['electric_meter_no']) && $row['electric_meter_no'] !== null ? (string)$row['electric_meter_no'] : null,
        'utility_provider' => isset($row['utility_provider']) && $row['utility_provider'] !== null ? (string)$row['utility_provider'] : null,
        'contract_account_no' => isset($row['contract_account_no']) && $row['contract_account_no'] !== null ? (string)$row['contract_account_no'] : null,
        'main_energy_source' => isset($row['main_energy_source']) && $row['main_energy_source'] !== null ? (string)$row['main_energy_source'] : null,
        'backup_power' => isset($row['backup_power']) && $row['backup_power'] !== null ? (string)$row['backup_power'] : null,
        'transformer_capacity' => isset($row['transformer_capacity']) && $row['transformer_capacity'] !== null ? (string)$row['transformer_capacity'] : null,
        'number_of_meters' => isset($row['number_of_meters']) && is_numeric($row['number_of_meters']) ? (int)$row['number_of_meters'] : null,
        'baseline_kwh' => isset($row['baseline_kwh']) && is_numeric($row['baseline_kwh']) ? (float)$row['baseline_kwh'] : null,
        'engineer_approved' => (bool)($row['engineer_approved'] ?? false),
        'baseline_locked' => (bool)($row['baseline_locked'] ?? false),
        'baseline_source' => isset($row['baseline_source']) && $row['baseline_source'] !== null ? (string)$row['baseline_source'] : null,
        'energy_updated_at' => $energyUpdatedAt,
    ];
}

function frs_energy_tables_exist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM energy_meter_readings LIMIT 1');
        $pdo->query('SELECT 1 FROM energy_facility_map LIMIT 1');
        $pdo->query('SELECT 1 FROM energy_profile_cache LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<int, array{energy_facility_id: int, energy_facility_name: string}> keyed by facility_id */
function frs_energy_get_mapping(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT facility_id, energy_facility_id, energy_facility_name FROM energy_facility_map')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['facility_id']] = [
            'energy_facility_id' => (int)$row['energy_facility_id'],
            'energy_facility_name' => (string)$row['energy_facility_name'],
        ];
    }
    return $map;
}

function frs_energy_save_mapping(PDO $pdo, int $facilityId, int $energyFacilityId, string $energyFacilityName, ?int $userId): void
{
    $stmt = $pdo->prepare('
        INSERT INTO energy_facility_map (facility_id, energy_facility_id, energy_facility_name, mapped_by)
        VALUES (:facility_id, :energy_facility_id, :energy_facility_name, :mapped_by)
        ON DUPLICATE KEY UPDATE
            energy_facility_id = VALUES(energy_facility_id),
            energy_facility_name = VALUES(energy_facility_name),
            mapped_by = VALUES(mapped_by)
    ');
    $stmt->execute([
        'facility_id' => $facilityId,
        'energy_facility_id' => $energyFacilityId,
        'energy_facility_name' => $energyFacilityName,
        'mapped_by' => $userId,
    ]);

    require_once __DIR__ . '/audit.php';
    logAudit('Mapped facility to Energy system', 'Energy Efficiency', "facility_id={$facilityId} -> energy_facility_id={$energyFacilityId} ({$energyFacilityName})");
}

/**
 * Auto-map facilities by the Energy system's external_ref.
 *
 * The Energy system mirrors CPRF facilities (source='cprf') and stores the
 * CPRF facility id in external_ref; when its /api/v1/cprf/facilities rows
 * carry that field, the mapping is exact by id — no name matching and no
 * manual Facility Mapping work needed. Manual mapping stays available as a
 * fallback for Energy-side facilities without an external_ref.
 *
 * @param array<int, array<string, mixed>>|null $energyFacilities pre-fetched rows, or null to fetch
 * @return array{mapped: int, error: ?string}
 */
function frs_energy_auto_map_by_external_ref(PDO $pdo, ?array $energyFacilities = null): array
{
    if ($energyFacilities === null) {
        require_once __DIR__ . '/../services/energy_api.php';
        $fetch = fetchEnergyFacilities();
        if (!$fetch['success']) {
            return ['mapped' => 0, 'error' => $fetch['error']];
        }
        $energyFacilities = $fetch['data'];
    }

    $existing = frs_energy_get_mapping($pdo);
    $localIds = array_map('intval', $pdo->query('SELECT id FROM facilities')->fetchAll(PDO::FETCH_COLUMN));
    $localIds = array_flip($localIds);

    $mapped = 0;
    foreach ($energyFacilities as $remote) {
        if (!is_array($remote) || !isset($remote['id'])) {
            continue;
        }
        $externalRef = $remote['external_ref'] ?? null;
        if ($externalRef === null || !is_numeric($externalRef)) {
            continue; // Energy-side own facility, or an older Energy build without the column.
        }
        $facilityId = (int)$externalRef;
        if (!isset($localIds[$facilityId])) {
            continue; // stale reference to a CPRF facility that no longer exists
        }
        $remoteId = (int)$remote['id'];
        $remoteName = (string)($remote['name'] ?? '');
        $current = $existing[$facilityId] ?? null;
        if ($current !== null && $current['energy_facility_id'] === $remoteId) {
            continue; // already mapped correctly
        }
        frs_energy_save_mapping($pdo, $facilityId, $remoteId, $remoteName, null);
        $mapped++;
    }

    return ['mapped' => $mapped, 'error' => null];
}

/** Latest reading for a facility (by year, month), or null. */
function frs_energy_last_reading(PDO $pdo, int $facilityId): ?array
{
    $stmt = $pdo->prepare('
        SELECT * FROM energy_meter_readings
        WHERE facility_id = :facility_id
        ORDER BY year DESC, month DESC
        LIMIT 1
    ');
    $stmt->execute(['facility_id' => $facilityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Whether $reading is its facility's latest reading by (year, month).
 * Only the latest reading is safe to correct — earlier periods must stay
 * chronologically frozen.
 *
 * @param array<string, mixed> $reading must contain facility_id, year, month
 */
function frs_energy_is_latest_reading(PDO $pdo, array $reading): bool
{
    $last = frs_energy_last_reading($pdo, (int)($reading['facility_id'] ?? 0));
    if ($last === null) {
        return false;
    }
    return (int)$last['year'] === (int)$reading['year'] && (int)$last['month'] === (int)$reading['month'];
}

/**
 * Insert a manual reading. When a previous reading exists, its
 * current_reading_kwh overrides the submitted previous value (meter
 * continuity); the first-ever reading uses the submitted previous value.
 *
 * @param array{facility_id: int, year: int, month: int, reading_date: string,
 *   previous_reading_kwh: float, current_reading_kwh: float,
 *   rate_per_kwh: float, notes: ?string, recorded_by: ?int} $data
 * @return int new reading id
 * @throws InvalidArgumentException on invalid values or duplicate period
 */
function frs_energy_save_reading(PDO $pdo, array $data): int
{
    foreach (['previous_reading_kwh', 'current_reading_kwh'] as $key) {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            throw new InvalidArgumentException('Meter readings must be numeric values.');
        }
    }
    if (!isset($data['rate_per_kwh']) || !is_numeric($data['rate_per_kwh']) || (float)$data['rate_per_kwh'] <= 0) {
        throw new InvalidArgumentException('Rate per kWh must be greater than zero.');
    }

    $facilityId = (int)$data['facility_id'];
    $last = frs_energy_last_reading($pdo, $facilityId);
    if ($last !== null) {
        $lastPeriod = ((int)$last['year']) * 100 + (int)$last['month'];
        $newPeriod = ((int)$data['year']) * 100 + (int)$data['month'];
        if ($newPeriod <= $lastPeriod) {
            throw new InvalidArgumentException(sprintf(
                'Readings must be recorded in chronological order. The latest recorded period for this facility is %04d-%02d.',
                (int)$last['year'],
                (int)$last['month']
            ));
        }
    }
    $previous = $last !== null ? (float)$last['current_reading_kwh'] : (float)$data['previous_reading_kwh'];
    $current = (float)$data['current_reading_kwh'];

    $consumption = frs_energy_compute_consumption($previous, $current);
    if ($consumption === null) {
        throw new InvalidArgumentException('Current reading must be greater than or equal to the previous reading (' . number_format($previous, 2) . ' kWh).');
    }

    $dupe = $pdo->prepare('SELECT COUNT(*) FROM energy_meter_readings WHERE facility_id = :f AND year = :y AND month = :m');
    $dupe->execute(['f' => $facilityId, 'y' => (int)$data['year'], 'm' => (int)$data['month']]);
    if ((int)$dupe->fetchColumn() > 0) {
        throw new InvalidArgumentException('A reading for this facility and month already exists.');
    }

    $stmt = $pdo->prepare('
        INSERT INTO energy_meter_readings
            (facility_id, year, month, reading_date, previous_reading_kwh, current_reading_kwh, consumption_kwh, rate_per_kwh, notes, recorded_by, sync_status)
        VALUES
            (:facility_id, :year, :month, :reading_date, :previous_kwh, :current_kwh, :consumption_kwh, :rate_per_kwh, :notes, :recorded_by, \'pending\')
    ');
    try {
        $stmt->execute([
            'facility_id' => $facilityId,
            'year' => (int)$data['year'],
            'month' => (int)$data['month'],
            'reading_date' => (string)$data['reading_date'],
            'previous_kwh' => $previous,
            'current_kwh' => $current,
            'consumption_kwh' => $consumption,
            'rate_per_kwh' => round((float)$data['rate_per_kwh'], 2),
            'notes' => $data['notes'] !== null && $data['notes'] !== '' ? (string)$data['notes'] : null,
            'recorded_by' => $data['recorded_by'],
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) == 1062) {
            throw new InvalidArgumentException('A reading for this facility and month already exists.');
        }
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/audit.php';
    logAudit('Recorded energy meter reading', 'Energy Efficiency', "facility_id={$facilityId} {$data['year']}-{$data['month']}: {$consumption} kWh");

    return $id;
}

/**
 * Correct a mistyped meter reading. Only the facility's latest reading is
 * editable — the chronological guard in frs_energy_save_reading prevents
 * re-entering past months, so typos in older rows cannot be fixed here.
 * previous_reading_kwh may only change when this is the facility's ONLY
 * reading (no earlier period exists); otherwise the stored previous value
 * is kept, ignoring any submitted override, to preserve meter continuity.
 * Marks the row 'pending' so the partner API push re-syncs the correction
 * (an idempotent upsert on their side).
 *
 * @param array{current_reading_kwh: mixed, rate_per_kwh: mixed,
 *   reading_date: string, notes: ?string, previous_reading_kwh?: mixed} $data
 * @throws InvalidArgumentException on invalid values or when not the latest reading
 */
function frs_energy_update_reading(PDO $pdo, int $readingId, array $data): void
{
    $stmt = $pdo->prepare('SELECT * FROM energy_meter_readings WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $readingId]);
    $reading = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reading === false) {
        throw new InvalidArgumentException('Reading not found.');
    }

    if (!frs_energy_is_latest_reading($pdo, $reading)) {
        throw new InvalidArgumentException('Only the latest reading for a facility can be corrected. Earlier periods are locked for chronological integrity.');
    }

    if (!isset($data['current_reading_kwh']) || !is_numeric($data['current_reading_kwh'])) {
        throw new InvalidArgumentException('Meter readings must be numeric values.');
    }
    if (!isset($data['rate_per_kwh']) || !is_numeric($data['rate_per_kwh']) || (float)$data['rate_per_kwh'] <= 0) {
        throw new InvalidArgumentException('Rate per kWh must be greater than zero.');
    }
    $current = (float)$data['current_reading_kwh'];
    $ratePerKwh = round((float)$data['rate_per_kwh'], 2);

    $facilityId = (int)$reading['facility_id'];
    $earlier = $pdo->prepare('
        SELECT COUNT(*) FROM energy_meter_readings
        WHERE facility_id = :facility_id
          AND (year < :year1 OR (year = :year2 AND month < :month))
    ');
    $earlier->execute(['facility_id' => $facilityId, 'year1' => (int)$reading['year'], 'year2' => (int)$reading['year'], 'month' => (int)$reading['month']]);
    $isOnlyReading = (int)$earlier->fetchColumn() === 0;

    $previous = (float)$reading['previous_reading_kwh'];
    if ($isOnlyReading && array_key_exists('previous_reading_kwh', $data) && $data['previous_reading_kwh'] !== null && $data['previous_reading_kwh'] !== '') {
        if (!is_numeric($data['previous_reading_kwh'])) {
            throw new InvalidArgumentException('Meter readings must be numeric values.');
        }
        $previous = (float)$data['previous_reading_kwh'];
    }

    $consumption = frs_energy_compute_consumption($previous, $current);
    if ($consumption === null) {
        throw new InvalidArgumentException('Current reading must be greater than or equal to the previous reading (' . number_format($previous, 2) . ' kWh).');
    }

    $notes = $data['notes'] !== null && $data['notes'] !== '' ? (string)$data['notes'] : null;

    $update = $pdo->prepare('
        UPDATE energy_meter_readings
        SET previous_reading_kwh = :previous_kwh,
            current_reading_kwh = :current_kwh,
            consumption_kwh = :consumption_kwh,
            rate_per_kwh = :rate_per_kwh,
            reading_date = :reading_date,
            notes = :notes,
            sync_status = \'pending\',
            synced_at = NULL,
            sync_error = NULL
        WHERE id = :id
    ');
    $update->execute([
        'previous_kwh' => $previous,
        'current_kwh' => $current,
        'consumption_kwh' => $consumption,
        'rate_per_kwh' => $ratePerKwh,
        'reading_date' => (string)$data['reading_date'],
        'notes' => $notes,
        'id' => $readingId,
    ]);

    require_once __DIR__ . '/audit.php';
    logAudit('Updated energy meter reading', 'Energy Efficiency', "reading_id={$readingId} facility_id={$facilityId} {$reading['year']}-{$reading['month']}: {$consumption} kWh");
}

/**
 * Delete a facility's latest reading. Only allowed while it has not yet been
 * synced to the Energy system — a synced reading lives on the partner side
 * too, and deleting it locally would silently diverge from the remote
 * record; it must be corrected via frs_energy_update_reading instead, which
 * re-pushes the correction as an idempotent upsert.
 *
 * @throws InvalidArgumentException when not found, not latest, or already synced
 */
function frs_energy_delete_reading(PDO $pdo, int $readingId): void
{
    $stmt = $pdo->prepare('SELECT * FROM energy_meter_readings WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $readingId]);
    $reading = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reading === false) {
        throw new InvalidArgumentException('Reading not found.');
    }

    if (!frs_energy_is_latest_reading($pdo, $reading)) {
        throw new InvalidArgumentException('Only the latest reading for a facility can be deleted. Earlier periods are locked for chronological integrity.');
    }

    if ($reading['sync_status'] === 'synced' || $reading['external_record_id'] !== null) {
        throw new InvalidArgumentException('This reading already exists in the Energy system. Correct it via edit instead of deleting, so the correction is re-pushed.');
    }

    $delete = $pdo->prepare('DELETE FROM energy_meter_readings WHERE id = :id');
    $delete->execute(['id' => $readingId]);

    require_once __DIR__ . '/audit.php';
    logAudit('Deleted energy meter reading', 'Energy Efficiency', "reading_id={$readingId} facility_id={$reading['facility_id']} {$reading['year']}-{$reading['month']}");
}

/** Count readings still awaiting a successful push. */
function frs_energy_pending_count(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM energy_meter_readings WHERE sync_status IN ('pending','failed')")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Push one local reading to the Energy system and record the outcome.
 *
 * @return array{success: bool, error: ?string}
 */
function frs_energy_push_reading(PDO $pdo, int $readingId, ?array $mapping = null): array
{
    $stmt = $pdo->prepare('
        SELECT r.*, u.name AS recorded_by_name, u.email AS recorded_by_email
        FROM energy_meter_readings r
        LEFT JOIN users u ON u.id = r.recorded_by
        WHERE r.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $readingId]);
    $reading = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reading === false) {
        return ['success' => false, 'error' => 'Reading not found.'];
    }

    $mapping = $mapping ?? frs_energy_get_mapping($pdo);
    $facilityId = (int)$reading['facility_id'];
    if (!isset($mapping[$facilityId])) {
        $fail = $pdo->prepare("UPDATE energy_meter_readings SET sync_status = 'failed', sync_error = :err WHERE id = :id");
        $fail->execute(['err' => 'Facility is not mapped to an Energy-system facility yet.', 'id' => $readingId]);
        return ['success' => false, 'error' => 'Facility is not mapped to an Energy-system facility yet.'];
    }

    $payload = frs_energy_build_reading_payload($reading, $mapping[$facilityId]['energy_facility_id']);
    $result = pushEnergyFacilityReading($payload);

    if (!$result['success']) {
        $fail = $pdo->prepare("UPDATE energy_meter_readings SET sync_status = 'failed', sync_error = :err WHERE id = :id");
        $fail->execute(['err' => (string)($result['error'] ?? 'Unknown error'), 'id' => $readingId]);
        return ['success' => false, 'error' => $result['error']];
    }

    $remoteId = isset($result['data']['record']['id']) ? (int)$result['data']['record']['id'] : null;
    $ok = $pdo->prepare("
        UPDATE energy_meter_readings
        SET sync_status = 'synced', synced_at = NOW(), sync_error = NULL, external_record_id = :remote_id
        WHERE id = :id
    ");
    $ok->execute(['remote_id' => $remoteId, 'id' => $readingId]);

    // Update here, not only inside frs_energy_run_sync()'s batch loop —
    // a reading pushed immediately on save (the add/edit-reading flow)
    // never touches that loop, so "Last push" would otherwise never
    // reflect an immediate push, only a batch Sync Now that found
    // something already pending.
    $pdo->prepare('UPDATE energy_sync_state SET last_push_at = NOW() WHERE id = 1')->execute();

    return ['success' => true, 'error' => null];
}

/**
 * Delete cached recommendations that are no longer present in the complete
 * Energy response. Call this only after every remote page was fetched.
 *
 * @param array<int, int|string> $remoteIds
 */
function frs_energy_prune_missing_recommendations(PDO $pdo, array $remoteIds): int
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $remoteIds),
        static fn (int $id): bool => $id > 0
    )));

    if ($ids === []) {
        return (int) $pdo->exec('DELETE FROM energy_recommendations_cache');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'DELETE FROM energy_recommendations_cache WHERE energy_recommendation_id NOT IN (' . $placeholders . ')'
    );
    $stmt->execute($ids);

    return $stmt->rowCount();
}

/**
 * Resolve an Energy facility to its mapped CPRF facility. Unmapped Energy
 * facilities must never be cached or displayed in CPRF.
 *
 * @param array<int, int> $reverseMap energy_facility_id => CPRF facility_id
 */
function frs_energy_resolve_mapped_facility_id(array $reverseMap, int $energyFacilityId): ?int
{
    if ($energyFacilityId <= 0 || !isset($reverseMap[$energyFacilityId])) {
        return null;
    }

    $facilityId = (int) $reverseMap[$energyFacilityId];

    return $facilityId > 0 ? $facilityId : null;
}

/**
 * Validate and normalize a Facilities-side recommendation progress update.
 *
 * @return array{implementation_status: string, actual_savings_kwh: ?float, implementation_notes: ?string}
 */
function frs_energy_parse_recommendation_progress(array $input): array
{
    $status = trim((string)($input['implementation_status'] ?? ''));
    if (!in_array($status, ['pending', 'in_progress', 'implemented'], true)) {
        throw new InvalidArgumentException('Choose a valid implementation status.');
    }

    $actual = $input['actual_savings_kwh'] ?? null;
    if ($actual === '') {
        $actual = null;
    }
    if ($actual !== null && (!is_numeric($actual) || (float)$actual < 0)) {
        throw new InvalidArgumentException('Actual savings must be zero or greater.');
    }

    $notes = trim((string)($input['implementation_notes'] ?? ''));
    if (strlen($notes) > 5000) {
        throw new InvalidArgumentException('Implementation notes may not exceed 5,000 characters.');
    }

    return [
        'implementation_status' => $status,
        'actual_savings_kwh' => $actual !== null ? round((float)$actual, 2) : null,
        'implementation_notes' => $notes !== '' ? $notes : null,
    ];
}

/**
 * Push one cached recommendation's implementation progress to Energy, then
 * mirror the confirmed remote state locally.
 *
 * @return array{success: bool, error: ?string}
 */
function frs_energy_push_recommendation_progress(
    PDO $pdo,
    int $cacheId,
    array $input,
    ?int $updatedBy
): array {
    $payload = frs_energy_parse_recommendation_progress($input);
    $stmt = $pdo->prepare('
        SELECT energy_recommendation_id, status, implementation_status
        FROM energy_recommendations_cache
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $cacheId]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached === false) {
        return ['success' => false, 'error' => 'Recommendation not found.'];
    }
    if ((string)$cached['status'] !== 'approved') {
        return ['success' => false, 'error' => 'Only approved recommendations can be implemented.'];
    }
    if ((string)($cached['implementation_status'] ?? 'pending') === 'verified') {
        return ['success' => false, 'error' => 'This recommendation is already verified by Energy.'];
    }

    $result = updateEnergyRecommendationImplementation(
        (int)$cached['energy_recommendation_id'],
        $payload
    );
    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error']];
    }

    $remote = $result['data']['recommendation'] ?? [];
    $implementedAt = null;
    if (!empty($remote['implemented_at'])) {
        $timestamp = strtotime((string)$remote['implemented_at']);
        $implementedAt = $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    $update = $pdo->prepare('
        UPDATE energy_recommendations_cache
        SET implementation_status = :implementation_status,
            actual_savings_kwh = :actual_savings_kwh,
            implementation_notes = :implementation_notes,
            implemented_at = :implemented_at,
            implementation_updated_by = :implementation_updated_by,
            implementation_updated_at = NOW(),
            fetched_at = NOW()
        WHERE id = :id
    ');
    $update->execute([
        'implementation_status' => (string)($remote['implementation_status'] ?? $payload['implementation_status']),
        'actual_savings_kwh' => $remote['actual_savings_kwh'] ?? $payload['actual_savings_kwh'],
        'implementation_notes' => $remote['implementation_notes'] ?? $payload['implementation_notes'],
        'implemented_at' => $implementedAt,
        'implementation_updated_by' => $updatedBy,
        'id' => $cacheId,
    ]);

    return ['success' => true, 'error' => null];
}

/**
 * Pull the complete approved recommendation list into the local cache,
 * resolving CPRF facilities via the mapping table. Draft, dismissed, and
 * for-review recommendations never cross into Facilities. Because every
 * approved page is reconciled, revoked or deleted rows are removed locally.
 *
 * @return array{success: bool, upserted: int, deleted: int, error: ?string}
 */
function frs_energy_pull_recommendations(PDO $pdo): array
{
    $query = ['status' => 'approved', 'per_page' => 100];

    // Reverse map: energy_facility_id => CPRF facility_id
    $reverse = [];
    foreach (frs_energy_get_mapping($pdo) as $facilityId => $m) {
        $reverse[$m['energy_facility_id']] = $facilityId;
    }

    $upserted = 0;
    $remoteIds = [];
    $page = 1;
    do {
        $query['page'] = $page;
        $result = fetchEnergyRecommendations($query);
        if (!$result['success']) {
            return ['success' => false, 'upserted' => $upserted, 'deleted' => 0, 'error' => $result['error']];
        }
        $rows = $result['data']['data'] ?? [];
        $stmt = $pdo->prepare('
            INSERT INTO energy_recommendations_cache
                (energy_recommendation_id, energy_facility_id, facility_id, year, month,
                 generated_message, engineer_recommendation, status, expected_savings_kwh,
                 target_date, implementation_status, actual_savings_kwh,
                 implementation_notes, implemented_at, verified_at, reviewed_at, fetched_at)
            VALUES
                (:remote_id, :energy_facility_id, :facility_id, :year, :month,
                 :generated_message, :engineer_recommendation, :status, :expected_savings_kwh,
                 :target_date, :implementation_status, :actual_savings_kwh,
                 :implementation_notes, :implemented_at, :verified_at, :reviewed_at, NOW())
            ON DUPLICATE KEY UPDATE
                energy_facility_id = VALUES(energy_facility_id),
                facility_id = VALUES(facility_id),
                year = VALUES(year),
                month = VALUES(month),
                generated_message = VALUES(generated_message),
                engineer_recommendation = VALUES(engineer_recommendation),
                status = VALUES(status),
                expected_savings_kwh = VALUES(expected_savings_kwh),
                target_date = VALUES(target_date),
                implementation_status = VALUES(implementation_status),
                actual_savings_kwh = VALUES(actual_savings_kwh),
                implementation_notes = VALUES(implementation_notes),
                implemented_at = VALUES(implemented_at),
                verified_at = VALUES(verified_at),
                reviewed_at = VALUES(reviewed_at),
                fetched_at = NOW()
        ');
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $energyFacilityId = (int)($row['facility']['id'] ?? 0);
            $localFacilityId = frs_energy_resolve_mapped_facility_id($reverse, $energyFacilityId);
            if ($localFacilityId === null) {
                continue;
            }
            $remoteIds[] = (int)$row['id'];
            $reviewedAt = isset($row['reviewed_at']) && $row['reviewed_at'] !== null
                ? date('Y-m-d H:i:s', strtotime((string)$row['reviewed_at']))
                : null;
            $implementedAt = !empty($row['implemented_at']) && strtotime((string)$row['implemented_at']) !== false
                ? date('Y-m-d H:i:s', strtotime((string)$row['implemented_at']))
                : null;
            $verifiedAt = !empty($row['verified_at']) && strtotime((string)$row['verified_at']) !== false
                ? date('Y-m-d H:i:s', strtotime((string)$row['verified_at']))
                : null;
            $stmt->execute([
                'remote_id' => (int)$row['id'],
                'energy_facility_id' => $energyFacilityId,
                'facility_id' => $localFacilityId,
                'year' => (int)($row['year'] ?? 0),
                'month' => (int)($row['month'] ?? 0),
                'generated_message' => (string)($row['generated_message'] ?? ''),
                'engineer_recommendation' => $row['engineer_recommendation'] !== null ? (string)$row['engineer_recommendation'] : null,
                'status' => (string)($row['status'] ?? 'approved'),
                'expected_savings_kwh' => isset($row['expected_savings_kwh']) && is_numeric($row['expected_savings_kwh']) ? (float)$row['expected_savings_kwh'] : null,
                'target_date' => !empty($row['target_date']) ? (string)$row['target_date'] : null,
                'implementation_status' => (string)($row['implementation_status'] ?? 'pending'),
                'actual_savings_kwh' => isset($row['actual_savings_kwh']) && is_numeric($row['actual_savings_kwh']) ? (float)$row['actual_savings_kwh'] : null,
                'implementation_notes' => isset($row['implementation_notes']) && $row['implementation_notes'] !== null ? (string)$row['implementation_notes'] : null,
                'implemented_at' => $implementedAt,
                'verified_at' => $verifiedAt,
                'reviewed_at' => $reviewedAt,
            ]);
            $upserted++;
        }
        $hasNext = !empty($result['data']['next_page_url']);
        $page++;
    } while ($hasNext && $page <= 10);

    if ($hasNext) {
        return [
            'success' => false,
            'upserted' => $upserted,
            'deleted' => 0,
            'error' => 'Recommendation pull exceeded the 1,000-row safety limit; local deletions were skipped.',
        ];
    }

    // Prune only after a complete, successful response. This prevents an API
    // outage or a truncated page set from deleting valid local cache rows.
    $deleted = frs_energy_prune_missing_recommendations($pdo, $remoteIds);
    $pdo->prepare('UPDATE energy_sync_state SET last_pull_at = NOW() WHERE id = 1')->execute();

    return ['success' => true, 'upserted' => $upserted, 'deleted' => $deleted, 'error' => null];
}

/**
 * Pull facility energy profiles (updated_since watermark) into the local
 * cache. Unlike frs_energy_pull_recommendations(), no reverse facility-id
 * mapping lookup is needed: the Energy endpoint already scopes its response
 * to CPRF-mapped facilities and returns facility_external_ref, which IS the
 * CPRF facility_id directly.
 *
 * @return array{success: bool, upserted: int, error: ?string}
 */
function frs_energy_pull_profiles(PDO $pdo): array
{
    // Always refresh the complete profile feed. Several values returned by
    // Energy are computed from related records (for example,
    // engineer_approved comes from the selected main meter), so the payload
    // can legitimately change after an Energy-side code deployment without
    // the facility/profile updated_at changing. An updated_since watermark
    // would permanently leave those cached values stale in CPRF.
    //
    // The endpoint is paginated and this integration already caps the pull
    // at 10 pages of 100 rows, so a full idempotent upsert remains bounded.
    $query = ['per_page' => 100];

    $upserted = 0;
    $maxUpdatedAt = null;
    $page = 1;
    do {
        $query['page'] = $page;
        $result = fetchEnergyFacilityProfiles($query);
        if (!$result['success']) {
            return ['success' => false, 'upserted' => $upserted, 'error' => $result['error']];
        }
        $rows = $result['data']['data'] ?? [];
        $stmt = $pdo->prepare('
            INSERT INTO energy_profile_cache
                (facility_id, main_meter_name, electric_meter_no, utility_provider, contract_account_no,
                 main_energy_source, backup_power, transformer_capacity, number_of_meters,
                 baseline_kwh, engineer_approved, baseline_locked, baseline_source,
                 energy_updated_at, synced_at)
            VALUES
                (:facility_id, :main_meter_name, :electric_meter_no, :utility_provider, :contract_account_no,
                 :main_energy_source, :backup_power, :transformer_capacity, :number_of_meters,
                 :baseline_kwh, :engineer_approved, :baseline_locked, :baseline_source,
                 :energy_updated_at, NOW())
            ON DUPLICATE KEY UPDATE
                main_meter_name = VALUES(main_meter_name),
                electric_meter_no = VALUES(electric_meter_no),
                utility_provider = VALUES(utility_provider),
                contract_account_no = VALUES(contract_account_no),
                main_energy_source = VALUES(main_energy_source),
                backup_power = VALUES(backup_power),
                transformer_capacity = VALUES(transformer_capacity),
                number_of_meters = VALUES(number_of_meters),
                baseline_kwh = VALUES(baseline_kwh),
                engineer_approved = VALUES(engineer_approved),
                baseline_locked = VALUES(baseline_locked),
                baseline_source = VALUES(baseline_source),
                energy_updated_at = VALUES(energy_updated_at),
                synced_at = NOW()
        ');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = frs_energy_parse_profile_row($row);
            if ($parsed === null) {
                continue;
            }
            $stmt->execute([
                'facility_id' => $parsed['facility_id'],
                'main_meter_name' => $parsed['main_meter_name'],
                'electric_meter_no' => $parsed['electric_meter_no'],
                'utility_provider' => $parsed['utility_provider'],
                'contract_account_no' => $parsed['contract_account_no'],
                'main_energy_source' => $parsed['main_energy_source'],
                'backup_power' => $parsed['backup_power'],
                'transformer_capacity' => $parsed['transformer_capacity'],
                'number_of_meters' => $parsed['number_of_meters'],
                'baseline_kwh' => $parsed['baseline_kwh'],
                'engineer_approved' => $parsed['engineer_approved'] ? 1 : 0,
                'baseline_locked' => $parsed['baseline_locked'] ? 1 : 0,
                'baseline_source' => $parsed['baseline_source'],
                'energy_updated_at' => $parsed['energy_updated_at'],
            ]);
            $upserted++;

            if ($parsed['energy_updated_at'] !== null) {
                if ($maxUpdatedAt === null || strtotime($parsed['energy_updated_at']) > strtotime($maxUpdatedAt)) {
                    $maxUpdatedAt = $parsed['energy_updated_at'];
                }
            }
        }
        $hasNext = !empty($result['data']['next_page_url']);
        $page++;
    } while ($hasNext && $page <= 10);

    if ($maxUpdatedAt !== null) {
        $pdo->prepare('UPDATE energy_sync_state SET last_profile_pull_at = :w WHERE id = 1')->execute(['w' => $maxUpdatedAt]);
    }

    return ['success' => true, 'upserted' => $upserted, 'error' => null];
}

/**
 * Re-resolve facility_id for cached recommendations left NULL because their
 * Energy-side facility wasn't mapped yet at pull time. frs_energy_pull_recommendations()
 * only re-fetches rows whose remote updated_at has moved past the last
 * watermark, so a recommendation cached before its facility was mapped can
 * stay orphaned (facility_id NULL, invisible in the Recommendations tab)
 * indefinitely even after the mapping is added later — this closes that gap
 * on every sync, independent of the incremental pull window.
 *
 * @return int number of cache rows fixed
 */
function frs_energy_backfill_recommendation_facility_ids(PDO $pdo): int
{
    $stmt = $pdo->prepare('
        UPDATE energy_recommendations_cache c
        JOIN energy_facility_map m ON m.energy_facility_id = c.energy_facility_id
        SET c.facility_id = m.facility_id
        WHERE c.facility_id IS NULL
    ');
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Full sync: retry pending/failed pushes, then pull recommendations.
 *
 * @return array{success: bool, pushed: int, push_failed: int, recommendations_upserted: int, recommendations_deleted: int, profiles_upserted: int, errors: string[], ran_at: string}
 */
function frs_energy_run_sync(PDO $pdo): array
{
    $errors = [];
    $pushed = 0;
    $pushFailed = 0;

    // Auto-map by external_ref first so readings for newly mirrored
    // facilities can push within the same run. Non-fatal: when the Energy
    // API is unreachable the push/pull steps below surface the error.
    $autoMap = frs_energy_auto_map_by_external_ref($pdo);

    // Fix any previously orphaned recommendation rows now that the mapping
    // table is current (see function doc above).
    $backfilled = frs_energy_backfill_recommendation_facility_ids($pdo);

    $pending = $pdo->query("SELECT id FROM energy_meter_readings WHERE sync_status IN ('pending','failed') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $mapping = frs_energy_get_mapping($pdo);
    foreach ($pending as $readingId) {
        $result = frs_energy_push_reading($pdo, (int)$readingId, $mapping);
        if ($result['success']) {
            $pushed++;
        } else {
            $pushFailed++;
            if ($result['error']) {
                $errors[] = 'Reading #' . $readingId . ': ' . $result['error'];
            }
        }
    }
    // last_push_at is now updated inside frs_energy_push_reading() itself
    // on every successful push, so no separate update is needed here.

    $pull = frs_energy_pull_recommendations($pdo);
    if (!$pull['success'] && $pull['error']) {
        $errors[] = 'Recommendations pull: ' . $pull['error'];
    }

    $profilePull = frs_energy_pull_profiles($pdo);
    if (!$profilePull['success'] && $profilePull['error']) {
        $errors[] = 'Facility profiles pull: ' . $profilePull['error'];
    }

    // Read the previous run's failure streak before it's overwritten below,
    // so we can detect the run that crosses the "keeps failing" threshold.
    $previousSummary = frs_energy_load_sync_state($pdo)['last_summary'];
    $previousFailures = (int)($previousSummary['consecutive_failures'] ?? 0);
    $consecutiveFailures = $errors !== [] ? $previousFailures + 1 : 0;

    $summary = [
        'success' => $errors === [],
        'pushed' => $pushed,
        'push_failed' => $pushFailed,
        'auto_mapped' => $autoMap['mapped'],
        'recommendations_backfilled' => $backfilled,
        'recommendations_upserted' => $pull['upserted'],
        'recommendations_deleted' => $pull['deleted'],
        'profiles_upserted' => $profilePull['upserted'],
        'errors' => $errors,
        'ran_at' => date('c'),
        'consecutive_failures' => $consecutiveFailures,
    ];

    // Notify Admins the moment sync crosses 3 consecutive failing runs (not
    // on every failure afterward, to avoid spamming on run 4, 5, ...).
    if ($consecutiveFailures === 3) {
        try {
            require_once __DIR__ . '/notifications.php';
            $firstError = $errors !== [] ? $errors[0] : 'Unknown error';
            if (strlen($firstError) > 200) {
                $firstError = substr($firstError, 0, 197) . '...';
            }
            $link = function_exists('base_path') ? base_path() . '/dashboard/energy-efficiency' : '/dashboard/energy-efficiency';
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'Admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                createNotification(
                    (int)$adminId,
                    'system',
                    'Energy sync failing',
                    'The Energy Efficiency sync has failed 3 times in a row. First issue: ' . $firstError,
                    $link
                );
            }
        } catch (Throwable $notifyEx) {
            error_log('Energy sync failure notification failed: ' . $notifyEx->getMessage());
        }
    }

    $save = $pdo->prepare('UPDATE energy_sync_state SET last_summary = :summary WHERE id = 1');
    $save->execute(['summary' => json_encode($summary)]);

    require_once __DIR__ . '/audit.php';
    logAudit('Ran Energy integration sync', 'Energy Efficiency', "pushed={$pushed} failed={$pushFailed} recos={$pull['upserted']} backfilled={$backfilled} profiles={$profilePull['upserted']}");

    return $summary;
}

/** @return array{last_pull_at: ?string, last_push_at: ?string, last_profile_pull_at: ?string, last_summary: ?array} */
function frs_energy_load_sync_state(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT last_pull_at, last_push_at, last_profile_pull_at, last_summary FROM energy_sync_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }
    if ($row === false) {
        return ['last_pull_at' => null, 'last_push_at' => null, 'last_profile_pull_at' => null, 'last_summary' => null];
    }
    $summary = null;
    if (!empty($row['last_summary'])) {
        $decoded = json_decode((string)$row['last_summary'], true);
        $summary = is_array($decoded) ? $decoded : null;
    }
    return [
        'last_pull_at' => $row['last_pull_at'] ?: null,
        'last_push_at' => $row['last_push_at'] ?: null,
        'last_profile_pull_at' => $row['last_profile_pull_at'] ?: null,
        'last_summary' => $summary,
    ];
}
