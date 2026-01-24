<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

// RBAC: User Management is Admin-only (create/deactivate Admin/Staff, system governance)
if (!($_SESSION['user_authenticated'] ?? false) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
$pdo = db();
$pageTitle = 'User Management | LGU Facilities Reservation';

$message = '';
$messageType = 'success';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action'])) {
    $userId = (int)$_POST['user_id'];
    $action = $_POST['action'];
    $currentUserId = $_SESSION['user_id'] ?? null;
    
    // Prevent self-modification for certain actions
    if ($userId === $currentUserId && in_array($action, ['lock', 'unlock'], true)) {
        $message = 'You cannot lock or unlock your own account.';
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
                                base_path() . '/resources/views/pages/dashboard/profile.php'
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
                        $userStmt = $pdo->prepare('SELECT name, email, role FROM users WHERE id = :id');
                        $userStmt->execute(['id' => $userId]);
                        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
                        $oldRole = $userInfo['role'] ?? 'Unknown';
                        
                        $stmt = $pdo->prepare('UPDATE users SET role = :role, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                        $stmt->execute(['role' => $newRole, 'id' => $userId]);
                        
                        logAudit('Changed user role', 'User Management', 
                            ($userInfo ? ($userInfo['name'] . ' (' . $userInfo['email'] . ')') : 'User ID: ' . $userId) . 
                            ' - Changed from ' . $oldRole . ' to ' . $newRole);
                        $message = 'User role updated successfully.';
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

// Build query with filters
$whereConditions = [];
$params = [];

if ($filterRole && $filterRole !== 'all') {
    $whereConditions[] = 'role = :role';
    $params['role'] = $filterRole;
}

if ($filterStatus && $filterStatus !== 'all') {
    // Map UI status to DB status
    $statusMap = [
        'active' => 'active',
        'pending' => 'pending',
        'locked' => 'locked'
    ];
    if (isset($statusMap[$filterStatus])) {
        $whereConditions[] = 'status = :status';
        $params['status'] = $statusMap[$filterStatus];
    }
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Pagination
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Count total users
$countSql = 'SELECT COUNT(*) FROM users ' . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch users
$sql = 'SELECT id, name, email, role, status, is_verified, verified_at, created_at, updated_at FROM users ' . $whereClause . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
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
}

// Get approval queue stats
$pendingCountStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE status = "pending"');
$pendingCount = (int)$pendingCountStmt->fetchColumn();

$activeStaffStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("Admin", "Staff") AND status = "active"');
$activeStaffCount = (int)$activeStaffStmt->fetchColumn();

$lastApprovalStmt = $pdo->query('SELECT MAX(updated_at) FROM users WHERE status = "active" AND updated_at != created_at');
$lastApproval = $lastApprovalStmt->fetchColumn();

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Administration</span><span class="sep">/</span><span>User Management</span>
    </div>
    <h1>User Management</h1>
    <small>Manage accounts, roles, and approvals for residents and LGU staff.</small>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Accounts Directory</h2>
        <div class="section-header" style="margin-bottom: 1rem;">
            <p>Filter by role and status to quickly locate user records.</p>
        </div>
        <form method="GET" class="booking-form" style="margin-bottom: 1rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <label>
                Role
                <select name="role" onchange="this.form.submit()">
                    <option value="all" <?= $filterRole === '' || $filterRole === 'all' ? 'selected' : ''; ?>>All Roles</option>
                    <option value="Admin" <?= $filterRole === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="Staff" <?= $filterRole === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="Resident" <?= $filterRole === 'Resident' ? 'selected' : ''; ?>>Resident</option>
                </select>
            </label>
            <label>
                Status
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $filterStatus === '' || $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="locked" <?= $filterStatus === 'locked' ? 'selected' : ''; ?>>Locked</option>
                </select>
            </label>
        </form>
        
        <?php if (empty($users)): ?>
            <p>No users found matching the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Verification</th>
                    <th>Documents</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['name']); ?></td>
                        <td><?= htmlspecialchars($user['email']); ?></td>
                        <td>
                            <?php if ($_SESSION['role'] === 'Admin'): ?>
                                <form method="POST" style="display:inline;" class="role-change-form">
                                    <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                    <input type="hidden" name="action" value="change_role">
                                    <select name="new_role" data-original-role="<?= htmlspecialchars($user['role']); ?>" data-user-name="<?= htmlspecialchars($user['name']); ?>" class="role-select" style="border:1px solid #dfe3ef; border-radius:6px; padding:0.25rem 0.5rem;">
                                        <option value="Admin" <?= $user['role'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                        <option value="Staff" <?= $user['role'] === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                                        <option value="Resident" <?= $user['role'] === 'Resident' ? 'selected' : ''; ?>>Resident</option>
                                    </select>
                                </form>
                            <?php else: ?>
                                <span class="status-badge <?= strtolower($user['role']); ?>"><?= htmlspecialchars($user['role']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusDisplay = ucfirst($user['status']);
                            if ($user['status'] === 'pending') {
                                $statusDisplay = 'Pending Approval';
                            }
                            $statusClass = $user['status'] === 'active' ? 'active' : ($user['status'] === 'pending' ? 'maintenance' : 'offline');
                            ?>
                            <span class="status-badge <?= $statusClass; ?>"><?= $statusDisplay; ?></span>
                        </td>
                        <td>
                            <?php
                            $isVerified = (bool)($user['is_verified'] ?? false);
                            $hasValidIdDoc = isset($docsByUser[$user['id']]) && 
                                !empty(array_filter($docsByUser[$user['id']], fn($d) => ($d['document_type'] ?? '') === 'valid_id'));
                            
                            if ($isVerified):
                            ?>
                                <span class="status-badge active" style="background:#28a745;">✓ Verified</span>
                            <?php elseif ($hasValidIdDoc): ?>
                                <span class="status-badge maintenance" style="background:#ffc107; color:#856404;">Pending Verification</span>
                                <form method="POST" style="display:inline; margin-left:0.5rem;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                    <input type="hidden" name="action" value="verify">
                                    <button type="submit" class="btn-primary" style="padding:0.25rem 0.5rem; font-size:0.8rem;" onclick="return confirm('Verify this user\\'s account? This will enable auto-approval features.');">Verify</button>
                                </form>
                            <?php else: ?>
                                <span class="status-badge offline" style="background:#6c757d;">Not Verified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($docsByUser[$user['id']])): ?>
                                <div style="display:flex; flex-direction:column; gap:0.25rem;">
                                    <?php 
                                    require_once __DIR__ . '/../../../../config/secure_documents.php';
                                    foreach ($docsByUser[$user['id']] as $doc): 
                                        $docId = $doc['id'] ?? null;
                                        if ($docId):
                                            $secureUrl = getSecureDocumentUrl($docId, 'view');
                                    ?>
                                        <a href="<?= htmlspecialchars($secureUrl); ?>" target="_blank" rel="noopener" class="btn-outline" style="padding:0.35rem 0.5rem; font-size:0.85rem;">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $doc['document_type']))); ?>
                                        </a>
                                    <?php 
                                        else:
                                            // Fallback for documents without ID (shouldn't happen, but safety)
                                    ?>
                                        <span class="status-badge maintenance"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $doc['document_type']))); ?></span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <span class="status-badge maintenance">No docs</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <?php if ($user['status'] === 'pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button class="btn-primary confirm-action" data-message="Approve this user account?" type="submit" style="padding:0.4rem 0.75rem; font-size:0.9rem;">Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                        <input type="hidden" name="action" value="deny">
                                        <button class="btn-outline confirm-action" data-message="Remove this pending user account?" type="submit" style="padding:0.4rem 0.75rem; font-size:0.9rem;">Deny</button>
                                    </form>
                                <?php elseif ($user['status'] === 'active'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                        <input type="hidden" name="action" value="lock">
                                        <input type="text" name="lock_reason" placeholder="Reason (optional)" style="width:180px; padding:0.35rem 0.5rem; border:1px solid #d7deed; border-radius:8px; font-size:0.85rem; margin-right:0.35rem;">
                                        <button class="btn-outline confirm-action" data-message="Lock this user account?" type="submit" style="padding:0.4rem 0.75rem; font-size:0.9rem;">Lock</button>
                                    </form>
                                <?php elseif ($user['status'] === 'locked'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                        <input type="hidden" name="action" value="unlock">
                                        <button class="btn-primary confirm-action" data-message="Unlock this user account?" type="submit" style="padding:0.4rem 0.75rem; font-size:0.9rem;">Unlock</button>
                                    </form>
                                <?php endif; ?>
                                <!-- Password Reset Button (available for all active/locked users) -->
                                <?php if (in_array($user['status'], ['active', 'locked'])): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <button class="btn-outline confirm-action" data-message="Reset password for this user? New credentials will be sent to their email." type="submit" style="padding:0.4rem 0.75rem; font-size:0.9rem; background:#ff9800; color:#fff; border-color:#ff9800;">Reset Password</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top:1rem;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1; ?>&role=<?= htmlspecialchars($filterRole); ?>&status=<?= htmlspecialchars($filterStatus); ?>">&larr; Prev</a>
                    <?php endif; ?>
                    <span class="current">Page <?= $page; ?> of <?= $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1; ?>&role=<?= htmlspecialchars($filterRole); ?>&status=<?= htmlspecialchars($filterStatus); ?>">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <aside class="booking-card">
        <h2>Account Approval Queue</h2>
        <p class="resource-meta">Used by Admin/Staff to validate new resident registrations before granting access.</p>
        <ul class="audit-list">
            <li><strong><?= $pendingCount; ?></strong> <?= $pendingCount === 1 ? 'account' : 'accounts'; ?> awaiting validation.</li>
            <?php if ($lastApproval): ?>
                <li>Last approval processed on <?= date('M d, Y', strtotime($lastApproval)); ?>.</li>
            <?php else: ?>
                <li>No approvals processed yet.</li>
            <?php endif; ?>
            <li><strong><?= $activeStaffCount; ?></strong> staff account<?= $activeStaffCount !== 1 ? 's' : ''; ?> currently active and in good standing.</li>
        </ul>
    </aside>
</div>
<script>
// Role change dropdown confirmation using the modal system
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('confirmModal');
    if (!modal) return;
    
    const messageEl = modal.querySelector('.confirm-message');
    const cancelBtn = modal.querySelector('[data-confirm-cancel]');
    const acceptBtn = modal.querySelector('[data-confirm-accept]');
    let pendingSelect = null;
    
    document.querySelectorAll('.role-select').forEach(function(select) {
        select.addEventListener('change', function(e) {
            const newRole = this.value;
            const originalRole = this.dataset.originalRole;
            const userName = this.dataset.userName || 'this user';
            
            // If role hasn't actually changed, do nothing
            if (newRole === originalRole) {
                // Reset to original value
                this.value = originalRole;
                return;
            }
            
            e.preventDefault();
            pendingSelect = this;
            messageEl.textContent = 'Change ' + userName + '\'s role from ' + originalRole + ' to ' + newRole + '?';
            modal.classList.add('open');
        });
    });
    
    if (cancelBtn && acceptBtn) {
        cancelBtn.addEventListener('click', function() {
            if (pendingSelect) {
                // Reset select to original value
                pendingSelect.value = pendingSelect.dataset.originalRole;
                pendingSelect = null;
            }
            modal.classList.remove('open');
        });
        
        acceptBtn.addEventListener('click', function() {
            if (pendingSelect) {
                const form = pendingSelect.closest('.role-change-form');
                if (form) {
                    form.submit();
                }
                pendingSelect = null;
            }
            modal.classList.remove('open');
        });
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
