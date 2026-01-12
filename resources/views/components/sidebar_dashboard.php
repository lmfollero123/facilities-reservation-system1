<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
$role = $_SESSION['role'] ?? 'Resident';
$current = $_SERVER['PHP_SELF'] ?? '';

$primaryLinks = [
    ['label' => 'Dashboard', 'href' => $base . '/resources/views/pages/dashboard/index.php', 'icon' => 'dashboard'],
    ['label' => 'Book a Facility', 'href' => $base . '/resources/views/pages/dashboard/book_facility.php', 'icon' => 'calendar-plus'],
    ['label' => 'My Reservations', 'href' => $base . '/resources/views/pages/dashboard/my_reservations.php', 'icon' => 'calendar'],
    ['label' => 'Smart Scheduler', 'href' => $base . '/resources/views/pages/dashboard/ai_scheduling.php', 'icon' => 'robot'],
];

$opsLinks = [];
if (in_array($role, ['Admin', 'Staff'], true)) {
    $opsLinks = [
        ['label' => 'Reservation Approvals', 'href' => $base . '/resources/views/pages/dashboard/reservations_manage.php', 'icon' => 'check-circle'],
        ['label' => 'Facility Management', 'href' => $base . '/resources/views/pages/dashboard/facility_management.php', 'icon' => 'building'],
        ['label' => 'Announcements', 'href' => $base . '/resources/views/pages/dashboard/announcements_manage.php', 'icon' => 'megaphone'],
        ['label' => 'Maintenance Integration', 'href' => $base . '/resources/views/pages/dashboard/maintenance_integration.php', 'icon' => 'wrench'],
        ['label' => 'Infrastructure Projects', 'href' => $base . '/resources/views/pages/dashboard/infrastructure_projects_integration.php', 'icon' => 'hammer'],
        ['label' => 'Utilities Integration', 'href' => $base . '/resources/views/pages/dashboard/utilities_integration.php', 'icon' => 'bolt'],
        ['label' => 'Reports & Analytics', 'href' => $base . '/resources/views/pages/dashboard/reports.php', 'icon' => 'chart-bar'],
        ['label' => 'User Management', 'href' => $base . '/resources/views/pages/dashboard/user_management.php', 'icon' => 'users'],
        ['label' => 'Document Management', 'href' => $base . '/resources/views/pages/dashboard/document_management.php', 'icon' => 'folder'],
        ['label' => 'Contact Inquiries', 'href' => $base . '/resources/views/pages/dashboard/contact_inquiries.php', 'icon' => 'envelope'],
    ];
}

$bottomLinks = [];
if (in_array($role, ['Admin', 'Staff'], true)) {
    $bottomLinks[] = ['label' => 'Audit Trail', 'href' => $base . '/resources/views/pages/dashboard/audit_trail.php', 'icon' => 'file-text'];
}
$bottomLinks[] = ['label' => 'Profile', 'href' => $base . '/resources/views/pages/dashboard/profile.php', 'icon' => 'user'];
?>

<aside class="sidebar">
    <div class="brand">
        <img src="<?= $base; ?>/public/img/logocityhall.png" alt="City Hall Logo" style="height: 32px; width: auto; object-fit: contain;">
        <span>Facilities</span>
        <button type="button" class="sidebar-close" data-sidebar-close aria-label="Close sidebar">✕</button>
    </div>
    <nav aria-label="Dashboard navigation">
        <div class="sidebar-section">
            <div class="sidebar-section-title">Main</div>
            <?php foreach ($primaryLinks as $link):
                $isActive = str_contains($current, basename($link['href']));
                ?>
                <a href="<?= $link['href']; ?>" class="<?= $isActive ? 'active' : ''; ?>" data-icon="<?= $link['icon']; ?>">
                    <svg class="sidebar-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <?php
                        $iconPaths = [
                            'dashboard' => '<path d="M3 13H11V3H3V13ZM3 21H11V15H3V21ZM13 21H21V11H13V21ZM13 3V9H21V3H13Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                            'calendar-plus' => '<path d="M19 21H5C3.89543 21 3 20.1046 3 19V7C3 5.89543 3.89543 5 5 5H19C20.1046 5 21 5.89543 21 7V19C21 20.1046 20.1046 21 19 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3V7M8 3V7M3 11H21M12 15V19M15 17H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                            'calendar' => '<path d="M19 21H5C3.89543 21 3 20.1046 3 19V7C3 5.89543 3.89543 5 5 5H19C20.1046 5 21 5.89543 21 7V19C21 20.1046 20.1046 21 19 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3V7M8 3V7M3 11H21M9 15H15M11 13V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                            'robot' => '<path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM21 9V7L18 4H6L3 7V9C1.9 9 1 9.9 1 11V18C1 19.1 1.9 20 3 20H21C22.1 20 23 19.1 23 18V11C23 9.9 22.1 9 21 9ZM7.5 15C6.7 15 6 14.3 6 13.5C6 12.7 6.7 12 7.5 12C8.3 12 9 12.7 9 13.5C9 14.3 8.3 15 7.5 15ZM16.5 15C15.7 15 15 14.3 15 13.5C15 12.7 15.7 12 16.5 12C17.3 12 18 12.7 18 13.5C18 14.3 17.3 15 16.5 15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                            'message-circle' => '<path d="M21 11.5C21.0034 12.8199 20.6951 14.1219 20.1 15.3C19.3944 16.7118 18.3098 17.8992 16.9674 18.7293C15.6251 19.5594 14.0782 19.9994 12.5 20C11.1801 20.0035 9.87812 19.6951 8.7 19.1L3 21L4.9 15.3C4.30493 14.1219 3.99656 12.8199 4 11.5C4.00061 9.92179 4.44061 8.37488 5.27072 7.03258C6.10083 5.69028 7.28825 4.6056 8.7 3.90003C9.87812 3.30496 11.1801 2.99659 12.5 3.00003H13C15.0843 3.11502 17.053 3.99479 18.5291 5.47089C20.0052 6.94699 20.885 8.91568 21 11V11.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                        ];
                        echo $iconPaths[$link['icon']] ?? '';
                        ?>
                    </svg>
                    <span><?= $link['label']; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($opsLinks): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-title collapsible-header" data-collapse-target="operations-menu">
                    <span>Operations</span>
                    <svg class="chevron-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="collapsible-content" id="operations-menu" data-collapsed="false">
                    <?php foreach ($opsLinks as $link):
                        $isActive = str_contains($current, basename($link['href']));
                        ?>
                        <a href="<?= $link['href']; ?>" class="<?= $isActive ? 'active' : ''; ?>" data-icon="<?= $link['icon']; ?>">
                            <svg class="sidebar-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <?php
                                $iconPaths = [
                                    'check-circle' => '<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.7088 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'building' => '<path d="M3 21H21M5 21V7L13 2V21M5 21H9M9 21V13H15V21M15 21H19M9 9H9.01M9 13H9.01M15 9H15.01M15 13H15.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'wrench' => '<path d="M14.7 6.3C15.1 5.9 15.1 5.3 14.7 4.9L13.1 3.3C12.7 2.9 12.1 2.9 11.7 3.3L10.5 4.5L7.5 1.5C6.7 0.7 5.3 0.7 4.5 1.5L1.5 4.5C0.7 5.3 0.7 6.7 1.5 7.5L4.5 10.5L3.3 11.7C2.9 12.1 2.9 12.7 3.3 13.1L4.9 14.7C5.3 15.1 5.9 15.1 6.3 14.7L7.5 13.5L10.5 16.5C11.3 17.3 12.7 17.3 13.5 16.5L16.5 13.5L17.7 14.7C18.1 15.1 18.7 15.1 19.1 14.7L20.7 13.1C21.1 12.7 21.1 12.1 20.7 11.7L19.5 10.5L22.5 7.5C23.3 6.7 23.3 5.3 22.5 4.5L19.5 1.5C18.7 0.7 17.3 0.7 16.5 1.5L13.5 4.5L14.7 6.3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'hammer' => '<path d="M15 12L3 24L0 21L12 9M18 6L21 3L18 0L15 3L12 6L15 9L18 6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 18L6 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'map' => '<path d="M1 6V22L8 18L16 22L23 18V2L16 6L8 2L1 6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 2V18M16 6V22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'bolt' => '<path d="M13 2L3 14H12L11 22L21 10H12L13 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'road' => '<path d="M4 19H20M4 19L2 12L4 5H20L22 12L20 19M4 19H20M8 12H16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'lightbulb' => '<path d="M9 21H15M12 3C8.7 3 6 5.7 6 9C6 11.2 7.2 13.1 9 14.2V17H15V14.2C16.8 13.1 18 11.2 18 9C18 5.7 15.3 3 12 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'chart-bar' => '<path d="M3 3V21H21M7 16L12 11L16 15L21 10M21 10H16M21 10V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'users' => '<path d="M17 21V19C17 17.9 16.1 17 15 17H9C7.9 17 7 17.9 7 19V21M21 21V19C20.9993 17.1 20.1 15.3 18.6 14.1M3 21V19C3.00068 17.1 3.9 15.3 5.4 14.1M12 11C13.6569 11 15 9.65685 15 8C15 6.34315 13.6569 5 12 5C10.3431 5 9 6.34315 9 8C9 9.65685 10.3431 11 12 11ZM12 11C14.2091 11 16 9.20914 16 7C16 4.79086 14.2091 3 12 3C9.79086 3 8 4.79086 8 7C8 9.20914 9.79086 11 12 11ZM18 13C19.6569 13 21 11.6569 21 10C21 8.34315 19.6569 7 18 7M6 13C4.34315 13 3 11.6569 3 10C3 8.34315 4.34315 7 6 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'envelope' => '<path d="M3 8L12 13L21 8M3 8L3 16C3 17.1 3.9 18 5 18H19C20.1 18 21 17.1 21 16V8M3 8L12 3L21 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                    'folder' => '<path d="M22 19C22 19.5304 21.7893 20.0391 21.4142 20.4142C21.0391 20.7893 20.5304 21 20 21H4C3.46957 21 2.96086 20.7893 2.58579 20.4142C2.21071 20.0391 2 19.5304 2 19V5C2 4.46957 2.21071 3.96086 2.58579 3.58579C2.96086 3.21071 3.46957 3 4 3H9L11 6H20C20.5304 6 21.0391 6.21071 21.4142 6.58579C21.7893 6.96086 22 7.46957 22 8V19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                                ];
                                echo $iconPaths[$link['icon']] ?? '';
                                ?>
                            </svg>
                            <span><?= $link['label']; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="sidebar-section sidebar-bottom">
            <div class="sidebar-section-title">Account</div>
            <?php foreach ($bottomLinks as $link):
                $isActive = str_contains($current, basename($link['href']));
                ?>
                <a href="<?= $link['href']; ?>" class="<?= $isActive ? 'active' : ''; ?>" data-icon="<?= $link['icon']; ?>">
                    <svg class="sidebar-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <?php
                        $iconPaths = [
                            'file-text' => '<path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 2V8H20M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                            'user' => '<path d="M20 21V19C20 17.9 19.1 17 18 17H6C4.9 17 4 17.9 4 19V21M16 7C16 9.2 14.2 11 12 11C9.8 11 8 9.2 8 7C8 4.8 9.8 3 12 3C14.2 3 16 4.8 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
                        ];
                        echo $iconPaths[$link['icon']] ?? '';
                        ?>
                    </svg>
                    <span><?= $link['label']; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
</aside>

