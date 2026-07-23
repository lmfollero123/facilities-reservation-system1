<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? '';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'reservations')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/sms_helper.php';
require_once __DIR__ . '/../../../../config/notification_preferences.php';
require_once __DIR__ . '/../../../../config/reservation_helpers.php';
require_once __DIR__ . '/../../../../config/lookups.php';
require_once __DIR__ . '/../../../../config/flash_helper.php';
$pdo = db();
$pageTitle = 'Reservation Approvals | LGU Facilities Reservation';
$paymentsCfg = file_exists(__DIR__ . '/../../../../config/payments.php') ? (require __DIR__ . '/../../../../config/payments.php') : [];
$approvalFirstThenPayment = !empty($paymentsCfg['enabled']) && !empty($paymentsCfg['require_payment_for_reservations']);

// Auto-decline expired pending reservations (past their reservation time)
$autoDeclined = autoDeclineExpiredReservations();

$message = '';
$messageType = 'success';
if ($autoDeclined > 0) {
    $message = $autoDeclined . ' pending reservation(s) automatically denied due to expired reservation time.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !frs_csrf_ok()) {
    $message = 'Your session expired or the form is invalid. Please refresh and try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_id'], $_POST['action'])) {
    $reservationId = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    $allowed = ['approved', 'denied', 'cancelled', 'modify', 'postpone', 'staff_reschedule'];

    if ($reservationId && in_array($action, $allowed, true)) {
        // Check permissions for each action
        $permissionError = false;
        switch ($action) {
            case 'approved':
            case 'denied':
            case 'modify':
            case 'postpone':
            case 'staff_reschedule':
                if (!frs_can_update($role, 'reservations')) {
                    $permissionError = true;
                }
                break;
            case 'cancelled':
                if (!frs_can_delete($role, 'reservations')) {
                    $permissionError = true;
                }
                break;
        }

        if ($permissionError) {
            $message = 'You do not have permission to perform this action.';
            $messageType = 'error';
        } else {
        try {
            // Get reservation details for audit log (including facility status and is_free)
            $resStmt = $pdo->prepare('SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.expected_attendees, r.status, r.postponed_priority, r.facility_id, f.name AS facility_name, f.status AS facility_status, f.is_free, u.name AS requester_name, u.id AS requester_id, u.email AS requester_email, u.mobile AS requester_mobile
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
                
                if (frs_reservation_slot_has_passed((string)$reservationDate, (string)$reservationTimeSlot)) {
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
                
                $newPurpose = trim($_POST['purpose'] ?? '') ?: ($reservation['purpose'] ?? '');
                if (empty($newPurpose)) {
                    throw new Exception('Purpose is required.');
                }
                $newExpectedAttendees = isset($_POST['expected_attendees']) && $_POST['expected_attendees'] !== '' ? (int)$_POST['expected_attendees'] : null;
                if ($newExpectedAttendees !== null && $newExpectedAttendees < 0) {
                    $newExpectedAttendees = null;
                }
                
                $facilityId = (int)($reservation['facility_id'] ?? 0);
                if ($facilityId <= 0) {
                    $facilityStmt = $pdo->prepare('SELECT facility_id FROM reservations WHERE id = ?');
                    $facilityStmt->execute([$reservationId]);
                    $facilityId = (int)$facilityStmt->fetchColumn();
                }
                if (frs_has_overlapping_booking($pdo, $facilityId, $newDate, $newTimeSlot, $reservationId)) {
                    throw new Exception('The selected date and time overlaps an existing booking. Please choose another time.');
                }
                
                // Update reservation (date, time, purpose, expected_attendees)
                $oldDate = $reservation['reservation_date'];
                $oldTimeSlot = $reservation['time_slot'];
                
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
                    base_path() . '/dashboard/my-reservations');
                
                // Send email notification
                if (!empty($reservation['requester_email']) && !empty($reservation['requester_name'])) {
                    $emailSubject = 'Reservation Modified - ' . $reservation['facility_name'];
                    $emailBody = '<p>Hi ' . htmlspecialchars($reservation['requester_name']) . ',</p>';
                    $emailBody .= '<p>Your approved reservation for <strong>' . htmlspecialchars($reservation['facility_name']) . '</strong> has been modified.</p>';
                    $emailBody .= '<p><strong>Original Date/Time:</strong> ' . date('F j, Y', strtotime($oldDate)) . ' (' . htmlspecialchars($oldTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>New Date/Time:</strong> ' . date('F j, Y', strtotime($newDate)) . ' (' . htmlspecialchars($newTimeSlot) . ')</p>';
                    $emailBody .= '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>';
                    $emailBody .= '<p><a href="' . base_url() . '/dashboard/my-reservations">View My Reservations</a></p>';
                    sendEmail($reservation['requester_email'], $reservation['requester_name'], $emailSubject, $emailBody);
                }
                
                $message = 'Reservation modified successfully.';
                
            } elseif ($action === 'postpone') {
                if (frs_reservation_slot_has_passed((string)$reservation['reservation_date'], (string)$reservation['time_slot'])) {
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
                
                $facilityId = (int)($reservation['facility_id'] ?? 0);
                if ($facilityId <= 0) {
                    $facilityStmt = $pdo->prepare('SELECT facility_id FROM reservations WHERE id = ?');
                    $facilityStmt->execute([$reservationId]);
                    $facilityId = (int)$facilityStmt->fetchColumn();
                }
                if (frs_has_overlapping_booking($pdo, $facilityId, $newDate, $newTimeSlot, $reservationId)) {
                    throw new Exception('The selected date and time overlaps an existing booking. Please choose another time.');
                }
                
                // Update reservation - set to postponed with priority (distinct state, requires re-approval)
                $oldDate = $reservation['reservation_date'];
                $oldTimeSlot = $reservation['time_slot'];
                
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
                $details = 'RES-' . $reservationId . ' – ' . $reservation['facility_name'] . ' – Postponed from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason;
                logAudit('Postponed approved reservation', 'Reservations', $details);
                
                // Create notification
                $notifMessage = 'Your approved reservation for ' . $reservation['facility_name'];
                $notifMessage .= ' has been postponed from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
                $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
                $notifMessage .= ' The new date requires re-approval. Reason: ' . $reason;
                
                createNotification($reservation['requester_id'], 'booking', 'Reservation Postponed', $notifMessage, 
                    base_path() . '/dashboard/my-reservations');
                
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
                
                $message = 'Reservation postponed successfully. It now has postponed status with priority and requires re-approval.';
                
            } elseif ($action === 'staff_reschedule') {
                if (empty($_POST['new_date']) || empty($_POST['start_time']) || empty($_POST['end_time'])) {
                    throw new Exception('New date, start time, and end time are required for reschedule.');
                }

                $startTime = trim((string)$_POST['start_time']);
                $endTime = trim((string)$_POST['end_time']);
                $startTimeObj = DateTime::createFromFormat('H:i', $startTime);
                $endTimeObj = DateTime::createFromFormat('H:i', $endTime);
                if (!$startTimeObj || !$endTimeObj) {
                    throw new Exception('Invalid time format. Please use valid time values.');
                }
                if ($endTimeObj <= $startTimeObj) {
                    throw new Exception('End time must be after start time.');
                }

                $newTimeSlot = $startTime . ' - ' . $endTime;
                $result = frs_staff_reschedule_postponed_priority(
                    $pdo,
                    $reservationId,
                    $reservation,
                    trim((string)$_POST['new_date']),
                    $newTimeSlot,
                    trim((string)($_POST['reason'] ?? '')),
                    $approvalFirstThenPayment
                );
                $message = $result['message'];

            } else {
                if ($action === 'cancelled' && $reservation['status'] === 'approved') {
                    if (frs_reservation_slot_has_passed((string)$reservation['reservation_date'], (string)$reservation['time_slot'])) {
                        throw new Exception('Cannot cancel past reservations. Only upcoming approved reservations can be cancelled.');
                    }
                    $reason = trim($_POST['reason'] ?? '');
                    if (empty($reason)) {
                        throw new Exception('Reason is required for cancelling an approved reservation.');
                    }
                    $note = 'Cancelled by admin/staff. Reason: ' . $reason;
                } else {
                    $note = trim($_POST['note'] ?? '');
                    if ($action === 'denied' && $note === '') {
                        throw new Exception('A reason is required when denying a reservation.');
                    }
                    $note = $note !== '' ? $note : null;
                }

                $statusResult = frs_staff_apply_status_decision(
                    $pdo,
                    $reservationId,
                    $action,
                    $reservation,
                    $approvalFirstThenPayment,
                    $note
                );
                $message = $statusResult['message'];
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
        }
    }
}

if ($message !== '' && $messageType === 'success') {
    frs_flash_success($message);
    $message = '';
}

// Get pending reservations with pagination and filtering
$pendingPerPage = 10;
$pendingPage = max(1, (int)($_GET['pending_page'] ?? 1));
$pendingOffset = ($pendingPage - 1) * $pendingPerPage;
$pendingSearch = trim($_GET['pending_search'] ?? '');
$pendingFilter = trim($_GET['pending_filter'] ?? 'all');
$allowedPendingFilters = ['all', 'pending', 'postponed', 'priority'];
if (!in_array($pendingFilter, $allowedPendingFilters, true)) {
    $pendingFilter = 'all';
}

// Sort: default soonest reservation schedule first (priority postponed still pins to top)
$pendingSort = trim((string)($_GET['pending_sort'] ?? 'schedule_asc'));
$allowedPendingSorts = [
    'schedule_asc' => 'Schedule (soonest first)',
    'schedule_desc' => 'Schedule (latest first)',
    'submitted_desc' => 'Date submitted (newest)',
    'submitted_asc' => 'Date submitted (oldest)',
    'facility_asc' => 'Facility (A–Z)',
    'requester_asc' => 'Requester (A–Z)',
];
if (!isset($allowedPendingSorts[$pendingSort])) {
    $pendingSort = 'schedule_asc';
}

$pendingSortSqlMap = [
    'schedule_asc' => 'r.postponed_priority DESC, r.reservation_date ASC, SUBSTRING_INDEX(r.time_slot, " - ", 1) ASC, r.created_at ASC',
    'schedule_desc' => 'r.postponed_priority DESC, r.reservation_date DESC, SUBSTRING_INDEX(r.time_slot, " - ", 1) DESC, r.created_at DESC',
    'submitted_desc' => 'r.postponed_priority DESC, r.created_at DESC, r.reservation_date ASC',
    'submitted_asc' => 'r.postponed_priority DESC, r.created_at ASC, r.reservation_date ASC',
    'facility_asc' => 'r.postponed_priority DESC, f.name ASC, r.reservation_date ASC, SUBSTRING_INDEX(r.time_slot, " - ", 1) ASC',
    'requester_asc' => 'r.postponed_priority DESC, u.name ASC, r.reservation_date ASC, SUBSTRING_INDEX(r.time_slot, " - ", 1) ASC',
];
$pendingOrderBy = $pendingSortSqlMap[$pendingSort];

// Build pending query with filters - use lookup values for non-final statuses
$pendingStatuses = [];
if (frs_lookups_table_ready($pdo)) {
    foreach (frs_lookup_values($pdo, 'reservation_status') as $status) {
        // Exclude 'approved' from pending requests - it should only appear in "All Reservations"
        if (!($status['metadata']['is_final'] ?? false) && $status['slug'] !== 'approved') {
            $pendingStatuses[] = $status['slug'];
        }
    }
} else {
    // Fallback to hardcoded statuses
    $pendingStatuses = ['pending', 'postponed', 'pending_payment'];
}
$pendingWhere = ['r.status IN ("' . implode('", "', $pendingStatuses) . '")'];
$pendingParams = [];

if ($pendingFilter === 'pending') {
    $pendingWhere[] = 'r.status = "pending"';
} elseif ($pendingFilter === 'postponed') {
    $pendingWhere[] = 'r.status = "postponed"';
} elseif ($pendingFilter === 'priority') {
    $pendingWhere[] = 'r.postponed_priority = 1';
}

if (!empty($pendingSearch)) {
    $pendingWhere[] = '(u.name LIKE :pending_search_name OR f.name LIKE :pending_search_facility OR r.purpose LIKE :pending_search_purpose)';
    $pendingParams['pending_search_name'] = '%' . $pendingSearch . '%';
    $pendingParams['pending_search_facility'] = '%' . $pendingSearch . '%';
    $pendingParams['pending_search_purpose'] = '%' . $pendingSearch . '%';
}

$pendingWhereClause = 'WHERE ' . implode(' AND ', $pendingWhere);

// Count total pending reservations
$pendingCountSql = 'SELECT COUNT(*) FROM reservations r JOIN facilities f ON r.facility_id = f.id JOIN users u ON r.user_id = u.id ' . $pendingWhereClause;
$pendingCountStmt = $pdo->prepare($pendingCountSql);
$pendingCountStmt->execute($pendingParams);
$pendingTotal = (int)$pendingCountStmt->fetchColumn();
$pendingTotalPages = max(1, (int)ceil($pendingTotal / $pendingPerPage));

// Get pending reservations
$pendingSql = 'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.status, r.postponed_priority, r.postponed_at,
       r.expected_attendees, r.is_commercial, r.created_at,
       f.id AS facility_id, f.name AS facility, f.capacity_threshold, f.base_rate,
       u.id AS requester_id, u.name AS requester, u.email AS requester_email, u.mobile AS requester_mobile
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     ' . $pendingWhereClause . '
     ORDER BY ' . $pendingOrderBy . '
     LIMIT :pending_limit OFFSET :pending_offset';
$pendingStmt = $pdo->prepare($pendingSql);
foreach ($pendingParams as $key => $value) {
    $pendingStmt->bindValue(':' . $key, $value);
}
$pendingStmt->bindValue(':pending_limit', $pendingPerPage, PDO::PARAM_INT);
$pendingStmt->bindValue(':pending_offset', $pendingOffset, PDO::PARAM_INT);
$pendingStmt->execute();
$pendingReservations = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for filter tabs (same search scope, no status sub-filter)
$pendingBaseWhere = ['r.status IN ("' . implode('", "', $pendingStatuses) . '")'];
$pendingBaseParams = [];
if (!empty($pendingSearch)) {
    $pendingBaseWhere[] = '(u.name LIKE :pending_search_name OR f.name LIKE :pending_search_facility OR r.purpose LIKE :pending_search_purpose)';
    $pendingBaseParams['pending_search_name'] = '%' . $pendingSearch . '%';
    $pendingBaseParams['pending_search_facility'] = '%' . $pendingSearch . '%';
    $pendingBaseParams['pending_search_purpose'] = '%' . $pendingSearch . '%';
}
$pendingFilterCounts = ['all' => 0, 'pending' => 0, 'postponed' => 0, 'priority' => 0];
$allCountSql = 'SELECT COUNT(*) FROM reservations r JOIN facilities f ON r.facility_id = f.id JOIN users u ON r.user_id = u.id WHERE ' . implode(' AND ', $pendingBaseWhere);
$allCountStmt = $pdo->prepare($allCountSql);
$allCountStmt->execute($pendingBaseParams);
$pendingFilterCounts['all'] = (int)$allCountStmt->fetchColumn();
foreach (['pending' => 'r.status = "pending"', 'postponed' => 'r.status = "postponed"', 'priority' => 'r.postponed_priority = 1'] as $filterKey => $filterSql) {
    $countWhere = $pendingBaseWhere;
    $countWhere[] = $filterSql;
    $countSql = 'SELECT COUNT(*) FROM reservations r JOIN facilities f ON r.facility_id = f.id JOIN users u ON r.user_id = u.id WHERE ' . implode(' AND ', $countWhere);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($pendingBaseParams);
    $pendingFilterCounts[$filterKey] = (int)$countStmt->fetchColumn();
}

// Get approved reservations for management (only upcoming dates) with pagination and filtering
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');
$approvedPerPage = 10;
$approvedPage = max(1, (int)($_GET['approved_page'] ?? 1));
$approvedOffset = ($approvedPage - 1) * $approvedPerPage;
$approvedSearch = trim($_GET['approved_search'] ?? '');
$approvedSort = trim((string)($_GET['approved_sort'] ?? 'schedule_asc'));
$allowedApprovedSorts = [
    'schedule_asc' => 'Schedule (soonest first)',
    'schedule_desc' => 'Schedule (latest first)',
    'requester_asc' => 'Requester (A–Z)',
    'facility_asc' => 'Facility (A–Z)',
];
if (!isset($allowedApprovedSorts[$approvedSort])) {
    $approvedSort = 'schedule_asc';
}
$approvedSortSqlMap = [
    'schedule_asc' => 'r.reservation_date ASC, SUBSTRING_INDEX(r.time_slot, " - ", 1) ASC',
    'schedule_desc' => 'r.reservation_date DESC, SUBSTRING_INDEX(r.time_slot, " - ", 1) DESC',
    'requester_asc' => 'u.name ASC, r.reservation_date ASC',
    'facility_asc' => 'f.name ASC, r.reservation_date ASC',
];
$approvedOrderBy = $approvedSortSqlMap[$approvedSort];

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
$approvedSql = 'SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.expected_attendees, r.facility_id, f.name AS facility, u.name AS requester, u.email AS requester_email
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     ' . $approvedWhereClause . '
     ORDER BY ' . $approvedOrderBy . '
     LIMIT :approved_limit OFFSET :approved_offset';
$approvedStmt = $pdo->prepare($approvedSql);
foreach ($approvedParams as $key => $value) {
    $approvedStmt->bindValue(':' . $key, $value);
}
$approvedStmt->bindValue(':approved_limit', $approvedPerPage, PDO::PARAM_INT);
$approvedStmt->bindValue(':approved_offset', $approvedOffset, PDO::PARAM_INT);
$approvedStmt->execute();
$approvedReservations = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);

$approvalsView = trim((string)($_GET['view'] ?? $_POST['view'] ?? 'pending'));
if (!in_array($approvalsView, ['pending', 'approved'], true)) {
    $approvalsView = 'pending';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !isset($_GET['view']) && !isset($_POST['view'])) {
    $postViewAction = (string)$_POST['action'];
    if (in_array($postViewAction, ['modify', 'postpone', 'cancelled'], true)) {
        $approvalsView = 'approved';
    }
}

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));

$raBuildPendingQuery = static function (array $extra = []) use ($pendingPage, $pendingSearch, $pendingFilter, $pendingSort, $approvedPage, $approvedSearch, $approvedSort, $page, $approvalsView): string {
    $params = array_merge([
        'view' => $approvalsView,
        'pending_page' => $pendingPage,
        'pending_search' => $pendingSearch,
        'pending_filter' => $pendingFilter,
        'pending_sort' => $pendingSort,
        'approved_page' => $approvedPage,
        'approved_search' => $approvedSearch,
        'approved_sort' => $approvedSort,
        'page' => $page,
    ], $extra);
    return '?' . http_build_query($params);
};

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
            <?= frs_page_title('Reservation Approvals', 'Review pending requests or manage upcoming approved bookings using the tabs below.'); ?>
        </div>
        <button type="button" onclick="openAllReservationsModal()" class="btn-primary" style="padding: 0.75rem 1.5rem; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; white-space: nowrap;">
            All Reservations
        </button>
    </div>
</div>

<div class="booking-wrapper ra-approvals-layout">
    <section class="booking-card ra-approvals-main">
        <details class="ra-legend-details">
            <summary class="ra-legend-summary">Status guide</summary>
            <div class="ra-legend-grid">
                <span class="ra-legend-item"><span class="status-badge pending">Pending</span> Staff review</span>
                <span class="ra-legend-item"><span class="status-badge approved">Approved</span> Reserved</span>
                <span class="ra-legend-item"><span class="status-badge pending_payment">Awaiting Payment</span> Pay to finalize</span>
                <span class="ra-legend-item"><span class="status-badge denied">Denied</span> Declined</span>
                <span class="ra-legend-item"><span class="status-badge cancelled">Cancelled</span> Slot released</span>
                <span class="ra-legend-item"><span class="status-badge postponed">Postponed</span> Re-approval</span>
                <span class="ra-legend-item"><span class="status-badge on_hold">On Hold</span> Temporarily paused</span>
            </div>
            <p class="ra-legend-note">Approve and Deny open a review step first. Postponed + priority items use <strong>Reschedule</strong> (auto-approves the new date). Lists default to soonest schedule first; priority postponed items stay pinned at the top. Use Sort to change order.</p>
        </details>

        <div data-frs-partial-id="ra-approvals-main" data-frs-partial-root>
        <?php if ($message): ?>
            <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <nav class="ra-view-tabs" aria-label="Approval sections">
            <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'pending', 'pending_page' => 1])); ?>"
               class="ra-view-tab<?= $approvalsView === 'pending' ? ' is-active' : ''; ?>"
               data-frs-partial="ra-approvals-main"
               <?= $approvalsView === 'pending' ? 'aria-current="page"' : ''; ?>>
                Pending Requests
                <span class="ra-view-tab__count"><?= (int)$pendingTotal; ?></span>
            </a>
            <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'approved', 'approved_page' => 1])); ?>"
               class="ra-view-tab<?= $approvalsView === 'approved' ? ' is-active' : ''; ?>"
               data-frs-partial="ra-approvals-main"
               <?= $approvalsView === 'approved' ? 'aria-current="page"' : ''; ?>>
                Approved
                <span class="ra-view-tab__count"><?= (int)$approvedTotal; ?></span>
            </a>
        </nav>

        <?php if ($approvalsView === 'pending'): ?>
        <div class="ra-view-panel" id="ra-panel-pending">
        <div class="ra-queue-header">
            <div class="ra-queue-header__title-row">
                <h2 class="ra-queue-title">Pending Requests</h2>
                <span class="ra-queue-count"><?= (int)$pendingTotal; ?> total</span>
            </div>
            <p class="ra-queue-subtitle">Approve and deny open a review step first. Priority postponed items use Reschedule (pick a free date and auto-approve).</p>
        </div>

        <div class="ra-queue-toolbar">
            <nav class="ra-filter-tabs" aria-label="Filter pending requests">
                <?php
                $raFilterTabs = [
                    'all' => 'All',
                    'pending' => 'Pending',
                    'postponed' => 'Postponed',
                    'priority' => 'Priority',
                ];
                foreach ($raFilterTabs as $filterKey => $filterLabel):
                    $isActive = $pendingFilter === $filterKey;
                    $filterHref = $raBuildPendingQuery(['view' => 'pending', 'pending_filter' => $filterKey, 'pending_page' => 1]);
                ?>
                    <a href="<?= htmlspecialchars($filterHref); ?>"
                       class="ra-filter-tab<?= $isActive ? ' is-active' : ''; ?>"
                       data-frs-partial="ra-approvals-main">
                        <?= htmlspecialchars($filterLabel); ?>
                        <span class="ra-filter-tab__count"><?= (int)($pendingFilterCounts[$filterKey] ?? 0); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form method="GET" class="ra-search-form" data-frs-partial="ra-approvals-main">
                <input type="hidden" name="view" value="pending">
                <input type="hidden" name="pending_filter" value="<?= htmlspecialchars($pendingFilter); ?>">
                <input type="hidden" name="pending_sort" value="<?= htmlspecialchars($pendingSort); ?>">
                <input type="hidden" name="approved_page" value="<?= $approvedPage; ?>">
                <input type="hidden" name="approved_search" value="<?= htmlspecialchars($approvedSearch); ?>">
                <input type="hidden" name="approved_sort" value="<?= htmlspecialchars($approvedSort); ?>">
                <input type="hidden" name="page" value="<?= $page; ?>">
                <input type="hidden" name="pending_page" value="1">
                <div class="ra-toolbar-controls">
                    <div class="ra-sort-menu" data-ra-sort-menu>
                        <button type="button"
                                class="ra-sort-trigger<?= $pendingSort !== 'schedule_asc' ? ' is-active' : ''; ?>"
                                aria-expanded="false"
                                aria-haspopup="listbox"
                                aria-label="Sort pending requests"
                                title="Sort: <?= htmlspecialchars($allowedPendingSorts[$pendingSort]); ?>">
                            <i class="bi bi-sort-down" aria-hidden="true"></i>
                        </button>
                        <div class="ra-sort-panel" role="listbox" hidden>
                            <div class="ra-sort-panel__title">Sort by</div>
                            <?php foreach ($allowedPendingSorts as $sortKey => $sortLabel): ?>
                                <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'pending', 'pending_sort' => $sortKey, 'pending_page' => 1])); ?>"
                                   class="ra-sort-option<?= $pendingSort === $sortKey ? ' is-selected' : ''; ?>"
                                   role="option"
                                   aria-selected="<?= $pendingSort === $sortKey ? 'true' : 'false'; ?>"
                                   data-frs-partial="ra-approvals-main">
                                    <?= htmlspecialchars($sortLabel); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="ra-search-field">
                        <input type="search" name="pending_search" value="<?= htmlspecialchars($pendingSearch); ?>" placeholder="Search requester, facility, purpose…" class="ra-search-input" aria-label="Search pending requests">
                        <button type="submit" class="btn-primary ra-search-btn">Search</button>
                        <?php if ($pendingSearch !== ''): ?>
                            <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'pending', 'pending_search' => '', 'pending_page' => 1])); ?>" class="btn-outline ra-search-btn" data-frs-partial="ra-approvals-main">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <?php if (empty($pendingReservations)): ?>
            <div class="ra-empty-panel">
                <p>No reservations awaiting approval<?= $pendingSearch !== '' ? ' matching your search' : ''; ?>.</p>
            </div>
        <?php else: ?>
            <?php
            $pendingReviewData = [];
            foreach ($pendingReservations as $reservation) {
                if (!in_array($reservation['status'], ['pending', 'postponed'], true)) {
                    continue;
                }
                $submittedAt = !empty($reservation['created_at'])
                    ? date('M j, Y g:i A', strtotime((string)$reservation['created_at']))
                    : '—';
                $scheduleDate = !empty($reservation['reservation_date'])
                    ? date('l, F j, Y', strtotime((string)$reservation['reservation_date']))
                    : '—';
                $attendees = isset($reservation['expected_attendees']) && $reservation['expected_attendees'] !== null && $reservation['expected_attendees'] !== ''
                    ? (int)$reservation['expected_attendees']
                    : null;
                $capacityThreshold = isset($reservation['capacity_threshold']) ? (int)$reservation['capacity_threshold'] : null;
                $pendingReviewData[(int)$reservation['id']] = [
                    'id' => (int)$reservation['id'],
                    'requester' => (string)$reservation['requester'],
                    'requester_email' => (string)($reservation['requester_email'] ?? ''),
                    'requester_mobile' => (string)($reservation['requester_mobile'] ?? ''),
                    'facility' => (string)$reservation['facility'],
                    'schedule_date' => $scheduleDate,
                    'time_slot' => (string)$reservation['time_slot'],
                    'purpose' => (string)$reservation['purpose'],
                    'expected_attendees' => $attendees,
                    'capacity_threshold' => $capacityThreshold,
                    'is_commercial' => !empty($reservation['is_commercial']),
                    'status' => (string)$reservation['status'],
                    'postponed_priority' => !empty($reservation['postponed_priority']),
                    'submitted_at' => $submittedAt,
                    'detail_url' => base_path() . '/dashboard/reservation-detail?id=' . (int)$reservation['id'],
                ];
            }
            ?>
            <div class="ra-table-scroll">
                <table class="ra-queue-table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Requester</th>
                            <th>Schedule</th>
                            <th>Purpose</th>
                            <th class="ra-col-narrow">Guests</th>
                            <th class="ra-col-narrow">Status</th>
                            <th class="ra-col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingReservations as $reservation): ?>
                            <?php
                            $canReview = in_array($reservation['status'], ['pending', 'postponed'], true);
                            $isPriorityPostponed = $reservation['status'] === 'postponed' && !empty($reservation['postponed_priority']);
                            $scheduleLabel = !empty($reservation['reservation_date'])
                                ? date('M j, Y', strtotime((string)$reservation['reservation_date']))
                                : '—';
                            $submittedLabel = !empty($reservation['created_at'])
                                ? date('M j, g:i A', strtotime((string)$reservation['created_at']))
                                : '';
                            $attendeesLabel = isset($reservation['expected_attendees']) && $reservation['expected_attendees'] !== null && $reservation['expected_attendees'] !== ''
                                ? (int)$reservation['expected_attendees']
                                : null;
                            $purposeRaw = (string)$reservation['purpose'];
                            $purposeShort = mb_strlen($purposeRaw) > 72
                                ? mb_substr($purposeRaw, 0, 72) . '…'
                                : $purposeRaw;
                            $statusLabel = $reservation['status'] === 'pending_payment'
                                ? 'Awaiting Payment'
                                : ucfirst((string)$reservation['status']);
                            ?>
                            <tr class="ra-queue-row<?= !empty($reservation['postponed_priority']) ? ' ra-queue-row--priority' : ''; ?>">
                                <td data-label="Facility">
                                    <span class="ra-cell-primary"><?= htmlspecialchars((string)$reservation['facility']); ?></span>
                                    <?php if ($reservation['status'] === 'postponed' && !empty($reservation['postponed_priority'])): ?>
                                        <span class="ra-priority-badge">Priority</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Requester">
                                    <span class="ra-cell-primary"><?= htmlspecialchars((string)$reservation['requester']); ?></span>
                                    <?php if ($submittedLabel !== ''): ?>
                                        <span class="ra-cell-meta">Submitted <?= htmlspecialchars($submittedLabel); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Schedule">
                                    <span class="ra-cell-primary"><?= htmlspecialchars($scheduleLabel); ?></span>
                                    <span class="ra-cell-meta"><?= htmlspecialchars((string)$reservation['time_slot']); ?></span>
                                </td>
                                <td data-label="Purpose">
                                    <span class="ra-cell-purpose" title="<?= htmlspecialchars($purposeRaw); ?>"><?= htmlspecialchars($purposeShort); ?></span>
                                </td>
                                <td data-label="Guests" class="ra-col-narrow">
                                    <?= $attendeesLabel !== null ? $attendeesLabel : '—'; ?>
                                </td>
                                <td data-label="Status" class="ra-col-narrow">
                                    <span class="status-badge status-badge--cell <?= htmlspecialchars((string)$reservation['status']); ?>"><?= htmlspecialchars($statusLabel); ?></span>
                                </td>
                                <td data-label="Actions" class="ra-col-actions">
                                    <div class="ra-action-group">
                                        <a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= (int)$reservation['id']; ?>" class="btn-outline ra-action-btn" title="Open full details">Details</a>
                                        <?php if ($isPriorityPostponed): ?>
                                            <button
                                                type="button"
                                                class="btn-primary ra-action-btn"
                                                data-staff-reschedule
                                                data-id="<?= (int)$reservation['id']; ?>"
                                                data-facility-id="<?= (int)($reservation['facility_id'] ?? 0); ?>"
                                                data-date="<?= htmlspecialchars((string)$reservation['reservation_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-time="<?= htmlspecialchars((string)$reservation['time_slot'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-facility="<?= htmlspecialchars((string)$reservation['facility'], ENT_QUOTES, 'UTF-8'); ?>"
                                                title="Pick a new date and auto-approve">Reschedule</button>
                                            <button type="button" class="btn-outline ra-action-btn ra-action-btn--deny" data-review-action="denied" data-review-id="<?= (int)$reservation['id']; ?>">Deny</button>
                                        <?php elseif ($canReview): ?>
                                            <button type="button" class="btn-primary ra-action-btn" data-review-action="approved" data-review-id="<?= (int)$reservation['id']; ?>">Approve</button>
                                            <button type="button" class="btn-outline ra-action-btn ra-action-btn--deny" data-review-action="denied" data-review-id="<?= (int)$reservation['id']; ?>">Deny</button>
                                        <?php elseif ($reservation['status'] === 'pending_payment'): ?>
                                            <span class="ra-payment-note">Awaiting payment</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($pendingReviewData)): ?>
                <script type="application/json" id="pendingReviewData"><?= json_encode($pendingReviewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
            <?php endif; ?>
            <?php if ($pendingTotalPages > 1): ?>
                <div class="ra-pagination">
                    <?php if ($pendingPage > 1): ?>
                        <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'pending', 'pending_page' => $pendingPage - 1])); ?>" class="ra-page-btn" data-frs-partial="ra-approvals-main">&larr; Prev</a>
                    <?php endif; ?>
                    <span class="ra-page-info">Page <?= $pendingPage; ?> of <?= $pendingTotalPages; ?> &middot; <?= $pendingTotal; ?> requests</span>
                    <?php if ($pendingPage < $pendingTotalPages): ?>
                        <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'pending', 'pending_page' => $pendingPage + 1])); ?>" class="ra-page-btn" data-frs-partial="ra-approvals-main">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="ra-view-panel" id="ra-panel-approved">
    <div class="ra-queue-header">
        <div class="ra-queue-header__title-row">
            <h2 class="ra-queue-title">Approved Reservations</h2>
            <span class="ra-queue-count"><?= (int)$approvedTotal; ?> upcoming</span>
        </div>
        <p class="ra-queue-subtitle">Modify, postpone, or cancel future approved bookings when schedules change.</p>
    </div>
    <form method="GET" class="ra-search-form ra-search-form--standalone" data-frs-partial="ra-approvals-main">
        <input type="hidden" name="view" value="approved">
        <input type="hidden" name="pending_page" value="<?= $pendingPage; ?>">
        <input type="hidden" name="pending_search" value="<?= htmlspecialchars($pendingSearch); ?>">
        <input type="hidden" name="pending_filter" value="<?= htmlspecialchars($pendingFilter); ?>">
        <input type="hidden" name="pending_sort" value="<?= htmlspecialchars($pendingSort); ?>">
        <input type="hidden" name="approved_sort" value="<?= htmlspecialchars($approvedSort); ?>">
        <input type="hidden" name="page" value="<?= $page; ?>">
        <input type="hidden" name="approved_page" value="1">
        <div class="ra-toolbar-controls">
            <div class="ra-sort-menu" data-ra-sort-menu>
                <button type="button"
                        class="ra-sort-trigger<?= $approvedSort !== 'schedule_asc' ? ' is-active' : ''; ?>"
                        aria-expanded="false"
                        aria-haspopup="listbox"
                        aria-label="Sort approved reservations"
                        title="Sort: <?= htmlspecialchars($allowedApprovedSorts[$approvedSort]); ?>">
                    <i class="bi bi-sort-down" aria-hidden="true"></i>
                </button>
                <div class="ra-sort-panel" role="listbox" hidden>
                    <div class="ra-sort-panel__title">Sort by</div>
                    <?php foreach ($allowedApprovedSorts as $sortKey => $sortLabel): ?>
                        <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'approved', 'approved_sort' => $sortKey, 'approved_page' => 1])); ?>"
                           class="ra-sort-option<?= $approvedSort === $sortKey ? ' is-selected' : ''; ?>"
                           role="option"
                           aria-selected="<?= $approvedSort === $sortKey ? 'true' : 'false'; ?>"
                           data-frs-partial="ra-approvals-main">
                            <?= htmlspecialchars($sortLabel); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ra-search-field">
                <input type="search" name="approved_search" value="<?= htmlspecialchars($approvedSearch); ?>" placeholder="Search requester, facility, purpose, email…" class="ra-search-input" aria-label="Search approved reservations">
                <button type="submit" class="btn-primary ra-search-btn">Search</button>
                <?php if ($approvedSearch !== ''): ?>
                    <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'approved', 'approved_search' => '', 'approved_page' => 1])); ?>" class="btn-outline ra-search-btn" data-frs-partial="ra-approvals-main">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    
    <?php if (empty($approvedReservations)): ?>
        <div class="ra-empty-panel">
            <p>No approved reservations at this time<?= $approvedSearch !== '' ? ' matching your search' : ''; ?>.</p>
        </div>
    <?php else: ?>
        <div class="ra-table-scroll">
            <table class="ra-queue-table ra-queue-table--approved">
                <thead>
                    <tr>
                        <th>Requester</th>
                        <th>Facility</th>
                        <th>Schedule</th>
                        <th>Purpose</th>
                        <th class="ra-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvedReservations as $reservation): ?>
                        <?php
                        $approvedPurpose = (string)($reservation['purpose'] ?? '');
                        $approvedPurposeShort = mb_strlen($approvedPurpose) > 72
                            ? mb_substr($approvedPurpose, 0, 72) . '…'
                            : $approvedPurpose;
                        $approvedDateLabel = !empty($reservation['reservation_date'])
                            ? date('M j, Y', strtotime((string)$reservation['reservation_date']))
                            : (string)$reservation['reservation_date'];
                        ?>
                        <tr class="ra-queue-row">
                            <td data-label="Requester">
                                <span class="ra-cell-primary"><?= htmlspecialchars((string)$reservation['requester']); ?></span>
                                <span class="ra-cell-meta"><?= htmlspecialchars((string)$reservation['requester_email']); ?></span>
                            </td>
                            <td data-label="Facility">
                                <span class="ra-cell-primary"><?= htmlspecialchars((string)$reservation['facility']); ?></span>
                            </td>
                            <td data-label="Schedule">
                                <span class="ra-cell-primary"><?= htmlspecialchars($approvedDateLabel); ?></span>
                                <span class="ra-cell-meta"><?= htmlspecialchars((string)$reservation['time_slot']); ?></span>
                            </td>
                            <td data-label="Purpose">
                                <span class="ra-cell-purpose" title="<?= htmlspecialchars($approvedPurpose); ?>"><?= htmlspecialchars($approvedPurposeShort); ?></span>
                            </td>
                            <td data-label="Actions" class="ra-col-actions">
                                <div class="ra-action-group">
                                    <a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= (int)$reservation['id']; ?>" class="btn-outline ra-action-btn">Details</a>
                                    <button type="button" class="btn-outline ra-action-btn" onclick="openModifyModal(this)" data-id="<?= (int)$reservation['id']; ?>" data-facility-id="<?= (int)$reservation['facility_id']; ?>" data-date="<?= htmlspecialchars((string)$reservation['reservation_date']); ?>" data-time="<?= htmlspecialchars((string)$reservation['time_slot'], ENT_QUOTES, 'UTF-8'); ?>" data-facility="<?= htmlspecialchars((string)$reservation['facility'], ENT_QUOTES, 'UTF-8'); ?>" data-purpose="<?= htmlspecialchars($approvedPurpose, ENT_QUOTES, 'UTF-8'); ?>" data-attendees="<?= htmlspecialchars((string)($reservation['expected_attendees'] ?? '')); ?>">Modify</button>
                                    <button type="button" class="btn-outline ra-action-btn" onclick="openPostponeModal(<?= (int)$reservation['id']; ?>, '<?= htmlspecialchars((string)$reservation['reservation_date']); ?>', '<?= htmlspecialchars((string)$reservation['time_slot']); ?>', '<?= htmlspecialchars((string)$reservation['facility']); ?>')">Postpone</button>
                                    <button type="button" class="btn-outline ra-action-btn ra-action-btn--deny" onclick="openCancelModal(<?= (int)$reservation['id']; ?>, '<?= htmlspecialchars((string)$reservation['facility']); ?>', '<?= htmlspecialchars((string)$reservation['reservation_date']); ?>', '<?= htmlspecialchars((string)$reservation['time_slot']); ?>')">Cancel</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($approvedTotalPages > 1): ?>
            <div class="ra-pagination">
                <?php if ($approvedPage > 1): ?>
                    <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'approved', 'approved_page' => $approvedPage - 1])); ?>" class="ra-page-btn" data-frs-partial="ra-approvals-main">&larr; Prev</a>
                <?php endif; ?>
                <span class="ra-page-info">Page <?= $approvedPage; ?> of <?= $approvedTotalPages; ?> &middot; <?= $approvedTotal; ?> reservations</span>
                <?php if ($approvedPage < $approvedTotalPages): ?>
                    <a href="<?= htmlspecialchars($raBuildPendingQuery(['view' => 'approved', 'approved_page' => $approvedPage + 1])); ?>" class="ra-page-btn" data-frs-partial="ra-approvals-main">Next &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
    </section>
</div>

<!-- Review & Decide Modal (mandatory before approve/deny) -->
<div id="reviewDecisionModal" class="ra-review-modal" aria-hidden="true">
    <div class="ra-review-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="reviewDecisionTitle">
        <div class="ra-review-modal__header">
            <div>
                <p class="ra-review-modal__eyebrow" id="reviewDecisionEyebrow">Review reservation</p>
                <h3 id="reviewDecisionTitle">Reservation details</h3>
            </div>
            <button type="button" class="ra-review-modal__close" data-close-review-modal aria-label="Close">&times;</button>
        </div>

        <div class="ra-review-modal__body">
            <div class="ra-review-banner" id="reviewDecisionBanner"></div>
            <div class="ra-review-grid" id="reviewDecisionGrid"></div>

            <div class="ra-review-purpose">
                <span class="ra-detail-label">Purpose / event description</span>
                <p id="reviewDecisionPurpose" class="ra-review-purpose__text"></p>
            </div>

            <form method="POST" id="reviewDecisionForm"
                  data-frs-ajax
                  data-frs-ajax-target="ra-approvals-main"
                  data-frs-ajax-close="#reviewDecisionModal"
                  action="<?= htmlspecialchars(base_path() . '/dashboard/reservations-manage', ENT_QUOTES, 'UTF-8'); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="view" value="pending">
                <input type="hidden" name="reservation_id" id="review_reservation_id" value="">
                <input type="hidden" name="action" id="review_action" value="">
                <label class="ra-review-note-label" for="review_note">Staff remarks <span id="reviewNoteRequiredMark" class="ra-required-mark" hidden>(required for denial)</span></label>
                <textarea name="note" id="review_note" rows="3" placeholder="Optional notes for approval, or explain why the request is denied." class="ra-review-note-input"></textarea>
            </form>
        </div>

        <div class="ra-review-modal__footer">
            <button type="button" class="btn-outline" data-close-review-modal>Cancel</button>
            <a href="#" id="reviewOpenFullDetails" class="btn-outline" target="_blank" rel="noopener">Open full page</a>
            <button type="submit" form="reviewDecisionForm" id="reviewConfirmBtn" class="btn-primary">Confirm</button>
        </div>
    </div>
</div>

<!-- Modify Modal -->
<div id="modifyModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Modify Approved Reservation</h3>
            <button onclick="closeModifyModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="modifyForm"
              data-frs-ajax
              data-frs-ajax-target="ra-approvals-main"
              data-frs-ajax-close="#modifyModal">
            <?= csrf_field(); ?>
            <input type="hidden" name="view" value="approved">
            <input type="hidden" name="reservation_id" id="modify_reservation_id">
            <input type="hidden" name="action" value="modify">
            <input type="hidden" id="modify_facility_id" value="">
            
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
        <form method="POST" id="postponeForm"
              data-frs-ajax
              data-frs-ajax-target="ra-approvals-main"
              data-frs-ajax-close="#postponeModal">
            <?= csrf_field(); ?>
            <input type="hidden" name="view" value="approved">
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
                <button type="submit" class="btn-primary confirm-action" data-message="Postpone this approved reservation? It will be set to postponed status with priority and require re-approval." style="flex: 1;">Postpone Reservation</button>
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
        <form method="POST" id="cancelForm"
              data-frs-ajax
              data-frs-ajax-target="ra-approvals-main"
              data-frs-ajax-close="#cancelModal">
            <?= csrf_field(); ?>
            <input type="hidden" name="view" value="approved">
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

<!-- Staff Reschedule Modal (postponed + priority) -->
<div id="staffRescheduleModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Reschedule Priority Reservation</h3>
            <button type="button" onclick="closeStaffRescheduleModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="staffRescheduleForm"
              data-frs-ajax
              data-frs-ajax-target="ra-approvals-main"
              data-frs-ajax-close="#staffRescheduleModal">
            <?= csrf_field(); ?>
            <input type="hidden" name="view" value="pending">
            <input type="hidden" name="reservation_id" id="staff_reschedule_reservation_id">
            <input type="hidden" name="action" value="staff_reschedule">
            <input type="hidden" id="staff_reschedule_facility_id" value="">

            <div style="margin-bottom: 1rem; padding: 1rem; background: #eff6ff; border-radius: 6px; border-left: 4px solid #2563eb;">
                <strong>Priority reschedule:</strong> Pick an available date. Confirming will <strong>auto-approve</strong> the booking.
                Payment is skipped if the facility is free or already paid.
            </div>

            <div style="margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                <strong>Current (postponed) schedule:</strong><br>
                <span id="staff_reschedule_current_schedule"></span>
            </div>

            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Date <span style="color: #dc3545;">*</span>
            </label>
            <input type="date" name="new_date" id="staff_reschedule_new_date" required min="<?= date('Y-m-d'); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">

            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Start Time <span style="color: #dc3545;">*</span>
            </label>
            <input type="time" name="start_time" id="staff_reschedule_start_time" required min="08:00" max="21:00" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            <small style="color: #8b95b5; font-size: 0.85rem; display: block; margin-top: -0.75rem; margin-bottom: 1rem;">Facility operating hours: 8:00 AM - 9:00 PM</small>

            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                End Time <span style="color: #dc3545;">*</span>
            </label>
            <input type="time" name="end_time" id="staff_reschedule_end_time" required min="08:00" max="21:00" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">

            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason / note to requester <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="staff_reschedule_reason" required placeholder="e.g. Original date under CIMM maintenance; moved to next available slot. We apologize for the inconvenience." style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>

            <div id="staff-reschedule-conflict-warning" style="display:none; border-radius:8px; padding:1rem; margin-top:1rem; transition: all 0.3s ease;">
                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                    <span id="staff-reschedule-conflict-icon" style="font-size:1.2rem;">⏳</span>
                    <h4 id="staff-reschedule-conflict-title" style="margin:0; font-size:0.95rem;">Checking Availability...</h4>
                </div>
                <p id="staff-reschedule-conflict-message" style="margin:0 0 0.75rem; font-size:0.85rem;"></p>
                <div id="staff-reschedule-conflict-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; font-size:0.85rem; font-weight:600;">Alternative time slots:</p>
                    <ul id="staff-reschedule-alternatives-list" style="margin:0; padding-left:1.25rem; font-size:0.85rem;"></ul>
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeStaffRescheduleModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Reschedule and auto-approve this priority reservation?" style="flex: 1;">Reschedule &amp; Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function closeAllRaSortMenus(exceptMenu) {
        document.querySelectorAll('[data-ra-sort-menu]').forEach(function (menu) {
            if (exceptMenu && menu === exceptMenu) return;
            var btn = menu.querySelector('.ra-sort-trigger');
            var panel = menu.querySelector('.ra-sort-panel');
            if (btn) btn.setAttribute('aria-expanded', 'false');
            if (panel) panel.hidden = true;
        });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.ra-sort-trigger');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            var menu = trigger.closest('[data-ra-sort-menu]');
            var panel = menu ? menu.querySelector('.ra-sort-panel') : null;
            var isOpen = trigger.getAttribute('aria-expanded') === 'true';
            closeAllRaSortMenus();
            if (!isOpen && panel) {
                trigger.setAttribute('aria-expanded', 'true');
                panel.hidden = false;
            }
            return;
        }
        if (!e.target.closest('[data-ra-sort-menu]')) {
            closeAllRaSortMenus();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllRaSortMenus();
    });
})();

let modifyConflictCheckTimeout = null;

function openModifyModal(btn) {
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

['modify_new_date', 'modify_start_time', 'modify_end_time'].forEach(function(id) {
    const el = document.getElementById(id);
    if (!el || el.dataset.frsConflictBound === '1') return;
    el.dataset.frsConflictBound = '1';
    el.addEventListener('change', debounceModifyConflict);
});

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

let staffRescheduleConflictTimeout = null;

function openStaffRescheduleModal(btn) {
    const id = btn.getAttribute('data-id');
    const facilityId = btn.getAttribute('data-facility-id') || '';
    const date = btn.getAttribute('data-date') || '';
    const time = btn.getAttribute('data-time') || '';
    const facility = btn.getAttribute('data-facility') || '';

    document.getElementById('staff_reschedule_reservation_id').value = id;
    document.getElementById('staff_reschedule_facility_id').value = facilityId;
    document.getElementById('staff_reschedule_current_schedule').textContent = facility + ' on ' + date + ' (' + time + ')';
    document.getElementById('staff_reschedule_new_date').value = '';
    document.getElementById('staff_reschedule_start_time').value = '';
    document.getElementById('staff_reschedule_end_time').value = '';
    document.getElementById('staff_reschedule_reason').value = 'Original schedule was postponed (facility unavailable / under maintenance). We apologize for the inconvenience.';

    const timeMatch = time.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
    if (timeMatch) {
        document.getElementById('staff_reschedule_start_time').value = timeMatch[1].padStart(2, '0') + ':' + timeMatch[2];
        document.getElementById('staff_reschedule_end_time').value = timeMatch[3].padStart(2, '0') + ':' + timeMatch[4];
    }

    const modal = document.getElementById('staffRescheduleModal');
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeStaffRescheduleModal() {
    const modal = document.getElementById('staffRescheduleModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
}

function debounceStaffRescheduleConflict() {
    if (staffRescheduleConflictTimeout) clearTimeout(staffRescheduleConflictTimeout);
    staffRescheduleConflictTimeout = setTimeout(checkStaffRescheduleConflict, 500);
}

async function checkStaffRescheduleConflict() {
    staffRescheduleConflictTimeout = null;
    const fid = document.getElementById('staff_reschedule_facility_id')?.value;
    const date = document.getElementById('staff_reschedule_new_date')?.value;
    const startTime = document.getElementById('staff_reschedule_start_time')?.value;
    const endTime = document.getElementById('staff_reschedule_end_time')?.value;
    const excludeId = document.getElementById('staff_reschedule_reservation_id')?.value;
    const msgBox = document.getElementById('staff-reschedule-conflict-warning');
    const msgText = document.getElementById('staff-reschedule-conflict-message');
    const altWrap = document.getElementById('staff-reschedule-conflict-alternatives');
    const altList = document.getElementById('staff-reschedule-alternatives-list');
    const conflictIcon = document.getElementById('staff-reschedule-conflict-icon');
    const conflictTitle = document.getElementById('staff-reschedule-conflict-title');

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
    if (msgText) { msgText.style.color = '#4f46e5'; msgText.textContent = 'Checking availability and conflicts...'; }
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
            if (msgText) { msgText.style.color = '#b23030'; msgText.textContent = data.message || 'This slot is already booked. Please select another time.'; }
            if (conflictIcon) conflictIcon.textContent = '✗';
            if (conflictTitle) { conflictTitle.textContent = 'Conflict Detected'; conflictTitle.style.color = '#b23030'; }
        } else if (data.soft_conflicts && data.soft_conflicts.length > 0) {
            const cnt = data.pending_count || data.soft_conflicts.length;
            msgBox.style.background = '#fff4e5';
            msgBox.style.border = '2px solid #ffc107';
            if (msgText) { msgText.style.color = '#856404'; msgText.textContent = 'Warning: ' + cnt + ' pending reservation(s) exist for this slot.'; }
            if (conflictIcon) conflictIcon.textContent = '⚠️';
            if (conflictTitle) { conflictTitle.textContent = 'Warning - Pending Reservations'; conflictTitle.style.color = '#856404'; }
        } else {
            msgBox.style.background = '#e8f5e9';
            msgBox.style.border = '2px solid #0d7a43';
            if (msgText) { msgText.style.color = '#0d7a43'; msgText.textContent = '✓ This time slot is available for reschedule.'; }
            if (conflictIcon) conflictIcon.textContent = '✓';
            if (conflictTitle) { conflictTitle.textContent = 'Available'; conflictTitle.style.color = '#0d7a43'; }
        }
        if (data.alternatives && data.alternatives.length && altWrap && altList) {
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

['staff_reschedule_new_date', 'staff_reschedule_start_time', 'staff_reschedule_end_time'].forEach(function(id) {
    const el = document.getElementById(id);
    if (!el || el.dataset.frsConflictBound === '1') return;
    el.dataset.frsConflictBound = '1';
    el.addEventListener('change', debounceStaffRescheduleConflict);
});

if (!window.__raStaffRescheduleDelegationBound) {
    window.__raStaffRescheduleDelegationBound = true;
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-staff-reschedule]');
        if (btn) {
            e.preventDefault();
            openStaffRescheduleModal(btn);
        }
    });
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
validateTimeInputs('staff_reschedule_start_time', 'staff_reschedule_end_time');

// Close modals when clicking outside
['modifyModal', 'postponeModal', 'cancelModal', 'staffRescheduleModal'].forEach(function(id) {
    const el = document.getElementById(id);
    if (!el || el.dataset.frsOutsideBound === '1') return;
    el.dataset.frsOutsideBound = '1';
    el.addEventListener('click', function(e) {
        if (e.target !== this) return;
        if (id === 'modifyModal') closeModifyModal();
        else if (id === 'postponeModal') closePostponeModal();
        else if (id === 'cancelModal') closeCancelModal();
        else if (id === 'staffRescheduleModal') closeStaffRescheduleModal();
    });
});

window.openModifyModal = openModifyModal;
window.closeModifyModal = closeModifyModal;
window.openPostponeModal = openPostponeModal;
window.closePostponeModal = closePostponeModal;
window.closeCancelModal = closeCancelModal;
window.openCancelModal = openCancelModal;
window.openStaffRescheduleModal = openStaffRescheduleModal;
window.closeStaffRescheduleModal = closeStaffRescheduleModal;
</script>

<script>
(function() {
    function getReviewModal() {
        return document.getElementById('reviewDecisionModal');
    }

    function readReviewData() {
        const el = document.getElementById('pendingReviewData');
        if (!el || !el.textContent) return {};
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (e) {
            return {};
        }
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function detailItem(label, value) {
        return '<div class="ra-detail-item"><span class="ra-detail-label">' + escapeHtml(label) + '</span><span class="ra-detail-value">' + escapeHtml(value || '—') + '</span></div>';
    }

    function closeReviewModal() {
        const modal = getReviewModal();
        if (!modal) return;
        const form = document.getElementById('reviewDecisionForm');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modal.style.display = 'none';
        document.body.style.overflow = '';
        if (form) form.reset();
        const bannerEl = document.getElementById('reviewDecisionBanner');
        const gridEl = document.getElementById('reviewDecisionGrid');
        const purposeEl = document.getElementById('reviewDecisionPurpose');
        if (bannerEl) bannerEl.innerHTML = '';
        if (gridEl) gridEl.innerHTML = '';
        if (purposeEl) purposeEl.textContent = '';
    }

    function openReviewModal(action, reservationId) {
        const modal = getReviewModal();
        if (!modal) return;
        const reviewData = readReviewData();
        const data = reviewData[String(reservationId)] || reviewData[reservationId];
        if (!data) return;

        const form = document.getElementById('reviewDecisionForm');
        const titleEl = document.getElementById('reviewDecisionTitle');
        const eyebrowEl = document.getElementById('reviewDecisionEyebrow');
        const bannerEl = document.getElementById('reviewDecisionBanner');
        const gridEl = document.getElementById('reviewDecisionGrid');
        const purposeEl = document.getElementById('reviewDecisionPurpose');
        const confirmBtn = document.getElementById('reviewConfirmBtn');
        const noteInput = document.getElementById('review_note');
        const noteRequiredMark = document.getElementById('reviewNoteRequiredMark');
        const fullDetailsLink = document.getElementById('reviewOpenFullDetails');
        const reservationIdInput = document.getElementById('review_reservation_id');
        const actionInput = document.getElementById('review_action');
        if (!reservationIdInput || !actionInput || !titleEl || !confirmBtn || !noteInput) return;

        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        reservationIdInput.value = String(data.id);
        actionInput.value = action;

        const isApprove = action === 'approved';
        if (eyebrowEl) eyebrowEl.textContent = isApprove ? 'Review before approval' : 'Review before denial';
        titleEl.textContent = 'Reservation #' + data.id + ' — ' + data.facility;
        confirmBtn.textContent = isApprove ? 'Confirm approval' : 'Confirm denial';
        confirmBtn.className = isApprove ? 'btn-primary' : 'btn-outline ra-btn-danger';
        if (noteRequiredMark) noteRequiredMark.hidden = isApprove;
        noteInput.placeholder = isApprove
            ? 'Optional remarks for the requester (e.g., setup instructions).'
            : 'Explain why this request is being denied.';
        noteInput.required = !isApprove;
        if (fullDetailsLink) fullDetailsLink.href = data.detail_url || '#';

        let bannerHtml = '';
        if (data.postponed_priority) {
            bannerHtml += '<div class="ra-review-alert ra-review-alert--info">This reservation has <strong>priority</strong> due to a previous postponement.</div>';
        }
        if (data.expected_attendees !== null && data.capacity_threshold && data.expected_attendees > data.capacity_threshold) {
            bannerHtml += '<div class="ra-review-alert ra-review-alert--warn">Expected attendees (' + data.expected_attendees + ') exceed the facility threshold (' + data.capacity_threshold + ').</div>';
        }
        if (bannerEl) bannerEl.innerHTML = bannerHtml;

        if (gridEl) {
            gridEl.innerHTML = [
                detailItem('Requester', data.requester),
                detailItem('Email', data.requester_email || '—'),
                detailItem('Mobile', data.requester_mobile || '—'),
                detailItem('Facility', data.facility),
                detailItem('Date', data.schedule_date),
                detailItem('Time', data.time_slot),
                detailItem('Attendees', data.expected_attendees !== null ? String(data.expected_attendees) : 'Not specified'),
                detailItem('Commercial use', data.is_commercial ? 'Yes' : 'No'),
                detailItem('Status', data.status.charAt(0).toUpperCase() + data.status.slice(1)),
                detailItem('Submitted', data.submitted_at),
            ].join('');
        }

        if (purposeEl) purposeEl.textContent = data.purpose || '—';

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        noteInput.focus();
    }

    window.openReviewModal = openReviewModal;
    window.closeReviewModal = closeReviewModal;

    const modalBoot = getReviewModal();
    if (modalBoot && modalBoot.parentNode !== document.body) {
        document.body.appendChild(modalBoot);
    }

    if (!window.__raReviewDelegationBound) {
        window.__raReviewDelegationBound = true;
        document.addEventListener('click', function(e) {
            const trigger = e.target.closest('[data-review-action][data-review-id]');
            if (trigger) {
                e.preventDefault();
                openReviewModal(trigger.getAttribute('data-review-action'), trigger.getAttribute('data-review-id'));
                return;
            }
            const modal = getReviewModal();
            if (modal && (e.target.closest('[data-close-review-modal]') || e.target === modal)) {
                e.preventDefault();
                closeReviewModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            const modal = getReviewModal();
            if (e.key === 'Escape' && modal && modal.classList.contains('show')) {
                closeReviewModal();
            }
        });

        document.addEventListener('submit', function(e) {
            const form = e.target.closest('#reviewDecisionForm');
            if (!form) return;
            const actionInput = document.getElementById('review_action');
            const noteInput = document.getElementById('review_note');
            if (!actionInput || !noteInput) return;
            if (actionInput.value === 'denied' && !noteInput.value.trim()) {
                e.preventDefault();
                noteInput.focus();
                noteInput.classList.add('ra-input-error');
                return;
            }
            noteInput.classList.remove('ra-input-error');
        });

        // data-frs-ajax-close only hides #reviewDecisionModal and restores body
        // overflow; the page's own closeReviewModal() also clears aria-hidden,
        // resets the form (note textarea isn't re-cleared by openReviewModal),
        // and wipes the banner/grid/purpose markup. Run the full close routine
        // whenever this region re-renders while the modal is open so an AJAX
        // approve/deny leaves no residue for the next open.
        document.addEventListener('frs:partial-loaded', function(e) {
            if (!e.detail || e.detail.id !== 'ra-approvals-main') return;
            const modal = getReviewModal();
            if (modal && modal.classList.contains('show')) {
                closeReviewModal();
            }
        });
    }
})();
</script>

<!-- All Reservations Modal (only opens when user clicks "All Reservations" button) -->
<div id="allReservationsModal" class="modal-overlay frs-all-reservations-modal" data-all-reservations-modal style="display: none;">
    <div class="modal-content frs-all-reservations-modal__panel">
        <div class="modal-header frs-all-reservations-modal__header">
            <h2>All Reservations</h2>
            <button type="button" data-close-all-reservations-modal style="background: none; border: none; font-size: 1.5rem; color: #6c757d; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: all 0.2s ease;" onmouseover="this.style.background='#f0f0f0'; this.style.color='#333';" onmouseout="this.style.background='none'; this.style.color='#6c757d';">&times;</button>
        </div>
        
        <!-- Search and Filter Form -->
        <form method="GET" id="modalSearchForm" class="frs-all-reservations-modal__search">
            <div class="frs-all-reservations-modal__search-grid" style="display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: end;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151;">Search Reservations</label>
                    <input type="text" name="modal_search" value="<?= htmlspecialchars($modalSearch); ?>" placeholder="Search by ID, requester name, email, or facility..." style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; font-size: 0.95rem; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #374151;">Status Filter</label>
                    <select name="modal_status" style="padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; font-size: 0.95rem; min-width: 150px; max-width: 100%; box-sizing: border-box;">
                        <option value="">All Statuses</option>
                        <option value="approved" <?= $modalStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="denied" <?= $modalStatus === 'denied' ? 'selected' : ''; ?>>Denied</option>
                        <option value="cancelled" <?= $modalStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="pending" <?= $modalStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="postponed" <?= $modalStatus === 'postponed' ? 'selected' : ''; ?>>Postponed</option>
                    </select>
                </div>
            </div>
            <div class="frs-all-reservations-modal__search-actions" style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                <button type="submit" class="btn-primary" style="padding: 0.75rem 1.5rem; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">Search</button>
                <?php if (!empty($modalSearch) || !empty($modalStatus)): ?>
                    <a href="?open_modal=all_reservations&pending_page=<?= $pendingPage; ?>&pending_search=<?= urlencode($pendingSearch); ?>&approved_page=<?= $approvedPage; ?>&approved_search=<?= urlencode($approvedSearch); ?>" class="btn-outline" style="padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 6px;">Clear Filters</a>
                <?php endif; ?>
            </div>
            <!-- Preserve other GET parameters -->
            <input type="hidden" name="pending_page" value="<?= $pendingPage; ?>">
            <input type="hidden" name="pending_search" value="<?= htmlspecialchars($pendingSearch); ?>">
            <input type="hidden" name="approved_page" value="<?= $approvedPage; ?>">
            <input type="hidden" name="approved_search" value="<?= htmlspecialchars($approvedSearch); ?>">
            <input type="hidden" name="open_modal" value="all_reservations">
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
                        $recordIsPast = frs_reservation_slot_has_passed((string)$record['reservation_date'], (string)$record['time_slot'])
                            && strtolower($record['status']) === 'approved';
                        $recordBadgeClass = $recordIsPast ? 'past' : $record['status'];
                        ?>
                        <article class="facility-card-admin" style="padding: 1rem; border: 1px solid #e5e7eb; border-radius: 8px;">
                            <header style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.75rem;">
                                <div>
                                    <h3 style="margin: 0 0 0.25rem 0; color: #1e3a5f;"><?= htmlspecialchars($record['facility']); ?></h3>
                                    <small style="color: #6b7280;"><?= htmlspecialchars($record['reservation_date']); ?> • <?= htmlspecialchars($record['time_slot']); ?></small>
                                </div>
                                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap: wrap;">
                                    <span class="status-badge <?= htmlspecialchars($recordBadgeClass); ?>"><?= $recordIsPast ? 'Past · ' : ''; ?><?= ucfirst($record['status']); ?></span>
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
                            <a href="?open_modal=all_reservations&modal_page=<?= $modalPage - 1; ?>&modal_search=<?= urlencode($modalSearch); ?>&modal_status=<?= urlencode($modalStatus); ?>&pending_page=<?= $pendingPage; ?>&pending_search=<?= urlencode($pendingSearch); ?>&approved_page=<?= $approvedPage; ?>&approved_search=<?= urlencode($approvedSearch); ?>" class="btn-outline" style="text-decoration: none; padding: 0.5rem 1rem;">&larr; Prev</a>
                        <?php endif; ?>
                        <span class="current" style="padding: 0.5rem 1rem;">Page <?= $modalPage; ?> of <?= $modalTotalPages; ?> (<?= $modalTotal; ?> total)</span>
                        <?php if ($modalPage < $modalTotalPages): ?>
                            <a href="?open_modal=all_reservations&modal_page=<?= $modalPage + 1; ?>&modal_search=<?= urlencode($modalSearch); ?>&modal_status=<?= urlencode($modalStatus); ?>&pending_page=<?= $pendingPage; ?>&pending_search=<?= urlencode($pendingSearch); ?>&approved_page=<?= $approvedPage; ?>&approved_search=<?= urlencode($approvedSearch); ?>" class="btn-outline" style="text-decoration: none; padding: 0.5rem 1rem;">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Reservation Approvals — pending cards & review modal */
.booking-wrapper.ra-approvals-layout {
    display: block !important;
    grid-template-columns: none !important;
    width: 100%;
    max-width: 100%;
}
.ra-approvals-layout {
    display: block !important;
}
.ra-approvals-main {
    width: 100% !important;
    max-width: 100%;
}
.ra-view-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    margin: 0 0 1.25rem;
    border-bottom: 2px solid #e2e8f0;
}
.ra-view-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.8rem 1.2rem;
    margin-bottom: -2px;
    border-bottom: 2px solid transparent;
    color: #64748b;
    font-size: 0.92rem;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
}
.ra-view-tab:hover {
    color: #1e3a5f;
    background: #f8fafc;
}
.ra-view-tab.is-active {
    color: #1e3a5f;
    border-bottom-color: #1e3a5f;
    background: #fff;
}
.ra-view-tab__count {
    display: inline-flex;
    min-width: 1.35rem;
    justify-content: center;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    background: #e2e8f0;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
}
.ra-view-tab.is-active .ra-view-tab__count {
    background: #dbeafe;
    color: #1e40af;
}
.ra-view-panel {
    width: 100%;
}
.ra-legend-details {
    margin-bottom: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f8fafc;
    padding: 0.55rem 0.85rem;
}
.ra-legend-summary {
    cursor: pointer;
    font-weight: 600;
    color: #475569;
    font-size: 0.9rem;
    list-style: none;
}
.ra-legend-summary::-webkit-details-marker {
    display: none;
}
.ra-legend-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem 0.85rem;
    margin-top: 0.65rem;
    padding-top: 0.65rem;
    border-top: 1px solid #e2e8f0;
}
.ra-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.82rem;
    color: #64748b;
}
.ra-legend-note {
    margin: 0.65rem 0 0;
    font-size: 0.8rem;
    color: #64748b;
    line-height: 1.45;
}
.ra-queue-header {
    margin-bottom: 1rem;
}
.ra-queue-header__title-row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    flex-wrap: wrap;
}
.ra-queue-title {
    margin: 0;
    font-size: 1.15rem;
    color: #1e3a5f;
    font-weight: 700;
}
.ra-queue-count {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
    font-size: 0.78rem;
    font-weight: 600;
}
.ra-queue-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.88rem;
    line-height: 1.45;
}
.ra-queue-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem 1.25rem;
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}
.ra-filter-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}
.ra-filter-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.75rem;
    border-radius: 8px;
    border: 1px solid #dbe3ef;
    background: #fff;
    color: #475569;
    font-size: 0.82rem;
    font-weight: 500;
    text-decoration: none;
    transition: border-color 0.15s, background 0.15s, color 0.15s;
}
.ra-filter-tab:hover {
    border-color: #94a3b8;
    color: #1e293b;
}
.ra-filter-tab.is-active {
    background: #1e3a5f;
    border-color: #1e3a5f;
    color: #fff;
}
.ra-filter-tab__count {
    display: inline-flex;
    min-width: 1.25rem;
    justify-content: center;
    padding: 0.05rem 0.35rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.08);
    font-size: 0.72rem;
    font-weight: 700;
}
.ra-filter-tab.is-active .ra-filter-tab__count {
    background: rgba(255, 255, 255, 0.2);
    color: #fff;
}
.ra-search-form {
    flex: 1;
    min-width: min(100%, 220px);
    max-width: 480px;
    margin: 0;
}
.ra-search-form--standalone {
    max-width: none;
    margin-bottom: 1rem;
}
.ra-toolbar-controls {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    justify-content: flex-end;
}
.ra-search-form--standalone .ra-toolbar-controls {
    justify-content: flex-start;
    flex-wrap: wrap;
}
.ra-sort-menu {
    position: relative;
    flex: 0 0 auto;
}
.ra-sort-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.35rem;
    height: 2.35rem;
    padding: 0;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    background: #fff;
    color: #475569;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s, color 0.15s;
}
.ra-sort-trigger:hover,
.ra-sort-trigger[aria-expanded="true"] {
    border-color: var(--gov-blue, #1d4ed8);
    color: var(--gov-blue-dark, #1e3a8a);
    background: #eff6ff;
}
.ra-sort-trigger.is-active {
    border-color: var(--gov-blue, #1d4ed8);
    color: var(--gov-blue-dark, #1e3a8a);
    background: #eff6ff;
}
.ra-sort-trigger i {
    font-size: 1.15rem;
    line-height: 1;
}
.ra-sort-panel {
    position: absolute;
    top: calc(100% + 0.35rem);
    right: 0;
    z-index: 40;
    min-width: 13.5rem;
    max-width: min(18rem, 80vw);
    padding: 0.4rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
}
.ra-sort-panel__title {
    padding: 0.35rem 0.65rem 0.45rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #94a3b8;
}
.ra-sort-option {
    display: block;
    padding: 0.5rem 0.65rem;
    border-radius: 7px;
    color: #334155;
    font-size: 0.85rem;
    text-decoration: none;
    line-height: 1.3;
}
.ra-sort-option:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.ra-sort-option.is-selected {
    background: #eff6ff;
    color: var(--gov-blue-dark, #1e3a8a);
    font-weight: 600;
}
.ra-search-field {
    display: flex;
    gap: 0.45rem;
    align-items: center;
    flex-wrap: nowrap;
    flex: 1 1 auto;
    min-width: 0;
}
.ra-search-input {
    flex: 1;
    min-width: 160px;
    padding: 0.5rem 0.7rem;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    font-size: 0.88rem;
    background: #fff;
}
.ra-search-input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}
.ra-search-btn {
    padding: 0.5rem 0.85rem !important;
    font-size: 0.85rem !important;
    white-space: nowrap;
    text-decoration: none;
}
.ra-empty-panel {
    padding: 2.5rem 1.5rem;
    text-align: center;
    color: #64748b;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
}
.ra-empty-panel p {
    margin: 0;
}
.ra-table-scroll {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #fff;
}
.ra-queue-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.ra-queue-table thead {
    background: #f1f5f9;
    position: sticky;
    top: 0;
    z-index: 1;
}
.ra-queue-table th {
    padding: 0.65rem 0.85rem;
    text-align: left;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.ra-queue-table td {
    padding: 0.75rem 0.85rem;
    vertical-align: top;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}
.ra-queue-row:hover td {
    background: #f8fafc;
}
.ra-queue-row--priority td:first-child {
    box-shadow: inset 3px 0 0 #1e40af;
}
.ra-cell-primary {
    display: block;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.35;
}
.ra-cell-meta {
    display: block;
    margin-top: 0.15rem;
    font-size: 0.78rem;
    color: #94a3b8;
    line-height: 1.35;
}
.ra-cell-purpose {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.4;
    color: #475569;
    max-width: 280px;
}
.ra-col-narrow {
    white-space: nowrap;
    width: 1%;
}
.ra-col-actions {
    width: 1%;
    min-width: 220px;
    white-space: nowrap;
}
@media (max-width: 900px) {
    .ra-col-actions,
    .ra-col-narrow {
        width: 100% !important;
        min-width: 0 !important;
        white-space: normal !important;
    }
}
.ra-action-group {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    gap: 0.35rem;
    align-items: center;
}
@media (min-width: 901px) {
    .ra-action-group {
        flex-wrap: nowrap;
    }
}
.ra-action-btn {
    padding: 0.35rem 0.65rem !important;
    font-size: 0.8rem !important;
    line-height: 1.2;
    white-space: nowrap;
    text-decoration: none;
}
.ra-action-btn--deny {
    color: #b91c1c !important;
    border-color: #fecaca !important;
}
.ra-action-btn--deny:hover {
    background: #fef2f2 !important;
}
.ra-priority-badge {
    display: inline-block;
    margin-left: 0.35rem;
    background: #1e40af;
    color: #fff;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    vertical-align: middle;
}
.ra-payment-note {
    font-size: 0.78rem;
    color: #9a3412;
    background: #ffedd5;
    border: 1px solid #fdba74;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    white-space: nowrap;
}
.ra-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 1rem;
    padding-top: 0.85rem;
    border-top: 1px solid #eef2f7;
}
.ra-page-btn {
    padding: 0.45rem 0.85rem;
    border-radius: 8px;
    border: 1px solid #dbe3ef;
    background: #fff;
    color: #334155;
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
}
.ra-page-btn:hover {
    border-color: #94a3b8;
    background: #f8fafc;
}
.ra-page-info {
    font-size: 0.85rem;
    color: #64748b;
}
.ra-detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}
.ra-detail-item--wide {
    grid-column: 1 / -1;
}
.ra-detail-label {
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: #94a3b8;
}
.ra-detail-value {
    color: #1f2937;
    font-size: 0.95rem;
    line-height: 1.45;
    word-break: break-word;
}
.ra-btn-danger {
    color: #b91c1c !important;
    border-color: #fecaca !important;
}
.ra-btn-danger:hover {
    background: #fef2f2 !important;
}
.ra-review-modal {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(4px);
}
.ra-review-modal.show {
    display: flex;
}
.ra-review-modal__dialog {
    width: min(720px, 100%);
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
}
.ra-review-modal__header,
.ra-review-modal__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.15rem 1.35rem;
    border-bottom: 1px solid #eef2f7;
}
.ra-review-modal__footer {
    border-bottom: none;
    border-top: 1px solid #eef2f7;
    justify-content: flex-end;
}
.ra-review-modal__eyebrow {
    margin: 0 0 0.2rem;
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}
.ra-review-modal__header h3 {
    margin: 0;
    color: #1e3a5f;
    font-size: 1.15rem;
}
.ra-review-modal__close {
    background: none;
    border: none;
    font-size: 1.6rem;
    line-height: 1;
    color: #94a3b8;
    cursor: pointer;
}
.ra-review-modal__body {
    padding: 1.15rem 1.35rem;
    overflow-y: auto;
}
.ra-review-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.9rem 1.25rem;
    margin-bottom: 1rem;
}
.ra-review-purpose {
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}
.ra-review-purpose__text {
    margin: 0.35rem 0 0;
    color: #1f2937;
    line-height: 1.55;
    white-space: pre-wrap;
}
.ra-review-alert {
    padding: 0.75rem 0.9rem;
    border-radius: 8px;
    margin-bottom: 0.75rem;
    font-size: 0.9rem;
    line-height: 1.45;
}
.ra-review-alert--info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
}
.ra-review-alert--warn {
    background: #fff7ed;
    border: 1px solid #fdba74;
    color: #9a3412;
}
.ra-review-note-label {
    display: block;
    margin-bottom: 0.4rem;
    font-weight: 500;
    color: #334155;
}
.ra-required-mark {
    color: #dc2626;
    font-weight: 600;
}
.ra-review-note-input {
    width: 100%;
    min-height: 88px;
    padding: 0.65rem 0.75rem;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    font-family: inherit;
    resize: vertical;
}
.ra-review-note-input.ra-input-error {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.12);
}
@media (max-width: 640px) {
    .ra-review-grid {
        grid-template-columns: 1fr;
    }
    .ra-review-modal__footer {
        flex-wrap: wrap;
    }
    .ra-review-modal__footer .btn-primary,
    .ra-review-modal__footer .btn-outline {
        flex: 1 1 auto;
        min-height: 44px;
    }
    .page-header .btn-primary {
        width: 100%;
        text-align: center;
        min-height: 44px;
    }
    .ra-view-tabs {
        display: flex;
        width: 100%;
    }
    .ra-view-tab {
        flex: 1 1 0;
        text-align: center;
        justify-content: center;
        font-size: 0.82rem;
        padding: 0.55rem 0.45rem;
    }
    .ra-queue-subtitle {
        font-size: 0.82rem;
    }
    .ra-filter-tabs {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        gap: 0.35rem;
        padding-bottom: 0.25rem;
        scrollbar-width: thin;
    }
    .ra-filter-tab {
        flex: 0 0 auto;
        white-space: nowrap;
        font-size: 0.78rem;
        padding: 0.4rem 0.55rem;
    }
    .ra-toolbar-controls {
        flex-wrap: wrap;
        justify-content: stretch;
    }
    .ra-search-field {
        flex: 1 1 100%;
        flex-wrap: wrap;
        width: 100%;
    }
    .ra-search-input {
        min-width: 0;
        width: 100%;
        flex: 1 1 100%;
    }
    .ra-search-btn {
        flex: 1 1 auto;
        min-height: 44px;
        text-align: center;
    }
    .ra-action-group {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        flex-wrap: nowrap;
    }
    .ra-action-btn {
        width: 100%;
        min-height: 40px;
        justify-content: center;
        text-align: center;
        box-sizing: border-box;
    }
    .ra-col-actions {
        min-width: 0;
        white-space: normal;
    }
    .ra-payment-note {
        white-space: normal;
    }
    #allReservationsModal.modal-overlay {
        padding: 0.5rem !important;
        align-items: flex-end !important;
    }
    #allReservationsModal.frs-all-reservations-modal .frs-all-reservations-modal__panel {
        max-width: 100%;
        max-height: 94dvh !important;
        border-radius: 14px 14px 0 0;
        padding: 1rem;
        margin: 0 !important;
    }
    .frs-all-reservations-modal__search-grid {
        grid-template-columns: 1fr !important;
    }
    .frs-all-reservations-modal__search select {
        min-width: 0 !important;
        width: 100%;
    }
    .frs-all-reservations-modal__search-actions {
        flex-wrap: wrap;
    }
    .frs-all-reservations-modal__search-actions .btn-primary,
    .frs-all-reservations-modal__search-actions .btn-outline {
        flex: 1 1 auto;
        text-align: center;
        min-height: 44px;
    }
}
@media (max-width: 900px) {
    .ra-queue-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .ra-search-form {
        max-width: none;
        min-width: 0;
        width: 100%;
    }
    .ra-toolbar-controls {
        flex-wrap: wrap;
        justify-content: flex-start;
    }
    .ra-search-input {
        min-width: 0;
    }
    .ra-table-scroll {
        overflow-x: visible;
        width: 100%;
        max-width: 100%;
        border: none;
        background: transparent;
    }
    .ra-queue-table,
    .ra-queue-table thead,
    .ra-queue-table tbody,
    .ra-queue-table tr,
    .ra-queue-table td {
        display: block;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }
    .ra-queue-table thead {
        display: none;
    }
    .ra-queue-table tr {
        margin: 0 0 0.85rem;
        padding: 0.5rem 0;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        overflow: hidden;
    }
    .ra-queue-table td {
        padding: 0.45rem 0.85rem;
        border: none;
        text-align: left;
        overflow-wrap: anywhere;
        word-break: normal;
    }
    .ra-queue-table td::before {
        content: attr(data-label);
        display: block;
        width: 100%;
        max-width: none;
        margin-bottom: 0.2rem;
        white-space: normal;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #94a3b8;
    }
    .ra-queue-table td > * {
        width: 100%;
        max-width: none;
        min-width: 0;
        text-align: left;
    }
    .ra-queue-table td.ra-col-actions,
    .ra-queue-table td.ra-col-narrow {
        width: 100% !important;
        min-width: 0 !important;
        white-space: normal !important;
    }
    .ra-action-group {
        flex-direction: column;
        flex-wrap: nowrap;
        align-items: stretch;
        width: 100%;
        gap: 0.45rem;
    }
    .ra-action-btn {
        width: 100%;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        min-height: 40px;
        box-sizing: border-box;
        white-space: normal;
    }
    .ra-priority-badge {
        white-space: nowrap;
        display: inline-block;
    }
    .ra-queue-table .status-badge,
    .status-badge.status-badge--cell {
        max-width: none;
        white-space: nowrap;
        overflow: visible;
        text-overflow: unset;
        display: inline-block;
        width: auto;
    }
    .ra-cell-purpose {
        max-width: none;
        text-align: left;
    }
}

/* All Reservations Modal - hidden by default, only visible with .show class */
#allReservationsModal.modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 1.5rem !important;
    box-sizing: border-box !important;
    background: rgba(0, 0, 0, 0.5) !important;
    backdrop-filter: blur(4px) !important;
    z-index: 99999 !important;
    pointer-events: auto !important;
    display: none !important;  /* hidden by default - .show class makes it visible */
    align-items: center !important;
    justify-content: center !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch;
}

#allReservationsModal.modal-overlay.show {
    display: flex !important;
}

#allReservationsModal.frs-all-reservations-modal .frs-all-reservations-modal__panel {
    background: var(--bg-secondary, #ffffff);
    color: var(--text-primary, #1e293b);
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    padding: 2rem;
    width: 100%;
    max-width: 900px;
    max-height: min(90vh, calc(100vh - 3rem)) !important;
    overflow-y: auto !important;
    margin: auto !important;
    flex-shrink: 1;
    animation: allReservationsModalSlideIn 0.3s ease-out;
}

.frs-all-reservations-modal__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--border-color, #e5e7eb);
}

.frs-all-reservations-modal__header h2 {
    margin: 0;
    color: var(--text-primary, #1e3a5f);
}

.frs-all-reservations-modal__search {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: var(--bg-tertiary, #f9fafb);
    border-radius: 8px;
    border: 1px solid var(--border-color, #e5e7eb);
}

.frs-all-reservations-modal__search label {
    color: var(--text-secondary, #374151);
}

.frs-all-reservations-modal__search input,
.frs-all-reservations-modal__search select {
    background: var(--bg-secondary, #fff);
    color: var(--text-primary, #111);
    border-color: var(--border-color, #e0e6ed);
}

html[data-theme="dark"] #allReservationsModal .facility-card-admin {
    border-color: var(--border-color) !important;
}

html[data-theme="dark"] #allReservationsModal .facility-card-admin h3,
html[data-theme="dark"] #allReservationsModal .facility-card-admin strong {
    color: var(--text-primary) !important;
}

html[data-theme="dark"] #allReservationsModal .facility-card-admin p,
html[data-theme="dark"] #allReservationsModal .facility-card-admin small,
html[data-theme="dark"] #allReservationsModal .facility-card-admin li {
    color: var(--text-secondary) !important;
}

/* Additional dark mode for modal components */
html[data-theme="dark"] #allReservationsModal.frs-all-reservations-modal .frs-all-reservations-modal__panel {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .frs-all-reservations-modal__header {
    border-color: #334155;
}

html[data-theme="dark"] .frs-all-reservations-modal__header h2 {
    color: #f1f5f9;
}

html[data-theme="dark"] .frs-all-reservations-modal__search {
    background: #0f172a;
    border-color: #334155;
}

html[data-theme="dark"] .frs-all-reservations-modal__search label {
    color: #cbd5e1;
}

html[data-theme="dark"] .frs-all-reservations-modal__search input,
html[data-theme="dark"] .frs-all-reservations-modal__search select {
    background: #1e293b;
    color: #f1f5f9;
    border-color: #334155;
}

html[data-theme="dark"] .frs-all-reservations-modal__search input:focus,
html[data-theme="dark"] .frs-all-reservations-modal__search select:focus {
    border-color: #3b82f6;
    outline: none;
}

@keyframes allReservationsModalSlideIn {
    from { opacity: 0; transform: translateY(-20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
</style>

<script>
(function() {
    function getAllReservationsModal() {
        return document.getElementById('allReservationsModal');
    }

    function openAllReservationsModal() {
        const modal = getAllReservationsModal();
        if (!modal) return;
        if (modal.parentNode !== document.body) document.body.appendChild(modal);
        const url = new URL(window.location.href);
        url.searchParams.set('open_modal', 'all_reservations');
        window.history.replaceState({}, '', url);
        modal.classList.add('show');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeAllReservationsModal() {
        const modal = getAllReservationsModal();
        if (!modal) return;
        const url = new URL(window.location.href);
        url.searchParams.delete('modal_search');
        url.searchParams.delete('modal_status');
        url.searchParams.delete('modal_page');
        url.searchParams.delete('open_modal');
        window.history.replaceState({}, '', url);
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    window.openAllReservationsModal = openAllReservationsModal;
    window.closeAllReservationsModal = closeAllReservationsModal;

    const modal = getAllReservationsModal();
    if (modal && modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }

    const params = new URLSearchParams(window.location.search);
    const shouldOpen = (params.get('open_modal') === 'all_reservations') ||
                       params.has('modal_search') || params.has('modal_status') || params.has('modal_page');
    if (shouldOpen) {
        setTimeout(openAllReservationsModal, 0);
    }

    if (!window.__raAllReservationsDelegationBound) {
        window.__raAllReservationsDelegationBound = true;
        document.addEventListener('click', function(e) {
            const m = getAllReservationsModal();
            if (!m || (m.style.display !== 'flex' && !m.classList.contains('show'))) return;
            if (e.target.closest('[data-close-all-reservations-modal]') || e.target === m) {
                e.preventDefault();
                e.stopPropagation();
                closeAllReservationsModal();
            }
        }, true);

        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            const m = getAllReservationsModal();
            if (m && (m.style.display === 'flex' || m.classList.contains('show'))) {
                e.preventDefault();
                closeAllReservationsModal();
            }
        }, true);
    }
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

