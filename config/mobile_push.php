<?php
/**
 * FCM HTTP v1 push for Resident Companion.
 * Register device tokens via POST /api/mobile/v1/devices.
 * Set FCM_SERVICE_ACCOUNT_PATH in .env / cprf.env to the Firebase service-account JSON.
 */
declare(strict_types=1);

if (!function_exists('frs_mobile_notify_user')) {
    /**
     * @param array<string, mixed> $data
     */
    function frs_mobile_notify_user(PDO $pdo, int $userId, string $title, string $body, array $data = []): int
    {
        if ($userId <= 0) {
            return 0;
        }
        if (!function_exists('frs_mobile_table_exists')) {
            require_once __DIR__ . '/mobile_auth.php';
        }
        if (!frs_mobile_table_exists($pdo, 'mobile_devices')) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT fcm_token FROM mobile_devices WHERE user_id = ?');
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($tokens === []) {
            return 0;
        }

        $access = frs_fcm_access_token();
        if ($access === null) {
            return 0;
        }
        [$accessToken, $projectId] = $access;

        $sent = 0;
        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
        }

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $payload = json_encode([
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $stringData,
                    'android' => [
                        'priority' => 'high',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);

            $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                $sent++;
                continue;
            }

            error_log('FCM v1 send failed HTTP ' . $code . ': ' . substr((string) $raw, 0, 300));

            // Drop dead tokens so we do not keep failing on them.
            if ($code === 404 || $code === 400) {
                $decoded = json_decode((string) $raw, true);
                $status = (string) ($decoded['error']['status'] ?? '');
                if (in_array($status, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED'], true)
                    || str_contains((string) $raw, 'UNREGISTERED')
                    || str_contains((string) $raw, 'Requested entity was not found')
                ) {
                    try {
                        $pdo->prepare('DELETE FROM mobile_devices WHERE fcm_token = ?')->execute([$token]);
                    } catch (Throwable $e) {
                        // ignore cleanup errors
                    }
                }
            }
        }
        return $sent;
    }
}

/**
 * @return array{0: string, 1: string}|null [accessToken, projectId]
 */
function frs_fcm_access_token(): ?array
{
    static $cached = null;
    static $cachedUntil = 0;

    if (is_array($cached) && time() < $cachedUntil) {
        return $cached;
    }

    $path = function_exists('env_value')
        ? trim((string) env_value('FCM_SERVICE_ACCOUNT_PATH', ''))
        : trim((string) (getenv('FCM_SERVICE_ACCOUNT_PATH') ?: ''));

    if ($path === '') {
        error_log('frs_mobile_notify_user: FCM_SERVICE_ACCOUNT_PATH not set');
        return null;
    }
    if (!is_readable($path)) {
        error_log('frs_mobile_notify_user: service account file not readable: ' . $path);
        return null;
    }

    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json)
        || empty($json['client_email'])
        || empty($json['private_key'])
        || empty($json['project_id'])
        || empty($json['token_uri'])
    ) {
        error_log('frs_mobile_notify_user: invalid service account JSON');
        return null;
    }

    $now = time();
    $claim = [
        'iss' => (string) $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => (string) $json['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $jwt = frs_fcm_encode_jwt($claim, (string) $json['private_key']);
    if ($jwt === null) {
        return null;
    }

    $ch = curl_init((string) $json['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $tokenResp = json_decode((string) $raw, true);
    $accessToken = is_array($tokenResp) ? (string) ($tokenResp['access_token'] ?? '') : '';
    if ($code < 200 || $code >= 300 || $accessToken === '') {
        error_log('frs_mobile_notify_user: OAuth token failed HTTP ' . $code . ': ' . substr((string) $raw, 0, 200));
        return null;
    }

    $expiresIn = (int) ($tokenResp['expires_in'] ?? 3600);
    $cached = [$accessToken, (string) $json['project_id']];
    $cachedUntil = time() + max(60, $expiresIn - 120);
    return $cached;
}

/**
 * @param array<string, mixed> $claims
 */
function frs_fcm_encode_jwt(array $claims, string $privateKeyPem): ?string
{
    if (!function_exists('openssl_sign')) {
        error_log('frs_mobile_notify_user: openssl extension required');
        return null;
    }

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $segments = [
        frs_fcm_b64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
        frs_fcm_b64url(json_encode($claims, JSON_UNESCAPED_SLASHES)),
    ];
    $signingInput = $segments[0] . '.' . $segments[1];

    $key = openssl_pkey_get_private($privateKeyPem);
    if ($key === false) {
        error_log('frs_mobile_notify_user: invalid private_key in service account JSON');
        return null;
    }
    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        error_log('frs_mobile_notify_user: openssl_sign failed');
        return null;
    }
    return $signingInput . '.' . frs_fcm_b64url($signature);
}

function frs_fcm_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
