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
$body = getStaffPendingDigestEmailTemplate($count, $manageUrl);
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
    if ($dryRun) {
        $sent++;
        continue;
    }
    $staffName = trim((string)($row['name'] ?? '')) ?: 'Staff';
    if (sendEmail($email, $staffName, $subject, $body, $textBody)) {
        $sent++;
        echo "Sent: {$email}\n";
    } else {
        echo "Failed: {$email}\n";
    }
}

echo "\nDone. Recipients: {$sent}\n";
