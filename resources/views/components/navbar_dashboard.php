<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
$username = $_SESSION['user_name'] ?? 'Guest';
$userId = $_SESSION['user_id'] ?? null;

require_once __DIR__ . '/../../../config/notifications.php';
$unreadCount = $userId ? getUnreadNotificationCount($userId) : 0;
?>
<header class="dashboard-header">
    <div>
        <button class="btn btn-outline" data-sidebar-toggle aria-expanded="true">
            ☰ Menu
        </button>
    </div>
    <div class="header-right">
        <div class="notif-container">
            <button class="notif-bell" type="button" title="Notifications" data-toggle="notif-panel">
                🔔
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-dot"><?= $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-panel" id="notifPanel">
                <div class="notif-panel-header">
                    <h3>Notifications</h3>
                    <a href="<?= $base; ?>/resources/views/pages/dashboard/notifications.php" class="view-all-link">View All</a>
                </div>
                <div class="notif-panel-content" id="notifPanelContent">
                    <div class="notif-loading">Loading...</div>
                </div>
            </div>
        </div>
        <span>Welcome, <?= htmlspecialchars($username); ?></span>
        <a class="btn btn-primary" href="<?= $base; ?>/resources/views/pages/auth/logout.php">Logout</a>
    </div>
</header>


