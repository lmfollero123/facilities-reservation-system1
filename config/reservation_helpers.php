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
        // Get all pending and postponed reservations that are past their date
        // Using database CURDATE() for reliable date comparison
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        
        // Get pending reservations with past dates (using CURDATE() for database timezone consistency)
        $pendingStmt = $pdo->prepare(
            'SELECT id, reservation_date, time_slot, user_id 
             FROM reservations 
             WHERE status IN ("pending", "postponed")
             AND reservation_date < CURDATE()'
        );
        $pendingStmt->execute();
        $expiredReservations = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get pending reservations for today that have passed their time slot
        $todayPendingStmt = $pdo->prepare(
            'SELECT id, reservation_date, time_slot, user_id 
             FROM reservations 
             WHERE status IN ("pending", "postponed")
             AND reservation_date = CURDATE()'
        );
        $todayPendingStmt->execute();
        $todayPending = $todayPendingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if today's reservations have passed their time slot
        foreach ($todayPending as $pending) {
            $timeSlot = $pending['time_slot'];
            $hasPassed = false;
            
            // Check time slot end times
            if (stripos($timeSlot, 'Morning') !== false && $currentHour >= 12) {
                $hasPassed = true;
            } elseif (stripos($timeSlot, 'Afternoon') !== false && $currentHour >= 17) {
                $hasPassed = true;
            } elseif (stripos($timeSlot, 'Evening') !== false && $currentHour >= 21) {
                $hasPassed = true;
            } elseif (preg_match('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $timeSlot, $matches)) {
                // Parse time slot format "HH:MM - HH:MM" (e.g., "10:09 - 19:09")
                $endHour = (int)$matches[3];
                $endMinute = (int)$matches[4];
                if ($currentHour > $endHour || ($currentHour === $endHour && $currentMinute >= $endMinute)) {
                    $hasPassed = true;
                }
            }
            
            if ($hasPassed) {
                $expiredReservations[] = $pending;
            }
        }
        
        // Process each expired reservation
        foreach ($expiredReservations as $expired) {
            // Update status to denied
            $updateStmt = $pdo->prepare(
                'UPDATE reservations 
                 SET status = "denied", updated_at = CURRENT_TIMESTAMP 
                 WHERE id = ? AND status IN ("pending", "postponed")'
            );
            $updateStmt->execute([$expired['id']]);
            
            // Only proceed if update was successful (prevents duplicate processing)
            if ($updateStmt->rowCount() > 0) {
                // Add to history
                $histStmt = $pdo->prepare(
                    'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
                     VALUES (?, "denied", ?, NULL)'
                );
                $histStmt->execute([
                    $expired['id'],
                    'Automatically denied: Reservation date/time has passed without approval.'
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
                        'Reservation Automatically Denied',
                        'Your reservation request for ' . $facilityName . ' on ' . 
                        date('F j, Y', strtotime($expired['reservation_date'])) . 
                        ' (' . $expired['time_slot'] . ') has been automatically denied because the reservation time has passed without approval.',
                        base_url() . '/resources/views/pages/dashboard/my_reservations.php'
                    );
                }
                
                // Log audit event
                if (function_exists('logAudit')) {
                    logAudit(
                        'Auto-denied expired reservation',
                        'Reservations',
                        'RES-' . $expired['id'] . ' – Past reservation date/time without approval'
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
