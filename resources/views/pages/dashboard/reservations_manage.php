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
require_once __DIR__ . '/../../../../config/reservation_helpers.php';
$pdo = db();
$pageTitle = 'Reservation Approvals | LGU Facilities Reservation';

// Auto-decline expired pending reservations (past their reservation time)
$autoDeclined = autoDeclineExpiredReservations();

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
            // Get reservation details for audit log (including facility status)
            $resStmt = $pdo->prepare('SELECT r.id, r.reservation_date, r.time_slot, r.status, r.postponed_priority, r.facility_id, f.name AS facility_name, f.status AS facility_status, u.name AS requester_name, u.id AS requester_id, u.email AS requester_email
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
                    $emailBody .= '<p><a href="' . base_url() . '/resources/views/pages/dashboard/my_reservations.php">View My Reservations</a></p>';
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
                    $emailBody = getReservationPostponedEmailTemplate(
                        $reservation['requester_name'],
                        $reservation['facility_name'],
                        $oldDate,
                        $oldTimeSlot,
                        $newDate,
                        $newTimeSlot,
                        $reason
                    );
                    sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Postponed', $emailBody);
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
                
                // Validate facility status before approval
                if ($action === 'approved') {
                    $facilityStatus = strtolower($reservation['facility_status'] ?? 'available');
                    if ($facilityStatus === 'maintenance' || $facilityStatus === 'offline') {
                        $statusLabel = ucfirst($facilityStatus);
                        throw new Exception('Cannot approve reservation: The facility "' . htmlspecialchars($reservation['facility_name']) . '" is currently under ' . $statusLabel . '. Please change the facility status to "Available" before approving reservations.');
                    }
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
                        if ($action === 'approved') {
                            // Use the new professional template for approvals
                            $emailBody = getReservationApprovedEmailTemplate(
                                $reservation['requester_name'],
                                $reservation['facility_name'],
                                $reservation['reservation_date'],
                                $reservation['time_slot'],
                                $note ?? ''
                            );
                            sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Approved', $emailBody);
                        } elseif ($action === 'denied') {
                            // Use professional template for denials
                            $emailBody = getReservationDeniedEmailTemplate(
                                $reservation['requester_name'],
                                $reservation['facility_name'],
                                $reservation['reservation_date'],
                                $reservation['time_slot'],
                                $note ?? ''
                            );
                            sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Denied', $emailBody);
                        } elseif ($action === 'cancelled') {
                            // Use professional template for cancellations
                            $emailBody = getReservationCancelledEmailTemplate(
                                $reservation['requester_name'],
                                $reservation['facility_name'],
                                $reservation['reservation_date'],
                                $reservation['time_slot'],
                                $note ?? ''
                            );
                            sendEmail($reservation['requester_email'], $reservation['requester_name'], 'Reservation Cancelled', $emailBody);
                        }
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

// Get pending reservations with pagination and filtering
$pendingPerPage = 10;
$pendingPage = max(1, (int)($_GET['pending_page'] ?? 1));
$pendingOffset = ($pendingPage - 1) * $pendingPerPage;
$pendingSearch = trim($_GET['pending_search'] ?? '');

// Build pending query with filters
$pendingWhere = ['r.status IN ("pending", "postponed")'];
$pendingParams = [];

if (!empty($pendingSearch)) {
    $pendingWhere[] = '(u.name LIKE :pending_search OR f.name LIKE :pending_search OR r.purpose LIKE :pending_search)';
    $pendingParams['pending_search'] = '%' . $pendingSearch . '%';
}

$pendingWhereClause = 'WHERE ' . implode(' AND ', $pendingWhere);

// Count total pending reservations
$pendingCountSql = 'SELECT COUNT(*) FROM reservations r JOIN facilities f ON r.facility_id = f.id JOIN users u ON r.user_id = u.id ' . $pendingWhereClause;
$pendingCountStmt = $pdo->prepare($pendingCountSql);
$pendingCountStmt->execute($pendingParams);
$pendingTotal = (int)$pendingCountStmt->fetchColumn();
$pendingTotalPages = max(1, (int)ceil($pendingTotal / $pendingPerPage));

// Get pending reservations
$pendingSql = 'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.status, r.postponed_priority, f.name AS facility, u.name AS requester
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     ' . $pendingWhereClause . '
     ORDER BY r.postponed_priority DESC, r.postponed_at ASC, r.created_at ASC
     LIMIT :pending_limit OFFSET :pending_offset';
$pendingStmt = $pdo->prepare($pendingSql);
foreach ($pendingParams as $key => $value) {
    $pendingStmt->bindValue(':' . $key, $value);
}
$pendingStmt->bindValue(':pending_limit', $pendingPerPage, PDO::PARAM_INT);
$pendingStmt->bindValue(':pending_offset', $pendingOffset, PDO::PARAM_INT);
$pendingStmt->execute();
$pendingReservations = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Get approved reservations for management (only upcoming dates) with pagination and filtering
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');
$approvedPerPage = 10;
$approvedPage = max(1, (int)($_GET['approved_page'] ?? 1));
$approvedOffset = ($approvedPage - 1) * $approvedPerPage;
$approvedSearch = trim($_GET['approved_search'] ?? '');

// Build approved query with filters
// Use unique parameter names for each occurrence to avoid PDO binding issues
$approvedWhere = [
    'r.status = "approved"',
    '(' .
    'r.reservation_date > :approved_current_date1' .
    ' OR (' .
    'r.reservation_date = :approved_current_date2' .
    ' AND (' .
    '(r.time_slot LIKE "%Morning%" AND :approved_current_hour1 < 12)' .
    ' OR (r.time_slot LIKE "%Afternoon%" AND :approved_current_hour2 < 17)' .
    ' OR (r.time_slot LIKE "%Evening%" AND :approved_current_hour3 < 21)' .
    ')' .
    ')' .
    ')'
];
$approvedParams = [
    'approved_current_date1' => $currentDate,
    'approved_current_date2' => $currentDate,
    'approved_current_hour1' => $currentHour,
    'approved_current_hour2' => $currentHour,
    'approved_current_hour3' => $currentHour,
];

if (!empty($approvedSearch)) {
    $approvedWhere[] = '(u.name LIKE :approved_search1 OR f.name LIKE :approved_search2 OR r.purpose LIKE :approved_search3 OR u.email LIKE :approved_search4)';
    $approvedParams['approved_search1'] = '%' . $approvedSearch . '%';
    $approvedParams['approved_search2'] = '%' . $approvedSearch . '%';
    $approvedParams['approved_search3'] = '%' . $approvedSearch . '%';
    $approvedParams['approved_search4'] = '%' . $approvedSearch . '%';
}

$approvedWhereClause = 'WHERE ' . implode(' AND ', $approvedWhere);

// Count total approved reservations
$approvedCountSql = 'SELECT COUNT(*) FROM reservations r JOIN facilities f ON r.facility_id = f.id JOIN users u ON r.user_id = u.id ' . $approvedWhereClause;
$approvedCountStmt = $pdo->prepare($approvedCountSql);
$approvedCountStmt->execute($approvedParams);
$approvedTotal = (int)$approvedCountStmt->fetchColumn();
$approvedTotalPages = max(1, (int)ceil($approvedTotal / $approvedPerPage));

// Get approved reservations
$approvedSql = 'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, f.name AS facility, u.name AS requester, u.email AS requester_email
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     ' . $approvedWhereClause . '
     ORDER BY r.reservation_date ASC, r.time_slot ASC
     LIMIT :approved_limit OFFSET :approved_offset';
$approvedStmt = $pdo->prepare($approvedSql);
foreach ($approvedParams as $key => $value) {
    $approvedStmt->bindValue(':' . $key, $value);
}
$approvedStmt->bindValue(':approved_limit', $approvedPerPage, PDO::PARAM_INT);
$approvedStmt->bindValue(':approved_offset', $approvedOffset, PDO::PARAM_INT);
$approvedStmt->execute();
$approvedReservations = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$totalStmt = $pdo->query('SELECT COUNT(*) FROM reservations');
$totalRows = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Get all reservations for modal (with search and filters)
$modalSearch = trim($_GET['modal_search'] ?? '');
$modalStatus = trim($_GET['modal_status'] ?? '');
$modalPage = max(1, (int)($_GET['modal_page'] ?? 1));
$modalPerPage = 10;
$modalOffset = ($modalPage - 1) * $modalPerPage;

$modalWhere = [];
$modalParams = [];

if (!empty($modalSearch)) {
    $modalWhere[] = '(r.id LIKE :modal_search_id OR u.name LIKE :modal_search_name OR u.email LIKE :modal_search_email OR f.name LIKE :modal_search_facility)';
    $modalParams['modal_search_id'] = '%' . $modalSearch . '%';
    $modalParams['modal_search_name'] = '%' . $modalSearch . '%';
    $modalParams['modal_search_email'] = '%' . $modalSearch . '%';
    $modalParams['modal_search_facility'] = '%' . $modalSearch . '%';
}

if (!empty($modalStatus) && in_array($modalStatus, ['approved', 'denied', 'cancelled', 'pending', 'postponed'], true)) {
    $modalWhere[] = 'r.status = :modal_status';
    $modalParams['modal_status'] = $modalStatus;
}

$modalWhereClause = !empty($modalWhere) ? 'WHERE ' . implode(' AND ', $modalWhere) : '';

// Count total for modal
$modalCountStmt = $pdo->prepare(
    "SELECT COUNT(*) as total FROM reservations r JOIN facilities f ON r.facility_id = f.id JOIN users u ON r.user_id = u.id $modalWhereClause"
);
foreach ($modalParams as $key => $value) {
    $modalCountStmt->bindValue(':' . $key, $value);
}
$modalCountStmt->execute();
$modalTotal = (int)$modalCountStmt->fetch(PDO::FETCH_ASSOC)['total'];
$modalTotalPages = max(1, ceil($modalTotal / $modalPerPage));

// Fetch modal reservations
$modalHistoryStmt = $pdo->prepare(
    "SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility, u.name AS requester
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     $modalWhereClause
     ORDER BY r.updated_at DESC
     LIMIT :modal_limit OFFSET :modal_offset"
);
foreach ($modalParams as $key => $value) {
    $modalHistoryStmt->bindValue(':' . $key, $value);
}
$modalHistoryStmt->bindValue(':modal_limit', $modalPerPage, PDO::PARAM_INT);
$modalHistoryStmt->bindValue(':modal_offset', $modalOffset, PDO::PARAM_INT);
$modalHistoryStmt->execute();
$modalHistory = $modalHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span>Approvals</span>
    </div>
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
        <div style="flex: 1;">
            <h1>Reservation Approvals</h1>
            <small>Review pending requests and manage reservation statuses.</small>
        </div>
        <button type="button" onclick="openAllReservationsModal()" class="btn-primary" style="padding: 0.75rem 1.5rem; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; white-space: nowrap;">
            All Reservations
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
            <h2 style="margin: 0;">Pending Requests</h2>
            <form method="GET" style="display: flex; gap: 0.5rem; align-items: center; flex: 1; min-width: 250px; max-width: 400px;">
                <input type="text" name="pending_search" value="<?= htmlspecialchars($pendingSearch); ?>" placeholder="Search by name, facility, or purpose..." style="flex: 1; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
                <button type="submit" class="btn-primary" style="padding: 0.5rem 1rem;">Search</button>
                <?php if (!empty($pendingSearch)): ?>
                    <a href="?" class="btn-outline" style="padding: 0.5rem 1rem; text-decoration: none;">Clear</a>
                <?php endif; ?>
                <input type="hidden" name="approved_page" value="<?= $approvedPage; ?>">
                <input type="hidden" name="approved_search" value="<?= htmlspecialchars($approvedSearch); ?>">
                <input type="hidden" name="page" value="<?= $page; ?>">
            </form>
        </div>
        <?php if (empty($pendingReservations)): ?>
            <p>No reservations awaiting approval<?= !empty($pendingSearch) ? ' matching your search.' : '.'; ?></p>
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
                                    <a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= $reservation['id']; ?>" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.9rem;">View Details</a>
                                    <?php if ($reservation['status'] === 'pending' || $reservation['status'] === 'postponed'): ?>
                                        <form method="POST" action="<?= base_path(); ?>/dashboard/reservations-manage" style="display:flex; gap:0.5rem; flex:1; min-width:300px;">
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
            <?php if ($pendingTotalPages > 1): ?>
                <div class="pagination" style="margin-top: 1rem;">
                    <?php if ($pendingPage > 1): ?>
                        <a href="?pending_page=<?= $pendingPage - 1; ?>&pending_search=<?= urlencode($pendingSearch); ?>&approved_page=<?= $approvedPage; ?>&approved_search=<?= urlencode($approvedSearch); ?>&page=<?= $page; ?>">&larr; Prev</a>
                    <?php endif; ?>
                    <span class="current">Page <?= $pendingPage; ?> of <?= $pendingTotalPages; ?> (<?= $pendingTotal; ?> total)</span>
                    <?php if ($pendingPage < $pendingTotalPages): ?>
                        <a href="?pending_page=<?= $pendingPage + 1; ?>&pending_search=<?= urlencode($pendingSearch); ?>&approved_page=<?= $approvedPage; ?>&approved_search=<?= urlencode($approvedSearch); ?>&page=<?= $page; ?>">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="margin: 0 0 0.5rem 0;">Approved Reservations Management</h2>
            <p style="color: #8b95b5; margin: 0; font-size: 0.9rem;">
                Manage upcoming approved reservations in case of emergencies or schedule conflicts. Only future reservations can be modified, postponed, or cancelled.
            </p>
        </div>
    </div>
    <form method="GET" style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap;">
        <input type="text" name="approved_search" value="<?= htmlspecialchars($approvedSearch); ?>" placeholder="Search by name, facility, purpose, or email..." style="flex: 1; min-width: 250px; max-width: 400px; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px;">
        <button type="submit" class="btn-primary" style="padding: 0.5rem 1rem;">Search</button>
        <?php if (!empty($approvedSearch)): ?>
            <a href="?" class="btn-outline" style="padding: 0.5rem 1rem; text-decoration: none;">Clear</a>
        <?php endif; ?>
        <input type="hidden" name="pending_page" value="<?= $pendingPage; ?>">
        <input type="hidden" name="pending_search" value="<?= htmlspecialchars($pendingSearch); ?>">
        <input type="hidden" name="page" value="<?= $page; ?>">
    </form>
    
    <?php if (empty($approvedReservations)): ?>
        <p style="color: #8b95b5; text-align: center; padding: 2rem;">No approved reservations at this time<?= !empty($approvedSearch) ? ' matching your search.' : '.'; ?></p>
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
                                    <a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= $reservation['id']; ?>" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.9rem;">View Details</a>
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
        <?php if ($approvedTotalPages > 1): ?>
            <div class="pagination" style="margin-top: 1rem;">
                <?php if ($approvedPage > 1): ?>
                    <a href="?approved_page=<?= $approvedPage - 1; ?>&approved_search=<?= urlencode($approvedSearch); ?>&pending_page=<?= $pendingPage; ?>&pending_search=<?= urlencode($pendingSearch); ?>&page=<?= $page; ?>">&larr; Prev</a>
                <?php endif; ?>
                <span class="current">Page <?= $approvedPage; ?> of <?= $approvedTotalPages; ?> (<?= $approvedTotal; ?> total)</span>
                <?php if ($approvedPage < $approvedTotalPages): ?>
                    <a href="?approved_page=<?= $approvedPage + 1; ?>&approved_search=<?= urlencode($approvedSearch); ?>&pending_page=<?= $pendingPage; ?>&pending_search=<?= urlencode($pendingSearch); ?>&page=<?= $page; ?>">Next &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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

function openPostponeModal(reservationId, currentDate, currentTime, facilityName) {
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

function openCancelModal(reservationId, facilityName, currentDate, currentTime) {
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
</script>

<!-- All Reservations Modal -->
<div id="allReservationsModal" class="modal-overlay" style="display: <?= (!empty($modalSearch) || !empty($modalStatus)) ? 'flex' : 'none'; ?>;">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e5e7eb;">
            <h2 style="margin: 0; color: #1e3a5f;">All Reservations</h2>
            <button type="button" onclick="closeAllReservationsModal()" style="background: none; border: none; font-size: 1.5rem; color: #6c757d; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: all 0.2s ease;" onmouseover="this.style.background='#f0f0f0'; this.style.color='#333';" onmouseout="this.style.background='none'; this.style.color='#6c757d';">&times;</button>
        </div>
        
        <!-- Search and Filter Form -->
        <form method="GET" id="modalSearchForm" style="margin-bottom: 1.5rem; padding: 1rem; background: #f9fafb; border-radius: 8px;">
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: end;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151;">Search Reservations</label>
                    <input type="text" name="modal_search" value="<?= htmlspecialchars($modalSearch); ?>" placeholder="Search by ID, requester name, email, or facility..." style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; font-size: 0.95rem;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151;">Status Filter</label>
                    <select name="modal_status" style="padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; font-size: 0.95rem; min-width: 150px;">
                        <option value="">All Statuses</option>
                        <option value="approved" <?= $modalStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="denied" <?= $modalStatus === 'denied' ? 'selected' : ''; ?>>Denied</option>
                        <option value="cancelled" <?= $modalStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="pending" <?= $modalStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="postponed" <?= $modalStatus === 'postponed' ? 'selected' : ''; ?>>Postponed</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                <button type="submit" class="btn-primary" style="padding: 0.75rem 1.5rem; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">Search</button>
                <?php if (!empty($modalSearch) || !empty($modalStatus)): ?>
                    <a href="?" class="btn-outline" style="padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 6px;">Clear Filters</a>
                <?php endif; ?>
            </div>
            <!-- Preserve other GET parameters -->
            <input type="hidden" name="pending_page" value="<?= $pendingPage; ?>">
            <input type="hidden" name="pending_search" value="<?= htmlspecialchars($pendingSearch); ?>">
            <input type="hidden" name="approved_page" value="<?= $approvedPage; ?>">
            <input type="hidden" name="approved_search" value="<?= htmlspecialchars($approvedSearch); ?>">
        </form>
        
        <!-- Reservations List -->
        <div class="modal-body">
            <?php if (empty($modalHistory)): ?>
                <p style="text-align: center; color: #8b95b5; padding: 2rem;">No reservations found<?= !empty($modalSearch) || !empty($modalStatus) ? ' matching your filters.' : '.'; ?></p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($modalHistory as $record): ?>
                        <?php
                        $historyItems = $pdo->prepare(
                            'SELECT status, note, created_at FROM reservation_history WHERE reservation_id = :id ORDER BY created_at DESC'
                        );
                        $historyItems->execute(['id' => $record['id']]);
                        $timeline = $historyItems->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <article class="facility-card-admin" style="padding: 1rem; border: 1px solid #e5e7eb; border-radius: 8px;">
                            <header style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.75rem;">
                                <div>
                                    <h3 style="margin: 0 0 0.25rem 0; color: #1e3a5f;"><?= htmlspecialchars($record['facility']); ?></h3>
                                    <small style="color: #6b7280;"><?= htmlspecialchars($record['reservation_date']); ?> • <?= htmlspecialchars($record['time_slot']); ?></small>
                                </div>
                                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap: wrap;">
                                    <span class="status-badge <?= $record['status']; ?>"><?= ucfirst($record['status']); ?></span>
                                    <a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= $record['id']; ?>" class="btn-outline" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.9rem;">View Details</a>
                                </div>
                            </header>
                            <p style="margin:0 0 0.75rem; color: #4a5568;"><strong>Requester:</strong> <?= htmlspecialchars($record['requester']); ?></p>
                            <?php if ($timeline): ?>
                                <ul class="timeline" style="margin: 0; padding-left: 1.25rem;">
                                    <?php foreach ($timeline as $event): ?>
                                        <li style="margin-bottom: 0.5rem;">
                                            <strong style="color: #1e3a5f;"><?= ucfirst($event['status']); ?></strong>
                                            <p style="margin:0.25rem 0; color: #6b7280;"><?= $event['note'] ? htmlspecialchars($event['note']) : 'No remarks provided.'; ?></p>
                                            <small style="color:#8b95b5;"><?= htmlspecialchars($event['created_at']); ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($modalTotalPages > 1): ?>
                    <div class="pagination" style="margin-top: 1.5rem; justify-content: center;">
                        <?php if ($modalPage > 1): ?>
                            <a href="?modal_page=<?= $modalPage - 1; ?>&modal_search=<?= urlencode($modalSearch); ?>&modal_status=<?= urlencode($modalStatus); ?>&pending_page=<?= $pendingPage; ?>&pending_search=<?= urlencode($pendingSearch); ?>&approved_page=<?= $approvedPage; ?>&approved_search=<?= urlencode($approvedSearch); ?>" class="btn-outline" style="text-decoration: none; padding: 0.5rem 1rem;">&larr; Prev</a>
                        <?php endif; ?>
                        <span class="current" style="padding: 0.5rem 1rem;">Page <?= $modalPage; ?> of <?= $modalTotalPages; ?> (<?= $modalTotal; ?> total)</span>
                        <?php if ($modalPage < $modalTotalPages): ?>
                            <a href="?modal_page=<?= $modalPage + 1; ?>&modal_search=<?= urlencode($modalSearch); ?>&modal_status=<?= urlencode($modalStatus); ?>&pending_page=<?= $pendingPage; ?>&pending_search=<?= urlencode($pendingSearch); ?>&approved_page=<?= $approvedPage; ?>&approved_search=<?= urlencode($approvedSearch); ?>" class="btn-outline" style="text-decoration: none; padding: 0.5rem 1rem;">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.modal-content {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    padding: 2rem;
    width: 100%;
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-overlay.show {
    display: flex !important;
}
</style>

<script>
(function() {
    const modal = document.getElementById('allReservationsModal');
    if (!modal) return;
    
    // Check if modal should be open on page load (when search/filter params exist)
    const urlParams = new URLSearchParams(window.location.search);
    const hasModalParams = urlParams.has('modal_search') || urlParams.has('modal_status');
    
    if (hasModalParams) {
        modal.classList.add('show');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function openAllReservationsModal() {
        if (modal) {
            modal.classList.add('show');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
    
    window.openAllReservationsModal = openAllReservationsModal;
    
    function closeAllReservationsModal() {
        if (modal) {
            // Remove modal parameters from URL when closing (without modal_open param)
            const url = new URL(window.location.href);
            url.searchParams.delete('modal_search');
            url.searchParams.delete('modal_status');
            url.searchParams.delete('modal_page');
            
            // Update URL without reload to remove modal params
            window.history.replaceState({}, '', url);
            
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
    
    window.closeAllReservationsModal = closeAllReservationsModal;
    
    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeAllReservationsModal();
        }
    });
    
    // Close modal on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            closeAllReservationsModal();
        }
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

