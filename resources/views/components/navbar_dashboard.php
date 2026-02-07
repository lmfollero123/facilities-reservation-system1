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
        <button class="btn btn-outline sidebar-toggle-btn" data-sidebar-toggle aria-expanded="true" title="Toggle Sidebar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="9" y1="3" x2="9" y2="21"></line>
            </svg>
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
                    <a href="<?= $base; ?>/dashboard/notifications" class="view-all-link">View All</a>
                </div>
                <div class="notif-panel-content" id="notifPanelContent">
                    <div class="notif-loading">Loading...</div>
                </div>
            </div>
        </div>
        <div class="theme-toggle-container">
            <button class="theme-toggle-btn" id="themeToggle" type="button" title="Toggle Dark Mode" aria-label="Toggle Dark Mode">
                <span class="theme-icon theme-icon-light">☀️</span>
                <span class="theme-icon theme-icon-dark">🌙</span>
            </button>
        </div>
        <a class="btn btn-primary confirm-action" data-message="Are you sure you want to log out?" href="<?= $base; ?>/resources/views/pages/auth/logout.php">Logout</a>
    </div>
</header>


