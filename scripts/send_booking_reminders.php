<?php
/**
 * Send 24-hour reminders for approved reservations happening tomorrow.
 *
 * Usage: php scripts/send_booking_reminders.php [--dry-run] [--verbose] [--date=YYYY-MM-DD]
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/notification_preferences.php';
require_once __DIR__ . '/../config/mail_helper.php';
require_once __DIR__ . '/../config/sms_helper.php';

$options = getopt('', ['dry-run', 'verbose', 'date:']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$targetDate = isset($options['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])
    ? (string)$options['date']
    : date('Y-m-d', strtotime('+1 day'));

frs_ensure_notification_preferences_schema();

$pdo = db();
$basePath = function_exists('base_path') ? base_path() : '';

echo "=== Booking Reminders (24h) ===\n";
echo 'Started: ' . date('Y-m-d H:i:s') . "\n";
echo 'Dry run: ' . ($dryRun ? 'yes' : 'no') . "\n";
echo 'Target date: ' . $targetDate . "\n\n";

$tomorrow = $targetDate;

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
    WHERE r.status IN ('approved', 'pending_payment')
      AND r.reservation_date = :tomorrow
";
if ($hasReminderCol) {
    $sql .= ' AND r.reminder_sent_at IS NULL';
}

$stmt = $pdo->prepare($sql);
$stmt->execute(['tomorrow' => $tomorrow]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo 'Target date (' . $tomorrow . '): ' . count($rows) . " confirmed reservation(s) to remind (approved or pending_payment).\n";

if ($verbose && count($rows) === 0) {
    $diag = $pdo->prepare(
        "SELECT r.id, r.reservation_date, r.status, r.reminder_sent_at, f.name AS facility_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         WHERE r.reservation_date >= CURDATE()
           AND r.reservation_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         ORDER BY r.reservation_date ASC, r.id ASC
         LIMIT 20"
    );
    $diag->execute();
    $upcoming = $diag->fetchAll(PDO::FETCH_ASSOC);
    echo "\nNo matches for target date. Upcoming reservations (next 7 days, any status):\n";
    if (!$upcoming) {
        echo "  (none)\n";
    } else {
        foreach ($upcoming as $u) {
            $rs = $u['reminder_sent_at'] ?? 'null';
            echo "  #{$u['id']} {$u['reservation_date']} [{$u['status']}] {$u['facility_name']} reminder_sent_at={$rs}\n";
        }
    }
}
echo "\n";

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
