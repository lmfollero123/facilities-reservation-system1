<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/app.php';

$token = trim((string)env_value('PHILSMS_API_TOKEN', ''));
echo 'Token length: ' . strlen($token) . PHP_EOL;
echo 'Has pipe: ' . (str_contains($token, '|') ? 'yes' : 'no') . PHP_EOL;

if ($token === '') {
    echo "ERROR: Token is empty in environment.\n";
    exit(1);
}

$ch = curl_init('https://app.philsms.com/api/v3/balance');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Balance endpoint HTTP: {$code}\n";
echo "Response: {$body}\n";
