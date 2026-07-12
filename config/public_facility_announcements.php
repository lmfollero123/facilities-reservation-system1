<?php
/**
 * Shared helpers for auto-published public facility announcements.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function frs_gemini_api_configured(): bool
{
    return defined('GEMINI_API_KEY')
        && GEMINI_API_KEY !== ''
        && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';
}

function frs_env_flag_enabled(string $key, bool $defaultWhenGemini = true): bool
{
    $flag = function_exists('env_value')
        ? strtolower(trim((string)env_value($key, '')))
        : strtolower(trim((string)(getenv($key) ?: '')));

    if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    return $defaultWhenGemini && frs_gemini_api_configured();
}

function frs_notifications_supports_image_path(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'image_path'");
        $cached = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function frs_facility_announcement_image_path(?string $imagePath): ?string
{
    if ($imagePath === null || trim($imagePath) === '') {
        return null;
    }
    $path = trim($imagePath);
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    return $path;
}

/**
 * @return int|null Notification id
 */
function frs_insert_public_facility_announcement(
    PDO $pdo,
    string $title,
    string $message,
    int $facilityId,
    ?string $facilityImagePath = null
): ?int {
    $title = trim($title);
    $message = trim($message);
    if ($title === '' || $message === '' || $facilityId <= 0) {
        return null;
    }

    $imagePath = frs_facility_announcement_image_path($facilityImagePath);
    $link = '/facility-details?id=' . $facilityId;
    $supportsImage = frs_notifications_supports_image_path($pdo);

    if ($supportsImage) {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link, image_path, created_at)
             VALUES (NULL, :type, :title, :message, :link, :image_path, NOW())'
        );
        $stmt->execute([
            'type' => 'system',
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'image_path' => $imagePath,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link, created_at)
             VALUES (NULL, :type, :title, :message, :link, NOW())'
        );
        $stmt->execute([
            'type' => 'system',
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ]);
    }

    $notificationId = (int)$pdo->lastInsertId();
    return $notificationId > 0 ? $notificationId : null;
}
