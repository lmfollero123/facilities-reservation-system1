<?php
$frsMineCsrfOk = frs_csrf_ok();
$frsMineNotifyRel = $GLOBALS['frsMineNotifyPath'] ?? (base_path() . '/dashboard/book-facility?module=mine');
$frsMineNotifyAbs = $GLOBALS['frsMineNotifyAbsUrl'] ?? (base_url() . '/dashboard/book-facility?module=mine');

// Handle edit details (purpose, expected_attendees) - no approval unless capacity exceeded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $frsMineCsrfOk && isset($_POST['action']) && $_POST['action'] === 'edit_details' && $userId) {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $newPurpose = trim($_POST['purpose'] ?? '');
    $newAttendees = isset($_POST['expected_attendees']) && $_POST['expected_attendees'] !== '' ? (int)$_POST['expected_attendees'] : null;
    
    try {
        if (empty($newPurpose)) {
            throw new Exception('Purpose is required.');
        }
        if ($newAttendees !== null && $newAttendees < 0) {
            $newAttendees = null;
        }
        
        $resStmt = $pdo->prepare(
            'SELECT r.id, r.purpose, r.expected_attendees, r.status, r.facility_id, f.name AS facility_name, f.capacity_threshold
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             WHERE r.id = :id AND r.user_id = :user_id'
        );
        $resStmt->execute(['id' => $reservationId, 'user_id' => $userId]);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            throw new Exception('Reservation not found or you do not have permission to edit it.');
        }
        
        if (!in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)) {
            throw new Exception('Only pending, approved, or postponed reservations can be edited.');
        }
        
        $capacityThreshold = isset($reservation['capacity_threshold']) && $reservation['capacity_threshold'] !== null && $reservation['capacity_threshold'] !== '' ? (int)$reservation['capacity_threshold'] : null;
        $requiresReapproval = ($capacityThreshold !== null && $newAttendees !== null && $newAttendees > $capacityThreshold);
        
        $newStatus = ($requiresReapproval && $reservation['status'] === 'approved') ? 'pending' : $reservation['status'];
        
        $updateStmt = $pdo->prepare('UPDATE reservations SET purpose = :purpose, expected_attendees = :expected_attendees, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $updateStmt->execute([
            'purpose' => $newPurpose,
            'expected_attendees' => $newAttendees,
            'status' => $newStatus,
            'id' => $reservationId
        ]);
        
        $historyNote = 'Purpose and/or expected attendees updated by user.';
        if ($requiresReapproval) {
            $historyNote .= ' Re-approval required: attendee count (' . $newAttendees . ') exceeds facility threshold (' . $capacityThreshold . ').';
        }
        
        $histStmt = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:reservation_id, :status, :note, :user_id)');
        $histStmt->execute([
            'reservation_id' => $reservationId,
            'status' => $newStatus,
            'note' => $historyNote,
            'user_id' => $userId
        ]);
        
        logAudit('Edited reservation details (purpose/attendees)', 'Reservations', 'RES-' . $reservationId . ' – ' . $reservation['facility_name']);
        
        $message = 'Details updated successfully.' . ($requiresReapproval ? ' Re-approval is required because attendee count exceeds facility capacity threshold.' : '');
        $messageType = 'success';
        
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Handle reschedule request (conceptually: user requests; staff applies changes upon approval)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $frsMineCsrfOk && isset($_POST['action']) && $_POST['action'] === 'reschedule' && $userId) {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $newDate = $_POST['new_date'] ?? '';
    $newTimeSlot = $_POST['new_time_slot'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    
    try {
        // Get reservation details with facility status
        $resStmt = $pdo->prepare(
            'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.reschedule_count, 
                    f.name AS facility_name, f.id AS facility_id, f.status AS facility_status
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             WHERE r.id = :id AND r.user_id = :user_id'
        );
        $resStmt->execute(['id' => $reservationId, 'user_id' => $userId]);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            throw new Exception('Reservation not found or you do not have permission to reschedule it.');
        }
        
        // Status-based constraints: Only pending, approved, or postponed can be rescheduled
        if (!in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)) {
            if ($reservation['status'] === 'denied') {
                throw new Exception('Rejected reservations cannot be rescheduled. Please create a new reservation request.');
            } elseif ($reservation['status'] === 'cancelled') {
                throw new Exception('Cancelled reservations cannot be rescheduled. Please create a new reservation request.');
            } elseif ($reservation['status'] === 'on_hold') {
                throw new Exception('Reservations on hold cannot be rescheduled at this time. Please contact support.');
            } else {
                throw new Exception('This reservation cannot be rescheduled due to its current status.');
            }
        }
        
        $reservationDate = new DateTime($reservation['reservation_date']);
        $today = new DateTime('today');

        if (frs_reservation_slot_has_passed((string)$reservation['reservation_date'], (string)$reservation['time_slot'])
            || frs_reservation_slot_is_ongoing((string)$reservation['reservation_date'], (string)$reservation['time_slot'])) {
            throw new Exception('Cannot reschedule a reservation that has already started or is ongoing.');
        }
        
        // Constraint 1: Reschedule allowed up to 3 days before event (same-day not allowed)
        $daysUntil = $today->diff($reservationDate)->days;
        
        if ($daysUntil < 3) {
            throw new Exception('Rescheduling is only allowed up to 3 days before the event. The event is ' . $daysUntil . ' day(s) away. Same-day rescheduling is not allowed.');
        }
        
        // Constraint 2: One reschedule per reservation
        $rescheduleCount = (int)($reservation['reschedule_count'] ?? 0);
        if ($rescheduleCount >= 1) {
            throw new Exception('This reservation has already been rescheduled once. Only one reschedule is allowed per reservation.');
        }
        
        // Facility availability check: Facility must be active
        if ($reservation['facility_status'] !== 'available') {
            throw new Exception('Cannot reschedule to a facility that is currently ' . $reservation['facility_status'] . '. Please contact support for assistance.');
        }
        
        // Validate new date and time slot
        if (empty($newDate) || empty($newTimeSlot)) {
            throw new Exception('New date and time slot are required.');
        }
        
        if ($newDate === $reservation['reservation_date'] && $newTimeSlot === $reservation['time_slot']) {
            throw new Exception('You are already scheduled for this date and time. Please select a different date or time slot to reschedule.');
        }
        
        if (empty($reason)) {
            throw new Exception('Reason for rescheduling is required.');
        }
        
        // Validate new date is not in the past
        $newDateObj = new DateTime($newDate);
        if ($newDateObj < $today) {
            throw new Exception('New reservation date cannot be in the past. Please select today or a future date.');
        }
        
        // Check facility status for new date (facility must be active)
        $facilityCheck = $pdo->prepare('SELECT status FROM facilities WHERE id = :facility_id');
        $facilityCheck->execute(['facility_id' => $reservation['facility_id']]);
        $facilityStatus = $facilityCheck->fetchColumn();
        
        if ($facilityStatus !== 'available') {
            throw new Exception('The facility is currently ' . $facilityStatus . ' and not available for the selected date. Please choose another date or facility.');
        }
        
        if (frs_has_overlapping_booking($pdo, (int)$reservation['facility_id'], $newDate, $newTimeSlot, $reservationId)) {
            throw new Exception('The selected date and time overlaps an existing booking. Please choose another time.');
        }
        
        // Update reservation
        $oldDate = $reservation['reservation_date'];
        $oldTimeSlot = $reservation['time_slot'];
        $oldStatus = $reservation['status'];
        
        // Constraint 3: Approved and postponed reservations require re-approval
        // Postponed reservations keep priority but go back to pending for re-approval
        $newStatus = (in_array($oldStatus, ['approved', 'postponed'], true)) ? 'pending' : $oldStatus;
        
        // Check if this is a postponed reservation with priority (keep priority when rescheduling)
        $priorityCheckStmt = $pdo->prepare('SELECT postponed_priority FROM reservations WHERE id = :id');
        $priorityCheckStmt->execute(['id' => $reservationId]);
        $hasPriority = (bool)$priorityCheckStmt->fetchColumn();
        
        // When rescheduling a postponed reservation, clear postponed_at but keep priority flag
        // The priority flag will remain so admins know this reservation has priority
        $updateStmt = $pdo->prepare(
            'UPDATE reservations 
             SET reservation_date = :new_date, 
                 time_slot = :new_time_slot, 
                 status = :new_status,
                 reschedule_count = reschedule_count + 1,
                 updated_at = CURRENT_TIMESTAMP,
                 postponed_at = NULL
             WHERE id = :id'
        );
        $updateStmt->execute([
            'new_date' => $newDate,
            'new_time_slot' => $newTimeSlot,
            'new_status' => $newStatus,
            'id' => $reservationId
        ]);
        
        // Note: postponed_priority flag is NOT cleared - it remains so admin knows this reservation has priority
        
        // Constraint 4: Full audit log maintained
        $historyNote = 'Rescheduled from ' . $oldDate . ' (' . $oldTimeSlot . ') to ' . $newDate . ' (' . $newTimeSlot . '). Reason: ' . $reason;
        if ($oldStatus === 'approved' || $oldStatus === 'postponed') {
            $historyNote .= ' Status changed to pending for re-approval.';
            if ($oldStatus === 'postponed' && $hasPriority) {
                $historyNote .= ' Priority status maintained - this reservation will be given preference during review.';
            }
        }
        
        $histStmt = $pdo->prepare(
            'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
             VALUES (:reservation_id, :status, :note, :user_id)'
        );
        $histStmt->execute([
            'reservation_id' => $reservationId,
            'status' => $newStatus,
            'note' => $historyNote,
            'user_id' => $userId
        ]);
        
        // Log audit event
        $auditDetails = 'RES-' . $reservationId . ' – ' . $reservation['facility_name'] . ' – Rescheduled from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason;
        logAudit('Requested reschedule (own reservation)', 'Reservations', $auditDetails);
        
        // Create notification
        $notifMessage = 'Your reservation for ' . $reservation['facility_name'];
        $notifMessage .= ' has been rescheduled from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
        $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
        if ($oldStatus === 'approved') {
            $notifMessage .= ' The new date requires re-approval.';
        }
        
        createNotification($userId, 'booking', 'Reschedule Request Submitted', $notifMessage,
            $frsMineNotifyRel);
        
        // Send email notification
        $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userInfo && !empty($userInfo['email'])) {
            $requiresApproval = ($oldStatus === 'approved' || $oldStatus === 'postponed');
            $emailBody = getReservationRescheduledEmailTemplate(
                $userInfo['name'],
                $reservation['facility_name'],
                $oldDate,
                $oldTimeSlot,
                $newDate,
                $newTimeSlot,
                $reason,
                $requiresApproval
            );
            sendEmail($userInfo['email'], $userInfo['name'], 'Reschedule Request Submitted', $emailBody);
        }
        
        $message = 'Reschedule request submitted successfully. ' . ($oldStatus === 'approved' || $oldStatus === 'postponed' ? 'Staff will review and apply changes upon approval.' : '');
        $messageType = 'success';
        
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Cancellation: for own reservations, status pending/approved, and before start time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $frsMineCsrfOk && isset($_POST['action']) && $_POST['action'] === 'cancel_reservation' && $userId) {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $userRole = $_SESSION['role'] ?? 'Resident';
    $isAdminOrStaff = in_array($userRole, ['Admin', 'Staff'], true);
    
    try {
        // Admin/Staff can cancel any reservation, residents only their own
        $whereClause = $isAdminOrStaff ? 'WHERE r.id = :id' : 'WHERE r.id = :id AND r.user_id = :user_id';
        $params = $isAdminOrStaff ? ['id' => $reservationId] : ['id' => $reservationId, 'user_id' => $userId];

        $resStmt = $pdo->prepare(
            'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.user_id,
                    f.name AS facility_name
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             ' . $whereClause
        );
        $resStmt->execute($params);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);

        if (!$reservation) {
            throw new Exception('Reservation not found or you do not have permission to cancel it.');
        }
        
        if (!in_array($reservation['status'], ['pending_payment', 'pending', 'approved'], true)) {
            throw new Exception('Only pending-payment, pending, or approved reservations can be cancelled. This reservation is already ' . $reservation['status'] . '.');
        }
        
        if (frs_reservation_slot_has_passed((string)$reservation['reservation_date'], (string)$reservation['time_slot'])) {
            throw new Exception('You cannot cancel a reservation that has already started or passed.');
        }
        
        $stmt = $pdo->prepare('UPDATE reservations SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['status' => 'cancelled', 'id' => $reservationId]);
        
        // Check if payment was made and issue refund
        $paymentStmt = $pdo->prepare('SELECT id, amount, reference_no, provider_event_id FROM payments WHERE reservation_id = :reservation_id AND status = :status LIMIT 1');
        $paymentStmt->execute(['reservation_id' => $reservationId, 'status' => 'paid']);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment) {
            // Issue refund via PayMongo if provider_event_id exists
            if (!empty($payment['provider_event_id'])) {
                $refundResult = frs_issue_refund($payment['provider_event_id'], $payment['amount']);

                if ($refundResult['ok']) {
                    // Update payment status to refunded
                    $updatePaymentStmt = $pdo->prepare('UPDATE payments SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $updatePaymentStmt->execute(['status' => 'refunded', 'id' => $payment['id']]);
                    $note = $isAdminOrStaff ? 'Cancelled by admin/staff. Payment refunded via PayMongo.' : 'Cancelled by user. Payment refunded via PayMongo.';
                } else {
                    $note = $isAdminOrStaff ? 'Cancelled by admin/staff. Refund failed: ' . $refundResult['message'] : 'Cancelled by user. Refund failed: ' . $refundResult['message'];
                }
            } else {
                // No provider_event_id - payment may not have been processed via webhook
                // Don't auto-refund, keep payment as paid and note that manual refund is needed
                $note = $isAdminOrStaff ? 'Cancelled by admin/staff. Payment exists but requires manual refund (no PayMongo event ID).' : 'Cancelled by user. Payment exists but requires manual refund (no PayMongo event ID).';
            }
        } else {
            $note = $isAdminOrStaff ? 'Cancelled by admin/staff.' : 'Cancelled by user.';
        }
        
        $histStmt = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:reservation_id, :status, :note, :user_id)');
        $histStmt->execute([
            'reservation_id' => $reservationId,
            'status' => 'cancelled',
            'note' => $note,
            'user_id' => $userId
        ]);
        
        logAudit('Resident self-cancelled reservation', 'Reservations', 'RES-' . $reservationId . ' – ' . $reservation['facility_name']);
        
        createNotification(
            $userId,
            'booking',
            'Reservation Cancelled',
            'Your reservation for ' . $reservation['facility_name'] . ' on ' . date('F j, Y', strtotime($reservation['reservation_date'])) . ' (' . $reservation['time_slot'] . ') has been cancelled. The time slot is now available for others.',
            $frsMineNotifyRel
        );

        $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($userInfo && !empty($userInfo['email'])) {
            $emailBody = getReservationCancelledEmailTemplate(
                $userInfo['name'],
                $reservation['facility_name'],
                $reservation['reservation_date'],
                $reservation['time_slot'],
                'Cancelled by you. The time slot is now available for others.',
                'View My Reservations',
                $frsMineNotifyAbs
            );
            sendEmail($userInfo['email'], $userInfo['name'], 'Reservation Cancelled', $emailBody);
        }
        
        $message = 'Reservation cancelled successfully. The time slot is now available for others.';
        $messageType = 'success';
        
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}
