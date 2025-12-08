<?php
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
$navLinks = [
    ['label' => 'Home', 'href' => $base . '/resources/views/pages/public/home.php'],
    ['label' => 'Facilities', 'href' => $base . '/resources/views/pages/public/facilities.php'],
    ['label' => 'Contact', 'href' => $base . '/resources/views/pages/public/contact.php'],
    ['label' => 'Login', 'href' => $base . '/resources/views/pages/auth/login.php'],
    ['label' => 'Register', 'href' => $base . '/resources/views/pages/auth/register.php', 'class' => 'cta'],
];
?>
<header class="guest-navbar">
    <div class="container">
        <div class="brand">LGU Facilities Reservation</div>
        <nav class="guest-nav-links" aria-label="Primary navigation">
            <?php foreach ($navLinks as $link): ?>
                <a href="<?= $link['href']; ?>" class="<?= $link['class'] ?? ''; ?>">
                    <?= $link['label']; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>
    <nav class="guest-nav-mobile" aria-label="Mobile navigation">
        <?php foreach ($navLinks as $link): ?>
            <a href="<?= $link['href']; ?>" class="<?= $link['class'] ?? ''; ?>">
                <?= $link['label']; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</header>



