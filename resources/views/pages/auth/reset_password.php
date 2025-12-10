<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';

$pageTitle = 'Reset Password | LGU Facilities Reservation';
$error = '';
$success = false;
$token = $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($token)) {
            $error = 'Invalid reset token.';
        } elseif (empty($password) || strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $pdo = db();
                $tokenHash = hash('sha256', $token);
                
                // Find valid token
                $stmt = $pdo->prepare(
                    "SELECT prt.user_id, prt.expires_at, prt.used_at 
                     FROM password_reset_tokens prt
                     JOIN users u ON prt.user_id = u.id
                     WHERE prt.token_hash = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL AND u.status = 'active'"
                );
                $stmt->execute([$tokenHash]);
                $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$tokenData) {
                    $error = 'Invalid or expired reset token. Please request a new password reset.';
                } else {
                    // Validate password strength
                    $passwordErrors = validatePasswordStrength($password);
                    if (!empty($passwordErrors)) {
                        $error = implode(' ', $passwordErrors);
                    } else {
                        // Update password
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
                            ->execute([$passwordHash, $tokenData['user_id']]);
                        
                        // Mark token as used
                        $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = ?")
                            ->execute([$tokenHash]);
                        
                        $success = true;
                    }
                }
            } catch (Exception $e) {
                error_log('Password reset error: ' . $e->getMessage());
                $error = 'An error occurred. Please try again.';
            }
        }
    }
} elseif (!empty($token)) {
    // Validate token on page load
    try {
        $pdo = db();
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            "SELECT prt.expires_at, prt.used_at 
             FROM password_reset_tokens prt
             JOIN users u ON prt.user_id = u.id
             WHERE prt.token_hash = ? AND u.status = 'active'"
        );
        $stmt->execute([$tokenHash]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            $error = 'Invalid reset token.';
        } elseif ($tokenData['used_at']) {
            $error = 'This reset link has already been used. Please request a new one.';
        } elseif (strtotime($tokenData['expires_at']) < time()) {
            $error = 'This reset link has expired. Please request a new one.';
        }
    } catch (Exception $e) {
        $error = 'An error occurred. Please try again.';
    }
} else {
    $error = 'No reset token provided.';
}

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🔑</div>
            <h1><?= $success ? 'Password Reset Successful' : 'Reset Password'; ?></h1>
            <p><?= $success ? 'Your password has been reset successfully.' : 'Enter your new password below'; ?></p>
        </div>
        
        <?php if ($success): ?>
            <div style="background: #e3f8ef; color: #0d7a43; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                Your password has been successfully reset. You can now log in with your new password.
            </div>
            <div class="auth-footer">
                <a href="<?= base_path(); ?>/resources/views/pages/auth/login.php" class="btn-primary" style="display: block; text-align: center;">Go to Login</a>
            </div>
        <?php elseif ($error): ?>
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($error); ?>
            </div>
            <div class="auth-footer">
                <a href="<?= base_path(); ?>/resources/views/pages/auth/forgot_password.php">Request New Reset Link</a>
            </div>
        <?php else: ?>
            <form method="POST" class="auth-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                
                <label>
                    New Password
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input name="password" type="password" placeholder="Enter new password (min. 8 characters)" required minlength="8" autofocus>
                    </div>
                    <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                        Must be at least 8 characters with uppercase, lowercase, and number.
                    </small>
                </label>
                
                <label>
                    Confirm Password
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input name="confirm_password" type="password" placeholder="Confirm new password" required minlength="8">
                    </div>
                </label>
                
                <button class="btn-primary" type="submit">Reset Password</button>
            </form>
            
            <div class="auth-footer">
                <a href="<?= base_path(); ?>/resources/views/pages/auth/login.php">← Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


