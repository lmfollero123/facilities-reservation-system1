<?php
/**
 * Shared Check In / Check Out logic (dashboard form + facility QR scan).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/time_helpers.php';
require_once __DIR__ . '/occupancy_monitoring.php';

/**
 * Build reservation start/end DateTime from date + slot string.
 */
function frs_reservation_window(string $reservationDate, string $timeSlot): array
{
    $parsed = parseTimeSlot($timeSlot);
    if (!$parsed) {
        return ['start' => null, 'end' => null];
    }
    $startTime = $parsed['start']->format('H:i');
    $endTime = $parsed['end']->format('H:i');
    $start = DateTime::createFromFormat('Y-m-d H:i', $reservationDate . ' ' . $startTime) ?: null;
    $end = DateTime::createFromFormat('Y-m-d H:i', $reservationDate . ' ' . $endTime) ?: null;

    return ['start' => $start, 'end' => $end];
}

/**
 * Pick the best approved reservation for today at a facility (handles multiple slots).
 *
 * @return array<string, mixed>|null
 */
function frs_best_today_reservation_for_facility(PDO $pdo, int $userId, int $facilityId): ?array
{
    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT r.id, r.user_id, r.facility_id, r.reservation_date, r.time_slot, r.status,
                f.name AS facility_name
         FROM reservations r
         JOIN facilities f ON f.id = r.facility_id
         WHERE r.user_id = :uid
           AND r.facility_id = :fid
           AND r.reservation_date = :today
           AND r.status = "approved"
         ORDER BY r.time_slot ASC'
    );
    $stmt->execute(['uid' => $userId, 'fid' => $facilityId, 'today' => $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return null;
    }

    $now = new DateTime();
    $best = null;
    $bestScore = PHP_INT_MIN;

    foreach ($rows as $row) {
        $window = frs_reservation_window($row['reservation_date'], $row['time_slot']);
        $start = $window['start'];
        $end = $window['end'];
        if (!$start || !$end) {
            continue;
        }

        $checkinOpen = (clone $start)->modify('-' . FRS_OCCUPANCY_CHECKIN_GRACE_BEFORE . ' minutes');
        $score = 0;

        if ($now >= $checkinOpen && $now <= $end) {
            $score = 1000;
        } elseif ($now < $checkinOpen) {
            $score = 500 - abs($now->getTimestamp() - $checkinOpen->getTimestamp());
        } elseif ($now > $end && $now <= (clone $end)->modify('+2 hours')) {
            $score = 300 - abs($now->getTimestamp() - $end->getTimestamp());
        } else {
            $score = -abs($now->getTimestamp() - $start->getTimestamp());
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }

    return $best ?? $rows[0];
}

/**
 * Load attendance row for a reservation.
 *
 * @return array<string, mixed>|null
 */
function frs_get_attendance(PDO $pdo, int $reservationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM reservation_attendance WHERE reservation_id = ? LIMIT 1');
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array{ok: bool, message: string, action?: string}
 */
function frs_record_check_in(PDO $pdo, int $reservationId, int $userId, ?string $proofPath = null): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.status
         FROM reservations r
         WHERE r.id = ? AND r.user_id = ? LIMIT 1'
    );
    $stmt->execute([$reservationId, $userId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
        return ['ok' => false, 'message' => 'Reservation not found.'];
    }
    if (strtolower((string)$res['status']) !== 'approved') {
        return ['ok' => false, 'message' => 'Only approved reservations can be checked in.'];
    }
    if ($res['reservation_date'] !== date('Y-m-d')) {
        return ['ok' => false, 'message' => 'Check In is only available on your reservation date.'];
    }

    $window = frs_reservation_window($res['reservation_date'], $res['time_slot']);
    $startDt = $window['start'];
    $endDt = $window['end'];
    if (!$startDt || !$endDt) {
        return ['ok' => false, 'message' => 'Unable to read reservation time window.'];
    }

    $now = new DateTime();
    $checkinOpen = (clone $startDt)->modify('-' . FRS_OCCUPANCY_CHECKIN_GRACE_BEFORE . ' minutes');
    if ($now < $checkinOpen) {
        return [
            'ok' => false,
            'message' => 'Check In opens ' . FRS_OCCUPANCY_CHECKIN_GRACE_BEFORE . ' minutes before your slot starts (' . $checkinOpen->format('g:i A') . ').',
        ];
    }
    if ($now > $endDt) {
        return ['ok' => false, 'message' => 'This reservation time has already ended. Check In is no longer available.'];
    }

    $att = frs_get_attendance($pdo, $reservationId);
    if ($att && !empty($att['time_in_at'])) {
        return ['ok' => false, 'message' => 'You are already checked in for this reservation.'];
    }

    if ($att) {
        $upd = $pdo->prepare(
            'UPDATE reservation_attendance SET time_in_at = NOW(), time_in_proof_path = ?, user_id = ? WHERE reservation_id = ?'
        );
        $upd->execute([$proofPath, $userId, $reservationId]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO reservation_attendance (reservation_id, user_id, time_in_at, time_in_proof_path) VALUES (?, ?, NOW(), ?)'
        );
        $ins->execute([$reservationId, $userId, $proofPath]);
    }

    return ['ok' => true, 'message' => 'Check In recorded successfully.', 'action' => 'check_in'];
}

/**
 * @return array{ok: bool, message: string, action?: string}
 */
function frs_record_check_out(PDO $pdo, int $reservationId, int $userId, ?string $proofPath = null): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.status
         FROM reservations r
         WHERE r.id = ? AND r.user_id = ? LIMIT 1'
    );
    $stmt->execute([$reservationId, $userId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
        return ['ok' => false, 'message' => 'Reservation not found.'];
    }
    if (strtolower((string)$res['status']) !== 'approved') {
        return ['ok' => false, 'message' => 'Only approved reservations can be checked out.'];
    }
    if ($res['reservation_date'] !== date('Y-m-d')) {
        return ['ok' => false, 'message' => 'Check Out is only available on your reservation date.'];
    }

    $window = frs_reservation_window($res['reservation_date'], $res['time_slot']);
    $endDt = $window['end'];
    if (!$endDt) {
        return ['ok' => false, 'message' => 'Unable to read reservation time window.'];
    }

    $now = new DateTime();
    $att = frs_get_attendance($pdo, $reservationId);
    if (!$att || empty($att['time_in_at'])) {
        return ['ok' => false, 'message' => 'You must Check In before you can Check Out.'];
    }
    if (!empty($att['time_out_at'])) {
        return ['ok' => false, 'message' => 'You are already checked out for this reservation.'];
    }
    if ($now < $endDt) {
        return [
            'ok' => false,
            'message' => 'Check Out will be available after your slot ends (' . $endDt->format('g:i A') . ').',
        ];
    }

    $upd = $pdo->prepare(
        'UPDATE reservation_attendance SET time_out_at = NOW(), time_out_proof_path = ? WHERE reservation_id = ?'
    );
    $upd->execute([$proofPath, $reservationId]);

    return ['ok' => true, 'message' => 'Check Out recorded successfully.', 'action' => 'check_out'];
}

/**
 * Facility QR scan: auto check in or check out when rules allow.
 *
 * @return array{
 *   ok: bool,
 *   message: string,
 *   action?: string,
 *   reservation?: array<string, mixed>,
 *   facility?: array<string, mixed>,
 *   status?: string
 * }
 */
function frs_process_facility_qr_scan(PDO $pdo, int $userId, int $facilityId): array
{
    $facStmt = $pdo->prepare('SELECT id, name, location, status FROM facilities WHERE id = ? LIMIT 1');
    $facStmt->execute([$facilityId]);
    $facility = $facStmt->fetch(PDO::FETCH_ASSOC);
    if (!$facility) {
        return ['ok' => false, 'message' => 'Facility not found.'];
    }

    $reservation = frs_best_today_reservation_for_facility($pdo, $userId, $facilityId);
    if (!$reservation) {
        return [
            'ok' => false,
            'message' => 'You have no approved reservation at this facility today.',
            'facility' => $facility,
            'status' => 'no_reservation',
        ];
    }

    $reservationId = (int)$reservation['id'];
    $att = frs_get_attendance($pdo, $reservationId);
    $window = frs_reservation_window($reservation['reservation_date'], $reservation['time_slot']);
    $endDt = $window['end'];
    $now = new DateTime();

    if (!$att || empty($att['time_in_at'])) {
        $result = frs_record_check_in($pdo, $reservationId, $userId, null);
        $result['reservation'] = $reservation;
        $result['facility'] = $facility;
        $result['status'] = $result['ok'] ? 'checked_in' : 'check_in_blocked';
        return $result;
    }

    if (!empty($att['time_out_at'])) {
        return [
            'ok' => true,
            'message' => 'You already completed Check In and Check Out for today’s reservation.',
            'reservation' => $reservation,
            'facility' => $facility,
            'status' => 'completed',
            'action' => 'none',
        ];
    }

    if ($endDt && $now >= $endDt) {
        $result = frs_record_check_out($pdo, $reservationId, $userId, null);
        $result['reservation'] = $reservation;
        $result['facility'] = $facility;
        $result['status'] = $result['ok'] ? 'checked_out' : 'check_out_blocked';
        return $result;
    }

    return [
        'ok' => true,
        'message' => 'You are checked in. Check Out opens after ' . ($endDt ? $endDt->format('g:i A') : 'your slot ends') . '.',
        'reservation' => $reservation,
        'facility' => $facility,
        'status' => 'checked_in_waiting',
        'action' => 'none',
    ];
}
