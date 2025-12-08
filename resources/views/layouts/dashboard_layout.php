<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = $_SESSION['user_authenticated'] ?? false;
if (!$isLoggedIn) {
    require_once __DIR__ . '/../../../config/app.php';
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}
$pageTitle = $pageTitle ?? 'LGU Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="<?= base_path(); ?>/public/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="dashboard">
<?php include __DIR__ . '/../components/sidebar_dashboard.php'; ?>
<div class="dashboard-main">
    <?php include __DIR__ . '/../components/navbar_dashboard.php'; ?>
    <section class="dashboard-content">
        <?= $content ?? ''; ?>
    </section>
</div>

<div class="modal-confirm" id="confirmModal" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true">
        <h3>Confirm Action</h3>
        <p class="confirm-message">Are you sure?</p>
        <div class="modal-actions">
            <button type="button" class="btn-outline" data-confirm-cancel>Cancel</button>
            <button type="button" class="btn-primary" data-confirm-accept>Yes, continue</button>
        </div>
    </div>
</div>

<script>
    window.APP_BASE_PATH = "<?= base_path(); ?>";
</script>
<script src="<?= base_path(); ?>/public/js/main.js"></script>
<script>
(function () {
    const modal = document.getElementById('confirmModal');
    if (!modal) {
        return;
    }
    const messageEl = modal.querySelector('.confirm-message');
    const cancelBtn = modal.querySelector('[data-confirm-cancel]');
    const acceptBtn = modal.querySelector('[data-confirm-accept]');
    let pendingAction = null;

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.confirm-action');
        if (!trigger) {
            return;
        }
        if (trigger.dataset.skipConfirm === 'true') {
            trigger.dataset.skipConfirm = '';
            return;
        }
        event.preventDefault();
        pendingAction = trigger;
        messageEl.textContent = trigger.dataset.message || 'Are you sure?';
        modal.classList.add('open');
    });

    function closeModal() {
        modal.classList.remove('open');
    }

    cancelBtn.addEventListener('click', function () {
        pendingAction = null;
        closeModal();
    });

    acceptBtn.addEventListener('click', function () {
        if (!pendingAction) {
            closeModal();
            return;
        }
        const actionEl = pendingAction;
        pendingAction = null;
        closeModal();

        if (actionEl.dataset.facility && typeof editFacility === 'function') {
            editFacility(actionEl.dataset.facility);
            return;
        }

        actionEl.dataset.skipConfirm = 'true';
        if (actionEl.tagName === 'A' && actionEl.href) {
            window.location.href = actionEl.href;
        } else if (typeof actionEl.click === 'function') {
            actionEl.click();
        }
    });
})();
</script>
</body>
</html>


