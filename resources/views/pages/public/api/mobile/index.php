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
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $base = rtrim(function_exists('base_path') ? base_path() : '', '/');
    return $base . '/' . ltrim($path, '/');
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
    if ($notes !== '') {
        $purpose = $purpose === '' ? $notes : ($purpose . ' — ' . $notes);
    }
    $attendees = isset($body['expected_attendees']) ? (int) $body['expected_attendees'] : null;

    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $timeSlot === '' || $purpose === '') {
        mobile_error('facility_id, reservation_date, time_slot, and purpose are required.', 422, 'validation');
    }
    if ($date < date('Y-m-d')) {
        mobile_error('Reservation date must be today or later.', 422, 'validation');
    }

    $fac = $pdo->prepare(
        'SELECT id, name, status, is_free, base_rate, capacity FROM facilities WHERE id = ? AND status != "deleted"'
    );
    $fac->execute([$facilityId]);
    $facility = $fac->fetch(PDO::FETCH_ASSOC);
    if (!$facility) {
        mobile_error('Facility not found.', 404, 'not_found');
    }
    if (($facility['status'] ?? '') === 'maintenance' || ($facility['status'] ?? '') === 'offline') {
        mobile_error('This facility is not available for booking.', 400, 'facility_unavailable');
    }

    require_once dirname(__DIR__, 6) . '/config/ai_helpers.php';
    require_once dirname(__DIR__, 6) . '/config/reservation_helpers.php';
    require_once dirname(__DIR__, 6) . '/config/auto_approval.php';
    if (function_exists('detectBookingConflict')) {
        $conflict = detectBookingConflict($facilityId, $date, $timeSlot);
        if (!empty($conflict['has_conflict'])) {
            mobile_error($conflict['message'] ?? 'Time slot conflicts with an existing reservation.', 409, 'conflict');
        }
    }

    $uid = (int) $user['id'];
    $advanceDays = 60;
    if (function_exists('frs_resident_booking_limit_config')) {
        $advanceDays = (int) (frs_resident_booking_limit_config()['advance_max_days'] ?? 60);
    }
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
    $reason = trim((string) ($body['reason'] ?? 'Rescheduled via Companion app'));
    require_once dirname(__DIR__, 6) . '/config/reservation_helpers.php';
    require_once dirname(__DIR__, 6) . '/config/ai_helpers.php';

    $reservation = mobile_load_reservation($pdo, $id, (int) $user['id']);
    if (!$reservation) {
        mobile_error('Reservation not found.', 404, 'not_found');
    }
    if (!in_array($reservation['status'], ['pending', 'approved', 'postponed', 'pending_payment'], true)) {
        mobile_error('This reservation cannot be rescheduled.', 400, 'not_reschedulable');
    }
    if ($newDate === '' || $newSlot === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
        mobile_error('reservation_date and time_slot are required.', 422, 'validation');
    }
    if ($newDate < date('Y-m-d')) {
        mobile_error('New date cannot be in the past.', 422, 'validation');
    }

    $count = (int) ($reservation['reschedule_count'] ?? 0);
    if ($count >= 1 && ($reservation['status'] ?? '') !== 'postponed') {
        mobile_error('You can only reschedule once per reservation.', 400, 'reschedule_limit');
    }

    $eventDate = new DateTime((string) $reservation['reservation_date']);
    $today = new DateTime('today');
    $daysLeft = (int) $today->diff($eventDate)->format('%r%a');
    if ($daysLeft < 3 && ($reservation['status'] ?? '') !== 'postponed') {
        mobile_error('Reschedule is only allowed at least 3 days before the booking date.', 400, 'too_late');
    }

    if (function_exists('detectBookingConflict')) {
        $conflict = detectBookingConflict((int) $reservation['facility_id'], $newDate, $newSlot, $id);
        if (!empty($conflict['has_conflict'])) {
            mobile_error($conflict['message'] ?? 'Selected slot conflicts with another booking.', 409, 'conflict');
        }
    }

    $newStatus = $reservation['status'];
    if (in_array($reservation['status'], ['approved', 'postponed'], true)) {
        // Paid stay approved if still paid; otherwise staff may need to re-check.
        try {
            $paid = $pdo->prepare('SELECT id FROM payments WHERE reservation_id = ? AND status = "paid" LIMIT 1');
            $paid->execute([$id]);
            $hasPaid = (bool) $paid->fetchColumn();
            $newStatus = $hasPaid || !empty($reservation['is_free']) ? 'approved' : 'pending';
        } catch (Throwable $e) {
            $newStatus = 'pending';
        }
    }

    $pdo->prepare(
        'UPDATE reservations
         SET reservation_date = ?, time_slot = ?, status = ?,
             reschedule_count = COALESCE(reschedule_count, 0) + 1,
             postponed_at = NULL, postponed_priority = FALSE, updated_at = NOW()
         WHERE id = ?'
    )->execute([$newDate, $newSlot, $newStatus, $id]);
    $pdo->prepare(
        'INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (?, ?, ?, ?)'
    )->execute([
        $id,
        $newStatus,
        'Rescheduled via Companion app to ' . $newDate . ' ' . $newSlot . '. ' . $reason,
        (int) $user['id'],
    ]);

    $fresh = mobile_load_reservation($pdo, $id, (int) $user['id']);
    mobile_json([
        'ok' => true,
        'message' => 'Reservation rescheduled.',
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
    $rawToken = trim((string) ($body['token'] ?? ''));
    if ($rawToken === '') {
        mobile_error('Facility QR token required.', 422, 'validation');
    }
    // Accept full URL or raw token
    if (preg_match('/[?&]token=([^&]+)/', $rawToken, $tm)) {
        $rawToken = urldecode($tm[1]);
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
    // Slim payload for mobile
    $facilities = [];
    foreach ($snap['facilities'] ?? [] as $f) {
        $facilities[] = [
            'facility_id' => (int) ($f['facility_id'] ?? 0),
            'facility_name' => (string) ($f['facility_name'] ?? ''),
            'aggregate_state' => (string) ($f['aggregate_state'] ?? 'available'),
            'label' => (string) ($f['aggregate_display']['label'] ?? ''),
            'is_within_operating_hours' => !empty($f['is_within_operating_hours']),
            'operating_hours' => (string) ($f['operating_hours'] ?? ''),
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
        $ann = [];
    }
    $announcements = array_map(static function ($r) {
        return [
            'id' => (int) $r['id'],
            'title' => (string) ($r['title'] ?? ''),
            'message' => (string) ($r['message'] ?? ''),
            'body' => (string) ($r['message'] ?? ''),
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
