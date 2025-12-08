<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
$role = $_SESSION['role'] ?? 'Resident';
$current = $_SERVER['PHP_SELF'] ?? '';

$primaryLinks = [
    ['label' => 'Dashboard', 'href' => $base . '/resources/views/pages/dashboard/index.php', 'icon' => '🏛'],
    ['label' => 'Book a Facility', 'href' => $base . '/resources/views/pages/dashboard/book_facility.php', 'icon' => '📝'],
    ['label' => 'Calendar & Schedule', 'href' => $base . '/resources/views/pages/dashboard/calendar.php', 'icon' => '🗓'],
    ['label' => 'Reports & Analytics', 'href' => $base . '/resources/views/pages/dashboard/reports.php', 'icon' => '📊'],
    ['label' => 'AI Scheduling', 'href' => $base . '/resources/views/pages/dashboard/ai_scheduling.php', 'icon' => '🤖'],
];

$opsLinks = [];
if (in_array($role, ['Admin', 'Staff'], true)) {
    $opsLinks = [
        ['label' => 'Reservation Approvals', 'href' => $base . '/resources/views/pages/dashboard/reservations_manage.php', 'icon' => '✅'],
        ['label' => 'Facility Management', 'href' => $base . '/resources/views/pages/dashboard/facility_management.php', 'icon' => '🏟'],
        ['label' => 'User Management', 'href' => $base . '/resources/views/pages/dashboard/user_management.php', 'icon' => '👥'],
    ];
}

$bottomLinks = [];
if (in_array($role, ['Admin', 'Staff'], true)) {
    $bottomLinks[] = ['label' => 'Audit Trail', 'href' => $base . '/resources/views/pages/dashboard/audit_trail.php', 'icon' => '📜'];
}
$bottomLinks[] = ['label' => 'Profile', 'href' => $base . '/resources/views/pages/dashboard/profile.php', 'icon' => '👤'];
?>

<aside class="sidebar">
    <div class="brand">
        <span class="logo">LGU</span>
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
                    <?= $link['label']; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($opsLinks): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-title">Operations</div>
                <?php foreach ($opsLinks as $link):
                    $isActive = str_contains($current, basename($link['href']));
                    ?>
                    <a href="<?= $link['href']; ?>" class="<?= $isActive ? 'active' : ''; ?>" data-icon="<?= $link['icon']; ?>">
                        <?= $link['label']; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="sidebar-section sidebar-bottom">
            <div class="sidebar-section-title">Account</div>
            <?php foreach ($bottomLinks as $link):
                $isActive = str_contains($current, basename($link['href']));
                ?>
                <a href="<?= $link['href']; ?>" class="<?= $isActive ? 'active' : ''; ?>" data-icon="<?= $link['icon']; ?>">
                    <?= $link['label']; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
</aside>

