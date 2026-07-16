<?php
/**
 * Reservation Helper Functions
 * 
 * Utility functions for managing reservations
 * 
 * @package FacilitiesReservation
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/time_helpers.php';
require_once __DIR__ . '/paymongo_helper.php';
require_once __DIR__ . '/lookups.php';

if (!defined('FRS_BOOKING_PURPOSE_MAX')) {
    define('FRS_BOOKING_PURPOSE_MAX', 2000);
}
if (!defined('FRS_BOOKING_NOTES_MAX')) {
    define('FRS_BOOKING_NOTES_MAX', 1200);
}
if (!defined('FRS_BOOKING_PURPOSE_COMBINED_MAX')) {
    define('FRS_BOOKING_PURPOSE_COMBINED_MAX', 3200);
}

/**
 * Validate booking purpose and notes lengths.
 *
 * @return array{ok: bool, message: string, field: string}
 */
function frs_validate_booking_text_fields(string $purpose, string $bookingNotes): array
{
    if (mb_strlen($purpose) > FRS_BOOKING_PURPOSE_MAX) {
        return [
            'ok' => false,
            'message' => 'Purpose of use must be ' . FRS_BOOKING_PURPOSE_MAX . ' characters or fewer.',
            'field' => 'purpose',
        ];
    }
    if (mb_strlen($bookingNotes) > FRS_BOOKING_NOTES_MAX) {
        return [
            'ok' => false,
            'message' => 'Notes for staff must be ' . FRS_BOOKING_NOTES_MAX . ' characters or fewer.',
            'field' => 'booking_notes',
        ];
    }
    $combined = $purpose;
    if ($purpose !== '' && $bookingNotes !== '') {
        $combined .= "\n\n--- Additional notes ---\n" . $bookingNotes;
    } elseif ($bookingNotes !== '') {
        $combined = $bookingNotes;
    }
    if (mb_strlen($combined) > FRS_BOOKING_PURPOSE_COMBINED_MAX) {
        return [
            'ok' => false,
            'message' => 'Combined purpose and notes must be ' . FRS_BOOKING_PURPOSE_COMBINED_MAX . ' characters or fewer.',
            'field' => 'purpose',
        ];
    }
    return ['ok' => true, 'message' => '', 'field' => ''];
}

/**
 * Payment hold window from config/payments.php (minutes).
 */
function frs_payment_window_minutes(): int
{
    static $minutes = null;
    if ($minutes !== null) {
        return $minutes;
    }
    $minutes = 60;
    $path = __DIR__ . '/payments.php';
    if (is_file($path)) {
        $cfg = require $path;
        if (is_array($cfg)) {
            $candidate = (int)($cfg['payment_window_minutes'] ?? 60);
            if ($candidate > 0) {
                $minutes = $candidate;
            }
        }
    }
    return $minutes;
}

/**
 * @return array{payment_due_at: string, expires_at: string}
 */
function frs_payment_hold_timestamps(): array
{
    $due = date('Y-m-d H:i:s', strtotime('+' . frs_payment_window_minutes() . ' minutes'));
    return ['payment_due_at' => $due, 'expires_at' => $due];
}

/**
 * Whether a reservation row still blocks booking (active hold / approval).
 */
function frs_reservation_blocks_booking(array $row): bool
{
    $status = (string)($row['status'] ?? '');
    $pdo = db();

    // Use lookup metadata to determine if status blocks booking
    if (!frs_lookups_table_ready($pdo)) {
        // Fallback to hardcoded logic if lookup tables not ready
        if ($status === 'approved') {
            return true;
        }
        if ($status === 'pending_payment') {
            $due = $row['payment_due_at'] ?? null;
            $expires = $row['expires_at'] ?? null;
            if ($due !== null && $due !== '' && strtotime((string)$due) < time()) {
                return false;
            }
            if ($due === null && $expires !== null && $expires !== '' && strtotime((string)$expires) < time()) {
                return false;
            }
            return true;
        }
        if ($status === 'pending' || $status === 'postponed') {
            $expires = $row['expires_at'] ?? null;
            return $expires === null || $expires === '' || strtotime((string)$expires) >= time();
        }
        return false;
    }

    // Use lookup-based logic
    $blocksBooking = frs_reservation_status_blocks_booking($pdo, $status);
    if (!$blocksBooking) {
        return false;
    }

    // Handle time-based expiration for statuses that block booking
    if ($status === 'pending_payment') {
        $due = $row['payment_due_at'] ?? null;
        $expires = $row['expires_at'] ?? null;
        if ($due !== null && $due !== '' && strtotime((string)$due) < time()) {
            return false;
        }
        if ($due === null && $expires !== null && $expires !== '' && strtotime((string)$expires) < time()) {
            return false;
        }
    }
    if ($status === 'pending' || $status === 'postponed') {
        $expires = $row['expires_at'] ?? null;
        if ($expires !== null && $expires !== '' && strtotime((string)$expires) < time()) {
            return false;
        }
    }

    return true;
}

/**
 * Check overlapping bookings for a facility/date (overlap-aware, not exact slot match).
 */
function frs_has_overlapping_booking(PDO $pdo, int $facilityId, string $date, string $timeSlot, ?int $excludeReservationId = null): bool
{
    $params = ['facility_id' => $facilityId, 'date' => $date];
    $excludeClause = '';
    if ($excludeReservationId) {
        $excludeClause = 'AND r.id != :exclude_id';
        $params['exclude_id'] = $excludeReservationId;
    }

    $stmt = $pdo->prepare(
        'SELECT r.id, r.time_slot, r.status, r.payment_due_at, r.expires_at
         FROM reservations r
         WHERE r.facility_id = :facility_id
           AND r.reservation_date = :date
           AND r.status IN ("approved", "pending", "pending_payment", "postponed")
           ' . $excludeClause
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!frs_reservation_blocks_booking($row)) {
            continue;
        }
        if (timeSlotsOverlap($timeSlot, (string)$row['time_slot'])) {
            return true;
        }
    }
    return false;
}

/**
 * Row-level lock on facility to reduce concurrent double-booking.
 */
function frs_lock_facility_for_booking(PDO $pdo, int $facilityId): void
{
    $stmt = $pdo->prepare('SELECT id FROM facilities WHERE id = ? FOR UPDATE');
    $stmt->execute([$facilityId]);
}

/**
 * Auto-decline pending reservations that have passed their reservation date/time
 * 
 * This function checks all pending reservations and automatically denies those where:
 * - The reservation date is in the past, OR
 * - The reservation date is today but the time slot has already passed
 * 
 * @return int Number of reservations auto-declined
 */
function autoDeclineExpiredReservations(): int {
    $pdo = db();
    $autoDeclined = 0;
    
    try {
        $expiredReservations = [];
        $seenIds = [];

        $appendExpired = static function (array $row) use (&$expiredReservations, &$seenIds): void {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0 && !isset($seenIds[$id])) {
                $seenIds[$id] = true;
                $expiredReservations[] = $row;
            }
        };

        // 1) Payment holds past payment_due_at (regardless of event date)
        $paymentHoldStmt = $pdo->query(
            'SELECT id, reservation_date, time_slot, user_id, status
             FROM reservations
             WHERE status = "pending_payment"
               AND payment_due_at IS NOT NULL
               AND payment_due_at < NOW()'
        );
        foreach ($paymentHoldStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $appendExpired($row);
        }

        // 2) Past event dates
        $pastStmt = $pdo->query(
            'SELECT id, reservation_date, time_slot, user_id, status
             FROM reservations
             WHERE status IN ("pending_payment", "pending", "postponed")
               AND reservation_date < CURDATE()'
        );
        foreach ($pastStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $appendExpired($row);
        }

        // 3) Today — slot ended (pending/postponed only; pending_payment uses payment_due_at above)
        $todayStmt = $pdo->query(
            'SELECT id, reservation_date, time_slot, user_id, status
             FROM reservations
             WHERE status IN ("pending", "postponed")
               AND reservation_date = CURDATE()'
        );
        foreach ($todayStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (frs_reservation_slot_has_passed((string)$row['reservation_date'], (string)$row['time_slot'])) {
                $appendExpired($row);
            }
        }
        
        // Process each expired reservation
        foreach ($expiredReservations as $expired) {
            // pending_payment = payment not completed in time => cancelled
            // pending/postponed = approval did not happen in time => denied
            $targetStatus = (($expired['status'] ?? '') === 'pending_payment') ? 'cancelled' : 'denied';
            $targetNote = (($expired['status'] ?? '') === 'pending_payment')
                ? 'Automatically cancelled: Pencil booking expired before payment confirmation.'
                : 'Automatically denied: Reservation date/time has passed without approval.';

            $updateStmt = $pdo->prepare(
                'UPDATE reservations 
                 SET status = :target_status, updated_at = CURRENT_TIMESTAMP 
                 WHERE id = :id AND status IN ("pending_payment", "pending", "postponed")'
            );
            $updateStmt->execute([
                'target_status' => $targetStatus,
                'id' => $expired['id'],
            ]);
            
            // Only proceed if update was successful (prevents duplicate processing)
            if ($updateStmt->rowCount() > 0) {
                // Add to history
                $histStmt = $pdo->prepare(
                    'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
                     VALUES (:reservation_id, :status, :note, NULL)'
                );
                $histStmt->execute([
                    'reservation_id' => $expired['id'],
                    'status' => $targetStatus,
                    'note' => $targetNote,
                ]);
                
                // Get facility name for notification
                $facilityStmt = $pdo->prepare(
                    'SELECT f.name 
                     FROM facilities f 
                     JOIN reservations r ON f.id = r.facility_id 
                     WHERE r.id = ?'
                );
                $facilityStmt->execute([$expired['id']]);
                $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
                $facilityName = $facility ? $facility['name'] : 'Facility';
                
                // Create notification
                if (function_exists('createNotification')) {
                    createNotification(
                        $expired['user_id'],
                        'booking',
                        $targetStatus === 'cancelled' ? 'Reservation Automatically Cancelled' : 'Reservation Automatically Denied',
                        'Your reservation request for ' . $facilityName . ' on ' .
                        date('F j, Y', strtotime($expired['reservation_date'])) . 
                        ' (' . $expired['time_slot'] . ') ' . (
                            $targetStatus === 'cancelled'
                                ? 'was automatically cancelled because the payment window has expired.'
                                : 'has been automatically denied because the reservation time has passed without approval.'
                        ),
                        base_url() . '/dashboard/my-reservations'
                    );
                }
                
                // Log audit event
                if (function_exists('logAudit')) {
                    logAudit(
                        $targetStatus === 'cancelled' ? 'Auto-cancelled expired payment hold' : 'Auto-denied expired reservation',
                        'Reservations',
                        'RES-' . $expired['id'] . ' – ' . (
                            $targetStatus === 'cancelled'
                                ? 'Payment window expired'
                                : 'Past reservation date/time without approval'
                        )
                    );
                }
                
                $autoDeclined++;
            }
        }
    } catch (Throwable $e) {
        // Log error but don't throw - allow the page to continue
        error_log('Error auto-declining expired reservations: ' . $e->getMessage());
    }
    
    return $autoDeclined;
}

/**
 * Resident booking limit config (env-tunable). Staff/Admin are not subject to these limits.
 *
 * @return array<string, int>
 */
function frs_resident_booking_limit_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $int = static function (string $key, int $default): int {
        if (!function_exists('env_value')) {
            return $default;
        }
        $v = (int)env_value($key, (string)$default);
        return $v > 0 ? $v : $default;
    };
    $cfg = [
        'per_day' => $int('BOOKING_MAX_PER_DAY', 1),
        'per_week' => $int('BOOKING_MAX_PER_WEEK', 3),
        'per_month' => $int('BOOKING_MAX_PER_MONTH', 8),
        'per_year' => $int('BOOKING_MAX_PER_YEAR', 96),
        'max_upcoming_active' => $int('BOOKING_MAX_UPCOMING_ACTIVE', 2),
        'advance_max_days' => $int('BOOKING_ADVANCE_MAX_DAYS', 60),
    ];
    return $cfg;
}

/**
 * Whether booking limits apply to this user (Residents only).
 */
function frs_booking_limits_apply_to_user(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $role = (string)($stmt->fetchColumn() ?: 'Resident');
    return $role === 'Resident';
}

/**
 * Validate resident booking limits before insert.
 *
 * @return array{ok: bool, message: string}
 */
function frs_validate_resident_booking_limits(
    PDO $pdo,
    int $userId,
    string $reservationDate,
    string $activeStatusesSql
): array {
    if (!frs_booking_limits_apply_to_user($pdo, $userId)) {
        return ['ok' => true, 'message' => ''];
    }

    $cfg = frs_resident_booking_limit_config();
    $today = date('Y-m-d');

    // Max upcoming active reservations (pending + approved, today and future)
    $upcomingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid
           AND reservation_date >= :today
           AND status IN (' . $activeStatusesSql . ')'
    );
    $upcomingStmt->execute(['uid' => $userId, 'today' => $today]);
    $upcoming = (int)$upcomingStmt->fetchColumn();
    if ($upcoming >= $cfg['max_upcoming_active']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: You can have at most ' . $cfg['max_upcoming_active']
                . ' active upcoming reservation(s) at a time.',
        ];
    }

    // Per day — one active booking on the same calendar date
    $perDayStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date = :date
           AND status IN (' . $activeStatusesSql . ')'
    );
    $perDayStmt->execute(['uid' => $userId, 'date' => $reservationDate]);
    if ((int)$perDayStmt->fetchColumn() >= $cfg['per_day']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Only ' . $cfg['per_day'] . ' active reservation per day is allowed.',
        ];
    }

    // Per week — rolling 7 days including selected date
    $weekStart = date('Y-m-d', strtotime($reservationDate . ' -6 days'));
    $weekEnd = $reservationDate;
    $weekStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date BETWEEN :start AND :end
           AND status IN (' . $activeStatusesSql . ')'
    );
    $weekStmt->execute(['uid' => $userId, 'start' => $weekStart, 'end' => $weekEnd]);
    if ((int)$weekStmt->fetchColumn() >= $cfg['per_week']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Maximum ' . $cfg['per_week'] . ' reservations per week.',
        ];
    }

    // Per month — rolling 30 days
    $monthStart = date('Y-m-d', strtotime($reservationDate . ' -29 days'));
    $monthStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date BETWEEN :start AND :end
           AND status IN (' . $activeStatusesSql . ')'
    );
    $monthStmt->execute(['uid' => $userId, 'start' => $monthStart, 'end' => $reservationDate]);
    if ((int)$monthStmt->fetchColumn() >= $cfg['per_month']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Maximum ' . $cfg['per_month'] . ' reservations per month.',
        ];
    }

    // Per year — calendar year of selected date
    $yearStart = substr($reservationDate, 0, 4) . '-01-01';
    $yearEnd = substr($reservationDate, 0, 4) . '-12-31';
    $yearStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date BETWEEN :start AND :end
           AND status IN (' . $activeStatusesSql . ')'
    );
    $yearStmt->execute(['uid' => $userId, 'start' => $yearStart, 'end' => $yearEnd]);
    if ((int)$yearStmt->fetchColumn() >= $cfg['per_year']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Maximum ' . $cfg['per_year'] . ' reservations per year.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Human-readable summary of resident booking limits (for UI / chatbot).
 */
function frs_resident_booking_limits_summary(): string
{
    $c = frs_resident_booking_limit_config();
    return sprintf(
        '%d per day, %d per week, %d per month, %d per year, max %d upcoming active, book up to %d days ahead',
        $c['per_day'],
        $c['per_week'],
        $c['per_month'],
        $c['per_year'],
        $c['max_upcoming_active'],
        $c['advance_max_days']
    );
}

/**
 * Multi-line bullet text for policies / chatbot (residents only; staff/admin exempt).
 */
function frs_resident_booking_limits_policy_bullets(): string
{
    $c = frs_resident_booking_limit_config();
    return sprintf(
        "• %d active reservation per day\n" .
        "• Up to %d reservations per week\n" .
        "• Up to %d reservations per month\n" .
        "• Up to %d reservations per year\n" .
        "• Maximum %d upcoming active reservations\n" .
        "• Book up to %d days in advance\n" .
        "• Staff and Admin are not subject to these limits",
        $c['per_day'],
        $c['per_week'],
        $c['per_month'],
        $c['per_year'],
        $c['max_upcoming_active'],
        $c['advance_max_days']
    );
}

/**
 * Active statuses that count toward resident booking quotas.
 */
function frs_active_booking_statuses_sql(PDO $pdo): string
{
    static $sql = null;
    if ($sql !== null) {
        return $sql;
    }
    $supportsPendingPayment = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'status'");
        $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = (string) ($col['Type'] ?? '');
        $supportsPendingPayment = stripos($type, 'pending_payment') !== false;
    } catch (Throwable $e) {
        $supportsPendingPayment = true;
    }
    $sql = $supportsPendingPayment
        ? '"pending_payment","pending","approved"'
        : '"pending","approved"';
    return $sql;
}

/**
 * Residents must be verified or have uploaded a valid ID (same as web Book Facility).
 *
 * @return array{ok: bool, message: string, error: string}
 */
function frs_resident_identity_allows_booking(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT is_verified, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'User not found.', 'error' => 'not_found'];
    }
    $role = (string) ($row['role'] ?? 'Resident');
    if (in_array($role, ['Staff', 'Admin'], true) || !empty($row['is_verified'])) {
        return ['ok' => true, 'message' => '', 'error' => ''];
    }
    try {
        $doc = $pdo->prepare(
            'SELECT id FROM user_documents
             WHERE user_id = ? AND document_type = "valid_id" AND is_archived = 0
             LIMIT 1'
        );
        $doc->execute([$userId]);
        if ($doc->fetchColumn()) {
            return ['ok' => true, 'message' => '', 'error' => ''];
        }
    } catch (Throwable $e) {
        // table may be missing in older installs — fall through to deny
    }
    return [
        'ok' => false,
        'message' => 'Please upload a valid ID on the website first. Unverified residents must submit a valid ID before booking.',
        'error' => 'id_required',
    ];
}

/**
 * Hard-block facility date for booking (offline / blackout / maintenance), matching web Book Facility.
 *
 * @return array{ok: bool, message: string, error: string}
 */
function frs_facility_date_bookable(PDO $pdo, int $facilityId, string $dateYmd, ?array $facilityRow = null): array
{
    if ($facilityRow === null) {
        $fac = $pdo->prepare(
            'SELECT id, name, status FROM facilities WHERE id = ? AND status != "deleted" LIMIT 1'
        );
        $fac->execute([$facilityId]);
        $facilityRow = $fac->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$facilityRow) {
        return ['ok' => false, 'message' => 'Facility not found.', 'error' => 'not_found'];
    }

    $status = (string) ($facilityRow['status'] ?? '');
    $name = (string) ($facilityRow['name'] ?? 'Facility');

    if ($status === 'offline') {
        return [
            'ok' => false,
            'message' => 'This facility is currently offline and unavailable for booking.',
            'error' => 'facility_unavailable',
        ];
    }

    $blackout = null;
    try {
        $bo = $pdo->prepare(
            'SELECT reason FROM facility_blackout_dates
             WHERE facility_id = ? AND blackout_date = ? LIMIT 1'
        );
        $bo->execute([$facilityId, $dateYmd]);
        $blackout = $bo->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $blackout = null;
    }

    if ($blackout) {
        $display = (string) ($blackout['reason'] ?? 'unavailable');
        if (file_exists(__DIR__ . '/blackout_dates.php')) {
            require_once __DIR__ . '/blackout_dates.php';
            if (function_exists('frs_blackout_enrich_row')) {
                $enriched = frs_blackout_enrich_row($blackout);
                $display = (string) ($enriched['display_reason'] ?? $display);
                if (($enriched['source_type'] ?? '') === 'cimm') {
                    return [
                        'ok' => false,
                        'message' => 'This facility has scheduled CIMM maintenance on the selected date (' . $display . '). Please choose another date or facility.',
                        'error' => 'blackout',
                    ];
                }
            }
        }
        return [
            'ok' => false,
            'message' => 'This facility is blacked out on the selected date (' . $display . '). Please choose another date or facility.',
            'error' => 'blackout',
        ];
    }

    if ($status === 'maintenance') {
        $hasAnyBlackout = false;
        try {
            $any = $pdo->prepare('SELECT 1 FROM facility_blackout_dates WHERE facility_id = ? LIMIT 1');
            $any->execute([$facilityId]);
            $hasAnyBlackout = (bool) $any->fetchColumn();
        } catch (Throwable $e) {
            $hasAnyBlackout = false;
        }
        // CIMM sets maintenance for a window but dated blackouts define which days are blocked.
        if (!$hasAnyBlackout) {
            return [
                'ok' => false,
                'message' => 'This facility is currently under maintenance and cannot be booked. Please select a different facility.',
                'error' => 'facility_unavailable',
            ];
        }
    }

    if ($status !== 'available' && $status !== 'maintenance') {
        return [
            'ok' => false,
            'message' => 'The facility "' . $name . '" is not available for booking (status: ' . $status . ').',
            'error' => 'facility_unavailable',
        ];
    }

    return ['ok' => true, 'message' => '', 'error' => ''];
}

/**
 * Validate time slot format/duration and parse start/end (HH:MM).
 *
 * @return array{ok: bool, message: string, error: string, start?: string, end?: string, hours?: float}
 */
function frs_validate_booking_time_slot(string $timeSlot): array
{
    $parsed = function_exists('parseTimeSlot') ? parseTimeSlot($timeSlot) : null;
    if (!$parsed || empty($parsed['start']) || empty($parsed['end'])) {
        return [
            'ok' => false,
            'message' => 'Invalid time slot. Use a start and end time (e.g. 09:00 - 11:00).',
            'error' => 'invalid_time_slot',
        ];
    }
    /** @var DateTimeInterface $start */
    $start = $parsed['start'];
    /** @var DateTimeInterface $end */
    $end = $parsed['end'];
    if ($end <= $start) {
        return [
            'ok' => false,
            'message' => 'End time must be after start time.',
            'error' => 'invalid_time_slot',
        ];
    }
    $durationHours = ((int) $end->format('H') * 60 + (int) $end->format('i')
        - ((int) $start->format('H') * 60 + (int) $start->format('i'))) / 60.0;
    if ($durationHours > 12) {
        return [
            'ok' => false,
            'message' => 'Reservation duration cannot exceed 12 hours.',
            'error' => 'duration_too_long',
        ];
    }
    if ($durationHours < 0.5) {
        return [
            'ok' => false,
            'message' => 'Reservation duration must be at least 30 minutes.',
            'error' => 'duration_too_short',
        ];
    }
    return [
        'ok' => true,
        'message' => '',
        'error' => '',
        'start' => $start->format('H:i'),
        'end' => $end->format('H:i'),
        'hours' => $durationHours,
    ];
}

/**
 * Full resident booking validation (parity with web Book Facility).
 *
 * @param array{
 *   facility_id: int,
 *   reservation_date: string,
 *   time_slot: string,
 *   purpose: string,
 *   notes?: string,
 *   expected_attendees?: int|null,
 *   exclude_reservation_id?: int|null,
 *   skip_quota?: bool,
 *   skip_identity?: bool
 * } $input
 * @return array{ok: bool, message: string, error: string, http: int, facility?: array}
 */
function frs_validate_resident_booking_request(PDO $pdo, int $userId, array $input): array
{
    $facilityId = (int) ($input['facility_id'] ?? 0);
    $date = trim((string) ($input['reservation_date'] ?? ''));
    $timeSlot = trim((string) ($input['time_slot'] ?? ''));
    $purpose = trim((string) ($input['purpose'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $attendees = isset($input['expected_attendees']) ? (int) $input['expected_attendees'] : 0;
    $excludeId = isset($input['exclude_reservation_id']) ? (int) $input['exclude_reservation_id'] : null;
    $skipQuota = !empty($input['skip_quota']);
    $skipIdentity = !empty($input['skip_identity']);

    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $timeSlot === '' || $purpose === '') {
        return [
            'ok' => false,
            'message' => 'facility_id, reservation_date, time_slot, and purpose are required.',
            'error' => 'validation',
            'http' => 422,
        ];
    }

    $textCheck = frs_validate_booking_text_fields($purpose, $notes);
    if (empty($textCheck['ok'])) {
        return [
            'ok' => false,
            'message' => (string) $textCheck['message'],
            'error' => 'validation',
            'http' => 422,
        ];
    }

    if (!$skipIdentity) {
        $idCheck = frs_resident_identity_allows_booking($pdo, $userId);
        if (empty($idCheck['ok'])) {
            return [
                'ok' => false,
                'message' => (string) $idCheck['message'],
                'error' => (string) ($idCheck['error'] ?: 'id_required'),
                'http' => 403,
            ];
        }
    }

    $tz = function_exists('frs_app_timezone') ? frs_app_timezone() : new DateTimeZone('Asia/Manila');
    $today = new DateTime('today', $tz);
    $reservationDate = DateTime::createFromFormat('Y-m-d', $date, $tz);
    if (!$reservationDate) {
        return ['ok' => false, 'message' => 'Invalid date format.', 'error' => 'validation', 'http' => 422];
    }
    if ($reservationDate < $today) {
        return [
            'ok' => false,
            'message' => 'Cannot book facilities for past dates. Please select today or a future date.',
            'error' => 'validation',
            'http' => 422,
        ];
    }

    $cfg = frs_resident_booking_limit_config();
    $advanceDays = (int) ($cfg['advance_max_days'] ?? 60);
    if (frs_booking_limits_apply_to_user($pdo, $userId)) {
        $maxDate = (clone $today)->modify('+' . $advanceDays . ' days');
        if ($reservationDate > $maxDate) {
            return [
                'ok' => false,
                'message' => "Bookings are allowed only up to {$advanceDays} days in advance.",
                'error' => 'advance_limit',
                'http' => 422,
            ];
        }
    }

    $slotCheck = frs_validate_booking_time_slot($timeSlot);
    if (empty($slotCheck['ok'])) {
        return [
            'ok' => false,
            'message' => (string) $slotCheck['message'],
            'error' => (string) ($slotCheck['error'] ?: 'invalid_time_slot'),
            'http' => 422,
        ];
    }

    $startHi = (string) $slotCheck['start'];
    if (function_exists('frs_is_start_time_past_for_date') && frs_is_start_time_past_for_date($date, $startHi)) {
        $earliestHi = function_exists('frs_minutes_to_hhmm') && function_exists('frs_earliest_bookable_start_minutes')
            ? frs_minutes_to_hhmm(frs_earliest_bookable_start_minutes())
            : 'now';
        return [
            'ok' => false,
            'message' => 'For reservations today, the earliest start time is ' . $earliestHi . ' (Philippine time). Past time slots are not available.',
            'error' => 'time_in_past',
            'http' => 422,
        ];
    }

    if ($attendees < 1) {
        return [
            'ok' => false,
            'message' => 'Expected number of attendees is required (enter at least 1).',
            'error' => 'validation',
            'http' => 422,
        ];
    }

    $fac = $pdo->prepare(
        'SELECT id, name, status, is_free, base_rate, capacity FROM facilities WHERE id = ? AND status != "deleted" LIMIT 1'
    );
    $fac->execute([$facilityId]);
    $facility = $fac->fetch(PDO::FETCH_ASSOC);
    if (!$facility) {
        return ['ok' => false, 'message' => 'Facility not found.', 'error' => 'not_found', 'http' => 404];
    }

    $capCell = (string) ($facility['capacity'] ?? '');
    if ($capCell !== '' && preg_match('/(\d{1,7})/', $capCell, $cm)) {
        $maxListed = (int) $cm[1];
        if ($attendees > $maxListed) {
            return [
                'ok' => false,
                'message' => 'Expected attendees (' . $attendees . ') cannot exceed this facility\'s maximum occupancy (' . $maxListed . ').',
                'error' => 'capacity_exceeded',
                'http' => 422,
            ];
        }
    }

    $dateOk = frs_facility_date_bookable($pdo, $facilityId, $date, $facility);
    if (empty($dateOk['ok'])) {
        return [
            'ok' => false,
            'message' => (string) $dateOk['message'],
            'error' => (string) ($dateOk['error'] ?: 'facility_unavailable'),
            'http' => 400,
        ];
    }

    if (!$skipQuota && frs_booking_limits_apply_to_user($pdo, $userId)) {
        // Exclude current reservation when rescheduling so it does not double-count.
        if ($excludeId && $excludeId > 0) {
            $activeSql = frs_active_booking_statuses_sql($pdo);
            $tmpOk = frs_validate_resident_booking_limits_excluding(
                $pdo,
                $userId,
                $date,
                $activeSql,
                $excludeId
            );
            if (empty($tmpOk['ok'])) {
                return [
                    'ok' => false,
                    'message' => (string) $tmpOk['message'],
                    'error' => 'booking_limit',
                    'http' => 409,
                ];
            }
        } else {
            $limitCheck = frs_validate_resident_booking_limits(
                $pdo,
                $userId,
                $date,
                frs_active_booking_statuses_sql($pdo)
            );
            if (empty($limitCheck['ok'])) {
                return [
                    'ok' => false,
                    'message' => (string) $limitCheck['message'],
                    'error' => 'booking_limit',
                    'http' => 409,
                ];
            }
        }
    }

    if (function_exists('detectBookingConflict')) {
        $conflict = detectBookingConflict($facilityId, $date, $timeSlot, $excludeId);
        if (!empty($conflict['has_conflict'])) {
            return [
                'ok' => false,
                'message' => (string) ($conflict['message'] ?? 'Time slot conflicts with an existing reservation.'),
                'error' => 'conflict',
                'http' => 409,
            ];
        }
    } elseif (function_exists('frs_has_overlapping_booking')
        && frs_has_overlapping_booking($pdo, $facilityId, $date, $timeSlot, $excludeId)) {
        return [
            'ok' => false,
            'message' => 'The selected date and time overlaps an existing booking. Please choose another time.',
            'error' => 'conflict',
            'http' => 409,
        ];
    }

    return [
        'ok' => true,
        'message' => '',
        'error' => '',
        'http' => 200,
        'facility' => $facility,
    ];
}

/**
 * Same as frs_validate_resident_booking_limits but ignores one reservation id (reschedule).
 *
 * @return array{ok: bool, message: string}
 */
function frs_validate_resident_booking_limits_excluding(
    PDO $pdo,
    int $userId,
    string $reservationDate,
    string $activeStatusesSql,
    int $excludeReservationId
): array {
    if (!frs_booking_limits_apply_to_user($pdo, $userId)) {
        return ['ok' => true, 'message' => ''];
    }

    $cfg = frs_resident_booking_limit_config();
    $today = date('Y-m-d');
    $exclude = max(0, $excludeReservationId);

    $upcomingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid
           AND reservation_date >= :today
           AND status IN (' . $activeStatusesSql . ')
           AND id != :exclude'
    );
    $upcomingStmt->execute(['uid' => $userId, 'today' => $today, 'exclude' => $exclude]);
    $upcoming = (int) $upcomingStmt->fetchColumn();
    if ($upcoming >= $cfg['max_upcoming_active']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: You can have at most ' . $cfg['max_upcoming_active']
                . ' active upcoming reservation(s) at a time.',
        ];
    }

    $perDayStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date = :date
           AND status IN (' . $activeStatusesSql . ')
           AND id != :exclude'
    );
    $perDayStmt->execute(['uid' => $userId, 'date' => $reservationDate, 'exclude' => $exclude]);
    if ((int) $perDayStmt->fetchColumn() >= $cfg['per_day']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Only ' . $cfg['per_day'] . ' active reservation per day is allowed.',
        ];
    }

    $weekStart = date('Y-m-d', strtotime($reservationDate . ' -6 days'));
    $weekStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date BETWEEN :start AND :end
           AND status IN (' . $activeStatusesSql . ')
           AND id != :exclude'
    );
    $weekStmt->execute([
        'uid' => $userId,
        'start' => $weekStart,
        'end' => $reservationDate,
        'exclude' => $exclude,
    ]);
    if ((int) $weekStmt->fetchColumn() >= $cfg['per_week']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Maximum ' . $cfg['per_week'] . ' reservations per week.',
        ];
    }

    $monthStart = date('Y-m-d', strtotime($reservationDate . ' -29 days'));
    $monthStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date BETWEEN :start AND :end
           AND status IN (' . $activeStatusesSql . ')
           AND id != :exclude'
    );
    $monthStmt->execute([
        'uid' => $userId,
        'start' => $monthStart,
        'end' => $reservationDate,
        'exclude' => $exclude,
    ]);
    if ((int) $monthStmt->fetchColumn() >= $cfg['per_month']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Maximum ' . $cfg['per_month'] . ' reservations per month.',
        ];
    }

    $yearStart = substr($reservationDate, 0, 4) . '-01-01';
    $yearEnd = substr($reservationDate, 0, 4) . '-12-31';
    $yearStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations
         WHERE user_id = :uid AND reservation_date BETWEEN :start AND :end
           AND status IN (' . $activeStatusesSql . ')
           AND id != :exclude'
    );
    $yearStmt->execute([
        'uid' => $userId,
        'start' => $yearStart,
        'end' => $yearEnd,
        'exclude' => $exclude,
    ]);
    if ((int) $yearStmt->fetchColumn() >= $cfg['per_year']) {
        return [
            'ok' => false,
            'message' => 'Limit reached: Maximum ' . $cfg['per_year'] . ' reservations per year.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Apply staff approve / deny / cancel (or pending_payment) for a reservation row.
 *
 * @param array<string, mixed> $reservation Row with facility_name, facility_status, status, reservation_date, time_slot, requester_id, requester_email, requester_name; optional postponed_priority
 * @return array{final_action: string, message: string}
 */
function frs_staff_apply_status_decision(
    PDO $pdo,
    int $reservationId,
    string $action,
    array $reservation,
    bool $approvalFirstThenPayment,
    ?string $note
): array {
    if (!in_array($action, ['approved', 'denied', 'cancelled'], true)) {
        throw new InvalidArgumentException('Unsupported status action.');
    }

    if ($action === 'approved') {
        $facilityStatus = strtolower((string)($reservation['facility_status'] ?? 'available'));
        if ($facilityStatus === 'offline') {
            throw new Exception(
                'Cannot approve reservation: The facility "' . htmlspecialchars((string)$reservation['facility_name'])
                . '" is currently Offline. Please change the facility status to "Available" before approving reservations.'
            );
        }

        // Check if reservation date is blacked out (includes CIMM Sync window days)
        $reservationDate = (string)($reservation['reservation_date'] ?? '');
        $facilityId = (int)($reservation['facility_id'] ?? 0);
        if ($reservationDate !== '' && $facilityId > 0) {
            $blackoutCheck = $pdo->prepare(
                'SELECT id FROM facility_blackout_dates WHERE facility_id = ? AND blackout_date = ? LIMIT 1'
            );
            $blackoutCheck->execute([$facilityId, $reservationDate]);
            if ($blackoutCheck->fetchColumn()) {
                throw new Exception(
                    'Cannot approve reservation: The date "' . htmlspecialchars($reservationDate)
                    . '" is blacked out for this facility. Please choose a different date or remove the blackout date.'
                );
            }
        }

        // Staff "maintenance" with no dated blackouts → still block approvals.
        // CIMM facilities keep status=maintenance during the window but allow
        // approving reservations on days outside the blackout range.
        if ($facilityStatus === 'maintenance' && $facilityId > 0) {
            $anyBo = $pdo->prepare('SELECT 1 FROM facility_blackout_dates WHERE facility_id = ? LIMIT 1');
            $anyBo->execute([$facilityId]);
            if (!$anyBo->fetchColumn()) {
                throw new Exception(
                    'Cannot approve reservation: The facility "' . htmlspecialchars((string)$reservation['facility_name'])
                    . '" is currently under Maintenance. Please change the facility status to "Available" before approving reservations.'
                );
            }
        }
    }

    $finalAction = $action;
    $finalNote = $note;

    // Check if facility is free - if so, skip payment step
    $facilityIsFree = false;
    if (isset($reservation['is_free'])) {
        $facilityIsFree = (bool)$reservation['is_free'];
    } else {
        // Fetch facility is_free status if not provided
        $facilityId = (int)($reservation['facility_id'] ?? 0);
        if ($facilityId > 0) {
            $facilityStmt = $pdo->prepare('SELECT is_free FROM facilities WHERE id = ?');
            $facilityStmt->execute([$facilityId]);
            $facilityData = $facilityStmt->fetch(PDO::FETCH_ASSOC);
            if ($facilityData) {
                $facilityIsFree = (bool)$facilityData['is_free'];
            }
        }
    }

    // Only require payment if: action is approved, payments are enabled, AND facility is NOT free
    // AND reservation doesn't already have a paid payment (e.g., rescheduled paid reservations)
    $hasPaidPayment = false;
    if ($approvalFirstThenPayment && !$facilityIsFree && ($reservation['status'] ?? '') === 'pending') {
        // Check if reservation already has a paid payment (e.g., from before rescheduling)
        $paymentCheck = $pdo->prepare('SELECT id FROM payments WHERE reservation_id = :reservation_id AND status = :status LIMIT 1');
        $paymentCheck->execute(['reservation_id' => $reservationId, 'status' => 'paid']);
        $hasPaidPayment = (bool)$paymentCheck->fetchColumn();

        if (!$hasPaidPayment) {
            $finalAction = 'pending_payment';
            $approvalPaymentNote = 'Approved by staff. Awaiting payment confirmation to finalize reservation.';
            $finalNote = !empty($finalNote) ? ($finalNote . ' | ' . $approvalPaymentNote) : $approvalPaymentNote;
        }
    }

    if ($finalAction === 'pending_payment') {
        $hold = frs_payment_hold_timestamps();
        $stmt = $pdo->prepare(
            'UPDATE reservations SET status = :status, payment_due_at = :payment_due_at, expires_at = :expires_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([
            'status' => $finalAction,
            'payment_due_at' => $hold['payment_due_at'],
            'expires_at' => $hold['expires_at'],
            'id' => $reservationId,
        ]);
    } elseif ($finalAction === 'approved' && ($reservation['status'] ?? '') === 'postponed') {
        $stmt = $pdo->prepare('UPDATE reservations SET status = :status, postponed_at = NULL, postponed_priority = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'status' => $finalAction,
            'id' => $reservationId,
        ]);
    } else {
        $stmt = $pdo->prepare('UPDATE reservations SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'status' => $finalAction,
            'id' => $reservationId,
        ]);
    }

    // Handle refund for cancelled and denied reservations with payments
    if ($finalAction === 'cancelled' || $finalAction === 'denied') {
        require_once __DIR__ . '/paymongo_helper.php';
        $paymentStmt = $pdo->prepare(
            'SELECT id, amount, reference_no, provider_event_id, provider_checkout_id, payload_json
             FROM payments
             WHERE reservation_id = :reservation_id AND status = :status
             LIMIT 1'
        );
        $paymentStmt->execute(['reservation_id' => $reservationId, 'status' => 'paid']);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        if ($payment && (float)($payment['amount'] ?? 0) > 0) {
            $refundOutcome = frs_refund_payment_row(
                $pdo,
                $payment,
                'requested_by_customer',
                'Reservation ' . $finalAction . ' — RES-' . $reservationId
            );
            if (!empty($refundOutcome['refunded'])) {
                $finalNote = !empty($finalNote)
                    ? ($finalNote . ' Payment refunded via PayMongo.')
                    : 'Payment refunded via PayMongo.';
            } else {
                $finalNote = !empty($finalNote)
                    ? ($finalNote . ' Refund failed: ' . ($refundOutcome['message'] ?? 'unknown error'))
                    : 'Refund failed: ' . ($refundOutcome['message'] ?? 'unknown error');
            }
        }
    }

    $hist = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:id, :status, :note, :user)');
    $hist->execute([
        'id' => $reservationId,
        'status' => $finalAction,
        'note' => $finalNote,
        'user' => $_SESSION['user_id'] ?? null,
    ]);

    $details = 'RES-' . $reservationId . ' – ' . ($reservation['facility_name'] ?? 'Unknown Facility');
    if (!empty($reservation['reservation_date']) && !empty($reservation['time_slot'])) {
        $details .= ' (' . $reservation['reservation_date'] . ' ' . $reservation['time_slot'] . ')';
    }
    if (!empty($finalNote)) {
        $details .= ' – Note: ' . $finalNote;
    }
    logAudit(ucfirst($finalAction) . ' reservation', 'Reservations', $details);

    $requesterId = (int)($reservation['requester_id'] ?? 0);
    if ($requesterId > 0) {
        $notifTitle = $finalAction === 'approved'
            ? 'Reservation Approved'
            : ($finalAction === 'pending_payment'
                ? 'Reservation Approved - Payment Required'
                : ($finalAction === 'denied' ? 'Reservation Denied' : 'Reservation Cancelled'));
        $notifMessage = 'Your reservation request for ' . $reservation['facility_name'];
        $notifMessage .= ' on ' . date('F j, Y', strtotime((string)$reservation['reservation_date'])) . ' (' . $reservation['time_slot'] . ')';
        if ($finalAction === 'pending_payment') {
            $notifMessage .= ' has been approved and is awaiting payment confirmation.';
        } else {
            $notifMessage .= ' has been ' . $finalAction . '.';
        }
        if ($finalAction === 'approved' && ($reservation['status'] ?? '') === 'postponed' && !empty($reservation['postponed_priority'])) {
            $notifMessage .= ' This reservation had priority due to previous postponement.';
        }
        if (!empty($finalNote)) {
            $notifMessage .= ' Note: ' . $finalNote;
        }
        $notifLink = $finalAction === 'pending_payment'
            ? (base_path() . '/dashboard/pay-now?reservation_id=' . $reservationId)
            : (base_path() . '/dashboard/my-reservations');
        createNotification($requesterId, 'booking', $notifTitle, $notifMessage, $notifLink);

        require_once __DIR__ . '/mail_helper.php';
        require_once __DIR__ . '/email_templates.php';
        require_once __DIR__ . '/sms_helper.php';
        require_once __DIR__ . '/notification_preferences.php';

        $reservation['user_id'] = $requesterId;
        if (frs_user_wants_notification($requesterId, 'booking', 'email')
            && !empty($reservation['requester_email'])
            && !empty($reservation['requester_name'])) {
            if ($finalAction === 'approved') {
                $emailBody = getReservationApprovedEmailTemplate(
                    $reservation['requester_name'],
                    $reservation['facility_name'],
                    $reservation['reservation_date'],
                    $reservation['time_slot'],
                    $note ?? ''
                );
                sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Approved', $emailBody);
                sendReservationStatusSms($reservation, 'approved');
            } elseif ($finalAction === 'pending_payment') {
                $emailBody = '<p>Hi ' . htmlspecialchars((string)$reservation['requester_name']) . ',</p>'
                    . '<p>Your reservation for <strong>' . htmlspecialchars((string)$reservation['facility_name']) . '</strong> on '
                    . '<strong>' . date('F j, Y', strtotime((string)$reservation['reservation_date'])) . '</strong> ('
                    . htmlspecialchars((string)$reservation['time_slot']) . ') has been approved.</p>'
                    . '<p><strong>Next step:</strong> Please complete your payment to finalize the reservation.</p>'
                    . '<p><a href="' . htmlspecialchars(base_url() . '/dashboard/pay-now?reservation_id=' . $reservationId, ENT_QUOTES, 'UTF-8') . '">Pay Now</a></p>';
                sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Approved - Payment Required', $emailBody);
                sendReservationStatusSms($reservation, 'pending_payment');
            } elseif ($finalAction === 'denied') {
                $emailBody = getReservationDeniedEmailTemplate(
                    $reservation['requester_name'],
                    $reservation['facility_name'],
                    $reservation['reservation_date'],
                    $reservation['time_slot'],
                    $note ?? ''
                );
                sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Denied', $emailBody);
                sendReservationStatusSms($reservation, 'denied');
            } elseif ($finalAction === 'cancelled') {
                $emailBody = getReservationCancelledEmailTemplate(
                    $reservation['requester_name'],
                    $reservation['facility_name'],
                    $reservation['reservation_date'],
                    $reservation['time_slot'],
                    $note ?? ''
                );
                sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Cancelled', $emailBody);
                sendReservationStatusSms($reservation, 'cancelled');
            }
        }
    }

    return [
        'final_action' => $finalAction,
        'message' => $finalAction === 'pending_payment'
            ? 'Reservation approved. User must complete payment to finalize the booking.'
            : (ucfirst($finalAction) . ' reservation successfully.'),
    ];
}

/**
 * Staff reschedule of a postponed+priority reservation onto a new slot, then auto-approve.
 * Skips payment when the facility is free or the reservation already has a paid payment.
 *
 * @param array<string,mixed> $reservation Row with facility_name, facility_status, is_free, requester_*, etc.
 * @return array{final_action:string,message:string,old_date:string,old_time:string,new_date:string,new_time:string}
 */
function frs_staff_reschedule_postponed_priority(
    PDO $pdo,
    int $reservationId,
    array $reservation,
    string $newDate,
    string $newTimeSlot,
    string $reason,
    bool $approvalFirstThenPayment
): array {
    $status = strtolower((string)($reservation['status'] ?? ''));
    $hasPriority = !empty($reservation['postponed_priority']);
    if ($status !== 'postponed' || !$hasPriority) {
        throw new Exception('Reschedule is only available for postponed reservations with priority.');
    }

    $oldDate = (string)($reservation['reservation_date'] ?? '');
    $oldTime = (string)($reservation['time_slot'] ?? '');
    $facilityId = (int)($reservation['facility_id'] ?? 0);
    $facilityName = (string)($reservation['facility_name'] ?? 'Facility');
    $facilityStatus = strtolower((string)($reservation['facility_status'] ?? 'available'));

    if ($facilityId <= 0) {
        throw new Exception('Invalid facility on this reservation.');
    }
    if ($facilityStatus === 'offline') {
        throw new Exception(
            'Cannot reschedule: The facility "' . htmlspecialchars($facilityName)
            . '" is currently Offline.'
        );
    }

    $newDate = trim($newDate);
    $newTimeSlot = trim($newTimeSlot);
    $reason = trim($reason);
    if ($newDate === '' || $newTimeSlot === '') {
        throw new Exception('New date and time are required.');
    }
    if ($reason === '') {
        throw new Exception('A reason is required when rescheduling a postponed reservation.');
    }

    $newDateObj = DateTime::createFromFormat('Y-m-d', $newDate) ?: new DateTime($newDate);
    $today = new DateTime('today');
    if ($newDateObj < $today) {
        throw new Exception('New reservation date cannot be in the past.');
    }

    if (!preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $newTimeSlot)) {
        throw new Exception('Invalid time slot format.');
    }

    if ($newDate === $oldDate && $newTimeSlot === $oldTime) {
        throw new Exception('Please choose a different date or time from the current postponed schedule.');
    }

    if (frs_has_overlapping_booking($pdo, $facilityId, $newDate, $newTimeSlot, $reservationId)) {
        throw new Exception('The selected date and time overlaps an existing booking. Please choose another time.');
    }

    $blackoutCheck = $pdo->prepare(
        'SELECT id FROM facility_blackout_dates WHERE facility_id = ? AND blackout_date = ? LIMIT 1'
    );
    $blackoutCheck->execute([$facilityId, $newDate]);
    if ($blackoutCheck->fetchColumn()) {
        throw new Exception(
            'Cannot reschedule to "' . htmlspecialchars($newDate)
            . '": that date is blacked out or under scheduled maintenance. Choose another date.'
        );
    }

    if ($facilityStatus === 'maintenance') {
        $anyBo = $pdo->prepare('SELECT 1 FROM facility_blackout_dates WHERE facility_id = ? LIMIT 1');
        $anyBo->execute([$facilityId]);
        if (!$anyBo->fetchColumn()) {
            throw new Exception(
                'Cannot reschedule: The facility "' . htmlspecialchars($facilityName)
                . '" is under maintenance with no dated window. Set it to Available first or pick a cleared date after sync.'
            );
        }
    }

    $facilityIsFree = !empty($reservation['is_free']);
    if (!isset($reservation['is_free'])) {
        $freeStmt = $pdo->prepare('SELECT is_free FROM facilities WHERE id = ?');
        $freeStmt->execute([$facilityId]);
        $facilityIsFree = (bool)$freeStmt->fetchColumn();
    }

    $hasPaidPayment = false;
    $paymentCheck = $pdo->prepare(
        'SELECT id FROM payments WHERE reservation_id = :reservation_id AND status = :status LIMIT 1'
    );
    $paymentCheck->execute(['reservation_id' => $reservationId, 'status' => 'paid']);
    $hasPaidPayment = (bool)$paymentCheck->fetchColumn();

    $finalAction = 'approved';
    if ($approvalFirstThenPayment && !$facilityIsFree && !$hasPaidPayment) {
        $finalAction = 'pending_payment';
    }

    if ($finalAction === 'pending_payment') {
        $hold = frs_payment_hold_timestamps();
        $stmt = $pdo->prepare(
            'UPDATE reservations
             SET reservation_date = :new_date,
                 time_slot = :new_time,
                 status = :status,
                 postponed_at = NULL,
                 postponed_priority = FALSE,
                 payment_due_at = :payment_due_at,
                 expires_at = :expires_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'new_date' => $newDate,
            'new_time' => $newTimeSlot,
            'status' => $finalAction,
            'payment_due_at' => $hold['payment_due_at'],
            'expires_at' => $hold['expires_at'],
            'id' => $reservationId,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE reservations
             SET reservation_date = :new_date,
                 time_slot = :new_time,
                 status = :status,
                 postponed_at = NULL,
                 postponed_priority = FALSE,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'new_date' => $newDate,
            'new_time' => $newTimeSlot,
            'status' => $finalAction,
            'id' => $reservationId,
        ]);
    }

    $histNote = 'Staff rescheduled priority postponement from '
        . $oldDate . ' ' . $oldTime . ' to ' . $newDate . ' ' . $newTimeSlot
        . '. Auto-' . ($finalAction === 'pending_payment' ? 'approved pending payment' : 'approved')
        . '. Reason: ' . $reason;

    $hist = $pdo->prepare(
        'INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:id, :status, :note, :user)'
    );
    $hist->execute([
        'id' => $reservationId,
        'status' => $finalAction,
        'note' => $histNote,
        'user' => $_SESSION['user_id'] ?? null,
    ]);

    logAudit(
        'Staff rescheduled postponed reservation',
        'Reservations',
        'RES-' . $reservationId . ' – ' . $facilityName . ' – ' . $histNote
    );

    $requesterId = (int)($reservation['requester_id'] ?? 0);
    if ($requesterId > 0) {
        $notifTitle = $finalAction === 'pending_payment'
            ? 'Reservation Rescheduled — Payment Required'
            : 'Reservation Rescheduled & Confirmed';
        $notifMessage = 'Your postponed reservation for ' . $facilityName
            . ' was rescheduled from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTime . ')'
            . ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
        if ($finalAction === 'approved') {
            $notifMessage .= ' It is confirmed (no re-approval needed).';
        } else {
            $notifMessage .= ' Please complete payment to finalize.';
        }
        $notifMessage .= ' Reason: ' . $reason;
        $notifLink = $finalAction === 'pending_payment'
            ? (base_path() . '/dashboard/pay-now?reservation_id=' . $reservationId)
            : (base_path() . '/dashboard/my-reservations');
        createNotification($requesterId, 'booking', $notifTitle, $notifMessage, $notifLink);

        require_once __DIR__ . '/mail_helper.php';
        require_once __DIR__ . '/email_templates.php';
        require_once __DIR__ . '/sms_helper.php';
        require_once __DIR__ . '/notification_preferences.php';

        if (frs_user_wants_notification($requesterId, 'booking', 'email')
            && !empty($reservation['requester_email'])
            && !empty($reservation['requester_name'])) {
            $emailBody = getStaffPriorityRescheduleEmailTemplate(
                (string)$reservation['requester_name'],
                $facilityName,
                $oldDate,
                $oldTime,
                $newDate,
                $newTimeSlot,
                $reason,
                $finalAction === 'pending_payment'
            );
            $subject = $finalAction === 'pending_payment'
                ? 'Reservation Rescheduled — Payment Required'
                : 'Reservation Rescheduled & Confirmed';
            sendEmail(
                (string)$reservation['requester_email'],
                (string)$reservation['requester_name'],
                $subject,
                $emailBody
            );
        }

        $reservation['user_id'] = $requesterId;
        $reservation['reservation_date'] = $newDate;
        $reservation['time_slot'] = $newTimeSlot;
        if (function_exists('sendReservationStatusSms')) {
            sendReservationStatusSms($reservation, $finalAction === 'pending_payment' ? 'pending_payment' : 'approved');
        }
    }

    return [
        'final_action' => $finalAction,
        'old_date' => $oldDate,
        'old_time' => $oldTime,
        'new_date' => $newDate,
        'new_time' => $newTimeSlot,
        'message' => $finalAction === 'pending_payment'
            ? 'Reservation rescheduled. Requester must complete payment to finalize.'
            : 'Reservation rescheduled and auto-approved. The requester has been emailed.',
    ];
}
