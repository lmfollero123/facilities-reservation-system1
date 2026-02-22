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
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/reservation_helpers.php';
$pdo = db();

// Auto-decline expired pending reservations before querying
autoDeclineExpiredReservations();

$pageTitle = 'My Reservations | LGU Facilities Reservation';
$userId = $_SESSION['user_id'] ?? null;
$message = '';
$messageType = 'success';

// Handle edit details (purpose, expected_attendees) - no approval unless capacity exceeded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_details' && $userId) {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $newPurpose = trim($_POST['purpose'] ?? '');
    $newAttendees = isset($_POST['expected_attendees']) && $_POST['expected_attendees'] !== '' ? (int)$_POST['expected_attendees'] : null;
    
    try {
        if (empty($newPurpose)) {
            throw new Exception('Purpose is required.');
        }
        if ($newAttendees !== null && $newAttendees < 0) {
            $newAttendees = null;
        }
        
        $resStmt = $pdo->prepare(
            'SELECT r.id, r.purpose, r.expected_attendees, r.status, r.facility_id, f.name AS facility_name, f.capacity_threshold
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             WHERE r.id = :id AND r.user_id = :user_id'
        );
        $resStmt->execute(['id' => $reservationId, 'user_id' => $userId]);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            throw new Exception('Reservation not found or you do not have permission to edit it.');
        }
        
        if (!in_array($reservation['status'], ['pending', 'approved', 'postponed'], true)) {
            throw new Exception('Only pending, approved, or postponed reservations can be edited.');
        }
        
        $capacityThreshold = isset($reservation['capacity_threshold']) && $reservation['capacity_threshold'] !== null && $reservation['capacity_threshold'] !== '' ? (int)$reservation['capacity_threshold'] : null;
        $requiresReapproval = ($capacityThreshold !== null && $newAttendees !== null && $newAttendees > $capacityThreshold);
        
        $newStatus = ($requiresReapproval && $reservation['status'] === 'approved') ? 'pending' : $reservation['status'];
        
        $updateStmt = $pdo->prepare('UPDATE reservations SET purpose = :purpose, expected_attendees = :expected_attendees, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $updateStmt->execute([
            'purpose' => $newPurpose,
            'expected_attendees' => $newAttendees,
            'status' => $newStatus,
            'id' => $reservationId
        ]);
        
        $historyNote = 'Purpose and/or expected attendees updated by user.';
        if ($requiresReapproval) {
            $historyNote .= ' Re-approval required: attendee count (' . $newAttendees . ') exceeds facility threshold (' . $capacityThreshold . ').';
        }
        
        $histStmt = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:reservation_id, :status, :note, :user_id)');
        $histStmt->execute([
            'reservation_id' => $reservationId,
            'status' => $newStatus,
            'note' => $historyNote,
            'user_id' => $userId
        ]);
        
        logAudit('Edited reservation details (purpose/attendees)', 'Reservations', 'RES-' . $reservationId . ' – ' . $reservation['facility_name']);
        
        $message = 'Details updated successfully.' . ($requiresReapproval ? ' Re-approval is required because attendee count exceeds facility capacity threshold.' : '');
        $messageType = 'success';
        
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Handle reschedule request (conceptually: user requests; staff applies changes upon approval)
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
        
        if ($newDate === $reservation['reservation_date'] && $newTimeSlot === $reservation['time_slot']) {
            throw new Exception('You are already scheduled for this date and time. Please select a different date or time slot to reschedule.');
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
             AND status IN ("pending", "approved", "postponed")
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
        logAudit('Requested reschedule (own reservation)', 'Reservations', $auditDetails);
        
        // Create notification
        $notifMessage = 'Your reservation for ' . $reservation['facility_name'];
        $notifMessage .= ' has been rescheduled from ' . date('F j, Y', strtotime($oldDate)) . ' (' . $oldTimeSlot . ')';
        $notifMessage .= ' to ' . date('F j, Y', strtotime($newDate)) . ' (' . $newTimeSlot . ').';
        if ($oldStatus === 'approved') {
            $notifMessage .= ' The new date requires re-approval.';
        }
        
        createNotification($userId, 'booking', 'Reschedule Request Submitted', $notifMessage, 
            base_path() . '/resources/views/pages/dashboard/my_reservations.php');
        
        // Send email notification
        $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userInfo && !empty($userInfo['email'])) {
            $requiresApproval = ($oldStatus === 'approved' || $oldStatus === 'postponed');
            $emailBody = getReservationRescheduledEmailTemplate(
                $userInfo['name'],
                $reservation['facility_name'],
                $oldDate,
                $oldTimeSlot,
                $newDate,
                $newTimeSlot,
                $reason,
                $requiresApproval
            );
            sendEmail($userInfo['email'], $userInfo['name'], 'Reschedule Request Submitted', $emailBody);
        }
        
        $message = 'Reschedule request submitted successfully. ' . ($oldStatus === 'approved' || $oldStatus === 'postponed' ? 'Staff will review and apply changes upon approval.' : '');
        $messageType = 'success';
        
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Resident self-cancellation: only for own reservations, status pending/approved, and before start time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_reservation' && $userId) {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    
    try {
        $resStmt = $pdo->prepare(
            'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.user_id,
                    f.name AS facility_name
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             WHERE r.id = :id AND r.user_id = :user_id'
        );
        $resStmt->execute(['id' => $reservationId, 'user_id' => $userId]);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            throw new Exception('Reservation not found or you do not have permission to cancel it.');
        }
        
        if (!in_array($reservation['status'], ['pending', 'approved'], true)) {
            throw new Exception('Only pending or approved reservations can be cancelled. This reservation is already ' . $reservation['status'] . '.');
        }
        
        $currentDate = date('Y-m-d');
        $currentHour = (int)date('H');
        $isPast = false;
        if ($reservation['reservation_date'] < $currentDate) {
            $isPast = true;
        } elseif ($reservation['reservation_date'] === $currentDate) {
            if (strpos($reservation['time_slot'], 'Morning') !== false && $currentHour >= 12) $isPast = true;
            elseif (strpos($reservation['time_slot'], 'Afternoon') !== false && $currentHour >= 17) $isPast = true;
            elseif (strpos($reservation['time_slot'], 'Evening') !== false && $currentHour >= 21) $isPast = true;
        }
        
        if ($isPast) {
            throw new Exception('You cannot cancel a reservation that has already started or passed.');
        }
        
        $stmt = $pdo->prepare('UPDATE reservations SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['status' => 'cancelled', 'id' => $reservationId]);
        
        $note = 'Cancelled by resident (self-cancellation).';
        $histStmt = $pdo->prepare('INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (:reservation_id, :status, :note, :user_id)');
        $histStmt->execute([
            'reservation_id' => $reservationId,
            'status' => 'cancelled',
            'note' => $note,
            'user_id' => $userId
        ]);
        
        logAudit('Resident self-cancelled reservation', 'Reservations', 'RES-' . $reservationId . ' – ' . $reservation['facility_name']);
        
        createNotification(
            $userId,
            'booking',
            'Reservation Cancelled',
            'Your reservation for ' . $reservation['facility_name'] . ' on ' . date('F j, Y', strtotime($reservation['reservation_date'])) . ' (' . $reservation['time_slot'] . ') has been cancelled. The time slot is now available for others.',
            base_path() . '/dashboard/my-reservations'
        );
        
        $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($userInfo && !empty($userInfo['email'])) {
            $emailBody = getReservationCancelledEmailTemplate(
                $userInfo['name'],
                $reservation['facility_name'],
                $reservation['reservation_date'],
                $reservation['time_slot'],
                'Cancelled by you. The time slot is now available for others.',
                'View My Reservations',
                base_url() . '/dashboard/my-reservations'
            );
            sendEmail($userInfo['email'], $userInfo['name'], 'Reservation Cancelled', $emailBody);
        }
        
        $message = 'Reservation cancelled successfully. The time slot is now available for others.';
        $messageType = 'success';
        
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Tab: upcoming (default) | past
$tab = isset($_GET['tab']) && $_GET['tab'] === 'past' ? 'past' : 'upcoming';
$statusFilter = $_GET['status'] ?? '';
$searchQuery = $_GET['search'] ?? '';

// Pagination settings - 6 cards per page for grid layout
$perPage = 6;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$reservations = [];
$totalRows = 0;
$today = date('Y-m-d');

if ($userId) {
    // Build WHERE clause: tab determines date filter
    $whereConditions = ['r.user_id = :user_id'];
    $params = ['user_id' => $userId];
    
    // Tab-based date filter: upcoming = date >= today, past = date < today
    if ($tab === 'upcoming') {
        $whereConditions[] = 'r.reservation_date >= :today';
        $whereConditions[] = "r.status != 'cancelled'"; // Exclude cancelled from upcoming tab
        $params['today'] = $today;
        $orderBy = 'r.reservation_date ASC, r.created_at ASC'; // Soonest first
    } else {
        $whereConditions[] = 'r.reservation_date < :today';
        $params['today'] = $today;
        $orderBy = 'r.reservation_date DESC, r.created_at DESC'; // Most recent first
    }
    
    // Status filter (optional, within tab)
    if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'denied', 'cancelled', 'on_hold', 'postponed'])) {
        $whereConditions[] = 'r.status = :status';
        $params['status'] = $statusFilter;
    }
    
    // Search filter
    if ($searchQuery) {
        $whereConditions[] = 'f.name LIKE :search';
        $params['search'] = '%' . $searchQuery . '%';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Count total matching reservations
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations r JOIN facilities f ON r.facility_id = f.id WHERE $whereClause");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    
    // Get paginated reservations
    $stmt = $pdo->prepare(
        "SELECT r.id, r.reservation_date, r.time_slot, r.purpose, r.expected_attendees, r.status, r.reschedule_count, r.facility_id, f.name AS facility_name, f.status AS facility_status, f.capacity_threshold, f.operating_hours
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         WHERE $whereClause
         ORDER BY $orderBy
         LIMIT :limit OFFSET :offset"
    );
    
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
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

<!-- Tab Buttons: Upcoming | Past -->
<div class="my-reservations-tabs" style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color, #e5e7eb); padding-bottom: 0;">
    <a href="?tab=upcoming<?= $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" 
       class="my-reservations-tab <?= $tab === 'upcoming' ? 'active' : ''; ?>" 
       style="padding: 0.75rem 1.5rem; font-weight: 600; text-decoration: none; color: var(--text-secondary, #6b7280); border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s ease;">
        Upcoming Reservations
    </a>
    <a href="?tab=past<?= $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" 
       class="my-reservations-tab <?= $tab === 'past' ? 'active' : ''; ?>" 
       style="padding: 0.75rem 1.5rem; font-weight: 600; text-decoration: none; color: var(--text-secondary, #6b7280); border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s ease;">
        Past Reservations
    </a>
</div>
<style>
.my-reservations-tab.active { color: var(--gov-blue, #2563eb) !important; border-bottom-color: var(--gov-blue, #2563eb) !important; }
.my-reservations-tab:hover { color: var(--gov-blue, #2563eb) !important; }
[data-theme="dark"] .my-reservations-tab.active { color: #60a5fa !important; border-bottom-color: #60a5fa !important; }
[data-theme="dark"] .my-reservations-tab:hover { color: #93c5fd !important; }
.reservation-card-highlight { border-left: 4px solid #059669 !important; background: rgba(5, 150, 105, 0.08) !important; }
[data-theme="dark"] .reservation-card-highlight { border-left-color: #34d399 !important; background: rgba(52, 211, 153, 0.12) !important; }
</style>

<!-- Filters Section -->
<form method="GET" class="filters-container" id="filtersForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab); ?>">
    <div class="filters-row">
        <div class="filter-item search-filter">
            <label for="searchInput">Search Facility</label>
            <input type="text" name="search" id="searchInput" placeholder="Search by facility name..." value="<?= htmlspecialchars($searchQuery); ?>">
        </div>
        <div class="filter-item">
            <label for="statusFilter">Status</label>
            <select name="status" id="statusFilter">
                <option value="">All Statuses</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="denied" <?= $statusFilter === 'denied' ? 'selected' : ''; ?>>Denied</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                <option value="on_hold" <?= $statusFilter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                <option value="postponed" <?= $statusFilter === 'postponed' ? 'selected' : ''; ?>>Postponed</option>
            </select>
        </div>
        <div class="filter-item filter-actions">
            <button type="submit" class="btn-primary" style="padding: 0.5rem 1rem;">Apply Filters</button>
            <a href="?tab=<?= htmlspecialchars($tab); ?>" class="btn-outline" id="clearFilters" style="padding: 0.5rem 1rem; text-decoration: none; display: inline-block;">Clear Filters</a>
        </div>
    </div>
    <div class="filter-results">
        <span id="resultsCount">
            <?php if ($totalRows > 0): ?>
                Showing <?= count($reservations); ?> of <?= $totalRows; ?> reservation<?= $totalRows !== 1 ? 's' : ''; ?>
                <?php if ($statusFilter || $searchQuery): ?>(filtered)<?php endif; ?>
            <?php else: ?>
                No reservations found
            <?php endif; ?>
        </span>
    </div>
</form>

<?php if (empty($reservations)): ?>
    <div style="text-align: center; padding: 4rem 2rem; background: var(--bg-secondary); border-radius: 12px; border: 2px dashed var(--border-color);">
        <div style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.5;">📋</div>
        <h2 style="font-size: 1.5rem; margin-bottom: 0.5rem; color: var(--text-primary);">
            <?= $tab === 'upcoming' ? 'No Upcoming Reservations' : 'No Past Reservations'; ?>
        </h2>
        <p style="font-size: 1.125rem; color: var(--text-secondary); margin-bottom: 2rem;">
            <?= $tab === 'upcoming' ? 'You have no upcoming reservations. Book a facility to get started.' : 'Your past reservations will appear here.'; ?>
        </p>
        <?php if ($tab === 'upcoming'): ?>
        <a href="<?= base_path(); ?>/dashboard/book-facility" class="btn-primary" style="padding: 1rem 2rem; font-size: 1.125rem; display: inline-block; text-decoration: none;">
            Book a Facility
        </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Reservation Cards - Elderly-Friendly Design -->
    <div class="reservations-grid">
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
            
            $canReschedule = ($daysUntil >= 3) && $rescheduleCount < 1 && in_array($reservation['status'], ['pending', 'approved', 'postponed']) && !$isOngoing && ($reservation['facility_status'] ?? 'available') === 'available';
            
            // Resident can cancel own reservation only when: status pending/approved, and before start time
            $canCancel = in_array($reservation['status'], ['pending', 'approved'], true) && !$isOngoing;
            
            // Highlight: within 1 day (today or tomorrow) for upcoming tab
            $withinOneDay = ($tab === 'upcoming' && $daysUntil <= 1 && !$isOngoing);
            
            // Status icon and color
            $statusIcons = [
                'pending' => '⏳',
                'approved' => '✅',
                'denied' => '❌',
                'cancelled' => '🚫',
                'on_hold' => '⏸️',
                'postponed' => '📅'
            ];
            $statusIcon = $statusIcons[$reservation['status']] ?? '📋';
            ?>
            
            <article class="reservation-card-modern facility-card-admin<?= $withinOneDay ? ' reservation-card-highlight' : ''; ?>" data-reservation-id="<?= $reservation['id']; ?>">
                <!-- Card Header -->
                <div class="reservation-card-header">
                    <div class="reservation-main-info">
                        <div class="facility-icon">📍</div>
                        <div class="facility-details">
                            <h3 class="facility-name-large"><?= htmlspecialchars($reservation['facility_name']); ?></h3>
                            <div class="reservation-datetime">
                                <span class="date-info">📅 <?= date('F j, Y', strtotime($reservation['reservation_date'])); ?></span>
                                <span class="time-info">🕐 <?= htmlspecialchars($reservation['time_slot']); ?></span>
                                <?php if ($withinOneDay): ?>
                                <span class="highlight-badge" style="display: inline-block; margin-left: 0.5rem; padding: 0.15rem 0.5rem; background: #059669; color: white; font-size: 0.75rem; font-weight: 600; border-radius: 4px;">Within 1 day</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="status-badge-large status-badge <?= $reservation['status']; ?>">
                        <span class="status-icon"><?= $statusIcon; ?></span>
                        <span class="status-text"><?= ucfirst(str_replace('_', ' ', $reservation['status'])); ?></span>
                    </div>
                </div>
                
                <!-- Expandable Details Section -->
                <details class="reservation-details-section">
                    <summary class="details-toggle">
                        <span class="toggle-text">View Details</span>
                        <span class="toggle-icon">▼</span>
                    </summary>
                    
                    <div class="details-content">
                        <?php if ($history): ?>
                            <div class="timeline-section">
                                <h4 class="section-title">📜 Status History</h4>
                                <ul class="timeline-modern">
                                    <?php foreach ($history as $entry): ?>
                                        <li class="timeline-item">
                                            <div class="timeline-marker"></div>
                                            <div class="timeline-content">
                                                <strong class="timeline-status"><?= ucfirst($entry['status']); ?></strong>
                                                <p class="timeline-note"><?= $entry['note'] ? htmlspecialchars($entry['note']) : 'No remarks provided.'; ?></p>
                                                <small class="timeline-date"><?= date('M d, Y g:i A', strtotime($entry['created_at'])); ?></small>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <p class="no-history">No status updates recorded yet.</p>
                        <?php endif; ?>
                    </div>
                </details>
                
                <!-- Action Buttons Section -->
                <div class="reservation-actions">
                    <?php 
                    $canEditDetails = in_array($reservation['status'], ['pending', 'approved', 'postponed']) && !$isOngoing;
                    if ($canCancel): ?>
                        <button type="button" class="btn-action btn-outline" style="border-color: #dc3545; color: #dc3545; margin-right: 0.5rem; margin-bottom: 0.5rem;" onclick="openCancelReservationModal(<?= (int)$reservation['id']; ?>, '<?= htmlspecialchars($reservation['facility_name'], ENT_QUOTES, 'UTF-8'); ?>', '<?= date('F j, Y', strtotime($reservation['reservation_date'])); ?>', '<?= htmlspecialchars($reservation['time_slot'], ENT_QUOTES, 'UTF-8'); ?>');">
                            <span class="btn-icon">🚫</span>
                            <span class="btn-text">Cancel Reservation</span>
                        </button>
                    <?php endif; ?>
                    <?php if ($canReschedule): ?>
                        <button class="btn-action btn-primary-large" onclick="openRescheduleModal(this)" data-id="<?= (int)$reservation['id']; ?>" data-facility-id="<?= (int)$reservation['facility_id']; ?>" data-date="<?= htmlspecialchars($reservation['reservation_date']); ?>" data-time="<?= htmlspecialchars($reservation['time_slot'], ENT_QUOTES, 'UTF-8'); ?>" data-facility="<?= htmlspecialchars($reservation['facility_name'], ENT_QUOTES, 'UTF-8'); ?>" data-operating-hours="<?= htmlspecialchars($reservation['operating_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="btn-icon">📅</span>
                            <span class="btn-text">Request Reschedule</span>
                        </button>
                    <?php endif; ?>
                    <?php if ($canEditDetails): ?>
                        <button class="btn-action btn-outline" onclick="openEditDetailsModal(this)" data-id="<?= (int)$reservation['id']; ?>" data-purpose="<?= htmlspecialchars($reservation['purpose'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-attendees="<?= htmlspecialchars((string)($reservation['expected_attendees'] ?? '')); ?>" data-capacity-threshold="<?= (int)($reservation['capacity_threshold'] ?? 0); ?>" style="margin-top: 0.5rem;">
                            <span class="btn-icon">✏️</span>
                            <span class="btn-text">Edit Purpose / Attendees</span>
                        </button>
                    <?php endif; ?>
                    <?php if ($isOngoing && in_array($reservation['status'], ['pending', 'approved'])): ?>
                        <div class="info-message info-error">
                            <span class="info-icon">⚠️</span>
                            <span class="info-text">This reservation has already started and cannot be rescheduled.</span>
                        </div>
                    <?php elseif (!in_array($reservation['status'], ['pending', 'approved', 'postponed'])): ?>
                        <div class="info-message info-error">
                            <span class="info-icon">ℹ️</span>
                            <span class="info-text">
                                <?php if ($reservation['status'] === 'denied'): ?>
                                    Rejected reservations cannot be rescheduled. Please create a new reservation request.
                                <?php elseif ($reservation['status'] === 'cancelled'): ?>
                                    Cancelled reservations cannot be rescheduled. Please create a new reservation request.
                                <?php else: ?>
                                    This reservation cannot be rescheduled due to its current status.
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php elseif (($reservation['reschedule_count'] ?? 0) >= 1): ?>
                        <div class="info-message info-warning">
                            <span class="info-icon">⚠️</span>
                            <span class="info-text">This reservation has already been rescheduled once. Only one reschedule is allowed per reservation.</span>
                        </div>
                    <?php elseif ($daysUntil < 3 && in_array($reservation['status'], ['pending', 'approved', 'postponed'])): ?>
                        <div class="info-message info-warning">
                            <span class="info-icon">⏰</span>
                            <span class="info-text">Rescheduling is only allowed up to 3 days before the event. (<?= $daysUntil; ?> day(s) remaining)</span>
                        </div>
                    <?php elseif (($reservation['facility_status'] ?? 'available') !== 'available' && in_array($reservation['status'], ['pending', 'approved', 'postponed'])): ?>
                        <div class="info-message info-warning">
                            <span class="info-icon">🔧</span>
                            <span class="info-text">The facility is currently <?= htmlspecialchars($reservation['facility_status']); ?> and rescheduling is not available. Please contact support.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <?php
        // Build query string for pagination links
        $queryParams = ['tab' => $tab];
        if ($statusFilter) $queryParams['status'] = $statusFilter;
        if ($searchQuery) $queryParams['search'] = $searchQuery;
        $queryString = http_build_query($queryParams);
        $separator = '&';
        ?>
        <div class="pagination-modern">
            <?php if ($page > 1): ?>
                <a href="?<?= $queryString . $separator; ?>page=<?= $page - 1; ?>" class="pagination-btn pagination-prev">
                    <span class="pagination-icon">←</span>
                    <span class="pagination-text">Previous</span>
                </a>
            <?php endif; ?>
            
            <span class="pagination-info">
                Page <strong><?= $page; ?></strong> of <strong><?= $totalPages; ?></strong>
            </span>
            
            <?php if ($page < $totalPages): ?>
                <a href="?<?= $queryString . $separator; ?>page=<?= $page + 1; ?>" class="pagination-btn pagination-next">
                    <span class="pagination-text">Next</span>
                    <span class="pagination-icon">→</span>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Cancel Reservation Confirmation Modal -->
<div id="cancelReservationModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: var(--bg-primary); border-radius: 8px; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="color: var(--text-primary); margin: 0;">Cancel Reservation</h3>
            <button type="button" onclick="closeCancelReservationModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        <p id="cancelReservationSummary" style="color: var(--text-secondary); margin-bottom: 1rem;"></p>
        <div style="margin-bottom: 1rem; padding: 1rem; background: var(--bg-secondary); border-radius: 6px; border-left: 4px solid #f59e0b;">
            <strong style="color: var(--text-primary);">⚠️ Cancellation policy</strong>
            <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0; font-size: 0.9rem; color: var(--text-secondary);">
                <li>You can only cancel <strong>upcoming</strong> reservations (pending or approved).</li>
                <li>Once cancelled, the time slot becomes available for others.</li>
                <li>Past or already-started reservations cannot be cancelled.</li>
            </ul>
        </div>
        <form method="POST" id="cancelReservationForm">
            <input type="hidden" name="action" value="cancel_reservation">
            <input type="hidden" name="reservation_id" id="cancel_reservation_id">
            <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                <button type="button" class="btn-outline" onclick="closeCancelReservationModal()" style="flex: 1;">Keep Reservation</button>
                <button type="submit" class="btn-primary" style="flex: 1; background: #dc3545; border-color: #dc3545;">Cancel Reservation</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Purpose / Attendees Modal -->
<div id="editDetailsModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Purpose / Attendees</h3>
            <button onclick="closeEditDetailsModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="editDetailsForm" data-capacity-threshold="0">
            <input type="hidden" name="action" value="edit_details">
            <input type="hidden" name="reservation_id" id="edit_details_reservation_id">
            <div style="margin-bottom: 1rem; padding: 1rem; background: #e8f5e9; border-radius: 6px; border-left: 4px solid #4caf50;">
                <strong>ℹ️ No approval needed</strong> unless attendee count exceeds the facility&apos;s capacity threshold.
            </div>
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Purpose / Event Description <span style="color: #dc3545;">*</span></label>
            <textarea name="purpose" id="edit_details_purpose" required placeholder="Purpose or event description" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem; min-height: 80px; font-family: inherit; resize: vertical;"></textarea>
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Expected Attendees</label>
            <input type="number" name="expected_attendees" id="edit_details_expected_attendees" min="0" placeholder="Optional" style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
            <small id="edit_details_capacity_warning" style="display: none; color: #f59e0b; margin-top: -0.5rem; margin-bottom: 1rem; font-size: 0.85rem;"></small>
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeEditDetailsModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary" style="flex: 1;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Request Reschedule Modal -->
<div id="rescheduleModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Request Reschedule</h3>
            <button onclick="closeRescheduleModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #8b95b5;">&times;</button>
        </div>
        <form method="POST" id="rescheduleForm">
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="reservation_id" id="reschedule_reservation_id">
            <input type="hidden" id="reschedule_facility_id" value="">
            <input type="hidden" id="reschedule_current_date" value="">
            <input type="hidden" id="reschedule_current_time_slot" value="">
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: #e3f2fd; border-radius: 6px; border-left: 4px solid #2196f3;">
                <strong>ℹ️ Request Reschedule Policy:</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem; padding: 0; font-size: 0.9rem;">
                    <li>Requests allowed up to <strong>3 days before</strong> the event (same-day not allowed)</li>
                    <li>Only <strong>one reschedule request</strong> per reservation</li>
                    <li>Staff will <strong>review and apply changes</strong> upon approval</li>
                    <li>Approved/postponed reservations will require re-approval after staff applies the change</li>
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
                Start Time <span style="color: #dc3545;">*</span>
            </label>
            <select name="start_time" id="reschedule_start_time" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="">Select start time...</option>
                <?php for ($hour = 8; $hour <= 21; $hour++): for ($minute = 0; $minute < 60; $minute += 30): if ($hour == 21 && $minute > 0) break; $tv = sprintf('%02d:%02d', $hour, $minute); $td = date('g:i A', strtotime($tv)); ?>
                <option value="<?= $tv; ?>"><?= $td; ?></option>
                <?php endfor; endfor; ?>
            </select>
            <small id="reschedule_start_help" style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:-0.5rem; margin-bottom:1rem;">Facility operating hours: 8:00 AM - 9:00 PM</small>
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                End Time <span style="color: #dc3545;">*</span>
            </label>
            <select name="end_time" id="reschedule_end_time" required style="width: 100%; padding: 0.5rem; border: 1px solid #e0e6ed; border-radius: 6px; margin-bottom: 1rem;">
                <option value="">Select end time...</option>
                <?php for ($hour = 8; $hour <= 21; $hour++): for ($minute = 0; $minute < 60; $minute += 30): if ($hour == 21 && $minute > 0) break; $tv = sprintf('%02d:%02d', $hour, $minute); $td = date('g:i A', strtotime($tv)); ?>
                <option value="<?= $tv; ?>"><?= $td; ?></option>
                <?php endfor; endfor; ?>
            </select>
            <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:-0.5rem; margin-bottom:1rem;">Must be after start time. Minimum 30 minutes.</small>
            <input type="hidden" name="new_time_slot" id="reschedule_new_time_slot">
            
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                Reason for Reschedule Request <span style="color: #dc3545;">*</span>
            </label>
            <textarea name="reason" id="reschedule_reason" required placeholder="Enter the reason for your reschedule request (e.g., schedule conflict, change of plans, etc.)" style="width: 100%; padding: 0.75rem; border: 1px solid #e0e6ed; border-radius: 6px; min-height: 100px; font-family: inherit; resize: vertical;"></textarea>
            
            <div id="reschedule-conflict-warning" style="display:none; border-radius:8px; padding:1rem; margin-top:1rem; transition: all 0.3s ease;">
                <div id="reschedule-conflict-header" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                    <span id="reschedule-conflict-icon" style="font-size:1.2rem;">⏳</span>
                    <h4 id="reschedule-conflict-title" style="margin:0; font-size:0.95rem;">Checking Availability...</h4>
                </div>
                <p id="reschedule-conflict-message" style="margin:0 0 0.75rem; font-size:0.85rem;"></p>
                <div id="reschedule-conflict-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; font-size:0.85rem; font-weight:600;">Alternative time slots:</p>
                    <ul id="reschedule-alternatives-list" style="margin:0; padding-left:1.25rem; font-size:0.85rem;"></ul>
                </div>
                <p id="reschedule-conflict-risk" style="margin:0; font-size:0.82rem; display:none;"></p>
            </div>
            
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-outline" onclick="closeRescheduleModal()" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn-primary confirm-action" data-message="Submit reschedule request? Staff will review and apply changes upon approval." style="flex: 1;">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
// Edit Details Modal
function openEditDetailsModal(btn) {
    const id = btn.getAttribute('data-id');
    const purpose = btn.getAttribute('data-purpose') || '';
    const attendees = btn.getAttribute('data-attendees') || '';
    const capacityThreshold = parseInt(btn.getAttribute('data-capacity-threshold') || '0', 10);
    document.getElementById('edit_details_reservation_id').value = id;
    document.getElementById('edit_details_purpose').value = purpose;
    document.getElementById('edit_details_expected_attendees').value = attendees;
    document.getElementById('editDetailsForm').setAttribute('data-capacity-threshold', capacityThreshold);
    document.getElementById('edit_details_capacity_warning').style.display = 'none';
    document.getElementById('editDetailsModal').style.display = 'flex';
}
function closeEditDetailsModal() {
    document.getElementById('editDetailsModal').style.display = 'none';
}
document.getElementById('edit_details_expected_attendees').addEventListener('input', function() {
    const threshold = parseInt(document.getElementById('editDetailsForm').getAttribute('data-capacity-threshold') || '0', 10);
    const val = parseInt(this.value, 10);
    const warn = document.getElementById('edit_details_capacity_warning');
    if (threshold > 0 && !isNaN(val) && val > threshold) {
        warn.textContent = 'Note: ' + val + ' attendees exceeds facility threshold (' + threshold + '). Re-approval will be required.';
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
});
document.getElementById('editDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditDetailsModal();
});

// Reschedule Modal - parse operating hours (same logic as book facility)
function parseOperatingHoursReschedule(operatingHours) {
    if (!operatingHours || operatingHours.trim() === '') return { start: 8, end: 21 };
    const hours = operatingHours.trim();
    const match24 = hours.match(/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/);
    if (match24) return { start: parseInt(match24[1]), end: parseInt(match24[3]) };
    const match12 = hours.match(/(\d{1,2}):(\d{2})\s*(AM|PM)\s*-\s*(\d{1,2}):(\d{2})\s*(AM|PM)/i);
    if (match12) {
        let sh = parseInt(match12[1]); const sp = match12[3].toUpperCase();
        let eh = parseInt(match12[4]); const ep = match12[6].toUpperCase();
        if (sp === 'PM' && sh !== 12) sh += 12; if (sp === 'AM' && sh === 12) sh = 0;
        if (ep === 'PM' && eh !== 12) eh += 12; if (ep === 'AM' && eh === 12) eh = 0;
        return { start: sh, end: eh };
    }
    return { start: 8, end: 21 };
}

function filterRescheduleTimeSlots(operatingHours) {
    const { start: openHour, end: closeHour } = parseOperatingHoursReschedule(operatingHours);
    const startSel = document.getElementById('reschedule_start_time');
    const endSel = document.getElementById('reschedule_end_time');
    const helpEl = document.getElementById('reschedule_start_help');
    [startSel, endSel].forEach((sel, idx) => {
        sel.querySelectorAll('option').forEach(opt => {
            if (opt.value === '') { opt.style.display = ''; opt.disabled = false; return; }
            const [hour, minute] = opt.value.split(':').map(Number);
            const isStart = idx === 0;
            const within = isStart ? (hour >= openHour && hour < closeHour) : (hour >= openHour && hour <= closeHour);
            opt.style.display = within ? '' : 'none';
            opt.disabled = !within;
        });
    });
    if (helpEl) {
        const fmt = h => { const p = h >= 12 ? 'PM' : 'AM'; const h12 = h > 12 ? h - 12 : (h === 0 ? 12 : h); return h12 + ':00 ' + p; };
        helpEl.textContent = 'Facility operating hours: ' + fmt(openHour) + ' - ' + fmt(closeHour);
    }
}

// Filter end time options to only allow times after start time (min 30 min duration) - same logic as book facility
function updateRescheduleEndTimeOptions() {
    const startSel = document.getElementById('reschedule_start_time');
    const endSel = document.getElementById('reschedule_end_time');
    if (!startSel || !endSel) return;
    const startTime = startSel.value;
    if (!startTime) {
        endSel.querySelectorAll('option').forEach(opt => { opt.disabled = false; });
        return;
    }
    const [startH, startM] = startTime.split(':').map(Number);
    const startMinutes = startH * 60 + startM;
    endSel.querySelectorAll('option').forEach(opt => {
        if (opt.value === '') { opt.disabled = false; return; }
        const [endH, endM] = opt.value.split(':').map(Number);
        const endMinutes = endH * 60 + endM;
        opt.disabled = endMinutes <= startMinutes;
    });
    const endVal = endSel.value;
    if (endVal) {
        const [endH, endM] = endVal.split(':').map(Number);
        if (endH * 60 + endM <= startMinutes) endSel.value = '';
    }
}

let rescheduleConflictCheckTimeout = null;

function openRescheduleModal(btn) {
    const id = btn.getAttribute('data-id');
    const facilityId = btn.getAttribute('data-facility-id') || '';
    const date = btn.getAttribute('data-date');
    const time = btn.getAttribute('data-time');
    const facility = btn.getAttribute('data-facility');
    const operatingHours = btn.getAttribute('data-operating-hours') || '';
    document.getElementById('reschedule_reservation_id').value = id;
    document.getElementById('reschedule_facility_id').value = facilityId;
    document.getElementById('reschedule_current_date').value = date;
    document.getElementById('reschedule_current_time_slot').value = time;
    document.getElementById('reschedule_current_schedule').textContent = facility + ' on ' + date + ' (' + time + ')';
    document.getElementById('reschedule_new_date').value = date;
    document.getElementById('reschedule_start_time').value = '';
    document.getElementById('reschedule_end_time').value = '';
    document.getElementById('reschedule_new_time_slot').value = '';
    document.getElementById('reschedule_reason').value = '';
    filterRescheduleTimeSlots(operatingHours);
    const timeMatch = time.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
    if (timeMatch) {
        const startVal = timeMatch[1].padStart(2,'0') + ':' + timeMatch[2];
        const endVal = timeMatch[3].padStart(2,'0') + ':' + timeMatch[4];
        const startOpt = document.querySelector('#reschedule_start_time option[value="' + startVal + '"]');
        const endOpt = document.querySelector('#reschedule_end_time option[value="' + endVal + '"]');
        if (startOpt && !startOpt.disabled) document.getElementById('reschedule_start_time').value = startVal;
        updateRescheduleEndTimeOptions();
        if (endOpt && !endOpt.disabled) document.getElementById('reschedule_end_time').value = endVal;
    }
    document.getElementById('rescheduleModal').style.display = 'flex';
    // Trigger conflict check after modal opens (debounced)
    if (rescheduleConflictCheckTimeout) clearTimeout(rescheduleConflictCheckTimeout);
    rescheduleConflictCheckTimeout = setTimeout(checkRescheduleConflict, 300);
}

function closeRescheduleModal() {
    document.getElementById('rescheduleModal').style.display = 'none';
}

function openCancelReservationModal(reservationId, facilityName, dateStr, timeSlot) {
    document.getElementById('cancel_reservation_id').value = reservationId;
    document.getElementById('cancelReservationSummary').textContent =
        'You are about to cancel: ' + facilityName + ' on ' + dateStr + ' (' + timeSlot + ').';
    document.getElementById('cancelReservationModal').style.display = 'flex';
}

function closeCancelReservationModal() {
    document.getElementById('cancelReservationModal').style.display = 'none';
}

function debounceRescheduleConflict() {
    if (rescheduleConflictCheckTimeout) clearTimeout(rescheduleConflictCheckTimeout);
    rescheduleConflictCheckTimeout = setTimeout(checkRescheduleConflict, 500);
}

async function checkRescheduleConflict() {
    rescheduleConflictCheckTimeout = null;
    const fid = document.getElementById('reschedule_facility_id')?.value;
    const date = document.getElementById('reschedule_new_date')?.value;
    const startTime = document.getElementById('reschedule_start_time')?.value;
    const endTime = document.getElementById('reschedule_end_time')?.value;
    const excludeId = document.getElementById('reschedule_reservation_id')?.value;
    const msgBox = document.getElementById('reschedule-conflict-warning');
    const msgText = document.getElementById('reschedule-conflict-message');
    const altWrap = document.getElementById('reschedule-conflict-alternatives');
    const altList = document.getElementById('reschedule-alternatives-list');
    const riskLine = document.getElementById('reschedule-conflict-risk');
    const conflictIcon = document.getElementById('reschedule-conflict-icon');
    const conflictTitle = document.getElementById('reschedule-conflict-title');

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
    if (riskLine) riskLine.style.display = 'none';

    const basePath = <?= json_encode(base_path()); ?>;
    try {
        let body = `facility_id=${encodeURIComponent(fid)}&date=${encodeURIComponent(date)}&time_slot=${encodeURIComponent(timeSlot)}`;
        if (excludeId) body += `&exclude_reservation_id=${encodeURIComponent(excludeId)}`;
        const resp = await fetch(basePath + '/resources/views/pages/dashboard/ai_conflict_check.php', {
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
            if (msgText) { msgText.style.color = '#856404'; msgText.textContent = 'Warning: ' + cnt + ' pending reservation(s) exist for this slot. You can still submit, but staff will approve only one based on priority.'; }
            if (conflictIcon) conflictIcon.textContent = '⚠️';
            if (conflictTitle) { conflictTitle.textContent = 'Warning - Pending Reservations'; conflictTitle.style.color = '#856404'; }
        } else {
            msgBox.style.background = '#e8f5e9';
            msgBox.style.border = '2px solid #0d7a43';
            if (msgText) { msgText.style.color = '#0d7a43'; msgText.textContent = '✓ This time slot is available for rescheduling!'; }
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

document.getElementById('reschedule_new_date').addEventListener('change', debounceRescheduleConflict);
document.getElementById('reschedule_start_time').addEventListener('change', function() {
    updateRescheduleEndTimeOptions();
    debounceRescheduleConflict();
});
document.getElementById('reschedule_end_time').addEventListener('change', function() {
    this.setCustomValidity('');
    debounceRescheduleConflict();
});

document.getElementById('rescheduleForm').addEventListener('submit', function(e) {
    const startVal = document.getElementById('reschedule_start_time').value;
    const endVal = document.getElementById('reschedule_end_time').value;
    const newDate = document.getElementById('reschedule_new_date').value;
    const currentDate = document.getElementById('reschedule_current_date').value;
    const currentTimeSlot = document.getElementById('reschedule_current_time_slot').value;
    const endTimeEl = document.getElementById('reschedule_end_time');

    if (startVal && endVal) {
        const [sh, sm] = startVal.split(':').map(Number);
        const [eh, em] = endVal.split(':').map(Number);
        const startM = sh * 60 + sm;
        const endM = eh * 60 + em;
        if (endM <= startM) {
            e.preventDefault();
            endTimeEl.setCustomValidity('End time must be after start time');
            endTimeEl.reportValidity();
            return false;
        }
        if (endM - startM < 30) {
            e.preventDefault();
            endTimeEl.setCustomValidity('Reservation must be at least 30 minutes');
            endTimeEl.reportValidity();
            return false;
        }
        const newTimeSlot = startVal + ' - ' + endVal;
        document.getElementById('reschedule_new_time_slot').value = newTimeSlot;

        // Block if rescheduling to the exact same date and time
        if (newDate === currentDate && newTimeSlot === currentTimeSlot) {
            e.preventDefault();
            endTimeEl.setCustomValidity('');
            alert('You are already scheduled for this date and time. Please select a different date or time slot to reschedule.');
            return false;
        }
        endTimeEl.setCustomValidity('');
    }
});

// Close modal when clicking outside
document.getElementById('rescheduleModal').addEventListener('click', function(e) {
    if (e.target === this) closeRescheduleModal();
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
