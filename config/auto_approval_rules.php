<?php
/**
 * Pure rule evaluation for reservation auto-approval.
 *
 * Extracted from evaluateAutoApproval() (config/auto_approval.php) so the
 * eight barangay-defined conditions can be unit-tested without a database:
 * the wrapper fetches the rows, this function only applies the rules.
 * Behavior and messages are identical to the original inline logic.
 *
 * @package FacilitiesReservation
 */

require_once __DIR__ . '/time_helpers.php';

/**
 * @param array<string, mixed>|null $facility Row with auto_approve, capacity_threshold,
 *                                            max_duration_hours, capacity, status — or null when not found
 * @param array<string, mixed>|null $blackout Matching facility_blackout_dates row, or null
 * @param list<string> $existingApprovedSlots time_slot strings of approved reservations (same facility+date)
 * @param bool $hasRecentSevereViolations High/critical violations for the user within the last 365 days
 * @param array<string, mixed>|null $user Row with is_verified and role, or null when not found
 * @param string|null $today Y-m-d "today" for the advance-window check (null = current date)
 *
 * @return array{eligible: bool, auto_approve: bool, conditions: array<string, array{passed: bool, message: string}>, reason: string}
 */
function frs_auto_approval_rules(
    ?array $facility,
    ?array $blackout,
    array $existingApprovedSlots,
    bool $hasRecentSevereViolations,
    ?array $user,
    string $reservationDate,
    string $timeSlot,
    ?int $expectedAttendees,
    bool $isCommercial,
    int $advanceBookingWindowDays = 60,
    ?string $today = null
): array {
    $conditions = [];
    $allPassed = true;
    $reason = '';

    $result = [
        'eligible' => false,
        'auto_approve' => false,
        'conditions' => [],
        'reason' => ''
    ];

    if (!$facility) {
        $result['reason'] = 'Facility not found';
        return $result;
    }

    if ($facility['status'] !== 'available') {
        $result['reason'] = 'Facility is not available';
        return $result;
    }

    // Condition 1: Facility must have auto_approve enabled
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
    $durationHours = getDurationHoursFromSlot($timeSlot);
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
    $hasConflict = false;
    foreach ($existingApprovedSlots as $existingSlot) {
        if (timeSlotsOverlap($timeSlot, (string)$existingSlot)) {
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
    $conditions['no_violations'] = [
        'passed' => !$hasRecentSevereViolations,
        'message' => $hasRecentSevereViolations
            ? 'User has previous violations requiring manual review'
            : 'User has no recent high-severity violations'
    ];

    if ($hasRecentSevereViolations) {
        $allPassed = false;
        $reason = $reason ?: 'User has previous violations requiring manual review';
    }

    // Condition 7.5: User must be verified (have submitted valid ID)
    // Note: Staff and Admin roles are automatically considered verified
    $user = $user ?: [];
    $isVerified = (bool)($user['is_verified'] ?? false);
    $userRole = $user['role'] ?? 'Resident';
    $isVerifiedOrPrivileged = $isVerified || in_array($userRole, ['Staff', 'Admin'], true);

    $conditions['user_verified'] = [
        'passed' => $isVerifiedOrPrivileged,
        'message' => $isVerifiedOrPrivileged
            ? (in_array($userRole, ['Staff', 'Admin'], true)
                ? 'User is ' . $userRole . ' (automatically verified)'
                : 'User account is verified')
            : 'User account is not verified - valid ID required for auto-approval'
    ];

    if (!$isVerifiedOrPrivileged) {
        $allPassed = false;
        $reason = $reason ?: 'User account is not verified - valid ID required for auto-approval';
    }

    // Condition 8: Reservation must be within advance booking window
    $today = $today ?: date('Y-m-d');
    $maxDate = date('Y-m-d', strtotime($today . " +{$advanceBookingWindowDays} days"));
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
