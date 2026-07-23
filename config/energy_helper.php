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
 *   previous_reading_kwh, current_reading_kwh, optional notes/recorded_by_name)
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
        'external_ref' => 'CPRF-' . (int)$reading['id'],
    ];
    if (!empty($reading['notes'])) {
        $payload['notes'] = (string)$reading['notes'];
    }
    if (!empty($reading['recorded_by_name'])) {
        $payload['recorded_by_name'] = (string)$reading['recorded_by_name'];
    }
    return $payload;
}

function frs_energy_tables_exist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM energy_meter_readings LIMIT 1');
        $pdo->query('SELECT 1 FROM energy_facility_map LIMIT 1');
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
 *   previous_reading_kwh: float, current_reading_kwh: float, notes: ?string,
 *   recorded_by: ?int} $data
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
            (facility_id, year, month, reading_date, previous_reading_kwh, current_reading_kwh, consumption_kwh, notes, recorded_by, sync_status)
        VALUES
            (:facility_id, :year, :month, :reading_date, :previous_kwh, :current_kwh, :consumption_kwh, :notes, :recorded_by, \'pending\')
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
 * @param array{current_reading_kwh: mixed, reading_date: string, notes: ?string,
 *   previous_reading_kwh?: mixed} $data
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
    $current = (float)$data['current_reading_kwh'];

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
        SELECT r.*, u.name AS recorded_by_name
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

    return ['success' => true, 'error' => null];
}

/**
 * Pull recommendations of all statuses (updated_since watermark) into the
 * local cache, resolving CPRF facilities via the mapping table. Pulling all
 * statuses (not just 'approved') lets status changes made on the Energy
 * side (e.g. an engineer un-approving a recommendation) reach the cache;
 * the display layer is responsible for filtering to approved-only.
 *
 * @return array{success: bool, upserted: int, error: ?string}
 */
function frs_energy_pull_recommendations(PDO $pdo): array
{
    $state = frs_energy_load_sync_state($pdo);
    $query = ['status' => 'all', 'per_page' => 100];
    if (!empty($state['last_pull_at'])) {
        $query['updated_since'] = $state['last_pull_at'];
    }

    // Reverse map: energy_facility_id => CPRF facility_id
    $reverse = [];
    foreach (frs_energy_get_mapping($pdo) as $facilityId => $m) {
        $reverse[$m['energy_facility_id']] = $facilityId;
    }

    $upserted = 0;
    $maxUpdatedAt = null;
    $page = 1;
    do {
        $query['page'] = $page;
        $result = fetchEnergyRecommendations($query);
        if (!$result['success']) {
            return ['success' => false, 'upserted' => $upserted, 'error' => $result['error']];
        }
        $rows = $result['data']['data'] ?? [];
        $stmt = $pdo->prepare('
            INSERT INTO energy_recommendations_cache
                (energy_recommendation_id, energy_facility_id, facility_id, year, month,
                 generated_message, engineer_recommendation, status, expected_savings_kwh,
                 target_date, reviewed_at, fetched_at)
            VALUES
                (:remote_id, :energy_facility_id, :facility_id, :year, :month,
                 :generated_message, :engineer_recommendation, :status, :expected_savings_kwh,
                 :target_date, :reviewed_at, NOW())
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
                reviewed_at = VALUES(reviewed_at),
                fetched_at = NOW()
        ');
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $energyFacilityId = (int)($row['facility']['id'] ?? 0);
            $reviewedAt = isset($row['reviewed_at']) && $row['reviewed_at'] !== null
                ? date('Y-m-d H:i:s', strtotime((string)$row['reviewed_at']))
                : null;
            $stmt->execute([
                'remote_id' => (int)$row['id'],
                'energy_facility_id' => $energyFacilityId,
                'facility_id' => $reverse[$energyFacilityId] ?? null,
                'year' => (int)($row['year'] ?? 0),
                'month' => (int)($row['month'] ?? 0),
                'generated_message' => (string)($row['generated_message'] ?? ''),
                'engineer_recommendation' => $row['engineer_recommendation'] !== null ? (string)$row['engineer_recommendation'] : null,
                'status' => (string)($row['status'] ?? 'approved'),
                'expected_savings_kwh' => isset($row['expected_savings_kwh']) && is_numeric($row['expected_savings_kwh']) ? (float)$row['expected_savings_kwh'] : null,
                'target_date' => !empty($row['target_date']) ? (string)$row['target_date'] : null,
                'reviewed_at' => $reviewedAt,
            ]);
            $upserted++;

            if (isset($row['updated_at']) && $row['updated_at'] !== null) {
                $rowUpdatedAt = (string)$row['updated_at'];
                if ($maxUpdatedAt === null || strtotime($rowUpdatedAt) > strtotime($maxUpdatedAt)) {
                    $maxUpdatedAt = $rowUpdatedAt;
                }
            }
        }
        $hasNext = !empty($result['data']['next_page_url']);
        $page++;
    } while ($hasNext && $page <= 10);

    // Use the remote's own updated_at watermark rather than our clock, to
    // avoid missing rows on the next pull due to clock skew between this
    // server and the Energy system. If no rows were fetched, leave the
    // watermark unchanged (re-fetching the newest row next time is harmless
    // since the upsert above is idempotent).
    if ($maxUpdatedAt !== null) {
        $watermark = date('Y-m-d H:i:s', strtotime($maxUpdatedAt));
        $pdo->prepare('UPDATE energy_sync_state SET last_pull_at = :w WHERE id = 1')->execute(['w' => $watermark]);
    }

    return ['success' => true, 'upserted' => $upserted, 'error' => null];
}

/**
 * Full sync: retry pending/failed pushes, then pull recommendations.
 *
 * @return array{success: bool, pushed: int, push_failed: int, recommendations_upserted: int, errors: string[], ran_at: string}
 */
function frs_energy_run_sync(PDO $pdo): array
{
    $errors = [];
    $pushed = 0;
    $pushFailed = 0;

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
    if ($pushed > 0) {
        $pdo->prepare('UPDATE energy_sync_state SET last_push_at = NOW() WHERE id = 1')->execute();
    }

    $pull = frs_energy_pull_recommendations($pdo);
    if (!$pull['success'] && $pull['error']) {
        $errors[] = 'Recommendations pull: ' . $pull['error'];
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
        'recommendations_upserted' => $pull['upserted'],
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
    logAudit('Ran Energy integration sync', 'Energy Efficiency', "pushed={$pushed} failed={$pushFailed} recos={$pull['upserted']}");

    return $summary;
}

/** @return array{last_pull_at: ?string, last_push_at: ?string, last_summary: ?array} */
function frs_energy_load_sync_state(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT last_pull_at, last_push_at, last_summary FROM energy_sync_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }
    if ($row === false) {
        return ['last_pull_at' => null, 'last_push_at' => null, 'last_summary' => null];
    }
    $summary = null;
    if (!empty($row['last_summary'])) {
        $decoded = json_decode((string)$row['last_summary'], true);
        $summary = is_array($decoded) ? $decoded : null;
    }
    return [
        'last_pull_at' => $row['last_pull_at'] ?: null,
        'last_push_at' => $row['last_push_at'] ?: null,
        'last_summary' => $summary,
    ];
}
