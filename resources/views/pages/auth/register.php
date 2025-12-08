<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';

$pageTitle = 'Register | LGU Facilities Reservation';
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $messageType = 'error';
        logSecurityEvent('csrf_validation_failed', 'Registration form', 'warning');
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        $mobile = sanitizeInput($_POST['mobile'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Validate inputs
        if (empty($name) || strlen($name) < 2) {
            $message = 'Please enter a valid name (at least 2 characters).';
            $messageType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } else {
            // Check rate limiting (by IP)
            $clientIP = getClientIP();
            if (!checkRegisterRateLimit($clientIP)) {
                $message = 'Too many registration attempts. Please try again in 1 hour.';
                $messageType = 'error';
                logSecurityEvent('rate_limit_exceeded', "Registration attempts exceeded from IP: $clientIP", 'warning');
            } else {
                // Validate password
                $passwordErrors = validatePassword($password);
                if (!empty($passwordErrors)) {
                    $message = implode(' ', $passwordErrors);
                    $messageType = 'error';
                } else {
                    try {
                        $pdo = db();
                        
                        // Check if email already exists
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $message = 'This email is already registered.';
                            $messageType = 'error';
                            logSecurityEvent('registration_attempt_existing_email', "Registration attempt with existing email: $email", 'info');
                        } else {
                            // Insert new user with pending status
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("INSERT INTO users (name, email, mobile, password_hash, role, status) VALUES (?, ?, ?, ?, 'Resident', 'pending')");
                            $stmt->execute([
                                $name,
                                $email,
                                $mobile ?: null,
                                $passwordHash
                            ]);
                            
                            logSecurityEvent('registration_success', "New user registered: $email", 'info');
                            
                            $message = 'Registration successful! Your account is pending approval.';
                            $messageType = 'success';
                        }
                    } catch (Exception $e) {
                        $message = 'Registration failed. Please try again.';
                        $messageType = 'error';
                        logSecurityEvent('registration_error', "Database error during registration: " . $e->getMessage(), 'error');
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
            <div class="auth-icon">📝</div>
            <h1>Create Account</h1>
            <p>Register for facility reservations</p>
        </div>
        
        <?php if ($message): ?>
            <div style="background: <?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>; color: <?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="auth-form">
            <?= csrf_field(); ?>
            <label>
                Full Name
                <div class="input-wrapper">
                    <span class="input-icon">👤</span>
                    <input name="name" type="text" placeholder="Juan Dela Cruz" required autofocus value="<?= isset($_POST['name']) ? e($_POST['name']) : ''; ?>" minlength="2">
                </div>
            </label>
            
            <label>
                Email Address
                <div class="input-wrapper">
                    <span class="input-icon">✉️</span>
                    <input name="email" type="email" placeholder="official@lgu.gov.ph" required value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                </div>
            </label>
            
            <label>
                Mobile Number
                <div class="input-wrapper">
                    <span class="input-icon">📱</span>
                    <input name="mobile" type="tel" placeholder="+63 900 000 0000" value="<?= isset($_POST['mobile']) ? e($_POST['mobile']) : ''; ?>">
                </div>
            </label>
            
            <label>
                Password
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input name="password" type="password" placeholder="Create a strong password (min. 8 characters)" required minlength="<?= PASSWORD_MIN_LENGTH; ?>">
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Must be at least <?= PASSWORD_MIN_LENGTH; ?> characters with uppercase, lowercase, and number.
                </small>
            </label>
            
            <button class="btn-primary" type="submit">Register</button>
        </form>
        
        <div class="auth-footer">
            Already registered? <a href="<?= base_path(); ?>/resources/views/pages/auth/login.php">Sign in here</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


