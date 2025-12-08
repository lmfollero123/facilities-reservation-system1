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
    <link rel="stylesheet" href="<?= base_path(); ?>/public/css/style.css">
</head>
<body class="<?= htmlspecialchars($bodyClass); ?>">
<?php include __DIR__ . '/../components/navbar_guest.php'; ?>
<main class="guest-content">
    <?= $content ?? ''; ?>
</main>
<?php include __DIR__ . '/../components/footer.php'; ?>
<script src="<?= base_path(); ?>/public/js/main.js"></script>
</body>
</html>



