<?php
$useTailwind = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Verify Email | LGU Facilities Reservation';
$error = '';
$info = '';

// Determine which account is being verified (for display only)
$email = '';
if (!empty($_SESSION['pending_email_verify_email'])) {
    $email = sanitizeInput($_SESSION['pending_email_verify_email'], 'email');
} elseif (isset($_GET['email'])) {
    $email = sanitizeInput($_GET['email'], 'email');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
        logSecurityEvent('csrf_validation_failed', 'Email verification form', 'warning');
    } else {
        // Accept code as 6 separate boxes (array) or single string fallback
        $rawCode = $_POST['code'] ?? '';
        if (is_array($rawCode)) {
            $code = implode('', array_map(static function ($c) { return trim((string)$c); }, $rawCode));
        } else {
            $code = trim((string)$rawCode);
        }

        if ($code === '') {
            $error = 'Please enter the verification code that was sent to your email.';
        } else {
            try {
                $pdo = db();
                $user = null;

                // Prefer pending session user ID for safety
                $pendingUserId = $_SESSION['pending_email_verify_user_id'] ?? null;
                if ($pendingUserId) {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$pendingUserId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif (!empty($email)) {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if (!$user) {
                    $error = 'We could not find an account to verify. Please register again.';
                } else {
                    // Keep email in sync with the account we actually loaded (for display)
                    $email = $user['email'] ?? $email;

                    // Already verified: just log the user in and go to dashboard
                    $emailVerified = isset($user['email_verified']) ? (bool)$user['email_verified'] : true;
                    if ($emailVerified) {
                        session_regenerate_id(true);
                        secureSession();
                        $_SESSION['user_authenticated'] = true;
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['user_org'] = $user['role'];
                        $_SESSION['last_activity'] = time();

                        header('Location: ' . base_path() . '/dashboard');
                        exit;
                    }

                    // Check expiry
                    $expiresAt = $user['email_verification_expires_at'] ?? null;
                    if (empty($expiresAt) || strtotime($expiresAt) < time()) {
                        $error = 'Your verification code has expired. Please register again to create a new account.';
                    } else {
                        $hash = $user['email_verification_code_hash'] ?? '';
                        if (!$hash || !password_verify($code, $hash)) {
                            $error = 'The verification code you entered is incorrect.';
                        } else {
                            // Mark email as verified and clear code fields
                            $update = $pdo->prepare(
                                "UPDATE users 
                                 SET email_verified = 1,
                                     email_verified_at = NOW(),
                                     email_verification_code_hash = NULL,
                                     email_verification_expires_at = NULL
                                 WHERE id = ?"
                            );
                            $update->execute([$user['id']]);

                            logSecurityEvent('email_verified', "User verified email: {$user['email']}", 'info');

                            // Log the user in and redirect to dashboard
                            session_regenerate_id(true);
                            secureSession();
                            $_SESSION['user_authenticated'] = true;
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['user_org'] = $user['role'];
                            $_SESSION['last_activity'] = time();

                            // Clear pending session flags
                            unset($_SESSION['pending_email_verify_user_id'], $_SESSION['pending_email_verify_email']);

                            header('Location: ' . base_path() . '/dashboard');
                            exit;
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'Unable to verify your email at the moment. Please try again later.';
                logSecurityEvent('email_verification_error', 'Error during email verification: ' . $e->getMessage(), 'error');
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
            <img src="<?= htmlspecialchars($base); ?>/public/img/infragov-logo.png" alt="Infra Gov Services" style="height: 64px; width: auto; display: block; margin: 0 auto 1.25rem; object-fit: contain;">
            <h1>Email Verification</h1>
            <p>Please enter the verification code sent to your email to continue.</p>
        </div>

        <?php if ($error): ?>
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php elseif ($info): ?>
            <div style="background: #e3f8ef; color: #0d7a43; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($info); ?>
            </div>
        <?php endif; ?>

        <div style="margin-bottom: 1rem; font-size: 0.9rem; color: #4c5b7c;">
            We sent a 6-digit verification code to:
            <strong><?= htmlspecialchars($email); ?></strong>
        </div>

        <form method="POST" class="auth-form">
            <?= csrf_field(); ?>
            <label>
                Verification Code
                <div class="input-wrapper" style="display:flex; gap:0.5rem; justify-content:center;">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input 
                            name="code[]" 
                            type="text" 
                            inputmode="numeric" 
                            maxlength="1" 
                            class="verify-code-input" 
                            required
                        >
                    <?php endfor; ?>
                </div>
            </label>

            <button class="btn-primary" type="submit" style="margin-top: 1.5rem;">Verify Email</button>
        </form>

        <div class="auth-footer">
            Already verified? <a href="<?= $base; ?>/login">Go to Login</a>
        </div>
    </div>
</div>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';

?>
<style>
/* OTP boxes: always high-contrast, fixed sizing, no clipping */
.verify-code-input {
    box-sizing: border-box !important;
    width: 3.1rem !important;
    height: 3.2rem !important;
    margin: 0 !important;
    padding: 0 !important;
    text-indent: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-family: inherit !important;
    font-size: 1.3rem !important;
    font-weight: 700 !important;
    border-radius: 10px !important;
    border: 2px solid #38bdf8 !important;
    background: #ffffff !important;
    color: #111827 !important;
    overflow: visible !important;
}
.verify-code-input:focus {
    outline: none !important;
    border-color: #0ea5e9 !important;
    box-shadow: 0 0 0 3px rgba(14,165,233,0.35) !important;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputs = Array.from(document.querySelectorAll('.verify-code-input'));
    if (!inputs.length) return;

    // Auto-focus first box
    inputs[0].focus();

    inputs.forEach((input, index) => {
        // Handle direct typing
        input.addEventListener('input', function () {
            // Allow only digits and single character
            this.value = this.value.replace(/\D/g, '').slice(0, 1);
            if (this.value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
        });

        // Handle backspace navigation
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && index > 0) {
                inputs[index - 1].focus();
            }
        });

        // Handle paste of full 6-digit code into any box
        input.addEventListener('paste', function (e) {
            const clipboardData = e.clipboardData || window.clipboardData;
            if (!clipboardData) return;
            const text = clipboardData.getData('text');
            if (!text) return;
            e.preventDefault();

            const digits = text.replace(/\D/g, '').slice(0, inputs.length).split('');
            inputs.forEach((el, i) => {
                el.value = digits[i] ?? '';
            });

            // Focus the last filled box or the last one
            const lastIndex = digits.length ? digits.length - 1 : 0;
            (inputs[lastIndex] || inputs[0]).focus();
        });
    });
});
</script>

