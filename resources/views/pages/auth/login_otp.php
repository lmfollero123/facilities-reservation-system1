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
    $stmt = $pdo->prepare("SELECT id, email, name, otp_code_hash, otp_expires_at, otp_attempts, otp_last_sent_at, role, status, totp_secret, COALESCE(totp_enabled, 0) AS totp_enabled, COALESCE(enable_otp, 1) AS enable_otp FROM users WHERE id = ?");
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
            // 2) Email OTP — only when enabled in profile (never when user turned off email OTP)
            if (!$valid && frs_user_email_otp_enabled($user) && frs_login_otp_code_is_valid($pdo, $userId)) {
                $hash = $user['otp_code_hash'] ?? '';
                if ($hash && password_verify($otpInput, $hash)) {
                    $valid = true;
                }
            }
            if (!$valid) {
                $emailOk = frs_user_email_otp_enabled($user) && frs_login_otp_code_is_valid($pdo, $userId);
                $hasTotp = frs_user_totp_active($user);
                if (!$hasTotp && !$emailOk) {
                    if (frs_user_email_otp_enabled($user)) {
                        $error = 'OTP has expired. Please request a new code.';
                    } else {
                        $error = 'Enter the 6-digit code from your authenticator app.';
                    }
                } else {
                    if ($emailOk) {
                        $pdo->prepare("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$userId]);
                    }
                    $error = 'Incorrect OTP.';
                }
            }
        }
        if (empty($error)) {
            // OTP valid -> finalize login
            $pdo->prepare("UPDATE users SET otp_code_hash = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?")->execute([$userId]);
            $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_ip = ? WHERE id = ?")
                ->execute([getClientIP(), $userId]);

            frs_complete_authenticated_login($user);
            logSecurityEvent('login_success', 'User logged in successfully via OTP: ' . ($user['email'] ?? ''), 'info');
            frs_redirect_after_login();
        }
    }

    // Resend email OTP — only when email OTP is enabled in profile
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
        if (!frs_user_email_otp_enabled($user)) {
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
            $otpBody = getOTPEmailTemplate($user['name'], (int) $otp, 1);
            sendEmail($user['email'], $user['name'], 'Login Verification Code', $otpBody);
            if (!empty($user['mobile'])) {
                require_once __DIR__ . '/../../../../config/sms_helper.php';
                sendLoginOtpSms((string) $user['mobile'], (string) $otp, 1);
            }
            $success = 'A 6-digit code has been sent to your email.';
            $_SESSION['login_otp_email_sent'] = true;
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
$hasEmailOtpInDb = $emailOtpEnabled && !empty($user['otp_code_hash']);
$emailOtpValid = $emailOtpEnabled && frs_login_otp_code_is_valid($pdo, $userId);
$otpRemainingSeconds = $emailOtpEnabled ? frs_login_otp_remaining_seconds($pdo, $userId) : 0;
$showEmailOtpCountdown = $emailOtpEnabled && ($hasEmailOtpInDb || !empty($_SESSION['login_otp_email_sent']));
$showResendEmailOtp = $emailOtpEnabled;

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🔐</div>
            <?php
            if ($showEmailOtpCountdown && $emailOtpValid) {
                $otpTip = 'We sent a 6-digit code to ' . $userEmail . '. Codes expire in about ' . $loginOtpTtlMinutes . ' minute' . ($loginOtpTtlMinutes === 1 ? '' : 's') . '.'
                    . ($hasTotp ? ' You may also use your authenticator app.' : '');
            } elseif ($hasTotp && !$emailOtpEnabled) {
                $otpTip = 'Email OTP is disabled. Enter the 6-digit code from your authenticator app to finish signing in.';
            } elseif ($hasTotp) {
                $otpTip = 'Enter the code from your authenticator app, or use the email code if you received one.';
            } else {
                $otpTip = 'Enter the 6-digit code from your email to finish signing in.';
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
            <?php elseif ($hasTotp && !$emailOtpEnabled): ?>
                <p style="font-weight:600; margin-top:0.5rem; color:#475569; font-size:0.9rem;">
                    Open your authenticator app and enter the current 6-digit code.
                </p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div style="background: #e3f8ef; color: #0d7a43; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="auth-form" id="otpForm">
            <?= csrf_field(); ?>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">OTP Code</label>
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

        <?php if ($showResendEmailOtp): ?>
        <form method="POST" id="loginOtpResendForm" style="margin-top:0.75rem; text-align:center;">
            <?= csrf_field(); ?>
            <button class="<?= ($showEmailOtpCountdown && !$emailOtpValid) ? 'btn-primary' : 'btn-outline'; ?>" type="submit" name="resend" value="1" id="loginOtpResendBtn" style="padding:0.45rem 0.75rem;">Resend Code</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="<?= base_path(); ?>/login">Back to login</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';

// Always load OTP styling since it's used for both email OTP and TOTP
?>
<style>
.otp-input-container {
    display: flex !important;
    flex-direction: row !important;
    gap: 0.75rem;
    justify-content: center;
    margin-top: 0.75rem;
    width: 100%;
}

.otp-input {
    width: 50px !important;
    height: 50px !important;
    text-align: center;
    font-size: 1.75rem;
    font-weight: 700;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 0;
    transition: all 0.2s ease;
    background: #ffffff;
    color: #1e293b;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    flex-shrink: 0;
    max-width: 50px !important;
    min-width: 50px !important;
}

.otp-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15), 0 4px 6px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.otp-input:not(:placeholder-shown) {
    border-color: #3b82f6;
    background: #f8fafc;
}

.otp-input::placeholder {
    color: #cbd5e1;
    font-size: 1.5rem;
}

@media (max-width: 480px) {
    .otp-input {
        width: 45px !important;
        height: 45px !important;
        font-size: 1.5rem;
        border-radius: 10px;
        max-width: 45px !important;
        min-width: 45px !important;
    }
    
    .otp-input-container {
        gap: 0.5rem;
    }
}
</style>
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
        const resendLabels = ['Resend Code', 'Send code to email instead'];

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


