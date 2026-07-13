<?php
/**
 * Daily email digest of pending reservation approvals for Staff/Admin.
 *
 * Usage: php scripts/send_staff_pending_digest.php [--dry-run] [--verbose]
 * Cron (weekdays 7 AM): 0 7 * * 1-5 cd /path && php scripts/send_staff_pending_digest.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail_helper.php';

$options = getopt('', ['dry-run', 'verbose']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

if (function_exists('env_value') && env_value('STAFF_PENDING_DIGEST_ENABLED', 'true') === 'false') {
    echo "Staff pending digest disabled (STAFF_PENDING_DIGEST_ENABLED=false).\n";
    exit(0);
}

$pdo = db();
$basePath = function_exists('base_path') ? base_path() : '';

echo "=== Staff Pending Approval Digest ===\n";
echo 'Started: ' . date('Y-m-d H:i:s') . "\n";
echo 'Dry run: ' . ($dryRun ? 'yes' : 'no') . "\n\n";

$count = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
if ($count === 0) {
    echo "No pending reservations. Skipping emails.\n";
    exit(0);
}

$staffStmt = $pdo->query(
    "SELECT id, name, email FROM users
     WHERE role IN ('Staff', 'Admin') AND status = 'active' AND email IS NOT NULL AND email != ''"
);
$staff = $staffStmt ? $staffStmt->fetchAll(PDO::FETCH_ASSOC) : [];

if ($staff === []) {
    echo "No active staff/admin emails found.\n";
    exit(0);
}

$manageUrl = (function_exists('env_value') ? rtrim((string)env_value('APP_URL', ''), '/') : '')
    . $basePath . '/dashboard/reservations-manage?view=pending';

$subject = "[CPRF] {$count} pending reservation(s) need review";
$body = '<p>Good morning,</p>'
    . '<p>There are <strong>' . $count . '</strong> reservation request(s) waiting for staff approval in CPRF.</p>'
    . '<p><a href="' . htmlspecialchars($manageUrl) . '">Open Reservation Approvals</a></p>'
    . '<p style="color:#64748b;font-size:12px;">This is an automated digest from Barangay Culiat CPRF.</p>';

$sent = 0;
foreach ($staff as $row) {
    $email = trim((string)($row['email'] ?? ''));
    if ($email === '') {
        continue;
    }
    if ($verbose) {
        echo "Would email: {$email}\n";
    }
    if ($dryRun) {
        $sent++;
        continue;
    }
    if (function_exists('sendMail') && sendMail($email, $subject, $body)) {
        $sent++;
        echo "Sent: {$email}\n";
    } else {
        echo "Failed: {$email}\n";
    }
}

echo "\nDone. Recipients: {$sent}\n";
