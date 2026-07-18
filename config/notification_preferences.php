<?php
/**
 * User notification channel preferences (in-app, email, SMS).
 */

require_once __DIR__ . '/database.php';

/** @return array<string, bool> */
function frs_default_notification_preferences(): array
{
    return [
        'booking_in_app' => true,
        'booking_email' => true,
        'booking_sms' => true,
        'reminder_in_app' => true,
        'reminder_email' => true,
        'reminder_sms' => false,
    ];
}

/**
 * @return array<string, bool>
 */
function frs_get_notification_preferences(int $userId): array
{
    $defaults = frs_default_notification_preferences();
    if ($userId <= 0) {
        return $defaults;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT notification_preferences FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') {
            return $defaults;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach ($defaults as $key => $val) {
            if (array_key_exists($key, $decoded)) {
                $defaults[$key] = (bool)$decoded[$key];
            }
        }
        return $defaults;
    } catch (Throwable $e) {
        error_log('frs_get_notification_preferences: ' . $e->getMessage());
        return $defaults;
    }
}

/**
 * @param array<string, bool> $prefs
 */
function frs_save_notification_preferences(int $userId, array $prefs): bool
{
    if ($userId <= 0) {
        return false;
    }
    $merged = frs_default_notification_preferences();
    // Start from saved prefs so partial updates do not wipe other channels.
    $current = frs_get_notification_preferences($userId);
    foreach ($merged as $key => $_) {
        if (array_key_exists($key, $current)) {
            $merged[$key] = (bool) $current[$key];
        }
    }
    foreach ($merged as $key => $_) {
        if (array_key_exists($key, $prefs)) {
            $merged[$key] = (bool) $prefs[$key];
        }
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'UPDATE users SET notification_preferences = :prefs, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([
            'prefs' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'id' => $userId,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('frs_save_notification_preferences: ' . $e->getMessage());
        return false;
    }
}

/**
 * @param 'booking'|'reminder' $category
 * @param 'in_app'|'email'|'sms' $channel
 */
function frs_user_wants_notification(int $userId, string $category, string $channel): bool
{
    $prefs = frs_get_notification_preferences($userId);
    $key = $category . '_' . $channel;
    return (bool)($prefs[$key] ?? true);
}

/**
 * Ensure users.notification_preferences column exists (soft migration).
 */
function frs_ensure_notification_preferences_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'notification_preferences'");
        if ($col && $col->rowCount() === 0) {
            $pdo->exec(
                'ALTER TABLE users ADD COLUMN notification_preferences JSON NULL COMMENT \'Notification opt-in JSON\''
            );
        }
        $col2 = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'reminder_sent_at'");
        if ($col2 && $col2->rowCount() === 0) {
            $pdo->exec(
                'ALTER TABLE reservations ADD COLUMN reminder_sent_at TIMESTAMP NULL DEFAULT NULL'
            );
        }
    } catch (Throwable $e) {
        error_log('frs_ensure_notification_preferences_schema: ' . $e->getMessage());
    }
}
