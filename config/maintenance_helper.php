<?php
/**
 * Helper functions for handling facility maintenance and reservation status updates
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';

/**
 * Handle reservation status updates when a facility goes under maintenance
 * 
 * @param int $facilityId The facility ID that went under maintenance
 * @param string $facilityName The name of the facility (for notifications/emails)
 * @return array Summary of updates: ['pending_cancelled' => count, 'approved_postponed' => count, 'errors' => []]
 */
function handleFacilityMaintenanceStatusChange(int $facilityId, string $facilityName): array
{
    $pdo = db();
    $result = [
        'pending_cancelled' => 0,
        'pending_on_hold' => 0,
        'approved_postponed' => 0,
        'errors' => []
    ];
    
    try {
        $pdo->beginTransaction();
        
        // Get all active reservations for this facility (pending and approved, future dates only)
        $reservationsStmt = $pdo->prepare(
            'SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.purpose, r.status, 
                    u.name AS user_name, u.email AS user_email
             FROM reservations r
             INNER JOIN users u ON r.user_id = u.id
             WHERE r.facility_id = :facility_id
               AND r.status IN ("pending", "approved")
               AND r.reservation_date >= CURDATE()
             ORDER BY r.reservation_date, r.time_slot'
        );
        $reservationsStmt->execute(['facility_id' => $facilityId]);
        $reservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($reservations as $reservation) {
            try {
                if ($reservation['status'] === 'pending') {
                    // Pending reservations → CANCELLED
                    $updateStmt = $pdo->prepare(
                        'UPDATE reservations 
                         SET status = "cancelled", updated_at = NOW()
                         WHERE id = :id'
                    );
                    $updateStmt->execute(['id' => $reservation['id']]);
                    
                    // Add to reservation history
                    $historyStmt = $pdo->prepare(
                        'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
                         VALUES (:id, "cancelled", :note, NULL)'
                    );
                    $historyStmt->execute([
                        'id' => $reservation['id'],
                        'note' => 'Cancelled automatically - Facility went under maintenance'
                    ]);
                    
                    // Create notification
                    createNotification(
                        $reservation['user_id'],
                        'booking',
                        'Reservation Cancelled',
                        "Your pending reservation for {$facilityName} on {$reservation['reservation_date']} ({$reservation['time_slot']}) has been cancelled because the facility is now under maintenance.",
                        base_path() . '/resources/views/pages/dashboard/my_reservations.php'
                    );
                    
                    $result['pending_cancelled']++;
                    
                } elseif ($reservation['status'] === 'approved') {
                    // Approved reservations → POSTPONED with priority
                    $updateStmt = $pdo->prepare(
                        'UPDATE reservations 
                         SET status = "postponed", 
                             postponed_priority = TRUE,
                             postponed_at = NOW(),
                             updated_at = NOW()
                         WHERE id = :id'
                    );
                    $updateStmt->execute(['id' => $reservation['id']]);
                    
                    // Add to reservation history
                    $historyStmt = $pdo->prepare(
                        'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
                         VALUES (:id, "postponed", :note, NULL)'
                    );
                    $historyStmt->execute([
                        'id' => $reservation['id'],
                        'note' => 'Postponed automatically - Facility went under maintenance. Priority will be given when facility becomes available.'
                    ]);
                    
                    // Send email notification
                    sendPostponementEmail(
                        $reservation['user_email'],
                        $reservation['user_name'],
                        $facilityName,
                        $reservation['reservation_date'],
                        $reservation['time_slot'],
                        $reservation['purpose']
                    );
                    
                    // Create notification
                    createNotification(
                        $reservation['user_id'],
                        'booking',
                        'Reservation Postponed',
                        "Your approved reservation for {$facilityName} on {$reservation['reservation_date']} ({$reservation['time_slot']}) has been postponed due to facility maintenance. You will receive priority when the facility becomes available again.",
                        base_path() . '/resources/views/pages/dashboard/my_reservations.php'
                    );
                    
                    $result['approved_postponed']++;
                }
                
            } catch (Throwable $e) {
                error_log("Error updating reservation {$reservation['id']}: " . $e->getMessage());
                $result['errors'][] = "Reservation ID {$reservation['id']}: " . $e->getMessage();
            }
        }
        
        $pdo->commit();
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("Error handling facility maintenance status change: " . $e->getMessage());
        $result['errors'][] = "Transaction failed: " . $e->getMessage();
    }
    
    return $result;
}

/**
 * Send email notification for postponed reservation
 */
function sendPostponementEmail(string $userEmail, string $userName, string $facilityName, string $reservationDate, string $timeSlot, string $purpose): bool
{
    $subject = "Reservation Postponed - {$facilityName}";
    
    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1e3a8a; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
            .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; border-top: none; }
            .info-box { background: #fff4e5; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
            .priority-badge { background: #10b981; color: white; padding: 5px 10px; border-radius: 4px; display: inline-block; margin-top: 10px; font-weight: bold; }
            .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 0.9em; }
            .btn { display: inline-block; padding: 12px 24px; background: #1e3a8a; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Reservation Postponed</h2>
            </div>
            <div class='content'>
                <p>Dear {$userName},</p>
                
                <p>We regret to inform you that your reservation has been postponed due to facility maintenance.</p>
                
                <div class='info-box'>
                    <strong>Reservation Details:</strong><br>
                    <strong>Facility:</strong> {$facilityName}<br>
                    <strong>Date:</strong> " . date('F j, Y', strtotime($reservationDate)) . "<br>
                    <strong>Time:</strong> {$timeSlot}<br>
                    <strong>Purpose:</strong> {$purpose}
                </div>
                
                <p><strong>What this means:</strong></p>
                <ul>
                    <li>Your reservation has been automatically postponed due to the facility being placed under maintenance.</li>
                    <li><strong>You will receive priority</strong> when the facility becomes available again.</li>
                    <li>We will notify you once the facility is back online so you can reschedule your reservation.</li>
                </ul>
                
                <div class='priority-badge'>✓ Priority Status: Active</div>
                
                <p>We apologize for any inconvenience this may cause. If you have any questions or concerns, please contact us.</p>
                
                <a href='" . base_url() . "/resources/views/pages/dashboard/my_reservations.php' class='btn'>View My Reservations</a>
            </div>
            <div class='footer'>
                <p>LGU Facilities Reservation System<br>
                This is an automated notification. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $textBody = "
Reservation Postponed

Dear {$userName},

We regret to inform you that your reservation has been postponed due to facility maintenance.

Reservation Details:
Facility: {$facilityName}
Date: " . date('F j, Y', strtotime($reservationDate)) . "
Time: {$timeSlot}
Purpose: {$purpose}

What this means:
- Your reservation has been automatically postponed due to the facility being placed under maintenance.
- You will receive PRIORITY when the facility becomes available again.
- We will notify you once the facility is back online so you can reschedule your reservation.

Priority Status: Active

We apologize for any inconvenience this may cause. If you have any questions or concerns, please contact us.

View your reservations: " . base_url() . "/resources/views/pages/dashboard/my_reservations.php

---
LGU Facilities Reservation System
This is an automated notification.
    ";
    
    return sendEmail($userEmail, $userName, $subject, $htmlBody, $textBody);
}

/**
 * Handle facility status change from maintenance to available
 * Notify users with postponed reservations that facility is available again
 * 
 * @param int $facilityId The facility ID that became available
 * @param string $facilityName The name of the facility
 * @return array Summary of notifications sent
 */
function handleFacilityAvailableStatusChange(int $facilityId, string $facilityName): array
{
    $pdo = db();
    $result = [
        'notified' => 0,
        'errors' => []
    ];
    
    try {
        // Get postponed reservations with priority for this facility (future dates only)
        $postponedStmt = $pdo->prepare(
            'SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.purpose, 
                    u.name AS user_name, u.email AS user_email
             FROM reservations r
             INNER JOIN users u ON r.user_id = u.id
             WHERE r.facility_id = :facility_id
               AND r.status = "postponed"
               AND r.postponed_priority = TRUE
               AND r.reservation_date >= CURDATE()
             ORDER BY r.postponed_at ASC, r.reservation_date ASC'
        );
        $postponedStmt->execute(['facility_id' => $facilityId]);
        $postponedReservations = $postponedStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($postponedReservations as $reservation) {
            try {
                // Send email notification
                sendFacilityAvailableEmail(
                    $reservation['user_email'],
                    $reservation['user_name'],
                    $facilityName,
                    $reservation['reservation_date'],
                    $reservation['time_slot'],
                    $reservation['purpose']
                );
                
                // Create notification that facility is available again
                createNotification(
                    $reservation['user_id'],
                    'booking',
                    'Facility Available - Priority Reservation',
                    "Great news! {$facilityName} is now available again. Your previously postponed reservation on " . date('F j, Y', strtotime($reservation['reservation_date'])) . " has priority. Please reschedule your reservation as soon as possible.",
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php'
                );
                
                $result['notified']++;
                
            } catch (Throwable $e) {
                error_log("Error notifying user for postponed reservation {$reservation['id']}: " . $e->getMessage());
                $result['errors'][] = "Reservation ID {$reservation['id']}: " . $e->getMessage();
            }
        }
        
    } catch (Throwable $e) {
        error_log("Error handling facility available status change: " . $e->getMessage());
        $result['errors'][] = "Failed: " . $e->getMessage();
    }
    
    return $result;
}

/**
 * Send email notification that facility is available again
 */
function sendFacilityAvailableEmail(string $userEmail, string $userName, string $facilityName, string $reservationDate, string $timeSlot, string $purpose): bool
{
    $subject = "Facility Available - {$facilityName} Ready for Your Reservation";
    
    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #10b981; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
            .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; border-top: none; }
            .info-box { background: #e3f8ef; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0; border-radius: 4px; }
            .priority-badge { background: #1e3a8a; color: white; padding: 5px 10px; border-radius: 4px; display: inline-block; margin-top: 10px; font-weight: bold; }
            .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 0.9em; }
            .btn { display: inline-block; padding: 12px 24px; background: #1e3a8a; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Facility Available Again</h2>
            </div>
            <div class='content'>
                <p>Dear {$userName},</p>
                
                <p><strong>Great news!</strong> {$facilityName} is now available again after maintenance.</p>
                
                <div class='info-box'>
                    <strong>Your Previous Reservation:</strong><br>
                    <strong>Date:</strong> " . date('F j, Y', strtotime($reservationDate)) . "<br>
                    <strong>Time:</strong> {$timeSlot}<br>
                    <strong>Purpose:</strong> {$purpose}
                </div>
                
                <p><strong>Priority Status:</strong> Your reservation has priority and will be given preference when you reschedule.</p>
                
                <div class='priority-badge'>✓ Priority Reservation - Active</div>
                
                <p><strong>Next Steps:</strong></p>
                <ul>
                    <li>Please log in to your account and reschedule your reservation as soon as possible.</li>
                    <li>Due to your priority status, your reservation will be processed with preference.</li>
                    <li>We recommend rescheduling at least 3 days in advance of your preferred date.</li>
                </ul>
                
                <a href='" . base_url() . "/resources/views/pages/dashboard/my_reservations.php' class='btn'>View My Reservations</a>
                
                <p>Thank you for your patience during the maintenance period.</p>
            </div>
            <div class='footer'>
                <p>LGU Facilities Reservation System<br>
                This is an automated notification. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $textBody = "
Facility Available - {$facilityName} Ready for Your Reservation

Dear {$userName},

Great news! {$facilityName} is now available again after maintenance.

Your Previous Reservation:
Date: " . date('F j, Y', strtotime($reservationDate)) . "
Time: {$timeSlot}
Purpose: {$purpose}

Priority Status: Your reservation has priority and will be given preference when you reschedule.

Priority Reservation - Active ✓

Next Steps:
- Please log in to your account and reschedule your reservation as soon as possible.
- Due to your priority status, your reservation will be processed with preference.
- We recommend rescheduling at least 3 days in advance of your preferred date.

View your reservations: " . base_url() . "/resources/views/pages/dashboard/my_reservations.php

Thank you for your patience during the maintenance period.

---
LGU Facilities Reservation System
This is an automated notification.
    ";
    
    return sendEmail($userEmail, $userName, $subject, $htmlBody, $textBody);
}
