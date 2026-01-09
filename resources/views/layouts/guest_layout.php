<?php
require_once __DIR__ . '/../../../config/app.php';
$pageTitle = $pageTitle ?? 'LGU Facilities Reservation';
$bodyClass = $bodyClass ?? '';
// VISUAL CHANGE ONLY - Add landing-page class for home page and public pages
$isHomePage = strpos($_SERVER['PHP_SELF'] ?? '', 'home.php') !== false;
$isPublicPage = strpos($_SERVER['PHP_SELF'] ?? '', 'facilities.php') !== false || 
                strpos($_SERVER['PHP_SELF'] ?? '', 'facility_details.php') !== false ||
                strpos($_SERVER['PHP_SELF'] ?? '', 'contact.php') !== false ||
                strpos($_SERVER['PHP_SELF'] ?? '', 'login.php') !== false ||
                strpos($_SERVER['PHP_SELF'] ?? '', 'register.php') !== false;
if ($isHomePage || $isPublicPage) {
    $bodyClass = ($bodyClass ? $bodyClass . ' ' : '') . 'landing-page';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <!-- Template CSS (includes Bootstrap) -->
    <?php 
    try {
        $base = base_path();
        $templateCss = $base . '/NewTemplate/css/styles.css';
        $customCss = $base . '/public/css/style.css';
    } catch (Exception $e) {
        $base = '';
        $templateCss = '/NewTemplate/css/styles.css';
        $customCss = '/public/css/style.css';
    }
    ?>
    <link href="<?= htmlspecialchars($templateCss); ?>" rel="stylesheet" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= htmlspecialchars($customCss); ?>">
    <style>
        /* Fallback: Ensure critical styles load even if external CSS fails */
        body.landing-page {
            background: linear-gradient(135deg, #1e3a5f 0%, #2c5282 25%, #2d5a8f 50%, #1e4a6b 75%, #0f2d4a 100%) !important;
            background-attachment: fixed;
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
            background: linear-gradient(135deg, #1e3a5f 0%, #2c5282 25%, #2d5a8f 50%, #1e4a6b 75%, #0f2d4a 100%) !important;
            background-attachment: fixed;
            position: relative;
        }
        .contact-section::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.03) 0%, transparent 50%),
                linear-gradient(135deg, transparent 0%, rgba(0, 0, 0, 0.1) 100%);
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
            background: rgba(255, 255, 255, 0.25) !important;
            backdrop-filter: blur(15px) !important;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
            padding: 2.5rem;
            border: none;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .auth-header h1 {
            margin: 0 0 0.5rem;
            color: #fff !important;
            font-size: 1.75rem;
            font-weight: 700;
        }
        .auth-header p {
            margin: 0;
            color: #f0f0f0 !important;
            font-size: 0.95rem;
        }
        .auth-form label {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-weight: 600;
            color: #fff !important;
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
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.1rem;
            pointer-events: none;
        }
        .auth-form input,
        .auth-form textarea {
            width: 100%;
            padding: 0.9rem 1rem 0.9rem 2.75rem;
            border: 2px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.2s ease;
            background: rgba(255, 255, 255, 0.2) !important;
            color: #fff !important;
        }
        .auth-form input:focus,
        .auth-form textarea:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.6) !important;
            background: rgba(255, 255, 255, 0.3) !important;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
        }
        .auth-form input::placeholder,
        .auth-form textarea::placeholder {
            color: rgba(255, 255, 255, 0.6) !important;
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
<?php include __DIR__ . '/../components/navbar_guest.php'; ?>
<main class="guest-content">
    <?= $content ?? ''; ?>
</main>
<?php include __DIR__ . '/../components/footer.php'; ?>
<!-- Bootstrap core JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- SimpleLightbox plugin JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/SimpleLightbox/2.1.0/simpleLightbox.min.js"></script>
<!-- Template JS -->
<script src="<?= htmlspecialchars($base); ?>/NewTemplate/js/scripts.js"></script>
<!-- Custom JS -->
<script src="<?= htmlspecialchars($base); ?>/public/js/main.js"></script>
</body>
</html>



