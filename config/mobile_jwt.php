<?php
/**
 * Lightweight HS256 JWT for the Resident Companion mobile API.
 * No external JWT package required.
 */
declare(strict_types=1);

if (!function_exists('frs_mobile_jwt_secret')) {
    function frs_mobile_jwt_secret(): string
    {
        $secret = '';
        if (function_exists('env_value')) {
            $secret = (string) env_value('MOBILE_JWT_SECRET', '');
        }
        if ($secret === '') {
            $secret = (string) (getenv('MOBILE_JWT_SECRET') ?: '');
        }
        if ($secret === '') {
            // Dev fallback — set MOBILE_JWT_SECRET in production.
            $secret = 'culiat-mobile-dev-secret-change-me';
        }
        return $secret;
    }
}

if (!function_exists('frs_mobile_b64url_encode')) {
    function frs_mobile_b64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('frs_mobile_b64url_decode')) {
    function frs_mobile_b64url_decode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

if (!function_exists('frs_mobile_jwt_encode')) {
    /**
     * @param array<string, mixed> $payload
     */
    function frs_mobile_jwt_encode(array $payload, int $ttlSeconds = 900): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $payload['iat'] = $payload['iat'] ?? $now;
        $payload['exp'] = $payload['exp'] ?? ($now + $ttlSeconds);
        $segments = [
            frs_mobile_b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES) ?: '{}'),
            frs_mobile_b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, frs_mobile_jwt_secret(), true);
        $segments[] = frs_mobile_b64url_encode($signature);
        return implode('.', $segments);
    }
}

if (!function_exists('frs_mobile_jwt_decode')) {
    /**
     * @return array<string, mixed>|null
     */
    function frs_mobile_jwt_decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;
        $signingInput = $h64 . '.' . $p64;
        $expected = frs_mobile_b64url_encode(hash_hmac('sha256', $signingInput, frs_mobile_jwt_secret(), true));
        if (!hash_equals($expected, $s64)) {
            return null;
        }
        $payloadJson = frs_mobile_b64url_decode($p64);
        if ($payloadJson === false) {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }
}
