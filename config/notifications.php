<?php
/**
 * Notification Helper Functions
 */

require_once __DIR__ . '/database.php';

/**
 * Create a notification for a user or all users
 * 
 * @param int|null $userId User ID (null for system-wide notifications)
 * @param string $type Notification type: 'booking', 'system', 'payment', 'reminder'
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string|null $link Optional link to related page
 * @return int|false Notification ID on success, false on failure
 */
function createNotification($userId, $type, $title, $message, $link = null) {
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
        return (int)$pdo->lastInsertId();
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







