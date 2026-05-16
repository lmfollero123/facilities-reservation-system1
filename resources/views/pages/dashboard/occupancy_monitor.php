<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';

if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pdo = db();
$staffId = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = 'Live Occupancy | LGU Facilities Reservation';
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $flash = 'Invalid security token. Please refresh and try again.';
        $flashType = 'error';
    } else {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $status = (string)($_POST['live_status'] ?? FRS_FACILITY_LIVE_AUTO);
        $note = trim((string)($_POST['live_note'] ?? ''));
        if ($facilityId <= 0) {
            $flash = 'Invalid facility.';
            $flashType = 'error';
        } elseif (!frs_set_facility_live_status($pdo, $facilityId, $status, $note !== '' ? $note : null, $staffId)) {
            $flash = 'Could not update status. Run database/migration_add_operational_occupancy.sql if this is a new install.';
            $flashType = 'error';
        } else {
            $flash = 'Facility status updated.';
            $flashType = 'success';
        }
    }
}

$occSnapshot = frs_build_operational_occupancy_snapshot($pdo);
$occLiveApiUrl = base_path() . '/dashboard/occupancy-live';

ob_start();
?>
<div class="dashboard-content dashboard-fade-in book-facility-compact">
    <div class="page-header" style="margin-bottom:1rem;">
        <div class="breadcrumb"><span>Operations</span><span class="sep">/</span><span>Live Occupancy</span></div>
        <h1>Live Occupancy Board</h1>
        <p style="color:#6b7280;max-width:720px;margin:0;">
            Manage facility status overrides and monitor today’s bookings and check-ins.
        </p>
    </div>

    <?php if ($flash): ?>
        <div class="message" style="padding:0.85rem 1rem;border-radius:12px;margin-bottom:1rem;border:1px solid <?= $flashType === 'error' ? '#fecaca' : '#bbf7d0'; ?>;<?= $flashType === 'error' ? 'background:#fef2f2;color:#b91c1c;' : 'background:#ecfdf5;color:#047857;'; ?>">
            <?= htmlspecialchars($flash); ?>
        </div>
    <?php endif; ?>

    <?php if (!$occSnapshot['has_live_status_table']): ?>
        <div class="booking-card" style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:0.85rem 1rem;margin-bottom:1rem;border-radius:12px;">
            Staff overrides are not enabled yet. Run <code>database/migration_add_operational_occupancy.sql</code>.
        </div>
    <?php endif; ?>

    <?php
    $occBoardId = 'staff';
    $occStaffMode = true;
    $occPerPage = 8;
    $occTitle = 'All facilities today';
    $occSubtitle = $occSnapshot['disclaimer'] ?? '';
    $occManageLink = null;
    $occDefaultFilter = 'all';
    include __DIR__ . '/../../components/occupancy_board.php';
    ?>

    <p style="margin-top:0.5rem;text-align:center;">
        <a href="<?= base_path(); ?>/dashboard/reports" class="btn-outline" style="text-decoration:none;">View in Reports &amp; Analytics</a>
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
