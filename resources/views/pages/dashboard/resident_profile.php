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
