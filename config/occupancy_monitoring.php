<?php
/**
 * Operational occupancy (bookings + check-in/out + staff override).
 * Not sensor-based headcount — see UI labels.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/time_helpers.php';

/** Minutes before slot start when Check In is allowed */
const FRS_OCCUPANCY_CHECKIN_GRACE_BEFORE = 15;

/** Minutes after slot start with no Check In → no_show_risk */
const FRS_OCCUPANCY_NO_SHOW_GRACE_AFTER = 30;

const FRS_FACILITY_LIVE_AUTO = 'auto';
const FRS_FACILITY_LIVE_VACANT = 'vacant';
const FRS_FACILITY_LIVE_IN_USE = 'in_use';
const FRS_FACILITY_LIVE_EVENT_ENDING = 'event_ending';
const FRS_FACILITY_LIVE_CLOSED = 'closed';

const FRS_RES_STATE_UPCOMING = 'upcoming';
const FRS_RES_STATE_SCHEDULED = 'scheduled';
const FRS_RES_STATE_NO_SHOW_RISK = 'no_show_risk';
const FRS_RES_STATE_CHECKED_IN = 'checked_in';
const FRS_RES_STATE_CHECKED_OUT = 'checked_out';
const FRS_RES_STATE_COMPLETED = 'completed';
const FRS_RES_STATE_NO_SHOW = 'no_show';

function frs_occupancy_table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function frs_occupancy_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{start: DateTime, end: DateTime}|null
 */
function frs_reservation_slot_window(string $reservationDate, string $timeSlot, ?DateTimeZone $tz = null): ?array
{
    $parsed = parseTimeSlot($timeSlot);
    if (!$parsed) {
        return null;
    }
    $tz = $tz ?? frs_app_timezone();
    $start = DateTime::createFromFormat('Y-m-d H:i', $reservationDate . ' ' . $parsed['start']->format('H:i'), $tz);
    $end = DateTime::createFromFormat('Y-m-d H:i', $reservationDate . ' ' . $parsed['end']->format('H:i'), $tz);
    if (!$start || !$end) {
        return null;
    }
    return ['start' => $start, 'end' => $end];
}

/**
 * @param array<string, mixed> $reservation row with reservation_date, time_slot
 * @param array<string, mixed>|null $attendance row with time_in_at, time_out_at
 */
function frs_compute_reservation_operational_state(
    array $reservation,
    ?array $attendance,
    ?DateTimeInterface $now = null
): string {
    $now = $now instanceof DateTimeInterface
        ? DateTime::createFromInterface($now)
        : new DateTime('now', frs_app_timezone());

    $date = (string)($reservation['reservation_date'] ?? '');
    $slot = (string)($reservation['time_slot'] ?? '');
    $window = frs_reservation_slot_window($date, $slot);
    if (!$window) {
        return FRS_RES_STATE_UPCOMING;
    }

    $start = $window['start'];
    $end = $window['end'];
    $checkinOpen = (clone $start)->modify('-' . FRS_OCCUPANCY_CHECKIN_GRACE_BEFORE . ' minutes');
    $noShowAfter = (clone $start)->modify('+' . FRS_OCCUPANCY_NO_SHOW_GRACE_AFTER . ' minutes');

    $timeIn = !empty($attendance['time_in_at']);
    $timeOut = !empty($attendance['time_out_at']);

    if ($timeOut) {
        return $now > $end ? FRS_RES_STATE_COMPLETED : FRS_RES_STATE_CHECKED_OUT;
    }
    if ($timeIn) {
        return FRS_RES_STATE_CHECKED_IN;
    }

    if ($now < $checkinOpen) {
        return FRS_RES_STATE_UPCOMING;
    }
    if ($now <= $end) {
        if ($now >= $noShowAfter) {
            return FRS_RES_STATE_NO_SHOW_RISK;
        }
        return FRS_RES_STATE_SCHEDULED;
    }

    return FRS_RES_STATE_NO_SHOW;
}

/**
 * Human label + badge color for reservation operational state.
 *
 * @return array{label: string, color: string, bg: string}
 */
function frs_reservation_state_display(string $state): array
{
    return match ($state) {
        FRS_RES_STATE_CHECKED_IN => ['label' => 'On-site (checked in)', 'color' => '#14532d', 'bg' => '#dcfce7'],
        FRS_RES_STATE_CHECKED_OUT => ['label' => 'Checked out', 'color' => '#1e40af', 'bg' => '#dbeafe'],
        FRS_RES_STATE_COMPLETED => ['label' => 'Completed', 'color' => '#334155', 'bg' => '#e2e8f0'],
        FRS_RES_STATE_NO_SHOW_RISK => ['label' => 'No-show risk', 'color' => '#92400e', 'bg' => '#fef3c7'],
        FRS_RES_STATE_NO_SHOW => ['label' => 'No-show', 'color' => '#991b1b', 'bg' => '#fee2e2'],
        FRS_RES_STATE_SCHEDULED => ['label' => 'Booked (in slot)', 'color' => '#1d4ed8', 'bg' => '#eff6ff'],
        default => ['label' => 'Upcoming', 'color' => '#475569', 'bg' => '#f1f5f9'],
    };
}

/**
 * @return array{label: string, color: string, bg: string, key: string}
 */
function frs_facility_aggregate_state_display(string $key): array
{
    return match ($key) {
        'staff_in_use' => ['key' => 'staff_in_use', 'label' => 'In use (staff)', 'color' => '#14532d', 'bg' => '#dcfce7'],
        'staff_vacant' => ['key' => 'staff_vacant', 'label' => 'Vacant (staff)', 'color' => '#047857', 'bg' => '#ecfdf5'],
        'staff_closed' => ['key' => 'staff_closed', 'label' => 'Closed (staff)', 'color' => '#475569', 'bg' => '#e2e8f0'],
        'staff_event_ending' => ['key' => 'staff_event_ending', 'label' => 'Ending soon (staff)', 'color' => '#92400e', 'bg' => '#fef3c7'],
        'checked_in' => ['key' => 'checked_in', 'label' => 'On-site (reported)', 'color' => '#14532d', 'bg' => '#dcfce7'],
        'booked' => ['key' => 'booked', 'label' => 'Booked (in slot)', 'color' => '#1d4ed8', 'bg' => '#eff6ff'],
        'no_show_risk' => ['key' => 'no_show_risk', 'label' => 'No-show risk', 'color' => '#92400e', 'bg' => '#fef3c7'],
        default => ['key' => 'available', 'label' => 'Available', 'color' => '#047857', 'bg' => '#ecfdf5'],
    };
}

/**
 * Build live operational snapshot for today.
 *
 * @return array<string, mixed>
 */
function frs_build_operational_occupancy_snapshot(PDO $pdo, ?DateTimeInterface $now = null): array
{
    $now = $now instanceof DateTimeInterface
        ? DateTime::createFromInterface($now)
        : new DateTime('now', frs_app_timezone());

    $today = $now->format('Y-m-d');
    $hasAttendance = frs_occupancy_table_exists($pdo, 'reservation_attendance');
    $hasLiveStatus = frs_occupancy_table_exists($pdo, 'facility_live_status');

    $facilitiesStmt = $pdo->query(
        'SELECT id, name, status, image_path FROM facilities WHERE status != "deleted" ORDER BY name'
    );
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

    $liveMap = [];
    if ($hasLiveStatus) {
        foreach ($pdo->query('SELECT facility_id, status, note, updated_at FROM facility_live_status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $liveMap[(int)$row['facility_id']] = $row;
        }
    }

    $resStmt = $pdo->prepare(
        'SELECT r.id, r.facility_id, r.user_id, r.reservation_date, r.time_slot, r.status, r.expected_attendees,
                f.name AS facility_name, u.name AS requester_name
         FROM reservations r
         JOIN facilities f ON f.id = r.facility_id
         JOIN users u ON u.id = r.user_id
         WHERE r.reservation_date = :today AND r.status = "approved"
         ORDER BY f.name, r.time_slot'
    );
    $resStmt->execute(['today' => $today]);
    $reservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);

    $attMap = [];
    if ($hasAttendance && $reservations !== []) {
        $ids = array_map(static fn($r) => (int)$r['id'], $reservations);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $attStmt = $pdo->prepare(
            "SELECT reservation_id, time_in_at, time_out_at FROM reservation_attendance WHERE reservation_id IN ($in)"
        );
        $attStmt->execute($ids);
        foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $attMap[(int)$a['reservation_id']] = $a;
        }
    }

    $byFacility = [];
    $facilityIndex = 0;
    foreach ($facilities as $f) {
        $fid = (int)$f['id'];
        $byFacility[$fid] = [
            'facility_id' => $fid,
            'facility_name' => (string)$f['name'],
            'facility_status' => (string)$f['status'],
            'image_url' => frs_facility_display_image_url($f['image_path'] ?? null, $facilityIndex),
            'staff_live' => $liveMap[$fid] ?? null,
            'aggregate_state' => 'available',
            'aggregate_display' => frs_facility_aggregate_state_display('available'),
            'reservations_today' => [],
            'counts' => [
                'checked_in' => 0,
                'booked' => 0,
                'no_show_risk' => 0,
            ],
        ];
        $facilityIndex++;
    }

    foreach ($reservations as $res) {
        $fid = (int)$res['facility_id'];
        if (!isset($byFacility[$fid])) {
            continue;
        }
        $rid = (int)$res['id'];
        $att = $attMap[$rid] ?? null;
        $state = frs_compute_reservation_operational_state($res, $att, $now);
        $disp = frs_reservation_state_display($state);
        $byFacility[$fid]['reservations_today'][] = [
            'reservation_id' => $rid,
            'requester_name' => (string)$res['requester_name'],
            'time_slot' => (string)$res['time_slot'],
            'expected_attendees' => $res['expected_attendees'],
            'operational_state' => $state,
            'state_label' => $disp['label'],
            'state_color' => $disp['color'],
            'state_bg' => $disp['bg'],
            'time_in_at' => $att['time_in_at'] ?? null,
            'time_out_at' => $att['time_out_at'] ?? null,
        ];
        if ($state === FRS_RES_STATE_CHECKED_IN) {
            $byFacility[$fid]['counts']['checked_in']++;
        } elseif ($state === FRS_RES_STATE_NO_SHOW_RISK) {
            $byFacility[$fid]['counts']['no_show_risk']++;
        } elseif (in_array($state, [FRS_RES_STATE_SCHEDULED, FRS_RES_STATE_UPCOMING], true)) {
            $byFacility[$fid]['counts']['booked']++;
        }
    }

    $occupiedKeys = ['staff_in_use', 'checked_in', 'no_show_risk', 'booked'];
    $summary = [
        'total_facilities' => count($byFacility),
        'available' => 0,
        'occupied' => 0,
        'no_show_risk' => 0,
        'checked_in' => 0,
    ];

    foreach ($byFacility as &$fac) {
        $staff = $fac['staff_live']['status'] ?? FRS_FACILITY_LIVE_AUTO;
        $agg = 'available';

        if ($staff === FRS_FACILITY_LIVE_CLOSED) {
            $agg = 'staff_closed';
        } elseif ($staff === FRS_FACILITY_LIVE_VACANT) {
            $agg = 'staff_vacant';
        } elseif ($staff === FRS_FACILITY_LIVE_IN_USE) {
            $agg = 'staff_in_use';
        } elseif ($staff === FRS_FACILITY_LIVE_EVENT_ENDING) {
            $agg = 'staff_event_ending';
        } elseif ($fac['counts']['checked_in'] > 0) {
            $agg = 'checked_in';
        } elseif ($fac['counts']['no_show_risk'] > 0) {
            $agg = 'no_show_risk';
        } elseif ($fac['counts']['booked'] > 0) {
            $agg = 'booked';
        }

        $fac['aggregate_state'] = $agg;
        $fac['aggregate_display'] = frs_facility_aggregate_state_display($agg);

        if (in_array($agg, $occupiedKeys, true) && $agg !== 'staff_vacant') {
            $summary['occupied']++;
        } else {
            $summary['available']++;
        }
        $summary['no_show_risk'] += $fac['counts']['no_show_risk'];
        $summary['checked_in'] += $fac['counts']['checked_in'];
    }
    unset($fac);

    $items = array_values($byFacility);
    $total = max(1, $summary['total_facilities']);

    return [
        'as_of' => $now->format('Y-m-d H:i:s'),
        'date' => $today,
        'disclaimer' => 'Estimated operational status from bookings, check-in/out, and staff updates — not live headcount.',
        'summary' => $summary,
        'occupancy_rate' => round(($summary['occupied'] / $total) * 100, 1),
        'facilities' => $items,
        'has_live_status_table' => $hasLiveStatus,
        'has_attendance_table' => $hasAttendance,
    ];
}

/**
 * Staff override for facility live status.
 */
function frs_set_facility_live_status(
    PDO $pdo,
    int $facilityId,
    string $status,
    ?string $note,
    int $staffUserId
): bool {
    if (!frs_occupancy_table_exists($pdo, 'facility_live_status')) {
        return false;
    }
    $allowed = [FRS_FACILITY_LIVE_AUTO, FRS_FACILITY_LIVE_VACANT, FRS_FACILITY_LIVE_IN_USE, FRS_FACILITY_LIVE_EVENT_ENDING, FRS_FACILITY_LIVE_CLOSED];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO facility_live_status (facility_id, status, note, updated_by, updated_at)
         VALUES (:fid, :st, :note, :uid, NOW())
         ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note),
             updated_by = VALUES(updated_by), updated_at = NOW()'
    );
    return $stmt->execute([
        'fid' => $facilityId,
        'st' => $status,
        'note' => $note,
        'uid' => $staffUserId,
    ]);
}

/**
 * Record no-show violations after grace period (cron).
 *
 * @return array{flagged: int, skipped: int}
 */
function frs_process_operational_no_shows(PDO $pdo, ?DateTimeInterface $now = null): array
{
    require_once __DIR__ . '/violations.php';

    $now = $now instanceof DateTimeInterface
        ? DateTime::createFromInterface($now)
        : new DateTime('now', frs_app_timezone());

    $hasNoShowCol = frs_occupancy_column_exists($pdo, 'reservations', 'no_show_flagged_at');
    $hasAttendance = frs_occupancy_table_exists($pdo, 'reservation_attendance');

    $today = $now->format('Y-m-d');
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');

    $sql = 'SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.no_show_flagged_at,
                   a.time_in_at
            FROM reservations r
            LEFT JOIN reservation_attendance a ON a.reservation_id = r.id
            WHERE r.status = "approved"
              AND r.reservation_date BETWEEN :y AND :today';
    if (!$hasAttendance) {
        $sql = 'SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.no_show_flagged_at,
                       NULL AS time_in_at
                FROM reservations r
                WHERE r.status = "approved"
                  AND r.reservation_date BETWEEN :y AND :today';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['y' => $yesterday, 'today' => $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $flagged = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        if (!empty($row['time_in_at'])) {
            $skipped++;
            continue;
        }
        if ($hasNoShowCol && !empty($row['no_show_flagged_at'])) {
            $skipped++;
            continue;
        }

        $state = frs_compute_reservation_operational_state($row, null, $now);
        if (!in_array($state, [FRS_RES_STATE_NO_SHOW_RISK, FRS_RES_STATE_NO_SHOW], true)) {
            $skipped++;
            continue;
        }

        $resId = (int)$row['id'];
        $userId = (int)$row['user_id'];
        $date = (string)$row['reservation_date'];
        $slot = (string)$row['time_slot'];

        $dup = $pdo->prepare(
            'SELECT 1 FROM user_violations
             WHERE reservation_id = :rid AND violation_type = "no_show" LIMIT 1'
        );
        $dup->execute(['rid' => $resId]);
        if (!$dup->fetchColumn()) {
            recordViolation(
                $userId,
                'no_show',
                'medium',
                'No show: no Time In recorded for reservation on ' . $date . ' (' . $slot . ').',
                $resId,
                null
            );
        }

        if ($hasNoShowCol) {
            $upd = $pdo->prepare('UPDATE reservations SET no_show_flagged_at = NOW() WHERE id = ? AND no_show_flagged_at IS NULL');
            $upd->execute([$resId]);
        }

        $flagged++;
    }

    return ['flagged' => $flagged, 'skipped' => $skipped];
}

/**
 * Ensure check-in token exists for QR (lazy).
 */
function frs_ensure_checkin_token(PDO $pdo, int $reservationId): ?string
{
    if (!frs_occupancy_column_exists($pdo, 'reservations', 'attendance_checkin_token')) {
        return null;
    }
    $sel = $pdo->prepare('SELECT attendance_checkin_token FROM reservations WHERE id = ? LIMIT 1');
    $sel->execute([$reservationId]);
    $existing = $sel->fetchColumn();
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }
    $token = bin2hex(random_bytes(24));
    $upd = $pdo->prepare(
        'UPDATE reservations SET attendance_checkin_token = ? WHERE id = ? AND (attendance_checkin_token IS NULL OR attendance_checkin_token = "")'
    );
    $upd->execute([$token, $reservationId]);
    return $token;
}

/** Occupied/busy aggregate keys for filters */
function frs_occupancy_aggregate_is_busy(string $aggregateState): bool
{
    $busy = ['staff_in_use', 'checked_in', 'no_show_risk', 'booked', 'staff_event_ending', 'staff_closed'];
    return in_array($aggregateState, $busy, true);
}

/**
 * Public URL for a facility thumbnail (with rotating fallbacks when no upload).
 */
function frs_facility_display_image_url(?string $imagePath, int $index = 0): string
{
    $base = base_path();
    if ($imagePath !== null && trim($imagePath) !== '') {
        $path = trim($imagePath);
        return $base . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
    $fallbacks = [
        $base . '/public/img/convention-hall.jpg',
        $base . '/public/img/sports-complex.jpg',
        $base . '/public/img/amphitheater.jpg',
    ];
    return $fallbacks[abs($index) % count($fallbacks)];
}

/**
 * Hide requester names on public/resident views.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function frs_sanitize_occupancy_snapshot_for_public(array $snapshot): array
{
    if (!isset($snapshot['facilities']) || !is_array($snapshot['facilities'])) {
        return $snapshot;
    }
    foreach ($snapshot['facilities'] as &$fac) {
        if (!isset($fac['reservations_today']) || !is_array($fac['reservations_today'])) {
            continue;
        }
        foreach ($fac['reservations_today'] as &$res) {
            unset($res['requester_name']);
        }
        unset($res);
    }
    unset($fac);
    $snapshot['disclaimer'] = 'Estimated facility availability from bookings and check-ins — not live headcount.';
    return $snapshot;
}

/**
 * Resolve reservation id from check-in token for logged-in user.
 */
function frs_reservation_id_from_checkin_token(PDO $pdo, string $token, int $userId): ?int
{
    if ($token === '' || !frs_occupancy_column_exists($pdo, 'reservations', 'attendance_checkin_token')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id FROM reservations
         WHERE attendance_checkin_token = ? AND user_id = ? AND status = "approved" LIMIT 1'
    );
    $st->execute([$token, $userId]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function frs_facility_qr_column_exists(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $st = $pdo->query("SHOW COLUMNS FROM facilities LIKE 'checkin_qr_token'");
        $cache = (bool)$st->fetch();
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Ensure a persistent facility QR token exists (for on-site posters).
 */
function frs_ensure_facility_checkin_token(PDO $pdo, int $facilityId): ?string
{
    if (!frs_facility_qr_column_exists($pdo)) {
        return null;
    }
    $sel = $pdo->prepare('SELECT checkin_qr_token FROM facilities WHERE id = ? LIMIT 1');
    $sel->execute([$facilityId]);
    $existing = $sel->fetchColumn();
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }
    $token = bin2hex(random_bytes(24));
    $upd = $pdo->prepare(
        'UPDATE facilities SET checkin_qr_token = ? WHERE id = ? AND (checkin_qr_token IS NULL OR checkin_qr_token = "")'
    );
    $upd->execute([$token, $facilityId]);
    if ($upd->rowCount() > 0) {
        return $token;
    }
    $sel->execute([$facilityId]);
    $again = $sel->fetchColumn();
    return is_string($again) && $again !== '' ? $again : null;
}

/**
 * Replace facility QR token (invalidates old printed posters).
 */
function frs_regenerate_facility_checkin_token(PDO $pdo, int $facilityId): ?string
{
    if (!frs_facility_qr_column_exists($pdo)) {
        return null;
    }
    $token = bin2hex(random_bytes(24));
    $upd = $pdo->prepare('UPDATE facilities SET checkin_qr_token = ? WHERE id = ?');
    $upd->execute([$token, $facilityId]);
    return $upd->rowCount() > 0 ? $token : frs_ensure_facility_checkin_token($pdo, $facilityId);
}

/**
 * @return array<string, mixed>|null
 */
function frs_facility_from_checkin_token(PDO $pdo, string $token): ?array
{
    if ($token === '' || !frs_facility_qr_column_exists($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, name, location, status, checkin_qr_token
         FROM facilities
         WHERE checkin_qr_token = ?
         LIMIT 1'
    );
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function frs_facility_checkin_url(string $token): string
{
    return base_url() . base_path() . '/dashboard/facility-check-in?token=' . urlencode($token);
}

function frs_facility_qr_image_url(string $checkinUrl, int $size = 320): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&margin=12&data=' . rawurlencode($checkinUrl);
}
