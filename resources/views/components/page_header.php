<?php
/**
 * Reusable page header - green section with icon, title, tagline (reference: announcements-style header)
 * Usage: $pageHeaderIcon, $pageHeaderTitle, $pageHeaderTagline must be set before include
 */
$pageHeaderIcon = $pageHeaderIcon ?? 'bi-building';
$pageHeaderTitle = $pageHeaderTitle ?? 'Page Title';
$pageHeaderTagline = $pageHeaderTagline ?? '';
$base = $base ?? base_path();
?>
<section class="page-header-hero">
    <div class="page-header-pattern"></div>
    <div class="page-header-content">
        <div class="page-header-icon" data-icon="<?= htmlspecialchars($pageHeaderIcon); ?>">
            <i class="bi <?= htmlspecialchars($pageHeaderIcon); ?>"></i>
        </div>
        <h1 class="page-header-title"><?= htmlspecialchars($pageHeaderTitle); ?></h1>
        <?php if ($pageHeaderTagline): ?>
            <p class="page-header-tagline"><?= htmlspecialchars($pageHeaderTagline); ?></p>
        <?php endif; ?>
    </div>
    <div class="page-header-wave">
        <svg viewBox="0 0 1440 60" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,30 C360,60 720,0 1080,30 C1260,45 1380,45 1440,30 L1440,60 L0,60 Z" fill="white"/>
        </svg>
    </div>
</section>
