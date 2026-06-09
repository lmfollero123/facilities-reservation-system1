<?php
/**
 * One-off CLI check: confirms Turnstile env vars are visible to PHP.
 * Run on server: php scripts/captcha_env_check.php
 * Delete after use — do not leave on production long-term.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/captcha.php';

$enabled = frs_captcha_enabled();
$siteKey = frs_turnstile_site_key();
$secretSet = frs_turnstile_secret_key() !== '';

echo 'CAPTCHA_ENABLED (parsed): ' . ($enabled ? 'yes' : 'no') . PHP_EOL;
echo 'TURNSTILE_SITE_KEY set: ' . ($siteKey !== '' ? 'yes (' . strlen($siteKey) . ' chars)' : 'no') . PHP_EOL;
echo 'TURNSTILE_SECRET_KEY set: ' . ($secretSet ? 'yes' : 'no') . PHP_EOL;
echo 'Widget would render: ' . ($enabled && $siteKey !== '' ? 'yes' : 'no') . PHP_EOL;
