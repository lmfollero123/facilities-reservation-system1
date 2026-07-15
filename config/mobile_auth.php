<?php
/**
 * Mobile Companion auth helpers (Resident-only JWT + refresh tokens).
 */
declare(strict_types=1);

require_once __DIR__ . '/mobile_jwt.php';

if (!function_exists('frs_mobile_access_ttl')) {
    function frs_mobile_access_ttl(): int
    {
        $v = function_exists('env_value') ? (int) env_value('MOBILE_ACCESS_TTL', '900') : 900;
        return max(300, $v);
    }
}

if (!function_exists('frs_mobile_refresh_ttl')) {
    function frs_mobile_refresh_ttl(): int
    {
        $v = function_exists('env_value') ? (int) env_value('MOBILE_REFRESH_TTL', '2592000') : 2592000; // 30d
        return max(86400, $v);
    }
}

if (!function_exists('frs_mobile_table_exists')) {
    function frs_mobile_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            $cache[$table] = $st && $st->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('frs_mobile_user_public')) {
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    function frs_mobile_user_public(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? 'Resident'),
            'mobile' => $user['mobile'] ?? null,
            'address' => $user['address'] ?? null,
            'status' => (string) ($user['status'] ?? 'active'),
        ];
    }
}

if (!function_exists('frs_mobile_issue_token_pair')) {
    /**
     * @param array<string, mixed> $user
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string, user: array}
     */
    function frs_mobile_issue_token_pair(PDO $pdo, array $user, ?string $deviceName = null): array
    {
        $userId = (int) $user['id'];
        $ttl = frs_mobile_access_ttl();
        $access = frs_mobile_jwt_encode([
            'sub' => $userId,
            'role' => 'Resident',
            'email' => (string) ($user['email'] ?? ''),
            'typ' => 'access',
        ], $ttl);

        $refreshPlain = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshPlain);
        $refreshTtl = frs_mobile_refresh_ttl();

        if (frs_mobile_table_exists($pdo, 'mobile_refresh_tokens')) {
            $stmt = $pdo->prepare(
                'INSERT INTO mobile_refresh_tokens (user_id, token_hash, device_name, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
            );
            $stmt->execute([$userId, $refreshHash, $deviceName, $refreshTtl]);
        }

        return [
            'access_token' => $access,
            'refresh_token' => $refreshPlain,
            'expires_in' => $ttl,
            'token_type' => 'Bearer',
            'user' => frs_mobile_user_public($user),
        ];
    }
}

if (!function_exists('frs_mobile_revoke_refresh')) {
    function frs_mobile_revoke_refresh(PDO $pdo, string $refreshToken): void
    {
        if (!frs_mobile_table_exists($pdo, 'mobile_refresh_tokens')) {
            return;
        }
        $hash = hash('sha256', $refreshToken);
        $pdo->prepare(
            'UPDATE mobile_refresh_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL'
        )->execute([$hash]);
    }
}

if (!function_exists('frs_mobile_rotate_refresh')) {
    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string, user: array}|null
     */
    function frs_mobile_rotate_refresh(PDO $pdo, string $refreshToken): ?array
    {
        if (!frs_mobile_table_exists($pdo, 'mobile_refresh_tokens')) {
            return null;
        }
        $hash = hash('sha256', $refreshToken);
        $stmt = $pdo->prepare(
            'SELECT t.*, u.id AS uid, u.name, u.email, u.role, u.status, u.mobile, u.address
             FROM mobile_refresh_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (($row['role'] ?? '') !== 'Resident' || ($row['status'] ?? '') !== 'active') {
            frs_mobile_revoke_refresh($pdo, $refreshToken);
            return null;
        }
        // Rotate: revoke old, issue new
        $pdo->prepare('UPDATE mobile_refresh_tokens SET revoked_at = NOW(), last_used_at = NOW() WHERE id = ?')
            ->execute([(int) $row['id']]);

        $user = [
            'id' => (int) $row['uid'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'status' => $row['status'],
            'mobile' => $row['mobile'] ?? null,
            'address' => $row['address'] ?? null,
        ];
        return frs_mobile_issue_token_pair($pdo, $user, $row['device_name'] ?? null);
    }
}

if (!function_exists('frs_mobile_authorization_header')) {
    function frs_mobile_authorization_header(): string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['Authorization'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }
}

if (!function_exists('frs_mobile_bearer_user')) {
    /**
     * @return array<string, mixed>|null
     */
    function frs_mobile_bearer_user(PDO $pdo): ?array
    {
        $header = frs_mobile_authorization_header();
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }
        $payload = frs_mobile_jwt_decode($m[1]);
        if (!$payload || ($payload['typ'] ?? '') !== 'access') {
            return null;
        }
        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || ($user['role'] ?? '') !== 'Resident' || ($user['status'] ?? '') !== 'active') {
            return null;
        }
        return $user;
    }
}

if (!function_exists('frs_mobile_create_otp_challenge')) {
    function frs_mobile_create_otp_challenge(PDO $pdo, int $userId, string $purpose = 'login'): string
    {
        $challengeId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
        if (frs_mobile_table_exists($pdo, 'mobile_otp_challenges')) {
            $pdo->prepare(
                'INSERT INTO mobile_otp_challenges (user_id, challenge_id, purpose, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
            )->execute([$userId, $challengeId, $purpose]);
        }
        return $challengeId;
    }
}

if (!function_exists('frs_mobile_find_otp_challenge')) {
    /**
     * Look up an OTP challenge without consuming it.
     *
     * @return array<string, mixed>|null
     */
    function frs_mobile_find_otp_challenge(PDO $pdo, string $challengeId): ?array
    {
        if (!frs_mobile_table_exists($pdo, 'mobile_otp_challenges')) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM mobile_otp_challenges
             WHERE challenge_id = ? AND consumed_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$challengeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('frs_mobile_mark_otp_challenge_consumed')) {
    function frs_mobile_mark_otp_challenge_consumed(PDO $pdo, int $challengeRowId): void
    {
        if (!frs_mobile_table_exists($pdo, 'mobile_otp_challenges')) {
            return;
        }
        $pdo->prepare('UPDATE mobile_otp_challenges SET consumed_at = NOW() WHERE id = ?')
            ->execute([$challengeRowId]);
    }
}

if (!function_exists('frs_mobile_consume_otp_challenge')) {
    function frs_mobile_consume_otp_challenge(PDO $pdo, string $challengeId): ?int
    {
        $row = frs_mobile_find_otp_challenge($pdo, $challengeId);
        if (!$row) {
            return null;
        }
        if (strtotime((string) ($row['expires_at'] ?? '')) <= time()) {
            return null;
        }
        frs_mobile_mark_otp_challenge_consumed($pdo, (int) $row['id']);
        return (int) $row['user_id'];
    }
}
