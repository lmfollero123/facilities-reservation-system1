<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function frs_checkin_waiver_table_exists(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE 'reservation_checkin_waivers'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function frs_checkin_waiver_ensure_schema(PDO $pdo): void
{
    if (frs_checkin_waiver_table_exists($pdo)) {
        return;
    }
    $sql = file_get_contents(__DIR__ . '/../database/migration_add_checkin_waiver.sql');
    if ($sql !== false) {
        $pdo->exec($sql);
    }
}

/**
 * @return array<string, mixed>|null
 */
function frs_get_checkin_waiver(PDO $pdo, int $reservationId): ?array
{
    if (!frs_checkin_waiver_table_exists($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM reservation_checkin_waivers WHERE reservation_id = ? LIMIT 1');
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function frs_reservation_has_approved_waiver(PDO $pdo, int $reservationId): bool
{
    $w = frs_get_checkin_waiver($pdo, $reservationId);
    return $w !== null && ($w['status'] ?? '') === 'approved';
}

/**
 * @return array{ok: bool, message: string}
 */
function frs_request_checkin_waiver(PDO $pdo, int $reservationId, int $userId, string $reason): array
{
    frs_checkin_waiver_ensure_schema($pdo);
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) < 10) {
        return ['ok' => false, 'message' => 'Please explain why you could not check in (at least 10 characters).'];
    }

    $stmt = $pdo->prepare(
        'SELECT r.id, r.user_id, r.status, r.reservation_date, a.time_in_at
         FROM reservations r
         LEFT JOIN reservation_attendance a ON a.reservation_id = r.id
         WHERE r.id = ? AND r.user_id = ? LIMIT 1'
    );
    $stmt->execute([$reservationId, $userId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$res) {
        return ['ok' => false, 'message' => 'Reservation not found.'];
    }
    if (strtolower((string)$res['status']) !== 'approved') {
        return ['ok' => false, 'message' => 'Only approved reservations can request a check-in waiver.'];
    }
    if (!empty($res['time_in_at'])) {
        return ['ok' => false, 'message' => 'You already checked in for this reservation.'];
    }

    $eventDate = (string)$res['reservation_date'];
    $cutoff = (new DateTime($eventDate))->modify('+2 days');
    if (new DateTime('today') > $cutoff) {
        return ['ok' => false, 'message' => 'Waiver requests must be submitted within 2 days after the reservation date.'];
    }

    $existing = frs_get_checkin_waiver($pdo, $reservationId);
    if ($existing) {
        $st = (string)($existing['status'] ?? '');
        if ($st === 'pending') {
            return ['ok' => false, 'message' => 'A waiver request is already pending staff review.'];
        }
        if ($st === 'approved') {
            return ['ok' => false, 'message' => 'A waiver was already approved for this reservation.'];
        }
        if ($st === 'denied') {
            return ['ok' => false, 'message' => 'Your previous waiver request was denied. Contact barangay staff if you need help.'];
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO reservation_checkin_waivers (reservation_id, user_id, reason, status, created_at)
         VALUES (?, ?, ?, "pending", NOW())'
    );
    $ins->execute([$reservationId, $userId, $reason]);

    return ['ok' => true, 'message' => 'Waiver request submitted. Staff will review it shortly.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function frs_review_checkin_waiver(PDO $pdo, int $waiverId, int $staffId, string $decision, ?string $staffNote = null): array
{
    frs_checkin_waiver_ensure_schema($pdo);
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approved', 'denied'], true)) {
        return ['ok' => false, 'message' => 'Invalid decision.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM reservation_checkin_waivers WHERE id = ? LIMIT 1');
    $stmt->execute([$waiverId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'Waiver request not found or already reviewed.'];
    }

    $note = $staffNote !== null ? trim($staffNote) : null;
    $upd = $pdo->prepare(
        'UPDATE reservation_checkin_waivers
         SET status = ?, staff_note = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ?'
    );
    $upd->execute([$decision, $note !== '' ? $note : null, $staffId, $waiverId]);

    return [
        'ok' => true,
        'message' => $decision === 'approved'
            ? 'Waiver approved. No-show violation will not apply for this reservation.'
            : 'Waiver denied.',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function frs_list_pending_checkin_waivers(PDO $pdo, int $limit = 50): array
{
    if (!frs_checkin_waiver_table_exists($pdo)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        "SELECT w.*, r.reservation_date, r.time_slot, r.status AS reservation_status,
                f.name AS facility_name, u.name AS resident_name, u.email AS resident_email
         FROM reservation_checkin_waivers w
         JOIN reservations r ON r.id = w.reservation_id
         JOIN facilities f ON f.id = r.facility_id
         JOIN users u ON u.id = w.user_id
         WHERE w.status = 'pending'
         ORDER BY w.created_at ASC
         LIMIT {$limit}"
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function frs_count_pending_checkin_waivers(PDO $pdo): int
{
    if (!frs_checkin_waiver_table_exists($pdo)) {
        return 0;
    }
    return (int)$pdo->query("SELECT COUNT(*) FROM reservation_checkin_waivers WHERE status = 'pending'")->fetchColumn();
}
