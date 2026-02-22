<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
$username = $_SESSION['user_name'] ?? 'Guest';
$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? 'Resident';

require_once __DIR__ . '/../../../config/notifications.php';
$unreadCount = $userId ? getUnreadNotificationCount($userId) : 0;

// Global search: flat list of { label, url, keywords } for dashboard pages (role-aware)
$dashboardSearchItems = [
    ['label' => 'Dashboard', 'url' => $base . '/dashboard', 'keywords' => 'dashboard home main'],
    ['label' => 'Book a Facility', 'url' => $base . '/dashboard/book-facility', 'keywords' => 'booking book facility reserve reservation'],
    ['label' => 'My Reservations', 'url' => $base . '/dashboard/my-reservations', 'keywords' => 'booking reservations my bookings list'],
    ['label' => 'Calendar', 'url' => $base . '/dashboard/calendar', 'keywords' => 'calendar schedule view dates'],
    ['label' => 'Smart Scheduler', 'url' => $base . '/dashboard/ai-scheduling', 'keywords' => 'AI smart scheduler scheduling'],
    ['label' => 'Profile', 'url' => $base . '/dashboard/profile', 'keywords' => 'profile account my profile security'],
];
if (in_array($role, ['Admin', 'Staff'], true)) {
    $dashboardSearchItems = array_merge($dashboardSearchItems, [
        ['label' => 'Reservation Approvals', 'url' => $base . '/dashboard/reservations-manage', 'keywords' => 'reservations approvals manage pending approve'],
        ['label' => 'Facility Management', 'url' => $base . '/dashboard/facility-management', 'keywords' => 'facility management facilities admin'],
        ['label' => 'Announcements', 'url' => $base . '/dashboard/announcements-manage', 'keywords' => 'announcements news communications'],
        ['label' => 'Contact Information', 'url' => $base . '/dashboard/contact-info', 'keywords' => 'contact info communications'],
        ['label' => 'Maintenance', 'url' => $base . '/dashboard/maintenance-integration', 'keywords' => 'maintenance integration'],
        ['label' => 'Infrastructure Projects', 'url' => $base . '/dashboard/infrastructure-projects', 'keywords' => 'infrastructure projects'],
        ['label' => 'Utilities', 'url' => $base . '/dashboard/utilities-integration', 'keywords' => 'utilities integration'],
        ['label' => 'Reports & Analytics', 'url' => $base . '/dashboard/reports', 'keywords' => 'reports analytics charts'],
    ]);
}
if ($role === 'Admin') {
    $dashboardSearchItems = array_merge($dashboardSearchItems, [
        ['label' => 'User Management', 'url' => $base . '/dashboard/user-management', 'keywords' => 'users management admin'],
        ['label' => 'Document Management', 'url' => $base . '/dashboard/document-management', 'keywords' => 'documents files management'],
        ['label' => 'Audit Trail', 'url' => $base . '/dashboard/audit-trail', 'keywords' => 'audit trail logs'],
    ]);
}
$dashboardSearchItems[] = ['label' => 'Notifications', 'url' => $base . '/dashboard/notifications', 'keywords' => 'notifications alerts'];
?>
<header class="dashboard-header" id="dashboardHeader">
    <div class="dashboard-header-left">
        <button class="btn btn-outline sidebar-toggle-btn" data-sidebar-toggle aria-expanded="true" title="Toggle Sidebar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="9" y1="3" x2="9" y2="21"></line>
            </svg>
        </button>
        <button type="button" class="btn btn-outline dashboard-mobile-search-trigger" aria-label="Open search" title="Search dashboard" id="dashboardMobileSearchTrigger">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
        <div class="dashboard-global-search-wrapper" id="dashboardGlobalSearchWrapper">
            <input type="search" class="dashboard-global-search" id="dashboardGlobalSearch" placeholder="Search dashboard (e.g. booking, reservations)..." autocomplete="off" aria-label="Search dashboard">
            <span class="dashboard-global-search-icon" aria-hidden="true">🔍</span>
            <button type="button" class="dashboard-search-close" aria-label="Close search" id="dashboardSearchClose">×</button>
            <div class="dashboard-global-search-results" id="dashboardGlobalSearchResults" role="listbox" aria-hidden="true"></div>
        </div>
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
<script>
(function() {
    var searchItems = <?= json_encode($dashboardSearchItems); ?>;
    var input = document.getElementById('dashboardGlobalSearch');
    var resultsEl = document.getElementById('dashboardGlobalSearchResults');
    if (!input || !resultsEl) return;
    function showResults(items) {
        if (items.length === 0) {
            resultsEl.innerHTML = '<div class="dashboard-search-no-results">No matches</div>';
        } else {
            resultsEl.innerHTML = items.slice(0, 8).map(function(item) {
                return '<a href="' + (item.url || '') + '" class="dashboard-search-result-item" role="option">' + (item.label || '') + '</a>';
            }).join('');
        }
        resultsEl.setAttribute('aria-hidden', 'false');
        resultsEl.style.display = 'block';
    }
    function hideResults() {
        resultsEl.style.display = 'none';
        resultsEl.setAttribute('aria-hidden', 'true');
    }
    function filterItems(q) {
        q = (q || '').toLowerCase().trim();
        if (q.length < 2) return [];
        return searchItems.filter(function(item) {
            var text = (item.label + ' ' + (item.keywords || '')).toLowerCase();
            return text.indexOf(q) !== -1;
        });
    }
    input.addEventListener('input', function() {
        var q = this.value;
        var items = filterItems(q);
        if (q.length < 2) { hideResults(); return; }
        showResults(items);
    });
    input.addEventListener('focus', function() {
        if (this.value.trim().length >= 2) showResults(filterItems(this.value));
    });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { hideResults(); this.blur(); }
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dashboard-global-search-wrapper')) hideResults();
    });
    resultsEl.addEventListener('click', function(e) {
        var a = e.target.closest('a');
        if (a && a.href) { hideResults(); }
    });

    var header = document.getElementById('dashboardHeader');
    var mobileTrigger = document.getElementById('dashboardMobileSearchTrigger');
    var searchClose = document.getElementById('dashboardSearchClose');
    var wrapper = document.getElementById('dashboardGlobalSearchWrapper');
    if (header && mobileTrigger && wrapper) {
        mobileTrigger.addEventListener('click', function() {
            header.classList.add('search-open');
            input.focus();
        });
        if (searchClose) searchClose.addEventListener('click', function() { header.classList.remove('search-open'); });
        document.addEventListener('click', function(e) {
            if (header.classList.contains('search-open') && !wrapper.contains(e.target) && e.target !== mobileTrigger) header.classList.remove('search-open');
        });
    }
})();
</script>


