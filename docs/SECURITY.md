# Security Features Documentation

This document outlines all security features implemented in the Facilities Reservation System.

## Table of Contents
1. [Authentication & Authorization](#authentication--authorization)
2. [CSRF Protection](#csrf-protection)
3. [Rate Limiting](#rate-limiting)
4. [Input Validation & Sanitization](#input-validation--sanitization)
5. [Password Security](#password-security)
6. [Session Security](#session-security)
7. [File Upload Security](#file-upload-security)
8. [SQL Injection Prevention](#sql-injection-prevention)
9. [XSS Protection](#xss-protection)
10. [Security Headers](#security-headers)
11. [Account Lockout](#account-lockout)
12. [Security Logging](#security-logging)

---

## Authentication & Authorization

### Features
- **Password Hashing**: Uses PHP's `password_hash()` with `PASSWORD_DEFAULT` (bcrypt)
- **Password Verification**: Uses `password_verify()` for secure password checking
- **Role-Based Access Control (RBAC)**: Admin, Staff, and Resident roles
- **Account Status**: Users must have `status = 'active'` to log in
- **Session-Based Authentication**: Secure session management

### Implementation
- Login credentials are verified against the database
- Session variables store user information securely
- Protected pages check `$_SESSION['user_authenticated']`
- Role-based access enforced on sensitive pages

---

## CSRF Protection

### Features
- **CSRF Tokens**: Generated for each form submission
- **Token Expiry**: Tokens expire after 1 hour
- **Token Verification**: All POST requests verify CSRF tokens
- **Automatic Token Generation**: Tokens generated automatically via `csrf_field()`

### Usage
```php
// In forms
<?= csrf_field(); ?>

// Verify in POST handlers
if (!verifyCSRFToken($_POST['csrf_token'])) {
    // Handle error
}
```

### Implementation
- Tokens stored in session with expiry time
- Tokens regenerated periodically
- Failed CSRF attempts are logged

---

## Rate Limiting

### Features
- **Login Rate Limiting**: 5 attempts per 15 minutes per email
- **Registration Rate Limiting**: 3 attempts per hour per IP
- **Automatic Cleanup**: Old rate limit entries are automatically removed
- **Database-Based**: Rate limits stored in `rate_limits` table

### Configuration
```php
define('RATE_LIMIT_LOGIN_ATTEMPTS', 5);
define('RATE_LIMIT_LOGIN_WINDOW', 900); // 15 minutes
define('RATE_LIMIT_REGISTER_ATTEMPTS', 3);
define('RATE_LIMIT_REGISTER_WINDOW', 3600); // 1 hour
```

### Implementation
- Rate limits tracked by action and identifier (email/IP)
- Failed attempts logged to security logs
- Users see friendly error messages when rate limited

---

## Input Validation & Sanitization

### Features
- **Input Sanitization**: All user inputs are sanitized
- **Type-Specific Validation**: Email, integer, float, URL, string
- **XSS Prevention**: HTML entities encoded in output
- **SQL Injection Prevention**: Prepared statements used throughout

### Functions
```php
sanitizeInput($input, 'email'); // Sanitize email
sanitizeInput($input, 'int');   // Sanitize integer
e($string);                      // Escape for HTML output
```

### Implementation
- Input sanitized before database operations
- Output escaped with `htmlspecialchars()` or `e()`
- Validation performed on both client and server side

---

## Password Security

### Features
- **Minimum Length**: 8 characters (configurable)
- **Complexity Requirements**:
  - At least one uppercase letter
  - At least one lowercase letter
  - At least one number
  - Special characters (optional, configurable)
- **Password Hashing**: bcrypt with automatic salt
- **Password Verification**: Secure comparison using `password_verify()`

### Configuration
```php
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', false);
```

### Validation
- Passwords validated on registration
- Clear error messages for password requirements
- Passwords never stored in plain text

---

## Session Security

### Features
- **Secure Cookies**: HttpOnly, Secure (in HTTPS), SameSite=Strict
- **Session Regeneration**: Session ID regenerated periodically and on login
- **Session Timeout**: 30 minutes of inactivity
- **Strict Mode**: Prevents session fixation attacks
- **Last Activity Tracking**: Monitors user activity

### Configuration
```php
define('SESSION_TIMEOUT', 1800); // 30 minutes
```

### Implementation
- Session ID regenerated every 5 minutes
- Sessions expire after 30 minutes of inactivity
- Secure cookie flags set automatically
- Session data cleared on timeout

---

## File Upload Security

### Features
- **File Type Validation**: MIME type and extension checking
- **File Size Limits**: 5MB maximum (configurable)
- **Malicious Content Detection**: Scans for PHP code and scripts
- **Secure File Names**: Sanitized filenames prevent directory traversal
- **File Permissions**: Uploaded files set to 0644 (read-only for web server)

### Allowed Types
- Images: JPEG, PNG, GIF, WEBP
- Documents: PDF (for registration documents)

### Validation Process
1. Check file upload error
2. Validate file size
3. Verify MIME type
4. Check file extension
5. Scan for malicious content
6. Sanitize filename
7. Set secure permissions

### Implementation
```php
$errors = validateFileUpload($_FILES['file'], $allowedTypes, $maxSize);
if (empty($errors)) {
    // Safe to process
}
```

---

## SQL Injection Prevention

### Features
- **Prepared Statements**: All database queries use PDO prepared statements
- **Parameter Binding**: User inputs bound as parameters
- **No String Concatenation**: SQL queries never built with string concatenation
- **Type Casting**: Integer inputs cast to integers before binding

### Implementation
```php
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]); // Safe - parameter binding
```

### Best Practices
- Never use `$_POST` or `$_GET` directly in queries
- Always use prepared statements
- Validate and sanitize inputs before binding
- Use parameter binding for all user inputs

---

## XSS Protection

### Features
- **Output Escaping**: All user-generated content escaped
- **HTML Encoding**: `htmlspecialchars()` used throughout
- **Helper Function**: `e()` function for easy escaping
- **Context-Aware**: Proper encoding for HTML attributes and content

### Implementation
```php
// In templates
<?= e($userInput); ?>
<?= htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); ?>
```

### Best Practices
- Escape all output
- Use `e()` helper function
- Never trust user input
- Validate on input, escape on output

---

## Security Headers

### Features
- **X-Frame-Options**: Prevents clickjacking (SAMEORIGIN)
- **X-XSS-Protection**: Enables browser XSS filter
- **X-Content-Type-Options**: Prevents MIME type sniffing
- **Referrer-Policy**: Controls referrer information
- **Content-Security-Policy**: Restricts resource loading
- **Permissions-Policy**: Restricts browser features

### Headers Set
```
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; ...
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

### Implementation
- Headers set via PHP `header()` function
- Also configured in `.htaccess` for Apache
- CSP adjusted for CDN resources (Chart.js)

---

## Account Lockout

### Features
- **Failed Attempt Tracking**: Tracks failed login attempts per user
- **Automatic Lockout**: Account locked after 5 failed attempts
- **Lock Duration**: 30 minutes
- **Automatic Unlock**: Account unlocks after lock duration
- **Login Attempt Logging**: All login attempts logged

### Implementation
- Failed attempts stored in `users.failed_login_attempts`
- Lock expiry stored in `users.locked_until`
- Login attempts logged in `login_attempts` table
- Successful login resets failed attempts counter

### Database Schema
```sql
ALTER TABLE users
    ADD COLUMN failed_login_attempts INT UNSIGNED DEFAULT 0,
    ADD COLUMN locked_until DATETIME NULL;
```

---

## Security Logging

### Features
- **Security Events**: All security events logged
- **Event Types**: info, warning, error, critical
- **IP Tracking**: Client IP addresses logged
- **User Agent Tracking**: Browser information logged
- **User Association**: Events linked to user accounts (when applicable)

### Logged Events
- Login attempts (success/failure)
- Account lockouts
- CSRF validation failures
- Rate limit violations
- Registration attempts
- Security errors

### Database Schema
```sql
CREATE TABLE security_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(100) NOT NULL,
    details TEXT NULL,
    severity ENUM('info', 'warning', 'error', 'critical'),
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Usage
```php
logSecurityEvent('login_success', "User logged in: $email", 'info');
logSecurityEvent('csrf_validation_failed', 'Login form', 'warning');
```

---

## Deployment Security Checklist

### Before Going Live

1. **Database Security**
   - [ ] Change default database credentials
   - [ ] Use strong database passwords
   - [ ] Restrict database user permissions
   - [ ] Enable SSL for database connections (if remote)

2. **Application Security**
   - [ ] Update `config/database.php` with production credentials
   - [ ] Enable HTTPS (uncomment HTTPS redirect in `.htaccess`)
   - [ ] Set `session.cookie_secure = 1` in production
   - [ ] Disable error display in production
   - [ ] Review and adjust CSP headers as needed

3. **File Permissions**
   - [ ] Set upload directories to 755
   - [ ] Set uploaded files to 644
   - [ ] Ensure config files are not web-accessible

4. **Server Configuration**
   - [ ] Enable HTTPS with valid SSL certificate
   - [ ] Configure firewall rules
   - [ ] Set up regular backups
   - [ ] Enable server-side logging
   - [ ] Review Apache/PHP security settings

5. **Monitoring**
   - [ ] Set up security log monitoring
   - [ ] Configure alerts for critical events
   - [ ] Regular security log reviews
   - [ ] Monitor failed login attempts

---

## Security Configuration Files

### Files Created
- `config/security.php` - Security functions and configuration
- `.htaccess` - Apache security headers and protections
- `database/migration_add_security_tables.sql` - Security database tables

### Files Modified
- `config/app.php` - Added security initialization
- `resources/views/pages/auth/login.php` - Enhanced with security features
- `resources/views/pages/auth/register.php` - Enhanced with security features
- `resources/views/pages/dashboard/facility_management.php` - Enhanced file upload security

---

## Security Best Practices

1. **Never trust user input** - Always validate and sanitize
2. **Use prepared statements** - Never concatenate SQL queries
3. **Escape all output** - Prevent XSS attacks
4. **Keep dependencies updated** - Regular security updates
5. **Monitor security logs** - Review logs regularly
6. **Use HTTPS in production** - Encrypt all traffic
7. **Regular backups** - Protect against data loss
8. **Strong passwords** - Enforce password policies
9. **Limit access** - Principle of least privilege
10. **Stay informed** - Keep up with security best practices

---

## Reporting Security Issues

If you discover a security vulnerability, please:
1. Do not disclose it publicly
2. Report it to the system administrator
3. Provide detailed information about the issue
4. Allow time for the issue to be addressed

---

## Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)

---

**Last Updated**: 2024
**Version**: 1.0

