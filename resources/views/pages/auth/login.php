<?php
$useTailwind = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/captcha.php';

$pageTitle = 'Login | LGU Facilities Reservation';
$error = '';
$lockNotice = '';
$next = '';
$clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$loginCaptchaRequired = frs_login_requires_captcha($_POST['email'] ?? null, (string)$clientIp);

// Capture redirect target (same-origin relative paths only)
if (isset($_GET['next'])) {
    $safeNext = frs_safe_redirect_path((string)$_GET['next']);
    if ($safeNext !== null) {
        $next = $safeNext;
        $_SESSION['post_login_redirect'] = $safeNext;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
        logSecurityEvent('csrf_validation_failed', 'Login form', 'warning');
    } else {
        $clientIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $emailInput = sanitizeInput($_POST['email'] ?? '', 'email');
        $loginCaptchaRequired = frs_login_requires_captcha($emailInput, (string)$clientIp);
        if ($loginCaptchaRequired) {
            $captcha = frs_verify_turnstile($_POST['cf-turnstile-response'] ?? null, (string)$clientIp, true);
            if (!$captcha['ok']) {
                $error = $captcha['error'];
                logSecurityEvent('captcha_validation_failed', 'Login form (suspicious activity)', 'warning');
            }
        }
        if ($error === '') {
        $email = $emailInput;
        $password = $_POST['password'] ?? '';
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check rate limiting (failed attempts only)
            if (!checkLoginRateLimit($email)) {
                $error = 'Too many login attempts. Please try again in 15 minutes.';
                logSecurityEvent('rate_limit_exceeded', "Login attempts exceeded for: $email", 'warning');
            } else {
                try {
                    $pdo = db();
                    
                    // Check account record (including enable_otp and totp_enabled preferences)
                    $stmt = $pdo->prepare("SELECT *, COALESCE(enable_otp, 1) as enable_otp, COALESCE(totp_enabled, 0) as totp_enabled FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        // Admin-locked account
                        if (isset($user['status']) && strtolower($user['status']) === 'locked') {
                            $error = 'Your account has been locked by an administrator. Please contact support to restore access.';
                            $lockNotice = 'Account locked by administrator.';
                            logSecurityEvent('login_attempt_locked_admin', "Attempted login to admin-locked account: $email", 'warning');
                        }
                        // Temporary lock due to rate limits
                        elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                            $until = date('F j, Y g:i A', strtotime($user['locked_until']));
                            $lockReason = 'Account locked due to multiple failed login attempts.';
                            $error = "Your account is temporarily locked until $until. Please contact support if you need it unlocked.";
                            $lockNotice = $lockReason;
                            logSecurityEvent('login_attempt_locked_account', "Attempted login to locked account: $email", 'warning');
                        } else {
                            // Verify password
                            if ($user && password_verify($password, $user['password_hash'])) {
                                // Check if account is deactivated
                                if (strtolower($user['status']) === 'deactivated') {
                                    $error = 'Your account has been deactivated. To restore access, please contact the LGU IT office.';
                                    logSecurityEvent('login_attempt_deactivated', "Login attempt to deactivated account: $email", 'info');
                                }
                                // Check if account is active
                                elseif ($user['status'] !== 'active') {
                                    $error = 'Your account is not active. Please contact an administrator.';
                                    logSecurityEvent('login_attempt_inactive', "Login attempt to inactive account: $email", 'info');
                                } else {
                                $emailVerified = isset($user['email_verified']) ? (bool)$user['email_verified'] : true;
                                if (!$emailVerified) {
                                    $verifyResult = frs_begin_login_email_verification($pdo, $user);
                                    if (!$verifyResult['ok']) {
                                        $error = $verifyResult['error'] ?? 'Email verification is required before you can sign in.';
                                    } else {
                                        header('Location: ' . base_path() . '/verify-email');
                                        exit;
                                    }
                                } else {
                                // Successful password check -> second factor (email OTP and/or authenticator)
                                $enableOtp = frs_user_email_otp_enabled($user);
                                $totpActive = frs_user_totp_active($user);

                                if (!frs_user_has_required_second_factor($user)) {
                                    frs_begin_pending_2fa_setup($user, $next !== '' ? $next : null);
                                    frs_login_clear_captcha_required();

                                    $logStmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 1)");
                                    $logStmt->execute([$email, getClientIP()]);
                                    logSecurityEvent('login_2fa_setup_required', "Admin/Staff redirected to 2FA setup: $email", 'warning');

                                    header('Location: ' . base_path() . '/login-setup-2fa');
                                    exit;
                                } elseif (frs_login_requires_second_factor($user)) {
                                    $_SESSION['login_otp_email_sent'] = false;

                                    if ($enableOtp) {
                                        $otp = frs_issue_login_otp_code($pdo, (int) $user['id'], getClientIP());

                                        require_once __DIR__ . '/../../../../config/email_templates.php';
                                        $otpBody = getOTPEmailTemplate($user['name'], (int) $otp, 1);
                                        sendEmail($user['email'], $user['name'], 'Login Verification Code', $otpBody);
                                        if (!empty($user['mobile'])) {
                                            require_once __DIR__ . '/../../../../config/sms_helper.php';
                                            sendLoginOtpSms((string)$user['mobile'], (string)$otp, 1);
                                        }
                                        $_SESSION['login_otp_email_sent'] = true;
                                    } else {
                                        // Authenticator-only: never send or keep email OTP codes
                                        $pdo->prepare('UPDATE users SET otp_code_hash = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?')
                                            ->execute([(int) $user['id']]);
                                    }

                                    // Log successful password stage
                                    $logStmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 1)");
                                    $logStmt->execute([$email, getClientIP()]);
                                    frs_login_clear_captcha_required();

                                    // Save pending OTP session
                                    session_regenerate_id(true);
                                    $_SESSION['pending_otp_user_id'] = $user['id'];
                                    $_SESSION['pending_otp_email'] = $user['email'];
                                    $_SESSION['pending_otp_name'] = $user['name'];
                                    // Keep redirect target for post-OTP landing
                                    if ($next) {
                                        $_SESSION['post_login_redirect'] = $next;
                                    }

                                    header('Location: ' . base_path() . '/login-otp');
                                    exit;
                                } else {
                                    // Both email OTP and Google Authenticator are disabled -> log in directly
                                    $updateStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_ip = ? WHERE id = ?");
                                    $updateStmt->execute([getClientIP(), $user['id']]);
                                    frs_login_clear_captcha_required();

                                    // Log successful login
                                    $logStmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 1)");
                                    $logStmt->execute([$email, getClientIP()]);

                                    if ($next) {
                                        $_SESSION['post_login_redirect'] = $next;
                                    }
                                    frs_complete_authenticated_login($user);
                                    logSecurityEvent('login_success', "User logged in successfully: $email (OTP disabled)", 'info');
                                    frs_redirect_after_login();
                                }
                                }
                                }
                            } else {
                                // Failed login
                                $failedAttempts = (int)($user['failed_login_attempts'] ?? 0) + 1;
                                $lockUntil = null;
                                
                                // Lock account after 5 failed attempts for 30 minutes
                                if ($failedAttempts >= 5) {
                                    $lockUntil = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
                                    $error = 'Too many failed login attempts. Your account has been locked for 30 minutes.';
                                    $lockReason = 'Account locked due to multiple failed login attempts.';
                                    logSecurityEvent('account_locked', "Account locked due to failed attempts: $email", 'warning');
                                    // Send lock notification email (one-time per lock event)
                                    try {
                                        require_once __DIR__ . '/../../../../config/email_templates.php';
                                        $body = getAccountLockedFailedLoginEmailTemplate($user['name'], 30);
                                        sendEmail($user['email'], $user['name'], 'Account Temporarily Locked', $body);
                                    } catch (Exception $e) {
                                        // ignore email failures here
                                    }
                                } else {
                                    $error = 'Invalid email or password.';
                                }
                                
                                recordLoginRateLimitFailure($email);
                                frs_login_mark_captcha_required();
                                $loginCaptchaRequired = true;

                                // Update failed attempts
                                $updateStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
                                $updateStmt->execute([$failedAttempts, $lockUntil, $user['id']]);
                                
                                // Log failed attempt
                                $logStmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 0)");
                                $logStmt->execute([$email, getClientIP()]);
                                
                                logSecurityEvent('login_failed', "Failed login attempt: $email", 'warning');
                            }
                        }
                    } else {
                        // User not found - don't reveal this to prevent email enumeration
                        $error = 'Invalid email or password.';
                        recordLoginRateLimitFailure($email);
                        frs_login_mark_captcha_required();
                        $loginCaptchaRequired = true;
                        logSecurityEvent('login_attempt_invalid_email', "Login attempt with non-existent email: $email", 'info');
                    }
                } catch (Exception $e) {
                    $error = 'Unable to connect. Please try again later.';
                    logSecurityEvent('login_error', "Database error during login: " . $e->getMessage(), 'error');
                }
            }
        }
        }
    }
}

$base = base_path();
ob_start();
?>
<section class="auth-page-hero">
    <div class="home-hero-bg" style="background-image: url('<?= htmlspecialchars($base); ?>/public/uploads/Main%20Bg.jpg');"></div>
    <div class="home-hero-overlay"></div>
    <div class="relative z-10 w-full flex flex-col items-center justify-center flex-1">
<div class="auth-container public-fade-in">
    <div class="auth-card">
        <div class="auth-header">
            <img src="<?= base_path(); ?>/public/img/infragov-logo.png" alt="Infra Gov Services" style="height: 64px; width: auto; display: block; margin: 0 auto 1.25rem; object-fit: contain;">
            <?= frs_heading_with_tip('Welcome Back', 'Residents and staff sign in with email. You may be asked for a one-time passcode (OTP) after your password.', 'h1'); ?>
        </div>
        
        <?php if (isset($_GET['deactivated']) && $_GET['deactivated'] == '1'): ?>
            <div style="background: #fff4e5; border: 2px solid #f59e0b; color: #92400e; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <strong>⚠️ Account Deactivated</strong>
                <p style="margin: 0.5rem 0 0; line-height: 1.6;">
                    Your account has been successfully deactivated. You can no longer log in to the system.
                    To restore access, please contact the LGU IT office.
                </p>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($error); ?>
            </div>
            <?php if ($lockNotice): ?>
                <div style="background: #fff4e5; color: #856404; padding: 0.7rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.88rem;">
                    <?= htmlspecialchars($lockNotice); ?>
                    <br>
                    Need help? Contact the admin team to review and unlock your account.
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <form method="POST" class="auth-form">
            <?= csrf_field(); ?>
            <?php if ($loginCaptchaRequired && frs_turnstile_site_key() !== ''): ?>
                <div style="background: #fff8e6; border: 1px solid #f59e0b; color: #92400e; padding: 0.65rem 0.85rem; border-radius: 8px; margin-bottom: 0.75rem; font-size: 0.85rem;">
                    For your security, please complete the verification below after multiple failed sign-in attempts.
                </div>
                <div style="margin: 0.25rem 0 0.5rem;">
                    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(frs_turnstile_site_key(), ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>
            <?php endif; ?>
            <label>
                Email Address
                <div class="input-wrapper">
                    <input name="email" type="email" placeholder="official@lgu.gov.ph" required autofocus value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                </div>
            </label>
            
            <label>
                Password
                <div class="input-wrapper">
                    <input name="password" type="password" placeholder="Enter your password" required>
                </div>
            </label>
            
            <div style="text-align: right; margin-bottom: 1rem;">
                <a href="<?= base_path(); ?>/forgot-password" style="color: #2864ef; font-size: 0.85rem; text-decoration: none;">
                    Forgot Password?
                </a>
            </div>
            
            <button class="btn-primary" type="submit">Sign In</button>
        </form>
        
        <div class="auth-footer">
            Need an account? <a href="<?= base_path(); ?>/register">Register here</a>
        </div>
    </div>
</div>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


