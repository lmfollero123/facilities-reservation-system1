<?php
/**
 * Attendance Reminders & Violations Script
 *
 * - Sends email reminders to Time In / Time Out when reservation time arrives
 * - Records violations for:
 *   - No-show (never Time In)
 *   - Late Time In (more than 1 hour after start)
 *   - Late Time Out (more than 1 hour after end)
 *
 * Run via cron, e.g. every 5–10 minutes:
 *   php scripts/attendance_reminders.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/time_helpers.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/mail_helper.php';
require_once __DIR__ . '/../config/email_templates.php';
require_once __DIR__ . '/../config/violations.php';

$pdo = db();
$now = new DateTime();
$today = $now->format('Y-m-d');

echo "=== Attendance Reminders & Violations ===\n";
echo "Now: " . $now->format('Y-m-d H:i:s') . "\n\n";

/**
 * Parse slot and build DateTime in local date
 */
function buildDateTime(string $date, string $slot, string $which): ?DateTime {
    $parsed = parseTimeSlot($slot);
    if (!$parsed) return null;
    $time = $which === 'end' ? $parsed['end']->format('H:i') : $parsed['start']->format('H:i');
    $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    return $dt ?: null;
}

// Fetch nearby approved reservations (yesterday, today, tomorrow)
$windowStart = (clone $now)->modify('-1 day')->format('Y-m-d');
$windowEnd = (clone $now)->modify('+1 day')->format('Y-m-d');

$stmt = $pdo->prepare(
    'SELECT r.id, r.user_id, r.reservation_date, r.time_slot, r.status,
            u.email, u.name AS user_name,
            a.time_in_at, a.time_out_at
     FROM reservations r
     JOIN users u ON u.id = r.user_id
     LEFT JOIN reservation_attendance a ON a.reservation_id = r.id
     WHERE r.status = "approved"
       AND r.reservation_date BETWEEN :start AND :end'
);
$stmt->execute([
    'start' => $windowStart,
    'end' => $windowEnd,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $resId = (int)$row['id'];
    $userId = (int)$row['user_id'];
    $email = $row['email'];
    $userName = $row['user_name'];
    $date = $row['reservation_date'];
    $slot = $row['time_slot'];
    $timeInAt = $row['time_in_at'] ? new DateTime($row['time_in_at']) : null;
    $timeOutAt = $row['time_out_at'] ? new DateTime($row['time_out_at']) : null;

    $startDt = buildDateTime($date, $slot, 'start');
    $endDt = buildDateTime($date, $slot, 'end');
    if (!$startDt || !$endDt) {
        continue;
    }

    $startPlusHour = (clone $startDt)->modify('+1 hour');
    $endPlusHour = (clone $endDt)->modify('+1 hour');

    $idKey = 'res:' . $resId;

    // === Email Reminders ===
    // Time In reminder: when reservation starts (10-minute window)
    if ($now >= $startDt && $now <= (clone $startDt)->modify('+10 minutes') && !$timeInAt) {
        if (checkRateLimit('attendance_time_in_reminder', $idKey, 1, 86400)) {
            echo "Time In reminder for reservation {$resId}\n";
            $body = getEmailInfoBox(
                '<p style="margin:0;">Your reservation is starting now. Please go to your dashboard and Time In.</p>',
                '#e3f8ef',
                '#0d7a43'
            );
            $emailBody = getEmailHeader('Reservation Time In Reminder')
                . '<p>Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>'
                . '<p>Your reservation scheduled for <strong>' . htmlspecialchars($date) . ' (' . htmlspecialchars($slot) . ')</strong> is starting.</p>'
                . $body
                . getEmailButton('Open Time In/Out', base_url() . '/dashboard/time-tracking', '#2563eb')
                . getEmailFooter();
            sendEmail($email, $userName, 'Reservation Time In Reminder', $emailBody);
        }
    }

    // Time Out reminder: when reservation should end (10-minute window), but only if user timed in
    if ($timeInAt && !$timeOutAt && $now >= $endDt && $now <= (clone $endDt)->modify('+10 minutes')) {
        if (checkRateLimit('attendance_time_out_reminder', $idKey, 1, 86400)) {
            echo "Time Out reminder for reservation {$resId}\n";
            $body = getEmailInfoBox(
                '<p style="margin:0;">Your reservation time is ending. Please go to your dashboard and Time Out.</p>',
                '#fff4e5',
                '#f59e0b'
            );
            $emailBody = getEmailHeader('Reservation Time Out Reminder')
                . '<p>Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>'
                . '<p>Your reservation scheduled for <strong>' . htmlspecialchars($date) . ' (' . htmlspecialchars($slot) . ')</strong> has reached its end time.</p>'
                . $body
                . getEmailButton('Open Time In/Out', base_url() . '/dashboard/time-tracking', '#2563eb')
                . getEmailFooter();
            sendEmail($email, $userName, 'Reservation Time Out Reminder', $emailBody);
        }
    }

    // === Violations ===
    // Helper to avoid duplicate violations for this reservation and type/description prefix
    $hasViolation = function (int $reservationId, string $type, string $prefix) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM user_violations 
             WHERE reservation_id = :rid AND violation_type = :vt AND description LIKE :pref LIMIT 1'
        );
        $stmt->execute([
            'rid' => $reservationId,
            'vt' => $type,
            'pref' => $prefix . '%',
        ]);
        return (bool)$stmt->fetchColumn();
    };

    // No-show: no Time In at all, and 1+ hour after end time
    if (!$timeInAt && $now > $endPlusHour && !$hasViolation($resId, 'no_show', 'No show')) {
        echo "Recording no-show for reservation {$resId}\n";
        recordViolation(
            $userId,
            'no_show',
            'medium',
            'No show: user did not Time In for reservation on ' . $date . ' (' . $slot . ').',
            $resId
        );
    }

    // Late Time In: Time In exists and is more than 1 hour after start
    if ($timeInAt && $timeInAt > $startPlusHour && !$hasViolation($resId, 'policy_violation', 'Late Time In')) {
        echo "Recording late Time In for reservation {$resId}\n";
        recordViolation(
            $userId,
            'policy_violation',
            'low',
            'Late Time In: checked in at ' . $timeInAt->format('Y-m-d H:i') . ' for reservation on ' . $date . ' (' . $slot . ').',
            $resId
        );
    }

    // Late Time Out: Time Out exists and is more than 1 hour after end time
    if ($timeOutAt && $timeOutAt > $endPlusHour && !$hasViolation($resId, 'policy_violation', 'Late Time Out')) {
        echo "Recording late Time Out for reservation {$resId}\n";
        recordViolation(
            $userId,
            'policy_violation',
            'low',
            'Late Time Out: checked out at ' . $timeOutAt->format('Y-m-d H:i') . ' for reservation on ' . $date . ' (' . $slot . ').',
            $resId
        );
    }
}

echo "\nDone.\n";

