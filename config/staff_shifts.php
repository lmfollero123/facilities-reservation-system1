<?php
/**
 * Staff shift scheduling — the availability layer the facilitator
 * auto-assigner consults so it never hands a reservation to someone who is
 * off duty or already busy.
 *
 * Model: a weekly recurring rota (staff_shifts), plus per-date exceptions
 * (staff_shift_exceptions) for leave / holidays / one-off cover. Staff may
 * request changes (staff_shift_requests) which an admin approves.
 *
 * Backward compatibility: a staffer with NO weekly shift rows at all is
 * treated as always available, so the system keeps auto-assigning exactly as
 * before until admins actually define rotas.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/time_helpers.php';

function frs_ensure_staff_shift_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_shifts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                staff_id INT UNSIGNED NOT NULL,
                day_of_week TINYINT UNSIGNED NOT NULL COMMENT "0=Sunday .. 6=Saturday",
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_staff_shifts_staff (staff_id),
                INDEX idx_staff_shifts_day (day_of_week),
                CONSTRAINT fk_staff_shifts_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_shift_exceptions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                staff_id INT UNSIGNED NOT NULL,
                exception_date DATE NOT NULL,
                type ENUM("off","custom") NOT NULL DEFAULT "off",
                start_time TIME NULL,
                end_time TIME NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_staff_date (staff_id, exception_date),
                INDEX idx_shift_exc_date (exception_date),
                CONSTRAINT fk_shift_exc_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_shift_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                staff_id INT UNSIGNED NOT NULL,
                request_date DATE NOT NULL,
                type ENUM("off","custom") NOT NULL DEFAULT "off",
                start_time TIME NULL,
                end_time TIME NULL,
                reason VARCHAR(255) NULL,
                status ENUM("pending","approved","denied") NOT NULL DEFAULT "pending",
                decided_by INT UNSIGNED NULL,
                decided_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_shift_req_staff (staff_id),
                INDEX idx_shift_req_status (status),
                CONSTRAINT fk_shift_req_user FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('staff shift schema ensure: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Does this staffer have any weekly rota defined at all? If not, they are
 * treated as always available (backward compatible with the pre-shift system).
 */
function frs_staff_has_rota(PDO $pdo, int $staffId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM staff_shifts WHERE staff_id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * The available time windows for a staffer on a given date, as
 * [['start'=>'HH:MM','end'=>'HH:MM'], ...]. A date-level exception overrides
 * the weekly rota entirely: an "off" exception yields no windows; a "custom"
 * exception yields its own window.
 *
 * @return list<array{start:string,end:string}>
 */
function frs_staff_windows_for_date(PDO $pdo, int $staffId, string $date): array
{
    frs_ensure_staff_shift_schema($pdo);

    $exc = $pdo->prepare(
        'SELECT type, start_time, end_time FROM staff_shift_exceptions
         WHERE staff_id = ? AND exception_date = ? LIMIT 1'
    );
    $exc->execute([$staffId, $date]);
    $exception = $exc->fetch(PDO::FETCH_ASSOC);
    if ($exception) {
        if ($exception['type'] === 'off' || !$exception['start_time'] || !$exception['end_time']) {
            return [];
        }
        return [[
            'start' => substr((string) $exception['start_time'], 0, 5),
            'end' => substr((string) $exception['end_time'], 0, 5),
        ]];
    }

    $dow = (int) date('w', strtotime($date)); // 0=Sun..6=Sat
    $rows = $pdo->prepare(
        'SELECT start_time, end_time FROM staff_shifts WHERE staff_id = ? AND day_of_week = ? ORDER BY start_time'
    );
    $rows->execute([$staffId, $dow]);
    $windows = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $windows[] = [
            'start' => substr((string) $r['start_time'], 0, 5),
            'end' => substr((string) $r['end_time'], 0, 5),
        ];
    }
    return $windows;
}

/**
 * Is the staffer on shift for the whole reservation window on that date?
 * A staffer with no rota at all is always available (unless a specific "off"
 * exception says otherwise for this date).
 */
function frs_staff_on_shift(PDO $pdo, int $staffId, string $date, string $timeSlot): bool
{
    frs_ensure_staff_shift_schema($pdo);

    // A date-level "off" exception blocks even a no-rota (always-available) staffer.
    $exc = $pdo->prepare(
        'SELECT type, start_time, end_time FROM staff_shift_exceptions
         WHERE staff_id = ? AND exception_date = ? LIMIT 1'
    );
    $exc->execute([$staffId, $date]);
    $exception = $exc->fetch(PDO::FETCH_ASSOC);

    if (!$exception && !frs_staff_has_rota($pdo, $staffId)) {
        return true; // no rota, no exception → unconstrained (legacy behavior)
    }

    $reservation = parseTimeSlot($timeSlot);
    if (!$reservation) {
        // Unparseable slot: fall back to "available if not explicitly off".
        return !($exception && $exception['type'] === 'off');
    }

    foreach (frs_staff_windows_for_date($pdo, $staffId, $date) as $w) {
        $window = parseTimeSlot($w['start'] . ' - ' . $w['end']);
        if ($window
            && $reservation['start'] >= $window['start']
            && $reservation['end'] <= $window['end']) {
            return true;
        }
    }
    return false;
}

/**
 * Is the staffer already assigned to another approved reservation whose time
 * overlaps this one on the same date?
 */
function frs_staff_assignment_overlaps(PDO $pdo, int $staffId, string $date, string $timeSlot, int $excludeReservationId = 0): bool
{
    $stmt = $pdo->prepare(
        "SELECT time_slot FROM reservations
         WHERE assigned_staff_id = ? AND reservation_date = ?
           AND id <> ? AND status IN ('approved','pending_payment')"
    );
    $stmt->execute([$staffId, $date, $excludeReservationId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        if (timeSlotsOverlap($timeSlot, (string) $existing)) {
            return true;
        }
    }
    return false;
}

/**
 * Staff (then Admin as fallback pool) who can facilitate a reservation at this
 * date+slot: active, on shift, and not already overlapping — ordered by fewest
 * upcoming assignments so load stays balanced.
 *
 * @return list<int>
 */
function frs_eligible_facilitator_ids(PDO $pdo, string $date, string $timeSlot, int $excludeReservationId = 0): array
{
    frs_ensure_staff_shift_schema($pdo);

    foreach (['Staff', 'Admin'] as $role) {
        $stmt = $pdo->prepare(
            "SELECT u.id
             FROM users u
             WHERE u.role = :role AND u.status = 'active'
             ORDER BY (
                 SELECT COUNT(*) FROM reservations r
                 WHERE r.assigned_staff_id = u.id
                   AND r.status = 'approved'
                   AND r.reservation_date >= CURDATE()
             ) ASC, u.id ASC"
        );
        $stmt->execute(['role' => $role]);
        $eligible = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if (frs_staff_on_shift($pdo, $id, $date, $timeSlot)
                && !frs_staff_assignment_overlaps($pdo, $id, $date, $timeSlot, $excludeReservationId)) {
                $eligible[] = $id;
            }
        }
        if ($eligible) {
            return $eligible;
        }
    }
    return [];
}
