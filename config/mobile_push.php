<?php
/**
 * FCM push helper stub for Resident Companion (Phase 5+).
 * Register device tokens via POST /api/mobile/v1/devices.
 * Wire HTTP v1 credentials later; this records intent and can be called from
 * approval/notification flows without failing hard if FCM is unconfigured.
 */
declare(strict_types=1);

if (!function_exists('frs_mobile_notify_user')) {
    /**
     * @param array<string, mixed> $data
     */
    function frs_mobile_notify_user(PDO $pdo, int $userId, string $title, string $body, array $data = []): int
    {
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

        $serverKey = function_exists('env_value') ? (string) env_value('FCM_SERVER_KEY', '') : '';
        if ($serverKey === '') {
            error_log('frs_mobile_notify_user: FCM_SERVER_KEY not set; skipped ' . count($tokens) . ' device(s) for user ' . $userId);
            return 0;
        }

        // Legacy FCM HTTP endpoint — replace with HTTP v1 when service account is available.
        $sent = 0;
        foreach ($tokens as $token) {
            $payload = json_encode([
                'to' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
            ], JSON_UNESCAPED_UNICODE);
            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: key=' . $serverKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $sent++;
            } else {
                error_log('FCM send failed HTTP ' . $code . ': ' . substr((string) $raw, 0, 200));
            }
        }
        return $sent;
    }
}
