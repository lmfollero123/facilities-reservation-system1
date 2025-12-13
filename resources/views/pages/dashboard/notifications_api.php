<?php
/**
 * Notifications API endpoint for AJAX requests
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/notifications.php';

$pdo = db();
$userId = $_SESSION['user_id'] ?? null;
$action = $_GET['action'] ?? '';

try {
    if ($action === 'list') {
        $limit = (int)($_GET['limit'] ?? 10);
        
        $stmt = $pdo->prepare(
            'SELECT id, type, title, message, link, is_read, created_at
             FROM notifications
             WHERE user_id = :user_id OR user_id IS NULL
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications
        ]);
    } elseif ($action === 'mark_read') {
        $notifId = (int)($_GET['id'] ?? 0);
        
        if ($notifId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE notifications 
                 SET is_read = TRUE 
                 WHERE id = :id AND (user_id = :user_id OR user_id IS NULL)'
            );
            $stmt->execute([
                'id' => $notifId,
                'user_id' => $userId,
            ]);
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
        }
    } elseif ($action === 'count') {
        $count = getUnreadNotificationCount($userId);
        echo json_encode(['success' => true, 'count' => $count]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('Notifications API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}










