<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

// RBAC: User Management for Admin and Staff (Staff: resident accounts only)
$actorRole = $_SESSION['role'] ?? '';
if (!($_SESSION['user_authenticated'] ?? false) || !in_array($actorRole, ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}
$isPageAdmin = $actorRole === 'Admin';

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/user_admin.php';
require_once __DIR__ . '/../../../../config/culiat_streets.php';
require_once __DIR__ . '/../../../../config/violations.php';
$pdo = db();
$pageTitle = 'User Management | LGU Facilities Reservation';

$message = '';
$messageType = 'success';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !frs_csrf_ok()) {
    $message = 'Your session expired or the form is invalid. Please refresh and try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $createName = trim($_POST['create_name'] ?? '');
    $createEmail = trim($_POST['create_email'] ?? '');
    $createMobile = trim($_POST['create_mobile'] ?? '');
    $createStreet = trim($_POST['create_street'] ?? '');
    $createHouseNumber = trim($_POST['create_house_number'] ?? '');
    $createAddress = frs_build_culiat_address($createHouseNumber, $createStreet);
    $createRole = $_POST['create_role'] ?? 'Resident';
    $createPassword = trim($_POST['create_password'] ?? '');
    $markEmailVerified = isset($_POST['create_email_verified']);
    $markIdVerified = isset($_POST['create_id_verified']);

    if (!$isPageAdmin) {
        $createRole = 'Resident';
    }

    $result = frs_admin_create_user(
        $pdo,
        $createName,
        $createEmail,
        $createRole,
        $createMobile !== '' ? $createMobile : null,
        $createAddress !== '' ? $createAddress : null,
        $createPassword !== '' ? $createPassword : null,
        $markEmailVerified,
        $markIdVerified,
        (int)($_SESSION['user_id'] ?? 0),
        $createStreet !== '' ? $createStreet : null,
        $createHouseNumber !== '' ? $createHouseNumber : null
    );

    if (!$result['ok']) {
        $message = $result['message'];
        $messageType = 'error';
    } else {
        $roleLabel = $createRole === 'Staff' ? 'Staff' : 'Resident';
        logAudit(
            'Created user account',
            'User Management',
            $createName . ' (' . $createEmail . ') — Role: ' . $roleLabel
        );
        $message = 'Account created for ' . $createEmail . '. Login credentials were sent by email.';

        if (!empty($result['plain_password'])) {
            try {
                $body = getAdminCreatedAccountEmailTemplate(
                    $createName,
                    $createEmail,
                    $result['plain_password'],
                    $roleLabel
                );
                sendEmail($createEmail, $createName, 'Your Facilities Reservation Account', $body);
            } catch (Throwable $e) {
                error_log('Failed to send admin-created account email: ' . $e->getMessage());
                $message .= ' However, the welcome email could not be sent — please share the temporary password manually.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    $userId = (int)$_POST['user_id'];
    $action = $_POST['action'];
    $currentUserId = $_SESSION['user_id'] ?? null;
    
    // Prevent self-modification for certain actions
    if ($userId === $currentUserId && in_array($action, ['lock', 'unlock', 'delete'], true)) {
        $message = 'You cannot perform this action on your own account.';
        $messageType = 'error';
    } elseif (!$isPageAdmin && in_array($action, ['change_role', 'delete'], true)) {
        $message = 'You do not have permission to perform this action.';
        $messageType = 'error';
    } else {
        try {
            switch ($action) {
                case 'approve':
                    // Get user info for audit log
                    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                    $userStmt->execute(['id' => $userId]);
                    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare('UPDATE users SET status = "active", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $userId]);
                    
                    logAudit('Approved user account', 'User Management', $userInfo ? ($userInfo['name'] . ' (' . $userInfo['email'] . ')') : 'User ID: ' . $userId);
                    $message = 'User account approved successfully.';
                    
                    // Send approval email
                    if ($userInfo && !empty($userInfo['email'])) {
                        $body = getAccountApprovedEmailTemplate($userInfo['name']);
                        sendEmail($userInfo['email'], $userInfo['name'], 'Account Approved', $body);
                    }
                    break;
                    
                case 'verify':
                    // Verify user's ID document
                    $adminId = $_SESSION['user_id'] ?? null;
                    $userStmt = $pdo->prepare('SELECT name, email, is_verified FROM users WHERE id = :id');
                    $userStmt->execute(['id' => $userId]);
                    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$userInfo) {
                        $message = 'User not found.';
                        $messageType = 'error';
                        break;
                    }
                    
                    // Check if user has a valid ID document
                    $docStmt = $pdo->prepare('SELECT id FROM user_documents WHERE user_id = :user_id AND document_type = "valid_id" AND is_archived = 0 LIMIT 1');
                    $docStmt->execute(['user_id' => $userId]);
                    $hasValidId = $docStmt->fetch() !== false;
                    
                    if (!$hasValidId) {
                        $message = 'User has not uploaded a valid ID document. Cannot verify.';
                        $messageType = 'error';
                        break;
                    }
                    
                    $stmt = $pdo->prepare('UPDATE users SET is_verified = TRUE, verified_at = CURRENT_TIMESTAMP, verified_by = :admin_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $userId, 'admin_id' => $adminId]);
                    
                    logAudit('Verified user account', 'User Management', $userInfo ? ($userInfo['name'] . ' (' . $userInfo['email'] . ')') : 'User ID: ' . $userId);
                    $message = 'User account verified successfully. User can now use auto-approval features.';
                    
                    // Notify user
                    if ($userInfo && !empty($userInfo['email'])) {
                        try {
                            require_once __DIR__ . '/../../../../config/notifications.php';
                            createNotification(
                                $userId,
                                'system',
                                'Account Verified',
                                'Your account has been verified. You can now use auto-approval features for facility bookings.',
                                base_path() . '/dashboard/profile'
                            );
                            
                            $body = getAccountVerifiedEmailTemplate($userInfo['name']);
                            sendEmail($userInfo['email'], $userInfo['name'], 'Account Verified', $body);
                        } catch (Exception $e) {
                            // ignore notification/email failures
                        }
                    }
                    break;
                    
                case 'deny':
                    // Get user info for audit log
                    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                    $userStmt->execute(['id' => $userId]);
                    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Check if user has any reservations
                    $reservationCheck = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = :id');
                    $reservationCheck->execute(['id' => $userId]);
                    $hasReservations = (int)$reservationCheck->fetchColumn() > 0;
                    
                    if ($hasReservations) {
                        $message = 'Cannot remove user with existing reservations. Please lock the account instead.';
                        $messageType = 'error';
                    } else {
                        // Only delete if user has no reservations and is pending
                        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id AND status = "pending"');
                        $stmt->execute(['id' => $userId]);
                        if ($stmt->rowCount() > 0) {
                            logAudit('Removed pending user account', 'User Management', $userInfo ? ($userInfo['name'] . ' (' . $userInfo['email'] . ')') : 'User ID: ' . $userId);
                            $message = 'User account removed successfully.';
                        } else {
                            $message = 'User cannot be removed. Account may not be pending or may have reservations.';
                            $messageType = 'error';
                        }
                    }
                    break;
                    
                case 'lock':
                    // Get user info for audit log
                    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                    $userStmt->execute(['id' => $userId]);
                    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                    $lockReason = trim($_POST['lock_reason'] ?? '');
                    
                    $stmt = $pdo->prepare('UPDATE users SET status = "locked", lock_reason = :lock_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $userId, 'lock_reason' => $lockReason !== '' ? $lockReason : null]);
                    
                    logAudit('Locked user account', 'User Management', $userInfo ? ($userInfo['name'] . ' (' . $userInfo['email'] . ')') : 'User ID: ' . $userId);
                    $message = 'User account locked successfully.';

                    // Notify user via email
                    if ($userInfo && !empty($userInfo['email'])) {
                        try {
                            $body = getAccountLockedEmailTemplate($userInfo['name'], $lockReason);
                            sendEmail($userInfo['email'], $userInfo['name'], 'Account Locked', $body);
                        } catch (Exception $e) {
                            // ignore email failures here
                        }
                    }
                    break;
                    
                case 'unlock':
                    // Get user info for audit log
                    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                    $userStmt->execute(['id' => $userId]);
                    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare('UPDATE users SET status = "active", lock_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $userId]);
                    
                    logAudit('Unlocked user account', 'User Management', $userInfo ? ($userInfo['name'] . ' (' . $userInfo['email'] . ')') : 'User ID: ' . $userId);
                    $message = 'User account unlocked successfully.';
                    break;
                    
                case 'change_role':
                    $newRole = $_POST['new_role'] ?? '';
                    if (in_array($newRole, ['Admin', 'Staff', 'Resident'], true)) {
                        // Get user info and old role for audit log
                        $userStmt = $pdo->prepare('SELECT name, email, role, is_verified FROM users WHERE id = :id');
                        $userStmt->execute(['id' => $userId]);
                        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                        $oldRole = $userInfo['role'] ?? 'Unknown';
                        
                        // Determine verification status based on new role
                        $shouldBeVerified = null;
                        $adminId = $_SESSION['user_id'] ?? null;
                        
                        if (in_array($newRole, ['Admin', 'Staff'], true)) {
                            // Staff and Admin are automatically verified (no ID required)
                            $shouldBeVerified = true;
                            $stmt = $pdo->prepare('UPDATE users SET role = :role, is_verified = TRUE, verified_at = CURRENT_TIMESTAMP, verified_by = :admin_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                            $stmt->execute(['role' => $newRole, 'id' => $userId, 'admin_id' => $adminId]);
                        } else {
                            // Resident role - check if they have a valid ID uploaded
                            $docStmt = $pdo->prepare('SELECT id FROM user_documents WHERE user_id = :user_id AND document_type = "valid_id" AND is_archived = 0 LIMIT 1');
                            $docStmt->execute(['user_id' => $userId]);
                            $hasValidId = $docStmt->fetch() !== false;
                            
                            if ($hasValidId) {
                                // Keep current verification status if they have uploaded ID
                                $stmt = $pdo->prepare('UPDATE users SET role = :role, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                                $stmt->execute(['role' => $newRole, 'id' => $userId]);
                            } else {
                                // Unverify if no valid ID uploaded
                                $shouldBeVerified = false;
                                $stmt = $pdo->prepare('UPDATE users SET role = :role, is_verified = FALSE, verified_at = NULL, verified_by = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                                $stmt->execute(['role' => $newRole, 'id' => $userId]);
                            }
                        }
                        
                        $verificationNote = '';
                        if ($shouldBeVerified === true) {
                            $verificationNote = ' (automatically verified)';
                        } elseif ($shouldBeVerified === false) {
                            $verificationNote = ' (unverified - no valid ID uploaded)';
                        }
                        
                        logAudit('Changed user role', 'User Management', 
                            ($userInfo ? ($userInfo['name'] . ' (' . $userInfo['email'] . ')') : 'User ID: ' . $userId) . 
                            ' - Changed from ' . $oldRole . ' to ' . $newRole . $verificationNote);
                        $message = 'User role updated successfully.' . $verificationNote;
                    } else {
                        $message = 'Invalid role selected.';
                        $messageType = 'error';
                    }
                    break;
                    
                case 'reset_password':
                    // Get user info for email
                    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                    $userStmt->execute(['id' => $userId]);
                    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$userInfo) {
                        $message = 'User not found.';
                        $messageType = 'error';
                        break;
                    }
                    
                    // Generate a secure random password (12 characters with mixed case, numbers, and symbols)
                    $newPassword = bin2hex(random_bytes(6)); // 12 character hex string
                    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    // Update password in database
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, failed_login_attempts = 0, locked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['password_hash' => $newPasswordHash, 'id' => $userId]);
                    
                    logAudit('Reset user password', 'User Management', $userInfo['name'] . ' (' . $userInfo['email'] . ')');
                    $message = 'Password reset successfully. New credentials have been sent to the user\'s email.';
                    
                    // Send email with new credentials
                    if (!empty($userInfo['email'])) {
                        try {
                            $body = "<p>Hi " . htmlspecialchars($userInfo['name']) . ",</p>"
                                  . "<p>Your password has been reset by an administrator. Here are your new login credentials:</p>"
                                  . "<div style='background: #f5f7fa; padding: 1rem; border-radius: 8px; margin: 1rem 0;'>"
                                  . "<p style='margin: 0.5rem 0;'><strong>Email:</strong> " . htmlspecialchars($userInfo['email']) . "</p>"
                                  . "<p style='margin: 0.5rem 0;'><strong>New Password:</strong> <code style='background: #fff; padding: 0.25rem 0.5rem; border-radius: 4px; font-family: monospace;'>" . htmlspecialchars($newPassword) . "</code></p>"
                                  . "</div>"
                                  . "<p><strong>Important:</strong> For security reasons, please change your password immediately after logging in.</p>"
                                  . "<p>If you did not request this password reset, please contact the administrator immediately.</p>";
                            sendEmail($userInfo['email'], $userInfo['name'], 'Your Password Has Been Reset', $body);
                        } catch (Exception $e) {
                            // Log but don't fail the operation
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

                    $userStmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id');
                    $userStmt->execute(['id' => $userId]);
                    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$userInfo) {
                        $message = 'User not found.';
                        $messageType = 'error';
                        break;
                    }

                    if (($userInfo['role'] ?? '') === 'Admin') {
                        $adminCountStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Admin"');
                        $adminCount = (int) $adminCountStmt->fetchColumn();
                        if ($adminCount <= 1) {
                            $message = 'Cannot delete the only remaining administrator account.';
                            $messageType = 'error';
                            break;
                        }
                    }

                    $reservationCheck = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = :id');
                    $reservationCheck->execute(['id' => $userId]);
                    if ((int) $reservationCheck->fetchColumn() > 0) {
                        $message = 'Cannot delete an account with reservation history. Lock the account instead.';
                        $messageType = 'error';
                        break;
                    }

                    $adminName = $_SESSION['user_name'] ?? 'System Administrator';
                    if (!empty($userInfo['email'])) {
                        try {
                            $body = getAccountDeletedEmailTemplate($userInfo['name'], $deleteReason, $adminName);
                            sendEmail($userInfo['email'], $userInfo['name'], 'Your Account Has Been Removed', $body);
                        } catch (Exception $e) {
                            error_log('Failed to send account deletion email: ' . $e->getMessage());
                        }
                    }

                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE reservation_history SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
                        $pdo->prepare('UPDATE users SET verified_by = NULL WHERE verified_by = ?')->execute([$userId]);
                        $pdo->prepare('UPDATE facility_blackout_dates SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
                        $pdo->prepare('UPDATE contact_inquiries SET responded_by = NULL WHERE responded_by = ?')->execute([$userId]);
                        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $e;
                    }

                    logAudit(
                        'Deleted user account',
                        'User Management',
                        ($userInfo['name'] . ' (' . $userInfo['email'] . ') — Reason: ' . $deleteReason)
                    );
                    $message = 'Account deleted successfully. The user has been notified by email.';
                    break;
                    
                default:
                    $message = 'Invalid action.';
                    $messageType = 'error';
            }
        } catch (Throwable $e) {
            $message = 'Unable to process request. Please try again.';
            $messageType = 'error';
        }
    }
}

// Get filter parameters
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

// Build query with filters
$whereConditions = [];
$params = [];

if ($filterRole && $filterRole !== 'all') {
    $whereConditions[] = 'role = :role';
    $params['role'] = $filterRole;
}

if ($filterStatus && $filterStatus !== 'all') {
    $statusMap = [
        'active' => 'active',
        'pending' => 'pending',
        'locked' => 'locked',
        'email_unverified' => 'email_unverified',
    ];
    if ($filterStatus === 'email_unverified') {
        $whereConditions[] = '(email_verified = 0 OR email_verified IS NULL)';
    } elseif (isset($statusMap[$filterStatus])) {
        $whereConditions[] = 'status = :status';
        $params['status'] = $statusMap[$filterStatus];
    }
}

if ($searchQuery !== '') {
    $whereConditions[] = '(name LIKE :search_name OR email LIKE :search_email)';
    $params['search_name'] = '%' . $searchQuery . '%';
    $params['search_email'] = '%' . $searchQuery . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Pagination
$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Count total users
$countSql = 'SELECT COUNT(*) FROM users ' . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch users
$sql = 'SELECT id, name, email, role, status, is_verified, COALESCE(email_verified, 0) AS email_verified, verified_at, created_at, updated_at FROM users ' . $whereClause . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch documents for listed users (include document ID for secure URLs)
$docsByUser = [];
if (!empty($users)) {
    $ids = array_column($users, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $docStmt = $pdo->prepare("SELECT id, user_id, document_type, file_name, file_path FROM user_documents WHERE user_id IN ($placeholders)");
    $docStmt->execute($ids);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($docs as $doc) {
        $docsByUser[$doc['user_id']][] = $doc;
    }

    $violationCountsByUser = getViolationCountsForUserIds($ids);
    $violationsByUser = getViolationsGroupedForUserIds($ids);
} else {
    $violationCountsByUser = [];
    $violationsByUser = [];
}

// Summary stats
$pendingCountStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE status = "pending"');
$pendingCount = (int)$pendingCountStmt->fetchColumn();

$emailUnverifiedStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE email_verified = 0 OR email_verified IS NULL');
$emailUnverifiedCount = (int)$emailUnverifiedStmt->fetchColumn();

$lockedCountStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE status = "locked"');
$lockedCount = (int)$lockedCountStmt->fetchColumn();

$totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM users');
$totalUsersCount = (int)$totalUsersStmt->fetchColumn();

$activeStaffStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("Admin", "Staff") AND status = "active"');
$activeStaffCount = (int)$activeStaffStmt->fetchColumn();

$lastApprovalStmt = $pdo->query('SELECT MAX(updated_at) FROM users WHERE status = "active" AND updated_at != created_at');
$lastApproval = $lastApprovalStmt->fetchColumn();

$retentionHours = (int) (defined('UNVERIFIED_ACCOUNT_RETENTION_HOURS') ? UNVERIFIED_ACCOUNT_RETENTION_HOURS : 24);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Administration</span><span class="sep">/</span><span>User Management</span>
    </div>
    <?= frs_page_title('User Management', 'Manage resident accounts, roles, verification, and access.'); ?>
</div>

<?php if ($message): ?>
    <div class="um-alert um-alert-<?= $messageType === 'success' ? 'success' : 'error'; ?>" role="status">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="um-stats-grid">
    <div class="stat-card">
        <span class="um-stat-label">Total accounts</span>
        <strong class="um-stat-value"><?= $totalUsersCount; ?></strong>
    </div>
    <div class="stat-card">
        <span class="um-stat-label">Pending approval</span>
        <strong class="um-stat-value"><?= $pendingCount; ?></strong>
    </div>
    <div class="stat-card">
        <span class="um-stat-label">Email unverified</span>
        <strong class="um-stat-value"><?= $emailUnverifiedCount; ?></strong>
    </div>
    <div class="stat-card">
        <span class="um-stat-label">Locked</span>
        <strong class="um-stat-value"><?= $lockedCount; ?></strong>
    </div>
</div>

<div class="um-layout">
    <section class="booking-card um-main">
        <div class="um-section-head um-section-head-row">
            <div>
                <h2>Accounts Directory</h2>
                <p class="resource-meta">Search, filter, and manage user records. Unverified registrations are auto-removed after <?= $retentionHours; ?> hours.</p>
            </div>
            <button type="button" class="btn-primary js-open-create-user-modal">Create account</button>
        </div>

        <form method="GET" class="um-toolbar">
            <label class="um-search">
                <span class="sr-only">Search users</span>
                <input type="search" name="q" value="<?= htmlspecialchars($searchQuery); ?>" placeholder="Search name or email…">
            </label>
            <label>
                Role
                <select name="role">
                    <option value="all" <?= $filterRole === '' || $filterRole === 'all' ? 'selected' : ''; ?>>All roles</option>
                    <option value="Admin" <?= $filterRole === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="Staff" <?= $filterRole === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="Resident" <?= $filterRole === 'Resident' ? 'selected' : ''; ?>>Resident</option>
                </select>
            </label>
            <label>
                Status
                <select name="status">
                    <option value="all" <?= $filterStatus === '' || $filterStatus === 'all' ? 'selected' : ''; ?>>All statuses</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending approval</option>
                    <option value="locked" <?= $filterStatus === 'locked' ? 'selected' : ''; ?>>Locked</option>
                    <option value="email_unverified" <?= $filterStatus === 'email_unverified' ? 'selected' : ''; ?>>Email unverified</option>
                </select>
            </label>
            <button type="submit" class="btn-primary um-filter-btn">Apply</button>
        </form>

        <?php if (empty($users)): ?>
            <div class="um-empty">No users match your filters.</div>
        <?php else: ?>
            <div class="um-user-list">
                <?php foreach ($users as $user):
                    $isSelf = ((int)$user['id'] === $currentUserId);
                    $initial = strtoupper(substr((string)$user['name'], 0, 1));
                    $emailVerified = (bool)($user['email_verified'] ?? false);
                    $isIdVerified = (bool)($user['is_verified'] ?? false);
                    $hasValidIdDoc = isset($docsByUser[$user['id']]) &&
                        !empty(array_filter($docsByUser[$user['id']], static fn($d) => ($d['document_type'] ?? '') === 'valid_id'));
                    $statusClass = $user['status'] === 'active' ? 'active' : ($user['status'] === 'pending' ? 'pending' : 'locked');
                    $statusLabel = $user['status'] === 'pending' ? 'Pending approval' : ucfirst($user['status']);
                ?>
                <article class="um-user-card">
                    <div class="um-user-main">
                        <div class="um-avatar" aria-hidden="true"><?= htmlspecialchars($initial); ?></div>
                        <div class="um-user-info">
                            <div class="um-user-title">
                                <h3><?= htmlspecialchars($user['name']); ?></h3>
                                <?php if ($isSelf): ?><span class="um-badge um-badge-self">You</span><?php endif; ?>
                            </div>
                            <p class="um-email"><?= htmlspecialchars($user['email']); ?></p>
                            <div class="um-badges">
                                <span class="um-badge um-badge-role"><?= htmlspecialchars($user['role']); ?></span>
                                <span class="um-badge um-badge-<?= $statusClass; ?>"><?= htmlspecialchars($statusLabel); ?></span>
                                <?php if (!$emailVerified): ?>
                                    <span class="um-badge um-badge-warn">Email not verified</span>
                                <?php endif; ?>
                                <?php if ($isIdVerified): ?>
                                    <span class="um-badge um-badge-ok">ID verified</span>
                                <?php elseif ($hasValidIdDoc): ?>
                                    <span class="um-badge um-badge-warn">ID pending review</span>
                                <?php else: ?>
                                    <span class="um-badge um-badge-muted">No ID on file</span>
                                <?php endif; ?>
                            </div>
                            <p class="um-meta">Registered <?= date('M j, Y', strtotime($user['created_at'])); ?></p>
                            <?php
                            $uid = (int)$user['id'];
                            $vTotal = $violationCountsByUser[$uid]['total'] ?? 0;
                            $vHigh = $violationCountsByUser[$uid]['high_critical'] ?? 0;
                            $userViolations = $violationsByUser[$uid] ?? [];
                            ?>
                            <div class="um-violations">
                                <div class="um-violations-head">
                                    <?php if ($vTotal > 0): ?>
                                        <span class="um-badge um-badge-violation<?= $vHigh > 0 ? ' um-badge-violation-high' : ''; ?>">
                                            <?= $vTotal; ?> violation<?= $vTotal === 1 ? '' : 's'; ?>
                                        </span>
                                        <?php if ($vHigh > 0): ?>
                                            <span class="um-badge um-badge-warn"><?= $vHigh; ?> high/critical</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="um-badge um-badge-muted">No violations</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($vTotal > 0): ?>
                                    <details class="um-violations-details">
                                        <summary>View violation history</summary>
                                        <ul class="um-violations-list">
                                            <?php foreach ($userViolations as $v): ?>
                                                <li class="um-violation-item um-violation-sev-<?= htmlspecialchars($v['severity']); ?>">
                                                    <div class="um-violation-top">
                                                        <strong><?= htmlspecialchars(frs_violation_type_label($v['violation_type'])); ?></strong>
                                                        <span class="um-violation-severity"><?= htmlspecialchars(ucfirst($v['severity'])); ?></span>
                                                    </div>
                                                    <p class="um-violation-desc"><?= htmlspecialchars($v['description'] ?: 'No description provided.'); ?></p>
                                                    <p class="um-violation-meta">
                                                        <?= date('M j, Y g:i A', strtotime($v['created_at'])); ?>
                                                        <?php if (!empty($v['facility_name'])): ?>
                                                            · <?= htmlspecialchars($v['facility_name']); ?>
                                                            <?php if (!empty($v['reservation_date'])): ?>
                                                                (<?= date('M j, Y', strtotime($v['reservation_date'])); ?>)
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="um-user-side">
                        <?php if ($isPageAdmin): ?>
                        <form method="POST" class="role-change-form um-role-form">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="user_id" value="<?= (int)$user['id']; ?>">
                            <input type="hidden" name="action" value="change_role">
                            <label class="um-role-label">
                                Role
                                <select name="new_role" data-original-role="<?= htmlspecialchars($user['role']); ?>" data-user-name="<?= htmlspecialchars($user['name']); ?>" class="role-select" <?= $isSelf ? 'disabled' : ''; ?>>
                                    <option value="Admin" <?= $user['role'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="Staff" <?= $user['role'] === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                                    <option value="Resident" <?= $user['role'] === 'Resident' ? 'selected' : ''; ?>>Resident</option>
                                </select>
                            </label>
                        </form>
                        <?php else: ?>
                        <div class="um-role-readonly">
                            <span class="um-role-label">Role</span>
                            <span class="um-badge um-badge-role"><?= htmlspecialchars($user['role']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($docsByUser[$user['id']])): ?>
                            <div class="um-docs">
                                <?php
                                require_once __DIR__ . '/../../../../config/secure_documents.php';
                                foreach ($docsByUser[$user['id']] as $doc):
                                    $docId = $doc['id'] ?? null;
                                    if (!$docId) continue;
                                    $secureUrl = getSecureDocumentUrl($docId, 'view');
                                ?>
                                    <a href="<?= htmlspecialchars($secureUrl); ?>" target="_blank" rel="noopener" class="um-doc-link">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $doc['document_type']))); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="um-actions">
                            <?php if ($user['status'] === 'pending'): ?>
                                <form method="POST" class="um-inline-form">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn-primary um-btn-sm confirm-action" data-message="Approve this account?">Approve</button>
                                </form>
                                <form method="POST" class="um-inline-form">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id']; ?>">
                                    <input type="hidden" name="action" value="deny">
                                    <button type="submit" class="btn-outline um-btn-sm confirm-action" data-message="Remove this pending registration?">Deny</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isIdVerified && $hasValidIdDoc): ?>
                                <form method="POST" class="um-inline-form">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id']; ?>">
                                    <input type="hidden" name="action" value="verify">
                                    <button type="submit" class="btn-primary um-btn-sm confirm-action" data-message="Verify this user's ID and enable auto-approval features?">Verify ID</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($user['status'] === 'active' && !$isSelf): ?>
                                <button type="button" class="btn-outline um-btn-sm js-open-lock-modal" data-user-id="<?= (int)$user['id']; ?>" data-user-name="<?= htmlspecialchars($user['name'], ENT_QUOTES); ?>">Lock</button>
                            <?php elseif ($user['status'] === 'locked' && !$isSelf): ?>
                                <form method="POST" class="um-inline-form">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id']; ?>">
                                    <input type="hidden" name="action" value="unlock">
                                    <button type="submit" class="btn-primary um-btn-sm confirm-action" data-message="Unlock this account?">Unlock</button>
                                </form>
                            <?php endif; ?>

                            <?php if (in_array($user['status'], ['active', 'locked'], true) && !$isSelf): ?>
                                <form method="POST" class="um-inline-form">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id']; ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <button type="submit" class="btn-outline um-btn-sm um-btn-warn confirm-action" data-message="Reset password? New credentials will be emailed to the user.">Reset password</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($isPageAdmin && !$isSelf): ?>
                                <button type="button" class="btn-outline um-btn-sm um-btn-danger js-open-delete-modal" data-user-id="<?= (int)$user['id']; ?>" data-user-name="<?= htmlspecialchars($user['name'], ENT_QUOTES); ?>" data-user-email="<?= htmlspecialchars($user['email'], ENT_QUOTES); ?>">Delete account</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination um-pagination">
                    <?php
                    $pageQuery = http_build_query(array_filter([
                        'page' => max(1, $page - 1),
                        'role' => $filterRole !== 'all' ? $filterRole : null,
                        'status' => $filterStatus !== 'all' ? $filterStatus : null,
                        'q' => $searchQuery !== '' ? $searchQuery : null,
                    ]));
                    $nextQuery = http_build_query(array_filter([
                        'page' => min($totalPages, $page + 1),
                        'role' => $filterRole !== 'all' ? $filterRole : null,
                        'status' => $filterStatus !== 'all' ? $filterStatus : null,
                        'q' => $searchQuery !== '' ? $searchQuery : null,
                    ]));
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?<?= htmlspecialchars($pageQuery); ?>">&larr; Previous</a>
                    <?php endif; ?>
                    <span class="current">Page <?= $page; ?> of <?= $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= htmlspecialchars($nextQuery); ?>">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <aside class="booking-card um-aside">
        <h2>Approval queue</h2>
        <p class="resource-meta">Overview of registration and staff activity.</p>
        <ul class="audit-list um-aside-list">
            <li><strong><?= $pendingCount; ?></strong> <?= $pendingCount === 1 ? 'account' : 'accounts'; ?> awaiting approval.</li>
            <li><strong><?= $emailUnverifiedCount; ?></strong> with unverified email<?= $retentionHours ? ' (purged after ' . $retentionHours . 'h)' : ''; ?>.</li>
            <?php if ($lastApproval): ?>
                <li>Last approval: <?= date('M j, Y', strtotime($lastApproval)); ?>.</li>
            <?php else: ?>
                <li>No approvals processed yet.</li>
            <?php endif; ?>
            <li><strong><?= $activeStaffCount; ?></strong> active staff/admin <?= $activeStaffCount === 1 ? 'account' : 'accounts'; ?>.</li>
        </ul>
        <div class="um-policy-note">
            <strong>Deletion policy</strong>
            <p>Accounts with reservation history cannot be deleted — lock them instead. Deletion requires a reason and notifies the user by email.</p>
        </div>
    </aside>
</div>

<div id="createUserModal" class="um-modal" aria-hidden="true">
    <div class="um-modal-backdrop js-close-modal" data-target="createUserModal"></div>
    <div class="um-modal-panel um-modal-panel-wide" role="dialog" aria-labelledby="createUserModalTitle" aria-modal="true">
        <h3 id="createUserModalTitle">Create account</h3>
        <p class="um-modal-sub">Add a resident<?= $isPageAdmin ? ' or staff member' : ''; ?> with login credentials sent by email.</p>
        <form method="POST" class="um-create-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="create_user">
            <div class="um-create-grid">
                <label>
                    Full name <span class="um-required">*</span>
                    <input type="text" name="create_name" required minlength="2" maxlength="120" autocomplete="name" placeholder="Juan Dela Cruz">
                </label>
                <label>
                    Email <span class="um-required">*</span>
                    <input type="email" name="create_email" required maxlength="190" autocomplete="off" placeholder="name@example.com">
                </label>
                <label>
                    Mobile
                    <input type="tel" name="create_mobile" maxlength="20" autocomplete="off" placeholder="09XX XXX XXXX">
                </label>
                <?php if ($isPageAdmin): ?>
                <label>
                    Role <span class="um-required">*</span>
                    <select name="create_role" required>
                        <option value="Resident" selected>Resident</option>
                        <option value="Staff">Staff</option>
                    </select>
                </label>
                <?php else: ?>
                <input type="hidden" name="create_role" value="Resident">
                <?php endif; ?>
                <?php
                $streetFieldName = 'create_street';
                $houseFieldName = 'create_house_number';
                $selectedStreet = $_POST['create_street'] ?? '';
                $selectedHouseNumber = $_POST['create_house_number'] ?? '';
                $required = true;
                $showHint = true;
                $selectExtraAttrs = '';
                include __DIR__ . '/../../components/culiat_street_fields.php';
                ?>
                <label class="um-create-full">
                    Password <span class="um-hint">(leave blank to auto-generate)</span>
                    <input type="password" name="create_password" minlength="8" autocomplete="new-password" placeholder="Optional temporary password">
                </label>
            </div>
            <div class="um-create-options">
                <label class="um-check-label">
                    <input type="checkbox" name="create_email_verified" value="1" checked>
                    Mark email as verified (user can sign in immediately)
                </label>
                <label class="um-check-label">
                    <input type="checkbox" name="create_id_verified" value="1">
                    Mark ID as verified (enables auto-approval for residents)
                </label>
            </div>
            <div class="um-modal-actions">
                <button type="button" class="btn-outline js-close-modal" data-target="createUserModal">Cancel</button>
                <button type="submit" class="btn-primary">Create account</button>
            </div>
        </form>
    </div>
</div>

<div id="lockUserModal" class="um-modal" aria-hidden="true">
    <div class="um-modal-backdrop js-close-modal" data-target="lockUserModal"></div>
    <div class="um-modal-panel" role="dialog" aria-labelledby="lockModalTitle" aria-modal="true">
        <h3 id="lockModalTitle">Lock account</h3>
        <p class="um-modal-sub" id="lockModalUser"></p>
        <form method="POST" id="lockUserForm">
            <?= csrf_field(); ?>
            <input type="hidden" name="user_id" id="lockUserId" value="">
            <input type="hidden" name="action" value="lock">
            <label>
                Reason (optional)
                <textarea name="lock_reason" rows="3" placeholder="Explain why this account is being locked…"></textarea>
            </label>
            <div class="um-modal-actions">
                <button type="button" class="btn-outline js-close-modal" data-target="lockUserModal">Cancel</button>
                <button type="submit" class="btn-primary">Lock account</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteUserModal" class="um-modal" aria-hidden="true">
    <div class="um-modal-backdrop js-close-modal" data-target="deleteUserModal"></div>
    <div class="um-modal-panel um-modal-panel-danger" role="dialog" aria-labelledby="deleteModalTitle" aria-modal="true">
        <h3 id="deleteModalTitle">Delete account permanently</h3>
        <p class="um-modal-sub" id="deleteModalUser"></p>
        <p class="um-modal-warning">This cannot be undone. The user will receive an email with your reason before the account is removed.</p>
        <form method="POST" id="deleteUserForm">
            <?= csrf_field(); ?>
            <input type="hidden" name="user_id" id="deleteUserId" value="">
            <input type="hidden" name="action" value="delete">
            <label>
                Reason for deletion <span class="um-required">*</span>
                <textarea name="delete_reason" id="deleteReasonInput" rows="4" required minlength="10" maxlength="1000" placeholder="Provide a clear reason (minimum 10 characters). This will be included in the email to the user."></textarea>
            </label>
            <div class="um-modal-actions">
                <button type="button" class="btn-outline js-close-modal" data-target="deleteUserModal">Cancel</button>
                <button type="submit" class="btn-primary um-btn-danger-solid">Delete account</button>
            </div>
        </form>
    </div>
</div>

<style>
.um-alert { padding: 0.85rem 1rem; border-radius: 10px; margin-bottom: 1.25rem; font-size: 0.95rem; }
.um-alert-success { background: #e3f8ef; color: #0d7a43; border: 1px solid #b7ebd0; }
.um-alert-error { background: #fdecee; color: #b23030; border: 1px solid #f5c2c7; }
.um-stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.um-stat-label { display: block; font-size: 0.82rem; color: #64748b; margin-bottom: 0.35rem; }
.um-stat-value { font-size: 1.75rem; color: #1e293b; line-height: 1; }
.um-layout { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 1.25rem; align-items: start; }
.um-section-head { margin-bottom: 1rem; }
.um-section-head-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.um-role-readonly { display: flex; flex-direction: column; gap: 0.35rem; }
.um-modal-panel-wide { width: min(100%, 560px); max-height: min(90vh, 720px); overflow-y: auto; }
.um-create-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 0.5rem; }
.um-create-full { grid-column: 1 / -1; }
.um-create-options { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.85rem; }
.um-check-label { flex-direction: row !important; align-items: flex-start; gap: 0.5rem !important; font-size: 0.85rem !important; cursor: pointer; }
.um-check-label input { margin-top: 0.15rem; flex-shrink: 0; }
.um-hint { font-weight: 400; color: #94a3b8; font-size: 0.8rem; }
.um-modal-panel input[type="text"],
.um-modal-panel input[type="email"],
.um-modal-panel input[type="tel"],
.um-modal-panel input[type="password"],
.um-modal-panel select { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #d7deed; border-radius: 8px; font-family: inherit; font-size: 0.92rem; }
.um-toolbar { display: grid; grid-template-columns: 1.4fr 0.8fr 0.9fr auto; gap: 0.75rem; align-items: end; margin-bottom: 1.25rem; }
.um-toolbar label { display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.85rem; color: #475569; }
.um-toolbar select, .um-search input { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #d7deed; border-radius: 8px; background: #fff; }
.um-filter-btn { padding: 0.55rem 1rem; white-space: nowrap; }
.um-user-list { display: flex; flex-direction: column; gap: 0.85rem; }
.um-user-card { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(260px, 0.8fr); gap: 1rem; padding: 1rem 1.1rem; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
.um-user-main { display: flex; gap: 0.85rem; min-width: 0; }
.um-avatar { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #6384d2, #285ccd); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
.um-user-info { min-width: 0; }
.um-user-title { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.um-user-title h3 { margin: 0; font-size: 1.05rem; color: #0f172a; }
.um-email { margin: 0.15rem 0 0.5rem; color: #64748b; font-size: 0.9rem; word-break: break-word; }
.um-badges { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.45rem; }
.um-badge { display: inline-flex; align-items: center; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
.um-badge-role { background: #eef2ff; color: #3730a3; }
.um-badge-active { background: #dcfce7; color: #166534; }
.um-badge-pending { background: #fef3c7; color: #92400e; }
.um-badge-locked { background: #fee2e2; color: #991b1b; }
.um-badge-ok { background: #dcfce7; color: #166534; }
.um-badge-warn { background: #fef3c7; color: #92400e; }
.um-badge-muted { background: #f1f5f9; color: #64748b; }
.um-badge-self { background: #dbeafe; color: #1d4ed8; }
.um-meta { margin: 0; font-size: 0.8rem; color: #94a3b8; }
.um-violations { margin-top: 0.65rem; padding-top: 0.65rem; border-top: 1px dashed #e2e8f0; }
.um-violations-head { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
.um-badge-violation { background: #fff7ed; color: #c2410c; }
.um-badge-violation-high { background: #fee2e2; color: #991b1b; }
.um-violations-details { margin-top: 0.45rem; font-size: 0.82rem; color: #475569; }
.um-violations-details summary { cursor: pointer; color: #2563eb; font-weight: 600; user-select: none; }
.um-violations-list { list-style: none; margin: 0.5rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.um-violation-item { padding: 0.55rem 0.65rem; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; }
.um-violation-top { display: flex; justify-content: space-between; gap: 0.5rem; align-items: center; font-size: 0.82rem; }
.um-violation-severity { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; color: #64748b; }
.um-violation-sev-high, .um-violation-sev-critical { border-color: #fecaca; background: #fef2f2; }
.um-violation-sev-high .um-violation-severity, .um-violation-sev-critical .um-violation-severity { color: #b91c1c; }
.um-violation-desc { margin: 0.3rem 0 0; font-size: 0.8rem; color: #334155; line-height: 1.4; }
.um-violation-meta { margin: 0.25rem 0 0; font-size: 0.75rem; color: #94a3b8; }
.um-user-side { display: flex; flex-direction: column; gap: 0.65rem; border-left: 1px solid #eef2f7; padding-left: 1rem; }
.um-role-label { font-size: 0.82rem; color: #475569; display: flex; flex-direction: column; gap: 0.35rem; }
.um-role-form select { width: 100%; padding: 0.45rem 0.55rem; border-radius: 8px; border: 1px solid #d7deed; }
.um-docs { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.um-doc-link { font-size: 0.78rem; padding: 0.25rem 0.55rem; border-radius: 999px; background: #f8fafc; border: 1px solid #e2e8f0; color: #334155; text-decoration: none; }
.um-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.um-inline-form { display: inline; }
.um-btn-sm { padding: 0.38rem 0.7rem !important; font-size: 0.82rem !important; }
.um-btn-warn { border-color: #fb923c !important; color: #c2410c !important; }
.um-btn-danger { border-color: #f87171 !important; color: #b91c1c !important; }
.um-btn-danger-solid { background: #dc2626 !important; border-color: #dc2626 !important; }
.um-empty { padding: 2rem; text-align: center; color: #64748b; background: #f8fafc; border-radius: 10px; }
.um-pagination { margin-top: 1.25rem; }
.um-aside-list { margin-top: 0.75rem; }
.um-policy-note { margin-top: 1rem; padding: 0.85rem; border-radius: 10px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.85rem; color: #475569; }
.um-policy-note p { margin: 0.35rem 0 0; }
.um-field-hint { display: block; margin-top: 0.2rem; color: #94a3b8; font-size: 0.78rem; font-weight: 400; }
.um-modal { position: fixed; inset: 0; z-index: 10050; display: none; align-items: center; justify-content: center; padding: 1rem; }
.um-modal.open { display: flex; }
.um-modal-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.45); }
.um-modal-panel { position: relative; width: min(100%, 480px); max-height: min(90vh, 720px); overflow-y: auto; background: #fff; border-radius: 14px; padding: 1.25rem; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18); }
.um-modal-panel-danger { border-top: 4px solid #dc2626; }
.um-modal-panel h3 { margin: 0 0 0.35rem; color: #0f172a; }
.um-modal-sub { margin: 0 0 0.75rem; color: #64748b; font-size: 0.92rem; }
.um-modal-warning { margin: 0 0 0.85rem; padding: 0.65rem 0.75rem; border-radius: 8px; background: #fef2f2; color: #991b1b; font-size: 0.85rem; }
.um-modal-panel label { display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.88rem; color: #334155; }
.um-modal-panel textarea { width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #d7deed; border-radius: 8px; resize: vertical; min-height: 90px; font-family: inherit; }
.um-required { color: #dc2626; }
.um-modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; }
body.um-modal-open { overflow: hidden; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
@media (max-width: 1100px) {
    .um-layout { grid-template-columns: 1fr; }
    .um-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .um-user-card { grid-template-columns: 1fr; }
    .um-user-side { border-left: 0; padding-left: 0; border-top: 1px solid #eef2f7; padding-top: 0.85rem; }
}
@media (max-width: 720px) {
    .um-toolbar { grid-template-columns: 1fr; }
    .um-stats-grid { grid-template-columns: 1fr 1fr; }
    .um-create-grid { grid-template-columns: 1fr; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.um-modal').forEach(function(modalEl) {
        if (modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }
    });

    const modal = document.getElementById('confirmModal');
    if (modal) {
        const messageEl = modal.querySelector('.confirm-message');
        const cancelBtn = modal.querySelector('[data-confirm-cancel]');
        const acceptBtn = modal.querySelector('[data-confirm-accept]');
        let pendingSelect = null;

        document.querySelectorAll('.role-select').forEach(function(select) {
            if (select.disabled) return;
            select.addEventListener('change', function() {
                const newRole = this.value;
                const originalRole = this.dataset.originalRole;
                const userName = this.dataset.userName || 'this user';
                if (newRole === originalRole) {
                    this.value = originalRole;
                    return;
                }
                pendingSelect = this;
                messageEl.textContent = 'Change ' + userName + '\'s role from ' + originalRole + ' to ' + newRole + '?';
                modal.classList.add('open');
            });
        });

        if (cancelBtn && acceptBtn) {
            cancelBtn.addEventListener('click', function() {
                if (pendingSelect) {
                    pendingSelect.value = pendingSelect.dataset.originalRole;
                    pendingSelect = null;
                }
                modal.classList.remove('open');
            });
            acceptBtn.addEventListener('click', function() {
                if (pendingSelect) {
                    pendingSelect.closest('.role-change-form')?.submit();
                    pendingSelect = null;
                }
                modal.classList.remove('open');
            });
        }
    }

    function portalModal(el) {
        if (el && el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
    }

    function syncBodyScrollLock() {
        const hasOpen = document.querySelector('.um-modal.open');
        document.body.classList.toggle('um-modal-open', !!hasOpen);
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (el) {
            portalModal(el);
            el.classList.add('open');
            el.setAttribute('aria-hidden', 'false');
            syncBodyScrollLock();
        }
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) {
            el.classList.remove('open');
            el.setAttribute('aria-hidden', 'true');
            syncBodyScrollLock();
        }
    }

    document.querySelectorAll('.js-close-modal').forEach(function(btn) {
        btn.addEventListener('click', function() {
            closeModal(this.dataset.target);
        });
    });

    document.querySelectorAll('.js-open-create-user-modal').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal('createUserModal');
        });
    });

    const createRoleSelect = document.querySelector('#createUserModal [name="create_role"]');
    const createStreetSelect = document.querySelector('#createUserModal [name="create_street"]');
    const createHouseInput = document.querySelector('#createUserModal [name="create_house_number"]');
    function syncCreateAddressRequired() {
        if (!createStreetSelect || !createHouseInput) return;
        const isResident = !createRoleSelect || createRoleSelect.value === 'Resident';
        createStreetSelect.required = isResident;
        createHouseInput.required = isResident;
    }
    if (createRoleSelect) {
        createRoleSelect.addEventListener('change', syncCreateAddressRequired);
    }
    syncCreateAddressRequired();

    document.querySelectorAll('.js-open-lock-modal').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('lockUserId').value = this.dataset.userId;
            document.getElementById('lockModalUser').textContent = 'Lock account for ' + this.dataset.userName + '?';
            openModal('lockUserModal');
        });
    });

    document.querySelectorAll('.js-open-delete-modal').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('deleteUserId').value = this.dataset.userId;
            document.getElementById('deleteReasonInput').value = '';
            document.getElementById('deleteModalUser').textContent =
                'Delete ' + this.dataset.userName + ' (' + this.dataset.userEmail + ')?';
            openModal('deleteUserModal');
        });
    });

    document.getElementById('deleteUserForm')?.addEventListener('submit', function(e) {
        const reason = document.getElementById('deleteReasonInput')?.value.trim() || '';
        if (reason.length < 10) {
            e.preventDefault();
            alert('Please enter a deletion reason of at least 10 characters.');
            return;
        }
        if (!confirm('Permanently delete this account? The user will be notified by email.')) {
            e.preventDefault();
        }
    });
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
