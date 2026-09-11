<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/staff_shifts.php';
require_once __DIR__ . '/../../../../config/notifications.php';

$role = $_SESSION['role'] ?? '';
// Admin and Staff only — facilitator rostering, not resident-facing.
if (!($_SESSION['user_authenticated'] ?? false) || !frs_is_staff($role)) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pdo = db();
frs_ensure_staff_shift_schema($pdo);

$myId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = ($role === 'Admin');
$pageTitle = 'Staff Scheduling | LGU Facilities Reservation';
$flash = '';
$flashType = 'success';

$DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** Validate an "HH:MM" 24h time. */
$validTime = static fn (string $t): bool => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $flash = 'Invalid security token. Please refresh and try again.';
        $flashType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        // ---- Admin-only actions ----
        if ($isAdmin && $action === 'add_shift') {
            $staffId = (int) ($_POST['staff_id'] ?? 0);
            $dow = (int) ($_POST['day_of_week'] ?? -1);
            $start = (string) ($_POST['start_time'] ?? '');
            $end = (string) ($_POST['end_time'] ?? '');
            if ($staffId <= 0 || $dow < 0 || $dow > 6 || !$validTime($start) || !$validTime($end) || $end <= $start) {
                $flash = 'Enter a valid day and a start time before the end time.';
                $flashType = 'error';
            } else {
                $pdo->prepare('INSERT INTO staff_shifts (staff_id, day_of_week, start_time, end_time) VALUES (?,?,?,?)')
                    ->execute([$staffId, $dow, $start, $end]);
                $flash = 'Shift added.';
            }
        } elseif ($isAdmin && $action === 'delete_shift') {
            $pdo->prepare('DELETE FROM staff_shifts WHERE id = ?')->execute([(int) ($_POST['shift_id'] ?? 0)]);
            $flash = 'Shift removed.';
        } elseif ($isAdmin && $action === 'set_exception') {
            $staffId = (int) ($_POST['staff_id'] ?? 0);
            $date = (string) ($_POST['exception_date'] ?? '');
            $type = ($_POST['type'] ?? 'off') === 'custom' ? 'custom' : 'off';
            $start = (string) ($_POST['start_time'] ?? '');
            $end = (string) ($_POST['end_time'] ?? '');
            $note = trim((string) ($_POST['note'] ?? '')) ?: null;
            $okTimes = $type === 'off' || ($validTime($start) && $validTime($end) && $end > $start);
            if ($staffId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$okTimes) {
                $flash = 'Provide a valid date (and a valid time range for a custom day).';
                $flashType = 'error';
            } else {
                $pdo->prepare(
                    'INSERT INTO staff_shift_exceptions (staff_id, exception_date, type, start_time, end_time, note)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE type=VALUES(type), start_time=VALUES(start_time), end_time=VALUES(end_time), note=VALUES(note)'
                )->execute([$staffId, $date, $type, $type === 'custom' ? $start : null, $type === 'custom' ? $end : null, $note]);
                $flash = 'Exception saved.';
            }
        } elseif ($isAdmin && $action === 'delete_exception') {
            $pdo->prepare('DELETE FROM staff_shift_exceptions WHERE id = ?')->execute([(int) ($_POST['exception_id'] ?? 0)]);
            $flash = 'Exception removed.';
        } elseif ($isAdmin && $action === 'decide_request') {
            $reqId = (int) ($_POST['request_id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'denied';
            $req = $pdo->prepare('SELECT * FROM staff_shift_requests WHERE id = ? AND status = "pending" LIMIT 1');
            $req->execute([$reqId]);
            $r = $req->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $flash = 'Request not found or already decided.';
                $flashType = 'error';
            } else {
                $pdo->prepare('UPDATE staff_shift_requests SET status=?, decided_by=?, decided_at=NOW() WHERE id=?')
                    ->execute([$decision, $myId, $reqId]);
                if ($decision === 'approved') {
                    // Approving a request materialises it as a date exception.
                    $pdo->prepare(
                        'INSERT INTO staff_shift_exceptions (staff_id, exception_date, type, start_time, end_time, note)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE type=VALUES(type), start_time=VALUES(start_time), end_time=VALUES(end_time), note=VALUES(note)'
                    )->execute([
                        (int) $r['staff_id'], $r['request_date'], $r['type'],
                        $r['start_time'], $r['end_time'],
                        'From request: ' . (string) ($r['reason'] ?? ''),
                    ]);
                }
                createNotification(
                    (int) $r['staff_id'],
                    'system',
                    'Shift request ' . $decision,
                    'Your availability request for ' . $r['request_date'] . ' was ' . $decision . '.',
                    base_path() . '/dashboard/staff-scheduling'
                );
                $flash = 'Request ' . $decision . '.';
            }
        } elseif ($isAdmin && $action === 'assign_facilitator') {
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            $staffId = (int) ($_POST['staff_id'] ?? 0);
            $resStmt = $pdo->prepare(
                'SELECT r.reservation_date, r.time_slot, r.status, f.name AS facility_name
                 FROM reservations r JOIN facilities f ON f.id = r.facility_id WHERE r.id = ? LIMIT 1'
            );
            $resStmt->execute([$reservationId]);
            $res = $resStmt->fetch(PDO::FETCH_ASSOC);
            if (!$res || $res['status'] !== 'approved') {
                $flash = 'Reservation not found or not approved.';
                $flashType = 'error';
            } elseif (!frs_staff_on_shift($pdo, $staffId, (string) $res['reservation_date'], (string) $res['time_slot'])
                || frs_staff_assignment_overlaps($pdo, $staffId, (string) $res['reservation_date'], (string) $res['time_slot'], $reservationId)) {
                $flash = 'That staffer is off shift or already booked for that time.';
                $flashType = 'error';
            } else {
                $pdo->prepare('UPDATE reservations SET assigned_staff_id = ? WHERE id = ?')->execute([$staffId, $reservationId]);
                createNotification(
                    $staffId, 'system', 'You are the facilitator for a booking',
                    $res['facility_name'] . ' — ' . date('M j, Y', strtotime((string) $res['reservation_date'])) . ' (' . $res['time_slot'] . ').',
                    base_path() . '/dashboard/reservation-detail?id=' . $reservationId
                );
                $flash = 'Facilitator assigned.';
            }
        } elseif ($action === 'submit_request') {
            // Staff (and admins) can request a change to their OWN availability.
            $date = (string) ($_POST['request_date'] ?? '');
            $type = ($_POST['type'] ?? 'off') === 'custom' ? 'custom' : 'off';
            $start = (string) ($_POST['start_time'] ?? '');
            $end = (string) ($_POST['end_time'] ?? '');
            $reason = trim((string) ($_POST['reason'] ?? '')) ?: null;
            $okTimes = $type === 'off' || ($validTime($start) && $validTime($end) && $end > $start);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d') || !$okTimes) {
                $flash = 'Choose a future date (and a valid time range for a custom day).';
                $flashType = 'error';
            } else {
                $pdo->prepare(
                    'INSERT INTO staff_shift_requests (staff_id, request_date, type, start_time, end_time, reason)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$myId, $date, $type, $type === 'custom' ? $start : null, $type === 'custom' ? $end : null, $reason]);
                // Notify admins there is something to review.
                foreach ($pdo->query("SELECT id FROM users WHERE role='Admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                    createNotification(
                        (int) $adminId, 'system', 'New shift request',
                        ($_SESSION['name'] ?? 'A staff member') . ' requested availability change for ' . $date . '.',
                        base_path() . '/dashboard/staff-scheduling'
                    );
                }
                $flash = 'Request submitted for admin approval.';
            }
        }
    }
    if ($flashType === 'success' && $flash !== '') {
        // PRG so a refresh doesn't resubmit.
        $_SESSION['staff_sched_flash'] = $flash;
        header('Location: ' . base_path() . '/dashboard/staff-scheduling'
            . (isset($_POST['staff_id']) && $isAdmin ? '?staff=' . (int) $_POST['staff_id'] : ''));
        exit;
    }
}

if (!empty($_SESSION['staff_sched_flash'])) {
    $flash = (string) $_SESSION['staff_sched_flash'];
    unset($_SESSION['staff_sched_flash']);
}

// ---- Data for rendering ----
$staffList = $pdo->query(
    "SELECT id, name, role FROM users WHERE role IN ('Staff','Admin') AND status='active' ORDER BY role, name"
)->fetchAll(PDO::FETCH_ASSOC);

$selectedStaffId = $isAdmin ? (int) ($_GET['staff'] ?? ($staffList[0]['id'] ?? 0)) : $myId;

$shiftsByDay = array_fill(0, 7, []);
$sh = $pdo->prepare('SELECT id, day_of_week, start_time, end_time FROM staff_shifts WHERE staff_id = ? ORDER BY day_of_week, start_time');
$sh->execute([$selectedStaffId]);
foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $shiftsByDay[(int) $s['day_of_week']][] = $s;
}

$exc = $pdo->prepare('SELECT * FROM staff_shift_exceptions WHERE staff_id = ? AND exception_date >= CURDATE() ORDER BY exception_date');
$exc->execute([$selectedStaffId]);
$exceptions = $exc->fetchAll(PDO::FETCH_ASSOC);

$myAssignments = [];
$assignStmt = $pdo->prepare(
    "SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name
     FROM reservations r JOIN facilities f ON f.id = r.facility_id
     WHERE r.assigned_staff_id = ? AND r.status = 'approved' AND r.reservation_date >= CURDATE()
     ORDER BY r.reservation_date, r.time_slot LIMIT 50"
);
$assignStmt->execute([$selectedStaffId]);
$myAssignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingRequests = [];
$needsFacilitator = [];
if ($isAdmin) {
    $pr = $pdo->query(
        "SELECT req.*, u.name AS staff_name FROM staff_shift_requests req
         JOIN users u ON u.id = req.staff_id WHERE req.status = 'pending' ORDER BY req.created_at"
    );
    $pendingRequests = $pr->fetchAll(PDO::FETCH_ASSOC);

    $nf = $pdo->query(
        "SELECT r.id, r.reservation_date, r.time_slot, u.name AS requester, f.name AS facility_name
         FROM reservations r JOIN facilities f ON f.id = r.facility_id JOIN users u ON u.id = r.user_id
         WHERE r.status = 'approved' AND r.assigned_staff_id IS NULL AND r.reservation_date >= CURDATE()
         ORDER BY r.reservation_date, r.time_slot LIMIT 50"
    );
    $needsFacilitator = $nf->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>
<div class="dashboard-content dashboard-fade-in">
    <div class="page-header" style="margin-bottom:1rem;">
        <div class="breadcrumb"><span>Reservations &amp; Facilities</span><span class="sep">/</span><span>Staff Scheduling</span></div>
        <?= frs_page_title('Staff Scheduling', 'Set who is on duty so approved reservations are auto-assigned only to available facilitators.'); ?>
    </div>

    <?php if ($flash): ?>
        <div class="message" style="padding:0.85rem 1rem;border-radius:12px;margin-bottom:1rem;border:1px solid <?= $flashType === 'error' ? '#fecaca' : '#bbf7d0'; ?>;<?= $flashType === 'error' ? 'background:#fef2f2;color:#b91c1c;' : 'background:#ecfdf5;color:#047857;'; ?>">
            <?= htmlspecialchars($flash); ?>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin && $needsFacilitator): ?>
        <div class="booking-card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:1.25rem;">
            <h2 style="margin-top:0;color:#92400e;">⚠️ Needs a facilitator (<?= count($needsFacilitator); ?>)</h2>
            <p style="color:#92400e;margin-top:0;">Approved reservations with no available staffer on shift. Assign one manually.</p>
            <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                <thead><tr style="text-align:left;color:#6b7280;"><th style="padding:0.4rem;">Facility</th><th>Requester</th><th>Date</th><th>Time</th><th>Assign</th></tr></thead>
                <tbody>
                <?php foreach ($needsFacilitator as $nfRow):
                    $eligible = frs_eligible_facilitator_ids($pdo, (string) $nfRow['reservation_date'], (string) $nfRow['time_slot'], (int) $nfRow['id']); ?>
                    <tr style="border-top:1px solid #f3f4f6;">
                        <td style="padding:0.4rem;"><a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= (int) $nfRow['id']; ?>"><?= htmlspecialchars($nfRow['facility_name']); ?></a></td>
                        <td><?= htmlspecialchars($nfRow['requester']); ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $nfRow['reservation_date']))); ?></td>
                        <td><?= htmlspecialchars($nfRow['time_slot']); ?></td>
                        <td>
                            <?php if ($eligible): ?>
                                <form method="POST" style="display:flex;gap:0.4rem;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="assign_facilitator">
                                    <input type="hidden" name="reservation_id" value="<?= (int) $nfRow['id']; ?>">
                                    <select name="staff_id" required style="padding:0.3rem;">
                                        <?php foreach ($eligible as $eid): ?>
                                            <?php $ename = ''; foreach ($staffList as $su) { if ((int) $su['id'] === $eid) { $ename = $su['name']; break; } } ?>
                                            <option value="<?= $eid; ?>"><?= htmlspecialchars($ename ?: ('Staff #' . $eid)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-primary" style="padding:0.3rem 0.75rem;">Assign</button>
                                </form>
                            <?php else: ?>
                                <span style="color:#b91c1c;">No one on shift</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <div class="booking-card" style="margin-bottom:1rem;">
            <form method="GET" style="display:flex;gap:0.6rem;align-items:center;">
                <label style="font-weight:600;">Staff member</label>
                <select name="staff" onchange="this.form.submit()" style="padding:0.4rem;">
                    <?php foreach ($staffList as $su): ?>
                        <option value="<?= (int) $su['id']; ?>" <?= (int) $su['id'] === $selectedStaffId ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($su['name']); ?> (<?= htmlspecialchars($su['role']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    <?php endif; ?>

    <div class="booking-card" style="margin-bottom:1.25rem;">
        <h2 style="margin-top:0;">Weekly rota<?= $isAdmin ? '' : ' (your shifts)'; ?></h2>
        <?php
        $hasAnyShift = false;
        foreach ($shiftsByDay as $d) { if ($d) { $hasAnyShift = true; break; } }
        ?>
        <?php if (!$hasAnyShift): ?>
            <p style="color:#6b7280;">No rota defined<?= $isAdmin ? '' : ' — you are treated as always available'; ?>.
            <?= $isAdmin ? 'This staffer is treated as always available until you add shifts below.' : ''; ?></p>
        <?php endif; ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
            <tbody>
            <?php foreach ($DAYS as $dow => $dayName): ?>
                <tr style="border-top:1px solid #f3f4f6;">
                    <td style="padding:0.5rem;font-weight:600;width:110px;vertical-align:top;"><?= $dayName; ?></td>
                    <td style="padding:0.5rem;">
                        <?php if ($shiftsByDay[$dow]): ?>
                            <?php foreach ($shiftsByDay[$dow] as $s): ?>
                                <span style="display:inline-flex;align-items:center;gap:0.3rem;background:#eef2ff;border-radius:8px;padding:0.2rem 0.5rem;margin:0 0.3rem 0.3rem 0;">
                                    <?= htmlspecialchars(substr((string) $s['start_time'], 0, 5)); ?>–<?= htmlspecialchars(substr((string) $s['end_time'], 0, 5)); ?>
                                    <?php if ($isAdmin): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this shift?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_shift">
                                            <input type="hidden" name="shift_id" value="<?= (int) $s['id']; ?>">
                                            <input type="hidden" name="staff_id" value="<?= $selectedStaffId; ?>">
                                            <button type="submit" title="Remove" style="border:none;background:none;color:#b91c1c;cursor:pointer;font-weight:700;">×</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="color:#9ca3af;">Off</span>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <form method="POST" style="display:inline-flex;gap:0.3rem;margin-left:0.5rem;">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="add_shift">
                                <input type="hidden" name="staff_id" value="<?= $selectedStaffId; ?>">
                                <input type="hidden" name="day_of_week" value="<?= $dow; ?>">
                                <input type="time" name="start_time" required style="padding:0.2rem;">
                                <input type="time" name="end_time" required style="padding:0.2rem;">
                                <button type="submit" class="btn-outline" style="padding:0.2rem 0.6rem;">+ Add</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="booking-card" style="margin-bottom:1.25rem;">
        <h2 style="margin-top:0;">Date exceptions (leave / cover)</h2>
        <?php if ($exceptions): ?>
            <ul style="list-style:none;padding:0;margin:0 0 1rem 0;">
                <?php foreach ($exceptions as $ex): ?>
                    <li style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0;border-bottom:1px solid #f3f4f6;">
                        <span>
                            <strong><?= htmlspecialchars(date('D, M j, Y', strtotime((string) $ex['exception_date']))); ?></strong>
                            — <?= $ex['type'] === 'off' ? 'Off' : ('Custom ' . htmlspecialchars(substr((string) $ex['start_time'], 0, 5)) . '–' . htmlspecialchars(substr((string) $ex['end_time'], 0, 5))); ?>
                            <?php if (!empty($ex['note'])): ?><em style="color:#6b7280;">(<?= htmlspecialchars($ex['note']); ?>)</em><?php endif; ?>
                        </span>
                        <?php if ($isAdmin): ?>
                            <form method="POST" onsubmit="return confirm('Remove this exception?');">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_exception">
                                <input type="hidden" name="exception_id" value="<?= (int) $ex['id']; ?>">
                                <input type="hidden" name="staff_id" value="<?= $selectedStaffId; ?>">
                                <button type="submit" style="border:none;background:none;color:#b91c1c;cursor:pointer;">Remove</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="color:#6b7280;">No upcoming exceptions.</p>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <form method="POST" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="set_exception">
                <input type="hidden" name="staff_id" value="<?= $selectedStaffId; ?>">
                <label>Date<br><input type="date" name="exception_date" required style="padding:0.3rem;"></label>
                <label>Type<br>
                    <select name="type" style="padding:0.35rem;">
                        <option value="off">Off (whole day)</option>
                        <option value="custom">Custom hours</option>
                    </select>
                </label>
                <label>From<br><input type="time" name="start_time" style="padding:0.3rem;"></label>
                <label>To<br><input type="time" name="end_time" style="padding:0.3rem;"></label>
                <label style="flex:1;min-width:160px;">Note<br><input type="text" name="note" placeholder="e.g. sick leave" style="padding:0.3rem;width:100%;"></label>
                <button type="submit" class="btn-primary" style="padding:0.4rem 0.9rem;">Save exception</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!$isAdmin || $selectedStaffId === $myId): ?>
        <div class="booking-card" style="margin-bottom:1.25rem;">
            <h2 style="margin-top:0;">Request a change to your availability</h2>
            <p style="color:#6b7280;margin-top:0;">Submit a day off or custom hours for a future date. An admin will approve it.</p>
            <form method="POST" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="submit_request">
                <label>Date<br><input type="date" name="request_date" required min="<?= date('Y-m-d'); ?>" style="padding:0.3rem;"></label>
                <label>Type<br>
                    <select name="type" style="padding:0.35rem;">
                        <option value="off">Day off</option>
                        <option value="custom">Custom hours</option>
                    </select>
                </label>
                <label>From<br><input type="time" name="start_time" style="padding:0.3rem;"></label>
                <label>To<br><input type="time" name="end_time" style="padding:0.3rem;"></label>
                <label style="flex:1;min-width:160px;">Reason<br><input type="text" name="reason" placeholder="optional" style="padding:0.3rem;width:100%;"></label>
                <button type="submit" class="btn-primary" style="padding:0.4rem 0.9rem;">Submit request</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin && $pendingRequests): ?>
        <div class="booking-card" style="margin-bottom:1.25rem;">
            <h2 style="margin-top:0;">Pending shift requests (<?= count($pendingRequests); ?>)</h2>
            <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                <thead><tr style="text-align:left;color:#6b7280;"><th style="padding:0.4rem;">Staff</th><th>Date</th><th>Request</th><th>Reason</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pendingRequests as $req): ?>
                    <tr style="border-top:1px solid #f3f4f6;">
                        <td style="padding:0.4rem;"><?= htmlspecialchars($req['staff_name']); ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $req['request_date']))); ?></td>
                        <td><?= $req['type'] === 'off' ? 'Day off' : ('Custom ' . htmlspecialchars(substr((string) $req['start_time'], 0, 5)) . '–' . htmlspecialchars(substr((string) $req['end_time'], 0, 5))); ?></td>
                        <td><?= htmlspecialchars((string) ($req['reason'] ?? '')); ?></td>
                        <td style="display:flex;gap:0.4rem;">
                            <form method="POST"><?= csrf_field(); ?><input type="hidden" name="action" value="decide_request"><input type="hidden" name="request_id" value="<?= (int) $req['id']; ?>"><input type="hidden" name="decision" value="approved"><button type="submit" class="btn-primary" style="padding:0.25rem 0.7rem;">Approve</button></form>
                            <form method="POST"><?= csrf_field(); ?><input type="hidden" name="action" value="decide_request"><input type="hidden" name="request_id" value="<?= (int) $req['id']; ?>"><input type="hidden" name="decision" value="denied"><button type="submit" class="btn-outline" style="padding:0.25rem 0.7rem;">Deny</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="booking-card">
        <h2 style="margin-top:0;">Upcoming assignments<?= $isAdmin ? '' : ' (yours)'; ?></h2>
        <?php if ($myAssignments): ?>
            <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                <thead><tr style="text-align:left;color:#6b7280;"><th style="padding:0.4rem;">Facility</th><th>Date</th><th>Time</th></tr></thead>
                <tbody>
                <?php foreach ($myAssignments as $a): ?>
                    <tr style="border-top:1px solid #f3f4f6;">
                        <td style="padding:0.4rem;"><a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= (int) $a['id']; ?>"><?= htmlspecialchars($a['facility_name']); ?></a></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime((string) $a['reservation_date']))); ?></td>
                        <td><?= htmlspecialchars($a['time_slot']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#6b7280;">No upcoming assignments.</p>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
