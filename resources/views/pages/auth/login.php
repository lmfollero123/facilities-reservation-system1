<?php
$useTailwind = true;
$authSplitLayout = true;
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
                                    unset($_SESSION['login_otp_recovery_mode']);
                                    $_SESSION['login_otp_email_sent'] = false;
                                    $_SESSION['login_otp_sms_sent'] = false;
                                    $smsOtpEnabled = frs_user_sms_otp_enabled($user);

                                    if (($enableOtp || $smsOtpEnabled) && !$totpActive) {
                                        // TOTP (when active) is entered directly with no send needed;
                                        // the OTP page offers "send via X" buttons for other channels.
                                        $otp = frs_issue_login_otp_code($pdo, (int) $user['id'], getClientIP());
                                        $ttlMinutes = (int) ceil(LOGIN_OTP_CODE_TTL_SECONDS / 60);
                                        if ($enableOtp) {
                                            require_once __DIR__ . '/../../../../config/email_templates.php';
                                            $otpBody = getOTPEmailTemplate($user['name'], (int) $otp, $ttlMinutes);
                                            sendEmail($user['email'], $user['name'], 'Login Verification Code', $otpBody);
                                            $_SESSION['login_otp_email_sent'] = true;
                                        } elseif ($smsOtpEnabled) {
                                            require_once __DIR__ . '/../../../../config/sms_helper.php';
                                            sendLoginOtpSms((string) $user['mobile'], (string) $otp, $ttlMinutes);
                                            $_SESSION['login_otp_sms_sent'] = true;
                                        }
                                    } else {
                                        // TOTP is active (with or without email/SMS also enabled as
                                        // fallback buttons on the OTP page) -- no code sent yet, so
                                        // clear any stale one from a previous attempt.
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
<section class="auth-split auth-split-login">
    <aside class="auth-split-brand" aria-hidden="false">
        <?php include __DIR__ . '/../../components/auth_brand_illustration.php'; ?>
        <div class="auth-split-brand-inner">
            <a href="<?= htmlspecialchars($base); ?>/" class="auth-split-back">
                <i class="bi bi-arrow-left"></i> Back to website
            </a>
            <img src="<?= htmlspecialchars($base); ?>/public/img/brgy-culiat-logo.png" alt="Barangay Culiat CPRFS" class="auth-split-brand-logo">
            <h2>Magandang Buhay! 👋</h2>
            <p>Book public facilities online — reserve covered courts, halls, and community spaces without the long lines. Fast, simple, and made for our residents.</p>
            <?php include __DIR__ . '/../../components/auth_facility_slideshow.php'; ?>
            <p class="auth-split-brand-footer">&copy; <?= date('Y'); ?> Barangay Culiat CPRFS. All rights reserved.</p>
        </div>
    </aside>

    <div class="auth-split-form-panel">
        <div class="auth-split-form-decor" aria-hidden="true">
            <!-- Top-right tropical palm / leaf cluster -->
            <svg class="auth-split-form-decor__palm-tr" viewBox="0 0 280 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g fill="#059669" fill-opacity="0.5">
                    <path d="M140 30 C 170 50, 210 60, 260 55 C 215 70, 180 90, 150 120 C 142 90, 135 60, 140 30 Z"/>
                    <path d="M140 30 C 130 60, 100 90, 55 110 C 90 85, 118 65, 138 35 Z" fill-opacity="0.4"/>
                    <path d="M140 30 C 168 48, 200 100, 225 160 C 190 125, 165 85, 142 38 Z" fill-opacity="0.45"/>
                    <path d="M140 30 C 115 55, 80 115, 45 175 C 80 140, 110 90, 138 35 Z" fill-opacity="0.35"/>
                </g>
                <g fill="#10b981" fill-opacity="0.35">
                    <path d="M145 60 C 165 80, 200 120, 245 170 C 208 140, 178 105, 148 65 Z"/>
                    <path d="M138 60 C 118 90, 85 140, 35 200 C 80 165, 115 115, 140 65 Z"/>
                </g>
                <!-- Thin stem -->
                <path d="M140 35 C 142 80, 144 140, 146 220" stroke="#047857" stroke-opacity="0.4" stroke-width="3" stroke-linecap="round" fill="none"/>
            </svg>

            <!-- Bottom-left grass / cropland tufts -->
            <svg class="auth-split-form-decor__grass-bl" viewBox="0 0 320 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Background grass mound -->
                <ellipse cx="160" cy="200" rx="160" ry="40" fill="#059669" fill-opacity="0.18"/>
                <ellipse cx="110" cy="210" rx="120" ry="32" fill="#10b981" fill-opacity="0.15"/>
                <!-- Individual grass blades -->
                <g stroke="#059669" stroke-opacity="0.55" fill="none" stroke-linecap="round">
                    <path d="M40 210 C 42 185, 44 165, 46 140" stroke-width="3"/>
                    <path d="M60 210 C 65 180, 70 155, 75 130" stroke-width="3.5"/>
                    <path d="M82 210 C 88 178, 94 150, 100 120" stroke-width="4"/>
                    <path d="M105 210 C 110 185, 115 160, 122 135" stroke-width="3"/>
                    <path d="M128 210 C 133 182, 138 155, 146 128" stroke-width="3.5"/>
                    <path d="M152 210 C 156 188, 160 165, 168 140" stroke-width="2.8"/>
                    <path d="M176 210 C 180 185, 184 158, 192 132" stroke-width="3.2"/>
                    <path d="M200 210 C 204 188, 208 162, 216 138" stroke-width="2.6"/>
                    <path d="M222 210 C 226 190, 230 168, 238 148" stroke-width="3"/>
                    <!-- Secondary layer shorter blades -->
                    <path d="M50 210 C 53 195, 56 180, 60 165" stroke-width="2"/>
                    <path d="M72 210 C 76 195, 80 178, 86 160" stroke-width="2.2"/>
                    <path d="M96 210 C 100 192, 104 172, 110 154" stroke-width="2"/>
                    <path d="M118 210 C 122 195, 126 175, 132 158" stroke-width="2"/>
                    <path d="M142 210 C 146 196, 150 178, 156 160" stroke-width="2"/>
                    <path d="M164 210 C 168 195, 172 178, 178 160" stroke-width="1.8"/>
                    <path d="M186 210 C 190 195, 194 178, 200 160" stroke-width="2"/>
                    <path d="M210 210 C 214 195, 218 180, 224 165" stroke-width="2"/>
                </g>
                <!-- Wheat / grain tufts accent (amber, barangay harvest feel) -->
                <g fill="#f59e0b" fill-opacity="0.45">
                    <circle cx="76" cy="118" r="4"/>
                    <circle cx="72" cy="128" r="3"/>
                    <circle cx="82" cy="130" r="3"/>
                    <circle cx="102" cy="108" r="4.5"/>
                    <circle cx="97" cy="120" r="3.5"/>
                    <circle cx="108" cy="122" r="3.2"/>
                    <circle cx="148" cy="116" r="4"/>
                    <circle cx="144" cy="128" r="3"/>
                    <circle cx="154" cy="130" r="3"/>
                </g>
            </svg>

            <!-- Sun accent (mirrors the left panel's sun motif) -->
            <svg class="auth-split-form-decor__sun-accent" viewBox="0 0 70 70" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Soft sun disc -->
                <circle cx="35" cy="35" r="24" fill="#fbbf24" fill-opacity="0.5"/>
                <circle cx="35" cy="35" r="18" fill="#fde68a" fill-opacity="0.7"/>
                <!-- Rays -->
                <g stroke="#fbbf24" stroke-opacity="0.55" stroke-width="2" stroke-linecap="round">
                    <line x1="35" y1="4"  x2="35" y2="12"/>
                    <line x1="35" y1="58" x2="35" y2="66"/>
                    <line x1="4"  y1="35" x2="12" y2="35"/>
                    <line x1="58" y1="35" x2="66" y2="35"/>
                    <line x1="13" y1="13" x2="19" y2="19"/>
                    <line x1="51" y1="51" x2="57" y2="57"/>
                    <line x1="13" y1="57" x2="19" y2="51"/>
                    <line x1="51" y1="19" x2="57" y2="13"/>
                </g>
            </svg>

            <!-- Tiny floating leaf #1 (right) -->
            <svg class="auth-split-form-decor__leaf auth-split-form-decor__leaf--1" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 23 C 3 23, 8 18, 14 12 C 20 6, 24 3, 24 3 C 24 3, 19 9, 13 15 C 7 21, 3 23, 3 23 Z" fill="#10b981" fill-opacity="0.65"/>
                <path d="M4 22 C 10 16, 22 5, 23 4" stroke="#047857" stroke-opacity="0.5" stroke-width="1" fill="none"/>
            </svg>
            <!-- Tiny floating leaf #2 (middle split gap) -->
            <svg class="auth-split-form-decor__leaf auth-split-form-decor__leaf--2" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 23 C 3 23, 8 18, 14 12 C 20 6, 24 3, 24 3 C 24 3, 19 9, 13 15 C 7 21, 3 23, 3 23 Z" fill="#059669" fill-opacity="0.55"/>
                <path d="M4 22 C 10 16, 22 5, 23 4" stroke="#064e3b" stroke-opacity="0.4" stroke-width="1" fill="none"/>
            </svg>
            <!-- Tiny floating leaf #3 (form top-right area) -->
            <svg class="auth-split-form-decor__leaf auth-split-form-decor__leaf--3" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M23 3 C 23 3, 18 8, 12 14 C 6 20, 3 24, 3 24 C 3 24, 9 19, 15 13 C 21 7, 23 3, 23 3 Z" fill="#fbbf24" fill-opacity="0.55"/>
                <path d="M22 4 C 16 10, 5 22, 4 23" stroke="#d97706" stroke-opacity="0.45" stroke-width="1" fill="none"/>
            </svg>
        </div>
        <div class="auth-split-form-inner">
            <div class="auth-split-form-top">
                <div class="auth-split-logo-text">
                    <img src="<?= htmlspecialchars($base); ?>/public/img/brgy-culiat-logo.png" alt="">
                    <span>Barangay Culiat <span style="color:#059669;">CPRFS</span></span>
                </div>
                <h1>Welcome Back!</h1>
                <p class="auth-split-sub">Don&rsquo;t have an account? <a href="<?= htmlspecialchars($base); ?>/register">Create a new account now</a>, it&rsquo;s FREE! Takes less than a minute.</p>
            </div>

            <?php if (isset($_GET['deactivated']) && $_GET['deactivated'] == '1'): ?>
                <div class="auth-split-alert is-warning" role="alert">
                    <strong>Account Deactivated</strong>
                    <p style="margin: 0.35rem 0 0;">Your account has been deactivated. Contact the LGU IT office to restore access.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['timeout']) && $_GET['timeout'] == '1'): ?>
                <div class="auth-split-alert is-warning" role="alert">
                    <strong>Session expired</strong>
                    <p style="margin: 0.35rem 0 0;">You were logged out due to inactivity. Please log in again.</p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-split-alert is-error" role="alert">
                    <?= htmlspecialchars($error); ?>
                </div>
                <?php if ($lockNotice): ?>
                    <div class="auth-split-alert is-warning" role="alert">
                        <?= htmlspecialchars($lockNotice); ?>
                        Need help? Contact the admin team to review and unlock your account.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" class="auth-split-form">
                <?= csrf_field(); ?>
                <?php if ($loginCaptchaRequired && frs_turnstile_site_key() !== ''): ?>
                    <div class="auth-split-alert is-warning">
                        For your security, please complete the verification below after multiple failed sign-in attempts.
                    </div>
                    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(frs_turnstile_site_key(), ENT_QUOTES, 'UTF-8'); ?>"></div>
                <?php endif; ?>

                <label>
                    Email Address
                    <div class="auth-split-field">
                        <i class="bi bi-envelope auth-split-field-icon" aria-hidden="true"></i>
                        <input name="email" type="email" placeholder="official@lgu.gov.ph" required autofocus value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                    </div>
                </label>

                <label>
                    Password
                    <div class="auth-split-field">
                        <i class="bi bi-lock auth-split-field-icon" aria-hidden="true"></i>
                        <input name="password" id="loginPassword" type="password" placeholder="Enter your password" required>
                        <button type="button" class="auth-split-password-toggle" id="toggleLoginPassword" aria-label="Show password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </label>

                <div class="auth-split-forgot">
                    <a href="<?= htmlspecialchars($base); ?>/forgot-password">Forgot password? Click here</a>
                </div>

                <button class="btn-primary" type="submit">Login Now</button>
                <p class="auth-split-trust"><i class="bi bi-shield-check" aria-hidden="true"></i> Protected under the Data Privacy Act of 2012 (RA 10173)</p>
            </form>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleLoginPassword');
    const pwdInput = document.getElementById('loginPassword');
    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function () {
            const isHidden = pwdInput.type === 'password';
            pwdInput.type = isHidden ? 'text' : 'password';
            toggleBtn.innerHTML = isHidden ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


