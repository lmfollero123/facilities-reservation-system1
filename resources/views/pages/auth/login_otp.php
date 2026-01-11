<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';

$pageTitle = 'Enter OTP | LGU Facilities Reservation';
$error = '';
$success = '';

if (!isset($_SESSION['pending_otp_user_id'])) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

$userId = (int)$_SESSION['pending_otp_user_id'];
$userEmail = $_SESSION['pending_otp_email'] ?? '';
$userName = $_SESSION['pending_otp_name'] ?? '';

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, name, otp_code_hash, otp_expires_at, otp_attempts, otp_last_sent_at, role, status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
        exit;
    }

    // Check if account is deactivated
    if (strtolower($user['status']) === 'deactivated') {
        session_destroy();
        header('Location: ' . base_path() . '/resources/views/pages/auth/login.php?deactivated=1');
        exit;
    }
    
    if ($user['status'] !== 'active') {
        $error = 'Your account is not active.';
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
        $otpInput = trim($_POST['otp']);

        if (!$otpInput) {
            $error = 'Please enter the OTP sent to your email.';
        } elseif ($user['otp_attempts'] >= 5) {
            $error = 'Too many incorrect attempts. Please log in again.';
        } elseif (!$user['otp_code_hash'] || !$user['otp_expires_at'] || strtotime($user['otp_expires_at']) < time()) {
            $error = 'OTP has expired. Please request a new code.';
        } elseif (!password_verify($otpInput, $user['otp_code_hash'])) {
            $error = 'Incorrect OTP.';
            $pdo->prepare("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$userId]);
        } else {
            // OTP valid -> finalize login
            $pdo->prepare("UPDATE users SET otp_code_hash = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?")->execute([$userId]);

            session_regenerate_id(true);
            $_SESSION['user_authenticated'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_org'] = $user['role'];
            $_SESSION['last_activity'] = time();

            unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_email'], $_SESSION['pending_otp_name']);

            // Redirect to requested page if provided and safe, otherwise dashboard
            $redirect = $_SESSION['post_login_redirect'] ?? '';
            unset($_SESSION['post_login_redirect']);
            if ($redirect && str_starts_with($redirect, '/')) {
                header('Location: ' . $redirect);
            } else {
                header('Location: ' . base_path() . '/resources/views/pages/dashboard/index.php');
            }
            exit;
        }
    }

    // Resend OTP
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
        $lastSent = $user['otp_last_sent_at'] ? strtotime($user['otp_last_sent_at']) : 0;
        if (time() - $lastSent < 60) {
            $error = 'Please wait a moment before requesting another code.';
        } else {
            $otp = random_int(100000, 999999);
            $otpHash = password_hash((string)$otp, PASSWORD_DEFAULT);
            $otpExpiry = date('Y-m-d H:i:s', time() + 600);

            $pdo->prepare("UPDATE users SET otp_code_hash = ?, otp_expires_at = ?, otp_attempts = 0, otp_last_sent_at = NOW() WHERE id = ?")
                ->execute([$otpHash, $otpExpiry, $userId]);

            $otpBody = "<p>Hi " . htmlspecialchars($user['name']) . ",</p><p>Your one-time passcode is <strong>$otp</strong>.</p><p>This code expires in 10 minutes.</p>";
            sendEmail($user['email'], $user['name'], 'Your login OTP', $otpBody);
            $success = 'A new OTP has been sent to your email.';
        }
    }
} catch (Exception $e) {
    $error = 'Unable to process OTP right now.';
}

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🔐</div>
            <h1>Enter One-Time Passcode</h1>
            <p>We sent a 6-digit code to <?= htmlspecialchars($userEmail); ?></p>
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

        <form method="POST" style="margin-top:0.75rem; text-align:center;">
            <?= csrf_field(); ?>
            <button class="btn-outline" type="submit" name="resend" value="1" style="padding:0.45rem 0.75rem;">Resend Code</button>
        </form>

        <div class="auth-footer">
            <a href="<?= base_path(); ?>/resources/views/pages/auth/login.php">Back to login</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';




