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
        if ($facilityStatus === 'maintenance' || $facilityStatus === 'offline') {
            $statusLabel = ucfirst($facilityStatus);
            throw new Exception(
                'Cannot approve reservation: The facility "' . htmlspecialchars((string)$reservation['facility_name'])
                . '" is currently under ' . $statusLabel
                . '. Please change the facility status to "Available" before approving reservations.'
            );
        }
    }

    $finalAction = $action;
    $finalNote = $note;

    if ($action === 'approved' && $approvalFirstThenPayment && ($reservation['status'] ?? '') === 'pending') {
        $finalAction = 'pending_payment';
        $approvalPaymentNote = 'Approved by staff. Awaiting payment confirmation to finalize reservation.';
        $finalNote = !empty($finalNote) ? ($finalNote . ' | ' . $approvalPaymentNote) : $approvalPaymentNote;
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
        $stmt = $pdo->prepare('UPDATE reservations SET status = :status, postponed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
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
