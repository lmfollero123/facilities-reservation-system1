<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';
require_once __DIR__ . '/../../../../config/auto_approval.php';

$pdo = db();
$pageTitle = 'Book a Facility | LGU Facilities Reservation';
$success = '';
$error = '';
$conflictWarning = null;
$recommendations = [];

$BOOKING_LIMIT_ACTIVE = 3; // max active (pending+approved) in window
$BOOKING_LIMIT_WINDOW_DAYS = 30; // rolling window for active bookings
$BOOKING_ADVANCE_MAX_DAYS = 60; // max days ahead
$BOOKING_PER_DAY = 1; // max bookings per user per day (pending+approved)

try {
    $facilitiesStmt = $pdo->query('SELECT id, name, base_rate, status FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $facilities = [];
    $error = 'Unable to load facilities right now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facilityId = (int)($_POST['facility_id'] ?? 0);
    $date = $_POST['reservation_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $purpose = trim($_POST['purpose'] ?? '');
    $docRef = trim($_POST['doc_ref'] ?? '');
    $expectedAttendees = !empty($_POST['expected_attendees']) ? (int)$_POST['expected_attendees'] : null;
    $isCommercial = isset($_POST['is_commercial']) && $_POST['is_commercial'] === '1';
    $userId = $_SESSION['user_id'] ?? null;
    
    // Validate time inputs and create time slot string
    if (!$startTime || !$endTime) {
        $error = 'Please select both start and end times.';
    } else {
        // Validate time format and range
        $startTimeObj = DateTime::createFromFormat('H:i', $startTime);
        $endTimeObj = DateTime::createFromFormat('H:i', $endTime);
        
        if (!$startTimeObj || !$endTimeObj) {
            $error = 'Invalid time format. Please use valid time values.';
        } elseif ($endTimeObj <= $startTimeObj) {
            $error = 'End time must be after start time.';
        } else {
            // Calculate duration in hours
            $duration = $startTimeObj->diff($endTimeObj);
            $durationHours = $duration->h + ($duration->i / 60);
            
            // Validate maximum duration (4 hours for auto-approval, but allow longer for manual approval)
            if ($durationHours > 12) {
                $error = 'Reservation duration cannot exceed 12 hours.';
            } elseif ($durationHours < 0.5) {
                $error = 'Reservation duration must be at least 30 minutes.';
            } else {
                // Format time slot as "HH:MM - HH:MM" for storage
                $timeSlot = $startTime . ' - ' . $endTime;
            }
        }
    }

    if (!$facilityId || !$date || !$purpose || !$userId) {
        $error = 'Please complete all required fields.';
    } elseif ($date < date('Y-m-d')) {
        $error = 'Cannot book facilities for past dates. Please select a future date.';
    } elseif ($date > date('Y-m-d', strtotime('+' . $BOOKING_ADVANCE_MAX_DAYS . ' days'))) {
        $error = "Bookings are allowed only up to {$BOOKING_ADVANCE_MAX_DAYS} days in advance.";
    } else {
        // Active bookings cap in rolling window
        $windowEnd = date('Y-m-d', strtotime('+' . ($BOOKING_LIMIT_WINDOW_DAYS - 1) . ' days'));
        $activeCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reservations
             WHERE user_id = :uid
               AND reservation_date BETWEEN :start AND :end
               AND status IN ("pending","approved")'
        );
        $activeCountStmt->execute([
            'uid' => $userId,
            'start' => date('Y-m-d'),
            'end' => $windowEnd,
        ]);
        $activeCount = (int)$activeCountStmt->fetchColumn();

        if ($activeCount >= $BOOKING_LIMIT_ACTIVE) {
            $error = "Limit reached: You can have up to {$BOOKING_LIMIT_ACTIVE} active reservations (pending/approved) within the next {$BOOKING_LIMIT_WINDOW_DAYS} days.";
        } else {
            // Per-day cap
            $perDayStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM reservations
                 WHERE user_id = :uid
                   AND reservation_date = :date
                   AND status IN ("pending","approved")'
            );
            $perDayStmt->execute([
                'uid' => $userId,
                'date' => $date,
            ]);
            $perDayCount = (int)$perDayStmt->fetchColumn();

            if ($perDayCount >= $BOOKING_PER_DAY) {
                $error = "Limit reached: You can only have {$BOOKING_PER_DAY} booking on this date.";
            }
        }
    }

    if (!$error) {
        // AI Conflict Detection
        $conflictCheck = detectBookingConflict($facilityId, $date, $timeSlot);
        
        if ($conflictCheck['has_conflict']) {
            $error = '⚠️ Conflict Detected: ' . $conflictCheck['message'];
            $conflictWarning = $conflictCheck;
        } elseif ($conflictCheck['risk_score'] > 70) {
            $conflictWarning = $conflictCheck;
        }
        
        // Only proceed if no hard conflict
        if (!$conflictCheck['has_conflict']) {
        try {
            // Evaluate auto-approval conditions
            $autoApprovalResult = evaluateAutoApproval(
                $facilityId,
                $date,
                $timeSlot,
                $expectedAttendees,
                $isCommercial,
                $userId,
                $BOOKING_ADVANCE_MAX_DAYS
            );
            
            // Determine initial status based on auto-approval evaluation
            $initialStatus = $autoApprovalResult['auto_approve'] ? 'approved' : 'pending';
            $isAutoApproved = $autoApprovalResult['auto_approve'];
            
            // Insert reservation with determined status
            $stmt = $pdo->prepare(
                'INSERT INTO reservations (
                    user_id, facility_id, reservation_date, time_slot, purpose, 
                    status, expected_attendees, is_commercial, auto_approved
                ) VALUES (
                    :user, :facility, :date, :slot, :purpose, 
                    :status, :attendees, :commercial, :auto_approved
                )'
            );
            $stmt->execute([
                'user' => $userId,
                'facility' => $facilityId,
                'date' => $date,
                'slot' => $timeSlot,
                'purpose' => $purpose,
                'status' => $initialStatus,
                'attendees' => $expectedAttendees,
                'commercial' => $isCommercial ? 1 : 0,
                'auto_approved' => $isAutoApproved ? 1 : 0,
            ]);
            $newReservationId = $pdo->lastInsertId();
            
            // Get facility name for audit log
            $facilityStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = :id');
            $facilityStmt->execute(['id' => $facilityId]);
            $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
            $facilityName = $facility ? $facility['name'] : 'Unknown Facility';
            
            // Log reservation history entry
            $historyStmt = $pdo->prepare(
                'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
                 VALUES (:res_id, :status, :note, NULL)'
            );
            $historyNote = $isAutoApproved 
                ? 'Automatically approved by system - all conditions met'
                : 'Pending manual review by staff';
            $historyStmt->execute([
                'res_id' => $newReservationId,
                'status' => $initialStatus,
                'note' => $historyNote
            ]);
            
            // Log audit event
            $auditDetails = 'RES-' . $newReservationId . ' – ' . $facilityName . ' (' . $date . ' ' . $timeSlot . ')';
            if ($isAutoApproved) {
                $auditDetails .= ' [Auto-approved]';
            }
            logAudit('Created reservation request', 'Reservations', $auditDetails);
            
            // Create notifications
            if ($isAutoApproved) {
                // Auto-approved: notify user only
                createNotification(
                    $userId, 
                    'booking', 
                    'Reservation Approved', 
                    'Your reservation request for ' . $facilityName . ' on ' . date('F j, Y', strtotime($date)) . ' (' . $timeSlot . ') has been automatically approved.', 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php'
                );
                $success = 'Reservation automatically approved! Your booking has been confirmed.';
            } else {
                // Pending: notify admin/staff and user
                $staffStmt = $pdo->query("SELECT id FROM users WHERE role IN ('Admin', 'Staff') AND status = 'active'");
                $staffUsers = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                
                $notifTitle = 'New Reservation Request';
                $notifMessage = 'A new reservation request has been submitted for ' . $facilityName . ' on ' . date('F j, Y', strtotime($date)) . ' (' . $timeSlot . ').';
                $notifLink = base_path() . '/resources/views/pages/dashboard/reservations_manage.php';
                
                foreach ($staffUsers as $staffId) {
                    createNotification($staffId, 'booking', $notifTitle, $notifMessage, $notifLink);
                }
                
                createNotification(
                    $userId, 
                    'booking', 
                    'Reservation Submitted', 
                    'Your reservation request for ' . $facilityName . ' has been submitted and is pending review.', 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php'
                );
                
                $success = 'Reservation submitted successfully. You will receive an update once it is reviewed.';
                
                // Include reason if provided
                if (!empty($autoApprovalResult['reason']) && $autoApprovalResult['reason'] !== 'All conditions met for auto-approval') {
                    $success .= ' (Note: ' . htmlspecialchars($autoApprovalResult['reason']) . ')';
                }
            }
        } catch (Throwable $e) {
            $error = 'Unable to submit reservation. Please try again later.';
            error_log('Reservation submission error: ' . $e->getMessage());
        }
        }
    }
}

// Get facility recommendations - will be loaded via AJAX when user types in purpose field
$recommendations = [];

$myReservations = [];
if (!empty($_SESSION['user_id'])) {
    $myStmt = $pdo->prepare(
        'SELECT r.reservation_date, r.time_slot, r.status, f.name AS facility
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         WHERE r.user_id = :user_id
         ORDER BY r.created_at DESC
         LIMIT 5'
    );
    $myStmt->execute(['user_id' => $_SESSION['user_id']]);
    $myReservations = $myStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get availability snapshot for next 14 days
$today = date('Y-m-d');
$endDate = date('Y-m-d', strtotime('+29 days'));

$availabilityStmt = $pdo->prepare(
    'SELECT r.reservation_date, r.status, COUNT(*) as reservation_count
     FROM reservations r
     WHERE r.reservation_date >= :start_date AND r.reservation_date <= :end_date
     GROUP BY r.reservation_date, r.status'
);
$availabilityStmt->execute([
    'start_date' => $today,
    'end_date' => $endDate,
]);
$availabilityData = $availabilityStmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date
$availabilityByDate = [];
foreach ($availabilityData as $row) {
    $date = $row['reservation_date'];
    if (!isset($availabilityByDate[$date])) {
        $availabilityByDate[$date] = ['approved' => 0, 'pending' => 0, 'blocked' => 0];
    }
    if ($row['status'] === 'approved') {
        $availabilityByDate[$date]['approved'] = (int)$row['reservation_count'];
    } elseif ($row['status'] === 'pending') {
        $availabilityByDate[$date]['pending'] = (int)$row['reservation_count'];
    }
}

// Check for facilities in maintenance
$maintenanceStmt = $pdo->query('SELECT COUNT(*) FROM facilities WHERE status IN ("maintenance", "offline")');
$hasMaintenance = (int)$maintenanceStmt->fetchColumn() > 0;

$resDetailStmt = $pdo->prepare(
    'SELECT r.reservation_date, r.time_slot, r.status, r.purpose, f.name AS facility_name, u.name AS requester
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.reservation_date >= :start_date AND r.reservation_date <= :end_date
       AND r.status IN ("pending","approved","denied","cancelled")
     ORDER BY r.reservation_date, r.time_slot'
);
$resDetailStmt->execute([
    'start_date' => $today,
    'end_date' => $endDate,
]);
$resDetailByDate = [];
foreach ($resDetailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $resDetailByDate[$row['reservation_date']][] = $row;
}

$yearNow = (int)date('Y');
$years = [$yearNow, $yearNow + 1];
$holidayList = [];
foreach ($years as $yr) {
    $holidayList["$yr-01-01"] = 'New Year\'s Day';
    $holidayList["$yr-02-25"] = 'EDSA People Power Anniversary';
    $holidayList["$yr-04-09"] = 'Araw ng Kagitingan';
    $holidayList[date('Y-m-d', strtotime("second sunday of May $yr"))] = 'Mother\'s Day';
    $holidayList[date('Y-m-d', strtotime("second sunday of June $yr"))] = 'Father\'s Day';
    $holidayList["$yr-06-12"] = 'Independence Day';
    $holidayList["$yr-08-21"] = 'Ninoy Aquino Day';
    $holidayList["$yr-08-26"] = 'National Heroes Day';
    $holidayList["$yr-11-01"] = 'All Saints\' Day';
    $holidayList["$yr-11-02"] = 'All Souls\' Day';
    $holidayList["$yr-11-30"] = 'Bonifacio Day';
    $holidayList["$yr-12-25"] = 'Christmas Day';
    $holidayList["$yr-12-30"] = 'Rizal Day';
    // Barangay Culiat local events
    $holidayList["$yr-09-08"] = 'Barangay Culiat Fiesta';
    $holidayList["$yr-02-11"] = 'Barangay Culiat Founding Day';
}

$eventMap = [];
for ($i = 0; $i < 30; $i++) {
    $d = date('Y-m-d', strtotime("+$i days"));
    if (isset($holidayList[$d])) {
        $eventMap[$d] = $holidayList[$d];
    }
}

$timeline = [
    ['title' => 'Request Submitted', 'detail' => 'Awaiting LGU staff review'],
    ['title' => 'Validation', 'detail' => 'Facility manager verifying availability'],
    ['title' => 'Approval / Denial', 'detail' => 'Resident notified via email + SMS'],
    ['title' => 'Reservation Confirmed', 'detail' => 'Facility reserved for your use'],
];

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span>Book a Facility</span>
    </div>
    <h1>Book a Facility</h1>
    <small>Submit a reservation request for LGU-managed venues.</small>
</div>

<?php if ($success): ?>
    <div class="message success" style="background:#e3f8ef;color:#0d7a43;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($success); ?>
    </div>
<?php elseif ($error): ?>
    <div class="message error" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Reservation Details</h2>
        <form class="booking-form" method="POST">
            <label>
                Facility
                <div class="input-wrapper">
                    <span class="input-icon">🏛️</span>
                    <select name="facility_id" id="facility-select" required>
                        <option value="">Select a facility...</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= $facility['id']; ?>">
                                <?= htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">All facilities are provided free of charge.</small>
                </div>
            </label>


            <label>
                Reservation Date
                <div class="input-wrapper">
                    <span class="input-icon">📅</span>
                    <input type="date" name="reservation_date" id="reservation-date" min="<?= date('Y-m-d'); ?>" required>
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Only future dates are allowed.</small>
            </label>

            <label>
                Start Time
                <div class="input-wrapper">
                    <span class="input-icon">⏰</span>
                    <input type="time" name="start_time" id="start-time" required min="08:00" max="21:00">
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Facility operating hours: 8:00 AM - 9:00 PM</small>
            </label>

            <label>
                End Time
                <div class="input-wrapper">
                    <span class="input-icon">⏰</span>
                    <input type="time" name="end_time" id="end-time" required min="08:00" max="21:00">
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Must be after start time. Maximum duration: 4 hours (if facility has auto-approval enabled).
                </small>
            </label>

            <div id="conflict-warning" style="display:none; background:#fff4e5; border:1px solid #ffc107; border-radius:8px; padding:1rem; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#856404; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚠️</span> Conflict / Risk
                </h4>
                <p id="conflict-message" style="margin:0 0 0.75rem; color:#856404; font-size:0.85rem;"></p>
                <div id="conflict-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; color:#856404; font-size:0.85rem; font-weight:600;">Alternative time slots:</p>
                    <ul id="alternatives-list" style="margin:0; padding-left:1.25rem; color:#856404; font-size:0.85rem;"></ul>
                </div>
                <p id="conflict-risk" style="margin:0; color:#5b6888; font-size:0.82rem; display:none;"></p>
                <small id="conflict-hint" style="display:none; color:#8b95b5; font-size:0.8rem;">Risk factors may include holidays/events, pending requests, and historical demand.</small>
            </div>

            <label>
                Purpose of Use
                <textarea name="purpose" id="purpose-input" rows="3" placeholder="e.g., Zumba class, Barangay General Assembly, Sports tournament" required></textarea>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Describe your event - AI will suggest the best facilities for you.</small>
            </label>

            <label>
                <input type="checkbox" name="is_commercial" value="1" id="is-commercial">
                <span style="margin-left:0.5rem;">This reservation is for commercial purposes (e.g., business events, paid workshops, sales activities)</span>
            </label>
            <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem; margin-left:1.5rem;">
                Commercial reservations require manual approval by LGU staff.
            </small>

            <label>
                Expected Number of Attendees (Optional)
                <div class="input-wrapper">
                    <span class="input-icon">👥</span>
                    <input type="number" name="expected_attendees" id="expected-attendees" min="1" placeholder="e.g., 50">
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Helps ensure facility capacity is appropriate. Some facilities have capacity limits for auto-approval.
                </small>
            </label>

            <div id="ai-recommendations" style="display:none; background:#e3f8ef; border:1px solid #0d7a43; border-radius:8px; padding:1rem; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#0d7a43; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>🤖</span> AI Recommendations
                </h4>
                <p style="margin:0 0 0.75rem; color:#0d7a43; font-size:0.85rem;">Based on your purpose, here are recommended facilities:</p>
                <div id="recommendations-list" style="color:#0d7a43; font-size:0.85rem;"></div>
            </div>

            <label>
                Supporting Document Reference
                <div class="input-wrapper">
                    <span class="input-icon">📄</span>
                    <input type="text" name="doc_ref" placeholder="Document No. / Request Letter Ref.">
                </div>
            </label>

            <button class="btn-primary" type="submit">Submit Booking Request</button>
        </form>

        <?php if ($conflictWarning && $conflictWarning['has_conflict']): ?>
            <div style="background:#fdecee; border:1px solid #b23030; border-radius:8px; padding:1rem; margin-top:1.5rem;">
                <h4 style="margin:0 0 0.5rem; color:#b23030; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚠️</span> Conflict Detected
                </h4>
                <p style="margin:0 0 0.75rem; color:#b23030; font-size:0.85rem;">
                    <?= htmlspecialchars($conflictWarning['message']); ?>
                </p>
                <?php if (!empty($conflictWarning['alternatives'])): ?>
                    <p style="margin:0 0 0.5rem; color:#b23030; font-size:0.85rem; font-weight:600;">Alternative time slots for this date:</p>
                    <ul style="margin:0; padding-left:1.25rem; color:#b23030; font-size:0.85rem;">
                        <?php foreach ($conflictWarning['alternatives'] as $alt): ?>
                            <?php if ($alt['available']): ?>
                                <li style="margin-bottom:0.25rem;">
                                    <strong><?= htmlspecialchars($alt['time_slot']); ?></strong> - <?= htmlspecialchars($alt['recommendation']); ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php elseif ($conflictWarning && $conflictWarning['risk_score'] > 70): ?>
            <div style="background:#fff4e5; border:1px solid #ffc107; border-radius:8px; padding:1rem; margin-top:1.5rem;">
                <h4 style="margin:0 0 0.5rem; color:#856404; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚠️</span> High Demand Warning
                </h4>
                <p style="margin:0; color:#856404; font-size:0.85rem;">
                    This time slot has high historical demand (Risk Score: <?= $conflictWarning['risk_score']; ?>%). 
                    Consider booking well in advance or selecting an alternative time.
                </p>
            </div>
        <?php endif; ?>

        <div style="background:#fff4e5; border:1px solid #ffc107; border-radius:8px; padding:1rem; margin-top:1.5rem;">
            <h3 style="margin:0 0 0.5rem; color:#856404; font-size:1rem; display:flex; align-items:center; gap:0.5rem;">
                <span>⚠️</span> Important Notice
            </h3>
            <p style="margin:0; color:#856404; font-size:0.9rem; line-height:1.6;">
                <strong>Emergency Override Policy:</strong> In case of emergencies (e.g., evacuation centers, disaster response, urgent LGU/Barangay needs), 
                the LGU reserves the right to override or cancel existing reservations. Affected residents will be notified immediately. 
                All facilities are provided free of charge for public use.
            </p>
        </div>

        <div class="schedule-board">
            <header>
                <h3>Availability Snapshot</h3>
                <a class="btn-outline" href="<?= base_path(); ?>/resources/views/pages/dashboard/calendar.php">View Full Calendar</a>
            </header>
            <div class="schedule-grid">
                <?php for ($i = 0; $i < 30; $i++): ?>
                    <?php
                    $currentDate = date('Y-m-d', strtotime("+$i days"));
                    $dayNumber = date('d', strtotime($currentDate));
                    $dayName = date('D', strtotime($currentDate));
                    $dateData = $availabilityByDate[$currentDate] ?? ['approved' => 0, 'pending' => 0, 'blocked' => 0];
                    $eventLabel = $eventMap[$currentDate] ?? null;
                    
                    // Determine status
                    $status = 'available';
                    $title = 'Available';
                    
                    if ($hasMaintenance && ($dateData['approved'] > 0 || $dateData['pending'] > 0)) {
                        $status = 'unavailable';
                        $title = 'Maintenance + ' . ($dateData['approved'] + $dateData['pending']) . ' booking(s)';
                    } elseif ($hasMaintenance) {
                        $status = 'unavailable';
                        $title = 'Maintenance';
                    } elseif ($dateData['approved'] > 0 && $dateData['pending'] > 0) {
                        $status = 'requested';
                        $title = $dateData['approved'] . ' approved, ' . $dateData['pending'] . ' pending';
                    } elseif ($dateData['approved'] > 0) {
                        $status = 'requested';
                        $title = $dateData['approved'] . ' booking(s)';
                    } elseif ($dateData['pending'] > 0) {
                        $status = 'requested';
                        $title = $dateData['pending'] . ' pending request(s)';
                    }
                    
                    // Highlight today
                    $isToday = $currentDate === date('Y-m-d');
                    ?>
                    <div class="schedule-cell <?= $status; ?><?= $isToday ? ' today' : ''; ?>" 
                         data-date="<?= $currentDate; ?>"
                         data-event="<?= htmlspecialchars($eventLabel ?? '', ENT_QUOTES); ?>"
                         title="<?= htmlspecialchars($title . ' - ' . date('M d, Y', strtotime($currentDate))); ?>">
                        <span class="cell-dow"><?= $dayName; ?></span>
                        <span class="cell-month"><?= date('M', strtotime($currentDate)); ?></span>
                        <span class="cell-day"><?= $dayNumber; ?></span>
                        <?php if ($dateData['approved'] > 0 || $dateData['pending'] > 0): ?>
                            <span class="cell-count">
                                <?= $dateData['approved'] + $dateData['pending']; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($eventLabel): ?>
                                    <?php
                                        $e = strtolower($eventLabel);
                                        $color = '#16a34a';
                                        if (strpos($e, 'christmas') !== false) $color = '#b91c1c';
                                        elseif (strpos($e, 'new year') !== false) $color = '#9333ea';
                                        elseif (strpos($e, 'fiesta') !== false) $color = '#2563eb';
                                        elseif (strpos($e, 'independence') !== false) $color = '#0f766e';
                                        elseif (strpos($e, 'heroes') !== false) $color = '#2563eb';
                                        elseif (strpos($e, 'bonifacio') !== false) $color = '#334155';
                                        elseif (strpos($e, 'all saints') !== false || strpos($e, 'all souls') !== false) $color = '#92400e';
                                    ?>
                                    <span class="event-pill" data-color="<?= $color; ?>">
                                <?= htmlspecialchars($eventLabel); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="legend">
                <span><span class="dot" style="background:#f6f8fc"></span> Available</span>
                <span><span class="dot" style="background:#fde9ec"></span> Blocked / Maintenance</span>
                <span><span class="dot" style="background:#fff4e5"></span> Has Bookings</span>
                <span><span class="dot" style="background:#dbeafe; border:1px solid #2563eb;"></span> Holiday / Barangay Event</span>
            </div>
            <small style="display:block; margin-top:0.75rem; color:#8b95b5; font-size:0.85rem;">
                Showing next 30 days. Hover over dates for details.
            </small>
        </div>

        <!-- Full Calendar Modal -->
        <?php
        $rangeStartLabel = date('M d, Y');
        $rangeEndLabel = date('M d, Y', strtotime('+29 days'));
        ?>
        <div id="fullCalendarModal" class="modal-overlay" style="display:none;">
            <div class="modal-container">
                <div class="modal-header">
                    <div>
                        <h3 style="margin:0;">Full Calendar</h3>
                        <small style="color:#64748b;">Next 30 days · <?= $rangeStartLabel; ?> — <?= $rangeEndLabel; ?></small>
                    </div>
                    <button type="button" class="btn-outline" id="closeFullCalendar" aria-label="Close calendar">Close</button>
                </div>
                <div class="modal-body">
                    <div class="schedule-grid full-grid">
                        <?php for ($i = 0; $i < 30; $i++): ?>
                            <?php
                            $currentDate = date('Y-m-d', strtotime("+$i days"));
                            $dayNumber = date('d', strtotime($currentDate));
                            $dayName = date('D', strtotime($currentDate));
                            $dateData = $availabilityByDate[$currentDate] ?? ['approved' => 0, 'pending' => 0, 'blocked' => 0];
                            $eventLabel = $eventMap[$currentDate] ?? null;

                            $status = 'available';
                            $title = 'Available';

                            if ($hasMaintenance && ($dateData['approved'] > 0 || $dateData['pending'] > 0)) {
                                $status = 'unavailable';
                                $title = 'Maintenance + ' . ($dateData['approved'] + $dateData['pending']) . ' booking(s)';
                            } elseif ($hasMaintenance) {
                                $status = 'unavailable';
                                $title = 'Maintenance';
                            } elseif ($dateData['approved'] > 0 && $dateData['pending'] > 0) {
                                $status = 'requested';
                                $title = $dateData['approved'] . ' approved, ' . $dateData['pending'] . ' pending';
                            } elseif ($dateData['approved'] > 0) {
                                $status = 'requested';
                                $title = $dateData['approved'] . ' booking(s)';
                            } elseif ($dateData['pending'] > 0) {
                                $status = 'requested';
                                $title = $dateData['pending'] . ' pending request(s)';
                            }

                            $isToday = $currentDate === date('Y-m-d');
                            ?>
                            <div class="schedule-cell <?= $status; ?><?= $isToday ? ' today' : ''; ?>" title="<?= htmlspecialchars($title . ' - ' . date('M d, Y', strtotime($currentDate))); ?>">
                                <span class="cell-dow"><?= $dayName; ?></span>
                                <span class="cell-month"><?= date('M', strtotime($currentDate)); ?></span>
                                <span class="cell-day"><?= $dayNumber; ?></span>
                                <?php if ($dateData['approved'] > 0 || $dateData['pending'] > 0): ?>
                                    <span class="cell-count">
                                        <?= $dateData['approved'] + $dateData['pending']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($eventLabel): ?>
                                    <span class="event-pill"><?= htmlspecialchars($eventLabel); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 24, 55, 0.55);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            z-index: 3000;
        }
        .modal-container {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 60px rgba(0,0,0,0.25);
            width: min(1100px, 100%);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e8ecf4;
        }
        .modal-body {
            padding: 1.25rem;
            overflow: auto;
        }
        .schedule-grid.full-grid {
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }
        </style>

        <!-- Day Details Modal -->
        <div id="dayDetailModal" class="modal-overlay" style="display:none;">
            <div class="modal-container" style="max-width: 640px;">
                <div class="modal-header">
                    <div>
                        <h3 id="dayDetailTitle" style="margin:0;">Date</h3>
                        <small id="dayDetailSub" style="color:#64748b;"></small>
                    </div>
                    <button type="button" class="btn-outline" id="closeDayDetail" aria-label="Close details">Close</button>
                </div>
                <div class="modal-body" id="dayDetailBody"></div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const openBtn = document.getElementById('openFullCalendar');
            const closeBtn = document.getElementById('closeFullCalendar');
            const modal = document.getElementById('fullCalendarModal');
            const dayModal = document.getElementById('dayDetailModal');
            const closeDayDetail = document.getElementById('closeDayDetail');
            const dayDetailTitle = document.getElementById('dayDetailTitle');
            const dayDetailSub = document.getElementById('dayDetailSub');
            const dayDetailBody = document.getElementById('dayDetailBody');
            const resDetail = <?= json_encode($resDetailByDate); ?>;
            const eventMapJS = <?= json_encode($eventMap); ?>;

            function openModal() {
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            }
            function closeModal() {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
            function openDayModal(dateStr) {
                const friendly = new Date(dateStr + 'T00:00:00');
                dayModal.style.display = 'flex';
                document.body.classList.add('modal-open');
                dayDetailTitle.textContent = friendly.toDateString();
                const eventLabel = eventMapJS[dateStr] || '';
                dayDetailSub.textContent = eventLabel ? `Event/Holiday: ${eventLabel}` : '';
                const items = resDetail[dateStr] || [];
                if (!items.length) {
                    dayDetailBody.innerHTML = '<p style="color:#8b95b5;">No pending or approved reservations for this date.</p>';
                } else {
                    dayDetailBody.innerHTML = '<ul style="list-style:none; padding-left:0; margin:0; display:flex; flex-direction:column; gap:0.75rem;">' +
                        items.map(it => {
                            const statusClass = `status-${it.status}`;
                            return `<li style="padding:0.75rem; border:1px solid #e8ecf4; border-radius:10px; background:#f8fbff;">
                                <div style="display:flex; justify-content:space-between; gap:0.5rem; align-items:flex-start;">
                                    <div>
                                        <strong>${it.facility_name || ''}</strong><br>
                                        <span style="color:#5b6888;">${it.time_slot}</span>
                                    </div>
                                    <span class="status-badge ${statusClass}" style="text-transform:capitalize;">${it.status}</span>
                                </div>
                                <div style="margin-top:0.35rem; color:#475569; font-size:0.9rem;">${it.purpose || ''}</div>
                                <div style="margin-top:0.25rem; color:#8b95b5; font-size:0.85rem;">Requester: ${it.requester || ''}</div>
                            </li>`;
                        }).join('') + '</ul>';
                }
            }
            function closeDayModal() {
                dayModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }

            if (openBtn) openBtn.addEventListener('click', openModal);
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            modal?.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });
            closeDayDetail?.addEventListener('click', closeDayModal);
            dayModal?.addEventListener('click', (e) => {
                if (e.target === dayModal) closeDayModal();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeModal();
                if (e.key === 'Escape') closeDayModal();
            });

            function bindCells(scope) {
                scope.querySelectorAll('.schedule-cell').forEach(cell => {
                    const dateStr = cell.getAttribute('data-date');
                    cell.addEventListener('click', () => {
                        if (!dateStr) return;
                        openDayModal(dateStr);
                    });
                    // event color
                    const pill = cell.querySelector('.event-pill');
                    if (pill && pill.dataset.color) {
                        pill.style.background = pill.dataset.color;
                        pill.style.color = '#fff';
                        pill.style.border = '1px solid rgba(0,0,0,0.05)';
                    }
                });
            }
            bindCells(document);
            bindCells(modal);
        });
        </script>
    </section>

    <aside class="booking-card">
        <h2>Approval Flow</h2>
        <ul class="timeline">
            <?php foreach ($timeline as $step): ?>
                <li>
                    <strong><?= $step['title']; ?></strong>
                    <p><?= $step['detail']; ?></p>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="booking-card" style="margin-top:1.5rem;">
            <h3>My Recent Reservations</h3>
            <?php if (empty($myReservations)): ?>
                <p>No reservations yet. Submitted bookings will appear here.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Facility</th>
                        <th>Schedule</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($myReservations as $reservation): ?>
                        <tr>
                            <td><?= htmlspecialchars($reservation['facility']); ?></td>
                            <td><?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?></td>
                            <td><?= ucfirst($reservation['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <a class="btn-outline" style="margin-top:0.75rem; text-align:center;" href="<?= base_path(); ?>/resources/views/pages/dashboard/my_reservations.php">View full history</a>
            <?php endif; ?>
        </div>
    </aside>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tell main.js to skip its legacy conflict handler on this page
    window.DISABLE_CONFLICT_CHECK = true;

    // Get form elements
    const facilitySel = document.getElementById('facility-select');
    const dateInput = document.getElementById('reservation-date');
    const startTimeInput = document.getElementById('start-time');
    const endTimeInput = document.getElementById('end-time');
    const messageBox = document.getElementById('conflict-warning');
    const messageText = document.getElementById('conflict-message');
    const altWrap = document.getElementById('conflict-alternatives');
    const altList = document.getElementById('alternatives-list');
    const riskLine = document.getElementById('conflict-risk');

    const eventMap = <?= json_encode($eventMap); ?>;
    const basePath = <?= json_encode(base_path()); ?>;

    // Prefill from query params (facility_id, reservation_date, time_slot)
    const qp = new URLSearchParams(window.location.search);
    const preFacility = qp.get('facility_id');
    const preDate = qp.get('reservation_date');
    const preSlot = qp.get('time_slot'); // Legacy support for pre-filled slots

    function clearMessage() {
        messageBox.style.display = 'none';
        messageText.textContent = '';
        altWrap.style.display = 'none';
        altList.innerHTML = '';
        riskLine.style.display = 'none';
        riskLine.textContent = '';
    }

    function showMessage(text, alternatives, riskScore, eventLabel) {
        messageBox.style.display = 'block';
        messageText.textContent = text;
        if (alternatives && alternatives.length) {
            altWrap.style.display = 'block';
            // Use display field if available, otherwise use time_slot
            altList.innerHTML = alternatives
                .filter(a => a.available !== false) // Only show available slots
                .map(a => {
                    const slotDisplay = a.display || a.time_slot || '';
                    return `<li><strong>${slotDisplay}</strong> — ${a.recommendation || 'Available'}</li>`;
                })
                .join('');
        } else {
            altWrap.style.display = 'none';
            altList.innerHTML = '';
        }
        if (riskScore !== null && riskScore !== undefined) {
            riskLine.style.display = 'block';
            const parts = [];
            parts.push(`Risk score: ${riskScore}`);
            if (eventLabel) parts.push(`Event/Holiday: ${eventLabel}`);
            riskLine.textContent = parts.join(' • ');
        } else {
            if (eventLabel) {
                riskLine.style.display = 'block';
                riskLine.textContent = `Event/Holiday: ${eventLabel}`;
            } else {
                riskLine.style.display = 'none';
            }
        }
    }

    let lastShown = null;
    let conflictCheckTimeout = null;

    async function checkConflict() {
        // Clear any pending timeout
        if (conflictCheckTimeout) {
            clearTimeout(conflictCheckTimeout);
            conflictCheckTimeout = null;
        }

        const fid = facilitySel?.value;
        const date = dateInput?.value;
        const startTime = startTimeInput?.value;
        const endTime = endTimeInput?.value;
        
        if (!fid || !date || !startTime || !endTime) {
            clearMessage();
            return;
        }
        
        // Build time slot string in format "HH:MM - HH:MM"
        const timeSlot = startTime + ' - ' + endTime;
        
        // Show loading state (optional, but helpful for UX)
        messageBox.style.display = 'block';
        messageText.textContent = 'Checking for conflicts...';
        
        try {
            const url = basePath + '/resources/views/pages/dashboard/ai_conflict_check.php';
            const body = `facility_id=${encodeURIComponent(fid)}&date=${encodeURIComponent(date)}&time_slot=${encodeURIComponent(timeSlot)}`;
            
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            
            if (!resp.ok) {
                console.error('Conflict check failed:', resp.status, resp.statusText);
                clearMessage();
                return;
            }
            
            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Failed to parse response:', text);
                clearMessage();
                return;
            }
            
            if (data.error) {
                console.error('Conflict check error:', data.error);
                clearMessage();
                return;
            }
            
            const eventLabel = eventMap[date] || null;
            const key = `${fid}|${date}|${timeSlot}`;
            
            if (data.has_conflict) {
                showMessage(data.message || 'This slot is already booked.', data.alternatives || [], data.risk_score ?? null, eventLabel);
                lastShown = {fid, date, timeSlot};
            } else if ((data.risk_score ?? 0) >= 50 || eventLabel) {
                const msg = eventLabel
                    ? `Higher demand expected (${eventLabel}). Consider alternative slots.`
                    : 'Higher demand expected. Consider alternative slots.';
                showMessage(msg, data.alternatives || [], data.risk_score ?? null, eventLabel);
                lastShown = {fid, date, timeSlot};
            } else {
                // Only clear if this is a different query; keep previous warning otherwise
                if (!lastShown || `${lastShown.fid}|${lastShown.date}|${lastShown.timeSlot}` !== key) {
                    clearMessage();
                    lastShown = null;
                }
            }
        } catch (e) {
            console.error('Conflict check exception:', e);
            clearMessage();
        }
    }

    function debouncedCheckConflict() {
        // Debounce: wait 300ms after user stops changing inputs before checking
        if (conflictCheckTimeout) {
            clearTimeout(conflictCheckTimeout);
        }
        conflictCheckTimeout = setTimeout(checkConflict, 300);
    }

    // Add event listeners for both 'change' and 'input' events
    // 'change' fires on blur, 'input' fires as user types/selects
    facilitySel?.addEventListener('change', debouncedCheckConflict);
    dateInput?.addEventListener('change', debouncedCheckConflict);
    dateInput?.addEventListener('input', debouncedCheckConflict);
    startTimeInput?.addEventListener('change', debouncedCheckConflict);
    startTimeInput?.addEventListener('input', debouncedCheckConflict);
    endTimeInput?.addEventListener('change', debouncedCheckConflict);
    endTimeInput?.addEventListener('input', debouncedCheckConflict);
    
    // Also trigger check when time inputs are updated via time picker
    startTimeInput?.addEventListener('blur', function() {
        if (facilitySel?.value && dateInput?.value && startTimeInput?.value && endTimeInput?.value) {
            checkConflict();
        }
    });
    endTimeInput?.addEventListener('blur', function() {
        if (facilitySel?.value && dateInput?.value && startTimeInput?.value && endTimeInput?.value) {
            checkConflict();
        }
    });
    
    // Time validation: ensure end time is after start time and within limits
    function validateTimes() {
        if (!startTimeInput || !endTimeInput) return true;
        
        const startTime = startTimeInput.value;
        const endTime = endTimeInput.value;
        
        if (!startTime || !endTime) return true;
        
        const start = new Date('2000-01-01T' + startTime);
        const end = new Date('2000-01-01T' + endTime);
        
        if (end <= start) {
            endTimeInput.setCustomValidity('End time must be after start time');
            return false;
        }
        
        const durationMs = end - start;
        const durationHours = durationMs / (1000 * 60 * 60);
        
        if (durationHours > 12) {
            endTimeInput.setCustomValidity('Reservation duration cannot exceed 12 hours');
            return false;
        }
        
        if (durationHours < 0.5) {
            endTimeInput.setCustomValidity('Reservation duration must be at least 30 minutes');
            return false;
        }
        
        endTimeInput.setCustomValidity('');
        
        // After validation passes, trigger conflict check if all fields are filled
        if (facilitySel?.value && dateInput?.value) {
            debouncedCheckConflict();
        }
        
        return true;
    }
    
    startTimeInput?.addEventListener('change', validateTimes);
    endTimeInput?.addEventListener('change', validateTimes);
    endTimeInput?.addEventListener('input', validateTimes);

    // Prefill values and trigger change events
    if (preFacility && facilitySel) {
        facilitySel.value = preFacility;
        facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (preDate && dateInput) {
        dateInput.value = preDate;
        dateInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (preSlot && startTimeInput && endTimeInput) {
        // Parse time slot format "HH:MM - HH:MM" or legacy format
        const timeMatch = preSlot.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
        if (timeMatch) {
            startTimeInput.value = timeMatch[1] + ':' + timeMatch[2];
            endTimeInput.value = timeMatch[3] + ':' + timeMatch[4];
            startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // If all fields are pre-filled, trigger conflict check after a short delay
    if (preFacility && preDate && startTimeInput?.value && endTimeInput?.value) {
        setTimeout(() => {
            checkConflict();
        }, 200);
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
