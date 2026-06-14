<?php
/**
 * Security Configuration and Helpers
 * 
 * Provides CSRF protection, rate limiting, input validation, and security headers
 */

// Security Configuration
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour
define('RATE_LIMIT_LOGIN_ATTEMPTS', 5); // Max failed login attempts per email
define('RATE_LIMIT_LOGIN_WINDOW', 900); // 15 minutes in seconds
define('RATE_LIMIT_EMAIL_VERIFY_ATTEMPTS', 10); // Max wrong codes per pending user
define('RATE_LIMIT_EMAIL_VERIFY_WINDOW', 900); // 15 minutes
define('EMAIL_VERIFICATION_CODE_TTL_SECONDS', 60); // Registration email code lifetime
define('EMAIL_VERIFICATION_RESEND_COOLDOWN_SECONDS', 60); // Min wait before resend
define('LOGIN_OTP_CODE_TTL_SECONDS', 60); // Login email OTP lifetime
define('LOGIN_OTP_RESEND_COOLDOWN_SECONDS', 60); // Min wait before login OTP resend
/** Hours to retain registrations that never completed email verification (industry norm: 24–72h). */
define('UNVERIFIED_ACCOUNT_RETENTION_HOURS', 24);
// Registration rate limit:
// - Allow up to 5 registrations per IP within a 1-hour rolling window
// - If more than 5 attempts occur in that window, block registrations from that IP for 6 hours
define('RATE_LIMIT_REGISTER_ATTEMPTS', 5); // Threshold before IP is blocked
define('RATE_LIMIT_REGISTER_WINDOW', 3600); // 1 hour in seconds
define('RATE_LIMIT_REGISTER_BLOCK_WINDOW', 21600); // 6 hours in seconds
// Gemini / AI chatbot (protect API key from abuse via app endpoints)
define('RATE_LIMIT_GEMINI_CHAT_USER_ATTEMPTS', 25); // Max chat messages per logged-in user
define('RATE_LIMIT_GEMINI_CHAT_USER_WINDOW', 3600); // 1 hour
define('RATE_LIMIT_GEMINI_CHAT_IP_ATTEMPTS', 40); // Max chat attempts per IP (fallback)
define('RATE_LIMIT_GEMINI_CHAT_IP_WINDOW', 3600);
define('RATE_LIMIT_GEMINI_REPORT_ATTEMPTS', 12); // AI summary on Reports page
define('RATE_LIMIT_GEMINI_REPORT_WINDOW', 3600);
define('SESSION_TIMEOUT', 300); // 5 minutes
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', false); // Set to true for stronger passwords

// HTTPS: Force redirect HTTP -> HTTPS (localhost + live when SSL is available)
// Set to false for local development (lgu.test, localhost) to avoid SSL issues
if (!defined('FORCE_HTTPS')) {
    define('FORCE_HTTPS', false); // Set to true for production when SSL is configured
}
// Hosts to NEVER redirect to HTTPS (e.g. plain localhost without SSL). Comma-separated.
// Add your local development domains here (e.g. lgu.test, app.local, etc.)
if (!defined('HTTPS_EXCLUDE_HOSTS')) {
    define('HTTPS_EXCLUDE_HOSTS', 'localhost,127.0.0.1,lgu.test');
}

/**
 * Generate CSRF token
 */
function generateCSRFToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || 
        !isset($_SESSION[CSRF_TOKEN_NAME . '_expiry']) ||
        $_SESSION[CSRF_TOKEN_NAME . '_expiry'] < time()) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        $_SESSION[CSRF_TOKEN_NAME . '_expiry'] = time() + CSRF_TOKEN_EXPIRY;
    }
    
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }
    
    // Check expiry
    if (isset($_SESSION[CSRF_TOKEN_NAME . '_expiry']) && 
        $_SESSION[CSRF_TOKEN_NAME . '_expiry'] < time()) {
        unset($_SESSION[CSRF_TOKEN_NAME]);
        unset($_SESSION[CSRF_TOKEN_NAME . '_expiry']);
        return false;
    }
    
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Get CSRF token for forms
 */
function csrf_token(): string
{
    return generateCSRFToken();
}

/**
 * Output CSRF token as hidden input
 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Count active rate-limit records for an action + identifier.
 */
function frs_rate_limit_count(string $action, string $identifier): int
{
    require_once __DIR__ . '/database.php';
    $pdo = db();
    $pdo->exec('DELETE FROM rate_limits WHERE expires_at < NOW()');
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS attempts
         FROM rate_limits
         WHERE action = ? AND identifier = ? AND expires_at > NOW()'
    );
    $stmt->execute([$action, $identifier]);
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['attempts'] ?? 0);
}

/**
 * Record a rate-limit attempt (failed login, wrong verify code, etc.).
 */
function frs_record_rate_limit(string $action, string $identifier, int $windowSeconds): void
{
    require_once __DIR__ . '/database.php';
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (action, identifier, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    );
    $stmt->execute([$action, $identifier, $windowSeconds]);
}

/**
 * Rate limiting — check and optionally record in one call (legacy).
 */
function checkRateLimit(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
{
    if (frs_rate_limit_count($action, $identifier) >= $maxAttempts) {
        return false;
    }
    frs_record_rate_limit($action, $identifier, $windowSeconds);
    return true;
}

/**
 * True when identifier has exceeded max attempts (does not record).
 */
function frs_is_rate_limited(string $action, string $identifier, int $maxAttempts): bool
{
    return frs_rate_limit_count($action, $identifier) >= $maxAttempts;
}

/**
 * Check login rate limit (failed attempts only — does not record).
 */
function checkLoginRateLimit(string $email): bool
{
    return !frs_is_rate_limited('login', strtolower(trim($email)), RATE_LIMIT_LOGIN_ATTEMPTS);
}

/**
 * Record a failed login attempt against email rate limit.
 */
function recordLoginRateLimitFailure(string $email): void
{
    frs_record_rate_limit('login', strtolower(trim($email)), RATE_LIMIT_LOGIN_WINDOW);
}

/**
 * Email verification code brute-force protection (per pending user id).
 */
function checkEmailVerifyRateLimit(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    return !frs_is_rate_limited('email_verify', 'user:' . $userId, RATE_LIMIT_EMAIL_VERIFY_ATTEMPTS);
}

function recordEmailVerifyRateLimitFailure(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    frs_record_rate_limit('email_verify', 'user:' . $userId, RATE_LIMIT_EMAIL_VERIFY_WINDOW);
}

/**
 * Issue a new registration email verification code (expiry uses MySQL NOW() for clock consistency).
 */
function frs_issue_email_verification_code(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user id for email verification.');
    }

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $ttl = (int) EMAIL_VERIFICATION_CODE_TTL_SECONDS;

    $stmt = $pdo->prepare(
        "UPDATE users
         SET email_verification_code_hash = ?,
             email_verification_expires_at = DATE_ADD(NOW(), INTERVAL {$ttl} SECOND),
             email_verified = 0
         WHERE id = ?"
    );
    $stmt->execute([$hash, $userId]);

    return $code;
}

/**
 * Whether the pending user's verification code is still valid (MySQL clock).
 */
function frs_email_verification_code_is_valid(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM users
         WHERE id = ?
           AND email_verification_expires_at IS NOT NULL
           AND email_verification_expires_at >= NOW()
         LIMIT 1"
    );
    $stmt->execute([$userId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Seconds until the current verification code expires (0 if expired or missing).
 */
function frs_email_verification_remaining_seconds(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), email_verification_expires_at)) AS remaining
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['remaining'] ?? 0);
}

/**
 * Track when a verification email was last sent (resend cooldown).
 */
function frs_mark_email_verification_sent(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['email_verify_last_sent_at'] = time();
}

/**
 * Whether the user can request another verification email yet.
 */
function frs_can_resend_email_verification(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $lastSent = (int) ($_SESSION['email_verify_last_sent_at'] ?? 0);
    if ($lastSent <= 0) {
        return true;
    }

    return (time() - $lastSent) >= (int) EMAIL_VERIFICATION_RESEND_COOLDOWN_SECONDS;
}

/**
 * Generate, store, and email a registration verification code.
 */
function frs_send_email_verification(PDO $pdo, int $userId, string $email, string $name): void
{
    require_once __DIR__ . '/email_templates.php';
    require_once __DIR__ . '/mail_helper.php';

    $code = frs_issue_email_verification_code($pdo, $userId);
    $expiryMinutes = max(1, (int) ceil(((int) EMAIL_VERIFICATION_CODE_TTL_SECONDS) / 60));
    $body = getEmailVerificationEmailTemplate($name, $code, $expiryMinutes);
    sendEmail($email, $name, 'Verify Your Email Address', $body);
    frs_mark_email_verification_sent();
}

/**
 * Issue a new login email OTP (expiry uses MySQL NOW() for clock consistency).
 *
 * @return string Plain 6-digit OTP (caller sends via email/SMS).
 */
function frs_issue_login_otp_code(PDO $pdo, int $userId, ?string $lastLoginIp = null): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user id for login OTP.');
    }

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $ttl = (int) LOGIN_OTP_CODE_TTL_SECONDS;

    if ($lastLoginIp !== null) {
        $stmt = $pdo->prepare(
            "UPDATE users
             SET failed_login_attempts = 0,
                 locked_until = NULL,
                 otp_code_hash = ?,
                 otp_expires_at = DATE_ADD(NOW(), INTERVAL {$ttl} SECOND),
                 otp_attempts = 0,
                 otp_last_sent_at = NOW(),
                 last_login_ip = ?
             WHERE id = ?"
        );
        $stmt->execute([$hash, $lastLoginIp, $userId]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE users
             SET otp_code_hash = ?,
                 otp_expires_at = DATE_ADD(NOW(), INTERVAL {$ttl} SECOND),
                 otp_attempts = 0,
                 otp_last_sent_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$hash, $userId]);
    }

    return $code;
}

/**
 * Whether the user's login email OTP is still valid (MySQL clock).
 */
function frs_login_otp_code_is_valid(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM users
         WHERE id = ?
           AND otp_expires_at IS NOT NULL
           AND otp_expires_at >= NOW()
         LIMIT 1"
    );
    $stmt->execute([$userId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Seconds until the login OTP expires (0 if expired or missing).
 */
function frs_login_otp_remaining_seconds(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), otp_expires_at)) AS remaining
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['remaining'] ?? 0);
}

/**
 * Whether the user can request another login OTP yet (MySQL clock).
 */
function frs_can_resend_login_otp(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $cooldown = (int) LOGIN_OTP_RESEND_COOLDOWN_SECONDS;
    $stmt = $pdo->prepare(
        "SELECT (
            otp_last_sent_at IS NULL
            OR TIMESTAMPDIFF(SECOND, otp_last_sent_at, NOW()) >= {$cooldown}
         ) AS can_resend
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$userId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Admin/Staff must always complete a second factor at login.
 */
function frs_role_requires_two_factor(string $role): bool
{
    return in_array($role, ['Admin', 'Staff'], true);
}

/**
 * Dashboard session is valid only when both flags are set.
 */
function frs_dashboard_is_authenticated(): bool
{
    return !empty($_SESSION['user_authenticated']) && !empty($_SESSION['user_id']);
}

/**
 * Check registration rate limit with IP-based blocking.
 *
 * Behaviour:
 * - Track individual registration attempts as action = 'register' with a 1-hour expiry
 * - If more than RATE_LIMIT_REGISTER_ATTEMPTS attempts occur within the last hour from the same IP,
 *   create a 'register_block' record that blocks further registrations for RATE_LIMIT_REGISTER_BLOCK_WINDOW seconds (6 hours).
 */
function checkRegisterRateLimit(string $ip): bool
{
    require_once __DIR__ . '/database.php';
    $pdo = db();

    // First, check if this IP is currently blocked
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
         FROM rate_limits
         WHERE action = 'register_block'
           AND identifier = ?
           AND expires_at > NOW()"
    );
    $stmt->execute([$ip]);
    $blocked = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

    if ($blocked > 0) {
        return false; // Currently blocked
    }

    // Count registration attempts in the last RATE_LIMIT_REGISTER_WINDOW seconds (1 hour)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS attempts
         FROM rate_limits
         WHERE action = 'register'
           AND identifier = ?
           AND expires_at > NOW()"
    );
    $stmt->execute([$ip]);
    $attempts = (int)($stmt->fetch(PDO::FETCH_ASSOC)['attempts'] ?? 0);

    if ($attempts >= RATE_LIMIT_REGISTER_ATTEMPTS) {
        // Too many attempts in the window – create a block record for this IP
        $blockStmt = $pdo->prepare(
            "INSERT INTO rate_limits (action, identifier, expires_at)
             VALUES ('register_block', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
        );
        $blockStmt->execute([$ip, RATE_LIMIT_REGISTER_BLOCK_WINDOW]);
        return false;
    }

    // Record this registration attempt with a 1-hour expiry
    $attemptStmt = $pdo->prepare(
        "INSERT INTO rate_limits (action, identifier, expires_at)
         VALUES ('register', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    $attemptStmt->execute([$ip, RATE_LIMIT_REGISTER_WINDOW]);

    return true;
}

/**
 * Rate limit AI chatbot messages (per user, with IP fallback).
 */
function checkGeminiChatbotRateLimit(?int $userId = null): bool
{
    if ($userId !== null && $userId > 0) {
        return checkRateLimit(
            'gemini_chat_user',
            'user:' . $userId,
            RATE_LIMIT_GEMINI_CHAT_USER_ATTEMPTS,
            RATE_LIMIT_GEMINI_CHAT_USER_WINDOW
        );
    }

    return checkRateLimit(
        'gemini_chat_ip',
        'ip:' . getClientIP(),
        RATE_LIMIT_GEMINI_CHAT_IP_ATTEMPTS,
        RATE_LIMIT_GEMINI_CHAT_IP_WINDOW
    );
}

/**
 * Rate limit Reports page AI summary generation (Admin/Staff).
 */
function checkGeminiReportSummaryRateLimit(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    return checkRateLimit(
        'gemini_report',
        'user:' . $userId,
        RATE_LIMIT_GEMINI_REPORT_ATTEMPTS,
        RATE_LIMIT_GEMINI_REPORT_WINDOW
    );
}

/**
 * Get client IP address
 */
function getClientIP(): string
{
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Validate and sanitize input
 */
function sanitizeInput(string $input, string $type = 'string'): mixed
{
    switch ($type) {
        case 'email':
            return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
        case 'int':
            return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'url':
            return filter_var(trim($input), FILTER_SANITIZE_URL);
        case 'string':
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Validate password strength
 */
function validatePassword(string $password): array
{
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long.";
    }
    
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }
    
    return $errors;
}

/**
 * Secure session configuration
 */
function secureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isHTTPS() ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
        // Use Lax so session cookie is preserved on top-level returns from payment providers (e.g., PayMongo).
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
        
        session_start();
    }

    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 300) { // 5 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }

    // Check session timeout (applies even if session was already started elsewhere)
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return;
    }

    $_SESSION['last_activity'] = time();
}

/**
 * Check if request is HTTPS
 */
function isHTTPS(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (int)($_SERVER['SERVER_PORT'] ?? 0) === 443 ||
           (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Set security headers
 */
function setSecurityHeaders(): void
{
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // XSS Protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy (adjust as needed)
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://static.cloudflareinsights.com https://challenges.cloudflare.com; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://fonts.gstatic.com; " .
           "img-src 'self' data: https:; " .
           "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
           "frame-src 'self' https://www.google.com https://maps.google.com https://challenges.cloudflare.com; " .
           "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cloudflareinsights.com https://challenges.cloudflare.com;";
    header("Content-Security-Policy: $csp");
    
    // Permissions Policy
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    // Force HTTPS in production (uncomment when deploying with SSL certificate)
    // NOTE: Uncomment this after obtaining SSL certificate and configuring HTTPS
    // if (!isHTTPS() && $_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1') {
    //     header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    //     exit;
    // }
}

/**
 * Validate file upload
 */
function validateFileUpload(array $file, array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 
                            int $maxSize = 5242880): array // 5MB default
{
    $errors = [];
    
    // Check if file was uploaded
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error occurred.";
        return $errors;
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        $errors[] = "File size exceeds maximum allowed size of " . ($maxSize / 1024 / 1024) . "MB.";
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "Invalid file type. Allowed types: " . implode(', ', $allowedTypes);
    }
    
    // Additional security: Check file extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    if (!in_array($ext, $allowedExts)) {
        $errors[] = "Invalid file extension.";
    }
    
    // Check for PHP code in file (basic check)
    $content = file_get_contents($file['tmp_name'], false, null, 0, 1024);
    if (preg_match('/<\?php|<\?=|<script/i', $content)) {
        $errors[] = "File contains potentially malicious content.";
    }
    
    return $errors;
}

/**
 * Log security event
 */
function logSecurityEvent(string $event, string $details = '', string $severity = 'info'): void
{
    require_once __DIR__ . '/database.php';
    $pdo = db();
    
    $stmt = $pdo->prepare(
        "INSERT INTO security_logs (event, details, severity, ip_address, user_agent, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    
    $stmt->execute([
        $event,
        $details,
        $severity,
        getClientIP(),
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);
}

/**
 * Escape output for HTML
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Read CSRF token from POST body or X-CSRF-Token header (AJAX).
 */
/**
 * Validate a post-login redirect path (blocks open redirects like //evil.com).
 */
function frs_safe_redirect_path(?string $candidate): ?string
{
    if ($candidate === null) {
        return null;
    }
    $path = trim($candidate);
    if ($path === '' || !str_starts_with($path, '/')) {
        return null;
    }
    if (str_starts_with($path, '//')) {
        return null;
    }
    if (preg_match('/[\x00-\x1F\x7F\\\\@]/', $path)) {
        return null;
    }
    $parsed = parse_url($path);
    if (!is_array($parsed) || isset($parsed['host']) || isset($parsed['scheme'])) {
        return null;
    }

    return $path;
}

function frs_request_csrf_token(): string
{
    $fromPost = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (is_string($fromPost) && $fromPost !== '') {
        return $fromPost;
    }
    $fromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($fromHeader) ? $fromHeader : '';
}

/**
 * Verify CSRF on POST requests. Returns false and sets flash when invalid.
 *
 * @param array{message?: string, messageType?: string}|null $flashVars
 */
function frs_verify_post_csrf(?array &$flashVars = null): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    if (verifyCSRFToken(frs_request_csrf_token())) {
        return true;
    }
    if ($flashVars !== null) {
        $flashVars['message'] = 'Your session expired or the form is invalid. Please refresh and try again.';
        $flashVars['messageType'] = 'error';
    }
    return false;
}

/**
 * Append CSRF field to application/x-www-form-urlencoded body.
 */
function frs_post_body_with_csrf(string $body = ''): string
{
    $params = [];
    if ($body !== '') {
        parse_str($body, $params);
    }
    $params[CSRF_TOKEN_NAME] = csrf_token();
    return http_build_query($params);
}

/**
 * Exit with 403 when POST CSRF token is invalid (for JSON APIs).
 */
function frs_reject_invalid_csrf_json(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (verifyCSRFToken(frs_request_csrf_token())) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or missing security token. Refresh the page and try again.',
    ]);
    exit;
}

/**
 * True when request is not POST or CSRF token is valid.
 */
function frs_csrf_ok(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    return verifyCSRFToken(frs_request_csrf_token());
}

