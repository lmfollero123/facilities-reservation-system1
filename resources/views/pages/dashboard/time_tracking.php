<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/time_helpers.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';
require_once __DIR__ . '/../../../../config/attendance.php';
require_once __DIR__ . '/../../../../config/checkin_waiver.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

$pdo = db();
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'Resident';
$pageTitle = 'Check In/Out | LGU Facilities Reservation';

$success = '';
$error = '';
$highlightResId = (int)($_GET['reservation_id'] ?? 0);

if (!empty($_SESSION['time_tracking_flash_error'])) {
    $error = (string)$_SESSION['time_tracking_flash_error'];
    unset($_SESSION['time_tracking_flash_error']);
}

// Check if attendance table exists (graceful message if migration not yet applied)
$hasAttendanceTable = false;
try {
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'reservation_attendance'");
    $hasAttendanceTable = (bool)$checkStmt->fetchColumn();
} catch (Throwable $e) {
    $hasAttendanceTable = false;
}

function buildReservationDateTime(string $reservationDate, string $timeSlot, string $which): ?DateTime
{
    $window = frs_reservation_window($reservationDate, $timeSlot);
    return $which === 'end' ? $window['end'] : $window['start'];
}

function saveAttendanceProof(array $file, int $reservationId, string $type): array
{
    $errors = validateFileUpload($file, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 5 * 1024 * 1024);
    if (!empty($errors)) {
        return ['ok' => false, 'error' => implode(' ', $errors)];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, ['jpg', 'png', 'gif', 'webp'], true)) {
        return ['ok' => false, 'error' => 'Invalid file extension.'];
    }

    $root = app_root_path();
    $dir = $root . '/public/uploads/attendance/' . $reservationId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $rand = bin2hex(random_bytes(8));
    $safeName = $type . '_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    $target = $dir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'Unable to save uploaded file. Please try again.'];
    }

    $relative = '/public/uploads/attendance/' . $reservationId . '/' . $safeName;
    return ['ok' => true, 'path' => $relative];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasAttendanceTable) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $reservationId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;

        if (!in_array($action, ['time_in', 'time_out', 'request_waiver'], true) || $reservationId <= 0) {
            $error = 'Invalid request.';
        } elseif ($action === 'request_waiver') {
            $reason = trim((string)($_POST['waiver_reason'] ?? ''));
            $result = frs_request_checkin_waiver($pdo, $reservationId, $userId, $reason);
            if ($result['ok']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        } elseif (empty($_FILES['proof'])) {
            $error = 'Please upload a photo proof.';
        } else {
            $save = saveAttendanceProof($_FILES['proof'], $reservationId, $action === 'time_in' ? 'time_in' : 'time_out');
            if (!$save['ok']) {
                $error = $save['error'];
            } elseif ($action === 'time_in') {
                $result = frs_record_check_in($pdo, $reservationId, $userId, $save['path']);
                if ($result['ok']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
            } else {
                $result = frs_record_check_out($pdo, $reservationId, $userId, $save['path']);
                if ($result['ok']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
            }
        }
    }
}

// Fetch recent reservations eligible for check-in / waiver (today + past 2 days)
$lookbackStart = date('Y-m-d', strtotime('-2 days'));
$today = date('Y-m-d');
$reservations = [];
$attendanceByResId = [];
$waiverByResId = [];

try {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name
         FROM reservations r
         JOIN facilities f ON f.id = r.facility_id
         WHERE r.user_id = ? AND r.reservation_date BETWEEN ? AND ? AND r.status = 'approved'
         ORDER BY r.reservation_date DESC, r.time_slot ASC"
    );
    $stmt->execute([$userId, $lookbackStart, $today]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($hasAttendanceTable && !empty($reservations)) {
        $ids = array_map(static fn($r) => (int)$r['id'], $reservations);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $attStmt = $pdo->prepare("SELECT * FROM reservation_attendance WHERE reservation_id IN ($in)");
        $attStmt->execute($ids);
        foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendanceByResId[(int)$row['reservation_id']] = $row;
        }
    }
    foreach ($reservations as $r) {
        $w = frs_get_checkin_waiver($pdo, (int)$r['id']);
        if ($w) {
            $waiverByResId[(int)$r['id']] = $w;
        }
    }
} catch (Throwable $e) {
    $error = $error ?: 'Unable to load reservations right now.';
}

ob_start();
?>
<div class="dashboard-content dashboard-fade-in">
    <div class="page-header">
        <?= frs_page_title('Check In / Check Out', 'Scan the facility QR at the venue for quick Check In/Out, or upload photo proof here for today’s approved reservation.'); ?>
    </div>

    <div class="booking-card" style="padding:0.9rem 1rem;margin-bottom:1rem;background:#eff6ff;border:1px solid #bfdbfe;">
        <strong style="color:#1e40af;">Quick tip:</strong>
        <span style="color:#1e3a8a;"> Each facility has a posted QR code. Scan it on your phone while logged in to Check In or Check Out automatically.</span>
    </div>

    <?php if (!$hasAttendanceTable): ?>
        <div class="message error" style="background:#fff4e5;color:#92400e;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1rem;border:1px solid #f59e0b;">
            Attendance feature is not yet enabled in the database. Please run the migration:
            <code>database/migration_add_reservation_attendance.sql</code>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message success" style="background:#e3f8ef;color:#0d7a43;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1rem;">
            <?= htmlspecialchars($success); ?>
        </div>
    <?php elseif ($error): ?>
        <div class="message error" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1rem;">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($reservations)): ?>
        <div class="booking-card" style="padding:1rem;">
            <strong>No reservations for today.</strong>
            <p style="margin:0.5rem 0 0;color:#6b7280;">When you have an approved reservation scheduled today, it will appear here with Check In/Out actions.</p>
        </div>
    <?php else: ?>
        <?php $now = new DateTime(); ?>
        <div class="booking-card time-tracking-card" style="padding:1rem;">
            <h2 style="margin-top:0;">Recent Approved Reservations</h2>
            <div style="display:grid;gap:1rem;">
                <?php foreach ($reservations as $r): 
                    $rid = (int)$r['id'];
                    $att = $attendanceByResId[$rid] ?? null;
                    $waiver = $waiverByResId[$rid] ?? null;
                    $isApproved = strtolower($r['status']) === 'approved';
                    $isToday = ($r['reservation_date'] === $today);
                    $startDt = buildReservationDateTime($r['reservation_date'], $r['time_slot'], 'start');
                    $endDt = buildReservationDateTime($r['reservation_date'], $r['time_slot'], 'end');

                    $checkinOpen = $startDt
                        ? (clone $startDt)->modify('-' . FRS_OCCUPANCY_CHECKIN_GRACE_BEFORE . ' minutes')
                        : null;
                    $isHighlighted = $highlightResId > 0 && $highlightResId === $rid;

                    // Buttons only enabled when approved; card may still show pending state
                    $canTimeIn = $hasAttendanceTable && $isApproved && $isToday && $checkinOpen && ($now >= $checkinOpen)
                        && $endDt && ($now <= $endDt) && (!$att || empty($att['time_in_at']));
                    $canTimeOut = $hasAttendanceTable && $isApproved && $isToday && $endDt && $att && !empty($att['time_in_at'])
                        && empty($att['time_out_at']) && ($now >= $endDt);
                    $canRequestWaiver = $isApproved && (!$att || empty($att['time_in_at'])) && !$waiver
                        && (new DateTime($r['reservation_date'])) <= new DateTime('today');
                    $timedIn = $att && !empty($att['time_in_at']);
                    $timedOut = $att && !empty($att['time_out_at']);
                ?>
                    <div id="reservation-<?= $rid; ?>" class="time-tracking-res-card" style="border:1px solid <?= $isHighlighted ? '#2563eb' : '#e5e7eb'; ?>;border-radius:12px;padding:1rem;<?= $isHighlighted ? 'box-shadow:0 0 0 3px rgba(37,99,235,0.25);' : ''; ?>">
                        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                            <div>
                                <div style="font-weight:700; font-size:1.05rem;"><?= htmlspecialchars($r['facility_name']); ?></div>
                                <div style="color:#6b7280; margin-top:0.25rem;">
                                    <?= date('M j, Y', strtotime($r['reservation_date'])); ?> • <?= htmlspecialchars($r['time_slot']); ?>
                                </div>
                                <div style="margin-top:0.5rem;">
                                    <?php if (!$isApproved): ?>
                                        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:700;font-size:0.85rem;">Pending Approval</span>
                                    <?php elseif ($timedOut): ?>
                                        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:999px;background:#e3f8ef;color:#0d7a43;font-weight:700;font-size:0.85rem;">Completed</span>
                                    <?php elseif ($timedIn): ?>
                                        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:700;font-size:0.85rem;">Checked In</span>
                                    <?php elseif ($waiver && ($waiver['status'] ?? '') === 'pending'): ?>
                                        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:999px;background:#fef9c3;color:#854d0e;font-weight:700;font-size:0.85rem;">Waiver Pending</span>
                                    <?php elseif ($waiver && ($waiver['status'] ?? '') === 'approved'): ?>
                                        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:999px;background:#e0e7ff;color:#3730a3;font-weight:700;font-size:0.85rem;">Waiver Approved</span>
                                    <?php elseif ($waiver && ($waiver['status'] ?? '') === 'denied'): ?>
                                        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:700;font-size:0.85rem;">Waiver Denied</span>
                                    <?php else: ?>
                                        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:999px;background:#fff7ed;color:#9a3412;font-weight:700;font-size:0.85rem;">Not Checked In</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="time-tracking-actions" style="min-width:280px;flex:1;display:flex;gap:0.75rem;align-items:flex-start;justify-content:flex-end;flex-wrap:wrap;">
                                <?php if ($isToday): ?>
                                <form method="POST" enctype="multipart/form-data" style="display:flex;gap:0.5rem;align-items:center;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="time_in">
                                    <input type="hidden" name="reservation_id" value="<?= $rid; ?>">
                                    <input type="file" name="proof" accept="image/*" <?= $canTimeIn ? 'required' : 'disabled'; ?> style="max-width:220px;">
                                    <button type="submit" class="btn btn-primary" <?= $canTimeIn ? '' : 'disabled'; ?>>Check In</button>
                                </form>

                                <form method="POST" enctype="multipart/form-data" style="display:flex;gap:0.5rem;align-items:center;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="time_out">
                                    <input type="hidden" name="reservation_id" value="<?= $rid; ?>">
                                    <input type="file" name="proof" accept="image/*" <?= $canTimeOut ? 'required' : 'disabled'; ?> style="max-width:220px;">
                                    <button type="submit" class="btn btn-outline" <?= $canTimeOut ? '' : 'disabled'; ?>>Check Out</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($canRequestWaiver): ?>
                                <form method="POST" style="display:grid;gap:0.35rem;min-width:240px;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="request_waiver">
                                    <input type="hidden" name="reservation_id" value="<?= $rid; ?>">
                                    <textarea name="waiver_reason" rows="2" required placeholder="Why could you not check in?" style="width:100%;font-size:0.85rem;"></textarea>
                                    <button type="submit" class="btn btn-outline">Request check-in waiver</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($att): ?>
                            <div style="margin-top:0.75rem; display:flex; gap:1rem; flex-wrap:wrap; color:#6b7280; font-size:0.9rem;">
                                <?php if (!empty($att['time_in_at'])): ?>
                                    <div><strong>Check In:</strong> <?= date('g:i A', strtotime($att['time_in_at'])); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($att['time_out_at'])): ?>
                                    <div><strong>Check Out:</strong> <?= date('g:i A', strtotime($att['time_out_at'])); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($att['time_in_proof_path'])): ?>
                                    <div><a href="<?= base_path() . $att['time_in_proof_path']; ?>" target="_blank" style="color:#2563eb;text-decoration:none;">View Check In Proof</a></div>
                                <?php endif; ?>
                                <?php if (!empty($att['time_out_proof_path'])): ?>
                                    <div><a href="<?= base_path() . $att['time_out_proof_path']; ?>" target="_blank" style="color:#2563eb;text-decoration:none;">View Check Out Proof</a></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($checkinOpen && $now < $checkinOpen): ?>
                            <div style="margin-top:0.75rem; color:#6b7280; font-size:0.9rem;">
                                Check In opens at <strong><?= $checkinOpen->format('g:i A'); ?></strong> (<?= FRS_OCCUPANCY_CHECKIN_GRACE_BEFORE; ?> min before start).
                            </div>
                        <?php elseif ($endDt && $now < $endDt && $timedIn && !$timedOut): ?>
                            <div style="margin-top:0.75rem; color:#6b7280; font-size:0.9rem;">
                                Check Out opens after <strong><?= $endDt->format('g:i A'); ?></strong>, or scan the facility QR when your slot ends.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

?>
<style>
/* Visually gray-out disabled Check In/Out buttons */
.time-tracking-card .time-tracking-actions button[disabled],
.time-tracking-card .time-tracking-actions button[disabled]:hover {
    background-color: #e5e7eb !important;
    color: #9ca3af !important;
    border-color: #e5e7eb !important;
    cursor: not-allowed !important;
    box-shadow: none !important;
}
</style>
<?php if ($highlightResId > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('reservation-<?= (int)$highlightResId; ?>');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
</script>
<?php endif; ?>

