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
$pageTitle = 'Reservation Approvals | LGU Facilities Reservation';

// Auto-decline expired pending reservations (past their reservation time)
$now = new DateTime();
$autoDeclined = 0;
try {
    // Get all pending reservations and check if they've expired
    $pendingStmt = $pdo->query(
        'SELECT id, reservation_date, time_slot, user_id FROM reservations WHERE status = "pending"'
    );
    $allPending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $expiredReservations = [];
    $currentTime = time();
    $currentDate = date('Y-m-d');
    
    foreach ($allPending as $pending) {
        $resDate = $pending['reservation_date'];
        $timeSlot = $pending['time_slot'];
        
        // Check if date is in the past
        if ($resDate < $currentDate) {
            $expiredReservations[] = $pending;
            continue;
        }
        
        // Check if it's today and the time slot has passed
        if ($resDate === $currentDate) {
            $currentHour = (int)date('H');
            if (strpos($timeSlot, 'Morning') !== false && $currentHour >= 12) {
                $expiredReservations[] = $pending;
            } elseif (strpos($timeSlot, 'Afternoon') !== false && $currentHour >= 17) {
                $expiredReservations[] = $pending;
            } elseif (strpos($timeSlot, 'Evening') !== false && $currentHour >= 21) {
                $expiredReservations[] = $pending;
            }
        }
    }
    
    foreach ($expiredReservations as $expired) {
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
        $autoDeclined++;
    }
} catch (Throwable $e) {
    // Silently fail - don't interrupt the page
}

$message = '';
$messageType = 'success';
if ($autoDeclined > 0) {
    $message = $autoDeclined . ' pending reservation(s) automatically denied due to expired reservation time.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_id'], $_POST['action'])) {
    $reservationId = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    $allowed = ['approved', 'denied', 'cancelled'];

    if ($reservationId && in_array($action, $allowed, true)) {
        try {
            // Get reservation details for audit log
            $resStmt = $pdo->prepare('SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name, u.name AS requester_name 
                                      FROM reservations r 
                                      JOIN facilities f ON r.facility_id = f.id 
                                      JOIN users u ON r.user_id = u.id 
                                      WHERE r.id = :id');
            $resStmt->execute(['id' => $reservationId]);
            $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
            
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
            $details = 'RES-' . $reservationId . ' – ' . ($reservation ? $reservation['facility_name'] : 'Unknown Facility');
            if ($reservation) {
                $details .= ' (' . $reservation['reservation_date'] . ' ' . $reservation['time_slot'] . ')';
            }
            if (!empty($_POST['note'])) {
                $details .= ' – Note: ' . $_POST['note'];
            }
            logAudit(ucfirst($action) . ' reservation', 'Reservations', $details);
            
            // Create notification for the requester
            $userStmt = $pdo->prepare('SELECT user_id FROM reservations WHERE id = :id');
            $userStmt->execute(['id' => $reservationId]);
            $requesterId = $userStmt->fetchColumn();
            
            if ($requesterId && $reservation) {
                $notifTitle = $action === 'approved' ? 'Reservation Approved' : ($action === 'denied' ? 'Reservation Denied' : 'Reservation Cancelled');
                $notifMessage = 'Your reservation request for ' . $reservation['facility_name'];
                $notifMessage .= ' on ' . date('F j, Y', strtotime($reservation['reservation_date'])) . ' (' . $reservation['time_slot'] . ')';
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

$pendingStmt = $pdo->query(
    'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, f.name AS facility, u.name AS requester
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.status = "pending"
     ORDER BY r.created_at ASC'
);
$pendingReservations = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$totalStmt = $pdo->query('SELECT COUNT(*) FROM reservations');
$totalRows = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$historyStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility, u.name AS requester
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     ORDER BY r.updated_at DESC
     LIMIT :limit OFFSET :offset'
);
$historyStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$historyStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$historyStmt->execute();
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span>Approvals</span>
    </div>
    <h1>Reservation Approvals</h1>
    <small>Review pending requests and manage reservation statuses.</small>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Pending Requests</h2>
        <?php if (empty($pendingReservations)): ?>
            <p>No reservations awaiting approval.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Requester</th>
                    <th>Facility</th>
                    <th>Schedule</th>
                    <th>Purpose</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingReservations as $reservation): ?>
                    <tr>
                        <td><?= htmlspecialchars($reservation['requester']); ?></td>
                        <td><?= htmlspecialchars($reservation['facility']); ?></td>
                        <td><?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?></td>
                        <td><?= htmlspecialchars($reservation['purpose']); ?></td>
                        <td>
                            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservation_detail.php?id=<?= $reservation['id']; ?>" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.9rem;">View Details</a>
                                <form method="POST" style="display:flex; gap:0.5rem; flex:1; min-width:300px;">
                                    <input type="hidden" name="reservation_id" value="<?= $reservation['id']; ?>">
                                    <input type="text" name="note" placeholder="Remarks" style="flex:1; border:1px solid #dfe3ef; border-radius:6px; padding:0.35rem 0.5rem;">
                                    <button class="btn-primary confirm-action" data-message="Approve this reservation?" name="action" value="approved" type="submit">Approve</button>
                                    <button class="btn-outline confirm-action" data-message="Deny this reservation?" name="action" value="denied" type="submit">Deny</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <aside class="booking-card">
        <h2>Status Legend</h2>
        <ul class="audit-list">
            <li><strong>Pending:</strong> Waiting for staff review.</li>
            <li><strong>Approved:</strong> Facility is reserved for the requester.</li>
            <li><strong>Denied:</strong> Request was declined; requester is notified.</li>
            <li><strong>Cancelled:</strong> Reservation cancelled by staff or requester.</li>
        </ul>
    </aside>
</div>

<div class="booking-card" style="margin-top:1.5rem;">
    <h2>Recent Activity</h2>
    <?php if (empty($history)): ?>
        <p>No reservation activity recorded.</p>
    <?php else: ?>
        <?php foreach ($history as $record): ?>
            <?php
            $historyItems = $pdo->prepare(
                'SELECT status, note, created_at FROM reservation_history WHERE reservation_id = :id ORDER BY created_at DESC'
            );
            $historyItems->execute(['id' => $record['id']]);
            $timeline = $historyItems->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <article class="facility-card-admin" style="margin-bottom:1rem;">
                <header>
                    <div>
                        <h3><?= htmlspecialchars($record['facility']); ?></h3>
                        <small><?= htmlspecialchars($record['reservation_date']); ?> • <?= htmlspecialchars($record['time_slot']); ?></small>
                    </div>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <span class="status-badge <?= $record['status']; ?>"><?= ucfirst($record['status']); ?></span>
                        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservation_detail.php?id=<?= $record['id']; ?>" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.9rem;">View Details</a>
                    </div>
                </header>
                <p style="margin:0 0 0.75rem;">Requester: <?= htmlspecialchars($record['requester']); ?></p>
                <?php if ($timeline): ?>
                    <ul class="timeline">
                        <?php foreach ($timeline as $event): ?>
                            <li>
                                <strong><?= ucfirst($event['status']); ?></strong>
                                <p style="margin:0;"><?= $event['note'] ? htmlspecialchars($event['note']) : 'No remarks provided.'; ?></p>
                                <small style="color:#8b95b5;"><?= htmlspecialchars($event['created_at']); ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1; ?>">&larr; Prev</a>
                <?php endif; ?>
                <span class="current">Page <?= $page; ?> of <?= $totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1; ?>">Next &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

