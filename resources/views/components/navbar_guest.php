<?php
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
$currentPage = $_SERVER['PHP_SELF'] ?? '';
$navLinks = [
    ['label' => frs_t('nav.home'), 'href' => $base . '/', 'anchor' => '#page-top'],
    ['label' => frs_t('nav.facilities'), 'href' => $base . '/facilities'],
    ['label' => frs_t('nav.announcements'), 'href' => $base . '/announcements'],
    ['label' => frs_t('nav.faq'), 'href' => $base . '/faqs'],
    ['label' => frs_t('nav.contact'), 'href' => $base . '/contact', 'anchor' => '#contact'],
];
?>
<!-- Navigation - Modern dark navbar -->
<?php $navStartsScrolled = empty($isHomePage); ?>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top py-3 public-navbar-modern<?= $navStartsScrolled ? ' navbar-scrolled' : ''; ?>" id="mainNav" data-nav-starts-scrolled="<?= $navStartsScrolled ? '1' : '0'; ?>">
    <a class="navbar-brand public-nav-brand" href="<?= $base; ?>/">
        <img src="<?= $base; ?>/public/img/brgy-culiat-logo.png" alt="Infra Gov Services">
        <span class="brand-name">Barangay Culiat</span>
        <span class="brand-accent">CPRFS</span>
    </a>
    
    <div class="collapse navbar-collapse" id="navbarResponsive">
        <ul class="navbar-nav my-2 my-lg-0">
            <?php 
            foreach ($navLinks as $link): 
                $href = $link['href'];
                if (isset($link['anchor'])) {
                    $href .= $link['anchor'];
                }
                $isActive = false;
                $currentPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
                $linkPath = trim(parse_url($link['href'], PHP_URL_PATH), '/');
                if ($linkPath && strpos($currentPath, $linkPath) === 0) {
                    $isActive = true;
                }
            ?>
                <li class="nav-item">
                    <a class="nav-link<?= $isActive ? ' active' : ''; ?>" href="<?= htmlspecialchars($href); ?>"><?= htmlspecialchars($link['label']); ?></a>
                </li>
            <?php endforeach; ?>
            <li class="nav-item nav-item-theme d-none d-md-flex" style="list-style:none;">
                <button type="button" class="theme-toggle-btn theme-toggle-nav" id="themeToggle" aria-label="<?= frs_te('nav.toggle_dark_mode'); ?>" title="<?= frs_te('nav.toggle_dark_mode'); ?>" style="pointer-events:auto;">
                    <i class="bi bi-sun-fill theme-icon-light"></i>
                    <i class="bi bi-moon-fill theme-icon-dark"></i>
                </button>
            </li>
            <li class="nav-item d-none d-md-flex" style="list-style:none; align-items:center;">
                <?= frs_language_switcher('public-nav-lang'); ?>
            </li>
        </ul>
        <div class="public-nav-actions d-none d-lg-flex">
            <a href="<?= $base; ?>/login" class="btn public-btn-login"><?= frs_te('nav.login'); ?></a>
            <a href="<?= $base; ?>/register" class="btn public-btn-register"><?= frs_te('nav.register'); ?></a>
        </div>
    </div>

    <div class="public-nav-actions public-nav-actions-mobile d-lg-none">
        <a href="<?= $base; ?>/login" class="btn public-btn-login btn-sm"><?= frs_te('nav.login'); ?></a>
        <a href="<?= $base; ?>/register" class="btn public-btn-register btn-sm"><?= frs_te('nav.register'); ?></a>
    </div>
    <button class="navbar-toggler navbar-toggler-right" type="button" id="mobileNavToggle" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <!-- Date/Time display - positioned absolutely on the far right -->
    <div id="navbar-datetime" class="navbar-datetime">
        <span id="navbar-date"></span>
        <span id="navbar-time"></span>
    </div>
</nav>

<!-- Mobile Sidebar Menu -->
<aside class="mobile-nav-sidebar" id="mobileNavSidebar">
    <div class="mobile-nav-header">
        <img src="<?= $base; ?>/public/img/brgy-culiat-logo.png" alt="Infra Gov Services" style="height: 44px; width: auto; object-fit: contain;">
        <span><?= frs_te('nav.menu'); ?></span>
        <button type="button" class="mobile-nav-close" id="mobileNavClose" aria-label="<?= frs_te('nav.close_menu'); ?>">✕</button>
    </div>
    <nav class="mobile-nav-menu">
        <div class="mobile-nav-theme-wrap" style="padding: 0.75rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;">
            <button type="button" class="theme-toggle-btn mobile-theme-toggle" id="themeToggleMobile" aria-label="<?= frs_te('nav.toggle_dark_mode'); ?>">
                <i class="bi bi-sun-fill theme-icon-light"></i>
                <i class="bi bi-moon-fill theme-icon-dark"></i>
                <span class="theme-toggle-label ms-2"><?= frs_te('nav.dark_mode'); ?></span>
            </button>
            <?= frs_language_switcher('mobile-nav-lang'); ?>
        </div>
        <ul class="mobile-nav-list">
            <?php 
            foreach ($navLinks as $link): 
                $href = $link['href'];
                if (isset($link['anchor'])) {
                    $href .= $link['anchor'];
                }
                
                // Determine if link is active (simplified for clean URLs)
                $isActive = false;
                $currentPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
                $linkPath = trim(parse_url($link['href'], PHP_URL_PATH), '/');
                if ($linkPath && strpos($currentPath, $linkPath) === 0) {
                    $isActive = true;
                }
            ?>
                <li class="mobile-nav-item">
                    <a class="mobile-nav-link<?= $isActive ? ' active' : ''; ?>" href="<?= htmlspecialchars($href); ?>">
                        <?= htmlspecialchars($link['label']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="mobile-nav-actions">
            <a href="<?= $base; ?>/login" class="mobile-nav-btn mobile-nav-btn-login"><?= frs_te('nav.login'); ?></a>
            <a href="<?= $base; ?>/register" class="mobile-nav-btn mobile-nav-btn-register"><?= frs_te('nav.register'); ?></a>
        </div>
    </nav>
</aside>

<!-- Mobile Sidebar Backdrop -->
<div class="mobile-nav-backdrop" id="mobileNavBackdrop"></div>

<script>
// Update navbar date and time
function updateNavbarDateTime() {
    const now = new Date();
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    
    const dayName = days[now.getDay()];
    const monthName = months[now.getMonth()];
    const day = now.getDate();
    const year = now.getFullYear();
    
    const hours = now.getHours();
    const minutes = now.getMinutes();
    const seconds = now.getSeconds();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours % 12 || 12;
    const displayMinutes = minutes.toString().padStart(2, '0');
    const displaySeconds = seconds.toString().padStart(2, '0');
    
    const dateElement = document.getElementById('navbar-date');
    const timeElement = document.getElementById('navbar-time');
    
    if (dateElement) {
        dateElement.textContent = `${dayName}, ${monthName} ${day}, ${year}`;
    }
    if (timeElement) {
        timeElement.textContent = `${displayHours}:${displayMinutes}:${displaySeconds} ${ampm}`;
    }
}

// Update immediately and then every second
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        updateNavbarDateTime();
        setInterval(updateNavbarDateTime, 1000);
    });
} else {
    updateNavbarDateTime();
    setInterval(updateNavbarDateTime, 1000);
}

// Navbar scroll behavior - turn white when scrolling.
// Pages without a dark hero (anything but Home) start pre-scrolled via
// the .navbar-scrolled class rendered server-side, and stay that way.
(function() {
    const navbar = document.getElementById('mainNav');
    if (!navbar) return;
    if (navbar.dataset.navStartsScrolled === '1') return;

    const scrollThreshold = 50; // Change color after scrolling 50px

    window.addEventListener('scroll', function() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

        if (scrollTop > scrollThreshold) {
            // Scrolled down - make navbar white
            navbar.classList.add('navbar-scrolled');
        } else {
            // At top - make navbar transparent
            navbar.classList.remove('navbar-scrolled');
        }
    }, { passive: true });
})();

// Theme toggle is handled by main.js (unified for guest + dashboard)

// Mobile Navigation Sidebar Toggle
(function() {
    const mobileNavToggle = document.getElementById('mobileNavToggle');
    const mobileNavSidebar = document.getElementById('mobileNavSidebar');
    const mobileNavClose = document.getElementById('mobileNavClose');
    const mobileNavBackdrop = document.getElementById('mobileNavBackdrop');
    
    if (!mobileNavToggle || !mobileNavSidebar || !mobileNavClose || !mobileNavBackdrop) {
        console.error('Mobile navigation elements not found');
        return;
    }
    
    const openMobileNav = () => {
        mobileNavSidebar.classList.add('active');
        mobileNavBackdrop.classList.add('active');
        mobileNavToggle.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden'; // Prevent body scroll when sidebar is open
    };
    
    const closeMobileNav = () => {
        mobileNavSidebar.classList.remove('active');
        mobileNavBackdrop.classList.remove('active');
        mobileNavToggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = ''; // Restore body scroll
    };
    
    mobileNavToggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        openMobileNav();
    });
    
    mobileNavClose.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        closeMobileNav();
    });
    
    mobileNavBackdrop.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        closeMobileNav();
    });
    
    // Close sidebar when clicking on a nav link
    const mobileNavLinks = mobileNavSidebar.querySelectorAll('.mobile-nav-link');
    mobileNavLinks.forEach(link => {
        link.addEventListener('click', () => {
            setTimeout(closeMobileNav, 100); // Small delay for smooth transition
        });
    });
    
    // Close on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && mobileNavSidebar.classList.contains('active')) {
            closeMobileNav();
        }
    });
})();
</script>



