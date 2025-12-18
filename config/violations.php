<?php
/**
 * User Violation Tracking System
 * 
 * Functions for recording and managing user violations (no-shows, policy violations, etc.)
 * 
 * @package FacilitiesReservation
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/audit.php';

/**
 * Records a violation for a user
 * 
 * @param int $userId User ID who committed the violation
 * @param string $violationType Type of violation: 'no_show', 'late_cancellation', 'policy_violation', 'damage', 'other'
 * @param string $severity Severity level: 'low', 'medium', 'high', 'critical'
 * @param string|null $description Description/details of the violation
 * @param int|null $reservationId Related reservation ID (if applicable)
 * @param int|null $createdBy Admin/Staff user ID who recorded the violation (defaults to session user)
 * @return int|false Violation ID on success, false on failure
 */
function recordViolation(
    int $userId,
    string $violationType,
    string $severity = 'medium',
    ?string $description = null,
    ?int $reservationId = null,
    ?int $createdBy = null
): int|false {
    $pdo = db();
    
    // Validate violation type
    $allowedTypes = ['no_show', 'late_cancellation', 'policy_violation', 'damage', 'other'];
    if (!in_array($violationType, $allowedTypes, true)) {
        error_log("Invalid violation type: $violationType");
        return false;
    }
    
    // Validate severity
    $allowedSeverities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($severity, $allowedSeverities, true)) {
        error_log("Invalid severity: $severity");
        return false;
    }
    
    // Get creator from session if not provided
    if ($createdBy === null && isset($_SESSION['user_id'])) {
        $createdBy = $_SESSION['user_id'];
    }
    
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO user_violations 
             (user_id, reservation_id, violation_type, description, severity, created_by) 
             VALUES (:user_id, :reservation_id, :violation_type, :description, :severity, :created_by)'
        );
        
        $stmt->execute([
            'user_id' => $userId,
            'reservation_id' => $reservationId,
            'violation_type' => $violationType,
            'description' => $description,
            'severity' => $severity,
            'created_by' => $createdBy,
        ]);
        
        $violationId = $pdo->lastInsertId();
        
        // Get user name for audit log
        $userStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $userName = $user ? $user['name'] : 'Unknown User';
        
        // Log audit event
        $auditDetails = "User: {$userName} (ID: {$userId}) - Type: {$violationType}, Severity: {$severity}";
        if ($reservationId) {
            $auditDetails .= ", Reservation ID: {$reservationId}";
        }
        if ($description) {
            $auditDetails .= " - {$description}";
        }
        
        logAudit('Recorded user violation', 'Violations', $auditDetails);
        
        return $violationId;
    } catch (Throwable $e) {
        error_log("Error recording violation: " . $e->getMessage());
        return false;
    }
}

/**
 * Gets violations for a specific user
 * 
 * @param int $userId User ID
 * @param int|null $daysBack Number of days to look back (default: 365 for auto-approval check)
 * @param string|null $severityFilter Filter by severity level (optional)
 * @return array Array of violation records
 */
function getUserViolations(int $userId, ?int $daysBack = null, ?string $severityFilter = null): array {
    $pdo = db();
    
    $sql = 'SELECT uv.*, 
                   u.name AS user_name, 
                   u.email AS user_email,
                   creator.name AS created_by_name,
                   r.reservation_date,
                   r.time_slot,
                   f.name AS facility_name
            FROM user_violations uv
            JOIN users u ON uv.user_id = u.id
            LEFT JOIN users creator ON uv.created_by = creator.id
            LEFT JOIN reservations r ON uv.reservation_id = r.id
            LEFT JOIN facilities f ON r.facility_id = f.id
            WHERE uv.user_id = :user_id';
    
    $params = ['user_id' => $userId];
    
    if ($daysBack !== null) {
        $sql .= ' AND uv.created_at >= DATE_SUB(NOW(), INTERVAL :days_back DAY)';
        $params['days_back'] = $daysBack;
    }
    
    if ($severityFilter !== null) {
        $allowedSeverities = ['low', 'medium', 'high', 'critical'];
        if (in_array($severityFilter, $allowedSeverities, true)) {
            $sql .= ' AND uv.severity = :severity';
            $params['severity'] = $severityFilter;
        }
    }
    
    $sql .= ' ORDER BY uv.created_at DESC';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Checks if a user has high-severity violations (used for auto-approval)
 * 
 * @param int $userId User ID
 * @param int $daysBack Number of days to look back (default: 365)
 * @return bool True if user has high or critical violations
 */
function userHasHighSeverityViolations(int $userId, int $daysBack = 365): bool {
    $violations = getUserViolations($userId, $daysBack, 'high');
    $criticalViolations = getUserViolations($userId, $daysBack, 'critical');
    
    return (count($violations) > 0 || count($criticalViolations) > 0);
}

/**
 * Gets violation statistics for a user
 * 
 * @param int $userId User ID
 * @param int $daysBack Number of days to look back (default: 365)
 * @return array Statistics array with counts by type and severity
 */
function getUserViolationStats(int $userId, int $daysBack = 365): array {
    $violations = getUserViolations($userId, $daysBack);
    
    $stats = [
        'total' => count($violations),
        'by_type' => [
            'no_show' => 0,
            'late_cancellation' => 0,
            'policy_violation' => 0,
            'damage' => 0,
            'other' => 0,
        ],
        'by_severity' => [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ],
        'recent' => array_slice($violations, 0, 5), // Last 5 violations
    ];
    
    foreach ($violations as $violation) {
        $stats['by_type'][$violation['violation_type']]++;
        $stats['by_severity'][$violation['severity']]++;
    }
    
    return $stats;
}

/**
 * Automatically records a no-show violation when an approved reservation date passes
 * 
 * This should be called by a scheduled job or manually by admins
 * 
 * @param int $reservationId Reservation ID
 * @param string $severity Severity level (default: 'medium')
 * @return bool True on success
 */
function recordNoShowViolation(int $reservationId, string $severity = 'medium'): bool {
    $pdo = db();
    
    // Get reservation details
    $stmt = $pdo->prepare(
        'SELECT r.user_id, r.reservation_date, r.time_slot, r.status, 
                f.name AS facility_name, u.name AS user_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         JOIN users u ON r.user_id = u.id
         WHERE r.id = :id AND r.status = "approved"'
    );
    $stmt->execute(['id' => $reservationId]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        return false;
    }
    
    // Check if reservation date has passed
    require_once __DIR__ . '/time_helpers.php';
    $today = date('Y-m-d');
    $reservationDate = $reservation['reservation_date'];
    
    if ($reservationDate >= $today) {
        // Reservation hasn't happened yet
        return false;
    }
    
    // Check if violation already exists for this reservation
    $existingStmt = $pdo->prepare(
        'SELECT id FROM user_violations 
         WHERE reservation_id = :reservation_id 
           AND violation_type = "no_show"'
    );
    $existingStmt->execute(['reservation_id' => $reservationId]);
    if ($existingStmt->fetch()) {
        // Violation already recorded
        return true;
    }
    
    // Record the violation
    $description = "No-show for reservation at {$reservation['facility_name']} on " . 
                   date('F j, Y', strtotime($reservationDate)) . 
                   " ({$reservation['time_slot']})";
    
    return recordViolation(
        (int)$reservation['user_id'],
        'no_show',
        $severity,
        $description,
        $reservationId,
        null // System-recorded
    ) !== false;
}

