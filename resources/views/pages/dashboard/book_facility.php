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
    } else {
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
$endDate = date('Y-m-d', strtotime('+13 days'));

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
                    <span>⚠️</span> Conflict Warning
                </h4>
                <p id="conflict-message" style="margin:0 0 0.75rem; color:#856404; font-size:0.85rem;"></p>
                <div id="conflict-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; color:#856404; font-size:0.85rem; font-weight:600;">Alternative time slots:</p>
                    <ul id="alternatives-list" style="margin:0; padding-left:1.25rem; color:#856404; font-size:0.85rem;"></ul>
                </div>
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
                <button class="btn-outline" type="button" onclick="window.location.href='<?= base_path(); ?>/resources/views/pages/dashboard/calendar.php'">View Full Calendar</button>
            </header>
            <div class="schedule-grid">
                <?php for ($i = 0; $i < 14; $i++): ?>
                    <?php
                    $currentDate = date('Y-m-d', strtotime("+$i days"));
                    $dayNumber = date('d', strtotime($currentDate));
                    $dayName = date('D', strtotime($currentDate));
                    $dateData = $availabilityByDate[$currentDate] ?? ['approved' => 0, 'pending' => 0, 'blocked' => 0];
                    
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
                    <div class="schedule-cell <?= $status; ?><?= $isToday ? ' today' : ''; ?>" title="<?= htmlspecialchars($title . ' - ' . date('M d, Y', strtotime($currentDate))); ?>">
                        <span style="font-size:0.75rem; color:#8b95b5; display:block;"><?= $dayName; ?></span>
                        <span style="font-weight:600;"><?= $dayNumber; ?></span>
                        <?php if ($dateData['approved'] > 0 || $dateData['pending'] > 0): ?>
                            <span style="font-size:0.7rem; color:#5b6888; display:block; margin-top:0.25rem;">
                                <?= $dateData['approved'] + $dateData['pending']; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="legend">
                <span><span class="dot" style="background:#f6f8fc"></span> Available</span>
                <span><span class="dot" style="background:#fde9ec"></span> Blocked / Maintenance</span>
                <span><span class="dot" style="background:#fff4e5"></span> Has Bookings</span>
            </div>
            <small style="display:block; margin-top:0.75rem; color:#8b95b5; font-size:0.85rem;">
                Showing next 14 days. Hover over dates for details.
            </small>
        </div>
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
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';

