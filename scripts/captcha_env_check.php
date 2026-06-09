<?php
/**
 * CLI check: Turnstile env visibility (mirrors web bootstrap).
 * Run: php scripts/captcha_env_check.php
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/captcha.php';

echo '=== CPRF captcha env check ===' . PHP_EOL;
echo 'SAPI: ' . PHP_SAPI . PHP_EOL;
echo 'HOME (server): ' . ($_SERVER['HOME'] ?? '(not set)') . PHP_EOL;
echo 'Project .env: ' . (is_file(dirname(__DIR__) . '/.env') ? 'found' : 'missing') . PHP_EOL;

if (function_exists('frs_private_env_candidates')) {
    echo 'Private env candidates:' . PHP_EOL;
    foreach (frs_private_env_candidates() as $path) {
        echo '  ' . $path . ' => ' . (is_file($path) ? 'FOUND' : 'missing') . PHP_EOL;
    }
}

$enabled = frs_captcha_enabled();
$siteKey = frs_turnstile_site_key();
$secretSet = frs_turnstile_secret_key() !== '';

echo PHP_EOL;
echo 'CAPTCHA_ENABLED (parsed): ' . ($enabled ? 'yes' : 'no') . PHP_EOL;
echo 'TURNSTILE_SITE_KEY set: ' . ($siteKey !== '' ? 'yes (' . strlen($siteKey) . ' chars)' : 'no') . PHP_EOL;
echo 'TURNSTILE_SECRET_KEY set: ' . ($secretSet ? 'yes' : 'no') . PHP_EOL;
echo 'Widget would render: ' . ($enabled && $siteKey !== '' ? 'yes' : 'no') . PHP_EOL;

if ($enabled && $siteKey !== '') {
    echo PHP_EOL;
    echo 'If widget shows "Unable to connect" or 400 in browser:' . PHP_EOL;
    echo '  Cloudflare Dashboard → Turnstile → your widget → add this hostname.' . PHP_EOL;
    echo '  Production: cprf.infragovservices.com' . PHP_EOL;
    echo '  Local dev: lgu.test and/or localhost' . PHP_EOL;
}
