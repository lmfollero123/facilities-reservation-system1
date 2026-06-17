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
require_once __DIR__ . '/../../../../config/sms_helper.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/violations.php';
require_once __DIR__ . '/../../../../config/reservation_documents.php';
require_once __DIR__ . '/../../../../config/reservation_helpers.php';
require_once __DIR__ . '/../../../../config/paymongo_helper.php';
$pdo = db();
$pageTitle = 'Reservation Details | LGU Facilities Reservation';
$paymentsCfg = file_exists(__DIR__ . '/../../../../config/payments.php') ? (require __DIR__ . '/../../../../config/payments.php') : [];
$approvalFirstThenPayment = !empty($paymentsCfg['enabled']) && !empty($paymentsCfg['require_payment_for_reservations']);

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
    if ($pending && frs_reservation_slot_has_passed((string)$pending['reservation_date'], (string)$pending['time_slot'])) {
        $expired = $pending;
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
            base_path() . '/dashboard/my-reservations'
        );
        
        logAudit('Auto-denied expired reservation', 'Reservations', 'RES-' . $expired['id'] . ' – Past reservation time without approval');
    }
} catch (Throwable $e) {
    // Silently fail
}

$message = '';
$messageType = 'success';

// Handle violation recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !frs_csrf_ok()) {
    $message = 'Your session expired or the form is invalid. Please refresh and try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_violation') {
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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_payment') {
    try {
        $syncResult = frs_try_sync_reservation_payment($pdo, $reservationId, (int)($_SESSION['user_id'] ?? 0));
        if (!empty($syncResult['changed'])) {
            header('Location: ' . base_path() . '/dashboard/reservation-detail?id=' . $reservationId . '&payment_synced=1');
            exit;
        }
        $message = $syncResult['message'] ?? 'Payment could not be verified.';
        $messageType = !empty($syncResult['ok']) ? 'success' : 'error';
    } catch (Throwable $e) {
        $message = 'Unable to verify payment: ' . $e->getMessage();
        $messageType = 'error';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'record_violation') {
    $action = $_POST['action'];
    $allowed = ['approved', 'denied', 'cancelled', 'modify', 'postpone', 'extend'];

    if (in_array($action, $allowed, true)) {
        try {
            // Get reservation details for audit log (including facility status)
            $resStmt = $pdo->prepare('SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.expected_attendees, r.status, r.facility_id, f.name AS facility_name, f.status AS facility_status, u.id AS requester_id, u.name AS requester_name, u.email AS requester_email, u.mobile AS requester_mobile
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
                if (frs_reservation_slot_has_passed((string)$reservationInfo['reservation_date'], (string)$reservationInfo['time_slot'])) {
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
                
                $newPurpose = trim($_POST['purpose'] ?? '') ?: ($reservationInfo['purpose'] ?? '');
                if (empty($newPurpose)) {
                    throw new Exception('Purpose is required.');
                }
                $newExpectedAttendees = isset($_POST['expected_attendees']) && $_POST['expected_attendees'] !== '' ? (int)$_POST['expected_attendees'] : null;
                if ($newExpectedAttendees !== null && $newExpectedAttendees < 0) {
                    $newExpectedAttendees = null;
                }
                
                $facilityId = (int)($reservationInfo['facility_id'] ?? 0);
                if (frs_has_overlapping_booking($pdo, $facilityId, $newDate, $newTimeSlot, $reservationId)) {
                    throw new Exception('The selected date and time overlaps an existing booking. Please choose another time.');
                }
                
                // Update reservation
                $oldDate = $reservationInfo['reservation_date'];
                $oldTimeSlot = $reservationInfo['time_slot'];
                
                $stmt = $pdo->prepare('UPDATE reservations SET reservation_date = :new_date, time_slot = :new_time, purpose = :purpose, expected_attendees = :expected_attendees, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([
                    'new_date' => $newDate,
                    'new_time' => $newTimeSlot,
                    'purpose' => $newPurpose,
                    'expected_attendees' => $newExpectedAttendees,
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
                    base_path() . '/dashboard/my-reservations');
                
                // Send email notification
                if (!empty($reservationInfo['requester_email']) && !empty($reservationInfo['requester_name'])) {
                    $emailSubject = 'Reservation Modified - ' . $reservationInfo['facility_name'];
                    $emailBody = '<p>Hi ' . htmlspecialchars($reservationInfo['requester_name']) . ',</p>';
                    $emailBody .= '<p>Your approved reservation for <strong>' . htmlspecialchars($reservationInfo['facility_name']) . '</strong> has been modified.</p>';
                    $emailBody .= '<p><strong>Original Date/Time:</strong> ' . date('F j, Y', strtotime($oldDate)) . ' (' . htmlspecialchars($oldTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>New Date/Time:</strong> ' . date('F j, Y', strtotime($newDate)) . ' (' . htmlspecialchars($newTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>';
                    $emailBody .= '<p><a href="' . base_url() . '/dashboard/my-reservations">View My Reservations</a></p>';
                    sendEmail($reservationInfo['requester_email'], $reservationInfo['requester_name'], $emailSubject, $emailBody);
                }
                
                $message = 'Reservation modified successfully.';
                header('Location: ' . base_path() . '/dashboard/reservation-detail?id=' . $reservationId);
                exit;
                
            } elseif ($action === 'postpone') {
                if (frs_reservation_slot_has_passed((string)$reservationInfo['reservation_date'], (string)$reservationInfo['time_slot'])) {
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
                
                $facilityId = (int)($reservationInfo['facility_id'] ?? 0);
                if (frs_has_overlapping_booking($pdo, $facilityId, $newDate, $newTimeSlot, $reservationId)) {
                    throw new Exception('The selected date and time overlaps an existing booking. Please choose another time.');
                }
                
                // Update reservation - set to postponed with priority (distinct state, requires re-approval)
                $oldDate = $reservationInfo['reservation_date'];
                $oldTimeSlot = $reservationInfo['time_slot'];
                
                $stmt = $pdo->prepare('UPDATE reservations SET reservation_date = :new_date, time_slot = :new_time, status = "postponed", postponed_priority = TRUE, postponed_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([
                    'new_date' => $newDate,
                    'new_time' => $newTimeSlot,
                    'id' => $reservationId,
                ]);
                
                // Add to history
                $hist = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:id, :status, :note, :user)');
                $hist->execute([
                    'id' => $reservationId,
                    'status' => 'postponed',
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
                    base_path() . '/dashboard/my-reservations');
                
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
                
                $message = 'Reservation postponed successfully. It now has postponed status with priority and requires re-approval.';
                header('Location: ' . base_path() . '/dashboard/reservation-detail?id=' . $reservationId);
                exit;

            } elseif ($action === 'extend') {
                require_once __DIR__ . '/../../../../config/extension_helpers.php';

                $extensionHours = (float)($_POST['extension_hours'] ?? 0);
                if ($extensionHours <= 0) {
                    throw new Exception('Extension hours must be greater than 0.');
                }

                // Validate extension
                $check = canExtendReservation($pdo, $reservationId, $extensionHours);
                if (!$check['can_extend']) {
                    throw new Exception($check['reason']);
                }

                // Check if auto-approval is possible
                $autoApprove = $check['can_auto_approve'] ?? false;

                // Process extension
                $result = processExtension($pdo, $reservationId, $extensionHours, $autoApprove);

                if ($result['success']) {
                    // Create notification for the requester
                    $notifMessage = 'Your reservation for ' . $reservationInfo['facility_name'];
                    $notifMessage .= ' on ' . date('F j, Y', strtotime($reservationInfo['reservation_date'])) . ' has been extended.';
                    $notifMessage .= ' New time slot: ' . $result['new_time_slot'] . '. Fee: ₱' . number_format($result['fee'], 2);
                    if ($result['status'] === 'pending') {
                        $notifMessage .= '. Extension is pending approval.';
                    } else {
                        $notifMessage .= '. Extension has been auto-approved.';
                    }

                    createNotification($reservationInfo['requester_id'], 'booking', 'Reservation Extended', $notifMessage,
                        base_path() . '/dashboard/my-reservations');

                    // Send email notification
                    if (!empty($reservationInfo['requester_email']) && !empty($reservationInfo['requester_name'])) {
                        $emailSubject = 'Reservation Extended - ' . $reservationInfo['facility_name'];
                        $emailBody = '<p>Hi ' . htmlspecialchars($reservationInfo['requester_name']) . ',</p>';
                        $emailBody .= '<p>Your reservation for <strong>' . htmlspecialchars($reservationInfo['facility_name']) . '</strong>';
                        $emailBody .= ' on <strong>' . date('F j, Y', strtotime($reservationInfo['reservation_date'])) . '</strong> has been extended.</p>';
                        $emailBody .= '<p><strong>New Time Slot:</strong> ' . htmlspecialchars($result['new_time_slot']) . '</p>';
                        $emailBody .= '<p><strong>Extension Fee:</strong> ₱' . number_format($result['fee'], 2) . '</p>';
                        if ($result['status'] === 'pending') {
                            $emailBody .= '<p>Your extension is pending approval. You will be notified once it is reviewed.</p>';
                        } else {
                            $emailBody .= '<p>Your extension has been auto-approved.</p>';
                        }
                        $emailBody .= '<p><a href="' . base_url() . '/dashboard/my-reservations">View My Reservations</a></p>';
                        sendEmail($reservationInfo['requester_email'], $reservationInfo['requester_name'], $emailSubject, $emailBody);
                    }

                    $message = 'Reservation extended successfully. New time slot: ' . $result['new_time_slot'] . '. Fee: ₱' . number_format($result['fee'], 2) . '.';
                    header('Location: ' . base_path() . '/dashboard/reservation-detail?id=' . $reservationId);
                    exit;
                } else {
                    throw new Exception($result['message']);
                }

            } else {
                if ($action === 'cancelled' && $reservationInfo['status'] === 'approved') {
                    if (frs_reservation_slot_has_passed((string)$reservationInfo['reservation_date'], (string)$reservationInfo['time_slot'])) {
                        throw new Exception('Cannot cancel past reservations. Only upcoming approved reservations can be cancelled.');
                    }
                    $reason = trim($_POST['reason'] ?? '');
                    if (empty($reason)) {
                        throw new Exception('Reason is required for cancelling an approved reservation.');
                    }
                    $note = 'Cancelled by admin/staff. Reason: ' . $reason;
                } else {
                    $note = $_POST['note'] ?? null;
                }

                frs_staff_apply_status_decision(
                    $pdo,
                    $reservationId,
                    $action,
                    $reservationInfo,
                    $approvalFirstThenPayment,
                    $note
                );
                header('Location: ' . base_path() . '/dashboard/reservation-detail?id=' . $reservationId);
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
    'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.expected_attendees, r.status, r.created_at, r.updated_at,
            u.id AS user_id, u.name AS requester_name, u.email AS requester_email, u.role AS requester_role,
            f.id AS facility_id, f.name AS facility_name, f.description AS facility_description, f.status AS facility_status, f.base_rate AS facility_base_rate
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

if (($reservation['status'] ?? '') === 'pending_payment' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        $autoSync = frs_try_sync_reservation_payment($pdo, $reservationId, (int)($_SESSION['user_id'] ?? 0));
        if (!empty($autoSync['changed'])) {
            header('Location: ' . base_path() . '/dashboard/reservation-detail?id=' . $reservationId . '&payment_synced=1');
            exit;
        }
    } catch (Throwable $e) {
        error_log('Reservation detail payment auto-sync RES-' . $reservationId . ': ' . $e->getMessage());
    }
}

if (isset($_GET['payment_synced'])) {
    $message = 'Payment verified. Reservation is now approved.';
    $messageType = 'success';
    $stmt->execute(['id' => $reservationId]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC) ?: $reservation;
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

$reservationDocuments = frs_list_reservation_documents($reservationId);

ob_start();
?>
<style>
.reservation-detail-compact .booking-wrapper {
    gap: 0.85rem;
}
.reservation-detail-compact .booking-card {
    padding: 0.9rem;
}
.reservation-detail-compact h2 {
    margin-bottom: 0.65rem;
    font-size: 1.02rem;
}
.reservation-detail-meta-grid {
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap:0.65rem 0.9rem;
}
.reservation-detail-email-wrap {
    grid-column: 1 / -1;
    min-width: 0;
}
.reservation-detail-email-wrap a {
    word-break: break-word;
    overflow-wrap: anywhere;
}
@media (max-width: 900px) {
    .reservation-detail-meta-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span><a href="<?= base_path(); ?>/dashboard/reservations-manage" style="color:inherit;text-decoration:none;">Approvals</a></span><span class="sep">/</span><span>Details</span>
    </div>
    <?= frs_page_title('Reservation #' . (int)$reservationId, 'Review requester info, permits, and history. Approve, deny, or add staff notes.'); ?>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper reservation-detail-compact">
    <section class="booking-card">
        <?= frs_heading_with_tip('Reservation Information', 'Core booking fields: facility, date, time slot, status, and requester contact.'); ?>
        <div class="reservation-detail-meta-grid">
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Reservation ID</strong>
                <p style="margin:0;font-size:1.1rem;font-weight:600;">#<?= $reservationId; ?></p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Status</strong>
                <span class="status-badge <?= $reservation['status']; ?>">
                    <?= $reservation['status'] === 'pending_payment' ? 'Awaiting Payment' : ucfirst($reservation['status']); ?>
                </span>
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
        <div class="reservation-detail-meta-grid">
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Name</strong>
                <p style="margin:0;font-size:1rem;"><?= htmlspecialchars($reservation['requester_name']); ?></p>
            </div>
            <div class="reservation-detail-email-wrap">
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
        
        <div style="margin-top:0.8rem;">
            <button class="btn-outline" onclick="openViolationModal(<?= $reservation['user_id']; ?>, '<?= htmlspecialchars($reservation['requester_name']); ?>', <?= $reservationId; ?>, '<?= htmlspecialchars($reservation['facility_name']); ?>', '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>')" style="padding:0.5rem 1rem; color: #dc3545; border-color: #dc3545;">
                Record Violation
            </button>
        </div>
    </section>

    <section class="booking-card">
        <h2>Facility Information</h2>
        <div class="reservation-detail-meta-grid">
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
                <?php
                $rawFacilityRate = (string)($reservation['facility_base_rate'] ?? '');
                $facilityRateDigits = preg_replace('/[^\d]/', '', $rawFacilityRate);
                $facilityRateAmount = $facilityRateDigits !== '' ? (int)$facilityRateDigits : 0;
                ?>
                <p style="margin:0;font-size:1rem;color:#5b6888;">
                    <?= $facilityRateAmount > 0 ? ('PHP ' . number_format((float)$facilityRateAmount, 2)) : 'Free of Charge'; ?>
                </p>
            </div>
            <div>
                <strong style="color:#5b6888;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Facility Status</strong>
                <span class="status-badge <?= $reservation['facility_status']; ?>"><?= ucfirst($reservation['facility_status']); ?></span>
            </div>
        </div>
    </section>

    <section class="booking-card">
        <h2>Supporting Documents</h2>
        <?php if (empty($reservationDocuments)): ?>
            <p style="margin:0; color:#8b95b5; font-size:0.95rem;">No event permit or supporting files were uploaded with this request.</p>
        <?php else: ?>
            <ul style="margin:0; padding:0; list-style:none; display:flex; flex-direction:column; gap:0.75rem;">
                <?php foreach ($reservationDocuments as $rdoc):
                    $docId = (int)($rdoc['id'] ?? 0);
                    $viewUrl = frs_reservation_document_download_url($docId, 'view');
                    $dlUrl = frs_reservation_document_download_url($docId, 'download');
                    $sizeKb = round(((int)($rdoc['file_size'] ?? 0)) / 1024, 1);
                ?>
                <li style="padding:0.75rem 1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem; flex-wrap:wrap;">
                        <div>
                            <strong style="display:block; color:#1e293b; font-size:0.95rem;">
                                <?= htmlspecialchars(frs_reservation_document_type_label((string)($rdoc['document_type'] ?? 'other'))); ?>
                            </strong>
                            <span style="font-size:0.88rem; color:#64748b;">
                                <?= htmlspecialchars((string)($rdoc['file_name'] ?? 'document')); ?>
                                <?php if ($sizeKb > 0): ?> · <?= $sizeKb; ?> KB<?php endif; ?>
                            </span>
                            <?php if (!empty($rdoc['uploaded_by_name'])): ?>
                                <span style="display:block; font-size:0.82rem; color:#94a3b8; margin-top:0.2rem;">
                                    Uploaded by <?= htmlspecialchars((string)$rdoc['uploaded_by_name']); ?>
                                    <?php if (!empty($rdoc['created_at'])): ?>
                                        · <?= htmlspecialchars((string)$rdoc['created_at']); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="btn-outline" style="padding:0.4rem 0.75rem; font-size:0.88rem; text-decoration:none;">View</a>
                            <a href="<?= htmlspecialchars($dlUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-outline" style="padding:0.4rem 0.75rem; font-size:0.88rem; text-decoration:none;">Download</a>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="booking-card" style="margin-top:0.85rem; background:#fff4e5; border:1px solid #ffc107;">
    <h2 style="margin:0 0 0.75rem; color:#856404; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
        <span>⚠️</span> Emergency Override Policy
    </h2>
    <p style="margin:0; color:#856404; font-size:0.95rem; line-height:1.6;">
        In case of emergencies (e.g., evacuation centers, disaster response, urgent LGU/Barangay needs), 
        the LGU reserves the right to override or cancel existing reservations. Affected residents will be notified immediately. 
        Reservation handling may include payment where configured by facility policy.
    </p>
</div>

<?php if ($reservation['status'] === 'pending'): ?>
    <div class="booking-card" style="margin-top:1.5rem;">
        <h2>Actions</h2>
        <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/reservation-detail?id=' . (int)$reservationId, ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;gap:1rem;align-items:flex-start;">
            <?= csrf_field(); ?>
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
<?php elseif ($reservation['status'] === 'pending_payment'): ?>
    <div class="booking-card" style="margin-top:0.85rem; border:1px solid #fdba74; background:#fff7ed;">
        <h2 style="margin:0 0 0.65rem; color:#9a3412;">Awaiting Payment</h2>
        <p style="margin:0 0 1rem; color:#7c2d12;">
            This reservation has been approved and is waiting for payment confirmation.
            The resident can complete payment from their reservations page.
        </p>
        <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/reservation-detail?id=' . (int)$reservationId, ENT_QUOTES, 'UTF-8'); ?>" style="margin:0;">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="sync_payment">
            <button type="submit" class="btn-primary confirm-action" data-message="Check PayMongo and mark this reservation paid if payment succeeded?">
                Verify Payment from PayMongo
            </button>
        </form>
        <p style="margin:0.75rem 0 0; color:#9a3412; font-size:0.85rem;">
            Use this after the resident completes checkout if the status did not update automatically.
        </p>
    </div>
<?php elseif ($reservation['status'] === 'approved'):
    $isPast = frs_reservation_slot_has_passed((string)$reservation['reservation_date'], (string)$reservation['time_slot']);
    if (!$isPast): ?>
    <div class="booking-card" style="margin-top:0.85rem;">
        <h2>Manage Approved Reservation</h2>
        <p style="color: #8b95b5; margin-bottom: 1rem; font-size: 0.9rem;">
            In case of emergencies or schedule conflicts, you can modify, postpone, extend, or cancel this approved reservation.
        </p>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button class="btn-outline" onclick="openModifyModalDetail(this)" data-id="<?= (int)$reservationId; ?>" data-facility-id="<?= (int)$reservation['facility_id']; ?>" data-date="<?= htmlspecialchars($reservation['reservation_date']); ?>" data-time="<?= htmlspecialchars($reservation['time_slot'], ENT_QUOTES, 'UTF-8'); ?>" data-facility="<?= htmlspecialchars($reservation['facility_name'], ENT_QUOTES, 'UTF-8'); ?>" data-purpose="<?= htmlspecialchars($reservation['purpose'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-attendees="<?= htmlspecialchars((string)($reservation['expected_attendees'] ?? '')); ?>" style="padding:0.5rem 1rem;">Modify</button>
            <button class="btn-outline" onclick="openPostponeModalDetail(<?= $reservationId; ?>, '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>', '<?= htmlspecialchars($reservation['facility_name']); ?>')" style="padding:0.5rem 1rem;">Postpone</button>
            <button class="btn-outline" onclick="openExtendModal(<?= $reservationId; ?>, '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>', '<?= htmlspecialchars($reservation['facility_name']); ?>', <?= (int)$reservation['facility_id']; ?>)" style="padding:0.5rem 1rem;">Extend</button>
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

<div class="booking-card" style="margin-top:0.85rem;">
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
        <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/reservation-detail?id=' . (int)$reservationId, ENT_QUOTES, 'UTF-8'); ?>" id="modifyForm">
            <?= csrf_field(); ?>
            <input type="hidden" name="reservation_id" id="modify_reservation_id">
            <input type="hidden" name="action" value="modify">
            <input type="hidden" id="modify_facility_id" value="<?= (int)($reservation['facility_id'] ?? 0); ?>">
            
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
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Purpose / Event Description</label>
            <textarea name="purpose" id="modify_purpose" placeholder="Purpose or event description" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem; min-height: 80px; font-family: inherit; resize: vertical;"></textarea>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Expected Attendees</label>
            <input type="number" name="expected_attendees" id="modify_expected_attendees" min="0" placeholder="Optional" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason for Modification <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="modify_reason" required placeholder="Enter the reason for modifying this reservation (e.g., emergency, facility maintenance, etc.)" style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>
            
            <div id="modify-conflict-warning" style="display:none; border-radius:8px; padding:1rem; margin-top:1rem; transition: all 0.3s ease;">
                <div id="modify-conflict-header" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                    <span id="modify-conflict-icon" style="font-size:1.2rem;">⏳</span>
                    <h4 id="modify-conflict-title" style="margin:0; font-size:0.95rem;">Checking Availability...</h4>
                </div>
                <p id="modify-conflict-message" style="margin:0 0 0.75rem; font-size:0.85rem;"></p>
                <div id="modify-conflict-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; font-size:0.85rem; font-weight:600;">Alternative time slots:</p>
                    <ul id="modify-alternatives-list" style="margin:0; padding-left:1.25rem; font-size:0.85rem;"></ul>
                </div>
            </div>
            
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
        <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/reservation-detail?id=' . (int)$reservationId, ENT_QUOTES, 'UTF-8'); ?>" id="postponeForm">
            <?= csrf_field(); ?>
            <input type="hidden" name="reservation_id" id="postpone_reservation_id">
            <input type="hidden" name="action" value="postpone">
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                <strong>⚠️ Note:</strong> Postponing will set the reservation status to "postponed" with priority and require re-approval.
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
        <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/reservation-detail?id=' . (int)$reservationId, ENT_QUOTES, 'UTF-8'); ?>" id="cancelForm">
            <?= csrf_field(); ?>
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

<!-- Extension Modal -->
<div id="extendModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Extend Reservation</h3>
            <button onclick="closeExtendModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/reservation-detail?id=' . (int)$reservationId, ENT_QUOTES, 'UTF-8'); ?>" id="extendForm">
            <?= csrf_field(); ?>
            <input type="hidden" name="reservation_id" id="extend_reservation_id">
            <input type="hidden" name="action" value="extend">
            <input type="hidden" name="facility_id" id="extend_facility_id">

            <div style="margin-bottom: 1rem; padding: 1rem; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                <strong>⚠️ Note:</strong> Extending will require payment and may require re-approval depending on the extension duration.
            </div>

            <div style="margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <strong>Current Schedule:</strong><br>
                <span id="extend_current_schedule"></span>
            </div>

            <div style="margin-bottom: 1rem; padding: 1rem; background: #e3f2fd; border-radius: 6px; border-left: 4px solid #2196f3;">
                <strong>Extension Fee:</strong> <span id="extension_fee_display">₱10.00</span> per hour
            </div>

            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Extension Duration (hours) <span style="color: #dc3545;">*</span>
            </label>
            <select name="extension_hours" id="extension_hours" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="0.5">0.5 hours (30 minutes)</option>
                <option value="1">1 hour</option>
                <option value="1.5">1.5 hours</option>
                <option value="2">2 hours</option>
                <option value="2.5">2.5 hours</option>
                <option value="3">3 hours</option>
                <option value="4">4 hours</option>
            </select>
            <small style="color: #8b95b5; font-size: 0.85rem; display: block; margin-top: -0.75rem; margin-bottom: 1rem;">Maximum 4 hours extension per request</small>

            <div style="margin-bottom: 1rem; padding: 1rem; background: #d4edda; border-radius: 6px; border-left: 4px solid #28a745;">
                <strong>Total Fee:</strong> <span id="total_extension_fee">₱10.00</span>
            </div>

            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeExtendModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Extend this reservation? Payment will be required." style="flex: 1;">Extend & Pay</button>
            </div>
        </form>
    </div>
</div>

<script>
let modifyConflictCheckTimeout = null;

function openModifyModalDetail(btn) {
    const id = btn.getAttribute('data-id');
    const facilityId = btn.getAttribute('data-facility-id') || '';
    const date = btn.getAttribute('data-date');
    const time = btn.getAttribute('data-time');
    const facility = btn.getAttribute('data-facility');
    const purpose = btn.getAttribute('data-purpose') || '';
    const attendees = btn.getAttribute('data-attendees') || '';
    
    document.getElementById('modify_reservation_id').value = id;
    document.getElementById('modify_facility_id').value = facilityId;
    document.getElementById('modify_current_schedule').textContent = facility + ' on ' + date + ' (' + time + ')';
    document.getElementById('modify_new_date').value = date;
    document.getElementById('modify_purpose').value = purpose;
    document.getElementById('modify_expected_attendees').value = attendees;
    document.getElementById('modify_reason').value = '';
    
    const timeMatch = time.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
    if (timeMatch) {
        document.getElementById('modify_start_time').value = timeMatch[1].padStart(2, '0') + ':' + timeMatch[2];
        document.getElementById('modify_end_time').value = timeMatch[3].padStart(2, '0') + ':' + timeMatch[4];
    } else {
        document.getElementById('modify_start_time').value = '';
        document.getElementById('modify_end_time').value = '';
    }
    
    document.getElementById('modifyModal').style.display = 'flex';
    if (modifyConflictCheckTimeout) clearTimeout(modifyConflictCheckTimeout);
    modifyConflictCheckTimeout = setTimeout(checkModifyConflict, 300);
}

function debounceModifyConflict() {
    if (modifyConflictCheckTimeout) clearTimeout(modifyConflictCheckTimeout);
    modifyConflictCheckTimeout = setTimeout(checkModifyConflict, 500);
}

async function checkModifyConflict() {
    modifyConflictCheckTimeout = null;
    const fid = document.getElementById('modify_facility_id')?.value;
    const date = document.getElementById('modify_new_date')?.value;
    const startTime = document.getElementById('modify_start_time')?.value;
    const endTime = document.getElementById('modify_end_time')?.value;
    const excludeId = document.getElementById('modify_reservation_id')?.value;
    const msgBox = document.getElementById('modify-conflict-warning');
    const msgText = document.getElementById('modify-conflict-message');
    const altWrap = document.getElementById('modify-conflict-alternatives');
    const altList = document.getElementById('modify-alternatives-list');
    const conflictIcon = document.getElementById('modify-conflict-icon');
    const conflictTitle = document.getElementById('modify-conflict-title');

    if (!fid || !date || !startTime || !endTime) {
        if (msgBox) msgBox.style.display = 'none';
        return;
    }
    const [sh, sm] = startTime.split(':').map(Number);
    const [eh, em] = endTime.split(':').map(Number);
    if (eh * 60 + em <= sh * 60 + sm) {
        if (msgBox) msgBox.style.display = 'none';
        return;
    }
    const timeSlot = startTime + ' - ' + endTime;

    msgBox.style.display = 'block';
    msgBox.style.background = '#f0f4ff';
    msgBox.style.border = '2px solid #6366f1';
    if (msgText) msgText.style.color = '#4f46e5';
    if (msgText) msgText.textContent = 'Checking availability and conflicts...';
    if (conflictIcon) conflictIcon.textContent = '⏳';
    if (conflictTitle) { conflictTitle.textContent = 'Checking Availability...'; conflictTitle.style.color = '#4f46e5'; }
    if (altWrap) altWrap.style.display = 'none';

    const basePath = <?= json_encode(base_path()); ?>;
    try {
        let body = `facility_id=${encodeURIComponent(fid)}&date=${encodeURIComponent(date)}&time_slot=${encodeURIComponent(timeSlot)}`;
        if (excludeId) body += `&exclude_reservation_id=${encodeURIComponent(excludeId)}`;
        const resp = await fetch(basePath + '/dashboard/ai-conflict-check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        if (!resp.ok) { msgBox.style.display = 'none'; return; }
        const data = await resp.json();
        if (data.error) { msgBox.style.display = 'none'; return; }

        if (data.has_conflict) {
            msgBox.style.background = '#fdecee';
            msgBox.style.border = '2px solid #b23030';
            if (msgText) { msgText.style.color = '#b23030'; msgText.textContent = data.message || 'This slot is already booked (approved reservation). Please select an alternative time.'; }
            if (conflictIcon) conflictIcon.textContent = '✗';
            if (conflictTitle) { conflictTitle.textContent = 'Conflict Detected'; conflictTitle.style.color = '#b23030'; }
        } else if (data.soft_conflicts && data.soft_conflicts.length > 0) {
            const cnt = data.pending_count || data.soft_conflicts.length;
            msgBox.style.background = '#fff4e5';
            msgBox.style.border = '2px solid #ffc107';
            if (msgText) { msgText.style.color = '#856404'; msgText.textContent = 'Warning: ' + cnt + ' pending reservation(s) exist for this slot. You can still modify, but only one can be approved based on priority.'; }
            if (conflictIcon) conflictIcon.textContent = '⚠️';
            if (conflictTitle) { conflictTitle.textContent = 'Warning - Pending Reservations'; conflictTitle.style.color = '#856404'; }
        } else {
            msgBox.style.background = '#e8f5e9';
            msgBox.style.border = '2px solid #0d7a43';
            if (msgText) { msgText.style.color = '#0d7a43'; msgText.textContent = '✓ This time slot is available for modification!'; }
            if (conflictIcon) conflictIcon.textContent = '✓';
            if (conflictTitle) { conflictTitle.textContent = 'Available'; conflictTitle.style.color = '#0d7a43'; }
        }
        if (data.alternatives && data.alternatives.length) {
            altWrap.style.display = 'block';
            altList.innerHTML = data.alternatives.filter(a => a.available !== false)
                .map(a => '<li><strong>' + (a.display || a.time_slot || '') + '</strong> — ' + (a.recommendation || 'Available') + '</li>').join('');
        }
    } catch (e) {
        msgBox.style.background = '#fdecee';
        msgBox.style.border = '2px solid #b23030';
        if (msgText) { msgText.style.color = '#b23030'; msgText.textContent = 'Error checking availability. Please try again.'; }
    }
}

['modify_new_date', 'modify_start_time', 'modify_end_time'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('change', debounceModifyConflict);
    }
});

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

function openExtendModal(reservationId, currentDate, currentTime, facilityName, facilityId) {
    document.getElementById('extend_reservation_id').value = reservationId;
    document.getElementById('extend_facility_id').value = facilityId;
    document.getElementById('extend_current_schedule').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('extension_hours').value = '1';
    updateExtensionFee();
    document.getElementById('extendModal').style.display = 'flex';
}

function closeExtendModal() {
    document.getElementById('extendModal').style.display = 'none';
}

function updateExtensionFee() {
    const hours = parseFloat(document.getElementById('extension_hours').value) || 0;
    const feePerHour = 10.00; // Default fee, can be updated from facility data
    const totalFee = hours * feePerHour;
    document.getElementById('total_extension_fee').textContent = '₱' + totalFee.toFixed(2);
}

const extensionHoursEl = document.getElementById('extension_hours');
if (extensionHoursEl) {
    extensionHoursEl.addEventListener('change', updateExtensionFee);
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
['modifyModal', 'postponeModal', 'cancelModal'].forEach(function (modalId) {
    const modalEl = document.getElementById(modalId);
    if (!modalEl) {
        return;
    }
    modalEl.addEventListener('click', function (e) {
        if (e.target !== modalEl) {
            return;
        }
        if (modalId === 'modifyModal') {
            closeModifyModal();
        } else if (modalId === 'postponeModal') {
            closePostponeModal();
        } else if (modalId === 'cancelModal') {
            closeCancelModal();
        }
    });
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

</script>

<!-- Violation Recording Modal -->
<div id="violationModal" class="modal-overlay" style="display:none;">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Record Violation</h3>
            <button type="button" class="btn-outline" onclick="closeViolationModal()">✕</button>
        </div>
        <form method="POST" class="modal-body">
            <?= csrf_field(); ?>
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

<script>
document.getElementById('violationModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeViolationModal();
});
</script>

<div style="margin-top:1.5rem;">
    <a href="<?= base_path(); ?>/dashboard/reservations-manage" class="btn-outline" style="display:inline-block;text-decoration:none;">← Back to Approvals</a>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

