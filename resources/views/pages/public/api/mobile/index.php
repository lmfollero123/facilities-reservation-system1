<?php
/**
 * Resident Companion Mobile API router — /api/mobile/v1/*
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__, 6) . '/config/app.php';
require_once dirname(__DIR__, 6) . '/config/database.php';
require_once dirname(__DIR__, 6) . '/config/security.php';
require_once dirname(__DIR__, 6) . '/config/mobile_auth.php';

/**
 * @param array<string, mixed> $data
 */
function mobile_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<string, mixed> $extra
 */
function mobile_error(string $message, int $code = 400, string $error = 'bad_request', array $extra = []): void
{
    mobile_json(array_merge(['ok' => false, 'error' => $error, 'message' => $message], $extra), $code);
}

function mobile_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST ?: [];
}

function mobile_require_user(PDO $pdo): array
{
    $user = frs_mobile_bearer_user($pdo);
    if (!$user) {
        mobile_error('Unauthorized. Please sign in again.', 401, 'unauthorized');
    }
    return $user;
}

function mobile_facility_image_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    // Absolute URL required by the Flutter Image.network client.
    $origin = function_exists('base_url') ? rtrim((string) base_url(), '/') : '';
    if ($origin === '' && function_exists('base_path')) {
        $origin = rtrim((string) base_path(), '/');
    }
    return $origin . '/' . ltrim($path, '/');
}

/**
 * @param array<string, mixed> $f
 * @return array<string, mixed>
 */
function mobile_serialize_facility(array $f): array
{
    return [
        'id' => (int) $f['id'],
        'name' => (string) $f['name'],
        'description' => $f['description'] ?? null,
        'location' => $f['location'] ?? null,
        'capacity' => $f['capacity'] ?? null,
        'amenities' => $f['amenities'] ?? null,
        'rules' => $f['rules'] ?? null,
        'status' => (string) ($f['status'] ?? 'available'),
        'operating_hours' => $f['operating_hours'] ?? null,
        'image_url' => mobile_facility_image_url($f['image_path'] ?? null),
        'is_free' => isset($f['is_free']) ? (bool) $f['is_free'] : true,
        'base_rate' => isset($f['base_rate'])
            ? (float) preg_replace('/[^\d]/', '', (string) $f['base_rate'])
            : null,
    ];
}

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function mobile_serialize_reservation(array $r): array
{
    $isFree = null;
    if (array_key_exists('is_free', $r)) {
        $isFree = !empty($r['is_free']);
    }
    $amount = null;
    if (isset($r['base_rate'])) {
        $normalized = preg_replace('/[^\d]/', '', (string) $r['base_rate']);
        $amount = $normalized !== '' ? (float) ((int) $normalized) : 0.0;
        if ($amount <= 0 && $isFree === false) {
            $amount = 50.0;
        }
        if ($isFree === true) {
            $amount = 0.0;
        }
    }
    $paymentStatus = $r['payment_status'] ?? null;

    return [
        'id' => (int) $r['id'],
        'facility_id' => (int) ($r['facility_id'] ?? 0),
        'facility_name' => (string) ($r['facility_name'] ?? ''),
        'reservation_date' => (string) ($r['reservation_date'] ?? ''),
        'time_slot' => (string) ($r['time_slot'] ?? ''),
        'purpose' => (string) ($r['purpose'] ?? ''),
        'status' => (string) ($r['status'] ?? ''),
        'expected_attendees' => isset($r['expected_attendees']) ? (int) $r['expected_attendees'] : null,
        'is_free' => $isFree,
        'amount' => $amount,
        'currency' => 'PHP',
        'payment_due_at' => $r['payment_due_at'] ?? null,
        'payment_status' => $paymentStatus,
        'reschedule_count' => isset($r['reschedule_count']) ? (int) $r['reschedule_count'] : null,
        'created_at' => $r['created_at'] ?? null,
        'updated_at' => $r['updated_at'] ?? null,
    ];
}

/**
 * @return array<string, mixed>
 */
function mobile_load_reservation(PDO $pdo, int $id, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, f.name AS facility_name, f.is_free, f.base_rate, f.capacity, f.status AS facility_status
         FROM reservations r
         JOIN facilities f ON f.id = r.facility_id
         WHERE r.id = ? AND r.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    try {
        $p = $pdo->prepare(
            'SELECT status FROM payments WHERE reservation_id = ? ORDER BY id DESC LIMIT 1'
        );
        $p->execute([$id]);
        $ps = $p->fetchColumn();
        if ($ps !== false) {
            $row['payment_status'] = (string) $ps;
        }
    } catch (Throwable $e) {
        // payments table optional on older DBs
    }
    return $row;
}

// Resolve path after /api/mobile/v1
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$pathOnly = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$pathOnly = rawurldecode($pathOnly);

if (!preg_match('#/api/mobile/v1(?:/|$)(.*)$#i', $pathOnly, $m)) {
    mobile_error('Not found', 404, 'not_found');
}
$route = trim($m[1], '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    $pdo = db();
} catch (Throwable $e) {
    mobile_error('Database unavailable', 503, 'db_error');
}

// ---------- AUTH ----------
if ($route === 'auth/login' && $method === 'POST') {
    $body = mobile_body();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $deviceName = isset($body['device_name']) ? substr((string) $body['device_name'], 0, 120) : null;

    if ($email === '' || $password === '') {
        mobile_error('Email and password are required.', 422, 'validation');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        mobile_error('Invalid email or password.', 401, 'invalid_credentials');
    }
    if (($user['role'] ?? '') !== 'Resident') {
        mobile_error('This app is for residents only. Please use the website for staff/admin access.', 403, 'role_forbidden');
    }
    if (($user['status'] ?? '') !== 'active') {
        mobile_error('Your account is not active yet.', 403, 'inactive');
    }
    if (isset($user['email_verified']) && !(int) $user['email_verified']) {
        mobile_error('Please verify your email on the website before using the app.', 403, 'email_unverified');
    }

    $needsOtp = function_exists('frs_login_requires_second_factor') && frs_login_requires_second_factor($user);
    if ($needsOtp) {
        $plainOtp = null;
        if (function_exists('frs_issue_login_otp_code')) {
            try {
                $plainOtp = frs_issue_login_otp_code($pdo, (int) $user['id']);
                if ($plainOtp && file_exists(dirname(__DIR__, 6) . '/config/mail_helper.php')) {
                    require_once dirname(__DIR__, 6) . '/config/mail_helper.php';
                    if (function_exists('sendEmail')) {
                        @sendEmail(
                            (string) $user['email'],
                            (string) ($user['name'] ?? 'Resident'),
                            'Your Culiat login verification code',
                            '<p>Your verification code is <strong>' . htmlspecialchars($plainOtp) . '</strong>.</p><p>It expires shortly.</p>'
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('Mobile OTP issue: ' . $e->getMessage());
            }
        }
        $challengeId = frs_mobile_create_otp_challenge($pdo, (int) $user['id'], 'login');
        mobile_json([
            'ok' => true,
            'otp_required' => true,
            'challenge_id' => $challengeId,
            'message' => 'A verification code was sent to your email.',
            'masked_email' => function_exists('frs_mask_email_for_display')
                ? frs_mask_email_for_display((string) $user['email'])
                : (string) $user['email'],
        ]);
    }

    $tokens = frs_mobile_issue_token_pair($pdo, $user, $deviceName);
    mobile_json(array_merge(['ok' => true, 'otp_required' => false], $tokens));
}

if ($route === 'auth/verify-otp' && $method === 'POST') {
    $body = mobile_body();
    $challengeId = trim((string) ($body['challenge_id'] ?? ''));
    $code = trim((string) ($body['code'] ?? $body['otp'] ?? ''));
    $deviceName = isset($body['device_name']) ? substr((string) $body['device_name'], 0, 120) : null;

    if ($challengeId === '' || $code === '') {
        mobile_error('challenge_id and code are required.', 422, 'validation');
    }

    $challenge = frs_mobile_find_otp_challenge($pdo, $challengeId);
    if (!$challenge) {
        mobile_error(
            'This verification session is no longer valid. Go back or request a new code.',
            401,
            'invalid_challenge'
        );
    }
    if (strtotime((string) ($challenge['expires_at'] ?? '')) <= time()) {
        mobile_error(
            'Your verification code has expired. Tap Resend code to get a new one.',
            401,
            'otp_expired'
        );
    }

    $userId = (int) $challenge['user_id'];
    $otpOk = false;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        mobile_error('User not found.', 404, 'not_found');
    }
    if (!empty($user['otp_code_hash'])
        && function_exists('frs_login_otp_code_is_valid')
        && frs_login_otp_code_is_valid($pdo, $userId)
        && password_verify($code, (string) $user['otp_code_hash'])
    ) {
        $otpOk = true;
    }
    if (!$otpOk && function_exists('env_value') && env_value('MOBILE_OTP_DEV_ACCEPT', '0') === '1' && preg_match('/^\d{6}$/', $code)) {
        $otpOk = true;
    }

    if (!$otpOk) {
        // Keep the same challenge so the user can retry without getting stuck.
        mobile_error('Invalid verification code. Please try again or resend.', 401, 'invalid_otp');
    }

    if (($user['role'] ?? '') !== 'Resident') {
        mobile_error('Residents only.', 403, 'role_forbidden');
    }

    frs_mobile_mark_otp_challenge_consumed($pdo, (int) $challenge['id']);
    $tokens = frs_mobile_issue_token_pair($pdo, $user, $deviceName);
    mobile_json(array_merge(['ok' => true], $tokens));
}

if ($route === 'auth/resend-otp' && $method === 'POST') {
    $body = mobile_body();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $priorChallenge = trim((string) ($body['challenge_id'] ?? ''));

    $user = null;
    if ($email !== '' && $password !== '') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            mobile_error('Invalid email or password.', 401, 'invalid_credentials');
        }
    } elseif ($priorChallenge !== '') {
        $challenge = frs_mobile_find_otp_challenge($pdo, $priorChallenge);
        // Allow resend even if the prior challenge already expired.
        if (!$challenge && frs_mobile_table_exists($pdo, 'mobile_otp_challenges')) {
            $st = $pdo->prepare(
                'SELECT * FROM mobile_otp_challenges WHERE challenge_id = ? ORDER BY id DESC LIMIT 1'
            );
            $st->execute([$priorChallenge]);
            $challenge = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$challenge) {
            mobile_error('Unable to resend. Please sign in again.', 401, 'invalid_challenge');
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $challenge['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        mobile_error('email+password or challenge_id is required.', 422, 'validation');
    }

    if (!$user || ($user['role'] ?? '') !== 'Resident' || ($user['status'] ?? '') !== 'active') {
        mobile_error('Unable to resend verification code.', 403, 'forbidden');
    }

    // Invalidate prior open login challenges for this user.
    if (frs_mobile_table_exists($pdo, 'mobile_otp_challenges')) {
        $pdo->prepare(
            'UPDATE mobile_otp_challenges SET consumed_at = NOW()
             WHERE user_id = ? AND purpose = "login" AND consumed_at IS NULL'
        )->execute([(int) $user['id']]);
    }

    $plainOtp = null;
    if (function_exists('frs_issue_login_otp_code')) {
        try {
            $plainOtp = frs_issue_login_otp_code($pdo, (int) $user['id']);
            if ($plainOtp && file_exists(dirname(__DIR__, 6) . '/config/mail_helper.php')) {
                require_once dirname(__DIR__, 6) . '/config/mail_helper.php';
                if (function_exists('sendEmail')) {
                    @sendEmail(
                        (string) $user['email'],
                        (string) ($user['name'] ?? 'Resident'),
                        'Your Culiat login verification code',
                        '<p>Your new verification code is <strong>' . htmlspecialchars($plainOtp) . '</strong>.</p><p>It expires shortly.</p>'
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('Mobile OTP resend: ' . $e->getMessage());
        }
    }

    $challengeId = frs_mobile_create_otp_challenge($pdo, (int) $user['id'], 'login');
    mobile_json([
        'ok' => true,
        'otp_required' => true,
        'challenge_id' => $challengeId,
        'message' => 'A new verification code was sent to your email.',
        'masked_email' => function_exists('frs_mask_email_for_display')
            ? frs_mask_email_for_display((string) $user['email'])
            : (string) $user['email'],
    ]);
}

if ($route === 'auth/refresh' && $method === 'POST') {
    $body = mobile_body();
    $refresh = (string) ($body['refresh_token'] ?? '');
    if ($refresh === '') {
        mobile_error('refresh_token required.', 422, 'validation');
    }
    $tokens = frs_mobile_rotate_refresh($pdo, $refresh);
    if (!$tokens) {
        mobile_error('Invalid or expired refresh token.', 401, 'invalid_refresh');
    }
    mobile_json(array_merge(['ok' => true], $tokens));
}

if ($route === 'auth/logout' && $method === 'POST') {
    $body = mobile_body();
    $refresh = (string) ($body['refresh_token'] ?? '');
    if ($refresh !== '') {
        frs_mobile_revoke_refresh($pdo, $refresh);
    }
    mobile_json(['ok' => true, 'message' => 'Logged out.']);
}

if ($route === 'auth/forgot-password' && $method === 'POST') {
    $body = mobile_body();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mobile_error('Valid email required.', 422, 'validation');
    }
    $stmt = $pdo->prepare('SELECT id, email, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // Always generic response
    if ($user && ($user['role'] ?? '') === 'Resident') {
        try {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([(int) $user['id']]);
            $pdo->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
            )->execute([(int) $user['id'], $hash]);
            if (file_exists(dirname(__DIR__, 6) . '/config/mail_helper.php')) {
                require_once dirname(__DIR__, 6) . '/config/mail_helper.php';
                require_once dirname(__DIR__, 6) . '/config/email_templates.php';
                $resetUrl = rtrim(base_path(), '/') . '/reset-password?token=' . urlencode($token);
                if (function_exists('sendEmail') && function_exists('getPasswordResetEmailTemplate')) {
                    $html = getPasswordResetEmailTemplate('Resident', $resetUrl);
                    @sendEmail((string) $user['email'], 'Resident', 'Password Reset Request', (string) $html);
                }
            }
        } catch (Throwable $e) {
            error_log('Mobile forgot-password: ' . $e->getMessage());
        }
    }
    mobile_json(['ok' => true, 'message' => 'If that email is registered, a reset link was sent.']);
}

if ($route === 'auth/reset-password' && $method === 'POST') {
    $body = mobile_body();
    $token = trim((string) ($body['token'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($token === '' || strlen($password) < 8) {
        mobile_error('Token and password (min 8 chars) are required.', 422, 'validation');
    }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT * FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        mobile_error('Invalid or expired reset token.', 400, 'invalid_token');
    }
    $pwdHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$pwdHash, (int) $row['user_id']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
        ->execute([(int) $row['id']]);
    mobile_json(['ok' => true, 'message' => 'Password updated. You can sign in now.']);
}

if ($route === 'auth/register' && $method === 'POST') {
    $body = mobile_body();
    $name = trim((string) ($body['name'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $mobile = trim((string) ($body['mobile'] ?? ''));
    $address = trim((string) ($body['address'] ?? ''));

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        mobile_error('Name, valid email, and password (min 8) are required.', 422, 'validation');
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        mobile_error('An account with that email already exists.', 409, 'email_taken');
    }

    $pwdHash = password_hash($password, PASSWORD_DEFAULT);
    // Minimal resident registration — may require email verification via website
    try {
        $cols = ['name', 'email', 'password_hash', 'role', 'status'];
        $vals = [$name, $email, $pwdHash, 'Resident', 'pending'];
        // optional columns
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'mobile'");
        if ($check && $check->fetch()) {
            $cols[] = 'mobile';
            $vals[] = $mobile !== '' ? $mobile : null;
        }
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'address'");
        if ($check && $check->fetch()) {
            $cols[] = 'address';
            $vals[] = $address !== '' ? $address : null;
        }
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO users (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')')
            ->execute($vals);
    } catch (Throwable $e) {
        error_log('Mobile register: ' . $e->getMessage());
        mobile_error('Registration failed. Please try the website.', 500, 'register_failed');
    }

    mobile_json([
        'ok' => true,
        'message' => 'Account created. Please verify your email / await activation, then sign in.',
        'requires_activation' => true,
    ], 201);
}

// ---------- ME ----------
if ($route === 'me' && $method === 'GET') {
    $user = mobile_require_user($pdo);
    mobile_json(['ok' => true, 'user' => frs_mobile_user_public($user)]);
}

if ($route === 'me' && in_array($method, ['PATCH', 'PUT'], true)) {
    $user = mobile_require_user($pdo);
    $body = mobile_body();
    $name = isset($body['name']) ? trim((string) $body['name']) : null;
    $mobile = array_key_exists('mobile', $body) ? trim((string) $body['mobile']) : null;
    $address = array_key_exists('address', $body) ? trim((string) $body['address']) : null;
    $sets = [];
    $params = [];
    if ($name !== null && $name !== '') {
        $sets[] = 'name = ?';
        $params[] = $name;
    }
    if ($mobile !== null) {
        $sets[] = 'mobile = ?';
        $params[] = $mobile;
    }
    if ($address !== null) {
        $sets[] = 'address = ?';
        $params[] = $address;
    }
    if ($sets === []) {
        mobile_error('No fields to update.', 422, 'validation');
    }
    $params[] = (int) $user['id'];
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?')->execute($params);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    mobile_json(['ok' => true, 'user' => frs_mobile_user_public($stmt->fetch(PDO::FETCH_ASSOC) ?: $user)]);
}

if ($route === 'me/password' && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $body = mobile_body();
    $current = (string) ($body['current_password'] ?? '');
    $next = (string) ($body['new_password'] ?? '');
    if ($current === '' || strlen($next) < 8) {
        mobile_error('current_password and new_password (min 8) required.', 422, 'validation');
    }
    if (!password_verify($current, (string) $user['password_hash'])) {
        mobile_error('Current password is incorrect.', 401, 'invalid_password');
    }
    $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
        ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);
    mobile_json(['ok' => true, 'message' => 'Password changed.']);
}

if ($route === 'me/preferences' && $method === 'GET') {
    $user = mobile_require_user($pdo);
    require_once dirname(__DIR__, 6) . '/config/notification_preferences.php';
    frs_ensure_notification_preferences_schema();
    $uid = (int) $user['id'];
    $stmt = $pdo->prepare(
        'SELECT COALESCE(enable_otp, 1) AS enable_otp, COALESCE(totp_enabled, 0) AS totp_enabled, totp_secret
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
    mobile_json([
        'ok' => true,
        'preferences' => [
            'notifications' => frs_get_notification_preferences($uid),
            'security' => [
                'email_otp' => (bool) ((int) ($row['enable_otp'] ?? 1)),
                'google_authenticator' => !empty($row['totp_enabled']) && !empty($row['totp_secret']),
                'google_authenticator_setup_on_web' => true,
            ],
        ],
    ]);
}

if ($route === 'me/preferences' && in_array($method, ['PATCH', 'PUT'], true)) {
    $user = mobile_require_user($pdo);
    $body = mobile_body();
    $uid = (int) $user['id'];
    require_once dirname(__DIR__, 6) . '/config/notification_preferences.php';
    frs_ensure_notification_preferences_schema();

    $updated = false;
    $messages = [];

    if (isset($body['notifications']) && is_array($body['notifications'])) {
        $allowed = array_keys(frs_default_notification_preferences());
        $prefs = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body['notifications'])) {
                $prefs[$key] = filter_var($body['notifications'][$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($prefs[$key] === null) {
                    $prefs[$key] = (bool) $body['notifications'][$key];
                }
            }
        }
        if ($prefs !== []) {
            if (!frs_save_notification_preferences($uid, $prefs)) {
                mobile_error('Could not save notification preferences.', 500, 'save_failed');
            }
            $updated = true;
            $messages[] = 'Notification preferences updated.';
        }
    }

    if (array_key_exists('email_otp', $body) || (isset($body['security']) && is_array($body['security']) && array_key_exists('email_otp', $body['security']))) {
        $enableOtp = array_key_exists('email_otp', $body)
            ? $body['email_otp']
            : $body['security']['email_otp'];
        $enableOtp = filter_var($enableOtp, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enableOtp === null) {
            mobile_error('email_otp must be true or false.', 422, 'validation');
        }
        $stmt = $pdo->prepare(
            'SELECT COALESCE(enable_otp, 1) AS enable_otp, COALESCE(totp_enabled, 0) AS totp_enabled, totp_secret, role
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
        $totpActive = !empty($row['totp_enabled']) && !empty($row['totp_secret']);
        if ($enableOtp === false && function_exists('frs_role_requires_two_factor')
            && frs_role_requires_two_factor((string) ($row['role'] ?? ''))
            && !$totpActive) {
            mobile_error(
                'Email OTP cannot be turned off while Google Authenticator is also off for this role.',
                422,
                'otp_required'
            );
        }
        if ($enableOtp === false && $totpActive && function_exists('frs_role_requires_two_factor')
            && !frs_role_requires_two_factor((string) ($row['role'] ?? ''))) {
            // Residents may keep TOTP as sole 2FA; allowing email OTP off is fine.
        }
        $pdo->prepare('UPDATE users SET enable_otp = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$enableOtp ? 1 : 0, $uid]);
        $updated = true;
        $messages[] = $enableOtp ? 'Email OTP enabled.' : 'Email OTP disabled.';
    }

    // Disable Google Authenticator only (setup requires website QR flow).
    $disableTotp = false;
    if (array_key_exists('google_authenticator', $body)) {
        $disableTotp = filter_var($body['google_authenticator'], FILTER_VALIDATE_BOOLEAN) === false;
    } elseif (isset($body['security']) && is_array($body['security']) && array_key_exists('google_authenticator', $body['security'])) {
        $disableTotp = filter_var($body['security']['google_authenticator'], FILTER_VALIDATE_BOOLEAN) === false;
    }
    if ($disableTotp) {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(enable_otp, 1) AS enable_otp, COALESCE(totp_enabled, 0) AS totp_enabled, totp_secret, role
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
        $totpActive = !empty($row['totp_enabled']) && !empty($row['totp_secret']);
        if (!$totpActive) {
            mobile_error('Google Authenticator is not enabled.', 422, 'validation');
        }
        $emailOtpOn = (bool) ((int) ($row['enable_otp'] ?? 1));
        if (function_exists('frs_role_requires_two_factor')
            && frs_role_requires_two_factor((string) ($row['role'] ?? ''))
            && !$emailOtpOn) {
            mobile_error(
                'Enable Email OTP before turning off Google Authenticator.',
                422,
                'otp_required'
            );
        }
        $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, updated_at = NOW() WHERE id = ?')
            ->execute([$uid]);
        $updated = true;
        $messages[] = 'Google Authenticator disabled. Re-enable it from the website Profile page.';
    }

    if (!$updated) {
        mobile_error('No preference fields to update.', 422, 'validation');
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(enable_otp, 1) AS enable_otp, COALESCE(totp_enabled, 0) AS totp_enabled, totp_secret
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    mobile_json([
        'ok' => true,
        'message' => implode(' ', $messages),
        'preferences' => [
            'notifications' => frs_get_notification_preferences($uid),
            'security' => [
                'email_otp' => (bool) ((int) ($row['enable_otp'] ?? 1)),
                'google_authenticator' => !empty($row['totp_enabled']) && !empty($row['totp_secret']),
                'google_authenticator_setup_on_web' => true,
            ],
        ],
    ]);
}

if ($route === 'me/avatar' && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $uid = (int) $user['id'];
    if (empty($_FILES['profile_picture']['name']) || ($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        mobile_error('profile_picture file is required (JPEG, PNG, GIF, or WebP, max 2MB).', 422, 'validation');
    }
    require_once dirname(__DIR__, 6) . '/config/upload_helper.php';
    $uploadErrors = validateFileUpload(
        $_FILES['profile_picture'],
        ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        2 * 1024 * 1024
    );
    if (!empty($uploadErrors)) {
        mobile_error(implode(' ', $uploadErrors), 422, 'validation');
    }

    $uploadDir = dirname(__DIR__, 6) . '/public/uploads/profile_pictures';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        mobile_error('Could not prepare upload directory.', 500, 'upload_failed');
    }

    $ext = strtolower(pathinfo((string) $_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $ext = 'jpg';
    }
    $fileName = 'profile-' . $uid . '-' . time() . '.' . $ext;
    $targetPath = $uploadDir . '/' . $fileName;
    [$ok, $err] = saveOptimizedImage($_FILES['profile_picture']['tmp_name'], $targetPath, 900, 82);
    if (!$ok && !move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
        mobile_error($err ?: 'Failed to upload profile picture.', 500, 'upload_failed');
    }
    @chmod($targetPath, 0644);
    $profilePicture = '/public/uploads/profile_pictures/' . $fileName;

    $old = (string) ($user['profile_picture'] ?? '');
    $pdo->prepare('UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$profilePicture, $uid]);

    if ($old !== '' && str_contains($old, '/public/uploads/profile_pictures/')) {
        $oldFs = dirname(__DIR__, 6) . $old;
        if (is_file($oldFs)) {
            @unlink($oldFs);
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    mobile_json([
        'ok' => true,
        'message' => 'Profile picture updated.',
        'user' => frs_mobile_user_public($stmt->fetch(PDO::FETCH_ASSOC) ?: $user),
    ]);
}

// ---------- FACILITIES ----------
if ($route === 'facilities' && $method === 'GET') {
    mobile_require_user($pdo);
    $q = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $sql = 'SELECT id, name, description, location, capacity, amenities, rules, status, operating_hours, image_path, is_free, base_rate
            FROM facilities WHERE status != "deleted"';
    $params = [];
    if ($status !== '' && in_array($status, ['available', 'maintenance', 'offline'], true)) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR location LIKE ? OR description LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY name LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    mobile_json([
        'ok' => true,
        'facilities' => array_map('mobile_serialize_facility', $rows),
    ]);
}

if (preg_match('#^facilities/(\d+)$#', $route, $m) && $method === 'GET') {
    mobile_require_user($pdo);
    $id = (int) $m[1];
    $stmt = $pdo->prepare(
        'SELECT id, name, description, location, capacity, amenities, rules, status, operating_hours, image_path, is_free, base_rate
         FROM facilities WHERE id = ? AND status != "deleted" LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        mobile_error('Facility not found.', 404, 'not_found');
    }
    mobile_json(['ok' => true, 'facility' => mobile_serialize_facility($row)]);
}

if (preg_match('#^facilities/(\d+)/availability$#', $route, $m) && $method === 'GET') {
    mobile_require_user($pdo);
    $id = (int) $m[1];
    $date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        mobile_error('Invalid date. Use YYYY-MM-DD.', 422, 'validation');
    }
    $stmt = $pdo->prepare(
        'SELECT time_slot, status, purpose FROM reservations
         WHERE facility_id = ? AND reservation_date = ? AND status IN ("pending","approved","pending_payment","postponed")
         ORDER BY time_slot'
    );
    $stmt->execute([$id, $date]);
    $booked = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    mobile_json([
        'ok' => true,
        'facility_id' => $id,
        'date' => $date,
        'booked_slots' => array_map(static function ($b) {
            return [
                'time_slot' => (string) $b['time_slot'],
                'status' => (string) $b['status'],
            ];
        }, $booked),
    ]);
}

if (preg_match('#^facilities/(\d+)/calendar$#', $route, $m) && $method === 'GET') {
    mobile_require_user($pdo);
    $id = (int) $m[1];
    $year = (int) ($_GET['year'] ?? date('Y'));
    $month = (int) ($_GET['month'] ?? date('n'));
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        mobile_error('Invalid year/month.', 422, 'validation');
    }
    require_once dirname(__DIR__, 6) . '/config/booking_calendar_status.php';
    $matrix = function_exists('frs_facility_calendar_matrix')
        ? frs_facility_calendar_matrix($pdo, $id, $year, $month)
        : [];
    $days = [];
    foreach ($matrix as $dateKey => $tone) {
        $days[] = [
            'date' => (string) $dateKey,
            'tone' => (string) $tone,
        ];
    }
    // Also mark days the resident already booked at this facility.
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last = date('Y-m-t', strtotime($first));
    $mine = [];
    try {
        $st = $pdo->prepare(
            'SELECT reservation_date, status FROM reservations
             WHERE facility_id = ? AND reservation_date BETWEEN ? AND ?
               AND status IN ("pending","approved","pending_payment","postponed")'
        );
        $st->execute([$id, $first, $last]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $mine[(string) $r['reservation_date']] = (string) $r['status'];
        }
    } catch (Throwable $e) {
        $mine = [];
    }
    mobile_json([
        'ok' => true,
        'facility_id' => $id,
        'year' => $year,
        'month' => $month,
        'days' => $days,
        'busy' => $mine,
    ]);
}

// ---------- RESERVATIONS ----------
if ($route === 'reservations' && $method === 'GET') {
    $user = mobile_require_user($pdo);
    $status = trim((string) ($_GET['status'] ?? ''));
    $sql = 'SELECT r.*, f.name AS facility_name, f.is_free, f.base_rate
            FROM reservations r
            JOIN facilities f ON f.id = r.facility_id
            WHERE r.user_id = ?';
    $params = [(int) $user['id']];
    if ($status !== '') {
        if ($status === 'rejected') {
            $sql .= ' AND r.status = "denied"';
        } elseif ($status === 'completed') {
            $sql .= ' AND r.status = "approved" AND CONCAT(r.reservation_date, " ", SUBSTRING_INDEX(r.time_slot, " - ", -1)) < NOW()';
        } else {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
    }
    $sql .= ' ORDER BY r.reservation_date DESC, r.id DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    mobile_json(['ok' => true, 'reservations' => array_map('mobile_serialize_reservation', $rows)]);
}

if ($route === 'reservations' && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $body = mobile_body();
    $facilityId = (int) ($body['facility_id'] ?? 0);
    $date = trim((string) ($body['reservation_date'] ?? ''));
    $timeSlot = trim((string) ($body['time_slot'] ?? ''));
    $purpose = trim((string) ($body['purpose'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? $body['booking_notes'] ?? ''));
    $attendees = isset($body['expected_attendees'])
        ? (int) $body['expected_attendees']
        : (isset($body['attendees']) ? (int) $body['attendees'] : 0);

    require_once dirname(__DIR__, 6) . '/config/ai_helpers.php';
    require_once dirname(__DIR__, 6) . '/config/reservation_helpers.php';
    require_once dirname(__DIR__, 6) . '/config/auto_approval.php';
    if (file_exists(dirname(__DIR__, 6) . '/config/time_helpers.php')) {
        require_once dirname(__DIR__, 6) . '/config/time_helpers.php';
    }

    $uid = (int) $user['id'];
    $check = frs_validate_resident_booking_request($pdo, $uid, [
        'facility_id' => $facilityId,
        'reservation_date' => $date,
        'time_slot' => $timeSlot,
        'purpose' => $purpose,
        'notes' => $notes,
        'expected_attendees' => $attendees,
    ]);
    if (empty($check['ok'])) {
        mobile_error(
            (string) ($check['message'] ?? 'Booking validation failed.'),
            (int) ($check['http'] ?? 400),
            (string) ($check['error'] ?? 'validation')
        );
    }

    /** @var array<string, mixed> $facility */
    $facility = $check['facility'] ?? [];
    if ($notes !== '') {
        $purpose = $purpose === '' ? $notes : ($purpose . ' — ' . $notes);
    }

    $advanceDays = (int) (frs_resident_booking_limit_config()['advance_max_days'] ?? 60);
    $auto = evaluateAutoApproval($facilityId, $date, $timeSlot, $attendees, false, $uid, $advanceDays);
    $autoApprovedByRules = !empty($auto['auto_approve']);

    $paymentsEnabled = false;
    $requirePayment = false;
    $paymentWindow = 60;
    $paymentsCfgPath = dirname(__DIR__, 6) . '/config/payments.php';
    if (file_exists($paymentsCfgPath)) {
        $paymentsCfg = require $paymentsCfgPath;
        if (is_array($paymentsCfg)) {
            $paymentsEnabled = !empty($paymentsCfg['enabled']);
            $requirePayment = !empty($paymentsCfg['require_payment_for_reservations']);
            $candidateWindow = (int) ($paymentsCfg['payment_window_minutes'] ?? 60);
            if ($candidateWindow > 0) {
                $paymentWindow = $candidateWindow;
            }
        }
    }
    $facilityIsFree = !empty($facility['is_free']);
    $hybridPaymentMode = $paymentsEnabled && $requirePayment && !$facilityIsFree;

    if ($hybridPaymentMode) {
        $initialStatus = $autoApprovedByRules ? 'pending_payment' : 'pending';
        $isAutoApproved = false;
    } else {
        $initialStatus = $autoApprovedByRules ? 'approved' : 'pending';
        $isAutoApproved = $autoApprovedByRules;
    }

    $paymentDueAt = date('Y-m-d H:i:s', strtotime('+' . $paymentWindow . ' minutes'));
    $expiresAt = $initialStatus === 'pending_payment'
        ? $paymentDueAt
        : ($initialStatus === 'pending' ? date('Y-m-d H:i:s', strtotime('+48 hours')) : null);

    try {
        $pdo->beginTransaction();
        if (function_exists('frs_lock_facility_for_booking')) {
            frs_lock_facility_for_booking($pdo, $facilityId);
        }
        // Re-check conflict under lock
        if (function_exists('detectBookingConflict')) {
            $conflict = detectBookingConflict($facilityId, $date, $timeSlot);
            if (!empty($conflict['has_conflict'])) {
                $pdo->rollBack();
                mobile_error(
                    (string) ($conflict['message'] ?? 'Time slot conflicts with an existing reservation.'),
                    409,
                    'conflict'
                );
            }
        }

        $cols = ['user_id', 'facility_id', 'reservation_date', 'time_slot', 'purpose', 'status', 'expected_attendees', 'is_commercial', 'auto_approved'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', '0', '?'];
        $values = [$uid, $facilityId, $date, $timeSlot, $purpose, $initialStatus, $attendees, $isAutoApproved ? 1 : 0];
        try {
            $pdo->query('SELECT payment_due_at FROM reservations LIMIT 1');
            $cols[] = 'payment_due_at';
            $placeholders[] = '?';
            $values[] = $paymentDueAt;
        } catch (Throwable $e) {
            // column missing
        }
        try {
            $pdo->query('SELECT expires_at FROM reservations LIMIT 1');
            $cols[] = 'expires_at';
            $placeholders[] = '?';
            $values[] = $expiresAt;
        } catch (Throwable $e) {
            // column missing
        }
        $stmt = $pdo->prepare(
            'INSERT INTO reservations (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($values);
        $newId = (int) $pdo->lastInsertId();

        if ($initialStatus === 'pending_payment') {
            $histNote = 'Auto-approved by rules via Companion app. Awaiting payment.';
        } elseif ($initialStatus === 'approved') {
            $histNote = 'Automatically approved via Companion app.';
        } else {
            $histNote = 'Submitted via Culiat Resident Companion app. Pending staff review.';
        }
        $pdo->prepare(
            'INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (?, ?, ?, ?)'
        )->execute([$newId, $initialStatus === 'pending_payment' ? 'pending' : $initialStatus, $histNote, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Mobile book: ' . $e->getMessage());
        mobile_error('Could not create reservation.', 500, 'book_failed');
    }

    if (file_exists(dirname(__DIR__, 6) . '/config/notifications.php')) {
        require_once dirname(__DIR__, 6) . '/config/notifications.php';
        if (function_exists('createNotification')) {
            $fname = (string) ($facility['name'] ?? 'Facility');
            if ($initialStatus === 'pending_payment') {
                createNotification(
                    $uid,
                    'booking',
                    'Payment required',
                    'Your hold for ' . $fname . ' is ready. Complete payment to secure the slot.',
                    null
                );
            } elseif ($initialStatus === 'approved') {
                createNotification(
                    $uid,
                    'booking',
                    'Reservation approved',
                    'Your booking for ' . $fname . ' is confirmed.',
                    null
                );
            } else {
                createNotification(
                    $uid,
                    'booking',
                    'Reservation submitted',
                    'Your request for ' . $fname . ' is pending staff review.',
                    null
                );
            }
        }
    }

    $row = mobile_load_reservation($pdo, $newId, $uid);
    mobile_json([
        'ok' => true,
        'reservation' => mobile_serialize_reservation($row ?: ['id' => $newId]),
        'payment_required' => $initialStatus === 'pending_payment',
    ], 201);
}

if (preg_match('#^reservations/(\d+)$#', $route, $m) && $method === 'GET') {
    $user = mobile_require_user($pdo);
    $id = (int) $m[1];
    $row = mobile_load_reservation($pdo, $id, (int) $user['id']);
    if (!$row) {
        mobile_error('Reservation not found.', 404, 'not_found');
    }
    mobile_json(['ok' => true, 'reservation' => mobile_serialize_reservation($row)]);
}

if (preg_match('#^reservations/(\d+)/cancel$#', $route, $m) && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $id = (int) $m[1];
    $body = mobile_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    require_once dirname(__DIR__, 6) . '/config/reservation_helpers.php';
    if (file_exists(dirname(__DIR__, 6) . '/config/paymongo_helper.php')) {
        require_once dirname(__DIR__, 6) . '/config/paymongo_helper.php';
    }
    $reservation = mobile_load_reservation($pdo, $id, (int) $user['id']);
    if (!$reservation) {
        mobile_error('Reservation not found.', 404, 'not_found');
    }
    if (!in_array($reservation['status'], ['pending_payment', 'pending', 'approved', 'postponed'], true)) {
        mobile_error('This reservation cannot be cancelled.', 400, 'not_cancellable');
    }
    if (function_exists('frs_reservation_slot_has_passed')
        && frs_reservation_slot_has_passed((string) $reservation['reservation_date'], (string) $reservation['time_slot'])) {
        mobile_error('You cannot cancel a reservation that has already started or passed.', 400, 'already_started');
    }

    $pdo->prepare(
        'UPDATE reservations SET status = "cancelled", postponed_priority = FALSE, postponed_at = NULL, updated_at = NOW() WHERE id = ?'
    )->execute([$id]);

    $facilityIsFree = !empty($reservation['is_free']);
    $refunded = false;
    $refundWarning = '';
    $note = 'Cancelled via Companion app.' . ($reason !== '' ? ' Reason: ' . $reason : '');

    if (!$facilityIsFree && function_exists('frs_refund_payment_row')) {
        try {
            $paymentStmt = $pdo->prepare(
                'SELECT id, amount, reference_no, provider_event_id, provider_checkout_id, payload_json
                 FROM payments WHERE reservation_id = ? AND status = "paid" LIMIT 1'
            );
            $paymentStmt->execute([$id]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            if ($payment && (float) ($payment['amount'] ?? 0) > 0) {
                $refundOutcome = frs_refund_payment_row(
                    $pdo,
                    $payment,
                    'requested_by_customer',
                    'Cancelled via mobile — RES-' . $id
                );
                if (!empty($refundOutcome['refunded'])) {
                    $refunded = true;
                    $note .= ' Payment refunded via PayMongo.';
                } else {
                    $refundWarning = (string) ($refundOutcome['message'] ?? 'Automatic refund failed.');
                    $note .= ' Refund failed: ' . $refundWarning;
                }
            }
        } catch (Throwable $e) {
            $refundWarning = $e->getMessage();
            $note .= ' Refund lookup error: ' . $refundWarning;
        }
    } elseif ($facilityIsFree) {
        $note .= ' Free facility — no refund required.';
    }

    $pdo->prepare(
        'INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (?, "cancelled", ?, ?)'
    )->execute([$id, $note, (int) $user['id']]);

    if (file_exists(dirname(__DIR__, 6) . '/config/audit.php')) {
        require_once dirname(__DIR__, 6) . '/config/audit.php';
        if (function_exists('logAudit')) {
            logAudit('Resident cancelled via mobile', 'Reservations', 'RES-' . $id, (int) $user['id']);
        }
    }
    if (file_exists(dirname(__DIR__, 6) . '/config/notifications.php')) {
        require_once dirname(__DIR__, 6) . '/config/notifications.php';
        if (function_exists('createNotification')) {
            $notifBody = 'Your reservation for ' . ($reservation['facility_name'] ?? 'facility')
                . ' on ' . ($reservation['reservation_date'] ?? '')
                . ' has been cancelled.';
            if ($refunded) {
                $notifBody .= ' Your payment has been refunded.';
            } elseif ($refundWarning !== '') {
                $notifBody .= ' Automatic refund needs staff follow-up.';
            }
            createNotification((int) $user['id'], 'booking', 'Reservation cancelled', $notifBody, null);
        }
    }

    $message = 'Reservation cancelled.';
    if ($refunded) {
        $message = 'Reservation cancelled and payment refunded.';
    } elseif ($refundWarning !== '') {
        $message = 'Reservation cancelled. Refund requires staff follow-up.';
    }

    mobile_json([
        'ok' => true,
        'message' => $message,
        'refunded' => $refunded,
        'refund_warning' => $refundWarning !== '' ? $refundWarning : null,
    ]);
}

if (preg_match('#^reservations/(\d+)/pay$#', $route, $m) && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $id = (int) $m[1];
    require_once dirname(__DIR__, 6) . '/config/paymongo_helper.php';
    require_once dirname(__DIR__, 6) . '/config/reservation_helpers.php';
    $reservation = mobile_load_reservation($pdo, $id, (int) $user['id']);
    if (!$reservation) {
        mobile_error('Reservation not found.', 404, 'not_found');
    }
    if (($reservation['status'] ?? '') !== 'pending_payment') {
        mobile_error('This reservation is not awaiting payment.', 400, 'not_payable');
    }
    if (!empty($reservation['payment_due_at']) && strtotime((string) $reservation['payment_due_at']) < time()) {
        if (function_exists('autoDeclineExpiredReservations')) {
            autoDeclineExpiredReservations();
        }
        mobile_error('The payment window has expired. Please book again.', 400, 'payment_expired');
    }
    if (!paymongoEnabled()) {
        mobile_error('Online payment is currently unavailable. Please use the website or contact the barangay office.', 503, 'payments_disabled');
    }

    $cfg = paymongoConfig();
    $rawRate = (string) ($reservation['base_rate'] ?? '');
    $normalizedRate = preg_replace('/[^\d]/', '', $rawRate);
    $amountPhp = $normalizedRate !== '' ? (float) ((int) $normalizedRate) : 0.0;
    if ($amountPhp <= 0) {
        $amountPhp = 50.00;
    }
    $amountCentavos = (int) round($amountPhp * 100);
    $successUrl = frs_paymongo_return_url($id, 'success');
    $cancelUrl = frs_paymongo_return_url($id, 'cancelled');
    $desc = ($reservation['facility_name'] ?? 'Facility')
        . ' on ' . ($reservation['reservation_date'] ?? '')
        . ' (' . ($reservation['time_slot'] ?? '') . ')';

    $checkoutPayload = [
        'billing' => [
            'name' => (string) ($user['name'] ?? 'Resident'),
            'email' => (string) ($user['email'] ?? ''),
        ],
        'line_items' => [[
            'currency' => strtoupper((string) ($cfg['currency'] ?? 'PHP')),
            'amount' => $amountCentavos,
            'name' => 'Facility Reservation #' . $id,
            'quantity' => 1,
            'description' => $desc,
        ]],
        'payment_method_types' => ['gcash', 'card', 'qrph'],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'reference_number' => 'RES-' . $id,
        'description' => 'Reservation payment for Culiat facilities',
        'metadata' => [
            'reservation_id' => (string) $id,
            'user_id' => (string) $user['id'],
            'source' => 'mobile_companion',
        ],
    ];

    $resp = paymongoCreateCheckoutSession($checkoutPayload);
    if (!$resp['ok']) {
        mobile_error('Unable to start payment: ' . ($resp['error'] ?? 'Unknown error'), 502, 'checkout_failed');
    }
    $attrs = $resp['data']['data']['attributes'] ?? [];
    $checkoutId = (string) ($resp['data']['data']['id'] ?? '');
    $checkoutUrl = (string) ($attrs['checkout_url'] ?? '');
    $expiresAt = $attrs['expires_at'] ?? null;
    if ($checkoutId === '' || $checkoutUrl === '') {
        mobile_error('PayMongo did not return a checkout URL.', 502, 'checkout_failed');
    }

    $insert = $pdo->prepare(
        'INSERT INTO payments (
            reservation_id, user_id, provider, provider_checkout_id,
            amount, currency, status, expires_at, payload_json
        ) VALUES (?, ?, "paymongo", ?, ?, ?, "pending", ?, ?)'
    );
    $insert->execute([
        $id,
        (int) $user['id'],
        $checkoutId,
        $amountPhp,
        strtoupper((string) ($cfg['currency'] ?? 'PHP')),
        $expiresAt ? date('Y-m-d H:i:s', strtotime((string) $expiresAt)) : null,
        json_encode($resp['data']),
    ]);

    mobile_json([
        'ok' => true,
        'checkout_url' => $checkoutUrl,
        'checkout_id' => $checkoutId,
        'amount' => $amountPhp,
        'currency' => strtoupper((string) ($cfg['currency'] ?? 'PHP')),
        'reservation' => mobile_serialize_reservation($reservation),
        'message' => 'Open the checkout URL to pay with GCash, card, or QRPh.',
    ]);
}

if (preg_match('#^reservations/(\d+)/payment-sync$#', $route, $m) && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $id = (int) $m[1];
    require_once dirname(__DIR__, 6) . '/config/paymongo_helper.php';
    $reservation = mobile_load_reservation($pdo, $id, (int) $user['id']);
    if (!$reservation) {
        mobile_error('Reservation not found.', 404, 'not_found');
    }
    $sync = frs_try_sync_reservation_payment($pdo, $id, (int) $user['id']);
    $fresh = mobile_load_reservation($pdo, $id, (int) $user['id']);
    mobile_json([
        'ok' => !empty($sync['ok']),
        'changed' => !empty($sync['changed']),
        'message' => (string) ($sync['message'] ?? ''),
        'reservation' => mobile_serialize_reservation($fresh ?: $reservation),
    ]);
}

if (preg_match('#^reservations/(\d+)/reschedule$#', $route, $m) && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $id = (int) $m[1];
    $body = mobile_body();
    $newDate = trim((string) ($body['reservation_date'] ?? $body['new_date'] ?? ''));
    $newSlot = trim((string) ($body['time_slot'] ?? $body['new_time_slot'] ?? ''));
    $reason = trim((string) ($body['reason'] ?? ''));
    require_once dirname(__DIR__, 6) . '/config/reservation_helpers.php';
    require_once dirname(__DIR__, 6) . '/config/ai_helpers.php';
    if (file_exists(dirname(__DIR__, 6) . '/config/time_helpers.php')) {
        require_once dirname(__DIR__, 6) . '/config/time_helpers.php';
    }

    $reservation = mobile_load_reservation($pdo, $id, (int) $user['id']);
    if (!$reservation) {
        mobile_error('Reservation not found.', 404, 'not_found');
    }

    // Match website My Reservations: pending_payment must be paid first; denied/cancelled blocked.
    if (($reservation['status'] ?? '') === 'pending_payment') {
        mobile_error('Please complete payment first before rescheduling.', 400, 'payment_required');
    }
    if (!in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)) {
        mobile_error('This reservation cannot be rescheduled.', 400, 'not_reschedulable');
    }
    if ($reason === '') {
        mobile_error('Reason for rescheduling is required.', 422, 'validation');
    }
    if ($newDate === '' || $newSlot === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
        mobile_error('reservation_date and time_slot are required.', 422, 'validation');
    }

    if (function_exists('frs_reservation_slot_has_passed')
        && (frs_reservation_slot_has_passed((string) $reservation['reservation_date'], (string) $reservation['time_slot'])
            || (function_exists('frs_reservation_slot_is_ongoing')
                && frs_reservation_slot_is_ongoing((string) $reservation['reservation_date'], (string) $reservation['time_slot'])))) {
        mobile_error('Cannot reschedule a reservation that has already started or is ongoing.', 400, 'already_started');
    }

    // Website parity: one reschedule per reservation (no postponed exception).
    $count = (int) ($reservation['reschedule_count'] ?? 0);
    if ($count >= 1) {
        mobile_error('You can only reschedule once per reservation.', 400, 'reschedule_limit');
    }

    $eventDate = new DateTime((string) $reservation['reservation_date']);
    $today = new DateTime('today');
    $daysLeft = (int) $today->diff($eventDate)->format('%r%a');
    // Website parity: at least 3 days before the event (no postponed exception).
    if ($daysLeft < 3) {
        mobile_error(
            'Rescheduling is only allowed up to 3 days before the event. The event is '
            . max(0, $daysLeft) . ' day(s) away.',
            400,
            'too_late'
        );
    }

    if ($newDate === (string) $reservation['reservation_date']
        && $newSlot === (string) $reservation['time_slot']) {
        mobile_error('You are already scheduled for this date and time. Please select a different slot.', 422, 'same_slot');
    }

    $newDateObj = new DateTime($newDate);
    if ($newDateObj < $today) {
        mobile_error('New reservation date cannot be in the past.', 422, 'past_date');
    }

    $uid = (int) $user['id'];
    $facilityId = (int) $reservation['facility_id'];
    $purpose = trim((string) ($reservation['purpose'] ?? 'Resident booking'));
    $attendees = (int) ($reservation['expected_attendees'] ?? $reservation['attendees'] ?? 1);
    if ($attendees < 1) {
        $attendees = 1;
    }

    // Same facility/date/slot rules as create; skip identity (already booked) and quota
    // double-count via exclude id; skip full quota for simple date move (web parity).
    $check = frs_validate_resident_booking_request($pdo, $uid, [
        'facility_id' => $facilityId,
        'reservation_date' => $newDate,
        'time_slot' => $newSlot,
        'purpose' => $purpose,
        'notes' => '',
        'expected_attendees' => $attendees,
        'exclude_reservation_id' => $id,
        'skip_quota' => true,
        'skip_identity' => true,
    ]);
    if (empty($check['ok'])) {
        mobile_error(
            (string) ($check['message'] ?? 'Reschedule validation failed.'),
            (int) ($check['http'] ?? 400),
            (string) ($check['error'] ?? 'validation')
        );
    }

    $oldStatus = (string) ($reservation['status'] ?? 'pending');
    $oldDate = (string) ($reservation['reservation_date'] ?? '');
    $oldSlot = (string) ($reservation['time_slot'] ?? '');

    // Website parity: approved / postponed always return to pending for re-approval.
    $newStatus = in_array($oldStatus, ['approved', 'postponed'], true) ? 'pending' : $oldStatus;

    // Keep postponed_priority when leaving postponed (website behavior); clear postponed_at only.
    $hasPriority = false;
    try {
        $priorityStmt = $pdo->prepare('SELECT postponed_priority FROM reservations WHERE id = ?');
        $priorityStmt->execute([$id]);
        $hasPriority = (bool) $priorityStmt->fetchColumn();
    } catch (Throwable $e) {
        $hasPriority = false;
    }

    $pdo->prepare(
        'UPDATE reservations
         SET reservation_date = ?, time_slot = ?, status = ?,
             reschedule_count = COALESCE(reschedule_count, 0) + 1,
             postponed_at = NULL, updated_at = NOW()
         WHERE id = ?'
    )->execute([$newDate, $newSlot, $newStatus, $id]);

    $histNote = 'Rescheduled via Companion app from ' . $oldDate . ' (' . $oldSlot . ') to '
        . $newDate . ' (' . $newSlot . '). Reason: ' . $reason;
    if ($oldStatus === 'approved' || $oldStatus === 'postponed') {
        $histNote .= ' Status changed to pending for re-approval.';
        if ($oldStatus === 'postponed' && $hasPriority) {
            $histNote .= ' Priority status maintained.';
        }
    }

    $pdo->prepare(
        'INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (?, ?, ?, ?)'
    )->execute([$id, $newStatus, $histNote, $uid]);

    if (file_exists(dirname(__DIR__, 6) . '/config/notifications.php')) {
        require_once dirname(__DIR__, 6) . '/config/notifications.php';
        if (function_exists('createNotification')) {
            $notifMsg = 'Your reservation for ' . ($reservation['facility_name'] ?? 'facility')
                . ' was rescheduled to ' . $newDate . ' (' . $newSlot . ').';
            if ($newStatus === 'pending') {
                $notifMsg .= ' It is pending staff re-approval.';
            }
            createNotification(
                $uid,
                'booking',
                'Reservation rescheduled',
                $notifMsg,
                null
            );
        }
    }

    $fresh = mobile_load_reservation($pdo, $id, $uid);
    $msg = 'Reservation rescheduled.';
    if ($newStatus === 'pending' && in_array($oldStatus, ['approved', 'postponed'], true)) {
        $msg = 'Reservation rescheduled and set to pending for staff re-approval.';
    }
    mobile_json([
        'ok' => true,
        'message' => $msg,
        'reservation' => mobile_serialize_reservation($fresh ?: $reservation),
    ]);
}

if (preg_match('#^reservations/(\d+)/pass$#', $route, $m) && $method === 'GET') {
    $user = mobile_require_user($pdo);
    $id = (int) $m[1];
    require_once dirname(__DIR__, 6) . '/config/occupancy_monitoring.php';
    $stmt = $pdo->prepare(
        'SELECT r.*, f.name AS facility_name, f.location AS facility_location
         FROM reservations r JOIN facilities f ON f.id = r.facility_id
         WHERE r.id = ? AND r.user_id = ? LIMIT 1'
    );
    $stmt->execute([$id, (int) $user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        mobile_error('Reservation not found.', 404, 'not_found');
    }
    if (($row['status'] ?? '') !== 'approved') {
        mobile_error('QR pass is only available for approved reservations.', 400, 'not_approved');
    }
    $token = '';
    if (function_exists('frs_ensure_checkin_token')) {
        $token = (string) (frs_ensure_checkin_token($pdo, $id) ?? '');
    }
    if ($token === '' && !empty($row['attendance_checkin_token'])) {
        $token = (string) $row['attendance_checkin_token'];
    }
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        try {
            $pdo->prepare('UPDATE reservations SET attendance_checkin_token = ? WHERE id = ?')->execute([$token, $id]);
        } catch (Throwable $e) {
            // column may not exist
        }
    }
    $payload = json_encode([
        'type' => 'reservation_pass',
        'reservation_id' => $id,
        'token' => $token,
        'user_id' => (int) $user['id'],
    ], JSON_UNESCAPED_SLASHES);

    mobile_json([
        'ok' => true,
        'pass' => [
            'reservation' => mobile_serialize_reservation($row),
            'qr_payload' => $payload,
            'token' => $token,
            'facility_location' => $row['facility_location'] ?? null,
        ],
    ]);
}

// ---------- CHECK-IN ----------
if ($route === 'check-in/facility' && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $body = mobile_body();
    $rawToken = trim((string) ($body['token'] ?? $body['code'] ?? $body['qr_payload'] ?? ''));
    if ($rawToken === '') {
        mobile_error('Facility QR token required.', 422, 'validation');
    }
    // Accept full URL or raw token
    if (preg_match('/[?&]token=([^&]+)/', $rawToken, $tm)) {
        $rawToken = urldecode($tm[1]);
    }
    // Reservation pass QR is not a facility gate QR.
    if ($rawToken !== '' && ($rawToken[0] === '{' || str_contains($rawToken, 'reservation_pass'))) {
        $decoded = json_decode($rawToken, true);
        if (is_array($decoded) && ($decoded['type'] ?? '') === 'reservation_pass') {
            mobile_error(
                'That is a booking pass QR. Scan the facility QR posted at the entrance to check in.',
                400,
                'wrong_qr_type'
            );
        }
    }

    require_once dirname(__DIR__, 6) . '/config/occupancy_monitoring.php';
    require_once dirname(__DIR__, 6) . '/config/attendance.php';

    $fac = null;
    try {
        $st = $pdo->prepare('SELECT id, name, location, status FROM facilities WHERE checkin_qr_token = ? LIMIT 1');
        $st->execute([$rawToken]);
        $fac = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $fac = null;
    }
    if (!$fac) {
        mobile_error('Unknown facility QR code.', 404, 'invalid_facility_qr');
    }

    $facilityId = (int) $fac['id'];
    if (function_exists('frs_process_facility_qr_scan')) {
        $result = frs_process_facility_qr_scan($pdo, (int) $user['id'], $facilityId);
        $ok = !empty($result['ok']);
        // If no reservation today, still return facility so app can open details / book
        if (($result['status'] ?? '') === 'no_reservation') {
            mobile_json([
                'ok' => true,
                'action' => 'open_facility',
                'facility_id' => $facilityId,
                'facility_name' => (string) ($fac['name'] ?? ''),
                'message' => $result['message'] ?? 'No approved reservation today. You can book this facility.',
                'result' => $result,
            ]);
        }
        mobile_json([
            'ok' => $ok,
            'action' => $result['action'] ?? ($ok ? 'check_in' : 'none'),
            'facility_id' => $facilityId,
            'facility_name' => (string) ($fac['name'] ?? ''),
            'result' => $result,
            'message' => $result['message'] ?? ($ok ? 'Check-in processed.' : 'Check-in failed.'),
        ], $ok ? 200 : 400);
    }

    mobile_json([
        'ok' => true,
        'action' => 'open_facility',
        'facility_id' => $facilityId,
        'facility_name' => (string) ($fac['name'] ?? ''),
        'message' => 'Facility recognized. Open details to book or check in.',
    ]);
}

// ---------- ANNOUNCEMENTS / NOTIFICATIONS ----------
if ($route === 'announcements' && $method === 'GET') {
    mobile_require_user($pdo);
    $stmt = $pdo->query(
        'SELECT id, title, message, created_at FROM notifications
         WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 50'
    );
    // Some schemas use `body` instead of `message`
    if (!$stmt) {
        mobile_json(['ok' => true, 'announcements' => []]);
    }
    try {
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $stmt = $pdo->query(
            'SELECT id, title, body AS message, created_at FROM notifications
             WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 50'
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'title' => (string) ($r['title'] ?? 'Announcement'),
            'message' => (string) ($r['message'] ?? $r['body'] ?? ''),
            'created_at' => $r['created_at'] ?? null,
        ];
    }
    mobile_json(['ok' => true, 'announcements' => $items]);
}

if ($route === 'notifications' && $method === 'GET') {
    $user = mobile_require_user($pdo);
    try {
        $stmt = $pdo->prepare(
            'SELECT id, title, message, is_read, created_at FROM notifications
             WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
        );
        $stmt->execute([(int) $user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            'SELECT id, title, body AS message, is_read, created_at FROM notifications
             WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
        );
        $stmt->execute([(int) $user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $items = array_map(static function ($r) {
        return [
            'id' => (int) $r['id'],
            'title' => (string) ($r['title'] ?? 'Notification'),
            'message' => (string) ($r['message'] ?? ''),
            'is_read' => (bool) ($r['is_read'] ?? false),
            'created_at' => $r['created_at'] ?? null,
        ];
    }, $rows);
    mobile_json(['ok' => true, 'notifications' => $items]);
}

if (preg_match('#^notifications/(\d+)/read$#', $route, $m) && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $id = (int) $m[1];
    try {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
            ->execute([$id, (int) $user['id']]);
    } catch (Throwable $e) {
        // ignore
    }
    mobile_json(['ok' => true]);
}

if ($route === 'devices' && $method === 'POST') {
    $user = mobile_require_user($pdo);
    $body = mobile_body();
    $fcm = trim((string) ($body['fcm_token'] ?? ''));
    $platform = substr((string) ($body['platform'] ?? ''), 0, 32);
    $deviceName = isset($body['device_name']) ? substr((string) $body['device_name'], 0, 120) : null;
    if ($fcm === '') {
        mobile_error('fcm_token required.', 422, 'validation');
    }
    if (!frs_mobile_table_exists($pdo, 'mobile_devices')) {
        mobile_json(['ok' => true, 'message' => 'Device table not migrated yet; token accepted for future use.']);
    }
    $pdo->prepare(
        'INSERT INTO mobile_devices (user_id, fcm_token, platform, device_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform),
             device_name = VALUES(device_name), updated_at = NOW()'
    )->execute([(int) $user['id'], $fcm, $platform !== '' ? $platform : null, $deviceName]);
    mobile_json(['ok' => true, 'message' => 'Device registered.']);
}

// ---------- OCCUPANCY ----------
if ($route === 'occupancy/live' && $method === 'GET') {
    mobile_require_user($pdo);
    require_once dirname(__DIR__, 6) . '/config/occupancy_monitoring.php';
    $snap = frs_build_operational_occupancy_snapshot($pdo);
    if (function_exists('frs_sanitize_occupancy_snapshot_for_public')) {
        $snap = frs_sanitize_occupancy_snapshot_for_public($snap);
    }
    // Slim payload for mobile (modern Live Occupancy strip)
    $facilities = [];
    foreach ($snap['facilities'] ?? [] as $f) {
        $counts = is_array($f['counts'] ?? null) ? $f['counts'] : [];
        $checkedIn = (int) ($counts['checked_in'] ?? 0);
        $booked = (int) ($counts['booked'] ?? 0);
        $imageUrl = $f['image_url'] ?? null;
        if (is_string($imageUrl) && $imageUrl !== '' && !preg_match('#^https?://#i', $imageUrl)) {
            $origin = function_exists('base_url') ? rtrim((string) base_url(), '/') : '';
            $imageUrl = $origin . '/' . ltrim($imageUrl, '/');
        } elseif (!is_string($imageUrl) || $imageUrl === '') {
            $imageUrl = null;
        }
        $state = (string) ($f['aggregate_state'] ?? 'available');
        $label = (string) ($f['aggregate_display']['label'] ?? $state);
        $facilities[] = [
            'facility_id' => (int) ($f['facility_id'] ?? 0),
            'facility_name' => (string) ($f['facility_name'] ?? ''),
            'aggregate_state' => $state,
            'status' => $state,
            'label' => $label,
            'is_occupied' => !empty($f['is_occupied']),
            'is_within_operating_hours' => !empty($f['is_within_operating_hours']),
            'operating_hours' => (string) ($f['operating_hours'] ?? ''),
            'image_url' => $imageUrl,
            'current' => $checkedIn > 0 ? $checkedIn : $booked,
            'capacity' => isset($f['capacity']) ? (int) $f['capacity'] : null,
            'counts' => [
                'checked_in' => $checkedIn,
                'booked' => $booked,
                'no_show_risk' => (int) ($counts['no_show_risk'] ?? 0),
            ],
        ];
    }
    mobile_json([
        'ok' => true,
        'as_of' => $snap['as_of'] ?? null,
        'summary' => $snap['summary'] ?? null,
        'facilities' => $facilities,
        'disclaimer' => $snap['disclaimer'] ?? null,
    ]);
}

// ---------- SMART SCHEDULER (same engine as website /dashboard/ai-scheduling) ----------
if ($route === 'smart-scheduler' && $method === 'GET') {
    $user = mobile_require_user($pdo);
    $uid = (int) $user['id'];
    $userName = (string) ($user['name'] ?? 'Resident');

    $servicePath = dirname(__DIR__, 6) . '/services/RecommendationService.php';
    if (!file_exists($servicePath)) {
        mobile_error('Recommendation service is not available.', 503, 'unavailable');
    }
    require_once $servicePath;

    try {
        $service = new RecommendationService($pdo);
        $raw = $service->getPersonalizedRecommendations($uid);
        if (!is_array($raw)) {
            $raw = [];
        }
    } catch (Throwable $e) {
        error_log('Mobile smart-scheduler: ' . $e->getMessage());
        mobile_error('Could not load recommendations.', 500, 'scheduler_failed');
    }

    $historyCount = 0;
    try {
        $h = $pdo->prepare(
            "SELECT COUNT(*) FROM reservations
             WHERE user_id = ?
               AND reservation_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
               AND status IN ('approved','completed')"
        );
        $h->execute([$uid]);
        $historyCount = (int) $h->fetchColumn();
    } catch (Throwable $e) {
        $historyCount = 0;
    }
    $personalized = $historyCount >= 3;
    $items = [];
    foreach ($raw as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $facilityId = (int) ($rec['facility_id'] ?? 0);
        $imageUrl = null;
        $isFree = true;
        $baseRate = null;
        if ($facilityId > 0) {
            try {
                $fstmt = $pdo->prepare(
                    'SELECT image_path, is_free, base_rate FROM facilities WHERE id = ? LIMIT 1'
                );
                $fstmt->execute([$facilityId]);
                $frow = $fstmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $imageUrl = mobile_facility_image_url($frow['image_path'] ?? null);
                $isFree = !isset($frow['is_free']) || !empty($frow['is_free']);
                if (isset($frow['base_rate'])) {
                    $normalized = preg_replace('/[^\d]/', '', (string) $frow['base_rate']);
                    $baseRate = $normalized !== '' ? (float) ((int) $normalized) : null;
                }
            } catch (Throwable $e) {
                // ignore enrichment errors
            }
        }
        $date = (string) ($rec['suggested_date'] ?? '');
        $time = (string) ($rec['suggested_time'] ?? '');
        $items[] = [
            'facility_id' => $facilityId,
            'facility_name' => (string) ($rec['facility_name'] ?? 'Facility'),
            'score' => (int) ($rec['score'] ?? 0),
            'reasons' => array_values(array_filter(array_map('strval', $rec['reasons'] ?? []))),
            'suggested_date' => $date,
            'suggested_time' => $time,
            'suggested_duration' => isset($rec['suggested_duration']) ? (float) $rec['suggested_duration'] : null,
            'suggested_attendees' => isset($rec['suggested_attendees']) ? (int) $rec['suggested_attendees'] : null,
            'is_fallback' => !empty($rec['is_fallback']),
            'image_url' => $imageUrl,
            'is_free' => $isFree,
            'base_rate' => $baseRate,
            'book_prefill' => [
                'facility_id' => $facilityId,
                'reservation_date' => $date,
                'time_slot' => $time,
                'expected_attendees' => isset($rec['suggested_attendees']) ? (int) $rec['suggested_attendees'] : null,
            ],
        ];
    }

    // Optional Gemini insight (same helper as dashboard chatbot) — non-blocking.
    $geminiInsight = null;
    $geminiConfigPath = dirname(__DIR__, 6) . '/config/gemini_config.php';
    if (file_exists($geminiConfigPath)) {
        require_once $geminiConfigPath;
    }
    require_once dirname(__DIR__, 6) . '/config/gemini_chatbot.php';
    if (function_exists('geminiChatbotResponse') && $items !== []) {
        try {
            $top = array_slice($items, 0, 3);
            $lines = [];
            foreach ($top as $t) {
                $lines[] = sprintf(
                    '- %s (score %d%%) on %s %s',
                    $t['facility_name'],
                    $t['score'],
                    $t['suggested_date'],
                    $t['suggested_time']
                );
            }
            $mode = $personalized ? 'personalized from booking history' : 'popular / fallback (limited history)';
            $system = 'You are the PFRS Smart Scheduler assistant for Barangay Culiat residents. '
                . 'Write 2 short friendly sentences (English or Taglish) summarizing why these slots are recommended. '
                . 'Do not invent facilities. Do not output JSON.';
            $userMsg = "Resident name: {$userName}. Recommendation mode: {$mode}.\nTop suggestions:\n"
                . implode("\n", $lines)
                . "\nWrite a brief insight for the mobile Smart Scheduler screen.";
            $g = geminiChatbotResponse($system, $userMsg, []);
            if ($g && !empty($g['reply'])) {
                $geminiInsight = trim((string) $g['reply']);
            }
        } catch (Throwable $e) {
            error_log('Mobile smart-scheduler Gemini insight: ' . $e->getMessage());
        }
    }

    mobile_json([
        'ok' => true,
        'title' => 'Smart Scheduler',
        'subtitle' => 'Recommended for you',
        'engine' => 'recommendation_service',
        'gemini_used' => $geminiInsight !== null,
        'personalized' => $personalized,
        'history_count' => $historyCount,
        'min_history' => 3,
        'gemini_insight' => $geminiInsight,
        'recommendations' => $items,
        'message' => $items === []
            ? 'No recommendations yet. Book a few facilities to unlock personalized suggestions.'
            : ($personalized
                ? 'Based on your recent booking patterns.'
                : 'Popular picks while we learn your preferences (book 3+ approved reservations for full personalization).'),
    ]);
}

// ---------- BOOKING POLICY (resident limits) ----------
if ($route === 'booking/policy' && $method === 'GET') {
    $user = mobile_require_user($pdo);
    require_once dirname(__DIR__, 6) . '/config/reservation_helpers.php';
    $cfg = frs_resident_booking_limit_config();
    $identity = frs_resident_identity_allows_booking($pdo, (int) $user['id']);
    mobile_json([
        'ok' => true,
        'limits' => [
            'per_day' => (int) $cfg['per_day'],
            'per_week' => (int) $cfg['per_week'],
            'per_month' => (int) $cfg['per_month'],
            'per_year' => (int) $cfg['per_year'],
            'max_upcoming_active' => (int) $cfg['max_upcoming_active'],
            'advance_max_days' => (int) $cfg['advance_max_days'],
        ],
        'rules' => [
            'min_duration_minutes' => 30,
            'max_duration_hours' => 12,
            'attendees_required' => true,
            'reschedule_min_days_before' => 3,
            'reschedule_max_times' => 1,
            'identity_required' => true,
        ],
        'summary' => frs_resident_booking_limits_summary(),
        'policy_bullets' => frs_resident_booking_limits_policy_bullets(),
        'can_book' => !empty($identity['ok']),
        'identity_message' => empty($identity['ok']) ? (string) $identity['message'] : null,
        // Companion Help center — same rules as website My Reservations / Terms.
        'help' => [
            'booking' => [
                'Valid ID / identity verification is required before residents can book.',
                frs_resident_booking_limits_policy_bullets(),
                'Duration must be between 30 minutes and 12 hours.',
                'Expected attendees is required and cannot exceed facility capacity.',
                'Some bookings may be auto-approved; staff can still override.',
                'Paid facilities may require PayMongo payment (GCash / card / QRPh) within the payment window.',
            ],
            'reschedule' => [
                'Only pending, approved, or postponed reservations can be rescheduled.',
                'Complete payment first if status is pending payment.',
                'Reschedule at least 3 days before the event (same-day not allowed).',
                'Only one reschedule is allowed per reservation.',
                'A reason is required.',
                'Approved or postponed bookings return to pending for staff re-approval after reschedule.',
                'Reservations that have already started or are ongoing cannot be rescheduled.',
            ],
            'cancel_refund' => [
                'You may cancel upcoming reservations that are pending payment, pending, approved, or postponed.',
                'You cannot cancel a reservation that has already started or passed.',
                'Free facilities: cancel releases the slot; no refund is involved.',
                'Paid bookings: the app attempts an automatic PayMongo refund when you cancel.',
                'If automatic refund fails, staff will follow up — keep your booking reference ready.',
                'Rejected or cancelled reservations cannot be rescheduled; create a new booking instead.',
            ],
            'qr_checkin' => [
                'QR facility pass is available only for approved reservations.',
                'Bring a valid ID when checking in at the facility.',
                'Follow barangay staff instructions on-site.',
            ],
        ],
    ]);
}

// ---------- AI ASSISTANT (Gemini) ----------
if ($route === 'assistant/chat' && $method === 'POST') {
    @set_time_limit(45);
    $user = mobile_require_user($pdo);
    $body = mobile_body();
    $message = trim((string) ($body['message'] ?? ''));
    $userId = (int) $user['id'];
    $userName = (string) ($user['name'] ?? 'Resident');

    require_once dirname(__DIR__, 6) . '/config/chatbot_responses.php';
    $geminiConfigPath = dirname(__DIR__, 6) . '/config/gemini_config.php';
    if (file_exists($geminiConfigPath)) {
        require_once $geminiConfigPath;
    }
    require_once dirname(__DIR__, 6) . '/config/gemini_chatbot.php';

    if ($message === '') {
        mobile_json([
            'ok' => true,
            'reply' => getRandomResponse(getEmptyMessageResponses()),
        ]);
    }

    if (!checkGeminiChatbotRateLimit($userId)) {
        mobile_json([
            'ok' => false,
            'error' => 'rate_limited',
            'reply' => 'You have sent many messages in a short time. Please wait a few minutes before using the AI assistant again.',
        ], 429);
    }

    $history = $body['history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }
    $sanitizedHistory = [];
    foreach (array_slice($history, -10) as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role = (($msg['role'] ?? '') === 'model') ? 'model' : 'user';
        $text = '';
        if (isset($msg['parts'][0]['text'])) {
            $text = trim((string) $msg['parts'][0]['text']);
        } elseif (isset($msg['text'])) {
            $text = trim((string) $msg['text']);
        }
        if ($text !== '') {
            $sanitizedHistory[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
    }

    if (function_exists('geminiChatbotResponse') && function_exists('buildGeminiChatbotPrompt')) {
        try {
            $facStmt = $pdo->query(
                "SELECT id, name, status, capacity, amenities, location, operating_hours
                 FROM facilities WHERE status != 'deleted' ORDER BY name LIMIT 50"
            );
            $facilities = $facStmt ? ($facStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            $bStmt = $pdo->prepare(
                'SELECT r.reservation_date, r.time_slot, f.name AS facility_name
                 FROM reservations r
                 JOIN facilities f ON r.facility_id = f.id
                 WHERE r.user_id = :uid
                 ORDER BY r.reservation_date DESC
                 LIMIT 5'
            );
            $bStmt->execute(['uid' => $userId]);
            $userBookings = $bStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Skip live occupancy on mobile: the snapshot can be slow enough to
            // exceed phone/proxy timeouts before Gemini (or soft-fail) can respond.
            $liveOccupancy = null;

            $prompt = buildGeminiChatbotPrompt($facilities, $userBookings, $userName, $userId, $liveOccupancy);
            $geminiResult = geminiChatbotResponse($prompt, $message, $sanitizedHistory);

            if ($geminiResult && !empty($geminiResult['reply'])) {
                $reply = (string) $geminiResult['reply'];
                $out = ['ok' => true, 'reply' => $reply];

                if (!empty($geminiResult['booking']) && is_array($geminiResult['booking'])) {
                    $b = $geminiResult['booking'];
                    if (empty($b['facility_id']) && !empty($b['facility_name'])) {
                        foreach ($facilities as $f) {
                            if (stripos((string) $f['name'], (string) $b['facility_name']) !== false
                                || stripos((string) $b['facility_name'], (string) $f['name']) !== false) {
                                $b['facility_id'] = (int) $f['id'];
                                break;
                            }
                        }
                    }
                    $hasUsefulData = isset($b['facility_id']) || isset($b['reservation_date'])
                        || isset($b['start_time']) || isset($b['end_time'])
                        || isset($b['time_slot']) || isset($b['purpose']);
                    if ($hasUsefulData) {
                        $out['action'] = 'prefill_booking';
                        $out['data'] = $b;
                    }
                }

                mobile_json($out);
            }
        } catch (Throwable $e) {
            error_log('Mobile Gemini assistant error: ' . $e->getMessage());
        }
    }

    $geminiConfigured = defined('GEMINI_API_KEY')
        && GEMINI_API_KEY !== ''
        && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';

    if ($geminiConfigured) {
        // Soft-fail: keep the chat usable with a rule-based reply instead of a hard 503.
        mobile_json([
            'ok' => true,
            'error' => 'gemini_unavailable',
            'message' => 'The AI assistant could not reach Gemini right now. Showing a basic reply instead.',
            'reply' => 'I can still help with facility bookings, availability, and your reservations. '
                . 'Ask about a facility, operating hours, or say you want to book one. '
                . '(Full AI replies will return once Gemini is available again.)',
        ]);
    }

    $simpleGreetings = ['hi', 'hello', 'hey', 'greetings', 'good morning', 'good afternoon', 'good evening'];
    $msgLower = strtolower($message);
    if (in_array($msgLower, $simpleGreetings, true)
        || in_array($msgLower . '!', $simpleGreetings, true)) {
        mobile_json([
            'ok' => true,
            'reply' => getRandomResponse(getGreetingResponses($userName)),
        ]);
    }

    mobile_json([
        'ok' => true,
        'reply' => 'I can help with facility bookings, availability, and your reservations. '
            . 'Ask me about a facility, operating hours, or say you want to book one.',
    ]);
}

// ---------- HOME (composed) ----------
if ($route === 'home' && $method === 'GET') {
    $user = mobile_require_user($pdo);
    $uid = (int) $user['id'];
    $upcoming = null;
    $ustmt = $pdo->prepare(
        'SELECT r.*, f.name AS facility_name FROM reservations r
         JOIN facilities f ON f.id = r.facility_id
         WHERE r.user_id = ? AND r.status IN ("pending","approved","pending_payment","postponed")
           AND r.reservation_date >= CURDATE()
         ORDER BY r.reservation_date ASC, r.id ASC LIMIT 1'
    );
    $ustmt->execute([$uid]);
    $urow = $ustmt->fetch(PDO::FETCH_ASSOC);
    if ($urow) {
        $upcoming = mobile_serialize_reservation($urow);
    }
    $facStmt = $pdo->query(
        'SELECT id, name, description, location, capacity, amenities, rules, status, operating_hours, image_path, is_free
         FROM facilities WHERE status = "available" ORDER BY name LIMIT 6'
    );
    $featured = array_map('mobile_serialize_facility', $facStmt ? ($facStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []);
    $ann = [];
    try {
        $a = $pdo->query(
            'SELECT id, title, message, created_at FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 5'
        );
        $ann = $a ? ($a->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        // Some schemas use `body` instead of `message`.
        try {
            $a = $pdo->query(
                'SELECT id, title, body AS message, created_at FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 5'
            );
            $ann = $a ? ($a->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e2) {
            $ann = [];
        }
    }
    $announcements = array_map(static function ($r) {
        $text = (string) ($r['message'] ?? $r['body'] ?? '');
        return [
            'id' => (int) $r['id'],
            'title' => (string) ($r['title'] ?? ''),
            'message' => $text,
            'body' => $text,
            'created_at' => $r['created_at'] ?? null,
        ];
    }, $ann);

    mobile_json([
        'ok' => true,
        'upcoming_reservation' => $upcoming,
        // Companion app aliases (same payloads, friendlier keys).
        'upcoming' => $upcoming ? [$upcoming] : [],
        'announcements' => $announcements,
        'featured_facilities' => $featured,
        'facilities' => $featured,
    ]);
}

mobile_error('Endpoint not found: ' . $route, 404, 'not_found');
