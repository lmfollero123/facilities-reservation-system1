<?php
/**
 * Extension Helper Functions
 * 
 * Functions for handling reservation extensions including:
 * - Conflict detection for extensions
 * - Operating hours validation
 * - Fee calculation
 * - Auto-approval logic
 */

if (!function_exists('canExtendReservation')) {
    /**
     * Check if a reservation can be extended
     * 
     * @param PDO $pdo Database connection
     * @param int $reservationId Reservation ID
     * @param int $extensionHours Number of hours to extend
     * @return array ['can_extend' => bool, 'reason' => string, 'fee' => float]
     */
    function canExtendReservation($pdo, $reservationId, $extensionHours) {
        // Get reservation details
        $stmt = $pdo->prepare('
            SELECT r.*, f.operating_hours, f.max_duration_hours, 
                   f.extension_fee_per_hour, f.extension_auto_approve_max_hours, 
                   f.allow_same_day_extension
            FROM reservations r
            JOIN facilities f ON r.facility_id = f.id
            WHERE r.id = ?
        ');
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            return ['can_extend' => false, 'reason' => 'Reservation not found', 'fee' => 0];
        }
        
        // Check 1: Status must be approved
        if ($reservation['status'] !== 'approved') {
            return ['can_extend' => false, 'reason' => 'Only approved reservations can be extended', 'fee' => 0];
        }
        
        // Check 2: Extension count limit (max 1 extension per reservation)
        if ($reservation['extension_count'] >= 1) {
            return ['can_extend' => false, 'reason' => 'This reservation has already been extended once', 'fee' => 0];
        }
        
        // Check 3: Reservation must not have started
        $currentDateTime = new DateTime();
        $reservationDateTime = new DateTime($reservation['reservation_date'] . ' ' . getTimeSlotStart($reservation['time_slot']));
        
        if ($currentDateTime >= $reservationDateTime) {
            return ['can_extend' => false, 'reason' => 'Cannot extend a reservation that has already started', 'fee' => 0];
        }
        
        // Check 4: Same-day extension rules
        $isSameDay = $reservation['reservation_date'] === $currentDateTime->format('Y-m-d');
        
        if ($isSameDay && !$reservation['allow_same_day_extension']) {
            return ['can_extend' => false, 'reason' => 'Same-day extensions are not allowed for this facility', 'fee' => 0];
        }
        
        // Check 5: Operating hours validation
        $operatingHoursCheck = checkOperatingHoursForExtension(
            $reservation['reservation_date'], 
            $reservation['time_slot'], 
            $extensionHours, 
            $reservation['operating_hours']
        );
        
        if (!$operatingHoursCheck['valid']) {
            return ['can_extend' => false, 'reason' => $operatingHoursCheck['reason'], 'fee' => 0];
        }
        
        // Check 6: Max duration limit
        $currentDuration = getTimeSlotDuration($reservation['time_slot']);
        $newDuration = $currentDuration + $extensionHours;
        
        if ($reservation['max_duration_hours'] && $newDuration > $reservation['max_duration_hours']) {
            return ['can_extend' => false, 'reason' => 'Extension would exceed maximum duration limit for this facility', 'fee' => 0];
        }
        
        // Check 7: 12-hour absolute maximum
        if ($newDuration > 12) {
            return ['can_extend' => false, 'reason' => 'Total duration cannot exceed 12 hours', 'fee' => 0];
        }
        
        // Check 8: Conflict detection
        $conflictCheck = checkExtensionConflict($pdo, $reservationId, $reservation['facility_id'], $reservation['reservation_date'], $reservation['time_slot'], $extensionHours);
        
        if (!$conflictCheck['no_conflict']) {
            return ['can_extend' => false, 'reason' => $conflictCheck['reason'], 'fee' => 0];
        }
        
        // Calculate fee
        $fee = $extensionHours * ($reservation['extension_fee_per_hour'] ?? 10.00);
        
        // Check auto-approval eligibility
        $canAutoApprove = false;
        if ($reservation['extension_auto_approve_max_hours'] && $extensionHours <= $reservation['extension_auto_approve_max_hours']) {
            $canAutoApprove = true;
        }
        
        return [
            'can_extend' => true,
            'reason' => '',
            'fee' => $fee,
            'can_auto_approve' => $canAutoApprove,
            'current_duration' => $currentDuration,
            'new_duration' => $newDuration
        ];
    }
}

if (!function_exists('checkOperatingHoursForExtension')) {
    /**
     * Check if extension is within facility operating hours
     * 
     * @param string $reservationDate Reservation date (Y-m-d)
     * @param string $timeSlot Current time slot
     * @param float $extensionHours Hours to extend
     * @param string $operatingHours Operating hours (e.g., "09:00-16:00")
     * @return array ['valid' => bool, 'reason' => string]
     */
    function checkOperatingHoursForExtension($reservationDate, $timeSlot, $extensionHours, $operatingHours) {
        if (!$operatingHours) {
            // No operating hours set, allow extension
            return ['valid' => true, 'reason' => ''];
        }
        
        // Parse operating hours
        $hours = parseOperatingHours($operatingHours);
        if (!$hours) {
            return ['valid' => true, 'reason' => '']; // Invalid format, allow by default
        }
        
        // Get current end time of reservation
        $endTime = getTimeSlotEnd($timeSlot);
        $endDateTime = new DateTime($reservationDate . ' ' . $endTime);
        
        // Calculate new end time after extension
        $newEndDateTime = clone $endDateTime;
        $newEndDateTime->modify('+' . $extensionHours . ' hours');
        
        // Check if new end time is within operating hours
        $operatingEndDateTime = new DateTime($reservationDate . ' ' . $hours['end']);
        
        if ($newEndDateTime > $operatingEndDateTime) {
            return [
                'valid' => false, 
                'reason' => 'Extension would exceed facility operating hours (closes at ' . formatTime($hours['end']) . ')'
            ];
        }
        
        return ['valid' => true, 'reason' => ''];
    }
}

if (!function_exists('checkExtensionConflict')) {
    /**
     * Check if extension would conflict with other reservations
     * 
     * @param PDO $pdo Database connection
     * @param int $reservationId Current reservation ID
     * @param int $facilityId Facility ID
     * @param string $reservationDate Reservation date
     * @param string $timeSlot Current time slot
     * @param float $extensionHours Hours to extend
     * @return array ['no_conflict' => bool, 'reason' => string]
     */
    function checkExtensionConflict($pdo, $reservationId, $facilityId, $reservationDate, $timeSlot, $extensionHours) {
        // Get current end time
        $currentEnd = getTimeSlotEnd($timeSlot);
        $currentStart = getTimeSlotStart($timeSlot);
        
        // Calculate new end time
        $currentEndDateTime = new DateTime($currentEnd);
        $newEndDateTime = clone $currentEndDateTime;
        $newEndDateTime->modify('+' . $extensionHours . ' hours');
        $newEndTime = $newEndDateTime->format('H:i');
        
        // Check for conflicts with other approved reservations
        $stmt = $pdo->prepare('
            SELECT time_slot
            FROM reservations
            WHERE facility_id = ?
            AND reservation_date = ?
            AND status = "approved"
            AND id != ?
        ');
        $stmt->execute([$facilityId, $reservationDate, $reservationId]);
        $otherReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($otherReservations as $other) {
            $otherStart = getTimeSlotStart($other['time_slot']);
            $otherEnd = getTimeSlotEnd($other['time_slot']);
            
            // Check if the extension would overlap with another reservation
            // The extension period is from current_end to new_end
            if (timeRangesOverlap($currentEnd, $newEndTime, $otherStart, $otherEnd)) {
                return [
                    'no_conflict' => false,
                    'reason' => 'Extension would conflict with another reservation (' . $other['time_slot'] . ')'
                ];
            }
        }
        
        return ['no_conflict' => true, 'reason' => ''];
    }
}

if (!function_exists('timeRangesOverlap')) {
    /**
     * Check if two time ranges overlap
     * 
     * @param string $start1 Start time of range 1 (HH:i)
     * @param string $end1 End time of range 1 (HH:i)
     * @param string $start2 Start time of range 2 (HH:i)
     * @param string $end2 End time of range 2 (HH:i)
     * @return bool
     */
    function timeRangesOverlap($start1, $end1, $start2, $end2) {
        $s1 = strtotime($start1);
        $e1 = strtotime($end1);
        $s2 = strtotime($start2);
        $e2 = strtotime($end2);
        
        // Two ranges overlap if: start1 < end2 AND start2 < end1
        return ($s1 < $e2) && ($s2 < $e1);
    }
}

if (!function_exists('parseOperatingHours')) {
    /**
     * Parse operating hours string
     * 
     * @param string $operatingHours Operating hours (e.g., "09:00-16:00" or "8:00 AM - 4:00 PM")
     * @return array|false ['start' => '09:00', 'end' => '16:00'] or false
     */
    function parseOperatingHours($operatingHours) {
        // Try 24-hour format (HH:MM-HH:MM)
        if (preg_match('/^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', $operatingHours, $matches)) {
            return ['start' => $matches[1], 'end' => $matches[2]];
        }
        
        // Try 12-hour format (HH:MM AM/PM - HH:MM AM/PM)
        if (preg_match('/^(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i', $operatingHours, $matches)) {
            $start = DateTime::createFromFormat('h:i A', strtoupper($matches[1]))->format('H:i');
            $end = DateTime::createFromFormat('h:i A', strtoupper($matches[2]))->format('H:i');
            return ['start' => $start, 'end' => $end];
        }
        
        return false;
    }
}

if (!function_exists('getTimeSlotStart')) {
    /**
     * Get start time from time slot
     * 
     * @param string $timeSlot Time slot (e.g., "Morning (8:00 AM - 12:00 PM)")
     * @return string Start time (H:i)
     */
    function getTimeSlotStart($timeSlot) {
        if (preg_match('/(\d{1,2}:\d{2}\s*[AP]M)/i', $timeSlot, $matches)) {
            return DateTime::createFromFormat('h:i A', strtoupper($matches[1]))->format('H:i');
        }
        return '00:00';
    }
}

if (!function_exists('getTimeSlotEnd')) {
    /**
     * Get end time from time slot
     * 
     * @param string $timeSlot Time slot (e.g., "Morning (8:00 AM - 12:00 PM)")
     * @return string End time (H:i)
     */
    function getTimeSlotEnd($timeSlot) {
        if (preg_match_all('/(\d{1,2}:\d{2}\s*[AP]M)/i', $timeSlot, $matches)) {
            if (isset($matches[1][1])) {
                return DateTime::createFromFormat('h:i A', strtoupper($matches[1][1]))->format('H:i');
            }
        }
        return '00:00';
    }
}

if (!function_exists('getTimeSlotDuration')) {
    /**
     * Get duration in hours from time slot
     * 
     * @param string $timeSlot Time slot
     * @return float Duration in hours
     */
    function getTimeSlotDuration($timeSlot) {
        $start = getTimeSlotStart($timeSlot);
        $end = getTimeSlotEnd($timeSlot);
        
        $startDateTime = new DateTime($start);
        $endDateTime = new DateTime($end);
        $interval = $startDateTime->diff($endDateTime);
        
        return $interval->h + ($interval->i / 60);
    }
}

if (!function_exists('formatTime')) {
    /**
     * Format time for display
     * 
     * @param string $time Time in 24-hour format (H:i)
     * @return string Formatted time (e.g., "8:00 AM")
     */
    function formatTime($time) {
        return date('g:i A', strtotime($time));
    }
}

if (!function_exists('processExtension')) {
    /**
     * Process reservation extension
     * 
     * @param PDO $pdo Database connection
     * @param int $reservationId Reservation ID
     * @param float $extensionHours Hours to extend
     * @param bool $autoApprove Whether to auto-approve (after payment)
     * @return array ['success' => bool, 'message' => string, 'new_time_slot' => string|null]
     */
    function processExtension($pdo, $reservationId, $extensionHours, $autoApprove = false) {
        // Get reservation details
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            return ['success' => false, 'message' => 'Reservation not found', 'new_time_slot' => null];
        }
        
        // Validate extension
        $check = canExtendReservation($pdo, $reservationId, $extensionHours);
        if (!$check['can_extend']) {
            return ['success' => false, 'message' => $check['reason'], 'new_time_slot' => null];
        }
        
        // Calculate new end time
        $currentEnd = getTimeSlotEnd($reservation['time_slot']);
        $currentStart = getTimeSlotStart($reservation['time_slot']);
        $newEndDateTime = new DateTime($currentEnd);
        $newEndDateTime->modify('+' . $extensionHours . ' hours');
        $newEndTime = $newEndDateTime->format('H:i');
        
        // Create new time slot string
        $newTimeSlot = formatTime($currentStart) . ' - ' . formatTime($newEndTime);
        
        // Store original end time for audit
        $originalEndTime = formatTime($currentEnd);
        
        // Calculate fee
        $fee = $check['fee'];
        
        // Update reservation
        $updateStmt = $pdo->prepare('
            UPDATE reservations 
            SET time_slot = ?,
                extension_count = extension_count + 1,
                original_end_time = ?,
                extension_fee_paid = COALESCE(extension_fee_paid, 0) + ?,
                last_extended_at = CURRENT_TIMESTAMP,
                status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        
        $newStatus = $autoApprove ? 'approved' : 'pending';
        $updateStmt->execute([$newTimeSlot, $originalEndTime, $fee, $newStatus, $reservationId]);
        
        // Add to history
        $historyStmt = $pdo->prepare('
            INSERT INTO reservation_history (reservation_id, status, note, created_by)
            VALUES (?, ?, ?, ?)
        ');
        $historyStmt->execute([
            $reservationId,
            $newStatus,
            'Extended by ' . $extensionHours . ' hour(s). Fee: ₱' . number_format($fee, 2),
            $_SESSION['user_id'] ?? null
        ]);
        
        // Log audit
        require_once __DIR__ . '/audit.php';
        logAudit(
            'Reservation extended',
            'Reservations',
            'RES-' . $reservationId . ' extended by ' . $extensionHours . ' hour(s). Fee: ₱' . number_format($fee, 2)
        );
        
        return [
            'success' => true,
            'message' => 'Reservation extended successfully',
            'new_time_slot' => $newTimeSlot,
            'fee' => $fee,
            'status' => $newStatus
        ];
    }
}
