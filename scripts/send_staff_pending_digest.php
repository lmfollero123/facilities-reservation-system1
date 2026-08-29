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
require_once __DIR__ . '/../config/email_templates.php';
require_once __DIR__ . '/../config/app_settings.php';
require_once __DIR__ . '/../config/notifications.php';

$options = getopt('', ['dry-run', 'verbose']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

if (function_exists('env_value') && env_value('STAFF_PENDING_DIGEST_ENABLED', 'true') === 'false') {
    echo "Staff pending digest disabled (STAFF_PENDING_DIGEST_ENABLED=false).\n";
    exit(0);
}

$pdo = db();

echo "=== Staff Pending Approval Digest ===\n";
echo 'Started: ' . date('Y-m-d H:i:s') . "\n";
echo 'Dry run: ' . ($dryRun ? 'yes' : 'no') . "\n\n";

$count = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
if ($count === 0) {
    echo "No pending reservations. Skipping emails.\n";
    exit(0);
}

$slaDays = frs_get_app_setting_int($pdo, 'sla_pending_days', 3);
$agingStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'pending' AND created_at < NOW() - INTERVAL :days DAY");
$agingStmt->bindValue(':days', $slaDays, PDO::PARAM_INT);
$agingStmt->execute();
$agingCount = (int)$agingStmt->fetchColumn();
echo "Aging (> {$slaDays} days): {$agingCount}\n";

$staffStmt = $pdo->query(
    "SELECT id, name, email FROM users
     WHERE role IN ('Staff', 'Admin') AND status = 'active' AND email IS NOT NULL AND email != ''"
);
$staff = $staffStmt ? $staffStmt->fetchAll(PDO::FETCH_ASSOC) : [];

if ($staff === []) {
    echo "No active staff/admin emails found.\n";
    exit(0);
}

// Hardcoded relative path: base_path() derives from $_SERVER['SCRIPT_NAME'],
// which is meaningless in a CLI/cron invocation (it reflects this script's
// own path instead of "", the effective value everywhere else since this
// site is installed at the domain root) and produced broken links like
// https://cprf.infragovservices.comscripts/... in past digest runs.
$manageUrl = base_url() . '/dashboard/reservations-manage?view=pending';

$subject = "[CPRF] {$count} pending reservation(s) need review";
$body = getStaffPendingDigestEmailTemplate($count, $manageUrl, $agingCount, $slaDays);
$textBody = strip_tags(str_replace(['<br>', '</p>', '</li>'], ["\n", "\n", "\n"], $body));

$sent = 0;
foreach ($staff as $row) {
    $email = trim((string)($row['email'] ?? ''));
    if ($email === '') {
        continue;
    }
    if ($verbose) {
        echo "Would email: {$email}\n";
    }
    if (!$dryRun) {
        $staffName = trim((string)($row['name'] ?? '')) ?: 'Staff';
        if (sendEmail($email, $staffName, $subject, $body, $textBody)) {
            echo "Sent: {$email}\n";
        } else {
            echo "Failed: {$email}\n";
        }
    }
    $sent++;

    // One in-app notification per run per recipient when there are genuinely
    // stale requests - not per stale reservation, to avoid flooding.
    if ($agingCount > 0 && !$dryRun) {
        createNotification(
            (int)$row['id'],
            'system',
            "{$agingCount} reservation(s) pending too long",
            "{$agingCount} reservation request(s) have been pending more than {$slaDays} day(s) and need review.",
            $manageUrl
        );
    }
}

echo "\nDone. Recipients: {$sent}\n";
