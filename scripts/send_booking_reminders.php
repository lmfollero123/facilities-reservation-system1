<?php
/**
 * Send 24-hour reminders for approved reservations happening tomorrow.
 *
 * Usage: php scripts/send_booking_reminders.php [--dry-run] [--verbose]
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/notification_preferences.php';
require_once __DIR__ . '/../config/mail_helper.php';
require_once __DIR__ . '/../config/sms_helper.php';

$options = getopt('', ['dry-run', 'verbose']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

frs_ensure_notification_preferences_schema();

$pdo = db();
$basePath = function_exists('base_path') ? base_path() : '';

echo "=== Booking Reminders (24h) ===\n";
echo 'Started: ' . date('Y-m-d H:i:s') . "\n";
echo 'Dry run: ' . ($dryRun ? 'yes' : 'no') . "\n\n";

$tomorrow = date('Y-m-d', strtotime('+1 day'));

$hasReminderCol = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'reminder_sent_at'");
    $hasReminderCol = $col && $col->rowCount() > 0;
} catch (Throwable $e) {
    echo "Warning: could not check reminder_sent_at column.\n";
}

$sql = "
    SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.purpose, r.status,
           f.name AS facility_name,
           u.name AS requester_name, u.email AS requester_email, u.mobile AS requester_mobile
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    JOIN users u ON r.user_id = u.id
    WHERE r.status = 'approved'
      AND r.reservation_date = :tomorrow
";
if ($hasReminderCol) {
    $sql .= ' AND r.reminder_sent_at IS NULL';
}

$stmt = $pdo->prepare($sql);
$stmt->execute(['tomorrow' => $tomorrow]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo 'Tomorrow (' . $tomorrow . '): ' . count($rows) . " approved reservation(s) to remind.\n\n";

$sent = 0;
$skipped = 0;

foreach ($rows as $row) {
    $resId = (int)$row['id'];
    $userId = (int)$row['user_id'];
    $facility = (string)$row['facility_name'];
    $dateLabel = date('F j, Y', strtotime((string)$row['reservation_date']));
    $slot = (string)$row['time_slot'];
    $link = $basePath . '/dashboard/book-facility?module=mine';

    echo "Reservation #{$resId} — {$facility} — {$row['requester_name']}\n";

    if ($dryRun) {
        echo "  [DRY RUN] Would send reminder channels per user preferences.\n";
        $sent++;
        continue;
    }

    $title = 'Reservation reminder';
    $message = "Your booking at {$facility} is scheduled for tomorrow ({$dateLabel}), {$slot}. Please arrive on time and bring any required documents.";

    if (frs_user_wants_notification($userId, 'reminder', 'in_app')) {
        createNotification($userId, 'reminder', $title, $message, $link);
    } else {
        $skipped++;
    }

    if (frs_user_wants_notification($userId, 'reminder', 'email') && !empty($row['requester_email'])) {
        $html = '<p>Hello ' . htmlspecialchars((string)$row['requester_name']) . ',</p>'
            . '<p>This is a reminder that your facility reservation is <strong>tomorrow</strong>:</p>'
            . '<ul><li><strong>Facility:</strong> ' . htmlspecialchars($facility) . '</li>'
            . '<li><strong>Date:</strong> ' . htmlspecialchars($dateLabel) . '</li>'
            . '<li><strong>Time:</strong> ' . htmlspecialchars($slot) . '</li></ul>'
            . '<p><a href="' . htmlspecialchars(function_exists('base_url') ? base_url() . $link : $link) . '">View My Reservations</a></p>';
        sendEmail(
            (string)$row['requester_email'],
            (string)$row['requester_name'],
            'Reminder: Your facility reservation is tomorrow',
            $html,
            strip_tags(str_replace(['<br>', '</p>', '</li>'], ["\n", "\n", "\n"], $html))
        );
    }

    $smsPayload = [
        'user_id' => $userId,
        'facility_name' => $facility,
        'reservation_date' => (string)$row['reservation_date'],
        'time_slot' => $slot,
        'requester_mobile' => $row['requester_mobile'] ?? null,
        'mobile' => $row['requester_mobile'] ?? null,
    ];
    sendReservationStatusSms($smsPayload, 'reminder');

    if ($hasReminderCol) {
        $upd = $pdo->prepare('UPDATE reservations SET reminder_sent_at = NOW() WHERE id = :id');
        $upd->execute(['id' => $resId]);
    }

    $sent++;
    if ($verbose) {
        echo "  Reminder sent (in-app/email/SMS per preferences).\n";
    }
}

echo "\nDone. Processed: {$sent}, preference skips (in-app only): {$skipped}\n";
