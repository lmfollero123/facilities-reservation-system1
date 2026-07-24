<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!frs_csrf_ok()) {
        header('Location: ' . base_path() . '/login?error=csrf');
        exit;
    }

    // If this session originated from a Main LGU SSO launch, send the admin
    // back to the SSO hub instead of this system's own login page.
    $returnToMainLgu = !empty($_SESSION['sso_from_mainlgu']);

    session_unset();
    session_destroy();

    if ($returnToMainLgu) {
        $mainLguUrl = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
            ? 'http://localhost/Main%20LGU/admin/dashboard.php'
            : 'https://infragovservices.com/admin/dashboard.php';
        header('Location: ' . $mainLguUrl);
        exit;
    }

    header('Location: ' . base_path() . '/login');
    exit;
}

$pageTitle = 'Log out | LGU Facilities Reservation';
$useTailwind = true;
ob_start();
?>
<section class="auth-page-hero">
    <div class="auth-container public-fade-in">
        <div class="auth-card">
            <div class="auth-header">
                <h1>Log out</h1>
                <p>Confirm to end your session.</p>
            </div>
            <form method="POST" class="auth-form">
                <?= csrf_field(); ?>
                <button class="btn-primary" type="submit">Log out now</button>
            </form>
            <div class="auth-footer" style="margin-top:1rem;">
                <a href="<?= base_path(); ?>/dashboard">Cancel and return to dashboard</a>
            </div>
        </div>
    </div>
</section>
<?php
$content = ob_get_clean();
$bodyClass = 'landing-page';
include __DIR__ . '/../../layouts/guest_layout.php';
