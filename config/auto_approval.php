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
require_once __DIR__ . '/auto_approval_rules.php';

// Load ML integration if available
if (file_exists(__DIR__ . '/ai_ml_integration.php')) {
    require_once __DIR__ . '/ai_ml_integration.php';
}

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

    // Gather the rows the rules need; the rule logic itself lives in
    // frs_auto_approval_rules() (config/auto_approval_rules.php) so it can
    // be unit-tested without a database.
    $facilityStmt = $pdo->prepare(
        'SELECT auto_approve, capacity_threshold, max_duration_hours, capacity, status
         FROM facilities
         WHERE id = :facility_id'
    );
    $facilityStmt->execute(['facility_id' => $facilityId]);
    $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $blackout = null;
    $existingSlots = [];
    $hasViolations = false;
    $userVerificationData = null;

    if ($facility && $facility['status'] === 'available') {
        $blackoutStmt = $pdo->prepare(
            'SELECT id, reason FROM facility_blackout_dates
             WHERE facility_id = :facility_id AND blackout_date = :date'
        );
        $blackoutStmt->execute([
            'facility_id' => $facilityId,
            'date' => $reservationDate
        ]);
        $blackout = $blackoutStmt->fetch(PDO::FETCH_ASSOC) ?: null;

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

        // Violations in the last 365 days (high/critical only)
        $violationsStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_violations
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
               AND severity IN ("high", "critical")'
        );
        $violationsStmt->execute(['user_id' => $userId]);
        $hasViolations = (int)$violationsStmt->fetchColumn() > 0;

        $userVerificationStmt = $pdo->prepare('SELECT is_verified, role FROM users WHERE id = :user_id');
        $userVerificationStmt->execute(['user_id' => $userId]);
        $userVerificationData = $userVerificationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $result = frs_auto_approval_rules(
        $facility,
        $blackout,
        $existingSlots,
        $hasViolations,
        $userVerificationData,
        $reservationDate,
        $timeSlot,
        $expectedAttendees,
        $isCommercial,
        $advanceBookingWindowDays
    );

    if (!$facility || $facility['status'] !== 'available') {
        return $result;
    }

    $facilityAutoApprove = (bool)($facility['auto_approve'] ?? false);
    $allPassed = $result['eligible'];

    // Add ML-based risk assessment if available
    if (function_exists('assessRiskML') && $allPassed) {
        try {
            // Get user data for ML assessment
            $userStmt = $pdo->prepare(
                'SELECT 
                    u.is_verified,
                    COUNT(r.id) as booking_count
                 FROM users u
                 LEFT JOIN reservations r ON r.user_id = u.id AND r.status = "approved"
                 WHERE u.id = :user_id
                 GROUP BY u.id'
            );
            $userStmt->execute(['user_id' => $userId]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get violation count
            $violationStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM user_violations 
                 WHERE user_id = :user_id 
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                   AND severity IN ("high", "critical")'
            );
            $violationStmt->execute(['user_id' => $userId]);
            $violationCount = (int)$violationStmt->fetchColumn();
            
            $userData = $userData ?: [];
            $userData['violation_count'] = $violationCount;
            $userData['booking_count'] = (int)($userData['booking_count'] ?? 0);
            $userData['is_verified'] = (bool)($userData['is_verified'] ?? false);
            
            // Prepare facility data
            $facilityData = [
                'auto_approve' => $facilityAutoApprove,
                'capacity' => $facility['capacity'] ?? '100',
                'max_duration_hours' => $facility['max_duration_hours'] ?? 8.0,
                'capacity_threshold' => $facility['capacity_threshold'] ?? null,
            ];
            
            $mlRisk = assessRiskML(
                $facilityId,
                $userId,
                $reservationDate,
                $timeSlot,
                $expectedAttendees,
                $isCommercial,
                $facilityData,
                $userData
            );
            
            if (!isset($mlRisk['error'])) {
                // Add ML risk info to result
                $result['ml_risk'] = [
                    'risk_level' => $mlRisk['risk_level'],
                    'risk_probability' => $mlRisk['risk_probability'],
                    'confidence' => $mlRisk['confidence'] ?? 0,
                    'is_low_risk' => $mlRisk['is_low_risk'] ?? false,
                    'is_high_risk' => $mlRisk['is_high_risk'] ?? true,
                ];
                
                // Use ML risk as additional signal (if high confidence ML says high risk, be more cautious)
                if ($mlRisk['is_high_risk'] && ($mlRisk['confidence'] ?? 0) > 0.7) {
                    // ML model with high confidence suggests high risk - override auto-approve
                    $result['auto_approve'] = false;
                    $result['ml_risk_override'] = true;
                    $result['reason'] = 'ML model suggests manual review (high risk confidence: ' . 
                                      round(($mlRisk['confidence'] ?? 0) * 100) . '%)';
                } elseif ($mlRisk['is_low_risk'] && ($mlRisk['confidence'] ?? 0) > 0.7) {
                    // ML model with high confidence suggests low risk - reinforce auto-approve if rules pass
                    $result['ml_risk_reinforce'] = true;
                }
            }
        } catch (Exception $e) {
            // Silent fail - continue with rule-based only
            error_log("ML risk assessment error in evaluateAutoApproval: " . $e->getMessage());
        }
    }
    
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

