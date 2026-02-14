<?php
/**
 * Security Configuration and Helpers
 * 
 * Provides CSRF protection, rate limiting, input validation, and security headers
 */

// Security Configuration
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour
define('RATE_LIMIT_LOGIN_ATTEMPTS', 5); // Max login attempts
define('RATE_LIMIT_LOGIN_WINDOW', 900); // 15 minutes in seconds
define('RATE_LIMIT_REGISTER_ATTEMPTS', 3); // Max registration attempts
define('RATE_LIMIT_REGISTER_WINDOW', 3600); // 1 hour in seconds
define('SESSION_TIMEOUT', 1800); // 30 minutes
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
 * Rate limiting - Check if action is rate limited
 */
function checkRateLimit(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
{
    require_once __DIR__ . '/database.php';
    $pdo = db();
    
    // Clean old entries
    $pdo->exec("DELETE FROM rate_limits WHERE expires_at < NOW()");
    
    // Check current attempts
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) as attempts 
         FROM rate_limits 
         WHERE action = ? AND identifier = ? AND expires_at > NOW()"
    );
    $stmt->execute([$action, $identifier]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $attempts = (int)($result['attempts'] ?? 0);
    
    if ($attempts >= $maxAttempts) {
        return false; // Rate limited
    }
    
    // Record this attempt
    $stmt = $pdo->prepare(
        "INSERT INTO rate_limits (action, identifier, expires_at) 
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    $stmt->execute([$action, $identifier, $windowSeconds]);
    
    return true; // Not rate limited
}

/**
 * Check login rate limit
 */
function checkLoginRateLimit(string $email): bool
{
    return checkRateLimit('login', $email, RATE_LIMIT_LOGIN_ATTEMPTS, RATE_LIMIT_LOGIN_WINDOW);
}

/**
 * Check registration rate limit
 */
function checkRegisterRateLimit(string $ip): bool
{
    return checkRateLimit('register', $ip, RATE_LIMIT_REGISTER_ATTEMPTS, RATE_LIMIT_REGISTER_WINDOW);
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
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
        
        session_start();
        
        // Regenerate session ID periodically to prevent fixation
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 300) { // 5 minutes
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
        
        // Check session timeout
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            session_start();
        }
        
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Check if request is HTTPS
 */
function isHTTPS(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           $_SERVER['SERVER_PORT'] == 443 ||
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
           "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://fonts.gstatic.com; " .
           "img-src 'self' data: https:; " .
           "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
           "frame-src 'self' https://www.google.com https://maps.google.com; " .
           "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cloudflareinsights.com;";
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

