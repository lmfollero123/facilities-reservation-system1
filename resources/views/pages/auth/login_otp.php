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

        $otpInput = trim($_POST['otp']);

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
            // 2) Otherwise, or if TOTP failed, try email OTP
            if (!$valid && frs_login_otp_code_is_valid($pdo, $userId)) {
                $hash = $user['otp_code_hash'] ?? '';
                if ($hash && password_verify($otpInput, $hash)) {
                    $valid = true;
                }
            }
            if (!$valid) {
                $emailOk = frs_login_otp_code_is_valid($pdo, $userId);
                $hasTotp = ($user['totp_enabled'] ?? 0) && !empty($user['totp_secret']);
                if (!$hasTotp && !$emailOk) {
                    $error = 'OTP has expired. Please request a new code.';
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

            session_regenerate_id(true);
            $_SESSION['user_authenticated'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_org'] = $user['role'];
            $_SESSION['last_activity'] = time();

            unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_email'], $_SESSION['pending_otp_name']);

            // Redirect to requested page if provided and safe, otherwise dashboard
            $redirect = frs_safe_redirect_path($_SESSION['post_login_redirect'] ?? null);
            unset($_SESSION['post_login_redirect']);
            if ($redirect !== null) {
                header('Location: ' . $redirect);
            } else {
                header('Location: ' . base_path() . '/dashboard');
            }
            exit;
        }
    }

    // Send/resend Email OTP (fallback)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
        if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
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
            $success = 'A 6-digit code has been sent to your email.';
        }
    }
} catch (Exception $e) {
    $error = 'Unable to process OTP right now.';
}

$hasTotp = (bool)($user['totp_enabled'] ?? 0) && !empty($user['totp_secret']);
$emailOtpValid = frs_login_otp_code_is_valid($pdo, $userId);
$emailOtpEnabled = (bool)($user['enable_otp'] ?? 1);
$otpRemainingSeconds = frs_login_otp_remaining_seconds($pdo, $userId);

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🔐</div>
            <?php
            if ($emailOtpValid) {
                $otpTip = 'We sent a 6-digit code to ' . $userEmail . '. You may also use an authenticator app if enabled. Codes expire in about one minute.';
            } elseif ($hasTotp) {
                $otpTip = 'Enter the 6-digit code from your authenticator app, or request an email code as a fallback.';
            } else {
                $otpTip = 'Enter the 6-digit code from your email to finish signing in.';
            }
            echo frs_heading_with_tip('Enter One-Time Passcode', $otpTip, 'h1');
            ?>
            <?php if ($emailOtpEnabled): ?>
                <p id="otpCountdown" style="font-weight:600; margin-top:0.5rem; color:<?= $emailOtpValid ? '#b45309' : '#b23030'; ?>;">
                    <?php if ($emailOtpValid): ?>
                        Code expires in <?= sprintf('%02d:%02d', intdiv($otpRemainingSeconds, 60), $otpRemainingSeconds % 60); ?>
                    <?php else: ?>
                        Code expired. Click "Resend Code" below to get a new one.
                    <?php endif; ?>
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

        <form method="POST" class="auth-form">
            <?= csrf_field(); ?>
            <label>
                OTP Code
                <div class="input-wrapper">
                    <input name="otp" type="text" inputmode="numeric" pattern="\d{6}" placeholder="Enter 6-digit code" required>
                </div>
            </label>

            <button class="btn-primary" type="submit">Verify &amp; Sign In</button>
        </form>

        <form method="POST" id="loginOtpResendForm" style="margin-top:0.75rem; text-align:center;">
            <?= csrf_field(); ?>
            <?php if ($hasTotp && !$emailOtpEnabled): ?>
                <button class="btn-outline" type="submit" name="resend" value="1" id="loginOtpResendBtn" style="padding:0.45rem 0.75rem;">Send code to email instead</button>
            <?php else: ?>
                <button class="<?= $emailOtpValid ? 'btn-outline' : 'btn-primary'; ?>" type="submit" name="resend" value="1" id="loginOtpResendBtn" style="padding:0.45rem 0.75rem;">Resend Code</button>
            <?php endif; ?>
        </form>

        <div class="auth-footer">
            <a href="<?= base_path(); ?>/login">Back to login</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';

if ($emailOtpEnabled):
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const countdownEl = document.getElementById('otpCountdown');
    const resendBtn = document.getElementById('loginOtpResendBtn');
    if (!countdownEl) return;

    let remaining = <?= (int)$otpRemainingSeconds; ?>;

    function renderCountdown() {
        const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
        const ss = String(remaining % 60).padStart(2, '0');
        if (remaining > 0) {
            countdownEl.textContent = `Code expires in ${mm}:${ss}`;
            countdownEl.style.color = '#b45309';
            if (resendBtn && resendBtn.textContent.trim() === 'Resend Code') {
                resendBtn.classList.remove('btn-primary');
                resendBtn.classList.add('btn-outline');
            }
        } else {
            countdownEl.textContent = 'Code expired. Click "Resend Code" below to get a new one.';
            countdownEl.style.color = '#b23030';
            if (resendBtn && resendBtn.textContent.trim() === 'Resend Code') {
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
    }
});
</script>
<?php
endif;




