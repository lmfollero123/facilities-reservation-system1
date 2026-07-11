<?php
/**
 * Facility blackout dates (events, maintenance, LGU activities).
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/** Reason prefix used by CIMM maintenance sync (see services/cimm_api.php). */
const FRS_BLACKOUT_CIMM_PREFIX = 'CIMM Sync:';

/**
 * True when a blackout row was created by CIMM maintenance sync (not CPRF staff).
 */
function frs_blackout_is_cimm_sync(array $row): bool
{
    $reason = trim((string)($row['reason'] ?? ''));
    return $reason !== '' && str_starts_with($reason, FRS_BLACKOUT_CIMM_PREFIX);
}

/**
 * @return 'cimm'|'manual'
 */
function frs_blackout_source_type(array $row): string
{
    return frs_blackout_is_cimm_sync($row) ? 'cimm' : 'manual';
}

/**
 * @return array<string, mixed>
 */
function frs_blackout_enrich_row(array $row): array
{
    $isCimm = frs_blackout_is_cimm_sync($row);
    $row['source_type'] = $isCimm ? 'cimm' : 'manual';
    $row['source_label'] = $isCimm ? 'CIMM maintenance' : 'CPRF blackout';
    $row['is_removable'] = !$isCimm;
    if ($isCimm) {
        $reason = trim((string)($row['reason'] ?? ''));
        $row['display_reason'] = $reason !== ''
            ? trim(substr($reason, strlen(FRS_BLACKOUT_CIMM_PREFIX)))
            : 'Scheduled maintenance';
        if ($row['display_reason'] === '') {
            $row['display_reason'] = 'Scheduled maintenance';
        }
    } else {
        $row['display_reason'] = trim((string)($row['reason'] ?? '')) ?: 'Facility unavailable';
    }
    return $row;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function frs_blackout_enrich_rows(array $rows): array
{
    return array_map('frs_blackout_enrich_row', $rows);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{manual: int, cimm: int}
 */
function frs_blackout_count_by_source(array $rows): array
{
    $counts = ['manual' => 0, 'cimm' => 0];
    foreach ($rows as $row) {
        $key = frs_blackout_source_type($row);
        $counts[$key]++;
    }
    return $counts;
}

/**
 * @return array{manual: int, cimm: int, total: int}
 */
function frs_count_blackout_dates_by_source(PDO $pdo, int $year, ?int $facilityId = null): array
{
    if (!frs_blackout_table_exists($pdo)) {
        return ['manual' => 0, 'cimm' => 0, 'total' => 0];
    }

    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $prefix = FRS_BLACKOUT_CIMM_PREFIX . '%';

    $sql = 'SELECT
                SUM(CASE WHEN reason LIKE :cimm_prefix THEN 1 ELSE 0 END) AS cimm,
                SUM(CASE WHEN reason NOT LIKE :cimm_prefix2 OR reason IS NULL THEN 1 ELSE 0 END) AS manual
            FROM facility_blackout_dates
            WHERE blackout_date BETWEEN :start AND :end';
    $params = [
        'cimm_prefix' => $prefix,
        'cimm_prefix2' => $prefix,
        'start' => $start,
        'end' => $end,
    ];

    if ($facilityId !== null && $facilityId > 0) {
        $sql .= ' AND facility_id = :fid';
        $params['fid'] = $facilityId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $manual = (int)($row['manual'] ?? 0);
    $cimm = (int)($row['cimm'] ?? 0);

    return ['manual' => $manual, 'cimm' => $cimm, 'total' => $manual + $cimm];
}

function frs_blackout_table_exists(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'facility_blackout_dates'");
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function frs_list_blackout_dates(
    PDO $pdo,
    int $year,
    ?int $facilityId = null,
    int $limit = 200,
    int $offset = 0
): array {
    if (!frs_blackout_table_exists($pdo)) {
        return [];
    }

    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);

    $sql = 'SELECT b.id, b.facility_id, b.blackout_date, b.reason, b.created_at,
                   f.name AS facility_name, u.name AS created_by_name
            FROM facility_blackout_dates b
            JOIN facilities f ON f.id = b.facility_id
            LEFT JOIN users u ON u.id = b.created_by
            WHERE b.blackout_date BETWEEN :start AND :end';
    $params = ['start' => $start, 'end' => $end];

    if ($facilityId !== null && $facilityId > 0) {
        $sql .= ' AND b.facility_id = :fid';
        $params['fid'] = $facilityId;
    }

    $sql .= ' ORDER BY b.blackout_date ASC, f.name ASC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Blackouts between two dates (inclusive), ordered by date then facility name.
 *
 * @return list<array<string, mixed>>
 */
function frs_list_blackout_dates_between(
    PDO $pdo,
    string $startDate,
    string $endDate,
    ?int $facilityId = null
): array {
    if (!frs_blackout_table_exists($pdo)) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return [];
    }

    $sql = 'SELECT b.id, b.facility_id, b.blackout_date, b.reason, b.created_at,
                   f.name AS facility_name, u.name AS created_by_name
            FROM facility_blackout_dates b
            JOIN facilities f ON f.id = b.facility_id
            LEFT JOIN users u ON u.id = b.created_by
            WHERE b.blackout_date BETWEEN :start AND :end';
    $params = ['start' => $startDate, 'end' => $endDate];

    if ($facilityId !== null && $facilityId > 0) {
        $sql .= ' AND b.facility_id = :fid';
        $params['fid'] = $facilityId;
    }

    $sql .= ' ORDER BY b.blackout_date ASC, f.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function frs_count_blackout_dates(PDO $pdo, int $year, ?int $facilityId = null): int
{
    if (!frs_blackout_table_exists($pdo)) {
        return 0;
    }

    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);

    $sql = 'SELECT COUNT(*) FROM facility_blackout_dates WHERE blackout_date BETWEEN :start AND :end';
    $params = ['start' => $start, 'end' => $end];

    if ($facilityId !== null && $facilityId > 0) {
        $sql .= ' AND facility_id = :fid';
        $params['fid'] = $facilityId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * @return array{added: int, skipped: int, errors: list<string>, affected_reservations: int}
 */
function frs_add_blackout_date(
    PDO $pdo,
    int $facilityId,
    string $date,
    string $reason,
    ?int $createdBy = null
): array {
    $result = ['added' => 0, 'skipped' => 0, 'errors' => [], 'affected_reservations' => 0];

    if (!frs_blackout_table_exists($pdo)) {
        $result['errors'][] = 'Blackout dates table is not available.';
        return $result;
    }

    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $result['errors'][] = 'Invalid facility or date.';
        return $result;
    }

    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Facility unavailable';
    }
    if (strlen($reason) > 255) {
        $reason = substr($reason, 0, 255);
    }

    try {
        $exists = $pdo->prepare(
            'SELECT id FROM facility_blackout_dates WHERE facility_id = ? AND blackout_date = ? LIMIT 1'
        );
        $exists->execute([$facilityId, $date]);
        if ($exists->fetchColumn()) {
            $result['skipped']++;
            return $result;
        }

        $pdo->beginTransaction();

        // Add blackout date
        $ins = $pdo->prepare(
            'INSERT INTO facility_blackout_dates (facility_id, blackout_date, reason, created_by, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $ins->execute([$facilityId, $date, $reason, $createdBy]);
        $result['added']++;

        // Handle existing reservations on this date
        $affectedStmt = $pdo->prepare(
            'SELECT id, user_id, reservation_date, time_slot, status 
             FROM reservations 
             WHERE facility_id = ? AND reservation_date = ? AND status IN (\'approved\', \'pending\', \'pending_payment\')'
        );
        $affectedStmt->execute([$facilityId, $date]);
        $affectedReservations = $affectedStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($affectedReservations)) {
            $result['affected_reservations'] = count($affectedReservations);

            // Update affected reservations to postponed status
            $updateStmt = $pdo->prepare(
                'UPDATE reservations 
                 SET status = \'postponed\', 
                     reschedule_count = reschedule_count + 1,
                     updated_at = NOW()
                 WHERE id = ?'
            );

            $historyStmt = $pdo->prepare(
                'INSERT INTO reservation_history (reservation_id, status, note, created_at)
                 VALUES (?, \'postponed\', ?, NOW())'
            );

            foreach ($affectedReservations as $reservation) {
                $updateStmt->execute([(int)$reservation['id']]);
                $historyStmt->execute([
                    (int)$reservation['id'],
                    'Auto-postponed due to blackout date: ' . $reason
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

/**
 * @return array{added: int, skipped: int, errors: list<string>, affected_reservations: int}
 */
function frs_add_blackout_date_range(
    PDO $pdo,
    int $facilityId,
    string $startDate,
    string $endDate,
    string $reason,
    ?int $createdBy = null
): array {
    $total = ['added' => 0, 'skipped' => 0, 'errors' => [], 'affected_reservations' => 0];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $total['errors'][] = 'Invalid date range.';
        return $total;
    }

    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end || $start > $end) {
        $total['errors'][] = 'End date must be on or after start date.';
        return $total;
    }

    $days = (int)$start->diff($end)->days + 1;
    if ($days > 366) {
        $total['errors'][] = 'Date range cannot exceed 366 days.';
        return $total;
    }

    $cursor = clone $start;
    while ($cursor <= $end) {
        $r = frs_add_blackout_date($pdo, $facilityId, $cursor->format('Y-m-d'), $reason, $createdBy);
        $total['added'] += $r['added'];
        $total['skipped'] += $r['skipped'];
        $total['affected_reservations'] += $r['affected_reservations'];
        foreach ($r['errors'] as $err) {
            $total['errors'][] = $err;
        }
        $cursor->modify('+1 day');
    }

    $total['errors'] = array_values(array_unique($total['errors']));
    return $total;
}

function frs_delete_blackout_date(PDO $pdo, int $blackoutId): bool
{
    if (!frs_blackout_table_exists($pdo) || $blackoutId <= 0) {
        return false;
    }
    $row = frs_get_blackout_by_id($pdo, $blackoutId);
    if ($row && frs_blackout_is_cimm_sync($row)) {
        return false;
    }
    $stmt = $pdo->prepare('DELETE FROM facility_blackout_dates WHERE id = ?');
    return $stmt->execute([$blackoutId]);
}

/**
 * @return array<string, mixed>|null
 */
function frs_get_blackout_by_id(PDO $pdo, int $blackoutId): ?array
{
    if (!frs_blackout_table_exists($pdo) || $blackoutId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT b.*, f.name AS facility_name FROM facility_blackout_dates b
         JOIN facilities f ON f.id = b.facility_id WHERE b.id = ? LIMIT 1'
    );
    $stmt->execute([$blackoutId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
