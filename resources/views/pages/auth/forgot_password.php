<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/captcha.php';

// Ensure base_url() function exists (fallback if not loaded from app.php)
if (!function_exists('base_url')) {
    function base_url(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = base_path();
        return $protocol . '://' . $host . $base;
    }
}

$pageTitle = 'Forgot Password | LGU Facilities Reservation';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh the page.';
        $messageType = 'error';
    } else {
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $captcha = frs_verify_turnstile($_POST['cf-turnstile-response'] ?? null, (string)$clientIp);
        if (!$captcha['ok']) {
            $message = $captcha['error'];
            $messageType = 'error';
        } else
        if (!checkRateLimit('forgot_password_ip', (string)$clientIp, 3, 600)) {
            $message = 'Too many password reset requests. Please try again in 10 minutes.';
            $messageType = 'error';
        } else {
        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } else {
            if (!checkRateLimit('forgot_password_email', strtolower($email), 2, 600)) {
                $message = 'Too many reset attempts for this email. Please try again later.';
                $messageType = 'error';
            } else {
            try {
                $pdo = db();
                
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND status = 'active'");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Always show success message (security best practice - don't reveal if email exists)
                if ($user) {
                    // Generate reset token
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    
                    // Debug: Log token info
                    error_log('Password reset token generated - Length: ' . strlen($token) . ', First 10 chars: ' . substr($token, 0, 10));
                    error_log('Token hash generated - First 20 chars: ' . substr($tokenHash, 0, 20));
                    
                    // Delete old tokens for this user
                    $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$user['id']]);
                    
                    // Save new token - use database function for timezone-safe expiration
                    $tokenStmt = $pdo->prepare(
                        "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
                    );
                    $result = $tokenStmt->execute([$user['id'], $tokenHash]);
                    
                    if (!$result) {
                        error_log('ERROR: Failed to insert password reset token for user ' . $user['id']);
                        throw new Exception('Failed to save reset token');
                    }
                    
                    // Verify token was saved correctly
                    $verifyStmt = $pdo->prepare("SELECT id, token_hash, expires_at FROM password_reset_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                    $verifyStmt->execute([$user['id']]);
                    $savedToken = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$savedToken) {
                        error_log('ERROR: Token was NOT saved to database after insert!');
                        throw new Exception('Token verification failed');
                    }
                    
                    // Double-check the hash matches
                    if ($savedToken['token_hash'] !== $tokenHash) {
                        error_log('ERROR: Token hash mismatch! Generated: ' . substr($tokenHash, 0, 20) . '... Saved: ' . substr($savedToken['token_hash'], 0, 20) . '...');
                        throw new Exception('Token hash verification failed');
                    }
                    
                    // Log the token and hash for debugging
                    error_log('Password reset token created - Token (first 20): ' . substr($token, 0, 20) . '... | Hash (first 20): ' . substr($tokenHash, 0, 20) . '...');
                    
                    // Send reset email - token is hex (URL-safe)
                    $resetUrl = base_url() . '/reset-password?token=' . $token;
                    error_log('Reset URL (first 100 chars): ' . substr($resetUrl, 0, 100) . '...');
                    
                    require_once __DIR__ . '/../../../../config/email_templates.php';
                    $htmlBody = getPasswordResetEmailTemplate($user['name'], $resetUrl);
                    sendEmail($user['email'], $user['name'], 'Password Reset Request', $htmlBody);
                }
                
                // Always show success (security best practice)
                $message = 'If an account with that email exists, a password reset link has been sent. Please check your email.';
                $messageType = 'success';
                
            } catch (Exception $e) {
                error_log('Password reset error: ' . $e->getMessage());
                $message = 'An error occurred. Please try again later.';
                $messageType = 'error';
            }
            }
        }
        }
    }
}

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🔒</div>
            <?= frs_heading_with_tip('Forgot Password', 'Enter the email on your account. If it exists, we send a reset link (check spam). The link expires after a short time.', 'h1'); ?>
        </div>
        
        <?php if ($message): ?>
            <div style="background: <?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>; color: <?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="auth-form">
            <?= csrf_field(); ?>
            <?php if (frs_captcha_enabled() && frs_turnstile_site_key() !== ''): ?>
                <div style="margin: 0.75rem 0 0.25rem;">
                    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(frs_turnstile_site_key(), ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>
            <?php endif; ?>
            <label>
                Email Address
                <div class="input-wrapper">
                    <input name="email" type="email" placeholder="your@email.com" required autofocus>
                </div>
            </label>
            
            <button class="btn-primary" type="submit">Send Reset Link</button>
        </form>
        
        <div class="auth-footer">
            <a href="<?= base_path(); ?>/login">← Back to Login</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';





