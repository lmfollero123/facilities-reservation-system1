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
require_once __DIR__ . '/../../../../config/mail_helper.php';
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
    $allowed = ['approved', 'denied', 'cancelled', 'modify', 'postpone'];

    if ($reservationId && in_array($action, $allowed, true)) {
        try {
            // Get reservation details for audit log
            $resStmt = $pdo->prepare('SELECT r.id, r.reservation_date, r.time_slot, r.status, r.postponed_priority, f.name AS facility_name, u.name AS requester_name, u.id AS requester_id, u.email AS requester_email
                                      FROM reservations r 
                                      JOIN facilities f ON r.facility_id = f.id 
                                      JOIN users u ON r.user_id = u.id 
                                      WHERE r.id = :id');
            $resStmt->execute(['id' => $reservationId]);
            $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reservation) {
                throw new Exception('Reservation not found');
            }
            
            // Handle different actions
            if ($action === 'modify') {
                // Check if reservation date has passed
                $reservationDate = $reservation['reservation_date'];
                $reservationTimeSlot = $reservation['time_slot'];
                $currentDate = date('Y-m-d');
                $currentHour = (int)date('H');
                
                $isPast = false;
                if ($reservationDate < $currentDate) {
                    $isPast = true;
                } elseif ($reservationDate === $currentDate) {
                    // Check if time slot has passed
                    if (strpos($reservationTimeSlot, 'Morning') !== false && $currentHour >= 12) {
                        $isPast = true;
                    } elseif (strpos($reservationTimeSlot, 'Afternoon') !== false && $currentHour >= 17) {
                        $isPast = true;
                    } elseif (strpos($reservationTimeSlot, 'Evening') !== false && $currentHour >= 21) {
                        $isPast = true;
                    }
                }
                
                if ($isPast) {
                    throw new Exception('Cannot modify past reservations. Only upcoming approved reservations can be modified.');
                }
                
                // Modify date/time of approved reservation
                if (empty($_POST['new_date']) || empty($_POST['new_time_slot'])) {
                    throw new Exception('New date and time slot are required for modification.');
                }
                
                $newDate = $_POST['new_date'];
                $newTimeSlot = $_POST['new_time_slot'];
                $reason = trim($_POST['reason'] ?? '');
                
                // Validate new date is not in the past
                $newDateObj = new DateTime($newDate);
                $today = new DateTime('today');
                if ($newDateObj < $today) {
                    throw new Exception('New reservation date cannot be in the past. Please select today or a future date.');
                }
                
                if (empty($reason)) {
                    throw new Exception('Reason is required for modifying an approved reservation.');
                }
                
                // Check if new date/time is available (no conflicts)
                $conflictCheck = $pdo->prepare(
                    'SELECT id FROM reservations 
                     WHERE facility_id = (SELECT facility_id FROM reservations WHERE id = ?)
                     AND reservation_date = ?
                     AND time_slot = ?
                     AND status IN ("pending", "approved")
                     AND id != ?'
                );
                $facilityStmt = $pdo->prepare('SELECT facility_id FROM reservations WHERE id = ?');
                $facilityStmt->execute([$reservationId]);
                $facilityId = $facilityStmt->fetchColumn();
                
                $conflictCheck->execute([$reservationId, $newDate, $newTimeSlot, $reservationId]);
                if ($conflictCheck->fetch()) {
                    throw new Exception('The selected date and time slot is already booked. Please choose another time.');
                }
                
                // Update reservation
                $oldDate = $reservation['reservation_date'];
                $oldTimeSlot = $reservation['time_slot'];
                
                $stmt = $pdo->prepare('UPDATE reservations SET reservation_date = :new_date, time_slot = :new_time, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([
                    'new_date' => $newDate,
                    'new_time' => $newTimeSlot,
                    'id' => $reservationId,
                ]);
                
                // Add to history
                $hist = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:id, :status, :note, :user)');
                $hist->execute([
                    'id' => $reservationId,
                    'status' => 'approved', // Keep status as approved
                    'note' => 'Modified from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason,
                    'user' => $_SESSION['user_id'] ?? null,
                ]);
                
                // Log audit event
                $details = 'RES-' . $reservationId . ' – ' . $reservation['facility_name'] . ' – Modified from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason;
                logAudit('Modified approved reservation', 'Reservations', $details);
                
                // Create notification
                $notifMessage = 'Your approved reservation for ' . $reservation['facility_name'];
                $notifMessage .= ' has been modified from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
                $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
                $notifMessage .= ' Reason: ' . $reason;
                
                createNotification($reservation['requester_id'], 'booking', 'Reservation Modified', $notifMessage, 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php');
                
                // Send email notification
                if (!empty($reservation['requester_email']) && !empty($reservation['requester_name'])) {
                    $emailSubject = 'Reservation Modified - ' . $reservation['facility_name'];
                    $emailBody = '<p>Hi ' . htmlspecialchars($reservation['requester_name']) . ',</p>';
                    $emailBody .= '<p>Your approved reservation for <strong>' . htmlspecialchars($reservation['facility_name']) . '</strong> has been modified.</p>';
                    $emailBody .= '<p><strong>Original Date/Time:</strong> ' . date('F j, Y', strtotime($oldDate)) . ' (' . htmlspecialchars($oldTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>New Date/Time:</strong> ' . date('F j, Y', strtotime($newDate)) . ' (' . htmlspecialchars($newTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>';
                    $emailBody .= '<p><a href="' . base_path() . '/resources/views/pages/dashboard/my_reservations.php">View My Reservations</a></p>';
                    sendEmail($reservation['requester_email'], $reservation['requester_name'], $emailSubject, $emailBody);
                }
                
                $message = 'Reservation modified successfully.';
                
            } elseif ($action === 'postpone') {
                // Check if reservation date has passed
                $reservationDate = $reservation['reservation_date'];
                $reservationTimeSlot = $reservation['time_slot'];
                $currentDate = date('Y-m-d');
                $currentHour = (int)date('H');
                
                $isPast = false;
                if ($reservationDate < $currentDate) {
                    $isPast = true;
                } elseif ($reservationDate === $currentDate) {
                    // Check if time slot has passed
                    if (strpos($reservationTimeSlot, 'Morning') !== false && $currentHour >= 12) {
                        $isPast = true;
                    } elseif (strpos($reservationTimeSlot, 'Afternoon') !== false && $currentHour >= 17) {
                        $isPast = true;
                    } elseif (strpos($reservationTimeSlot, 'Evening') !== false && $currentHour >= 21) {
                        $isPast = true;
                    }
                }
                
                if ($isPast) {
                    throw new Exception('Cannot postpone past reservations. Only upcoming approved reservations can be postponed.');
                }
                
                // Postpone approved reservation (change to pending with new date)
                if (empty($_POST['new_date']) || empty($_POST['new_time_slot'])) {
                    throw new Exception('New date and time slot are required for postponement.');
                }
                
                $newDate = $_POST['new_date'];
                $newTimeSlot = $_POST['new_time_slot'];
                $reason = trim($_POST['reason'] ?? '');
                
                // Validate new date is not in the past
                $newDateObj = new DateTime($newDate);
                $today = new DateTime('today');
                if ($newDateObj < $today) {
                    throw new Exception('New reservation date cannot be in the past. Please select today or a future date.');
                }
                
                if (empty($reason)) {
                    throw new Exception('Reason is required for postponing an approved reservation.');
                }
                
                // Check if new date/time is available
                $conflictCheck = $pdo->prepare(
                    'SELECT id FROM reservations 
                     WHERE facility_id = (SELECT facility_id FROM reservations WHERE id = ?)
                     AND reservation_date = ?
                     AND time_slot = ?
                     AND status IN ("pending", "approved")
                     AND id != ?'
                );
                $facilityStmt = $pdo->prepare('SELECT facility_id FROM reservations WHERE id = ?');
                $facilityStmt->execute([$reservationId]);
                $facilityId = $facilityStmt->fetchColumn();
                
                $conflictCheck->execute([$reservationId, $newDate, $newTimeSlot, $reservationId]);
                if ($conflictCheck->fetch()) {
                    throw new Exception('The selected date and time slot is already booked. Please choose another time.');
                }
                
                // Update reservation - set to pending for re-approval
                $oldDate = $reservation['reservation_date'];
                $oldTimeSlot = $reservation['time_slot'];
                
                $stmt = $pdo->prepare('UPDATE reservations SET reservation_date = :new_date, time_slot = :new_time, status = "pending", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([
                    'new_date' => $newDate,
                    'new_time' => $newTimeSlot,
                    'id' => $reservationId,
                ]);
                
                // Add to history
                $hist = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:id, :status, :note, :user)');
                $hist->execute([
                    'id' => $reservationId,
                    'status' => 'pending',
                    'note' => 'Postponed from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason,
                    'user' => $_SESSION['user_id'] ?? null,
                ]);
                
                // Log audit event
                $details = 'RES-' . $reservationId . ' – ' . $reservation['facility_name'] . ' – Postponed from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason;
                logAudit('Postponed approved reservation', 'Reservations', $details);
                
                // Create notification
                $notifMessage = 'Your approved reservation for ' . $reservation['facility_name'];
                $notifMessage .= ' has been postponed from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
                $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
                $notifMessage .= ' The new date requires re-approval. Reason: ' . $reason;
                
                createNotification($reservation['requester_id'], 'booking', 'Reservation Postponed', $notifMessage, 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php');
                
                // Send email notification
                if (!empty($reservation['requester_email']) && !empty($reservation['requester_name'])) {
                    $emailSubject = 'Reservation Postponed - ' . $reservation['facility_name'];
                    $emailBody = '<p>Hi ' . htmlspecialchars($reservation['requester_name']) . ',</p>';
                    $emailBody .= '<p>Your approved reservation for <strong>' . htmlspecialchars($reservation['facility_name']) . '</strong> has been postponed.</p>';
                    $emailBody .= '<p><strong>Original Date/Time:</strong> ' . date('F j, Y', strtotime($oldDate)) . ' (' . htmlspecialchars($oldTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>New Date/Time:</strong> ' . date('F j, Y', strtotime($newDate)) . ' (' . htmlspecialchars($newTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>';
                    $emailBody .= '<p><strong>Note:</strong> The new date requires re-approval. You will be notified once it is reviewed.</p>';
                    $emailBody .= '<p><a href="' . base_path() . '/resources/views/pages/dashboard/my_reservations.php">View My Reservations</a></p>';
                    sendEmail($reservation['requester_email'], $reservation['requester_name'], $emailSubject, $emailBody);
                }
                
                $message = 'Reservation postponed successfully. It is now pending re-approval.';
                
            } else {
                // Standard approve/deny/cancel actions
                if ($action === 'cancelled' && $reservation['status'] === 'approved') {
                    // Check if reservation date has passed
                    $reservationDate = $reservation['reservation_date'];
                    $reservationTimeSlot = $reservation['time_slot'];
                    $currentDate = date('Y-m-d');
                    $currentHour = (int)date('H');
                    
                    $isPast = false;
                    if ($reservationDate < $currentDate) {
                        $isPast = true;
                    } elseif ($reservationDate === $currentDate) {
                        // Check if time slot has passed
                        if (strpos($reservationTimeSlot, 'Morning') !== false && $currentHour >= 12) {
                            $isPast = true;
                        } elseif (strpos($reservationTimeSlot, 'Afternoon') !== false && $currentHour >= 17) {
                            $isPast = true;
                        } elseif (strpos($reservationTimeSlot, 'Evening') !== false && $currentHour >= 21) {
                            $isPast = true;
                        }
                    }
                    
                    if ($isPast) {
                        throw new Exception('Cannot cancel past reservations. Only upcoming approved reservations can be cancelled.');
                    }
                    
                    // Require reason for cancelling approved reservations
                    $reason = trim($_POST['reason'] ?? '');
                    if (empty($reason)) {
                        throw new Exception('Reason is required for cancelling an approved reservation.');
                    }
                    $note = 'Cancelled by admin/staff. Reason: ' . $reason;
                } else {
                    $note = $_POST['note'] ?? null;
                }
                
                // If approving a postponed reservation, clear postponed_at timestamp (priority flag remains for tracking)
                if ($action === 'approved' && $reservation['status'] === 'postponed') {
                    $stmt = $pdo->prepare('UPDATE reservations SET status = :status, postponed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                } else {
                    $stmt = $pdo->prepare('UPDATE reservations SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                }
                $stmt->execute([
                    'status' => $action,
                    'id' => $reservationId,
                ]);
                $hist = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:id, :status, :note, :user)');
                $hist->execute([
                    'id' => $reservationId,
                    'status' => $action,
                    'note' => $note,
                    'user' => $_SESSION['user_id'] ?? null,
                ]);
                
                // Log audit event
                $details = 'RES-' . $reservationId . ' – ' . ($reservation ? $reservation['facility_name'] : 'Unknown Facility');
                if ($reservation) {
                    $details .= ' (' . $reservation['reservation_date'] . ' ' . $reservation['time_slot'] . ')';
                }
                if (!empty($note)) {
                    $details .= ' – Note: ' . $note;
                }
                logAudit(ucfirst($action) . ' reservation', 'Reservations', $details);
                
                // Create notification for the requester
                if ($reservation) {
                    $notifTitle = $action === 'approved' ? 'Reservation Approved' : ($action === 'denied' ? 'Reservation Denied' : 'Reservation Cancelled');
                    $notifMessage = 'Your reservation request for ' . $reservation['facility_name'];
                    $notifMessage .= ' on ' . date('F j, Y', strtotime($reservation['reservation_date'])) . ' (' . $reservation['time_slot'] . ')';
                    $notifMessage .= ' has been ' . $action . '.';
                    
                    // Check if this was a postponed reservation with priority
                    if ($action === 'approved' && $reservation['status'] === 'postponed' && !empty($reservation['postponed_priority'])) {
                        $notifMessage .= ' This reservation had priority due to previous postponement.';
                    }
                    
                    if (!empty($note)) {
                        $notifMessage .= ' Note: ' . $note;
                    }
                    
                    $notifLink = base_path() . '/resources/views/pages/dashboard/my_reservations.php';
                    createNotification($reservation['requester_id'], 'booking', $notifTitle, $notifMessage, $notifLink);
                    
                    // Send email notification
                    if (!empty($reservation['requester_email']) && !empty($reservation['requester_name'])) {
                        $emailSubject = 'Reservation ' . ucfirst($action) . ' - ' . $reservation['facility_name'];
                        $emailBody = '<p>Hi ' . htmlspecialchars($reservation['requester_name']) . ',</p>';
                        $emailBody .= '<p>Your reservation request for <strong>' . htmlspecialchars($reservation['facility_name']) . '</strong>';
                        $emailBody .= ' on <strong>' . date('F j, Y', strtotime($reservation['reservation_date'])) . '</strong> (' . htmlspecialchars($reservation['time_slot']) . ')';
                        $emailBody .= ' has been <strong>' . $action . '</strong>.</p>';
                        if (!empty($note)) {
                            $emailBody .= '<p><strong>Note:</strong> ' . htmlspecialchars($note) . '</p>';
                        }
                        $emailBody .= '<p><a href="' . base_path() . '/resources/views/pages/dashboard/my_reservations.php">View My Reservations</a></p>';
                        sendEmail($reservation['requester_email'], $reservation['requester_name'], $emailSubject, $emailBody);
                    }
                }
                
                $message = ucfirst($action) . ' reservation successfully.';
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

$pendingStmt = $pdo->query(
    'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.status, r.postponed_priority, f.name AS facility, u.name AS requester
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.status IN ("pending", "postponed")
     ORDER BY r.postponed_priority DESC, r.postponed_at ASC, r.created_at ASC'
);
$pendingReservations = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Get approved reservations for management (only upcoming dates)
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');
$approvedStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, f.name AS facility, u.name AS requester, u.email AS requester_email
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.status = "approved"
     AND (
         r.reservation_date > :current_date
         OR (
             r.reservation_date = :current_date
             AND (
                 (r.time_slot LIKE "%Morning%" AND :current_hour < 12)
                 OR (r.time_slot LIKE "%Afternoon%" AND :current_hour < 17)
                 OR (r.time_slot LIKE "%Evening%" AND :current_hour < 21)
             )
         )
     )
     ORDER BY r.reservation_date ASC, r.time_slot ASC'
);
$approvedStmt->execute([
    'current_date' => $currentDate,
    'current_hour' => $currentHour,
]);
$approvedReservations = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);

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
            <div class="table-responsive">
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
                            <td>
                                <?= htmlspecialchars($reservation['requester']); ?>
                                <?php if ($reservation['status'] === 'postponed' && !empty($reservation['postponed_priority'])): ?>
                                    <span style="display:inline-block; margin-left:0.5rem; background:#1e3a8a; color:white; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.75rem; font-weight:600;">PRIORITY</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($reservation['facility']); ?></td>
                            <td><?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?></td>
                            <td><?= htmlspecialchars($reservation['purpose']); ?></td>
                            <td>
                                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                    <span class="status-badge <?= $reservation['status']; ?>"><?= ucfirst($reservation['status']); ?></span>
                                    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservation_detail.php?id=<?= $reservation['id']; ?>" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.9rem;">View Details</a>
                                    <?php if ($reservation['status'] === 'pending' || $reservation['status'] === 'postponed'): ?>
                                        <form method="POST" style="display:flex; gap:0.5rem; flex:1; min-width:300px;">
                                            <input type="hidden" name="reservation_id" value="<?= $reservation['id']; ?>">
                                            <input type="text" name="note" placeholder="Remarks" style="flex:1; border:1px solid #dfe3ef; border-radius:6px; padding:0.35rem 0.5rem;">
                                            <button class="btn-primary confirm-action" data-message="Approve this reservation?<?= !empty($reservation['postponed_priority']) ? ' (This reservation has priority due to previous postponement.)' : ''; ?>" name="action" value="approved" type="submit">Approve</button>
                                            <button class="btn-outline confirm-action" data-message="Deny this reservation?" name="action" value="denied" type="submit">Deny</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <aside class="booking-card">
        <h2>Status Legend</h2>
        <ul class="audit-list">
            <li>
                <span class="status-badge pending">Pending</span>
                <span style="margin-left: 0.5rem;">Waiting for staff review.</span>
            </li>
            <li>
                <span class="status-badge approved">Approved</span>
                <span style="margin-left: 0.5rem;">Facility is reserved for the requester.</span>
            </li>
            <li>
                <span class="status-badge denied">Denied</span>
                <span style="margin-left: 0.5rem;">Request was declined; requester is notified.</span>
            </li>
            <li>
                <span class="status-badge cancelled">Cancelled</span>
                <span style="margin-left: 0.5rem;">Reservation cancelled by staff or requester.</span>
            </li>
            <li>
                <span class="status-badge postponed">Postponed</span>
                <span style="margin-left: 0.5rem;">Reservation postponed (e.g., due to facility maintenance). May have priority status.</span>
            </li>
            <li>
                <span class="status-badge on_hold">On Hold</span>
                <span style="margin-left: 0.5rem;">Reservation temporarily on hold.</span>
            </li>
        </ul>
        <p style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; font-size: 0.85rem; color: #6b7280;">
            <strong>Note:</strong> Postponed reservations with priority status (marked with PRIORITY badge) will be given preference during review.
        </p>
    </aside>
</div>

<!-- Approved Reservations Management Section -->
<div class="booking-card" style="margin-top: 1.5rem;">
    <h2>Approved Reservations Management</h2>
    <p style="color: #8b95b5; margin-bottom: 1rem; font-size: 0.9rem;">
        Manage upcoming approved reservations in case of emergencies or schedule conflicts. Only future reservations can be modified, postponed, or cancelled.
    </p>
    
    <?php if (empty($approvedReservations)): ?>
        <p style="color: #8b95b5; text-align: center; padding: 2rem;">No approved reservations at this time.</p>
    <?php else: ?>
        <div class="table-responsive">
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
                    <?php foreach ($approvedReservations as $reservation): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($reservation['requester']); ?><br>
                                <small style="color: #8b95b5;"><?= htmlspecialchars($reservation['requester_email']); ?></small>
                            </td>
                            <td><?= htmlspecialchars($reservation['facility']); ?></td>
                            <td>
                                <?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?>
                            </td>
                            <td><?= htmlspecialchars($reservation['purpose']); ?></td>
                            <td>
                                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservation_detail.php?id=<?= $reservation['id']; ?>" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.9rem;">View Details</a>
                                    <button class="btn-outline" onclick="openModifyModal(<?= $reservation['id']; ?>, '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>', '<?= htmlspecialchars($reservation['facility']); ?>')" style="padding:0.4rem 0.75rem; font-size:0.9rem;">Modify</button>
                                    <button class="btn-outline" onclick="openPostponeModal(<?= $reservation['id']; ?>, '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>', '<?= htmlspecialchars($reservation['facility']); ?>')" style="padding:0.4rem 0.75rem; font-size:0.9rem;">Postpone</button>
                                    <button class="btn-outline" onclick="openCancelModal(<?= $reservation['id']; ?>, '<?= htmlspecialchars($reservation['facility']); ?>', '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>')" style="padding:0.4rem 0.75rem; font-size:0.9rem; color: #dc3545;">Cancel</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modify Modal -->
<div id="modifyModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Modify Approved Reservation</h3>
            <button onclick="closeModifyModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="modifyForm">
            <input type="hidden" name="reservation_id" id="modify_reservation_id">
            <input type="hidden" name="action" value="modify">
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <strong>Current Schedule:</strong><br>
                <span id="modify_current_schedule"></span>
            </div>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Date <span style="color: #dc3545;">*</span>
            </label>
            <input type="date" name="new_date" id="modify_new_date" required min="<?= date('Y-m-d'); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Time Slot <span style="color: #dc3545;">*</span>
            </label>
            <select name="new_time_slot" id="modify_new_time_slot" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="">Select time slot...</option>
                <option value="Morning (8:00 AM - 12:00 PM)">Morning (8:00 AM - 12:00 PM)</option>
                <option value="Afternoon (1:00 PM - 5:00 PM)">Afternoon (1:00 PM - 5:00 PM)</option>
                <option value="Evening (6:00 PM - 10:00 PM)">Evening (6:00 PM - 10:00 PM)</option>
            </select>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason for Modification <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="modify_reason" required placeholder="Enter the reason for modifying this reservation (e.g., emergency, facility maintenance, etc.)" style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>
            
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeModifyModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Modify this approved reservation?" style="flex: 1;">Modify Reservation</button>
            </div>
        </form>
    </div>
</div>

<!-- Postpone Modal -->
<div id="postponeModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Postpone Approved Reservation</h3>
            <button onclick="closePostponeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="postponeForm">
            <input type="hidden" name="reservation_id" id="postpone_reservation_id">
            <input type="hidden" name="action" value="postpone">
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                <strong>⚠️ Note:</strong> Postponing will change the reservation status back to "pending" and require re-approval.
            </div>
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <strong>Current Schedule:</strong><br>
                <span id="postpone_current_schedule"></span>
            </div>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Date <span style="color: #dc3545;">*</span>
            </label>
            <input type="date" name="new_date" id="postpone_new_date" required min="<?= date('Y-m-d'); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Time Slot <span style="color: #dc3545;">*</span>
            </label>
            <select name="new_time_slot" id="postpone_new_time_slot" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="">Select time slot...</option>
                <option value="Morning (8:00 AM - 12:00 PM)">Morning (8:00 AM - 12:00 PM)</option>
                <option value="Afternoon (1:00 PM - 5:00 PM)">Afternoon (1:00 PM - 5:00 PM)</option>
                <option value="Evening (6:00 PM - 10:00 PM)">Evening (6:00 PM - 10:00 PM)</option>
            </select>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason for Postponement <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="postpone_reason" required placeholder="Enter the reason for postponing this reservation (e.g., emergency, facility maintenance, etc.)" style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>
            
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closePostponeModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Postpone this approved reservation? It will require re-approval." style="flex: 1;">Postpone Reservation</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Cancel Approved Reservation</h3>
            <button onclick="closeCancelModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="cancelForm">
            <input type="hidden" name="reservation_id" id="cancel_reservation_id">
            <input type="hidden" name="action" value="cancelled">
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #f8d7da; border-radius: 6px; border-left: 4px solid #dc3545;">
                <strong>⚠️ Warning:</strong> This will cancel the approved reservation. The user will be notified.
            </div>
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <strong>Reservation Details:</strong><br>
                <span id="cancel_reservation_details"></span>
            </div>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason for Cancellation <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="cancel_reason" required placeholder="Enter the reason for cancelling this reservation (e.g., emergency, facility unavailable, etc.)" style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>
            
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeCancelModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Cancel this approved reservation?" style="flex: 1; background: #dc3545;">Cancel Reservation</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModifyModal(reservationId, currentDate, currentTime, facilityName) {
    document.getElementById('modify_reservation_id').value = reservationId;
    document.getElementById('modify_current_schedule').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('modify_new_date').value = '';
    document.getElementById('modify_new_time_slot').value = '';
    document.getElementById('modify_reason').value = '';
    document.getElementById('modifyModal').style.display = 'flex';
}

function closeModifyModal() {
    document.getElementById('modifyModal').style.display = 'none';
}

function openPostponeModal(reservationId, currentDate, currentTime, facilityName) {
    document.getElementById('postpone_reservation_id').value = reservationId;
    document.getElementById('postpone_current_schedule').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('postpone_new_date').value = '';
    document.getElementById('postpone_new_time_slot').value = '';
    document.getElementById('postpone_reason').value = '';
    document.getElementById('postponeModal').style.display = 'flex';
}

function closePostponeModal() {
    document.getElementById('postponeModal').style.display = 'none';
}

function openCancelModal(reservationId, facilityName, currentDate, currentTime) {
    document.getElementById('cancel_reservation_id').value = reservationId;
    document.getElementById('cancel_reservation_details').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('cancel_reason').value = '';
    document.getElementById('cancelModal').style.display = 'flex';
}

function closeCancelModal() {
    document.getElementById('cancelModal').style.display = 'none';
}

// Close modals when clicking outside
document.getElementById('modifyModal').addEventListener('click', function(e) {
    if (e.target === this) closeModifyModal();
});
document.getElementById('postponeModal').addEventListener('click', function(e) {
    if (e.target === this) closePostponeModal();
});
document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});
</script>

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

