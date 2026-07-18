<?php
/**
 * Notification Helper Functions
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notification_preferences.php';

/**
 * Create a notification for a user or all users
 * 
 * @param int|null $userId User ID (null for system-wide notifications)
 * @param string $type Notification type: 'booking', 'system', 'reminder'
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string|null $link Optional link to related page
 * @return int|false Notification ID on success, false on failure
 */
function createNotification($userId, $type, $title, $message, $link = null) {
    frs_ensure_notification_preferences_schema();
    if ($userId !== null && (int)$userId > 0 && in_array($type, ['booking', 'reminder'], true)) {
        $category = ($type === 'reminder') ? 'reminder' : 'booking';
        if (!frs_user_wants_notification((int)$userId, $category, 'in_app')) {
            return false;
        }
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link) 
             VALUES (:user_id, :type, :title, :message, :link)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ]);
        $id = (int) $pdo->lastInsertId();

        // Best-effort system-tray push for companion app (no-op if FCM unconfigured).
        if ($id > 0 && $userId !== null && (int) $userId > 0) {
            try {
                require_once __DIR__ . '/mobile_push.php';
                frs_mobile_notify_user(
                    $pdo,
                    (int) $userId,
                    (string) $title,
                    (string) $message,
                    [
                        'type' => (string) $type,
                        'notification_id' => (string) $id,
                        'link' => (string) ($link ?? ''),
                    ]
                );
            } catch (Throwable $pushEx) {
                error_log('FCM push after notification failed: ' . $pushEx->getMessage());
            }
        }

        return $id;
    } catch (Throwable $e) {
        error_log('Notification creation failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notification count for a user
 * 
 * @param int|null $userId User ID (null for system-wide notifications)
 * @return int Unread count
 */
function getUnreadNotificationCount($userId = null) {
    try {
        $pdo = db();
        if ($userId === null) {
            $stmt = $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id IS NULL AND is_read = FALSE');
        } else {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM notifications 
                 WHERE (user_id = :user_id OR user_id IS NULL) AND is_read = FALSE'
            );
            $stmt->execute(['user_id' => $userId]);
        }
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Failed to get unread count: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Mark all notifications as read for a user (includes system-wide notices).
 *
 * @return int Number of notifications updated
 */
function markAllNotificationsRead(?int $userId): int
{
    if ($userId === null || $userId <= 0) {
        return 0;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = TRUE
             WHERE (user_id = :user_id OR user_id IS NULL) AND is_read = FALSE'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('Failed to mark all notifications read: ' . $e->getMessage());
        return 0;
    }
}









