<?php
/**
 * Facility QR scan gate — resolves facility token and records Check In / Check Out.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/attendance.php';

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    header('Location: ' . base_path() . '/dashboard/time-tracking');
    exit;
}

$returnUrl = base_path() . '/dashboard/facility-check-in?token=' . urlencode($token);

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login?next=' . urlencode($returnUrl));
    exit;
}

$pdo = db();
$userId = (int)($_SESSION['user_id'] ?? 0);
$facility = frs_facility_from_checkin_token($pdo, $token);

$pageTitle = 'Facility Check In | LGU Facilities Reservation';
$result = [
    'ok' => false,
    'message' => 'Invalid or unknown facility QR code. Please contact staff.',
    'status' => 'invalid',
];

if ($facility) {
    if (($facility['status'] ?? '') !== 'available') {
        $result = [
            'ok' => false,
            'message' => 'This facility is not available for check-in right now.',
            'facility' => $facility,
            'status' => 'facility_unavailable',
        ];
    } else {
        $result = frs_process_facility_qr_scan($pdo, $userId, (int)$facility['id']);
    }
}

$reservation = $result['reservation'] ?? null;
$attendance = $reservation ? frs_get_attendance($pdo, (int)$reservation['id']) : null;

ob_start();
?>
<div class="dashboard-content dashboard-fade-in fci-page">
    <div class="fci-card fci-status-<?= htmlspecialchars($result['status'] ?? 'unknown'); ?>">
        <div class="fci-icon" aria-hidden="true">
            <?php if (!empty($result['ok']) && in_array($result['status'] ?? '', ['checked_in', 'checked_out', 'completed', 'checked_in_waiting'], true)): ?>
                ✓
            <?php else: ?>
                !
            <?php endif; ?>
        </div>
        <h1><?= !empty($result['ok']) ? 'Check In / Out' : 'Unable to Process'; ?></h1>
        <p class="fci-message"><?= htmlspecialchars($result['message'] ?? ''); ?></p>

        <?php if (!empty($facility['name'])): ?>
            <div class="fci-meta">
                <strong><?= htmlspecialchars($facility['name']); ?></strong>
                <?php if (!empty($facility['location'])): ?>
                    <span><?= htmlspecialchars($facility['location']); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($reservation): ?>
            <div class="fci-reservation">
                <span>Today’s slot</span>
                <strong><?= htmlspecialchars($reservation['time_slot']); ?></strong>
                <?php if ($attendance && !empty($attendance['time_in_at'])): ?>
                    <small>Checked in at <?= date('g:i A', strtotime($attendance['time_in_at'])); ?></small>
                <?php endif; ?>
                <?php if ($attendance && !empty($attendance['time_out_at'])): ?>
                    <small>Checked out at <?= date('g:i A', strtotime($attendance['time_out_at'])); ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="fci-actions">
            <a href="<?= base_path(); ?>/dashboard/time-tracking" class="btn-primary">Open Check In/Out</a>
            <a href="<?= base_path(); ?>/dashboard/my-reservations?module=mine" class="btn-outline">My Reservations</a>
        </div>
    </div>
</div>

<style>
.fci-page { display: flex; justify-content: center; padding-top: 1rem; }
.fci-card {
    width: min(100%, 520px);
    padding: 1.5rem;
    border-radius: 16px;
    background: #fff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    text-align: center;
}
.fci-icon {
    width: 56px; height: 56px; margin: 0 auto 0.75rem;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; font-weight: 700;
    background: #eff6ff; color: #1d4ed8;
}
.fci-status-checked_in .fci-icon,
.fci-status-checked_out .fci-icon,
.fci-status-completed .fci-icon,
.fci-status-checked_in_waiting .fci-icon {
    background: #dcfce7; color: #166534;
}
.fci-status-invalid .fci-icon,
.fci-status-no_reservation .fci-icon,
.fci-status-facility_unavailable .fci-icon,
.fci-status-check_in_blocked .fci-icon,
.fci-status-check_out_blocked .fci-icon {
    background: #fee2e2; color: #991b1b;
}
.fci-card h1 { margin: 0 0 0.5rem; font-size: 1.35rem; color: #0f172a; }
.fci-message { margin: 0 0 1rem; color: #475569; line-height: 1.5; }
.fci-meta { margin-bottom: 1rem; padding: 0.75rem; border-radius: 10px; background: #f8fafc; }
.fci-meta strong { display: block; color: #0f172a; }
.fci-meta span { display: block; margin-top: 0.25rem; font-size: 0.88rem; color: #64748b; }
.fci-reservation { margin-bottom: 1rem; padding: 0.75rem; border-radius: 10px; border: 1px dashed #cbd5e1; }
.fci-reservation span { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; }
.fci-reservation strong { display: block; margin-top: 0.2rem; color: #1e293b; }
.fci-reservation small { display: block; margin-top: 0.35rem; color: #64748b; }
.fci-actions { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
