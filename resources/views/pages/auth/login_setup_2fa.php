<?php
$useTailwind = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

$pageTitle = 'Set Up Two-Factor Authentication | LGU Facilities Reservation';
$error = '';
$success = '';
$view = 'choose';
$totpQrUri = null;
$totpSecretDisplay = null;

if (!isset($_SESSION['pending_2fa_setup_user_id'])) {
    header('Location: ' . base_path() . '/login');
    exit;
}

$userId = (int) $_SESSION['pending_2fa_setup_user_id'];
$userEmail = (string) ($_SESSION['pending_2fa_setup_email'] ?? '');
$userName = (string) ($_SESSION['pending_2fa_setup_name'] ?? '');

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, email, name, mobile, role, status, otp_code_hash, otp_expires_at, otp_attempts, otp_last_sent_at,
                totp_secret, COALESCE(totp_enabled, 0) AS totp_enabled, COALESCE(enable_otp, 1) AS enable_otp
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || strtolower((string) ($user['status'] ?? '')) !== 'active') {
        frs_clear_pending_2fa_setup();
        session_destroy();
        header('Location: ' . base_path() . '/login');
        exit;
    }

    if (!frs_role_requires_two_factor((string) ($user['role'] ?? '')) || frs_user_has_required_second_factor($user)) {
        frs_clear_pending_2fa_setup();
        header('Location: ' . base_path() . '/login');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['back_to_choose'])) {
        if (isset($_POST[CSRF_TOKEN_NAME]) && verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            unset($_SESSION['pending_2fa_setup_email_sent'], $_SESSION['pending_2fa_setup_totp_secret']);
            $view = 'choose';
        } else {
            $error = 'Invalid security token. Please refresh and try again.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_setup'])) {
        if (isset($_POST[CSRF_TOKEN_NAME]) && verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            frs_clear_pending_2fa_setup();
            header('Location: ' . base_path() . '/login');
            exit;
        }
        $error = 'Invalid security token. Please refresh and try again.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email_setup'])) {
        if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh and try again.';
        } elseif (!frs_can_resend_login_otp($pdo, $userId)) {
            $error = 'Please wait a moment before requesting another code.';
        } else {
            $otp = frs_issue_login_otp_code($pdo, $userId, getClientIP());
            $otpBody = getOTPEmailTemplate($user['name'], (int) $otp, 1);
            sendEmail($user['email'], $user['name'], 'Set Up Login Verification Code', $otpBody);
            if (!empty($user['mobile'])) {
                require_once __DIR__ . '/../../../../config/sms_helper.php';
                sendLoginOtpSms((string) $user['mobile'], (string) $otp, 1);
            }
            $_SESSION['pending_2fa_setup_email_sent'] = true;
            $view = 'email';
            $success = 'A verification code was sent to ' . htmlspecialchars($userEmail) . '. Enter it below to enable email OTP.';
            logSecurityEvent('2fa_setup_email_sent', 'Recovery email OTP sent for user id ' . $userId, 'info');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email_setup'])) {
        if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh and try again.';
            $view = 'email';
        } else {
            $otpInput = trim((string) ($_POST['otp'] ?? ''));
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;

            if (($user['otp_attempts'] ?? 0) >= 5) {
                $error = 'Too many incorrect attempts. Please sign in again.';
                frs_clear_pending_2fa_setup();
            } elseif ($otpInput === '' || !frs_login_otp_code_is_valid($pdo, $userId)) {
                $error = 'The code has expired. Request a new verification code.';
                $view = 'email';
            } elseif (empty($user['otp_code_hash']) || !password_verify($otpInput, (string) $user['otp_code_hash'])) {
                $pdo->prepare('UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$userId]);
                $error = 'Incorrect verification code.';
                $view = 'email';
            } else {
                $pdo->prepare(
                    'UPDATE users SET enable_otp = 1, otp_code_hash = NULL, otp_expires_at = NULL, otp_attempts = 0,
                     failed_login_attempts = 0, locked_until = NULL, last_login_ip = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([getClientIP(), $userId]);

                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;

                frs_complete_authenticated_login($user);
                logSecurityEvent('2fa_setup_email_complete', 'Email OTP enabled via recovery for user id ' . $userId, 'info');
                logSecurityEvent('login_success', 'User logged in after 2FA email setup: ' . $userEmail, 'info');
                frs_redirect_after_login();
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_totp_setup'])) {
        if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh and try again.';
        } else {
            try {
                if (!class_exists('RobThree\Auth\TwoFactorAuth') || !class_exists('RobThree\Auth\Providers\Qr\QRServerProvider')) {
                    throw new Exception('Authenticator setup is unavailable. Please enable email OTP instead.');
                }
                $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
                $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
                $secret = $tfa->createSecret();
                $_SESSION['pending_2fa_setup_totp_secret'] = $secret;
                $totpQrUri = $tfa->getQRCodeImageAsDataUri($user['email'], $secret);
                $totpSecretDisplay = $secret;
                $view = 'totp';
                $success = 'Scan the QR code with Google Authenticator (or similar), then enter the 6-digit code to finish.';
            } catch (Throwable $e) {
                error_log('2FA setup TOTP start error: ' . $e->getMessage());
                $error = 'Could not start authenticator setup. Try email OTP instead.';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_totp_setup'])) {
        $view = 'totp';
        if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
            $error = 'Invalid security token. Please refresh and try again.';
        } elseif (empty($_SESSION['pending_2fa_setup_totp_secret'])) {
            $error = 'Authenticator setup expired. Please start again.';
            $view = 'choose';
        } else {
            $code = trim(preg_replace('/\D/', '', (string) ($_POST['totp_code'] ?? '')));
            try {
                $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
                $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
                if (strlen($code) === 6 && $tfa->verifyCode($_SESSION['pending_2fa_setup_totp_secret'], $code)) {
                    $pdo->prepare(
                        'UPDATE users SET totp_secret = ?, totp_enabled = 1, failed_login_attempts = 0, locked_until = NULL,
                         last_login_ip = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                    )->execute([$_SESSION['pending_2fa_setup_totp_secret'], getClientIP(), $userId]);

                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;

                    frs_complete_authenticated_login($user);
                    logSecurityEvent('2fa_setup_totp_complete', 'Authenticator enabled via recovery for user id ' . $userId, 'info');
                    logSecurityEvent('login_success', 'User logged in after 2FA authenticator setup: ' . $userEmail, 'info');
                    frs_redirect_after_login();
                } else {
                    $error = 'Invalid authenticator code. Check the app and try again.';
                    $totpSecretDisplay = $_SESSION['pending_2fa_setup_totp_secret'];
                    if (class_exists('RobThree\Auth\TwoFactorAuth') && class_exists('RobThree\Auth\Providers\Qr\QRServerProvider')) {
                        $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
                        $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
                        $totpQrUri = $tfa->getQRCodeImageAsDataUri($user['email'], $totpSecretDisplay);
                    }
                }
            } catch (Throwable $e) {
                error_log('2FA setup TOTP verify error: ' . $e->getMessage());
                $error = 'Could not verify authenticator code. Please try again.';
            }
        }
    }

    if ($view === 'email' && empty($error) && empty($success) && !empty($_SESSION['pending_2fa_setup_email_sent'])) {
        $view = 'email';
    }

    if ($view === 'totp' && $totpQrUri === null && !empty($_SESSION['pending_2fa_setup_totp_secret'])) {
        $totpSecretDisplay = $_SESSION['pending_2fa_setup_totp_secret'];
        if (class_exists('RobThree\Auth\TwoFactorAuth') && class_exists('RobThree\Auth\Providers\Qr\QRServerProvider')) {
            $qrProvider = new \RobThree\Auth\Providers\Qr\QRServerProvider();
            $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'LGU Facilities');
            $totpQrUri = $tfa->getQRCodeImageAsDataUri($user['email'], $totpSecretDisplay);
        }
    }

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
} catch (Throwable $e) {
    error_log('login_setup_2fa error: ' . $e->getMessage());
    $error = 'Unable to complete security setup right now. Please try again.';
}

$loginOtpTtlMinutes = max(1, (int) ceil(((int) LOGIN_OTP_CODE_TTL_SECONDS) / 60));
$emailOtpValid = ($view === 'email') && isset($pdo) && frs_login_otp_code_is_valid($pdo, $userId);
$otpRemainingSeconds = ($view === 'email') && isset($pdo) ? frs_login_otp_remaining_seconds($pdo, $userId) : 0;

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🛡️</div>
            <?= frs_heading_with_tip(
                'Set Up Two-Factor Authentication',
                'Admin and Staff accounts must have email OTP or Google Authenticator enabled. Choose one method below to restore access.',
                'h1'
            ); ?>
            <p style="color:#64748b; font-size:0.92rem; margin-top:0.75rem;">
                Signed in as <strong><?= htmlspecialchars($userName); ?></strong>
                (<span style="word-break:break-all;"><?= htmlspecialchars($userEmail); ?></span>)
            </p>
        </div>

        <?php if ($error): ?>
            <div style="background:#fdecee;color:#b23030;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.9rem;">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#e3f8ef;color:#0d7a43;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.9rem;">
                <?= $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($view === 'choose'): ?>
            <div style="display:grid; gap:1rem; margin-bottom:1.25rem;">
                <div style="border:1px solid #e5e7eb;border-radius:12px;padding:1rem;background:#f8fafc;">
                    <h2 style="margin:0 0 0.35rem;font-size:1rem;color:#1e3a5f;">Email OTP</h2>
                    <p style="margin:0 0 0.85rem;color:#64748b;font-size:0.9rem;line-height:1.5;">
                        Receive a 6-digit code by email each time you sign in. We will send a verification code to confirm it is you.
                    </p>
                    <form method="POST">
                        <?= csrf_field(); ?>
                        <button type="submit" name="send_email_setup" value="1" class="btn-primary" style="width:100%;">Enable Email OTP</button>
                    </form>
                </div>
                <div style="border:1px solid #e5e7eb;border-radius:12px;padding:1rem;">
                    <h2 style="margin:0 0 0.35rem;font-size:1rem;color:#1e3a5f;">Google Authenticator</h2>
                    <p style="margin:0 0 0.85rem;color:#64748b;font-size:0.9rem;line-height:1.5;">
                        Use an authenticator app for login codes. You will scan a QR code and confirm with a 6-digit code.
                    </p>
                    <form method="POST">
                        <?= csrf_field(); ?>
                        <button type="submit" name="start_totp_setup" value="1" class="btn-outline" style="width:100%;">Set Up Authenticator</button>
                    </form>
                </div>
            </div>
        <?php elseif ($view === 'email'): ?>
            <?php if ($emailOtpValid): ?>
                <p id="setupOtpCountdown" style="font-weight:600;margin:0 0 1rem;color:#b45309;font-size:0.9rem;">
                    Code expires in <?= sprintf('%02d:%02d', intdiv($otpRemainingSeconds, 60), $otpRemainingSeconds % 60); ?>
                </p>
            <?php else: ?>
                <p style="margin:0 0 1rem;color:#b23030;font-size:0.9rem;">Code expired. Request a new verification code.</p>
            <?php endif; ?>
            <form method="POST" class="auth-form">
                <?= csrf_field(); ?>
                <label>
                    Verification code
                    <div class="input-wrapper">
                        <input name="otp" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="6-digit code" required autofocus>
                    </div>
                </label>
                <button class="btn-primary" type="submit" name="verify_email_setup" value="1">Verify and Sign In</button>
            </form>
            <form method="POST" style="margin-top:0.75rem;">
                <?= csrf_field(); ?>
                <button type="submit" name="send_email_setup" value="1" class="btn-outline" style="width:100%;">Resend Code</button>
            </form>
            <form method="POST" style="margin-top:1rem;text-align:center;">
                <?= csrf_field(); ?>
                <button type="submit" name="back_to_choose" value="1" style="background:none;border:none;color:#2864ef;font-size:0.88rem;cursor:pointer;text-decoration:underline;">
                    Choose a different method
                </button>
            </form>
        <?php elseif ($view === 'totp' && $totpQrUri): ?>
            <div style="text-align:center;margin-bottom:1rem;">
                <img src="<?= htmlspecialchars($totpQrUri); ?>" alt="Authenticator QR code" style="max-width:220px;border:1px solid #e5e7eb;border-radius:8px;padding:0.5rem;background:#fff;">
            </div>
            <?php if ($totpSecretDisplay): ?>
                <p style="font-size:0.82rem;color:#64748b;word-break:break-all;margin:0 0 1rem;">
                    Manual entry key: <code><?= htmlspecialchars($totpSecretDisplay); ?></code>
                </p>
            <?php endif; ?>
            <form method="POST" class="auth-form">
                <?= csrf_field(); ?>
                <label>
                    Authenticator code
                    <div class="input-wrapper">
                        <input name="totp_code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="6-digit code" required autofocus>
                    </div>
                </label>
                <button class="btn-primary" type="submit" name="verify_totp_setup" value="1">Verify and Sign In</button>
            </form>
            <form method="POST" style="margin-top:1rem;text-align:center;">
                <?= csrf_field(); ?>
                <button type="submit" name="back_to_choose" value="1" style="background:none;border:none;color:#2864ef;font-size:0.88rem;cursor:pointer;text-decoration:underline;">
                    Choose a different method
                </button>
            </form>
        <?php endif; ?>

        <form method="POST" style="margin-top:1.25rem;text-align:center;">
            <?= csrf_field(); ?>
            <button type="submit" name="cancel_setup" value="1" style="background:none;border:none;color:#64748b;font-size:0.88rem;cursor:pointer;text-decoration:underline;">
                Back to sign in
            </button>
        </form>
    </div>
</div>
<?php if ($view === 'email' && $emailOtpValid && $otpRemainingSeconds > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const countdownEl = document.getElementById('setupOtpCountdown');
    if (!countdownEl) return;
    let remaining = <?= (int) $otpRemainingSeconds; ?>;
    const timer = setInterval(function () {
        remaining--;
        if (remaining <= 0) {
            countdownEl.textContent = 'Code expired. Click "Resend Code" to get a new one.';
            countdownEl.style.color = '#b23030';
            clearInterval(timer);
            return;
        }
        const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
        const ss = String(remaining % 60).padStart(2, '0');
        countdownEl.textContent = 'Code expires in ' + mm + ':' + ss;
    }, 1000);
});
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
