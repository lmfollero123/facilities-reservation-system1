<?php
require_once __DIR__ . '/app.php';

function frs_captcha_enabled(): bool
{
    $enabled = strtolower((string)env_value('CAPTCHA_ENABLED', 'false'));
    return $enabled === 'true' || $enabled === '1' || $enabled === 'yes';
}

/**
 * Failed login attempts (per email) before Turnstile is shown on the login page.
 */
function frs_captcha_login_threshold(): int
{
    $value = (int)env_value('CAPTCHA_LOGIN_AFTER_FAILED', '2');
    return max(1, min(10, $value));
}

/**
 * Whether the login form should show/require Turnstile (after suspicious activity only).
 */
function frs_login_requires_captcha(?string $email = null, ?string $clientIp = null): bool
{
    if (!frs_captcha_enabled() || frs_turnstile_site_key() === '') {
        return false;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['login_captcha_required'])) {
        return true;
    }

    require_once __DIR__ . '/security.php';

    $threshold = frs_captcha_login_threshold();
    $emailNorm = strtolower(trim((string)$email));
    if ($emailNorm !== '') {
        if (frs_rate_limit_count('login', $emailNorm) >= $threshold) {
            return true;
        }
    }

    $ip = trim((string)$clientIp);
    if ($ip !== '' && frs_login_failed_attempts_by_ip($ip) >= $threshold) {
        return true;
    }

    return false;
}

function frs_login_mark_captcha_required(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['login_captcha_required'] = true;
}

function frs_login_clear_captcha_required(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['login_captcha_required']);
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
function frs_verify_turnstile(?string $token, ?string $remoteIp = null, bool $required = true): array
{
    if (!$required || !frs_captcha_enabled()) {
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

