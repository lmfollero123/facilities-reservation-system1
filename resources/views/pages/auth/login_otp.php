<?php
$useTailwind = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

$pageTitle = 'Enter OTP | LGU Facilities Reservation';
$error = '';
$success = '';

if (!isset($_SESSION['pending_otp_user_id'])) {
    header('Location: ' . base_path() . '/login');
    exit;
}

$userId = (int)$_SESSION['pending_otp_user_id'];
$userEmail = $_SESSION['pending_otp_email'] ?? '';
$userName = $_SESSION['pending_otp_name'] ?? '';

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, name, mobile, otp_code_hash, otp_expires_at, otp_attempts, otp_last_sent_at, role, status, totp_secret, must_change_password, COALESCE(totp_enabled, 0) AS totp_enabled, COALESCE(enable_otp, 1) AS enable_otp, COALESCE(sms_otp_enabled, 0) AS sms_otp_enabled FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: ' . base_path() . '/login');
        exit;
    }

    // Check if account is deactivated
    if (strtolower($user['status']) === 'deactivated') {
        session_destroy();
        header('Location: ' . base_path() . '/login?deactivated=1');
        exit;
    }
    
    if ($user['status'] !== 'active') {
        $error = 'Your account is not active.';
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
        if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh the page and try again.';
            logSecurityEvent('csrf_validation_failed', 'OTP verify form', 'warning');
        }

        // Combine individual OTP fields if they exist
        if (isset($_POST['otp_1']) && isset($_POST['otp_2']) && isset($_POST['otp_3']) && 
            isset($_POST['otp_4']) && isset($_POST['otp_5']) && isset($_POST['otp_6'])) {
            $otpInput = trim($_POST['otp_1'] . $_POST['otp_2'] . $_POST['otp_3'] . 
                        $_POST['otp_4'] . $_POST['otp_5'] . $_POST['otp_6']);
        } else {
            $otpInput = trim($_POST['otp']);
        }

        if (empty($error) && !$otpInput) {
            $error = 'Please enter the OTP from your email or authenticator app.';
        } elseif (empty($error) && $user['otp_attempts'] >= 5) {
            $error = 'Too many incorrect attempts. Please log in again.';
        } elseif (empty($error)) {
            $valid = false;
            // 1) If Google Authenticator is enabled, try TOTP first
            if (($user['totp_enabled'] ?? 0) && !empty($user['totp_secret'])) {
                try {
                    if (class_exists('RobThree\Auth\TwoFactorAuth') && class_exists('RobThree\Auth\Providers\Qr\QRServerProvider')) {
                        $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
                        $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
                        $code = preg_replace('/\D/', '', $otpInput);
                        if (strlen($code) === 6 && $tfa->verifyCode($user['totp_secret'], $code)) {
                            $valid = true;
                        }
                    }
                } catch (Throwable $e) { 
                    error_log('TOTP verification error in login: ' . $e->getMessage());
                    /* fall through to email OTP */ 
                }
            }
            // 2) Email/SMS OTP — when enabled in profile, or recovery after lost authenticator.
            // Verification itself is channel-agnostic (same otp_code_hash either way).
            if (!$valid && frs_login_may_verify_otp_code($user) && frs_login_otp_code_is_valid($pdo, $userId)) {
                $hash = $user['otp_code_hash'] ?? '';
                if ($hash && password_verify($otpInput, $hash)) {
                    $valid = true;
                }
            }
            if (!$valid) {
                $codeOk = frs_login_may_verify_otp_code($user) && frs_login_otp_code_is_valid($pdo, $userId);
                $hasTotp = frs_user_totp_active($user);
                $hasFallbackChannel = frs_user_email_otp_enabled($user) || frs_user_sms_otp_enabled($user);
                if (!$hasTotp && !$codeOk) {
                    if ($hasFallbackChannel) {
                        $error = 'OTP has expired. Please request a new code.';
                    } else {
                        $error = 'Enter the 6-digit code from your authenticator app.';
                    }
                } else {
                    if ($codeOk) {
                        $pdo->prepare("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$userId]);
                    }
                    if ($hasTotp && frs_login_otp_recovery_mode_active()) {
                        $error = 'Incorrect code. Check your email/phone or try your authenticator app again.';
                    } elseif ($hasTotp && !$hasFallbackChannel) {
                        $error = 'Incorrect authenticator code.';
                    } else {
                        $error = 'Incorrect OTP.';
                    }
                }
            }
        }
        if (empty($error)) {
            // OTP valid -> finalize login
            $usedRecovery = frs_login_otp_recovery_mode_active()
                && !frs_user_email_otp_enabled($user)
                && !frs_user_sms_otp_enabled($user);
            $pdo->prepare("UPDATE users SET otp_code_hash = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?")->execute([$userId]);
            $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_ip = ? WHERE id = ?")
                ->execute([getClientIP(), $userId]);

            frs_complete_authenticated_login($user);
            unset($_SESSION['login_otp_recovery_mode'], $_SESSION['login_otp_email_sent'], $_SESSION['login_otp_sms_sent']);
            if ($usedRecovery) {
                logSecurityEvent('login_success_totp_recovery', 'User signed in via recovery code after lost authenticator: ' . ($user['email'] ?? ''), 'warning');
            } else {
                logSecurityEvent('login_success', 'User logged in successfully via OTP: ' . ($user['email'] ?? ''), 'info');
            }
            frs_redirect_after_login();
        }
    }

    // Lost authenticator — send one-time email OTP even when email OTP is disabled in profile
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recovery_email'])) {
        if (!frs_login_can_request_totp_recovery($user)) {
            $error = 'Email recovery is not available for this account.';
        } elseif (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh the page and try again.';
            logSecurityEvent('csrf_validation_failed', 'OTP recovery email form', 'warning');
        } elseif (!frs_can_resend_login_otp($pdo, $userId)) {
            $error = 'Please wait a moment before requesting another code.';
        } elseif (empty($error)) {
            $otp = frs_issue_login_otp_code($pdo, $userId);
            require_once __DIR__ . '/../../../../config/email_templates.php';
            $otpBody = getOTPEmailTemplate($user['name'], (int) $otp, (int) ceil(LOGIN_OTP_CODE_TTL_SECONDS / 60));
            sendEmail($user['email'], $user['name'], 'Login Recovery Code', $otpBody);
            $_SESSION['login_otp_recovery_mode'] = true;
            $_SESSION['login_otp_email_sent'] = true;
            $_SESSION['login_otp_sms_sent'] = false;
            $success = 'A recovery code was sent to ' . frs_mask_email_for_display((string) $user['email']) . '.';
            logSecurityEvent('login_totp_recovery_otp_sent', 'Recovery email OTP issued (authenticator-only account): ' . ($user['email'] ?? ''), 'warning');
        }
    }

    // Lost authenticator — send one-time SMS OTP (mirrors the email recovery block above)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recovery_sms'])) {
        if (!frs_login_can_request_sms_totp_recovery($user)) {
            $error = 'SMS recovery is not available for this account.';
        } elseif (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh the page and try again.';
            logSecurityEvent('csrf_validation_failed', 'OTP recovery SMS form', 'warning');
        } elseif (!frs_can_resend_login_otp($pdo, $userId)) {
            $error = 'Please wait a moment before requesting another code.';
        } elseif (empty($error)) {
            $otp = frs_issue_login_otp_code($pdo, $userId);
            require_once __DIR__ . '/../../../../config/sms_helper.php';
            sendLoginOtpSms((string) $user['mobile'], (string) $otp, (int) ceil(LOGIN_OTP_CODE_TTL_SECONDS / 60));
            $_SESSION['login_otp_recovery_mode'] = true;
            $_SESSION['login_otp_sms_sent'] = true;
            $_SESSION['login_otp_email_sent'] = false;
            $success = 'A recovery code was sent to your phone.';
            logSecurityEvent('login_totp_recovery_otp_sent', 'Recovery SMS OTP issued (authenticator-only account): ' . ($user['email'] ?? ''), 'warning');
        }
    }

    // Resend email OTP — profile enabled, or active authenticator recovery session
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
        if (!frs_login_may_verify_email_otp($user)) {
            $error = 'Email OTP is disabled for your account. Use your authenticator app instead.';
        } elseif (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh the page and try again.';
            logSecurityEvent('csrf_validation_failed', 'OTP resend form', 'warning');
        }

        if (empty($error) && !frs_can_resend_login_otp($pdo, $userId)) {
            $error = 'Please wait a moment before requesting another code.';
        } elseif (empty($error)) {
            $otp = frs_issue_login_otp_code($pdo, $userId);

            require_once __DIR__ . '/../../../../config/email_templates.php';
            $otpBody = getOTPEmailTemplate($user['name'], (int) $otp, (int) ceil(LOGIN_OTP_CODE_TTL_SECONDS / 60));
            sendEmail($user['email'], $user['name'], 'Login Verification Code', $otpBody);
            $success = frs_login_otp_recovery_mode_active() && !frs_user_email_otp_enabled($user)
                ? 'A new recovery code was sent to your email.'
                : 'A 6-digit code has been sent to your email.';
            $_SESSION['login_otp_email_sent'] = true;
            $_SESSION['login_otp_sms_sent'] = false;
            if (frs_login_otp_recovery_mode_active()) {
                logSecurityEvent('login_totp_recovery_otp_resent', 'Recovery email OTP resent: ' . ($user['email'] ?? ''), 'info');
            }
        }
    }

    // Resend SMS OTP — mirrors the email resend block above
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_sms'])) {
        $mayUseSms = frs_user_sms_otp_enabled($user)
            || (frs_login_otp_recovery_mode_active() && frs_login_can_request_sms_totp_recovery($user));
        if (!$mayUseSms) {
            $error = 'SMS OTP is not enabled for your account.';
        } elseif (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh the page and try again.';
            logSecurityEvent('csrf_validation_failed', 'OTP resend SMS form', 'warning');
        }

        if (empty($error) && !frs_can_resend_login_otp($pdo, $userId)) {
            $error = 'Please wait a moment before requesting another code.';
        } elseif (empty($error)) {
            $otp = frs_issue_login_otp_code($pdo, $userId);

            require_once __DIR__ . '/../../../../config/sms_helper.php';
            sendLoginOtpSms((string) $user['mobile'], (string) $otp, (int) ceil(LOGIN_OTP_CODE_TTL_SECONDS / 60));
            $success = frs_login_otp_recovery_mode_active() && !frs_user_sms_otp_enabled($user)
                ? 'A new recovery code was sent to your phone.'
                : 'A 6-digit code has been sent to your phone.';
            $_SESSION['login_otp_sms_sent'] = true;
            $_SESSION['login_otp_email_sent'] = false;
            if (frs_login_otp_recovery_mode_active()) {
                logSecurityEvent('login_totp_recovery_otp_resent', 'Recovery SMS OTP resent: ' . ($user['email'] ?? ''), 'info');
            }
        }
    }

    // Refresh user row after POST handlers (resend/verify may change OTP fields)
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
} catch (Exception $e) {
    $error = 'Unable to process OTP right now.';
}

if (!isset($user) || !is_array($user)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

$loginOtpTtlMinutes = max(1, (int) ceil(((int) LOGIN_OTP_CODE_TTL_SECONDS) / 60));
$hasTotp = frs_user_totp_active($user);
$emailOtpEnabled = frs_user_email_otp_enabled($user);
$smsOtpEnabled = frs_user_sms_otp_enabled($user);
$hasFallbackChannel = $emailOtpEnabled || $smsOtpEnabled;
$recoveryMode = frs_login_otp_recovery_mode_active();
$canRequestRecovery = frs_login_can_request_totp_recovery($user);
$canRequestSmsRecovery = frs_login_can_request_sms_totp_recovery($user);
// Verification is channel-agnostic (one shared otp_code_hash/otp_expires_at
// row regardless of whether the pending code went out by email or SMS), so
// the countdown/validity state below is shared across both resend buttons.
$mayVerifyOtpCode = frs_login_may_verify_otp_code($user);
$hasOtpInDb = $mayVerifyOtpCode && !empty($user['otp_code_hash']);
$emailOtpValid = $mayVerifyOtpCode && frs_login_otp_code_is_valid($pdo, $userId);
$otpRemainingSeconds = $mayVerifyOtpCode ? frs_login_otp_remaining_seconds($pdo, $userId) : 0;
$anyCodeSent = !empty($_SESSION['login_otp_email_sent']) || !empty($_SESSION['login_otp_sms_sent']);
$showEmailOtpCountdown = $mayVerifyOtpCode && ($hasOtpInDb || $anyCodeSent);
$showResendEmailOtp = $emailOtpEnabled || ($recoveryMode && $canRequestRecovery);
$showResendSmsOtp = $smsOtpEnabled || ($recoveryMode && $canRequestSmsRecovery);
$maskedUserEmail = frs_mask_email_for_display((string) ($user['email'] ?? $userEmail));
$rawMobile = (string) ($user['mobile'] ?? '');
$maskedUserMobile = $rawMobile !== '' ? (substr($rawMobile, 0, 2) . str_repeat('*', max(0, strlen($rawMobile) - 4)) . substr($rawMobile, -2)) : '';

ob_start();
?>
<div class="auth-card auth-split-standalone-card">
    <div class="auth-header">
        <div class="auth-icon">🔐</div>
        <?php
        $sentViaSms = !empty($_SESSION['login_otp_sms_sent']) && empty($_SESSION['login_otp_email_sent']);
        $sentDestination = $sentViaSms ? $maskedUserMobile : $maskedUserEmail;
        if ($showEmailOtpCountdown && $emailOtpValid) {
            if ($recoveryMode && !$hasFallbackChannel) {
                $otpTip = 'Recovery code sent to ' . $sentDestination . '. It expires in about ' . $loginOtpTtlMinutes . ' minute' . ($loginOtpTtlMinutes === 1 ? '' : 's') . '. You can still use your authenticator app if you regain access.';
            } else {
                $otpTip = 'We sent a 6-digit code to ' . $sentDestination . '. Codes expire in about ' . $loginOtpTtlMinutes . ' minute' . ($loginOtpTtlMinutes === 1 ? '' : 's') . '.'
                    . ($hasTotp ? ' You may also use your authenticator app.' : '');
            }
        } elseif ($hasTotp && !$hasFallbackChannel && !$recoveryMode) {
            $otpTip = 'Enter the 6-digit code from your authenticator app. If you lost access to the app, use the recovery option below.';
        } elseif ($hasTotp && !$hasFallbackChannel && $recoveryMode) {
            $otpTip = 'Enter the recovery code from your email or phone, or use your authenticator app if available.';
        } elseif ($hasTotp) {
            $otpTip = 'Enter the code from your authenticator app, or use an email/SMS code if you received one.';
        } else {
            $otpTip = 'Enter the 6-digit code we sent you to finish signing in.';
        }
        echo frs_heading_with_tip('Enter One-Time Passcode', $otpTip, 'h1');
        ?>
        <?php if ($showEmailOtpCountdown): ?>
            <p id="otpCountdown" style="font-weight:600; margin-top:0.5rem; color:<?= $emailOtpValid ? '#b45309' : '#b23030'; ?>;">
                <?php if ($emailOtpValid): ?>
                    Code expires in <?= sprintf('%02d:%02d', intdiv($otpRemainingSeconds, 60), $otpRemainingSeconds % 60); ?>
                <?php else: ?>
                    Code expired. Click "Resend Code" below to get a new one.
                <?php endif; ?>
            </p>
        <?php elseif ($hasTotp && !$hasFallbackChannel && !$recoveryMode): ?>
            <p style="font-weight:600; margin-top:0.5rem; color:#475569; font-size:0.9rem;">
                Open your authenticator app and enter the current 6-digit code.
            </p>
        <?php elseif ($recoveryMode && !$hasFallbackChannel): ?>
            <p style="font-weight:600; margin-top:0.5rem; color:#475569; font-size:0.9rem;">
                Check your email or phone for the recovery code, or use your authenticator app.
            </p>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div style="background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1.25rem; font-size: 0.9rem;">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1.25rem; font-size: 0.9rem;">
            <?= htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="auth-form" id="otpForm">
        <?= csrf_field(); ?>
        <div style="margin-bottom: 1rem;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color:#334155; font-size:0.85rem;">OTP Code</label>
            <div class="otp-input-container" id="otpContainer">
                <input type="text" name="otp_1" class="otp-input" inputmode="numeric" pattern="[0-9]" maxlength="1" required autocomplete="one-time-code">
                <input type="text" name="otp_2" class="otp-input" inputmode="numeric" pattern="[0-9]" maxlength="1" required autocomplete="one-time-code">
                <input type="text" name="otp_3" class="otp-input" inputmode="numeric" pattern="[0-9]" maxlength="1" required autocomplete="one-time-code">
                <input type="text" name="otp_4" class="otp-input" inputmode="numeric" pattern="[0-9]" maxlength="1" required autocomplete="one-time-code">
                <input type="text" name="otp_5" class="otp-input" inputmode="numeric" pattern="[0-9]" maxlength="1" required autocomplete="one-time-code">
                <input type="text" name="otp_6" class="otp-input" inputmode="numeric" pattern="[0-9]" maxlength="1" required autocomplete="one-time-code">
                <input type="hidden" name="otp" id="otpCombined" value="">
            </div>
        </div>

        <button class="btn-primary" type="submit">Verify &amp; Sign In</button>
    </form>

    <?php $showedAuthenticatorPrompt = false; ?>
    <?php if ($showResendEmailOtp): ?>
    <form method="POST" id="loginOtpResendForm" style="margin-top:0.75rem; text-align:center;">
        <?= csrf_field(); ?>
        <button class="<?= ($showEmailOtpCountdown && !$emailOtpValid && !$sentViaSms) ? 'btn-primary' : 'btn-outline'; ?>" type="submit" name="resend" value="1" id="loginOtpResendBtn" style="padding:0.45rem 0.75rem;">
            <?= ($recoveryMode && !$emailOtpEnabled) ? 'Resend recovery code (Email)' : 'Resend Code (Email)'; ?>
        </button>
    </form>
    <?php elseif ($canRequestRecovery && !$recoveryMode): ?>
    <form method="POST" id="loginOtpRecoveryForm" style="margin-top:1rem; text-align:center;">
        <?= csrf_field(); ?>
        <?php if (!$showedAuthenticatorPrompt): $showedAuthenticatorPrompt = true; ?>
        <p style="font-size:0.85rem; color:#64748b; margin:0 0 0.5rem;">
            Can't access your authenticator app?
        </p>
        <?php endif; ?>
        <button class="btn-outline" type="submit" name="recovery_email" value="1" id="loginOtpRecoveryBtn" style="padding:0.45rem 0.85rem;">
            Send code via Email
        </button>
        <p style="font-size:0.78rem; color:#94a3b8; margin:0.5rem 0 0;">
            A one-time code will be sent to <?= htmlspecialchars($maskedUserEmail); ?>.
        </p>
    </form>
    <?php endif; ?>

    <?php if ($showResendSmsOtp): ?>
    <form method="POST" id="loginOtpResendSmsForm" style="margin-top:0.75rem; text-align:center;">
        <?= csrf_field(); ?>
        <button class="<?= ($showEmailOtpCountdown && !$emailOtpValid && $sentViaSms) ? 'btn-primary' : 'btn-outline'; ?>" type="submit" name="resend_sms" value="1" id="loginOtpResendSmsBtn" style="padding:0.45rem 0.75rem;">
            <?= ($recoveryMode && !$smsOtpEnabled) ? 'Resend recovery code (SMS)' : 'Resend Code (SMS)'; ?>
        </button>
    </form>
    <?php elseif ($canRequestSmsRecovery && !$recoveryMode): ?>
    <form method="POST" id="loginOtpRecoverySmsForm" style="margin-top:1rem; text-align:center;">
        <?= csrf_field(); ?>
        <?php if (!$showedAuthenticatorPrompt): $showedAuthenticatorPrompt = true; ?>
        <p style="font-size:0.85rem; color:#64748b; margin:0 0 0.5rem;">
            Can't access your authenticator app?
        </p>
        <?php endif; ?>
        <button class="btn-outline" type="submit" name="recovery_sms" value="1" id="loginOtpRecoverySmsBtn" style="padding:0.45rem 0.85rem;">
            Send code via SMS
        </button>
        <p style="font-size:0.78rem; color:#94a3b8; margin:0.5rem 0 0;">
            A one-time code will be sent to <?= htmlspecialchars($maskedUserMobile); ?>.
        </p>
    </form>
    <?php endif; ?>

    <div class="auth-footer" style="margin-top:1.5rem; text-align:center; padding-top:1rem; border-top:1px solid #e2e8f0;">
        <a href="<?= base_path(); ?>/login" style="color:#047857; font-weight:600; text-decoration:none; font-size:0.9rem;">Back to login</a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';

// OTP container/input styling lives in auth-pages.css under .auth-split-page scope.
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const countdownEl = document.getElementById('otpCountdown');
    const resendBtn = document.getElementById('loginOtpResendBtn');
    const otpInputs = document.querySelectorAll('.otp-input');
    const otpCombined = document.getElementById('otpCombined');
    const otpForm = document.getElementById('otpForm');
    
    // OTP input handling
    otpInputs.forEach((input, index) => {
        // Auto-focus next input on digit entry
        input.addEventListener('input', function(e) {
            const value = e.target.value;
            
            // Only allow numbers
            if (!/^\d*$/.test(value)) {
                e.target.value = value.replace(/\D/g, '');
                return;
            }
            
            // Move to next input if value is entered
            if (value.length === 1 && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
            
            // Combine all digits
            updateCombinedOtp();
        });
        
        // Handle backspace - move to previous input
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && e.target.value === '' && index > 0) {
                otpInputs[index - 1].focus();
            }
            
            // Handle Enter key - submit form
            if (e.key === 'Enter') {
                e.preventDefault();
                if (index === otpInputs.length - 1) {
                    otpForm.submit();
                }
            }
        });
        
        // Handle paste - distribute digits across inputs
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedData = e.clipboardData.getData('text').replace(/\D/g, '');
            
            if (pastedData.length > 0) {
                otpInputs.forEach((inp, i) => {
                    if (i < pastedData.length) {
                        inp.value = pastedData[i];
                    }
                });
                
                // Focus the last filled input or the next empty one
                const focusIndex = Math.min(pastedData.length, otpInputs.length - 1);
                otpInputs[focusIndex].focus();
                updateCombinedOtp();
            }
        });
        
        // Select all content on focus
        input.addEventListener('focus', function() {
            this.select();
        });
    });
    
    // Auto-focus first input on page load
    if (otpInputs.length > 0) {
        otpInputs[0].focus();
    }
    
    function updateCombinedOtp() {
        const combined = Array.from(otpInputs).map(input => input.value).join('');
        otpCombined.value = combined;
    }
    
    // Countdown timer (only if countdown element exists and we have remaining seconds)
    <?php if (isset($otpRemainingSeconds) && $showEmailOtpCountdown): ?>
    if (countdownEl) {
        let remaining = <?= (int)$otpRemainingSeconds; ?>;
        const resendLabels = ['Resend Code', 'Resend recovery code', 'Send code to email instead'];

        function renderCountdown() {
            const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
            const ss = String(remaining % 60).padStart(2, '0');
            const resendText = resendBtn ? resendBtn.textContent.trim() : 'Resend Code';
            if (remaining > 0) {
                countdownEl.textContent = `Code expires in ${mm}:${ss}`;
                countdownEl.style.color = '#b45309';
                if (resendBtn && resendLabels.includes(resendText)) {
                    resendBtn.classList.remove('btn-primary');
                    resendBtn.classList.add('btn-outline');
                }
            } else {
                countdownEl.textContent = `Code expired. Click "${resendText}" below to get a new one.`;
                countdownEl.style.color = '#b23030';
                if (resendBtn && resendLabels.includes(resendText)) {
                    resendBtn.classList.remove('btn-outline');
                    resendBtn.classList.add('btn-primary');
                }
            }
        }

        renderCountdown();
        if (remaining > 0) {
            const timer = setInterval(function () {
                remaining--;
                renderCountdown();
                if (remaining <= 0) {
                    clearInterval(timer);
                }
            }, 1000);
        } else if (resendBtn && resendLabels.includes(resendBtn.textContent.trim())) {
            resendBtn.classList.remove('btn-outline');
            resendBtn.classList.add('btn-primary');
        }
    }
    <?php endif; ?>
});
</script>


