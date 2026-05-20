<?php
require_once __DIR__ . '/app.php';

function frs_captcha_enabled(): bool
{
    $enabled = strtolower((string)env_value('CAPTCHA_ENABLED', 'false'));
    return $enabled === 'true' || $enabled === '1' || $enabled === 'yes';
}

function frs_turnstile_site_key(): string
{
    return trim((string)env_value('TURNSTILE_SITE_KEY', ''));
}

function frs_turnstile_secret_key(): string
{
    return trim((string)env_value('TURNSTILE_SECRET_KEY', ''));
}

/**
 * Verify Cloudflare Turnstile token.
 *
 * @return array{ok: bool, error: string}
 */
function frs_verify_turnstile(?string $token, ?string $remoteIp = null): array
{
    if (!frs_captcha_enabled()) {
        return ['ok' => true, 'error' => ''];
    }

    $secret = frs_turnstile_secret_key();
    if ($secret === '') {
        return ['ok' => false, 'error' => 'Captcha is enabled but not configured.'];
    }

    $token = trim((string)$token);
    if ($token === '') {
        return ['ok' => false, 'error' => 'Please complete the captcha.'];
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $remoteIp ?: '',
    ]);

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err) {
        return ['ok' => false, 'error' => 'Captcha verification failed (network).'];
    }

    $decoded = json_decode((string)$raw, true);
    $ok = is_array($decoded) && !empty($decoded['success']);
    if ($ok) {
        return ['ok' => true, 'error' => ''];
    }

    if ($code >= 500) {
        return ['ok' => false, 'error' => 'Captcha verification unavailable. Try again.'];
    }

    return ['ok' => false, 'error' => 'Captcha check failed. Please try again.'];
}

