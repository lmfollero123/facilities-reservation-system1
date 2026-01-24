<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/violations.php';
$pdo = db();
$pageTitle = 'Reservation Details | LGU Facilities Reservation';

$reservationId = (int)($_GET['id'] ?? 0);
if (!$reservationId) {
    header('Location: ' . base_path() . '/dashboard/reservations-manage');
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

// Handle violation recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_violation') {
    require_once __DIR__ . '/../../../../config/violations.php';
    
    $violationUserId = (int)($_POST['violation_user_id'] ?? 0);
    $violationType = $_POST['violation_type'] ?? '';
    $severity = $_POST['severity'] ?? 'medium';
    $description = trim($_POST['violation_description'] ?? '');
    $relatedReservationId = (int)($_POST['reservation_id'] ?? 0);
    
    if ($violationUserId && $violationType) {
        $violationId = recordViolation(
            $violationUserId,
            $violationType,
            $severity,
            $description ?: null,
            $relatedReservationId ?: null
        );
        
        if ($violationId) {
            $message = 'Violation recorded successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to record violation. Please try again.';
            $messageType = 'error';
        }
    } else {
        $message = 'Missing required information to record violation.';
        $messageType = 'error';
    }
}

// Handle status change and modifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'record_violation') {
    $action = $_POST['action'];
    $allowed = ['approved', 'denied', 'cancelled', 'modify', 'postpone'];

    if (in_array($action, $allowed, true)) {
        try {
            // Get reservation details for audit log (including facility status)
            $resStmt = $pdo->prepare('SELECT r.id, r.reservation_date, r.time_slot, r.status, r.facility_id, f.name AS facility_name, f.status AS facility_status, u.id AS requester_id, u.name AS requester_name, u.email AS requester_email
                                      FROM reservations r 
                                      JOIN facilities f ON r.facility_id = f.id 
                                      JOIN users u ON r.user_id = u.id 
                                      WHERE r.id = :id');
            $resStmt->execute(['id' => $reservationId]);
            $reservationInfo = $resStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reservationInfo) {
                throw new Exception('Reservation not found');
            }
            
            // Handle different actions
            if ($action === 'modify') {
                // Check if reservation date has passed
                $reservationDate = $reservationInfo['reservation_date'];
                $reservationTimeSlot = $reservationInfo['time_slot'];
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
                if (empty($_POST['new_date']) || empty($_POST['start_time']) || empty($_POST['end_time'])) {
                    throw new Exception('New date, start time, and end time are required for modification.');
                }
                
                $newDate = $_POST['new_date'];
                $startTime = trim($_POST['start_time']);
                $endTime = trim($_POST['end_time']);
                $reason = trim($_POST['reason'] ?? '');
                
                // Validate time format and create time slot string
                $startTimeObj = DateTime::createFromFormat('H:i', $startTime);
                $endTimeObj = DateTime::createFromFormat('H:i', $endTime);
                
                if (!$startTimeObj || !$endTimeObj) {
                    throw new Exception('Invalid time format. Please use valid time values.');
                } elseif ($endTimeObj <= $startTimeObj) {
                    throw new Exception('End time must be after start time.');
                }
                
                // Format time slot as "HH:MM - HH:MM" for storage
                $newTimeSlot = $startTime . ' - ' . $endTime;
                
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
                $conflictCheck->execute([$reservationId, $newDate, $newTimeSlot, $reservationId]);
                if ($conflictCheck->fetch()) {
                    throw new Exception('The selected date and time slot is already booked. Please choose another time.');
                }
                
                // Update reservation
                $oldDate = $reservationInfo['reservation_date'];
                $oldTimeSlot = $reservationInfo['time_slot'];
                
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
                    'status' => 'approved',
                    'note' => 'Modified from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason,
                    'user' => $_SESSION['user_id'] ?? null,
                ]);
                
                // Log audit event
                $details = 'RES-' . $reservationId . ' – ' . $reservationInfo['facility_name'] . ' – Modified from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason;
                logAudit('Modified approved reservation', 'Reservations', $details);
                
                // Create notification
                $notifMessage = 'Your approved reservation for ' . $reservationInfo['facility_name'];
                $notifMessage .= ' has been modified from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
                $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
                $notifMessage .= ' Reason: ' . $reason;
                
                createNotification($reservationInfo['requester_id'], 'booking', 'Reservation Modified', $notifMessage, 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php');
                
                // Send email notification
                if (!empty($reservationInfo['requester_email']) && !empty($reservationInfo['requester_name'])) {
                    $emailSubject = 'Reservation Modified - ' . $reservationInfo['facility_name'];
                    $emailBody = '<p>Hi ' . htmlspecialchars($reservationInfo['requester_name']) . ',</p>';
                    $emailBody .= '<p>Your approved reservation for <strong>' . htmlspecialchars($reservationInfo['facility_name']) . '</strong> has been modified.</p>';
                    $emailBody .= '<p><strong>Original Date/Time:</strong> ' . date('F j, Y', strtotime($oldDate)) . ' (' . htmlspecialchars($oldTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>New Date/Time:</strong> ' . date('F j, Y', strtotime($newDate)) . ' (' . htmlspecialchars($newTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>';
                    $emailBody .= '<p><a href="' . base_url() . '/resources/views/pages/dashboard/my_reservations.php">View My Reservations</a></p>';
                    sendEmail($reservationInfo['requester_email'], $reservationInfo['requester_name'], $emailSubject, $emailBody);
                }
                
                $message = 'Reservation modified successfully.';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reservationId);
                exit;
                
            } elseif ($action === 'postpone') {
                // Check if reservation date has passed
                $reservationDate = $reservationInfo['reservation_date'];
                $reservationTimeSlot = $reservationInfo['time_slot'];
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
                if (empty($_POST['new_date']) || empty($_POST['start_time']) || empty($_POST['end_time'])) {
                    throw new Exception('New date, start time, and end time are required for postponement.');
                }
                
                $newDate = $_POST['new_date'];
                $startTime = trim($_POST['start_time']);
                $endTime = trim($_POST['end_time']);
                $reason = trim($_POST['reason'] ?? '');
                
                // Validate time format and create time slot string
                $startTimeObj = DateTime::createFromFormat('H:i', $startTime);
                $endTimeObj = DateTime::createFromFormat('H:i', $endTime);
                
                if (!$startTimeObj || !$endTimeObj) {
                    throw new Exception('Invalid time format. Please use valid time values.');
                } elseif ($endTimeObj <= $startTimeObj) {
                    throw new Exception('End time must be after start time.');
                }
                
                // Format time slot as "HH:MM - HH:MM" for storage
                $newTimeSlot = $startTime . ' - ' . $endTime;
                
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
                $conflictCheck->execute([$reservationId, $newDate, $newTimeSlot, $reservationId]);
                if ($conflictCheck->fetch()) {
                    throw new Exception('The selected date and time slot is already booked. Please choose another time.');
                }
                
                // Update reservation - set to pending for re-approval
                $oldDate = $reservationInfo['reservation_date'];
                $oldTimeSlot = $reservationInfo['time_slot'];
                
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
                $details = 'RES-' . $reservationId . ' – ' . $reservationInfo['facility_name'] . ' – Postponed from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason;
                logAudit('Postponed approved reservation', 'Reservations', $details);
                
                // Create notification
                $notifMessage = 'Your approved reservation for ' . $reservationInfo['facility_name'];
                $notifMessage .= ' has been postponed from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
                $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
                $notifMessage .= ' The new date requires re-approval. Reason: ' . $reason;
                
                createNotification($reservationInfo['requester_id'], 'booking', 'Reservation Postponed', $notifMessage, 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php');
                
                // Send email notification
                if (!empty($reservationInfo['requester_email']) && !empty($reservationInfo['requester_name'])) {
                    $emailBody = getReservationPostponedEmailTemplate(
                        $reservationInfo['requester_name'],
                        $reservationInfo['facility_name'],
                        $oldDate,
                        $oldTimeSlot,
                        $newDate,
                        $newTimeSlot,
                        $reason
                    );
                    sendEmail($reservationInfo['requester_email'], $reservationInfo['requester_name'], 'Reservation Postponed', $emailBody);
                }
                
                $message = 'Reservation postponed successfully. It is now pending re-approval.';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reservationId);
                exit;
                
            } else {
                // Standard approve/deny/cancel actions
                if ($action === 'cancelled' && $reservationInfo['status'] === 'approved') {
                    // Check if reservation date has passed
                    $reservationDate = $reservationInfo['reservation_date'];
                    $reservationTimeSlot = $reservationInfo['time_slot'];
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
                
                // Validate facility status before approval
                if ($action === 'approved') {
                    $facilityStatus = strtolower($reservationInfo['facility_status'] ?? 'available');
                    if ($facilityStatus === 'maintenance' || $facilityStatus === 'offline') {
                        $statusLabel = ucfirst($facilityStatus);
                        throw new Exception('Cannot approve reservation: The facility "' . htmlspecialchars($reservationInfo['facility_name']) . '" is currently under ' . $statusLabel . '. Please change the facility status to "Available" before approving reservations.');
                    }
                }
                
                $stmt = $pdo->prepare('UPDATE reservations SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
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
                $details = 'RES-' . $reservationId . ' – ' . ($reservationInfo ? $reservationInfo['facility_name'] : 'Unknown Facility');
                if ($reservationInfo) {
                    $details .= ' (' . $reservationInfo['reservation_date'] . ' ' . $reservationInfo['time_slot'] . ')';
                }
                if (!empty($note)) {
                    $details .= ' – Note: ' . $note;
                }
                logAudit(ucfirst($action) . ' reservation', 'Reservations', $details);
                
                // Create notification for the requester
                if ($reservationInfo) {
                    $notifTitle = $action === 'approved' ? 'Reservation Approved' : ($action === 'denied' ? 'Reservation Denied' : 'Reservation Cancelled');
                    $notifMessage = 'Your reservation request for ' . $reservationInfo['facility_name'];
                    $notifMessage .= ' on ' . date('F j, Y', strtotime($reservationInfo['reservation_date'])) . ' (' . $reservationInfo['time_slot'] . ')';
                    $notifMessage .= ' has been ' . $action . '.';
                    if (!empty($note)) {
                        $notifMessage .= ' Note: ' . $note;
                    }
                    
                    $notifLink = base_path() . '/resources/views/pages/dashboard/my_reservations.php';
                    createNotification($reservationInfo['requester_id'], 'booking', $notifTitle, $notifMessage, $notifLink);
                    
                    // Send email notification
                    if (!empty($reservationInfo['requester_email']) && !empty($reservationInfo['requester_name'])) {
                        $emailSubject = 'Reservation ' . ucfirst($action) . ' - ' . $reservationInfo['facility_name'];
                        $emailBody = '<p>Hi ' . htmlspecialchars($reservationInfo['requester_name']) . ',</p>';
                        $emailBody .= '<p>Your reservation request for <strong>' . htmlspecialchars($reservationInfo['facility_name']) . '</strong>';
                        $emailBody .= ' on <strong>' . date('F j, Y', strtotime($reservationInfo['reservation_date'])) . '</strong> (' . htmlspecialchars($reservationInfo['time_slot']) . ')';
                        $emailBody .= ' has been <strong>' . $action . '</strong>.</p>';
                        if (!empty($note)) {
                            $emailBody .= '<p><strong>Note:</strong> ' . htmlspecialchars($note) . '</p>';
                        }
                        $emailBody .= '<p><a href="' . base_url() . '/resources/views/pages/dashboard/my_reservations.php">View My Reservations</a></p>';
                        sendEmail($reservationInfo['requester_email'], $reservationInfo['requester_name'], $emailSubject, $emailBody);
                    }
                }
                
                $message = ucfirst($action) . ' reservation successfully.';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $reservationId);
                exit;
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
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
    header('Location: ' . base_path() . '/dashboard/reservations-manage');
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
        
        <?php
        // Get user violation stats
        require_once __DIR__ . '/../../../../config/violations.php';
        $violationStats = getUserViolationStats((int)$reservation['user_id'], 365);
        if ($violationStats['total'] > 0):
        ?>
        <div style="margin-top:1.5rem; padding:1rem; background:#fff4e5; border:1px solid #ffc107; border-radius:8px;">
            <h3 style="margin:0 0 0.75rem; font-size:1rem; color:#856404; display:flex; align-items:center; gap:0.5rem;">
                <span>⚠️</span> User Violation History
            </h3>
            <p style="margin:0 0 0.75rem; color:#856404; font-size:0.9rem;">
                This user has <strong><?= $violationStats['total']; ?></strong> violation(s) in the last 365 days.
            </p>
            <div style="display:flex; gap:1rem; flex-wrap:wrap; font-size:0.85rem; color:#856404;">
                <?php if ($violationStats['by_severity']['critical'] > 0): ?>
                    <span><strong>Critical:</strong> <?= $violationStats['by_severity']['critical']; ?></span>
                <?php endif; ?>
                <?php if ($violationStats['by_severity']['high'] > 0): ?>
                    <span><strong>High:</strong> <?= $violationStats['by_severity']['high']; ?></span>
                <?php endif; ?>
                <?php if ($violationStats['by_severity']['medium'] > 0): ?>
                    <span><strong>Medium:</strong> <?= $violationStats['by_severity']['medium']; ?></span>
                <?php endif; ?>
            </div>
            <?php if ($violationStats['by_severity']['high'] > 0 || $violationStats['by_severity']['critical'] > 0): ?>
                <p style="margin:0.75rem 0 0; color:#dc3545; font-size:0.85rem; font-weight:600;">
                    ⚠️ High-severity violations detected. Auto-approval is disabled for this user.
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div style="margin-top:1.5rem;">
            <button class="btn-outline" onclick="openViolationModal(<?= $reservation['user_id']; ?>, '<?= htmlspecialchars($reservation['requester_name']); ?>', <?= $reservationId; ?>, '<?= htmlspecialchars($reservation['facility_name']); ?>', '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>')" style="padding:0.5rem 1rem; color: #dc3545; border-color: #dc3545;">
                Record Violation
            </button>
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
        <form method="POST" action="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= $reservationId; ?>" style="display:flex;gap:1rem;align-items:flex-start;">
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
<?php elseif ($reservation['status'] === 'approved'): 
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
    
    if (!$isPast): ?>
    <div class="booking-card" style="margin-top:1.5rem;">
        <h2>Manage Approved Reservation</h2>
        <p style="color: #8b95b5; margin-bottom: 1rem; font-size: 0.9rem;">
            In case of emergencies or schedule conflicts, you can modify, postpone, or cancel this approved reservation.
        </p>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button class="btn-outline" onclick="openModifyModalDetail(<?= $reservationId; ?>, '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>', '<?= htmlspecialchars($reservation['facility_name']); ?>')" style="padding:0.5rem 1rem;">Modify Date/Time</button>
            <button class="btn-outline" onclick="openPostponeModalDetail(<?= $reservationId; ?>, '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>', '<?= htmlspecialchars($reservation['facility_name']); ?>')" style="padding:0.5rem 1rem;">Postpone</button>
            <button class="btn-outline" onclick="openCancelModalDetail(<?= $reservationId; ?>, '<?= htmlspecialchars($reservation['facility_name']); ?>', '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>')" style="padding:0.5rem 1rem; color: #dc3545;">Cancel</button>
        </div>
    </div>
    <?php else: ?>
    <div class="booking-card" style="margin-top:1.5rem; background:#f8f9fa; border:1px solid #e0e6ed;">
        <h2>Reservation Status</h2>
        <p style="color: #8b95b5; margin: 0; font-size: 0.9rem;">
            This reservation has already passed. Modification, postponement, or cancellation is no longer available for past reservations.
        </p>
    </div>
    <?php endif; ?>
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

<!-- Modify Modal -->
<div id="modifyModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Modify Approved Reservation</h3>
            <button onclick="closeModifyModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" action="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= $reservationId; ?>" id="modifyForm">
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
                Start Time <span style="color: #dc3545;">*</span>
            </label>
            <input type="time" name="start_time" id="modify_start_time" required min="08:00" max="21:00" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            <small style="color: #8b95b5; font-size: 0.85rem; display: block; margin-top: -0.75rem; margin-bottom: 1rem;">Facility operating hours: 8:00 AM - 9:00 PM</small>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                End Time <span style="color: #dc3545;">*</span>
            </label>
            <input type="time" name="end_time" id="modify_end_time" required min="08:00" max="21:00" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            
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
        <form method="POST" action="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= $reservationId; ?>" id="postponeForm">
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
                Start Time <span style="color: #dc3545;">*</span>
            </label>
            <input type="time" name="start_time" id="postpone_start_time" required min="08:00" max="21:00" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            <small style="color: #8b95b5; font-size: 0.85rem; display: block; margin-top: -0.75rem; margin-bottom: 1rem;">Facility operating hours: 8:00 AM - 9:00 PM</small>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                End Time <span style="color: #dc3545;">*</span>
            </label>
            <input type="time" name="end_time" id="postpone_end_time" required min="08:00" max="21:00" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            
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
        <form method="POST" action="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= $reservationId; ?>" id="cancelForm">
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
function openModifyModalDetail(reservationId, currentDate, currentTime, facilityName) {
    document.getElementById('modify_reservation_id').value = reservationId;
    document.getElementById('modify_current_schedule').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('modify_new_date').value = '';
    document.getElementById('modify_start_time').value = '';
    document.getElementById('modify_end_time').value = '';
    document.getElementById('modify_reason').value = '';
    
    // Try to parse current time slot and prefill if in "HH:MM - HH:MM" format
    const timeMatch = currentTime.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
    if (timeMatch) {
        document.getElementById('modify_start_time').value = timeMatch[1].padStart(2, '0') + ':' + timeMatch[2];
        document.getElementById('modify_end_time').value = timeMatch[3].padStart(2, '0') + ':' + timeMatch[4];
    }
    
    document.getElementById('modifyModal').style.display = 'flex';
}

function closeModifyModal() {
    document.getElementById('modifyModal').style.display = 'none';
}

function openPostponeModalDetail(reservationId, currentDate, currentTime, facilityName) {
    document.getElementById('postpone_reservation_id').value = reservationId;
    document.getElementById('postpone_current_schedule').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('postpone_new_date').value = '';
    document.getElementById('postpone_start_time').value = '';
    document.getElementById('postpone_end_time').value = '';
    document.getElementById('postpone_reason').value = '';
    
    // Try to parse current time slot and prefill if in "HH:MM - HH:MM" format
    const timeMatch = currentTime.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
    if (timeMatch) {
        document.getElementById('postpone_start_time').value = timeMatch[1].padStart(2, '0') + ':' + timeMatch[2];
        document.getElementById('postpone_end_time').value = timeMatch[3].padStart(2, '0') + ':' + timeMatch[4];
    }
    
    document.getElementById('postponeModal').style.display = 'flex';
}

function closePostponeModal() {
    document.getElementById('postponeModal').style.display = 'none';
}

function openCancelModalDetail(reservationId, facilityName, currentDate, currentTime) {
    document.getElementById('cancel_reservation_id').value = reservationId;
    document.getElementById('cancel_reservation_details').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('cancel_reason').value = '';
    document.getElementById('cancelModal').style.display = 'flex';
}

function closeCancelModal() {
    document.getElementById('cancelModal').style.display = 'none';
}

// Time validation for modify and postpone modals
function validateTimeInputs(startInputId, endInputId) {
    const startInput = document.getElementById(startInputId);
    const endInput = document.getElementById(endInputId);
    
    if (startInput && endInput) {
        function validate() {
            const startTime = startInput.value;
            const endTime = endInput.value;
            
            if (startTime && endTime) {
                const start = new Date('2000-01-01T' + startTime);
                const end = new Date('2000-01-01T' + endTime);
                
                if (end <= start) {
                    endInput.setCustomValidity('End time must be after start time');
                    return false;
                }
                
                const durationMs = end - start;
                const durationHours = durationMs / (1000 * 60 * 60);
                
                if (durationHours > 12) {
                    endInput.setCustomValidity('Reservation duration cannot exceed 12 hours');
                    return false;
                }
                
                if (durationHours < 0.5) {
                    endInput.setCustomValidity('Reservation duration must be at least 30 minutes');
                    return false;
                }
                
                endInput.setCustomValidity('');
            }
            return true;
        }
        
        startInput.addEventListener('change', validate);
        endInput.addEventListener('change', validate);
        endInput.addEventListener('input', validate);
    }
}

// Initialize time validation for both modals
validateTimeInputs('modify_start_time', 'modify_end_time');
validateTimeInputs('postpone_start_time', 'postpone_end_time');

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

// Violation Modal
function openViolationModal(userId, userName, reservationId, facilityName, reservationDate, timeSlot) {
    document.getElementById('violationModal').style.display = 'flex';
    document.getElementById('violation-user-id').value = userId;
    document.getElementById('violation-user-name').textContent = userName;
    document.getElementById('violation-reservation-id').value = reservationId;
    document.getElementById('violation-reservation-info').textContent = facilityName + ' on ' + reservationDate + ' (' + timeSlot + ')';
}

function closeViolationModal() {
    document.getElementById('violationModal').style.display = 'none';
}

document.getElementById('violationModal').addEventListener('click', function(e) {
    if (e.target === this) closeViolationModal();
});
</script>

<!-- Violation Recording Modal -->
<div id="violationModal" class="modal-overlay" style="display:none;">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Record Violation</h3>
            <button type="button" class="btn-outline" onclick="closeViolationModal()">✕</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="record_violation">
            <input type="hidden" name="violation_user_id" id="violation-user-id">
            <input type="hidden" name="reservation_id" id="violation-reservation-id">
            
            <div style="margin-bottom:1rem; padding:0.75rem; background:#f8f9fa; border-radius:8px;">
                <p style="margin:0 0 0.5rem; font-size:0.9rem; color:#5b6888;"><strong>User:</strong> <span id="violation-user-name"></span></p>
                <p style="margin:0; font-size:0.85rem; color:#8b95b5;"><strong>Related Reservation:</strong> <span id="violation-reservation-info"></span></p>
            </div>
            
            <label>
                Violation Type *
                <select name="violation_type" required style="width:100%; padding:0.5rem; border:1px solid #d1d5db; border-radius:6px; margin-top:0.25rem;">
                    <option value="">Select violation type...</option>
                    <option value="no_show">No Show</option>
                    <option value="late_cancellation">Late Cancellation</option>
                    <option value="policy_violation">Policy Violation</option>
                    <option value="damage">Damage to Facility</option>
                    <option value="other">Other</option>
                </select>
            </label>
            
            <label style="margin-top:1rem;">
                Severity *
                <select name="severity" required style="width:100%; padding:0.5rem; border:1px solid #d1d5db; border-radius:6px; margin-top:0.25rem;">
                    <option value="low">Low - Minor infraction</option>
                    <option value="medium" selected>Medium - Standard violation</option>
                    <option value="high">High - Serious violation</option>
                    <option value="critical">Critical - Severe violation</option>
                </select>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    High and Critical violations will disable auto-approval for this user.
                </small>
            </label>
            
            <label style="margin-top:1rem;">
                Description (Optional)
                <textarea name="violation_description" rows="4" placeholder="Provide details about the violation..." style="width:100%; padding:0.5rem; border:1px solid #d1d5db; border-radius:6px; margin-top:0.25rem; font-family:inherit; resize:vertical;"></textarea>
            </label>
            
            <div style="margin-top:1.5rem; display:flex; gap:0.75rem; justify-content:flex-end;">
                <button type="button" class="btn-outline" onclick="closeViolationModal()">Cancel</button>
                <button type="submit" class="btn-primary" style="background:#dc3545; border-color:#dc3545;">Record Violation</button>
            </div>
        </form>
    </div>
</div>

<div style="margin-top:1.5rem;">
    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php" class="btn-outline" style="display:inline-block;text-decoration:none;">← Back to Approvals</a>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

