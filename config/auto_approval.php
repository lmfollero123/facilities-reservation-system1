<?php
/**
 * Auto-Approval System for Facility Reservations
 * 
 * Evaluates reservation requests against barangay-defined rules to determine
 * if a reservation should be automatically approved or remain pending for manual review.
 * 
 * Auto-approval conditions (all must be true):
 * 1. Facility is marked auto_approve = true
 * 2. Reservation date is not in blackout dates
 * 3. Reservation duration is within allowed limit (≤ max_duration_hours)
 * 4. Expected attendees ≤ facility capacity threshold
 * 5. Purpose is non-commercial
 * 6. Reservation does not conflict with existing approved bookings
 * 7. User has no previous violations
 * 8. Reservation is within allowed advance booking window
 * 
 * @package FacilitiesReservation
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/time_helpers.php';

/**
 * Evaluates a reservation request against auto-approval conditions.
 * 
 * @param int $facilityId Facility ID
 * @param string $reservationDate Reservation date (Y-m-d format)
 * @param string $timeSlot Time slot string (e.g., "Morning (8AM - 12PM)")
 * @param int|null $expectedAttendees Expected number of attendees
 * @param bool $isCommercial Whether the reservation is for commercial purposes
 * @param int $userId User ID making the reservation
 * @param int $advanceBookingWindowDays Maximum days in advance for bookings (default: 60)
 * 
 * @return array {
 *     @var bool $eligible Whether reservation meets all conditions for auto-approval
 *     @var bool $auto_approve Whether to auto-approve (if eligible and facility allows it)
 *     @var array $conditions Array of condition check results with reasons
 *     @var string $reason Primary reason for rejection (if not eligible)
 * }
 */
function evaluateAutoApproval(
    int $facilityId,
    string $reservationDate,
    string $timeSlot,
    ?int $expectedAttendees,
    bool $isCommercial,
    int $userId,
    int $advanceBookingWindowDays = 60
): array {
    $pdo = db();
    $conditions = [];
    $allPassed = true;
    $reason = '';
    
    // Initialize result structure
    $result = [
        'eligible' => false,
        'auto_approve' => false,
        'conditions' => [],
        'reason' => ''
    ];
    
    // Condition 1: Facility must have auto_approve enabled
    $facilityStmt = $pdo->prepare(
        'SELECT auto_approve, capacity_threshold, max_duration_hours, capacity, status 
         FROM facilities 
         WHERE id = :facility_id'
    );
    $facilityStmt->execute(['facility_id' => $facilityId]);
    $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facility) {
        $result['reason'] = 'Facility not found';
        return $result;
    }
    
    if ($facility['status'] !== 'available') {
        $result['reason'] = 'Facility is not available';
        return $result;
    }
    
    $facilityAutoApprove = (bool)($facility['auto_approve'] ?? false);
    $conditions['facility_auto_approve_enabled'] = [
        'passed' => $facilityAutoApprove,
        'message' => $facilityAutoApprove 
            ? 'Facility allows auto-approval' 
            : 'Facility requires manual approval'
    ];
    
    if (!$facilityAutoApprove) {
        $allPassed = false;
        $reason = 'Facility does not allow auto-approval';
    }
    
    // Condition 2: Check blackout dates
    $blackoutStmt = $pdo->prepare(
        'SELECT id, reason FROM facility_blackout_dates 
         WHERE facility_id = :facility_id AND blackout_date = :date'
    );
    $blackoutStmt->execute([
        'facility_id' => $facilityId,
        'date' => $reservationDate
    ]);
    $blackout = $blackoutStmt->fetch(PDO::FETCH_ASSOC);
    
    $isBlackedOut = (bool)$blackout;
    $conditions['not_in_blackout'] = [
        'passed' => !$isBlackedOut,
        'message' => $isBlackedOut 
            ? 'Reservation date is in blackout period: ' . ($blackout['reason'] ?? 'No reason specified')
            : 'Reservation date is not blacked out'
    ];
    
    if ($isBlackedOut) {
        $allPassed = false;
        $reason = $reason ?: 'Reservation date is blacked out';
    }
    
    // Condition 3: Check reservation duration
    $durationHours = getDurationHours($timeSlot);
    $maxDurationHours = $facility['max_duration_hours'] ? (float)$facility['max_duration_hours'] : null;
    
    $durationWithinLimit = true;
    if ($maxDurationHours !== null && $durationHours > $maxDurationHours) {
        $durationWithinLimit = false;
    }
    
    $conditions['duration_within_limit'] = [
        'passed' => $durationWithinLimit,
        'message' => $maxDurationHours === null
            ? 'No duration limit set for this facility'
            : ($durationWithinLimit 
                ? "Reservation duration ({$durationHours}h) is within limit ({$maxDurationHours}h)"
                : "Reservation duration ({$durationHours}h) exceeds limit ({$maxDurationHours}h)")
    ];
    
    if (!$durationWithinLimit) {
        $allPassed = false;
        $reason = $reason ?: 'Reservation duration exceeds allowed limit';
    }
    
    // Condition 4: Check expected attendees against capacity threshold
    $capacityThreshold = $facility['capacity_threshold'] ? (int)$facility['capacity_threshold'] : null;
    $attendeesWithinCapacity = true;
    
    if ($capacityThreshold !== null && $expectedAttendees !== null) {
        if ($expectedAttendees > $capacityThreshold) {
            $attendeesWithinCapacity = false;
        }
    }
    
    $conditions['attendees_within_capacity'] = [
        'passed' => $attendeesWithinCapacity,
        'message' => $capacityThreshold === null
            ? 'No capacity threshold set for this facility'
            : ($expectedAttendees === null
                ? 'Expected attendees not specified'
                : ($attendeesWithinCapacity
                    ? "Expected attendees ({$expectedAttendees}) within capacity threshold ({$capacityThreshold})"
                    : "Expected attendees ({$expectedAttendees}) exceeds capacity threshold ({$capacityThreshold})"))
    ];
    
    if (!$attendeesWithinCapacity) {
        $allPassed = false;
        $reason = $reason ?: 'Expected attendees exceed capacity threshold';
    }
    
    // Condition 5: Purpose must be non-commercial
    $conditions['non_commercial'] = [
        'passed' => !$isCommercial,
        'message' => $isCommercial 
            ? 'Commercial reservations require manual approval'
            : 'Reservation is for non-commercial purposes'
    ];
    
    if ($isCommercial) {
        $allPassed = false;
        $reason = $reason ?: 'Commercial reservations require manual approval';
    }
    
    // Condition 6: Check for conflicts with existing approved bookings (overlapping time ranges)
    $conflictStmt = $pdo->prepare(
        'SELECT time_slot FROM reservations 
         WHERE facility_id = :facility_id 
           AND reservation_date = :date 
           AND status = "approved"'
    );
    $conflictStmt->execute([
        'facility_id' => $facilityId,
        'date' => $reservationDate
    ]);
    $existingSlots = $conflictStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $hasConflict = false;
    foreach ($existingSlots as $existingSlot) {
        if (timeSlotsOverlap($timeSlot, $existingSlot)) {
            $hasConflict = true;
            break;
        }
    }
    
    $conditions['no_conflict'] = [
        'passed' => !$hasConflict,
        'message' => $hasConflict 
            ? 'Conflicts with existing approved reservation'
            : 'No conflicts with existing approved reservations'
    ];
    
    if ($hasConflict) {
        $allPassed = false;
        $reason = $reason ?: 'Conflicts with existing approved reservation';
    }
    
    // Condition 7: User must have no previous violations
    // Check for violations in the last 365 days (configurable)
    $violationsStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM user_violations 
         WHERE user_id = :user_id 
           AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
           AND severity IN ("high", "critical")'
    );
    $violationsStmt->execute(['user_id' => $userId]);
    $hasViolations = (int)$violationsStmt->fetchColumn() > 0;
    
    $conditions['no_violations'] = [
        'passed' => !$hasViolations,
        'message' => $hasViolations 
            ? 'User has previous violations requiring manual review'
            : 'User has no recent high-severity violations'
    ];
    
    if ($hasViolations) {
        $allPassed = false;
        $reason = $reason ?: 'User has previous violations requiring manual review';
    }
    
    // Condition 8: Reservation must be within advance booking window
    $today = date('Y-m-d');
    $maxDate = date('Y-m-d', strtotime("+{$advanceBookingWindowDays} days"));
    $withinWindow = ($reservationDate >= $today && $reservationDate <= $maxDate);
    
    $conditions['within_advance_window'] = [
        'passed' => $withinWindow,
        'message' => $withinWindow 
            ? "Reservation date is within advance booking window ({$advanceBookingWindowDays} days)"
            : "Reservation date is outside advance booking window ({$advanceBookingWindowDays} days)"
    ];
    
    if (!$withinWindow) {
        $allPassed = false;
        $reason = $reason ?: 'Reservation date is outside advance booking window';
    }
    
    // Final determination
    $result['eligible'] = $allPassed;
    $result['auto_approve'] = $allPassed && $facilityAutoApprove;
    $result['conditions'] = $conditions;
    $result['reason'] = $reason ?: ($allPassed ? 'All conditions met for auto-approval' : 'One or more conditions not met');
    
    return $result;
}

/**
 * Calculates duration in hours from a time slot string.
 * 
 * @param string $timeSlot Time slot string (e.g., "08:00 - 12:00" or legacy "Morning (8AM - 12PM)")
 * @return float Duration in hours
 */
function getDurationHours(string $timeSlot): float {
    require_once __DIR__ . '/time_helpers.php';
    return getDurationHoursFromSlot($timeSlot);
}

