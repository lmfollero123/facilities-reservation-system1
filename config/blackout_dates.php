<?php
/**
 * Facility blackout dates (events, maintenance, LGU activities).
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/** Reason prefix used by CIMM maintenance sync (see services/cimm_api.php). */
const FRS_BLACKOUT_CIMM_PREFIX = 'CIMM Sync:';

/** Reason prefix used by IPMS infrastructure-project sync (see services/ipms_api.php). */
const FRS_BLACKOUT_IPMS_PREFIX = 'IPMS Sync:';

/**
 * True when a blackout row was created by CIMM maintenance sync (not CPRF staff).
 */
function frs_blackout_is_cimm_sync(array $row): bool
{
    $reason = trim((string)($row['reason'] ?? ''));
    return $reason !== '' && str_starts_with($reason, FRS_BLACKOUT_CIMM_PREFIX);
}

/**
 * True when a blackout row was created by IPMS infrastructure-project sync (not CPRF staff).
 */
function frs_blackout_is_ipms_sync(array $row): bool
{
    $reason = trim((string)($row['reason'] ?? ''));
    return $reason !== '' && str_starts_with($reason, FRS_BLACKOUT_IPMS_PREFIX);
}

/**
 * True for staff-created blackouts (not CIMM or IPMS sync).
 */
function frs_blackout_reason_is_cprf_manual(string $reason): bool
{
    $reason = trim($reason);
    if ($reason === '') {
        return true;
    }
    return !str_starts_with($reason, FRS_BLACKOUT_CIMM_PREFIX)
        && !str_starts_with($reason, FRS_BLACKOUT_IPMS_PREFIX);
}

/**
 * @return 'cimm'|'ipms'|'manual'
 */
function frs_blackout_source_type(array $row): string
{
    if (frs_blackout_is_cimm_sync($row)) {
        return 'cimm';
    }
    if (frs_blackout_is_ipms_sync($row)) {
        return 'ipms';
    }
    return 'manual';
}

/**
 * @return array<string, mixed>
 */
function frs_blackout_enrich_row(array $row): array
{
    $sourceType = frs_blackout_source_type($row);
    $row['source_type'] = $sourceType;

    if ($sourceType === 'cimm') {
        $row['source_label'] = 'CIMM maintenance';
        $row['is_removable'] = false;
        $reason = trim((string)($row['reason'] ?? ''));
        $row['display_reason'] = $reason !== ''
            ? trim(substr($reason, strlen(FRS_BLACKOUT_CIMM_PREFIX)))
            : 'Scheduled maintenance';
        if ($row['display_reason'] === '') {
            $row['display_reason'] = 'Scheduled maintenance';
        }
    } elseif ($sourceType === 'ipms') {
        $row['source_label'] = 'IPMS project';
        $row['is_removable'] = false;
        $reason = trim((string)($row['reason'] ?? ''));
        $row['display_reason'] = $reason !== ''
            ? trim(substr($reason, strlen(FRS_BLACKOUT_IPMS_PREFIX)))
            : 'Infrastructure project';
        if ($row['display_reason'] === '') {
            $row['display_reason'] = 'Infrastructure project';
        }
    } else {
        $row['source_label'] = 'CPRF blackout';
        $row['is_removable'] = true;
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
 * @return array{manual: int, cimm: int, ipms: int}
 */
function frs_blackout_count_by_source(array $rows): array
{
    $counts = ['manual' => 0, 'cimm' => 0, 'ipms' => 0];
    foreach ($rows as $row) {
        $key = frs_blackout_source_type($row);
        $counts[$key]++;
    }
    return $counts;
}

/**
 * @return array{manual: int, cimm: int, ipms: int, total: int}
 */
function frs_count_blackout_dates_by_source(PDO $pdo, int $year, ?int $facilityId = null): array
{
    if (!frs_blackout_table_exists($pdo)) {
        return ['manual' => 0, 'cimm' => 0, 'ipms' => 0, 'total' => 0];
    }

    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $cimmPrefix = FRS_BLACKOUT_CIMM_PREFIX . '%';
    $ipmsPrefix = FRS_BLACKOUT_IPMS_PREFIX . '%';

    $sql = 'SELECT
                SUM(CASE WHEN reason LIKE :cimm_prefix THEN 1 ELSE 0 END) AS cimm,
                SUM(CASE WHEN reason LIKE :ipms_prefix THEN 1 ELSE 0 END) AS ipms,
                SUM(CASE WHEN (reason NOT LIKE :cimm_prefix2 AND reason NOT LIKE :ipms_prefix2) OR reason IS NULL THEN 1 ELSE 0 END) AS manual
            FROM facility_blackout_dates
            WHERE blackout_date BETWEEN :start AND :end';
    $params = [
        'cimm_prefix' => $cimmPrefix,
        'cimm_prefix2' => $cimmPrefix,
        'ipms_prefix' => $ipmsPrefix,
        'ipms_prefix2' => $ipmsPrefix,
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
    $ipms = (int)($row['ipms'] ?? 0);

    return ['manual' => $manual, 'cimm' => $cimm, 'ipms' => $ipms, 'total' => $manual + $cimm + $ipms];
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
 * @return array{added: int, skipped: int, errors: list<string>, affected_reservations: int, announcement?: array{published: bool, title: ?string}}
 */
function frs_add_blackout_date(
    PDO $pdo,
    int $facilityId,
    string $date,
    string $reason,
    ?int $createdBy = null,
    bool $deferAnnouncement = false
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

        if (!$deferAnnouncement && $result['added'] > 0 && frs_blackout_reason_is_cprf_manual($reason)) {
            $announcementHelper = __DIR__ . '/blackout_announcements.php';
            if (is_file($announcementHelper)) {
                require_once $announcementHelper;
                $announcement = frs_publish_cprf_blackout_announcement($pdo, $facilityId, $date, $date, $reason);
                if (!empty($announcement['published'])) {
                    $result['announcement'] = [
                        'published' => true,
                        'title' => $announcement['title'] ?? null,
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

/**
 * @return array{added: int, skipped: int, errors: list<string>, affected_reservations: int, announcement?: array{published: bool, title: ?string}}
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
        $r = frs_add_blackout_date($pdo, $facilityId, $cursor->format('Y-m-d'), $reason, $createdBy, true);
        $total['added'] += $r['added'];
        $total['skipped'] += $r['skipped'];
        $total['affected_reservations'] += $r['affected_reservations'];
        foreach ($r['errors'] as $err) {
            $total['errors'][] = $err;
        }
        $cursor->modify('+1 day');
    }

    $total['errors'] = array_values(array_unique($total['errors']));

    if ($total['added'] > 0 && frs_blackout_reason_is_cprf_manual($reason)) {
        $announcementHelper = __DIR__ . '/blackout_announcements.php';
        if (is_file($announcementHelper)) {
            require_once $announcementHelper;
            $announcement = frs_publish_cprf_blackout_announcement(
                $pdo,
                $facilityId,
                $startDate,
                $endDate,
                $reason
            );
            if (!empty($announcement['published'])) {
                $total['announcement'] = [
                    'published' => true,
                    'title' => $announcement['title'] ?? null,
                ];
            }
        }
    }

    return $total;
}

function frs_delete_blackout_date(PDO $pdo, int $blackoutId): bool
{
    if (!frs_blackout_table_exists($pdo) || $blackoutId <= 0) {
        return false;
    }
    $row = frs_get_blackout_by_id($pdo, $blackoutId);
    if ($row && (frs_blackout_is_cimm_sync($row) || frs_blackout_is_ipms_sync($row))) {
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

/**
 * Upcoming CIMM-synced maintenance window for one facility (today or later).
 *
 * @return array{start_date: string, end_date: string, day_count: int, display_reason: string}|null
 */
function frs_facility_upcoming_cimm_maintenance(PDO $pdo, int $facilityId): ?array
{
    if (!frs_blackout_table_exists($pdo) || $facilityId <= 0) {
        return null;
    }

    $prefix = FRS_BLACKOUT_CIMM_PREFIX . '%';
    $stmt = $pdo->prepare(
        'SELECT MIN(blackout_date) AS start_date, MAX(blackout_date) AS end_date, COUNT(*) AS day_count,
                MIN(reason) AS sample_reason
         FROM facility_blackout_dates
         WHERE facility_id = ? AND blackout_date >= CURDATE() AND reason LIKE ?'
    );
    $stmt->execute([$facilityId, $prefix]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['start_date'])) {
        return null;
    }

    $enriched = frs_blackout_enrich_row([
        'reason' => (string)($row['sample_reason'] ?? ''),
    ]);

    return [
        'start_date' => (string)$row['start_date'],
        'end_date' => (string)$row['end_date'],
        'day_count' => (int)($row['day_count'] ?? 0),
        'display_reason' => (string)($enriched['display_reason'] ?? 'Scheduled maintenance'),
    ];
}

/**
 * @return array<int, array{start_date: string, end_date: string, day_count: int, display_reason: string}>
 */
function frs_facilities_upcoming_cimm_maintenance_map(PDO $pdo): array
{
    if (!frs_blackout_table_exists($pdo)) {
        return [];
    }

    $prefix = FRS_BLACKOUT_CIMM_PREFIX . '%';
    $stmt = $pdo->prepare(
        'SELECT facility_id, MIN(blackout_date) AS start_date, MAX(blackout_date) AS end_date,
                COUNT(*) AS day_count, MIN(reason) AS sample_reason
         FROM facility_blackout_dates
         WHERE blackout_date >= CURDATE() AND reason LIKE ?
         GROUP BY facility_id'
    );
    $stmt->execute([$prefix]);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fid = (int)($row['facility_id'] ?? 0);
        if ($fid <= 0 || empty($row['start_date'])) {
            continue;
        }
        $enriched = frs_blackout_enrich_row(['reason' => (string)($row['sample_reason'] ?? '')]);
        $map[$fid] = [
            'start_date' => (string)$row['start_date'],
            'end_date' => (string)$row['end_date'],
            'day_count' => (int)($row['day_count'] ?? 0),
            'display_reason' => (string)($enriched['display_reason'] ?? 'Scheduled maintenance'),
        ];
    }

    return $map;
}

/**
 * Format upcoming CIMM maintenance for UI (e.g. "Jul 22 – Aug 8, 2026").
 */
function frs_format_cimm_maintenance_window(array $window): string
{
    $start = strtotime((string)($window['start_date'] ?? ''));
    $end = strtotime((string)($window['end_date'] ?? ''));
    if (!$start) {
        return '';
    }
    $startFmt = date('M j', $start);
    if (!$end || date('Y-m-d', $end) === date('Y-m-d', $start)) {
        return $startFmt . ', ' . date('Y', $start);
    }
    $endFmt = date('M j', $end);
    if (date('Y', $start) !== date('Y', $end)) {
        $endFmt .= ', ' . date('Y', $end);
    }
    return $startFmt . ' – ' . $endFmt . ', ' . date('Y', $start);
}
