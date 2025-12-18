<?php
/**
 * Auto-Decline Expired Reservations Script
 * Run this via cron job daily to decline expired pending reservations
 * 
 * Usage: php scripts/auto_decline_expired.php [--dry-run]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

$pdo = db();

echo "=== Auto-Decline Expired Reservations Script ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Dry Run: " . ($dryRun ? 'Yes' : 'No') . "\n";
echo "\n";

// Get all pending reservations that have passed
// Check both date and time slot (parse time slot to get end time)
$stmt = $pdo->query(
    "SELECT r.id, r.reservation_date, r.time_slot, r.user_id, r.facility_id,
            f.name as facility_name, u.name as user_name, u.email as user_email
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.status = 'pending'
       AND (
         r.reservation_date < CURDATE()
         OR (
           r.reservation_date = CURDATE()
           AND (
             -- Check if time slot has passed (basic check for HH:MM - HH:MM format)
             SUBSTRING_INDEX(SUBSTRING_INDEX(r.time_slot, ' - ', -1), ':', 1) < HOUR(NOW())
             OR (
               SUBSTRING_INDEX(SUBSTRING_INDEX(r.time_slot, ' - ', -1), ':', 1) = HOUR(NOW())
               AND SUBSTRING_INDEX(SUBSTRING_INDEX(r.time_slot, ' - ', -1), ':', -1) < MINUTE(NOW())
             )
           )
         )
       )"
);

$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$count = count($reservations);

echo "Found {$count} expired pending reservations\n\n";

if ($count === 0) {
    echo "No expired reservations to decline.\n";
    exit(0);
}

$declined = 0;
$failed = 0;

$pdo->beginTransaction();

try {
    foreach ($reservations as $reservation) {
        echo "Processing Reservation ID {$reservation['id']}: {$reservation['facility_name']} on {$reservation['reservation_date']}\n";
        
        if ($dryRun) {
            echo "  [DRY RUN] Would decline this reservation\n";
            $declined++;
            continue;
        }
        
        // Update reservation status
        $updateStmt = $pdo->prepare(
            "UPDATE reservations 
             SET status = 'denied', updated_at = NOW() 
             WHERE id = ?"
        );
        
        if (!$updateStmt->execute([$reservation['id']])) {
            echo "  ✗ Failed to update reservation\n";
            $failed++;
            continue;
        }
        
        // Create history entry
        $histStmt = $pdo->prepare(
            "INSERT INTO reservation_history (reservation_id, status, note, created_at)
             VALUES (?, 'denied', 'Auto-declined: Reservation date/time has passed', NOW())"
        );
        $histStmt->execute([$reservation['id']]);
        
        // Create notification for user
        $notifStmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, type, title, message, link, created_at)
             VALUES (?, 'booking', 'Reservation Auto-Declined', 
                    CONCAT('Your reservation for ', ?, ' on ', ?), 
                    ?, NOW())"
        );
        $link = base_path() . '/resources/views/pages/dashboard/my_reservations.php';
        $notifStmt->execute([
            $reservation['user_id'],
            $reservation['facility_name'],
            $reservation['reservation_date'],
            $link
        ]);
        
        // Log audit event
        logAudit(
            'Auto-declined expired reservation',
            'Reservation Management',
            "Reservation ID: {$reservation['id']}, User: {$reservation['user_name']}, Facility: {$reservation['facility_name']}"
        );
        
        echo "  ✓ Declined successfully\n";
        $declined++;
        
        if ($verbose) {
            echo "    User: {$reservation['user_name']} ({$reservation['email']})\n";
            echo "    Facility: {$reservation['facility_name']}\n";
            echo "    Date: {$reservation['reservation_date']}\n";
        }
    }
    
    if (!$dryRun) {
        $pdo->commit();
    }
    
} catch (Exception $e) {
    if (!$dryRun) {
        $pdo->rollBack();
    }
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Summary ===\n";
if ($dryRun) {
    echo "Dry run completed - would have declined {$declined} reservations\n";
} else {
    echo "Declined: {$declined} reservations\n";
    echo "Failed: {$failed} reservations\n";
}

echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

