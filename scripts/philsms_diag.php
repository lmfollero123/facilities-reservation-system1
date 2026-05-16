<?php
declare(strict_types=1);

/**
 * PhilSMS auth diagnostics (does not print full token).
 *
 * Usage:
 *   php scripts/philsms_diag.php
 *   php scripts/philsms_diag.php "3020|your_token_here"
 */

$root = dirname(__DIR__);
require_once $root . '/config/app.php';

$token = $argv[1] ?? trim((string)env_value('PHILSMS_API_TOKEN', ''));
if ($token === '') {
    fwrite(STDERR, "No token. Pass as argv[1] or set PHILSMS_API_TOKEN in .env\n");
    exit(1);
}

$variants = [
    'full' => $token,
];
if (str_contains($token, '|')) {
    [$id, $secret] = explode('|', $token, 2);
    $variants['secret_only'] = $secret;
    $variants['id_only'] = $id;
}

$hosts = [
    'https://app.philsms.com/api/v3/balance',
    'https://api.philsms.com/api/v3/balance',
];

echo 'Token length: ' . strlen($token) . PHP_EOL;
echo 'Env file: ' . (is_file($root . '/.env') ? realpath($root . '/.env') : 'MISSING') . PHP_EOL;
echo PHP_EOL;

foreach ($hosts as $url) {
    echo "=== URL: {$url} ===" . PHP_EOL;
    foreach ($variants as $label => $value) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $value,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($body, true);
        $status = is_array($decoded) ? (string)($decoded['status'] ?? '?') : '?';
        $message = is_array($decoded) ? (string)($decoded['message'] ?? '') : substr($body, 0, 80);
        echo "  [{$label}] HTTP {$code} status={$status} msg={$message}" . PHP_EOL;
    }
    echo PHP_EOL;
}
