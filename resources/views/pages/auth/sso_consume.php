<?php
/**
 * SSO consumer: accepts a signed token from Main LGU (infragovservices.com hub)
 * and establishes a real session via frs_complete_authenticated_login(), the
 * same function config/security.php:669 uses for a normal password login.
 */
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/sso_helper.php';

function sso_reject(string $message): void
{
    http_response_code(403);
    exit('SSO error: ' . $message);
}

$ssoSecret = frs_sso_shared_secret();
if ($ssoSecret === null) {
    // Fail closed: no valid secret configured, so no token can be trusted.
    error_log('SSO consume refused: SSO_SHARED_SECRET is unset or compromised.');
    sso_reject('SSO is not configured');
}

$token = $_GET['sso_token'] ?? '';
$parts = explode('.', $token, 2);
if (count($parts) !== 2) {
    sso_reject('malformed token');
}
[$payloadPart, $signaturePart] = $parts;

$expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadPart, $ssoSecret, true)), '+/', '-_'), '=');
if (!hash_equals($expectedSig, $signaturePart)) {
    sso_reject('invalid signature');
}

$payload = json_decode(base64_decode(strtr($payloadPart, '-_', '+/')), true);
if (!is_array($payload)) {
    sso_reject('invalid payload');
}
if (($payload['target'] ?? '') !== 'cprf') {
    sso_reject('token not issued for this system');
}
if (!isset($payload['exp']) || time() > $payload['exp']) {
    sso_reject('token expired');
}

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS sso_used_tokens (
    nonce VARCHAR(64) PRIMARY KEY,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $pdo->prepare('INSERT INTO sso_used_tokens (nonce) VALUES (?)')->execute([$payload['nonce'] ?? '']);
} catch (PDOException $e) {
    sso_reject('token already used');
}

$email = $payload['email'] ?? '';
$fullName = $payload['full_name'] ?? 'Super Admin';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sso_reject('token missing a valid email');
}

$stmt = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Do not provision accounts from an SSO token — an earlier version minted an
// Admin here for any unknown email, which was a takeover path. SSO only signs
// in a user who already exists in this system.
if (!$user) {
    error_log('SSO consume refused: no CPRF account for ' . $email);
    sso_reject('no account for this user — ask an administrator to create one first');
}
if (($user['status'] ?? '') !== 'active') {
    sso_reject('account is not active');
}

frs_complete_authenticated_login($user);
$_SESSION['sso_from_mainlgu'] = true;

header('Location: ' . base_path() . '/dashboard');
exit;
