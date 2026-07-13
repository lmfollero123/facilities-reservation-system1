<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/checkin_waiver.php';

$role = $_SESSION['role'] ?? '';
if (!($_SESSION['user_authenticated'] ?? false) || !in_array($role, ['Staff', 'Admin'], true)) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pdo = db();
$staffId = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = 'Check-In Waivers | LGU Facilities Reservation';
$message = '';
$messageType = 'success';

frs_checkin_waiver_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && frs_csrf_ok()) {
    $action = (string)($_POST['action'] ?? '');
    $waiverId = (int)($_POST['waiver_id'] ?? 0);
    $note = trim((string)($_POST['staff_note'] ?? ''));
    if ($waiverId > 0 && in_array($action, ['approve', 'deny'], true)) {
        $result = frs_review_checkin_waiver($pdo, $waiverId, $staffId, $action === 'approve' ? 'approved' : 'denied', $note);
        $message = $result['message'];
        $messageType = $result['ok'] ? 'success' : 'error';
        if ($result['ok'] && function_exists('logAudit')) {
            logAudit('Check-in waiver ' . ($action === 'approve' ? 'approved' : 'denied'), 'Attendance', 'Waiver #' . $waiverId);
        }
    }
}

$pending = frs_list_pending_checkin_waivers($pdo, 100);

ob_start();
?>
<div class="dashboard-content dashboard-fade-in">
    <div class="page-header">
        <?= frs_page_title('Check-In Waiver Requests', 'Residents who forgot to check in can request a waiver. Approved waivers skip no-show violations.'); ?>
    </div>

    <?php if ($message): ?>
        <div class="message <?= $messageType === 'success' ? 'success' : 'error'; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1rem;">
            <?= htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($pending === []): ?>
        <div class="booking-card" style="padding:1rem;">
            <strong>No pending waiver requests.</strong>
            <p style="margin:0.5rem 0 0;color:#6b7280;">When residents submit a waiver from Check In/Out, they appear here for review.</p>
        </div>
    <?php else: ?>
        <div style="display:grid;gap:1rem;">
            <?php foreach ($pending as $w): ?>
                <div class="booking-card" style="padding:1rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:700;"><?= htmlspecialchars((string)$w['resident_name']); ?></div>
                            <div style="color:#64748b;font-size:0.9rem;"><?= htmlspecialchars((string)$w['resident_email']); ?></div>
                            <div style="margin-top:0.5rem;">
                                <strong><?= htmlspecialchars((string)$w['facility_name']); ?></strong>
                                · <?= htmlspecialchars((string)$w['reservation_date']); ?>
                                · <?= htmlspecialchars((string)$w['time_slot']); ?>
                            </div>
                            <p style="margin:0.75rem 0 0;"><strong>Reason:</strong> <?= nl2br(htmlspecialchars((string)$w['reason'])); ?></p>
                            <p style="margin:0.35rem 0 0;color:#94a3b8;font-size:0.85rem;">Submitted <?= htmlspecialchars((string)$w['created_at']); ?></p>
                        </div>
                        <form method="POST" style="min-width:260px;display:grid;gap:0.5rem;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="waiver_id" value="<?= (int)$w['id']; ?>">
                            <label>
                                <span class="sr-only">Staff note</span>
                                <textarea name="staff_note" rows="2" placeholder="Optional note to resident…" style="width:100%;"></textarea>
                            </label>
                            <div style="display:flex;gap:0.5rem;">
                                <button type="submit" name="action" value="approve" class="btn btn-primary">Approve</button>
                                <button type="submit" name="action" value="deny" class="btn btn-outline">Deny</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../layouts/dashboard_layout.php';
