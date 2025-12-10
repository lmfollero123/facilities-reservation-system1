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
    $timeSlot = $_POST['time_slot'] ?? '';
    $purpose = trim($_POST['purpose'] ?? '');
    $docRef = trim($_POST['doc_ref'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;

    if (!$facilityId || !$date || !$timeSlot || !$purpose || !$userId) {
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
            $stmt = $pdo->prepare('INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, status) VALUES (:user, :facility, :date, :slot, :purpose, :status)');
            $stmt->execute([
                'user' => $userId,
                'facility' => $facilityId,
                'date' => $date,
                'slot' => $timeSlot,
                'purpose' => $purpose,
                'status' => 'pending',
            ]);
            $newReservationId = $pdo->lastInsertId();
            
            // Get facility name for audit log
            $facilityStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = :id');
            $facilityStmt->execute(['id' => $facilityId]);
            $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
            $facilityName = $facility ? $facility['name'] : 'Unknown Facility';
            
            // Log audit event
            logAudit('Created reservation request', 'Reservations', 
                'RES-' . $newReservationId . ' – ' . $facilityName . ' (' . $date . ' ' . $timeSlot . ')');
            
            // Create notification for admin/staff about new reservation
            // Get all Admin and Staff users
            $staffStmt = $pdo->query("SELECT id FROM users WHERE role IN ('Admin', 'Staff') AND status = 'active'");
            $staffUsers = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $notifTitle = 'New Reservation Request';
            $notifMessage = 'A new reservation request has been submitted for ' . $facilityName . ' on ' . date('F j, Y', strtotime($date)) . ' (' . $timeSlot . ').';
            $notifLink = base_path() . '/resources/views/pages/dashboard/reservations_manage.php';
            
            foreach ($staffUsers as $staffId) {
                createNotification($staffId, 'booking', $notifTitle, $notifMessage, $notifLink);
            }
            
            // Also notify the requester
            createNotification($userId, 'booking', 'Reservation Submitted', 
                'Your reservation request for ' . $facilityName . ' has been submitted and is pending review.', 
                base_path() . '/resources/views/pages/dashboard/my_reservations.php');
            
            $success = 'Reservation submitted successfully. You will receive an update once it is reviewed.';
        } catch (Throwable $e) {
            $error = 'Unable to submit reservation. Please try again later.';
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
                Time Slot
                <div class="input-wrapper">
                    <span class="input-icon">⏰</span>
                    <select name="time_slot" id="time-slot" required>
                        <option value="">Select time slot...</option>
                        <option value="Morning (8AM - 12PM)">Morning (8AM - 12PM)</option>
                        <option value="Afternoon (1PM - 5PM)">Afternoon (1PM - 5PM)</option>
                        <option value="Evening (5PM - 9PM)">Evening (5PM - 9PM)</option>
                    </select>
                </div>
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

    // Prefill from query params (facility_id, reservation_date, time_slot)
    const qp = new URLSearchParams(window.location.search);
    const preFacility = qp.get('facility_id');
    const preDate = qp.get('reservation_date');
    const preSlot = qp.get('time_slot');
    const facilitySel = document.getElementById('facility-select');
    const dateInput = document.getElementById('reservation-date');
    const slotSel = document.getElementById('time-slot');

    if (preFacility && facilitySel) {
        facilitySel.value = preFacility;
    }
    if (preDate && dateInput) {
        dateInput.value = preDate;
    }
    if (preSlot && slotSel) {
        slotSel.value = preSlot;
    }

    const facilitySel = document.getElementById('facility-select');
    const dateInput = document.getElementById('reservation-date');
    const slotSel = document.getElementById('time-slot');
    const messageBox = document.getElementById('conflict-warning');
    const messageText = document.getElementById('conflict-message');
    const altWrap = document.getElementById('conflict-alternatives');
    const altList = document.getElementById('alternatives-list');
    const riskLine = document.getElementById('conflict-risk');

    const eventMap = <?= json_encode($eventMap); ?>;
    const basePath = <?= json_encode(base_path()); ?>;

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
            altList.innerHTML = alternatives.map(a => `<li>${a.time_slot} — ${a.recommendation}</li>`).join('');
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

    async function checkConflict() {
        const fid = facilitySel.value;
        const date = dateInput.value;
        const slot = slotSel.value;
        if (!fid || !date || !slot) {
            clearMessage();
            return;
        }
        try {
            const resp = await fetch(basePath + '/resources/views/pages/dashboard/ai_conflict_check.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `facility_id=${encodeURIComponent(fid)}&date=${encodeURIComponent(date)}&time_slot=${encodeURIComponent(slot)}`
            });
            const data = await resp.json();
            if (data.error) {
                // Keep last state on error to avoid flicker
                return;
            }
            const eventLabel = eventMap[date] || null;
            const key = `${fid}|${date}|${slot}`;
            if (data.has_conflict) {
                showMessage(data.message || 'This slot is already booked.', data.alternatives || [], data.risk_score ?? null, eventLabel);
                lastShown = {fid, date, slot};
            } else if ((data.risk_score ?? 0) >= 50 || eventLabel) {
                const msg = eventLabel
                    ? `Higher demand expected (${eventLabel}). Consider alternative slots.`
                    : 'Higher demand expected. Consider alternative slots.';
                showMessage(msg, data.alternatives || [], data.risk_score ?? null, eventLabel);
                lastShown = {fid, date, slot};
            } else {
                // Only clear if this is a different query; keep previous warning otherwise
                if (!lastShown || `${lastShown.fid}|${lastShown.date}|${lastShown.slot}` !== key) {
                    clearMessage();
                    lastShown = null;
                }
            }
        } catch (e) {
            // Keep last shown state on error
            if (lastShown) {
                messageBox.style.display = 'block';
            } else {
                clearMessage();
            }
        }
    }

    facilitySel?.addEventListener('change', checkConflict);
    dateInput?.addEventListener('change', checkConflict);
    slotSel?.addEventListener('change', checkConflict);
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
