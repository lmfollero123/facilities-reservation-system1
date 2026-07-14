<?php
/**
 * Facility day tone for booking calendar (no new endpoints).
 * Mirrors public availability splitting logic against 08:00–21:00.
 */
declare(strict_types=1);

require_once __DIR__ . '/time_helpers.php';
require_once __DIR__ . '/blackout_dates.php';

/**
 * Parse "HH:MM - HH:MM" from reservations.time_slot.
 *
 * @return array{0:string,1:string}|null [start,end] 24h
 */
function frs_parse_booking_slot_range(string $slot): ?array
{
    if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $slot, $m)) {
        return [$m[1], $m[2]];
    }
    return null;
}

/** @return list<array{0:int,1:int}> merged closed intervals inside [windowStartMin, windowEndMin) */
function frs_merge_intervals(array $intervals): array
{
    if ($intervals === []) {
        return [];
    }
    usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    [$cs, $ce] = $intervals[0];
    for ($i = 1, $n = count($intervals); $i < $n; $i++) {
        [$s, $e] = $intervals[$i];
        if ($s <= $ce) {
            $ce = max($ce, $e);
        } else {
            $out[] = [$cs, $ce];
            $cs = $s;
            $ce = $e;
        }
    }
    $out[] = [$cs, $ce];
    return $out;
}

/**
 * Free minutes remaining in [OPEN, CLOSE) after subtracting booked ranges.
 *
 * @param list<array{0:int,1:int}> $bookedIntervals minutes, half-open preferred [start,end)
 */
function frs_free_minutes_in_window(array $bookedIntervals, int $openMin, int $closeMin): int
{
    if ($closeMin <= $openMin) {
        return 0;
    }
    $merged = frs_merge_intervals($bookedIntervals);
    $free = 0;
    $cursor = $openMin;
    foreach ($merged as [$s, $e]) {
        $s = max($s, $openMin);
        $e = min($e, $closeMin);
        if ($e <= $cursor) {
            continue;
        }
        if ($s > $cursor) {
            $free += $s - $cursor;
        }
        $cursor = max($cursor, $e);
        if ($cursor >= $closeMin) {
            return $free;
        }
    }
    if ($cursor < $closeMin) {
        $free += $closeMin - $cursor;
    }
    return $free;
}

/** SQL fragment matching conflict detection’s active bookings for calendar density. */
function frs_calendar_active_booking_condition(PDO $pdo): string
{
    static $cond = null;
    if ($cond !== null) {
        return $cond;
    }
    $hasExpires = false;
    try {
        $check = $pdo->query('SHOW COLUMNS FROM reservations LIKE ' . $pdo->quote('expires_at'));
        $hasExpires = (bool)$check && $check->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasExpires = false;
    }
    // Align with ai_helpers.detectBookingConflict: approved always; pending only if not expired.
    $cond = ' (r.status = "approved" OR (r.status = "pending"';
    $cond .= $hasExpires ? ' AND (r.expires_at IS NULL OR r.expires_at > NOW())' : '';
    $cond .= ')) ';
    return $cond;
}

/**
 * One-shot month matrix for a facility: blackout + facility status + booking density.
 *
 * @return array<string,string> date Y-m-d => tone: maintenance|offline|blackout|cimm_maintenance|green|yellow|red
 */
function frs_facility_calendar_matrix(PDO $pdo, int $facilityId, int $year, int $month): array
{
    $open = '08:00';
    $close = '21:00';
    $openMin = frs_hhmm_to_minutes($open);
    $closeMin = frs_hhmm_to_minutes($close);

    $stFac = $pdo->prepare('SELECT status FROM facilities WHERE id = ? AND status != "deleted" LIMIT 1');
    $stFac->execute([$facilityId]);
    $fStatus = $stFac->fetchColumn();
    if (!$fStatus) {
        return [];
    }
    // Offline is indefinite — fill the month. Maintenance must NOT (CIMM uses
    // dated blackouts Jul–Aug; painting the whole month ignores the end date).
    if ($fStatus === 'offline') {
        return frs_calendar_month_fill_tone($year, $month, 'offline');
    }

    $first = sprintf('%04d-%02d-01', $year, $month);
    $last = date('Y-m-t', strtotime($first));
    $today = date('Y-m-d');

    $tone = [];

    // Blackouts for facility in range (CPRF vs CIMM maintenance)
    $blackSet = [];
    try {
        $b = $pdo->prepare(
            'SELECT blackout_date, reason FROM facility_blackout_dates WHERE facility_id = ? AND blackout_date BETWEEN ? AND ?'
        );
        $b->execute([$facilityId, $first, $last]);
        while ($row = $b->fetch(PDO::FETCH_ASSOC)) {
            $d = (string)$row['blackout_date'];
            $blackSet[$d] = frs_blackout_is_cimm_sync($row) ? 'cimm_maintenance' : 'blackout';
        }
    } catch (Throwable $e) {
        // table may not exist in minimal installs
    }

    // Staff flipped facility to "maintenance" with no dated blackouts → block whole month.
    // CIMM always writes per-day blackouts; do not whole-month-fill when those exist.
    if ($fStatus === 'maintenance' && $blackSet === []) {
        $hasAnyBlackout = false;
        try {
            $any = $pdo->prepare('SELECT 1 FROM facility_blackout_dates WHERE facility_id = ? LIMIT 1');
            $any->execute([$facilityId]);
            $hasAnyBlackout = (bool)$any->fetchColumn();
        } catch (Throwable $e) {
            $hasAnyBlackout = false;
        }
        if (!$hasAnyBlackout) {
            return frs_calendar_month_fill_tone($year, $month, 'maintenance');
        }
    }

    $act = frs_calendar_active_booking_condition($pdo);
    $bookSt = $pdo->prepare(
        "SELECT reservation_date, time_slot FROM reservations r
         WHERE r.facility_id = ? AND r.reservation_date BETWEEN ? AND ? AND {$act}"
    );
    $bookSt->execute([$facilityId, $first, $last]);
    $byDate = [];
    while ($row = $bookSt->fetch(PDO::FETCH_ASSOC)) {
        $d = (string)$row['reservation_date'];
        $range = frs_parse_booking_slot_range((string)$row['time_slot']);
        if (!$range) {
            continue;
        }
        $s = frs_hhmm_to_minutes($range[0]);
        $e = frs_hhmm_to_minutes($range[1]);
        // clamp & treat as occupying [start,end]
        $s = max($s, $openMin);
        $e = min($e, $closeMin);
        if ($e <= $openMin || $s >= $closeMin) {
            continue;
        }
        $byDate[$d][] = [max($s, $openMin), min($e, $closeMin)];
    }

    $iter = new DateTimeImmutable($first);
    $endDt = new DateTimeImmutable($last);
    while ($iter <= $endDt) {
        $dStr = $iter->format('Y-m-d');

        if (isset($blackSet[$dStr])) {
            $tone[$dStr] = $blackSet[$dStr];
        } elseif ($dStr < $today) {
            $tone[$dStr] = 'past';
        } else {
            $intervals = $byDate[$dStr] ?? [];
            $busyMin = ($closeMin - $openMin) - frs_free_minutes_in_window($intervals, $openMin, $closeMin);
            if ($busyMin <= 0) {
                $tone[$dStr] = 'green';
            } elseif (frs_free_minutes_in_window($intervals, $openMin, $closeMin) <= 0) {
                $tone[$dStr] = 'red';
            } else {
                $tone[$dStr] = 'yellow';
            }
        }
        $iter = $iter->modify('+1 day');
    }

    return $tone;
}

/**
 * @return array<string,string>
 */
function frs_calendar_month_fill_tone(int $year, int $month, string $tone): array
{
    $out = [];
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last = date('Y-m-t', strtotime($first));
    $iter = new DateTimeImmutable($first);
    $endDt = new DateTimeImmutable($last);
    while ($iter <= $endDt) {
        $out[$iter->format('Y-m-d')] = $tone;
        $iter = $iter->modify('+1 day');
    }
    return $out;
}
