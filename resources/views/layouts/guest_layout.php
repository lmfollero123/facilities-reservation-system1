<?php
require_once __DIR__ . '/../../../config/app.php';
$pageTitle = $pageTitle ?? 'LGU Facilities Reservation';
$bodyClass = $bodyClass ?? '';
// VISUAL CHANGE ONLY - Add landing-page class for home page and public pages
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
// Remove hash and query string for checking
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?? $requestUri;
$phpSelf = $_SERVER['PHP_SELF'] ?? '';
$isHomePage = strpos($phpSelf, 'home.php') !== false || $requestPath === '/' || (strpos($requestPath, '/home') !== false);
$isPublicPage = strpos($phpSelf, 'announcements.php') !== false ||
                strpos($requestPath, '/announcements') !== false ||
                strpos($phpSelf, 'facilities.php') !== false || 
                strpos($requestPath, '/facilities') !== false ||
                strpos($phpSelf, 'facility_details.php') !== false ||
                strpos($requestPath, '/facility-details') !== false ||
                strpos($phpSelf, 'contact.php') !== false ||
                strpos($requestPath, '/contact') !== false ||
                strpos($phpSelf, 'faq.php') !== false ||
                strpos($requestPath, '/faq') !== false ||
                strpos($phpSelf, 'login.php') !== false ||
                strpos($requestPath, '/login') !== false ||
                strpos($phpSelf, 'register.php') !== false ||
                strpos($requestPath, '/register') !== false;
if ($isHomePage || $isPublicPage) {
    $bodyClass = ($bodyClass ? $bodyClass . ' ' : '') . 'landing-page';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
    (function(){var t;try{t=localStorage.getItem('publicTheme')||localStorage.getItem('theme')||'light';}catch(e){t='light';}
    if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle); ?></title>
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css" rel="stylesheet" />
    <!-- Google fonts -->
    <link href="https://fonts.googleapis.com/css?family=Merriweather+Sans:400,700" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css?family=Merriweather:400,300,300italic,400italic,700,700italic" rel="stylesheet" type="text/css" />
    <!-- SimpleLightbox plugin CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/SimpleLightbox/2.1.0/simpleLightbox.min.css" rel="stylesheet" />
    <!-- Favicon (inline to avoid 404) -->
    <link rel="icon" href="data:image/png;base64,iVBORw0KGgo=">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Custom CSS -->
    <?php 
    try {
        $base = base_path();
        $customCss = $base . '/public/css/style.css';
        $appRoot = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__, 3);
    } catch (Exception $e) {
        $base = '';
        $customCss = '/public/css/style.css';
        $appRoot = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__, 3);
    }
    // Cache-busting: Use filemtime so CSS updates on deploy when file changes
    $stylePath = $appRoot . '/public/css/style.css';
    $cssVersion = file_exists($stylePath) ? filemtime($stylePath) : time();
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($customCss); ?>?v=<?= $cssVersion; ?>">
    <link rel="stylesheet" href="<?= $base; ?>/public/css/dark-mode-public.css?v=<?= file_exists($appRoot . '/public/css/dark-mode-public.css') ? filemtime($appRoot . '/public/css/dark-mode-public.css') : time(); ?>">
    <?php if (!empty($useTailwind)): 
        $homeCssPath = $appRoot . '/public/css/home.css';
        $publicCssPath = $appRoot . '/public/css/public-pages.css';
        $tailwindPath = $appRoot . '/public/css/tailwind.css';
        $homeCssVersion = file_exists($homeCssPath) ? filemtime($homeCssPath) : time();
        $publicCssVersion = file_exists($publicCssPath) ? filemtime($publicCssPath) : time();
        $tailwindVersion = file_exists($tailwindPath) ? filemtime($tailwindPath) : time();
    ?>
    <!-- Tailwind CSS (built via CLI) - Preflight/collapse disabled in tailwind.config.js -->
    <link rel="stylesheet" href="<?= $base; ?>/public/css/tailwind.css?v=<?= $tailwindVersion; ?>">
    <link rel="stylesheet" href="<?= $base; ?>/public/css/home.css?v=<?= $homeCssVersion; ?>">
    <link rel="stylesheet" href="<?= $base; ?>/public/css/public-pages.css?v=<?= $publicCssVersion; ?>">
    <?php endif; ?>
    <style>
        /* Fallback: Ensure critical styles load even if external CSS fails */
        body.landing-page {
            background: #ffffff !important;
            min-height: 100vh;
        }
        .contact-section {
            min-height: 100vh !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding-top: 7rem !important;
            padding-bottom: 3rem !important;
            padding-left: 1.25rem !important;
            padding-right: 1.25rem !important;
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 50%, #f0fdf4 100%) !important;
            position: relative;
        }
        .contact-section::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: transparent;
            z-index: 0;
            pointer-events: none;
        }
        .contact-section > * {
            position: relative;
            z-index: 1;
        }
        .auth-card {
            max-width: 440px;
            width: 100%;
            background: #ffffff !important;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            padding: 2.5rem;
            border: none;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .auth-header h1 {
            margin: 0 0 0.5rem;
            color: #1b1b1f !important;
            font-size: 1.75rem;
            font-weight: 700;
        }
        .auth-header p {
            margin: 0;
            color: #4c5b7c !important;
            font-size: 0.95rem;
        }
        .auth-form label {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-weight: 600;
            color: #1b1b1f !important;
            font-size: 0.9rem;
        }
        .auth-form .input-wrapper {
            position: relative;
        }
        .auth-form .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6e84b7;
            font-size: 1.1rem;
            pointer-events: none;
        }
        .auth-form input,
        .auth-form textarea {
            width: 100%;
            padding: 0.9rem 1rem !important;
            border: 2px solid #cdd5e4 !important;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.2s ease;
            background: #ffffff !important;
            color: #1b1b1f !important;
        }
        .auth-form input:focus,
        .auth-form textarea:focus {
            outline: none;
            border-color: #6384d2 !important;
            background: #ffffff !important;
            box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
        }
        .auth-form input::placeholder,
        .auth-form textarea::placeholder {
            color: #8b94a8 !important;
        }
        .btn-primary {
            margin-top: 0.5rem;
            padding: 0.95rem;
            background: linear-gradient(135deg, #059669, #047857) !important;
            color: #fff !important;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(5, 150, 105, 0.3);
        }
        
        /* Ensure facility details page has proper spacing from navbar */
        body.landing-page .section.facility-details-section {
            padding-top: 7rem !important;
            padding-bottom: 3rem !important;
        }
        
        /* CRITICAL OVERRIDES - Section Headers Centering */
        .section-title {
            text-align: center !important;
            color: #1f2937 !important;
        }
        
        .section-subtitle {
            text-align: center !important;
            color: #6b7280 !important;
        }
        
        .section-divider {
            margin-left: auto !important;
            margin-right: auto !important;
        }
        
        /* CRITICAL - Footer green gradient (overrides Tailwind/Bootstrap) */
        footer.modern-footer {
            background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important;
            background-color: #1e3a8a !important;
            color: #ffffff !important;
            padding: 4rem 0 2rem !important;
        }
        footer.modern-footer *,
        footer.modern-footer a,
        footer.modern-footer p,
        footer.modern-footer h3,
        footer.modern-footer h4,
        footer.modern-footer small,
        footer.modern-footer li,
        footer.modern-footer span {
            color: #ffffff !important;
        }
        footer.modern-footer a:hover {
            color: #e0e7ff !important;
        }
        footer.modern-footer .container,
        footer.modern-footer .footer-grid,
        footer.modern-footer .footer-brand,
        footer.modern-footer .footer-section,
        footer.modern-footer .footer-bottom {
            background: transparent !important;
        }
        
        /* CRITICAL OVERRIDES - Hero Section Alignment */
        .modern-hero {
            align-items: flex-start !important;
            padding-top: 12rem !important;
        }
        
        <?php if (!empty($useTailwind)): ?>
        /* CRITICAL - Ensure navbar links visible on Tailwind pages (fixes Bootstrap .collapse vs Tailwind conflicts) */
        @media (min-width: 768px) {
            #mainNav .navbar-collapse { display: flex !important; visibility: visible !important; }
            #mainNav .navbar-toggler { display: none !important; }
        }
        @media (max-width: 767px) {
            #mainNav .navbar-collapse { display: none !important; }
        }
        #mainNav .nav-link { color: rgba(31,41,55,0.9) !important; }
        #mainNav.navbar-scrolled .nav-link { color: #212529 !important; }
        <?php endif; ?>
    </style>
</head>
<body id="page-top" class="<?= htmlspecialchars($bodyClass); ?>">
<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner">
        <div class="spinner"></div>
        <div class="loading-text">Loading...</div>
    </div>
</div>

<?php include __DIR__ . '/../components/navbar_guest.php'; ?>
<main class="guest-content">
    <?= $content ?? ''; ?>
</main>
<?php include __DIR__ . '/../components/facility_assistant.php'; ?>
<?php include __DIR__ . '/../components/footer.php'; ?>
<!-- Bootstrap core JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- SimpleLightbox plugin JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/SimpleLightbox/2.1.0/simpleLightbox.min.js"></script>
<!-- Bootstrap and plugins JS is loaded below -->
<!-- Custom JS -->
<script src="<?= htmlspecialchars($base); ?>/public/js/main.js"></script>
</body>
</html>



