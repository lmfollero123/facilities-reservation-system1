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
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/reservation_helpers.php';
$pdo = db();

// Auto-decline expired pending reservations before querying
autoDeclineExpiredReservations();

$pageTitle = 'My Reservations | LGU Facilities Reservation';
$userId = $_SESSION['user_id'] ?? null;
$message = '';
$messageType = 'success';

// Handle reschedule request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule' && $userId) {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $newDate = $_POST['new_date'] ?? '';
    $newTimeSlot = $_POST['new_time_slot'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    
    try {
        // Get reservation details with facility status
        $resStmt = $pdo->prepare(
            'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.reschedule_count, 
                    f.name AS facility_name, f.id AS facility_id, f.status AS facility_status
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             WHERE r.id = :id AND r.user_id = :user_id'
        );
        $resStmt->execute(['id' => $reservationId, 'user_id' => $userId]);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            throw new Exception('Reservation not found or you do not have permission to reschedule it.');
        }
        
        // Status-based constraints: Only pending, approved, or postponed can be rescheduled
        if (!in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)) {
            if ($reservation['status'] === 'denied') {
                throw new Exception('Rejected reservations cannot be rescheduled. Please create a new reservation request.');
            } elseif ($reservation['status'] === 'cancelled') {
                throw new Exception('Cancelled reservations cannot be rescheduled. Please create a new reservation request.');
            } elseif ($reservation['status'] === 'on_hold') {
                throw new Exception('Reservations on hold cannot be rescheduled at this time. Please contact support.');
            } else {
                throw new Exception('This reservation cannot be rescheduled due to its current status.');
            }
        }
        
        // Check if reservation has already started (ongoing)
        $reservationDate = new DateTime($reservation['reservation_date']);
        $today = new DateTime('today');
        $currentDate = date('Y-m-d');
        $currentHour = (int)date('H');
        $reservationTimeSlot = $reservation['time_slot'];
        
        $isOngoing = false;
        if ($reservation['reservation_date'] < $currentDate) {
            $isOngoing = true;
        } elseif ($reservation['reservation_date'] === $currentDate) {
            // Check if time slot has passed
            if (strpos($reservationTimeSlot, 'Morning') !== false && $currentHour >= 12) {
                $isOngoing = true;
            } elseif (strpos($reservationTimeSlot, 'Afternoon') !== false && $currentHour >= 17) {
                $isOngoing = true;
            } elseif (strpos($reservationTimeSlot, 'Evening') !== false && $currentHour >= 21) {
                $isOngoing = true;
            }
        }
        
        if ($isOngoing) {
            throw new Exception('Cannot reschedule a reservation that has already started or is ongoing.');
        }
        
        // Constraint 1: Reschedule allowed up to 3 days before event (same-day not allowed)
        $daysUntil = $today->diff($reservationDate)->days;
        
        if ($daysUntil < 3) {
            throw new Exception('Rescheduling is only allowed up to 3 days before the event. The event is ' . $daysUntil . ' day(s) away. Same-day rescheduling is not allowed.');
        }
        
        // Constraint 2: One reschedule per reservation
        $rescheduleCount = (int)($reservation['reschedule_count'] ?? 0);
        if ($rescheduleCount >= 1) {
            throw new Exception('This reservation has already been rescheduled once. Only one reschedule is allowed per reservation.');
        }
        
        // Facility availability check: Facility must be active
        if ($reservation['facility_status'] !== 'available') {
            throw new Exception('Cannot reschedule to a facility that is currently ' . $reservation['facility_status'] . '. Please contact support for assistance.');
        }
        
        // Validate new date and time slot
        if (empty($newDate) || empty($newTimeSlot)) {
            throw new Exception('New date and time slot are required.');
        }
        
        if (empty($reason)) {
            throw new Exception('Reason for rescheduling is required.');
        }
        
        // Validate new date is not in the past
        $newDateObj = new DateTime($newDate);
        if ($newDateObj < $today) {
            throw new Exception('New reservation date cannot be in the past. Please select today or a future date.');
        }
        
        // Check facility status for new date (facility must be active)
        $facilityCheck = $pdo->prepare('SELECT status FROM facilities WHERE id = :facility_id');
        $facilityCheck->execute(['facility_id' => $reservation['facility_id']]);
        $facilityStatus = $facilityCheck->fetchColumn();
        
        if ($facilityStatus !== 'available') {
            throw new Exception('The facility is currently ' . $facilityStatus . ' and not available for the selected date. Please choose another date or facility.');
        }
        
        // Check for conflicts (time slot must be free, no conflict with maintenance)
        $conflictCheck = $pdo->prepare(
            'SELECT id FROM reservations
             WHERE facility_id = :facility_id
             AND reservation_date = :new_date
             AND time_slot = :new_time_slot
             AND status IN ("pending", "approved")
             AND id != :reservation_id'
        );
        $conflictCheck->execute([
            'facility_id' => $reservation['facility_id'],
            'new_date' => $newDate,
            'new_time_slot' => $newTimeSlot,
            'reservation_id' => $reservationId
        ]);
        
        if ($conflictCheck->fetch()) {
            throw new Exception('The selected date and time slot is already booked. Please choose another time.');
        }
        
        // Update reservation
        $oldDate = $reservation['reservation_date'];
        $oldTimeSlot = $reservation['time_slot'];
        $oldStatus = $reservation['status'];
        
        // Constraint 3: Approved and postponed reservations require re-approval
        // Postponed reservations keep priority but go back to pending for re-approval
        $newStatus = (in_array($oldStatus, ['approved', 'postponed'], true)) ? 'pending' : $oldStatus;
        
        // Check if this is a postponed reservation with priority (keep priority when rescheduling)
        $priorityCheckStmt = $pdo->prepare('SELECT postponed_priority FROM reservations WHERE id = :id');
        $priorityCheckStmt->execute(['id' => $reservationId]);
        $hasPriority = (bool)$priorityCheckStmt->fetchColumn();
        
        // When rescheduling a postponed reservation, clear postponed_at but keep priority flag
        // The priority flag will remain so admins know this reservation has priority
        $updateStmt = $pdo->prepare(
            'UPDATE reservations 
             SET reservation_date = :new_date, 
                 time_slot = :new_time_slot, 
                 status = :new_status,
                 reschedule_count = reschedule_count + 1,
                 updated_at = CURRENT_TIMESTAMP,
                 postponed_at = NULL
             WHERE id = :id'
        );
        $updateStmt->execute([
            'new_date' => $newDate,
            'new_time_slot' => $newTimeSlot,
            'new_status' => $newStatus,
            'id' => $reservationId
        ]);
        
        // Note: postponed_priority flag is NOT cleared - it remains so admin knows this reservation has priority
        
        // Constraint 4: Full audit log maintained
        $historyNote = 'Rescheduled from ' . $oldDate . ' (' . $oldTimeSlot . ') to ' . $newDate . ' (' . $newTimeSlot . '). Reason: ' . $reason;
        if ($oldStatus === 'approved' || $oldStatus === 'postponed') {
            $historyNote .= ' Status changed to pending for re-approval.';
            if ($oldStatus === 'postponed' && $hasPriority) {
                $historyNote .= ' Priority status maintained - this reservation will be given preference during review.';
            }
        }
        
        $histStmt = $pdo->prepare(
            'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
             VALUES (:reservation_id, :status, :note, :user_id)'
        );
        $histStmt->execute([
            'reservation_id' => $reservationId,
            'status' => $newStatus,
            'note' => $historyNote,
            'user_id' => $userId
        ]);
        
        // Log audit event
        $auditDetails = 'RES-' . $reservationId . ' – ' . $reservation['facility_name'] . ' – Rescheduled from ' . $oldDate . ' ' . $oldTimeSlot . ' to ' . $newDate . ' ' . $newTimeSlot . '. Reason: ' . $reason;
        logAudit('Rescheduled own reservation', 'Reservations', $auditDetails);
        
        // Create notification
        $notifMessage = 'Your reservation for ' . $reservation['facility_name'];
        $notifMessage .= ' has been rescheduled from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
        $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
        if ($oldStatus === 'approved') {
            $notifMessage .= ' The new date requires re-approval.';
        }
        
        createNotification($userId, 'booking', 'Reservation Rescheduled', $notifMessage, 
            base_path() . '/resources/views/pages/dashboard/my_reservations.php');
        
        // Send email notification
        $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userInfo && !empty($userInfo['email'])) {
            $emailSubject = 'Reservation Rescheduled - ' . $reservation['facility_name'];
            $emailBody = '<p>Hi ' . htmlspecialchars($userInfo['name']) . ',</p>';
            $emailBody .= '<p>Your reservation for <strong>' . htmlspecialchars($reservation['facility_name']) . '</strong> has been rescheduled.</p>';
            $emailBody .= '<p><strong>Original Date/Time:</strong> ' . date('F j, Y', strtotime($oldDate)) . ' (' . htmlspecialchars($oldTimeSlot) . ')</p>';
            $emailBody .= '<p><strong>New Date/Time:</strong> ' . date('F j, Y', strtotime($newDate)) . ' (' . htmlspecialchars($newTimeSlot) . ')</p>';
            $emailBody .= '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>';
            if ($oldStatus === 'approved') {
                $emailBody .= '<p><strong>Note:</strong> The new date requires re-approval. You will be notified once it is reviewed.</p>';
            }
            $emailBody .= '<p><a href="' . base_url() . '/resources/views/pages/dashboard/my_reservations.php">View My Reservations</a></p>';
            sendEmail($userInfo['email'], $userInfo['name'], $emailSubject, $emailBody);
        }
        
        $message = 'Reservation rescheduled successfully.' . ($oldStatus === 'approved' ? ' It is now pending re-approval.' : '');
        $messageType = 'success';
        
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$reservations = [];
$totalRows = 0;

if ($userId) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = :user_id');
    $countStmt->execute(['user_id' => $userId]);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.reschedule_count, f.name AS facility_name, f.status AS facility_status
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         WHERE r.user_id = :user_id
         ORDER BY r.reservation_date DESC, r.created_at DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span>My Reservations</span>
    </div>
    <h1>My Reservations</h1>
    <small>Track the status of your submitted bookings.</small>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType === 'error' ? 'error' : 'success'; ?>" style="background:<?= $messageType === 'error' ? '#fdecee;color:#b23030' : '#e8f5e9;color:#2e7d32'; ?>;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Filters Section -->
<div class="filters-container">
    <div class="filters-row">
        <!-- Search Bar -->
        <div class="filter-item search-filter">
            <label for="searchInput">Search Facility</label>
            <input type="text" id="searchInput" placeholder="Search by facility name...">
        </div>
        
        <!-- Status Filter -->
        <div class="filter-item">
            <label for="statusFilter">Status</label>
            <select id="statusFilter">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="denied">Denied</option>
                <option value="cancelled">Cancelled</option>
                <option value="on_hold">On Hold</option>
                <option value="postponed">Postponed</option>
            </select>
        </div>
        
        <!-- Date Filter -->
        <div class="filter-item">
            <label for="dateFilter">Date Range</label>
            <select id="dateFilter">
                <option value="">All Dates</option>
                <option value="upcoming">Upcoming</option>
                <option value="past">Past</option>
                <option value="custom">Custom Range</option>
            </select>
        </div>
        
        <!-- Clear Filters Button -->
        <div class="filter-item filter-actions">
            <button type="button" class="btn-outline" id="clearFilters">
                Clear Filters
            </button>
        </div>
    </div>
    
    <!-- Custom Date Range (hidden by default) -->
    <div class="custom-date-range" id="customDateRange" style="display: none;">
        <div class="filter-item">
            <label for="dateFrom">From</label>
            <input type="date" id="dateFrom">
        </div>
        <div class="filter-item">
            <label for="dateTo">To</label>
            <input type="date" id="dateTo">
        </div>
    </div>
    
    <!-- Results Count -->
    <div class="filter-results">
        <span id="resultsCount">Showing all reservations</span>
    </div>
</div>

<?php if (empty($reservations)): ?>
    <p>You have not submitted any reservations yet.</p>
<?php else: ?>
    <?php foreach ($reservations as $reservation): ?>
        <?php
        $historyStmt = $pdo->prepare(
            'SELECT status, note, created_at FROM reservation_history WHERE reservation_id = :id ORDER BY created_at DESC'
        );
        $historyStmt->execute(['id' => $reservation['id']]);
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if reschedule is allowed
        $reservationDate = new DateTime($reservation['reservation_date']);
        $today = new DateTime('today');
        $currentDate = date('Y-m-d');
        $currentHour = (int)date('H');
        $daysUntil = $today->diff($reservationDate)->days;
        $rescheduleCount = (int)($reservation['reschedule_count'] ?? 0);
        
        // Check if reservation has started (ongoing)
        $isOngoing = false;
        if ($reservation['reservation_date'] < $currentDate) {
            $isOngoing = true;
        } elseif ($reservation['reservation_date'] === $currentDate) {
            if (strpos($reservation['time_slot'], 'Morning') !== false && $currentHour >= 12) {
                $isOngoing = true;
            } elseif (strpos($reservation['time_slot'], 'Afternoon') !== false && $currentHour >= 17) {
                $isOngoing = true;
            } elseif (strpos($reservation['time_slot'], 'Evening') !== false && $currentHour >= 21) {
                $isOngoing = true;
            }
        }
        
        $canReschedule = ($daysUntil >= 3) && $rescheduleCount < 1 && in_array($reservation['status'], ['pending', 'approved']) && !$isOngoing && ($reservation['facility_status'] ?? 'available') === 'available';
        ?>
        <article class="facility-card-admin" style="margin-bottom:1rem;">
            <header>
                <div>
                    <h3><?= htmlspecialchars($reservation['facility_name']); ?></h3>
                    <small><?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?></small>
                </div>
                <span class="status-badge <?= $reservation['status']; ?>"><?= ucfirst($reservation['status']); ?></span>
            </header>
            <?php if ($canReschedule): ?>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e0e6ed;">
                    <button class="btn-outline" onclick="openRescheduleModal(<?= $reservation['id']; ?>, '<?= htmlspecialchars($reservation['reservation_date']); ?>', '<?= htmlspecialchars($reservation['time_slot']); ?>', '<?= htmlspecialchars($reservation['facility_name']); ?>')" style="padding:0.5rem 1rem;">
                        Reschedule
                    </button>
                </div>
            <?php elseif ($isOngoing && in_array($reservation['status'], ['pending', 'approved'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #f8d7da; border-radius: 6px; border-left: 4px solid #dc3545;">
                    <small style="color: #721c24;">This reservation has already started and cannot be rescheduled.</small>
                </div>
            <?php elseif (!in_array($reservation['status'], ['pending', 'approved'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #f8d7da; border-radius: 6px; border-left: 4px solid #dc3545;">
                    <small style="color: #721c24;">
                        <?php if ($reservation['status'] === 'denied'): ?>
                            Rejected reservations cannot be rescheduled. Please create a new reservation request.
                        <?php elseif ($reservation['status'] === 'cancelled'): ?>
                            Cancelled reservations cannot be rescheduled. Please create a new reservation request.
                        <?php else: ?>
                            This reservation cannot be rescheduled due to its current status.
                        <?php endif; ?>
                    </small>
                </div>
            <?php elseif (($reservation['reschedule_count'] ?? 0) >= 1): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                    <small style="color: #856404;">This reservation has already been rescheduled once. Only one reschedule is allowed per reservation.</small>
                </div>
            <?php elseif ($daysUntil < 3 && in_array($reservation['status'], ['pending', 'approved'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                    <small style="color: #856404;">Rescheduling is only allowed up to 3 days before the event. (<?= $daysUntil; ?> day(s) remaining)</small>
                </div>
            <?php elseif (($reservation['facility_status'] ?? 'available') !== 'available' && in_array($reservation['status'], ['pending', 'approved'])): ?>
                <div style="margin-top: 1rem; padding: 0.75rem; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                    <small style="color: #856404;">The facility is currently <?= htmlspecialchars($reservation['facility_status']); ?> and rescheduling is not available. Please contact support.</small>
                </div>
            <?php endif; ?>
            <?php if ($history): ?>
                <ul class="timeline">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <strong><?= ucfirst($entry['status']); ?></strong>
                            <p style="margin:0;"><?= $entry['note'] ? htmlspecialchars($entry['note']) : 'No remarks.'; ?></p>
                            <small style="color:#8b95b5;"><?= htmlspecialchars($entry['created_at']); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No status updates recorded yet.</p>
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

<!-- Reschedule Modal -->
<div id="rescheduleModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Reschedule Reservation</h3>
            <button onclick="closeRescheduleModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="rescheduleForm">
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="reservation_id" id="reschedule_reservation_id">
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #e3f2fd; border-radius: 6px; border-left: 4px solid #2196f3;">
                <strong>ℹ️ Reschedule Policy:</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem; padding: 0; font-size: 0.9rem;">
                    <li>Rescheduling is allowed up to <strong>3 days before</strong> the event (same-day rescheduling is not allowed)</li>
                    <li>Only <strong>one reschedule</strong> per reservation</li>
                    <li><strong>Approved reservations</strong> will require re-approval after rescheduling</li>
                    <li>Reservations that have <strong>already started</strong> cannot be rescheduled</li>
                    <li>Rejected or cancelled reservations cannot be rescheduled</li>
                </ul>
            </div>
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #f5f5f5; border-radius: 6px;">
                <strong>Current Schedule:</strong>
                <div style="margin-top: 0.5rem;">
                    <span id="reschedule_current_schedule"></span>
                </div>
            </div>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Date <span style="color: #dc3545;">*</span>
            </label>
            <input type="date" name="new_date" id="reschedule_new_date" required min="<?= date('Y-m-d'); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                New Time Slot <span style="color: #dc3545;">*</span>
            </label>
            <select name="new_time_slot" id="reschedule_new_time_slot" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="">Select time slot...</option>
                <option value="Morning (8:00 AM - 12:00 PM)">Morning (8:00 AM - 12:00 PM)</option>
                <option value="Afternoon (1:00 PM - 5:00 PM)">Afternoon (1:00 PM - 5:00 PM)</option>
                <option value="Evening (6:00 PM - 10:00 PM)">Evening (6:00 PM - 10:00 PM)</option>
            </select>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason for Rescheduling <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="reschedule_reason" required placeholder="Enter the reason for rescheduling (e.g., schedule conflict, change of plans, etc.)" style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>
            
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeRescheduleModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Reschedule this reservation? Approved reservations will require re-approval." style="flex: 1;">Reschedule</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter and Search Functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const dateFilter = document.getElementById('dateFilter');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const customDateRange = document.getElementById('customDateRange');
    const clearFilters = document.getElementById('clearFilters');
    const resultsCount = document.getElementById('resultsCount');
    
    const reservationCards = document.querySelectorAll('.facility-card-admin');
    
    // Show/hide custom date range
    if (dateFilter) {
        dateFilter.addEventListener('change', function() {
            if (this.value === 'custom') {
                customDateRange.style.display = 'grid';
            } else {
                customDateRange.style.display = 'none';
            }
            applyFilters();
        });
    }
    
    // Apply filters on input
    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (statusFilter) statusFilter.addEventListener('change', applyFilters);
    if (dateFrom) dateFrom.addEventListener('change', applyFilters);
    if (dateTo) dateTo.addEventListener('change', applyFilters);
    
    // Clear all filters
    if (clearFilters) {
        clearFilters.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (dateFilter) dateFilter.value = '';
            if (dateFrom) dateFrom.value = '';
            if (dateTo) dateTo.value = '';
            if (customDateRange) customDateRange.style.display = 'none';
            applyFilters();
        });
    }
    
    function applyFilters() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const selectedStatus = statusFilter ? statusFilter.value.toLowerCase() : '';
        const selectedDateFilter = dateFilter ? dateFilter.value : '';
        const fromDate = dateFrom && dateFrom.value ? new Date(dateFrom.value) : null;
        const toDate = dateTo && dateTo.value ? new Date(dateTo.value) : null;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        let visibleCount = 0;
        
        reservationCards.forEach(card => {
            let show = true;
            
            // Search filter
            if (searchTerm) {
                const facilityNameEl = card.querySelector('h3');
                if (facilityNameEl) {
                    const facilityName = facilityNameEl.textContent.toLowerCase();
                    if (!facilityName.includes(searchTerm)) {
                        show = false;
                    }
                }
            }
            
            // Status filter
            if (selectedStatus) {
                const statusBadge = card.querySelector('.status-badge');
                if (statusBadge) {
                    const cardStatus = statusBadge.className.split(' ').find(c => c !== 'status-badge');
                    if (cardStatus && cardStatus.toLowerCase() !== selectedStatus) {
                        show = false;
                    }
                }
            }
            
            // Date filter
            if (selectedDateFilter) {
                const smallEl = card.querySelector('small');
                if (smallEl) {
                    const dateText = smallEl.textContent.split('•')[0].trim();
                    const reservationDate = new Date(dateText);
                    
                    if (selectedDateFilter === 'upcoming') {
                        if (reservationDate < today) show = false;
                    } else if (selectedDateFilter === 'past') {
                        if (reservationDate >= today) show = false;
                    } else if (selectedDateFilter === 'custom') {
                        if (fromDate && reservationDate < fromDate) show = false;
                        if (toDate && reservationDate > toDate) show = false;
                    }
                }
            }
            
            // Show/hide card
            card.style.display = show ? 'block' : 'none';
            if (show) visibleCount++;
        });
        
        // Update results count
        const totalCount = reservationCards.length;
        if (resultsCount) {
            if (visibleCount === totalCount) {
                resultsCount.textContent = `Showing all ${totalCount} reservation${totalCount !== 1 ? 's' : ''}`;
            } else {
                resultsCount.textContent = `Showing ${visibleCount} of ${totalCount} reservation${totalCount !== 1 ? 's' : ''}`;
            }
        }
    }
    
    // Initial count
    if (resultsCount && reservationCards.length > 0) {
        resultsCount.textContent = `Showing all ${reservationCards.length} reservation${reservationCards.length !== 1 ? 's' : ''}`;
    }
});

// Reschedule Modal Functions
function openRescheduleModal(reservationId, currentDate, currentTime, facilityName) {
    document.getElementById('reschedule_reservation_id').value = reservationId;
    document.getElementById('reschedule_current_schedule').textContent = facilityName + ' on ' + currentDate + ' (' + currentTime + ')';
    document.getElementById('reschedule_new_date').value = '';
    document.getElementById('reschedule_new_time_slot').value = '';
    document.getElementById('reschedule_reason').value = '';
    document.getElementById('rescheduleModal').style.display = 'flex';
}

function closeRescheduleModal() {
    document.getElementById('rescheduleModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('rescheduleModal').addEventListener('click', function(e) {
    if (e.target === this) closeRescheduleModal();
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
