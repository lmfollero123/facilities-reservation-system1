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
$isPublicPage = strpos($phpSelf, 'facilities.php') !== false || 
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
    <!-- Mobile-first viewport meta tag with proper scaling -->
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
    } catch (Exception $e) {
        $base = '';
        $customCss = '/public/css/style.css';
    }
    // Cache-busting: Update this version number when CSS changes are deployed
    $cssVersion = '9.0';
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($customCss); ?>?v=<?= $cssVersion; ?>">
    <style>
        /* Fallback: Ensure critical styles load even if external CSS fails */
        body.landing-page {
            background: url("<?= base_path(); ?>/public/img/cityhall.jpeg") center/cover no-repeat fixed !important;
            min-height: 100vh;
        }
        body.landing-page::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 0;
            pointer-events: none;
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
            background: url("<?= base_path(); ?>/public/img/cityhall.jpeg") center/cover no-repeat fixed !important;
            position: relative;
        }
        .contact-section::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
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
            background: linear-gradient(135deg, #6384d2, #285ccd) !important;
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
            box-shadow: 0 6px 16px rgba(0, 71, 171, 0.3);
        }
        
        /* Ensure facility details page has proper spacing from navbar */
        body.landing-page .section.facility-details-section {
            padding-top: 7rem !important;
            padding-bottom: 3rem !important;
        }
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



