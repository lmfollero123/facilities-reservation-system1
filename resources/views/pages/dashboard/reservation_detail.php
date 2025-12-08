<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/resources/views/pages/dashboard/index.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/notifications.php';
$pdo = db();
$pageTitle = 'Reservation Details | LGU Facilities Reservation';

$reservationId = (int)($_GET['id'] ?? 0);
if (!$reservationId) {
    header('Location: ' . base_path() . '/resources/views/pages/dashboard/reservations_manage.php');
    exit;
}

// Auto-decline expired pending reservations if viewing this reservation
try {
    $checkStmt = $pdo->prepare('SELECT id, reservation_date, time_slot, user_id, status FROM reservations WHERE id = ? AND status = "pending"');
    $checkStmt->execute([$reservationId]);
    $pending = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    $expired = null;
    if ($pending) {
        $resDate = $pending['reservation_date'];
        $timeSlot = $pending['time_slot'];
        $currentDate = date('Y-m-d');
        $currentHour = (int)date('H');
        
        // Check if date is in the past
        if ($resDate < $currentDate) {
            $expired = $pending;
        } elseif ($resDate === $currentDate) {
            // Check if it's today and the time slot has passed
            if (strpos($timeSlot, 'Morning') !== false && $currentHour >= 12) {
                $expired = $pending;
            } elseif (strpos($timeSlot, 'Afternoon') !== false && $currentHour >= 17) {
                $expired = $pending;
            } elseif (strpos($timeSlot, 'Evening') !== false && $currentHour >= 21) {
                $expired = $pending;
            }
        }
    }
    
    if ($expired) {
        // Update status to denied
        $updateStmt = $pdo->prepare('UPDATE reservations SET status = "denied", updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $updateStmt->execute([$expired['id']]);
        
        // Add to history
        $histStmt = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (?, ?, ?, NULL)');
        $histStmt->execute([
            $expired['id'],
            'denied',
            'Automatically denied: Reservation time has passed without approval.'
        ]);
        
        // Notify user
        $facilityStmt = $pdo->prepare('SELECT f.name FROM facilities f JOIN reservations r ON f.id = r.facility_id WHERE r.id = ?');
        $facilityStmt->execute([$expired['id']]);
        $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
        $facilityName = $facility ? $facility['name'] : 'Facility';
        
        createNotification(
            $expired['user_id'],
            'booking',
            'Reservation Automatically Denied',
            'Your reservation request for ' . $facilityName . ' on ' . date('F j, Y', strtotime($expired['reservation_date'])) . ' (' . $expired['time_slot'] . ') has been automatically denied because the reservation time has passed without approval.',
            base_path() . '/resources/views/pages/dashboard/my_reservations.php'
        );
        
        logAudit('Auto-denied expired reservation', 'Reservations', 'RES-' . $expired['id'] . ' – Past reservation time without approval');
    }
} catch (Throwable $e) {
    // Silently fail
}

$message = '';
$messageType = 'success';

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $allowed = ['approved', 'denied', 'cancelled'];

    if (in_array($action, $allowed, true)) {
        try {
            // Get reservation details for audit log (already fetched below, but we need it here)
            $resStmt = $pdo->prepare('SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name 
                                      FROM reservations r 
                                      JOIN facilities f ON r.facility_id = f.id 
                                      WHERE r.id = :id');
            $resStmt->execute(['id' => $reservationId]);
            $reservationInfo = $resStmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare('UPDATE reservations SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                'status' => $action,
                'id' => $reservationId,
            ]);
            $hist = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:id, :status, :note, :user)');
            $hist->execute([
                'id' => $reservationId,
                'status' => $action,
                'note' => $_POST['note'] ?? null,
                'user' => $_SESSION['user_id'] ?? null,
            ]);
            
            // Log audit event
            $details = 'RES-' . $reservationId . ' – ' . ($reservationInfo ? $reservationInfo['facility_name'] : 'Unknown Facility');
            if ($reservationInfo) {
                $details .= ' (' . $reservationInfo['reservation_date'] . ' ' . $reservationInfo['time_slot'] . ')';
            }
            if (!empty($_POST['note'])) {
                $details .= ' – Note: ' . $_POST['note'];
            }
            logAudit(ucfirst($action) . ' reservation', 'Reservations', $details);
            
            // Create notification for the requester
            $userStmt = $pdo->prepare('SELECT user_id FROM reservations WHERE id = :id');
            $userStmt->execute(['id' => $reservationId]);
            $requesterId = $userStmt->fetchColumn();
            
            if ($requesterId) {
                $notifTitle = $action === 'approved' ? 'Reservation Approved' : ($action === 'denied' ? 'Reservation Denied' : 'Reservation Cancelled');
                $notifMessage = 'Your reservation request for ' . ($reservationInfo ? $reservationInfo['facility_name'] : 'a facility');
                if ($reservationInfo) {
                    $notifMessage .= ' on ' . date('F j, Y', strtotime($reservationInfo['reservation_date'])) . ' (' . $reservationInfo['time_slot'] . ')';
                }
                $notifMessage .= ' has been ' . $action . '.';
                if (!empty($_POST['note'])) {
                    $notifMessage .= ' Note: ' . $_POST['note'];
                }
                
                $notifLink = base_path() . '/resources/views/pages/dashboard/my_reservations.php';
                createNotification($requesterId, 'booking', $notifTitle, $notifMessage, $notifLink);
            }
            
            $message = ucfirst($action) . ' reservation successfully.';
        } catch (Throwable $e) {
            $message = 'Unable to update reservation. Please try again.';
            $messageType = 'error';
        }
    }
}

// Fetch reservation details
$stmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.status, r.created_at, r.updated_at,
            u.id AS user_id, u.name AS requester_name, u.email AS requester_email, u.role AS requester_role,
            f.id AS facility_id, f.name AS facility_name, f.description AS facility_description, f.status AS facility_status
     FROM reservations r
     JOIN users u ON r.user_id = u.id
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.id = :id'
);
$stmt->execute(['id' => $reservationId]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    header('Location: ' . base_path() . '/resources/views/pages/dashboard/reservations_manage.php');
    exit;
}

// Fetch status history
$historyStmt = $pdo->prepare(
    'SELECT rh.status, rh.note, rh.created_at, u.name AS created_by_name
     FROM reservation_history rh
     LEFT JOIN users u ON rh.created_by = u.id
     WHERE rh.reservation_id = :id
     ORDER BY rh.created_at DESC'
);
$historyStmt->execute(['id' => $reservationId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span><a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php" style="color:inherit;text-decoration:none;">Approvals</a></span><span class="sep">/</span><span>Details</span>
    </div>
    <h1>Reservation Details</h1>
    <small>Comprehensive view of reservation #<?= $reservationId; ?></small>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Reservation Information</h2>
        <div style="display:grid;gap:1rem;">
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Reservation ID</strong>
                <p style="margin:0;font-size:1.1rem;font-weight:600;">#<?= $reservationId; ?></p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Status</strong>
                <span class="status-badge <?= $reservation['status']; ?>"><?= ucfirst($reservation['status']); ?></span>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Reservation Date</strong>
                <p style="margin:0;font-size:1rem;"><?= htmlspecialchars($reservation['reservation_date']); ?></p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Time Slot</strong>
                <p style="margin:0;font-size:1rem;"><?= htmlspecialchars($reservation['time_slot']); ?></p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Purpose</strong>
                <p style="margin:0;font-size:1rem;line-height:1.6;"><?= htmlspecialchars($reservation['purpose']); ?></p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Submitted</strong>
                <p style="margin:0;font-size:0.95rem;color:#8b95b5;"><?= htmlspecialchars($reservation['created_at']); ?></p>
            </div>
            <?php if ($reservation['updated_at'] !== $reservation['created_at']): ?>
                <div>
                    <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Last Updated</strong>
                    <p style="margin:0;font-size:0.95rem;color:#8b95b5;"><?= htmlspecialchars($reservation['updated_at']); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="booking-card">
        <h2>Requester Information</h2>
        <div style="display:grid;gap:1rem;">
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Name</strong>
                <p style="margin:0;font-size:1rem;"><?= htmlspecialchars($reservation['requester_name']); ?></p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Email</strong>
                <p style="margin:0;font-size:1rem;"><a href="mailto:<?= htmlspecialchars($reservation['requester_email']); ?>" style="color:#2563eb;text-decoration:none;"><?= htmlspecialchars($reservation['requester_email']); ?></a></p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Role</strong>
                <span class="status-badge <?= strtolower($reservation['requester_role']); ?>"><?= htmlspecialchars($reservation['requester_role']); ?></span>
            </div>
        </div>
    </section>

    <section class="booking-card">
        <h2>Facility Information</h2>
        <div style="display:grid;gap:1rem;">
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Facility Name</strong>
                <p style="margin:0;font-size:1rem;font-weight:600;"><?= htmlspecialchars($reservation['facility_name']); ?></p>
            </div>
            <?php if ($reservation['facility_description']): ?>
                <div>
                    <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Description</strong>
                    <p style="margin:0;font-size:1rem;line-height:1.6;color:#4b5563;"><?= htmlspecialchars($reservation['facility_description']); ?></p>
                </div>
            <?php endif; ?>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Usage Fee</strong>
                <p style="margin:0;font-size:1rem;color:#5b6888;">Free of Charge</p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Facility Status</strong>
                <span class="status-badge <?= $reservation['facility_status']; ?>"><?= ucfirst($reservation['facility_status']); ?></span>
            </div>
        </div>
    </section>
</div>

<div class="booking-card" style="margin-top:1.5rem; background:#fff4e5; border:1px solid #ffc107;">
    <h2 style="margin:0 0 0.75rem; color:#856404; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
        <span>⚠️</span> Emergency Override Policy
    </h2>
    <p style="margin:0; color:#856404; font-size:0.95rem; line-height:1.6;">
        In case of emergencies (e.g., evacuation centers, disaster response, urgent LGU/Barangay needs), 
        the LGU reserves the right to override or cancel existing reservations. Affected residents will be notified immediately. 
        All facilities are provided free of charge for public use.
    </p>
</div>

<?php if ($reservation['status'] === 'pending'): ?>
    <div class="booking-card" style="margin-top:1.5rem;">
        <h2>Actions</h2>
        <form method="POST" style="display:flex;gap:1rem;align-items:flex-start;">
            <div style="flex:1;">
                <label style="display:block;margin-bottom:0.5rem;color:#5b6888;font-size:0.9rem;">Add Remarks (Optional)</label>
                <textarea name="note" placeholder="Enter any notes or remarks for this action..." style="width:100%;padding:0.75rem;border:1px solid #dfe3ef;border-radius:8px;font-family:inherit;font-size:0.95rem;resize:vertical;min-height:80px;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;flex-direction:column;">
                <button class="btn-primary confirm-action" data-message="Approve this reservation?" name="action" value="approved" type="submit">Approve</button>
                <button class="btn-outline confirm-action" data-message="Deny this reservation?" name="action" value="denied" type="submit">Deny</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="booking-card" style="margin-top:1.5rem;">
    <h2>Status History Timeline</h2>
    <?php if (empty($history)): ?>
        <p style="color:#8b95b5;">No status history recorded yet.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($history as $entry): ?>
                <li>
                    <strong><?= ucfirst($entry['status']); ?></strong>
                    <p style="margin:0.25rem 0;"><?= $entry['note'] ? htmlspecialchars($entry['note']) : 'No remarks provided.'; ?></p>
                    <small style="color:#8b95b5;">
                        <?= htmlspecialchars($entry['created_at']); ?>
                        <?php if ($entry['created_by_name']): ?>
                            • by <?= htmlspecialchars($entry['created_by_name']); ?>
                        <?php endif; ?>
                    </small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div style="margin-top:1.5rem;">
    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php" class="btn-outline" style="display:inline-block;text-decoration:none;">← Back to Approvals</a>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

