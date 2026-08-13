<?php
$useTailwind = true;
$authSplitLayout = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';

// This page only makes sense mid-session: user has a valid dashboard session
// (password + any required 2FA already verified) but is flagged to set a new
// password before doing anything else.
if (!frs_dashboard_is_authenticated()) {
    header('Location: ' . base_path() . '/login');
    exit;
}
if (empty($_SESSION['must_change_password'])) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pageTitle = 'Change Your Password | LGU Facilities Reservation';
$error = '';
$passwordError = '';
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($password === '' || $confirmPassword === '') {
            $passwordError = 'Please enter and confirm your new password.';
        } elseif ($password !== $confirmPassword) {
            $passwordError = 'Passwords do not match.';
        } else {
            $passwordErrors = validatePassword($password);
            if (!empty($passwordErrors)) {
                $passwordError = implode(' ', $passwordErrors);
            } else {
                $pdo = db();
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute(['password_hash' => $passwordHash, 'id' => $userId]);

                unset($_SESSION['must_change_password']);

                if (function_exists('logSecurityEvent')) {
                    logSecurityEvent('password_changed', "User completed forced password change: user_id=$userId", 'info');
                }

                header('Location: ' . base_path() . '/dashboard');
                exit;
            }
        }
    }
}

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🔑</div>
            <?= frs_heading_with_tip('Set a New Password', 'Your password was reset by an administrator. Choose a new password to continue.', 'h1'); ?>
        </div>

        <?php if ($error): ?>
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($passwordError): ?>
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($passwordError); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <?= csrf_field(); ?>

            <label>
                New Password
                <div class="input-wrapper">
                    <input name="password" type="password" placeholder="Enter new password" required minlength="8" autofocus>
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Must be at least 8 characters with uppercase, lowercase, and number.
                </small>
            </label>

            <label>
                Confirm Password
                <div class="input-wrapper">
                    <input name="confirm_password" type="password" placeholder="Confirm new password" required minlength="8">
                </div>
            </label>

            <button class="btn-primary" type="submit">Set New Password</button>
        </form>

        <div class="auth-footer">
            <a href="<?= base_path(); ?>/logout">Log out instead</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
