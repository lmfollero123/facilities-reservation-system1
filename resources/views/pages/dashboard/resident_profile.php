<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$actorRole = $_SESSION['role'] ?? '';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($actorRole, 'users')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/violations.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/ui_helpers.php';

$pdo = db();
$currentUserId = $_SESSION['user_id'] ?? null;
$viewedUserId = (int)($_GET['user_id'] ?? 0);

if ($viewedUserId <= 0) {
    header('Location: ' . base_path() . '/dashboard/user-management');
    exit;
}

$userStmt = $pdo->prepare('SELECT id, name, email, mobile, address, role, status, lock_reason, is_verified, verified_at, profile_picture, created_at FROM users WHERE id = :id');
$userStmt->execute(['id' => $viewedUserId]);
$viewedUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$viewedUser) {
    header('Location: ' . base_path() . '/dashboard/user-management');
    exit;
}

$isSelfView = ($currentUserId !== null && (int)$currentUserId === $viewedUserId);

if (!$isSelfView) {
    logAudit('Viewed resident profile', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
}

$message = '';
$messageType = 'success';
$action = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!frs_csrf_ok()) {
        $message = 'Security check failed. Please try again.';
        $messageType = 'error';
    } elseif ($viewedUserId === (int)$currentUserId && in_array($_POST['action'], ['lock', 'delete'], true)) {
        $message = 'You cannot perform this action on your own account.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'];
        $permissionError = false;
        switch ($action) {
            case 'delete':
                if (!frs_can_delete($actorRole, 'users')) {
                    $permissionError = true;
                }
                break;
            case 'lock':
            case 'unlock':
                if (!frs_can_update($actorRole, 'users')) {
                    $permissionError = true;
                }
                break;
        }

        if ($permissionError) {
            $message = 'You do not have permission to perform this action.';
            $messageType = 'error';
        } else {
            switch ($action) {
                case 'lock':
                    $lockReason = trim($_POST['lock_reason'] ?? '');
                    $stmt = $pdo->prepare('UPDATE users SET status = "locked", lock_reason = :lock_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $viewedUserId, 'lock_reason' => $lockReason !== '' ? $lockReason : null]);
                    logAudit('Locked user account', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
                    $message = 'User account locked successfully.';
                    if (!empty($viewedUser['email'])) {
                        try {
                            $body = getAccountLockedEmailTemplate($viewedUser['name'], $lockReason);
                            sendEmail($viewedUser['email'], $viewedUser['name'], 'Account Locked', $body);
                        } catch (Exception $e) {
                            // ignore email failures here
                        }
                    }
                    break;

                case 'unlock':
                    $stmt = $pdo->prepare('UPDATE users SET status = "active", lock_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $viewedUserId]);
                    logAudit('Unlocked user account', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
                    $message = 'User account unlocked successfully.';
                    break;

                case 'reset_password':
                    $newPassword = bin2hex(random_bytes(6));
                    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 1, failed_login_attempts = 0, locked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['password_hash' => $newPasswordHash, 'id' => $viewedUserId]);
                    logAudit('Reset user password', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
                    $message = 'Password reset successfully. New credentials have been sent to the user\'s email.';
                    if (!empty($viewedUser['email'])) {
                        try {
                            $body = "<p>Hi " . htmlspecialchars($viewedUser['name']) . ",</p>"
                                  . "<p>Your password has been reset by an administrator. Here are your new login credentials:</p>"
                                  . "<div style='background: #f5f7fa; padding: 1rem; border-radius: 8px; margin: 1rem 0;'>"
                                  . "<p style='margin: 0.5rem 0;'><strong>Email:</strong> " . htmlspecialchars($viewedUser['email']) . "</p>"
                                  . "<p style='margin: 0.5rem 0;'><strong>New Password:</strong> <code style='background: #fff; padding: 0.25rem 0.5rem; border-radius: 4px; font-family: monospace;'>" . htmlspecialchars($newPassword) . "</code></p>"
                                  . "</div>"
                                  . "<p><strong>Important:</strong> For security reasons, please change your password immediately after logging in.</p>";
                            sendEmail($viewedUser['email'], $viewedUser['name'], 'Your Password Has Been Reset', $body);
                        } catch (Exception $e) {
                            error_log('Failed to send password reset email: ' . $e->getMessage());
                            $message .= ' However, the email could not be sent. Please provide the new password manually.';
                        }
                    }
                    break;

                case 'delete':
                    $deleteReason = trim($_POST['delete_reason'] ?? '');
                    if (strlen($deleteReason) < 10) {
                        $message = 'Please provide a deletion reason (at least 10 characters).';
                        $messageType = 'error';
                        break;
                    }
                    if (($viewedUser['role'] ?? '') === 'Admin') {
                        $adminCountStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Admin"');
                        if ((int)$adminCountStmt->fetchColumn() <= 1) {
                            $message = 'Cannot delete the only remaining administrator account.';
                            $messageType = 'error';
                            break;
                        }
                    }
                    logAudit('Deleted user account', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ') — Reason: ' . $deleteReason);
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $stmt->execute(['id' => $viewedUserId]);
                    header('Location: ' . base_path() . '/dashboard/user-management');
                    exit;

                default:
                    $message = 'Unknown action.';
                    $messageType = 'error';
            }
        }
    }

    if ($messageType === 'success' && $action !== 'delete') {
        $userStmt->execute(['id' => $viewedUserId]);
        $viewedUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
}

$reservationsStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name
     FROM reservations r
     LEFT JOIN facilities f ON r.facility_id = f.id
     WHERE r.user_id = :user_id
     ORDER BY r.reservation_date DESC, r.id DESC
     LIMIT 20'
);
$reservationsStmt->execute(['user_id' => $viewedUserId]);
$recentReservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);

$violations = getUserViolations($viewedUserId);

$canUpdateUsers = frs_can_update($actorRole, 'users');
$canDeleteUsers = frs_can_delete($actorRole, 'users');
$base = base_path();
$profilePicUrl = !empty($viewedUser['profile_picture']) ? $base . $viewedUser['profile_picture'] : null;
$initials = '';
foreach (explode(' ', (string)$viewedUser['name']) as $part) {
    if ($part !== '') { $initials .= strtoupper($part[0]); }
    if (strlen($initials) >= 2) { break; }
}

ob_start();
?>
<div class="dashboard-content">
    <?= frs_page_title('Resident Profile', 'View account details, reservation history, and violations.'); ?>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success'; ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <section class="booking-card">
        <div style="display:flex; gap:1.25rem; align-items:center; flex-wrap:wrap;">
            <div style="width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:1.5rem; <?= $profilePicUrl ? 'background-image:url(' . htmlspecialchars($profilePicUrl) . '); background-size:cover; background-position:center;' : 'background:linear-gradient(135deg, #2563eb, #1d4ed8);'; ?>">
                <?php if (!$profilePicUrl): ?><?= htmlspecialchars($initials ?: '?'); ?><?php endif; ?>
            </div>
            <div style="flex:1; min-width:200px;">
                <h2 style="margin:0 0 0.25rem;"><?= htmlspecialchars($viewedUser['name']); ?></h2>
                <p style="margin:0; color:#6b7280;"><?= htmlspecialchars($viewedUser['email']); ?> · <?= htmlspecialchars($viewedUser['mobile'] ?? '—'); ?></p>
                <p style="margin:0.25rem 0 0; color:#6b7280;"><?= htmlspecialchars($viewedUser['address'] ?? '—'); ?></p>
            </div>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <span class="status-badge <?= htmlspecialchars(strtolower((string)$viewedUser['role'])); ?>"><?= htmlspecialchars((string)$viewedUser['role']); ?></span>
                <span class="status-badge <?= $viewedUser['status'] === 'active' ? 'active' : ($viewedUser['status'] === 'locked' ? 'offline' : 'pending'); ?>"><?= htmlspecialchars(ucfirst((string)$viewedUser['status'])); ?></span>
                <span class="status-badge <?= $viewedUser['is_verified'] ? 'active' : 'pending'; ?>"><?= $viewedUser['is_verified'] ? 'Verified' : 'Unverified'; ?></span>
            </div>
        </div>
    </section>

    <section class="booking-card">
        <h3 style="margin-top:0;">Account Actions</h3>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <?php if ($canUpdateUsers): ?>
                <form method="POST" style="display:inline;">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" class="btn-outline" onclick="return frsConfirmSubmit(this.form, 'Reset this user\'s password?', {title: 'Reset password'});">Reset Password</button>
                </form>
                <?php if ($viewedUser['status'] === 'locked'): ?>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="unlock">
                        <button type="submit" class="btn-outline">Unlock Account</button>
                    </form>
                <?php elseif ((int)$currentUserId !== $viewedUserId): ?>
                    <form method="POST" style="display:flex; gap:0.4rem; align-items:center;" onsubmit="return frsConfirmSubmit(this, 'Lock this account?', {title: 'Lock account'});">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="lock">
                        <input type="text" name="lock_reason" placeholder="Reason (optional)" style="padding:0.6rem;">
                        <button type="submit" class="btn-outline">Lock Account</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canDeleteUsers && (int)$currentUserId !== $viewedUserId): ?>
                <form method="POST" style="display:flex; gap:0.4rem; align-items:center;" onsubmit="return frsConfirmSubmit(this, 'Delete this account permanently?', {title: 'Delete account permanently', danger: true, confirmText: 'Delete account'});">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="text" name="delete_reason" placeholder="Deletion reason (min 10 chars)" required minlength="10" style="padding:0.6rem; min-width:220px;">
                    <button type="submit" class="btn btn-danger">Delete Account</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="booking-card">
        <h3 style="margin-top:0;">Recent Reservations</h3>
        <?php if ($recentReservations === []): ?>
            <p style="color:#8b95b5;">No reservations found.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Facility</th><th>Date</th><th>Time</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($recentReservations as $res): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($res['facility_name'] ?? '—')); ?></td>
                            <td><?= htmlspecialchars((string)$res['reservation_date']); ?></td>
                            <td><?= htmlspecialchars((string)$res['time_slot']); ?></td>
                            <td><span class="status-badge <?= htmlspecialchars((string)$res['status']); ?>"><?= htmlspecialchars(ucfirst((string)$res['status'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><a href="<?= htmlspecialchars($base . '/dashboard/reservations-manage?requester_id=' . $viewedUserId); ?>">View all reservations for this resident →</a></p>
        <?php endif; ?>
    </section>

    <section class="booking-card">
        <h3 style="margin-top:0;">Violations History</h3>
        <?php if ($violations === []): ?>
            <p style="color:#8b95b5;">No violations recorded.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Type</th><th>Severity</th><th>Facility</th><th>Recorded</th><th>Description</th></tr></thead>
                <tbody>
                    <?php foreach ($violations as $v): ?>
                        <tr>
                            <td><?= htmlspecialchars(frs_violation_type_label((string)$v['violation_type'])); ?></td>
                            <td><span class="status-badge <?= in_array($v['severity'], ['high', 'critical'], true) ? 'offline' : 'pending'; ?>"><?= htmlspecialchars(ucfirst((string)$v['severity'])); ?></span></td>
                            <td><?= htmlspecialchars((string)($v['facility_name'] ?? '—')); ?></td>
                            <td><?= htmlspecialchars((string)$v['created_at']); ?></td>
                            <td><?= htmlspecialchars((string)($v['description'] ?? '—')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
