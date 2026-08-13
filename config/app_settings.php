<?php
/**
 * Generic key-value app settings (app_settings table), admin-editable from
 * System Settings > Security & Timers. Falls back to hardcoded defaults
 * wherever the table doesn't exist yet or the DB isn't reachable.
 */

function frs_app_settings_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query('SELECT 1 FROM app_settings LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * @return array<string, string> setting_key => setting_value
 */
function frs_get_app_settings_map(PDO $pdo): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!frs_app_settings_table_ready($pdo)) {
        return $map;
    }
    try {
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM app_settings');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
    } catch (Throwable $e) {
        // Keep whatever was loaded before the failure; caller falls back to defaults per-key.
    }
    return $map;
}

function frs_get_app_setting_int(PDO $pdo, string $key, int $default): int
{
    $map = frs_get_app_settings_map($pdo);
    if (!isset($map[$key]) || $map[$key] === '') {
        return $default;
    }
    $value = (int)$map[$key];
    return $value > 0 ? $value : $default;
}

function frs_set_app_setting(PDO $pdo, string $key, string $value, ?int $userId = null): void
{
    if (!frs_app_settings_table_ready($pdo)) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (:key, :value, :user_id)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute(['key' => $key, 'value' => $value, 'user_id' => $userId]);
}
