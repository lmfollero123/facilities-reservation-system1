<?php
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
$currentPage = $_SERVER['PHP_SELF'] ?? '';
$navLinks = [
    ['label' => 'Home', 'href' => $base . '/resources/views/pages/public/home.php', 'anchor' => '#page-top'],
    ['label' => 'Facilities', 'href' => $base . '/resources/views/pages/public/facilities.php', 'anchor' => '#portfolio'],
    ['label' => 'About', 'href' => '#about'],
    ['label' => 'Contact', 'href' => $base . '/resources/views/pages/public/contact.php', 'anchor' => '#contact'],
    ['label' => 'Login', 'href' => $base . '/resources/views/pages/auth/login.php'],
    ['label' => 'Register', 'href' => $base . '/resources/views/pages/auth/register.php'],
];
?>
<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-light fixed-top py-3" id="mainNav">
    <div class="container px-4 px-lg-5">
        <a class="navbar-brand" href="<?= $base; ?>/resources/views/pages/public/home.php">Barangay Culiat Public Facilities Reservation System</a>
        <button class="navbar-toggler navbar-toggler-right" type="button" data-bs-toggle="collapse" data-bs-target="#navbarResponsive" aria-controls="navbarResponsive" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarResponsive">
            <ul class="navbar-nav ms-auto my-2 my-lg-0">
                <?php 
                $isHomePage = str_contains($currentPage, 'home.php');
                foreach ($navLinks as $link): 
                    // Determine the correct href based on current page and link type
                    $hrefValue = $link['href'] ?? '';
                    
                    // Check if href is an anchor-only link (starts with #)
                    if (strpos($hrefValue, '#') === 0) {
                        // Anchor-only link: use anchor if on home page, otherwise navigate to home with anchor
                        $href = $isHomePage ? $hrefValue : ($base . '/resources/views/pages/public/home.php' . $hrefValue);
                    } elseif (isset($link['anchor'])) {
                        // Link has both href and anchor
                        $targetPage = basename($hrefValue);
                        $isOnTargetPage = str_contains($currentPage, $targetPage);
                        
                        if ($isOnTargetPage) {
                            // On the target page, use anchor for smooth scroll
                            $href = $link['anchor'];
                        } else {
                            // Not on target page, navigate to page with anchor
                            $href = $hrefValue . $link['anchor'];
                        }
                    } else {
                        // No anchor, just use the href
                        $href = $hrefValue;
                    }
                    
                    // Determine if link is active
                    $isActive = false;
                    if (strpos($hrefValue, '#') !== 0 && !empty($hrefValue)) {
                        $isActive = str_contains($currentPage, basename($hrefValue));
                    }
                ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $isActive ? ' active' : ''; ?>" href="<?= htmlspecialchars($href); ?>">
                            <?= htmlspecialchars($link['label']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>



