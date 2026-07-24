<?php
/**
 * SSO consumer: accepts a signed token from Main LGU (infragovservices.com hub)
 * and establishes a real session via frs_complete_authenticated_login(), the
 * same function config/security.php:669 uses for a normal password login.
 */
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';

function sso_reject(string $message): void
{
    http_response_code(403);
    exit('SSO error: ' . $message);
}

$ssoSecret = env_value('SSO_SHARED_SECRET', '6724201881389f70d4d233dcd87caa15d507ebfd56f3fc73e0ad2b1c61e2d825');

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

$stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $insert = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'Admin', 'active')");
    $insert->execute([$fullName, $email, $passwordHash]);

    $user = [
        'id' => (int) $pdo->lastInsertId(),
        'name' => $fullName,
        'email' => $email,
        'role' => 'Admin',
    ];
}

frs_complete_authenticated_login($user);
$_SESSION['sso_from_mainlgu'] = true;

header('Location: ' . base_path() . '/dashboard');
exit;
