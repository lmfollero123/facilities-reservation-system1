<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';

$pageTitle = 'Login | LGU Facilities Reservation';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
        logSecurityEvent('csrf_validation_failed', 'Login form', 'warning');
    } else {
        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        $password = $_POST['password'] ?? '';
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check rate limiting
            if (!checkLoginRateLimit($email)) {
                $error = 'Too many login attempts. Please try again in 15 minutes.';
                logSecurityEvent('rate_limit_exceeded', "Login attempts exceeded for: $email", 'warning');
            } else {
                try {
                    $pdo = db();
                    
                    // Check if account is locked
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        // Check if account is locked
                        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                            $error = 'Account is temporarily locked due to multiple failed login attempts. Please try again later.';
                            logSecurityEvent('login_attempt_locked_account', "Attempted login to locked account: $email", 'warning');
                        } else {
                            // Verify password
                            if ($user && password_verify($password, $user['password_hash'])) {
                                // Check if account is active
                                if ($user['status'] !== 'active') {
                                    $error = 'Your account is not active. Please contact an administrator.';
                                    logSecurityEvent('login_attempt_inactive', "Login attempt to inactive account: $email", 'info');
                                } else {
                                    // Successful login
                                    // Reset failed login attempts
                                    $updateStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
                                    $updateStmt->execute([getClientIP(), $user['id']]);
                                    
                                    // Log successful login
                                    $logStmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 1)");
                                    $logStmt->execute([$email, getClientIP()]);
                                    
                                    // Regenerate session ID for security
                                    session_regenerate_id(true);
                                    
                                    // Set session variables
                                    $_SESSION['user_authenticated'] = true;
                                    $_SESSION['user_id'] = $user['id'];
                                    $_SESSION['user_name'] = $user['name'];
                                    $_SESSION['user_email'] = $user['email'];
                                    $_SESSION['role'] = $user['role'];
                                    $_SESSION['user_org'] = $user['role'];
                                    $_SESSION['last_activity'] = time();
                                    
                                    logSecurityEvent('login_success', "User logged in: $email", 'info');
                                    
                                    header('Location: ' . base_path() . '/resources/views/pages/dashboard/index.php');
                                    exit;
                                }
                            } else {
                                // Failed login
                                $failedAttempts = (int)($user['failed_login_attempts'] ?? 0) + 1;
                                $lockUntil = null;
                                
                                // Lock account after 5 failed attempts for 30 minutes
                                if ($failedAttempts >= 5) {
                                    $lockUntil = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
                                    $error = 'Too many failed login attempts. Your account has been locked for 30 minutes.';
                                    logSecurityEvent('account_locked', "Account locked due to failed attempts: $email", 'warning');
                                } else {
                                    $error = 'Invalid email or password.';
                                }
                                
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

ob_start();
?>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🏛️</div>
            <h1>Welcome Back</h1>
            <p>Sign in to access your reservations</p>
        </div>
        
        <?php if ($error): ?>
            <div style="background: #fdecee; color: #b23030; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="auth-form">
            <?= csrf_field(); ?>
            <label>
                Email Address
                <div class="input-wrapper">
                    <span class="input-icon">✉️</span>
                    <input name="email" type="email" placeholder="official@lgu.gov.ph" required autofocus value="<?= isset($_POST['email']) ? e($_POST['email']) : ''; ?>">
                </div>
            </label>
            
            <label>
                Password
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input name="password" type="password" placeholder="Enter your password" required>
                </div>
            </label>
            
            <button class="btn-primary" type="submit">Sign In</button>
        </form>
        
        <div class="auth-footer">
            Need an account? <a href="<?= base_path(); ?>/resources/views/pages/auth/register.php">Register here</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


