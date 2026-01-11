<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';

$pageTitle = 'Reset Password | LGU Facilities Reservation';
$error = '';
$passwordError = ''; // Separate error for password validation (doesn't invalidate token)
$success = false;
$tokenValid = false; // Track if token is valid
// Get token from URL - PHP automatically decodes URL parameters, but ensure we get raw value
$rawToken = isset($_GET['token']) ? $_GET['token'] : '';
// Try to get from REQUEST_URI if not in GET
if (empty($rawToken) && isset($_SERVER['REQUEST_URI'])) {
    $parts = parse_url($_SERVER['REQUEST_URI']);
    if (isset($parts['query'])) {
        parse_str($parts['query'], $queryParams);
        $rawToken = $queryParams['token'] ?? '';
    }
}
$token = trim($rawToken);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh the page.';
        } else {
            $token = isset($_POST['token']) ? trim($_POST['token']) : '';
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($token)) {
                $error = 'Invalid reset token.';
            } else {
                try {
                    $pdo = db();
                    
                    // Clean and hash the token
                    $token = trim($token);
                    $tokenHash = hash('sha256', $token);
                    
                    // Find valid token - use database NOW() for timezone-safe comparison
                    $stmt = $pdo->prepare(
                        "SELECT prt.user_id, prt.expires_at, prt.used_at 
                         FROM password_reset_tokens prt
                         JOIN users u ON prt.user_id = u.id
                         WHERE prt.token_hash = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL AND u.status = 'active'"
                    );
                    $stmt->execute([$tokenHash]);
                    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$tokenData) {
                        // Check if token exists but is expired or used - use database NOW() for expiration check
                        $checkStmt = $pdo->prepare(
                            "SELECT prt.expires_at, prt.used_at, 
                                    (prt.expires_at > NOW()) as is_not_expired
                             FROM password_reset_tokens prt
                             JOIN users u ON prt.user_id = u.id
                             WHERE prt.token_hash = ? AND u.status = 'active'"
                        );
                        $checkStmt->execute([$tokenHash]);
                        $checkData = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$checkData) {
                            $error = 'Invalid reset token. Please request a new password reset.';
                        } elseif ($checkData['used_at']) {
                            $error = 'This reset link has already been used. Please request a new one.';
                        } elseif (!$checkData['is_not_expired']) {
                            $error = 'This reset link has expired. Please request a new one.';
                        } else {
                            $error = 'Invalid or expired reset token. Please request a new password reset.';
                        }
                    } else {
                        // Token is valid - now validate password
                        $tokenValid = true;
                        
                        if (empty($password) || strlen($password) < 8) {
                            $passwordError = 'Password must be at least 8 characters long.';
                        } elseif ($password !== $confirmPassword) {
                            $passwordError = 'Passwords do not match.';
                        } else {
                            // Validate password strength
                            $passwordErrors = validatePassword($password);
                            if (!empty($passwordErrors)) {
                                $passwordError = implode(' ', $passwordErrors);
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
        
        // Clean the token - remove any whitespace
        $token = trim($token);
        
        // Ensure token is 64 characters (hex string from bin2hex(random_bytes(32)))
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            $error = 'Invalid token format. Please request a new password reset.';
        } else {
            // Generate hash from the token
            $tokenHash = hash('sha256', $token);
            
            // Debug: Log the received token and generated hash
            error_log('Password reset validation - Token received (first 20): ' . substr($token, 0, 20) . '...');
            error_log('Password reset validation - Hash generated (first 20): ' . substr($tokenHash, 0, 20) . '...');
            
            // Debug: Check all recent tokens in database to see what's there
            $allTokensStmt = $pdo->query("SELECT id, user_id, LEFT(token_hash, 20) as hash_prefix, expires_at, used_at, created_at FROM password_reset_tokens ORDER BY id DESC LIMIT 5");
            $allTokens = $allTokensStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log('Password reset: Looking for hash starting with: ' . substr($tokenHash, 0, 20));
            error_log('Password reset: Recent tokens in DB: ' . json_encode($allTokens));
            
            // Check if token exists - use database NOW() for timezone-safe expiration check
            $checkStmt = $pdo->prepare(
                "SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, prt.token_hash as db_hash, 
                        COALESCE(u.status, '') as user_status,
                        (prt.expires_at > NOW()) as is_not_expired
                 FROM password_reset_tokens prt
                 LEFT JOIN users u ON prt.user_id = u.id
                 WHERE prt.token_hash = ?"
            );
            $checkStmt->execute([$tokenHash]);
            $checkData = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$checkData) {
                error_log('Password reset: Token hash not found in database. Hash: ' . substr($tokenHash, 0, 20) . '...');
                $error = 'Invalid reset token. Please request a new password reset link.';
            } else {
                // Token found - validate it
                error_log('Password reset: Token found. User ID: ' . $checkData['user_id'] . ', User Status: "' . $checkData['user_status'] . '", Expires: ' . $checkData['expires_at'] . ', Used: ' . ($checkData['used_at'] ?? 'NULL') . ', Is Not Expired (DB): ' . ($checkData['is_not_expired'] ?? 'NULL'));
                
                if (strtolower($checkData['user_status']) !== 'active') {
                    $error = 'Your account is not active. Please contact support.';
                } elseif ($checkData['used_at']) {
                    $error = 'This reset link has already been used. Please request a new one.';
                } elseif (!$checkData['is_not_expired']) {
                    $error = 'This reset link has expired. Please request a new one.';
                } else {
                    // Token is valid - clear any error and set flag
                    $error = '';
                    $tokenValid = true;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Password reset validation error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
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
        <?php elseif ($error && !$tokenValid): ?>
            <!-- Token validation error - show error page -->
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($error); ?>
                <?php if (!empty($token)): ?>
                    <?php 
                    $debugHash = hash('sha256', $token);
                    try {
                        $pdo = db();
                        // Get recent tokens for comparison
                        $recentStmt = $pdo->query("SELECT id, LEFT(token_hash, 20) as hash_prefix, expires_at, used_at, created_at FROM password_reset_tokens ORDER BY id DESC LIMIT 3");
                        $recentTokens = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $recentTokens = [];
                    }
                    ?>
                    <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid rgba(178, 48, 48, 0.2); font-size: 0.8rem; color: #8b5a5a;">
                        <strong>Debug Info:</strong><br>
                        Token: <?= substr($token, 0, 20); ?>...<?= substr($token, -10); ?><br>
                        Token Length: <?= strlen($token); ?> characters<br>
                        Token Format: <?= ctype_xdigit($token) ? 'Valid hex' : 'Invalid'; ?><br>
                        Token Hash (first 20): <?= substr($debugHash, 0, 20); ?>...<br>
                        <?php if (!empty($recentTokens)): ?>
                            <br><strong>Recent tokens in database:</strong><br>
                            <?php foreach ($recentTokens as $rt): ?>
                                Hash: <?= htmlspecialchars($rt['hash_prefix']); ?>... | Created: <?= $rt['created_at']; ?><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="auth-footer">
                <a href="<?= base_path(); ?>/resources/views/pages/auth/forgot_password.php">Request New Reset Link</a>
            </div>
        <?php else: ?>
            <!-- Show form (either no error, or password validation error with valid token) -->
            <?php if ($passwordError): ?>
                <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                    <?= htmlspecialchars($passwordError); ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="auth-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                
                <label>
                    New Password
                    <div class="input-wrapper">
                        <input name="password" type="password" placeholder="Enter new password (min. 8 characters)" required minlength="8" autofocus>
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





