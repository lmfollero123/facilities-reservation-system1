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
require_once __DIR__ . '/../../../../config/notifications.php';

$pdo = db();
$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'Resident';
$pageTitle = 'Dashboard | LGU Facilities Reservation';

$statusFilter = '';
$facilityFilter = 0;
$startDateFilter = '';
$endDateFilter = '';

// Helper: shared conditions for filters
function applyFilters(array &$conditions, array &$params, string $statusFilter, int $facilityFilter, string $startDateFilter, string $endDateFilter, bool $requireDate = true, string $dateColumn = 'r.reservation_date'): void {
    if ($statusFilter) {
        $conditions[] = "LOWER(r.status) = :f_status";
        $params['f_status'] = $statusFilter;
    }
    if ($facilityFilter > 0) {
        $conditions[] = "r.facility_id = :f_facility";
        $params['f_facility'] = $facilityFilter;
    }
    if ($startDateFilter) {
        $conditions[] = "$dateColumn >= :f_start";
        $params['f_start'] = $startDateFilter;
    } elseif ($requireDate) {
        // default lower bound today for some queries
        $conditions[] = "$dateColumn >= :today";
    }
    if ($endDateFilter) {
        $conditions[] = "$dateColumn <= :f_end";
        $params['f_end'] = $endDateFilter;
    }
}

// Filters (Recent/Upcoming)
$allowedStatuses = ['approved','pending','denied','cancelled'];
if (isset($_GET['status']) && in_array(strtolower($_GET['status']), $allowedStatuses, true)) {
    $statusFilter = strtolower($_GET['status']);
}
if (isset($_GET['facility_id']) && ctype_digit((string)$_GET['facility_id'])) {
    $facilityFilter = (int)$_GET['facility_id'];
}
if (!empty($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])) {
    $startDateFilter = $_GET['start_date'];
}
if (!empty($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])) {
    $endDateFilter = $_GET['end_date'];
}

$today = date('Y-m-d');
$weekFromNow = date('Y-m-d', strtotime('+7 days'));

// Facility options for filter
$facilityOptionsStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name ASC');
$facilityOptions = $facilityOptionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming/reservations (filtered, up to 20)
// For Admin/Staff: show all upcoming reservations; For Residents: show only their own
if (in_array($userRole, ['Admin', 'Staff'])) {
    $whereUpcoming = [];
    $paramsUpcoming = [];
} else {
    $whereUpcoming = ['r.user_id = :user_id'];
    $paramsUpcoming = ['user_id' => $userId];
}

applyFilters($whereUpcoming, $paramsUpcoming, $statusFilter, $facilityFilter, $startDateFilter, $endDateFilter, true);
if (!$statusFilter) {
    // default to approved/pending if no status filter set
    $whereUpcoming[] = 'r.status IN ("approved","pending")';
}
// applyFilters added :today placeholder when no start date; ensure it's bound
if (!isset($paramsUpcoming['today']) && !$startDateFilter) {
    $paramsUpcoming['today'] = $today;
}

$upcomingSql = '
    SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name' . 
    (in_array($userRole, ['Admin', 'Staff']) ? ', u.name AS requester_name' : '') . '
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id' .
    (in_array($userRole, ['Admin', 'Staff']) ? ' JOIN users u ON r.user_id = u.id' : '') . '
    WHERE ' . (empty($whereUpcoming) ? '1=1' : implode(' AND ', $whereUpcoming)) . '
    ORDER BY r.reservation_date ASC
    LIMIT 20';

$upcomingStmt = $pdo->prepare($upcomingSql);
foreach ($paramsUpcoming as $k => $v) {
    $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $upcomingStmt->bindValue(':' . $k, $v, $type);
}
$upcomingStmt->execute();
$upcomingReservations = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
$upcomingCount = count($upcomingReservations);

// Get pending approvals (Admin/Staff only)
$pendingCount = 0;
$pendingReservations = [];
if (in_array($userRole, ['Admin', 'Staff'])) {
    $pendingStmt = $pdo->query(
        'SELECT COUNT(*) FROM reservations WHERE status = "pending"'
    );
    $pendingCount = (int)$pendingStmt->fetchColumn();
    
    $pendingListStmt = $pdo->query(
        'SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name, u.name AS requester_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         JOIN users u ON r.user_id = u.id
         WHERE r.status = "pending"
         ORDER BY r.created_at ASC
         LIMIT 5'
    );
    $pendingReservations = $pendingListStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get total reservations (global for Admin/Staff, user-specific for Residents)
if (in_array($userRole, ['Admin', 'Staff'])) {
    $totalResStmt = $pdo->query('SELECT COUNT(*) FROM reservations');
    $totalReservations = (int)$totalResStmt->fetchColumn();
    
    $approvedResStmt = $pdo->query('SELECT COUNT(*) FROM reservations WHERE status = "approved"');
    $approvedReservations = (int)$approvedResStmt->fetchColumn();
    
    $deniedResStmt = $pdo->query('SELECT COUNT(*) FROM reservations WHERE status = "denied"');
    $deniedReservations = (int)$deniedResStmt->fetchColumn();
    
    $cancelledResStmt = $pdo->query('SELECT COUNT(*) FROM reservations WHERE status = "cancelled"');
    $cancelledReservations = (int)$cancelledResStmt->fetchColumn();
    
    // Additional global statistics for Admin/Staff
    $totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Resident" AND status = "active"');
    $totalUsers = (int)$totalUsersStmt->fetchColumn();
    
    $totalFacilitiesStmt = $pdo->query('SELECT COUNT(*) FROM facilities WHERE status = "available"');
    $totalFacilities = (int)$totalFacilitiesStmt->fetchColumn();
    
    $activeUsersStmt = $pdo->query('SELECT COUNT(DISTINCT user_id) FROM reservations WHERE status IN ("approved", "pending")');
    $activeUsers = (int)$activeUsersStmt->fetchColumn();
    
    // Today's reservations
    $todayResStmt = $pdo->query('SELECT COUNT(*) FROM reservations WHERE reservation_date = CURDATE() AND status IN ("approved", "pending")');
    $todayReservations = (int)$todayResStmt->fetchColumn();
    
    // This week's reservations
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    $weekResStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE reservation_date BETWEEN :start AND :end AND status IN ("approved", "pending")');
    $weekResStmt->execute(['start' => $weekStart, 'end' => $weekEnd]);
    $weekReservations = (int)$weekResStmt->fetchColumn();
    
    // Approval rate calculation
    $approvalRate = $totalReservations > 0 
        ? round(($approvedReservations / $totalReservations) * 100, 1) 
        : 0;
    
    // Average reservations per user
    $avgReservationsPerUser = $totalUsers > 0 
        ? round($totalReservations / $totalUsers, 1) 
        : 0;
    
    // Expired pending (expiring soon - within 24 hours)
    $expiringSoonStmt = $pdo->query('SELECT COUNT(*) FROM reservations WHERE status = "pending" AND expires_at IS NOT NULL AND expires_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)');
    $expiringSoon = (int)$expiringSoonStmt->fetchColumn();
} else {
    // Resident view - user-specific data
    $totalResStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations WHERE user_id = :user_id'
    );
    $totalResStmt->execute(['user_id' => $userId]);
    $totalReservations = (int)$totalResStmt->fetchColumn();
    
    $approvedResStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations WHERE user_id = :user_id AND status = "approved"'
    );
    $approvedResStmt->execute(['user_id' => $userId]);
    $approvedReservations = (int)$approvedResStmt->fetchColumn();
    
    // Set defaults for non-admin users
    $deniedReservations = 0;
    $cancelledReservations = 0;
    $totalUsers = 0;
    $totalFacilities = 0;
    $activeUsers = 0;
    $todayReservations = 0;
    $weekReservations = 0;
    $approvalRate = 0;
    $avgReservationsPerUser = 0;
    $expiringSoon = 0;
}

// Get unread notifications
$unreadNotifications = getUnreadNotificationCount($userId);

// Check if user is verified
$userVerificationStmt = $pdo->prepare('SELECT is_verified FROM users WHERE id = :user_id');
$userVerificationStmt->execute(['user_id' => $userId]);
$isVerified = (bool)($userVerificationStmt->fetchColumn() ?? false);

// Get recent activity with pagination
$perPage = 10;
$recentPage = max(1, (int)($_GET['recent_page'] ?? 1));
$recentOffset = ($recentPage - 1) * $perPage;

$recentCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.user_id = :user_id'
);
$recentCountStmt->execute(['user_id' => $userId]);
$recentTotal = (int)$recentCountStmt->fetchColumn();
$recentTotalPages = max(1, (int)ceil($recentTotal / $perPage));

$recentStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name, r.created_at
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     WHERE r.user_id = :user_id
     ORDER BY r.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$recentStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$recentStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$recentStmt->bindValue(':offset', $recentOffset, PDO::PARAM_INT);
$recentStmt->execute();
$recentReservations = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Chart data: Monthly reservations (last 6 months)
$monthlyLabels = [];
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $monthLabel = date('M Y', strtotime("-$i months"));
    
    $monthlyLabels[] = $monthLabel;
    
    $cond = [];
    $params = [];
    if (in_array($userRole, ['Admin', 'Staff'])) {
        $cond[] = 'reservation_date >= :start AND reservation_date <= :end';
        $params['start'] = $monthStart;
        $params['end'] = $monthEnd;
        if ($statusFilter) { $cond[] = 'LOWER(status) = :f_status_m'; $params['f_status_m'] = $statusFilter; }
        if ($facilityFilter > 0) { $cond[] = 'facility_id = :f_facility_m'; $params['f_facility_m'] = $facilityFilter; }
    } else {
        $cond[] = 'user_id = :user_id';
        $params['user_id'] = $userId;
        $cond[] = 'reservation_date >= :start AND reservation_date <= :end';
        $params['start'] = $monthStart;
        $params['end'] = $monthEnd;
        if ($statusFilter) { $cond[] = 'LOWER(status) = :f_status_m'; $params['f_status_m'] = $statusFilter; }
        if ($facilityFilter > 0) { $cond[] = 'facility_id = :f_facility_m'; $params['f_facility_m'] = $facilityFilter; }
    }
    $sql = 'SELECT COUNT(*) FROM reservations WHERE ' . implode(' AND ', $cond);
    $monthStmt = $pdo->prepare($sql);
    $monthStmt->execute($params);
    $monthlyData[] = (int)$monthStmt->fetchColumn();
}

// Chart data: Status breakdown
$statusData = [];
if (in_array($userRole, ['Admin', 'Staff'])) {
    $cond = [];
    $params = [];
    if ($statusFilter) { $cond[] = 'LOWER(status) = :f_status_s'; $params['f_status_s'] = $statusFilter; }
    if ($facilityFilter > 0) { $cond[] = 'facility_id = :f_facility_s'; $params['f_facility_s'] = $facilityFilter; }
    if ($startDateFilter) { $cond[] = 'reservation_date >= :f_start_s'; $params['f_start_s'] = $startDateFilter; }
    if ($endDateFilter)   { $cond[] = 'reservation_date <= :f_end_s';   $params['f_end_s'] = $endDateFilter; }
    $sql = 'SELECT status, COUNT(*) as count FROM reservations' . (empty($cond) ? '' : ' WHERE ' . implode(' AND ', $cond)) . ' GROUP BY status';
    $statusStmt = $pdo->prepare($sql);
    $statusStmt->execute($params);
    $statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $cond = ['user_id = :user_id'];
    $params = ['user_id' => $userId];
    if ($statusFilter) { $cond[] = 'LOWER(status) = :f_status_s'; $params['f_status_s'] = $statusFilter; }
    if ($facilityFilter > 0) { $cond[] = 'facility_id = :f_facility_s'; $params['f_facility_s'] = $facilityFilter; }
    if ($startDateFilter) { $cond[] = 'reservation_date >= :f_start_s'; $params['f_start_s'] = $startDateFilter; }
    if ($endDateFilter)   { $cond[] = 'reservation_date <= :f_end_s';   $params['f_end_s'] = $endDateFilter; }
    $sql = 'SELECT status, COUNT(*) as count FROM reservations WHERE ' . implode(' AND ', $cond) . ' GROUP BY status';
    $statusStmt = $pdo->prepare($sql);
    $statusStmt->execute($params);
    $statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
}

$statusMap = ['approved' => 0, 'pending' => 0, 'denied' => 0, 'cancelled' => 0];
$statusLabels = [];
$statusCounts = [];
$statusColors = ['#28a745', '#ff9800', '#e53935', '#6c757d'];

foreach ($statusData as $row) {
    $status = strtolower($row['status']);
    if (isset($statusMap[$status])) {
        $statusMap[$status] = (int)$row['count'];
    }
}

$statusLabels = ['Approved', 'Pending', 'Denied', 'Cancelled'];
$statusCounts = [$statusMap['approved'], $statusMap['pending'], $statusMap['denied'], $statusMap['cancelled']];

// Chart data: Facility utilization (Admin/Staff only or user's top facilities)
if (in_array($userRole, ['Admin', 'Staff'])) {
    $cond = ['r.status = "approved"'];
    $params = [];
    if ($statusFilter) { $cond[] = 'LOWER(r.status) = :f_status_f'; $params['f_status_f'] = $statusFilter; }
    if ($facilityFilter > 0) { $cond[] = 'r.facility_id = :f_facility_f'; $params['f_facility_f'] = $facilityFilter; }
    if ($startDateFilter) { $cond[] = 'r.reservation_date >= :f_start_f'; $params['f_start_f'] = $startDateFilter; }
    if ($endDateFilter)   { $cond[] = 'r.reservation_date <= :f_end_f';   $params['f_end_f'] = $endDateFilter; }
    $sql = 'SELECT f.name, COUNT(r.id) as booking_count
            FROM facilities f
            LEFT JOIN reservations r ON f.id = r.facility_id' . (empty($cond) ? '' : ' AND ' . implode(' AND ', $cond)) . '
            GROUP BY f.id, f.name
            ORDER BY booking_count DESC
            LIMIT 5';
    $facilityStmt = $pdo->prepare($sql);
    $facilityStmt->execute($params);
    $facilityData = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $cond = ['r.user_id = :user_id'];
    $params = ['user_id' => $userId];
    if ($statusFilter) { $cond[] = 'LOWER(r.status) = :f_status_f'; $params['f_status_f'] = $statusFilter; }
    if ($facilityFilter > 0) { $cond[] = 'r.facility_id = :f_facility_f'; $params['f_facility_f'] = $facilityFilter; }
    if ($startDateFilter) { $cond[] = 'r.reservation_date >= :f_start_f'; $params['f_start_f'] = $startDateFilter; }
    if ($endDateFilter)   { $cond[] = 'r.reservation_date <= :f_end_f';   $params['f_end_f'] = $endDateFilter; }
    $sql = 'SELECT f.name, COUNT(r.id) as booking_count
            FROM facilities f
            JOIN reservations r ON f.id = r.facility_id
            WHERE ' . implode(' AND ', $cond) . '
            GROUP BY f.id, f.name
            ORDER BY booking_count DESC
            LIMIT 5';
    $facilityStmt = $pdo->prepare($sql);
    $facilityStmt->execute($params);
    $facilityData = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
}

$facilityLabels = [];
$facilityCounts = [];
foreach ($facilityData as $fac) {
    $facilityLabels[] = $fac['name'];
    $facilityCounts[] = (int)$fac['booking_count'];
}

ob_start();
?>
<div class="page-header">
    <h1>Dashboard Overview</h1>
    <small>Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>!</small>
</div>

<?php if (!$isVerified && $userRole === 'Resident'): ?>
<div class="booking-card" style="background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%); border: 2px solid #ffc107; margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: start; gap: 1rem;">
        <div style="font-size: 2rem; flex-shrink: 0;">⚠️</div>
        <div style="flex: 1;">
            <h3 style="margin: 0 0 0.5rem 0; color: #856404;">Account Verification Required</h3>
            <p style="margin: 0 0 0.75rem 0; color: #856404; line-height: 1.6;">
                Your account is active, but you haven't submitted a valid ID yet. To enable <strong>auto-approval features</strong> for facility bookings, please upload a valid government-issued ID. You can still make reservations, but they will require manual approval and you'll need to submit an ID during the booking process.
            </p>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/profile.php#verification" class="btn-primary" style="text-decoration: none;">Upload Valid ID Now</a>
                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/book_facility.php" class="btn-outline" style="text-decoration: none;">Book Facility Anyway</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="GET" class="booking-card" style="margin-bottom: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; align-items: end;">
    <div>
        <label for="status" style="display:block; font-weight:600; margin-bottom:0.35rem; color:#334155;">Status</label>
        <select id="status" name="status" class="booking-form-control">
            <option value="">All</option>
            <?php foreach (['approved' => 'Approved','pending' => 'Pending','denied' => 'Denied','cancelled' => 'Cancelled'] as $key => $label): ?>
                <option value="<?= $key; ?>" <?= $statusFilter === $key ? 'selected' : ''; ?>><?= $label; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="facility_id" style="display:block; font-weight:600; margin-bottom:0.35rem; color:#334155;">Facility</label>
        <select id="facility_id" name="facility_id" class="booking-form-control">
            <option value="0">All</option>
            <?php foreach ($facilityOptions as $facility): ?>
                <option value="<?= (int)$facility['id']; ?>" <?= $facilityFilter === (int)$facility['id'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($facility['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="start_date" style="display:block; font-weight:600; margin-bottom:0.35rem; color:#334155;">Start Date</label>
        <input type="date" id="start_date" name="start_date" class="booking-form-control" value="<?= htmlspecialchars($startDateFilter); ?>">
    </div>
    <div>
        <label for="end_date" style="display:block; font-weight:600; margin-bottom:0.35rem; color:#334155;">End Date</label>
        <input type="date" id="end_date" name="end_date" class="booking-form-control" value="<?= htmlspecialchars($endDateFilter); ?>">
    </div>
    <div style="display:flex; gap:0.5rem;">
        <button type="submit" class="btn-primary" style="flex:1;">Apply Filters</button>
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/index.php" class="btn-outline" style="flex:1; text-align:center; text-decoration:none;">Reset</a>
    </div>
</form>

<div class="stat-grid">
    <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
        <!-- Admin/Staff Global Statistics -->
        <div class="stat-card" style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);">
            <h3>Total Reservations</h3>
            <p style="font-size: 2rem; font-weight: 700; color: #1976d2; margin: 0.5rem 0;">
                <?= number_format($totalReservations); ?>
            </p>
            <small style="color: #5b6888;">
                <?= number_format($approvedReservations); ?> approved • 
                <?= number_format($pendingCount); ?> pending
            </small>
        </div>
        
        <div class="stat-card" style="background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);">
            <h3>Pending Approvals</h3>
            <p style="font-size: 2rem; font-weight: 700; color: #f57c00; margin: 0.5rem 0;">
                <?= number_format($pendingCount); ?>
            </p>
            <small style="color: #5b6888;">
                <?php if ($expiringSoon > 0): ?>
                    <strong style="color: #d32f2f;"><?= $expiringSoon; ?></strong> expiring in 24h
                <?php else: ?>
                    Awaiting review
                <?php endif; ?>
            </small>
        </div>
        
        <div class="stat-card" style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);">
            <h3>Total Users</h3>
            <p style="font-size: 2rem; font-weight: 700; color: #388e3c; margin: 0.5rem 0;">
                <?= number_format($totalUsers); ?>
            </p>
            <small style="color: #5b6888;">
                <?= number_format($activeUsers); ?> active users
            </small>
        </div>
        
        <div class="stat-card" style="background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);">
            <h3>Available Facilities</h3>
            <p style="font-size: 2rem; font-weight: 700; color: #7b1fa2; margin: 0.5rem 0;">
                <?= number_format($totalFacilities); ?>
            </p>
            <small style="color: #5b6888;">
                Facilities in system
            </small>
        </div>
        
        <div class="stat-card" style="background: linear-gradient(135deg, #fff9c4 0%, #fff59d 100%);">
            <h3>Today's Bookings</h3>
            <p style="font-size: 2rem; font-weight: 700; color: #f9a825; margin: 0.5rem 0;">
                <?= number_format($todayReservations); ?>
            </p>
            <small style="color: #5b6888;">
                <?= number_format($weekReservations); ?> this week
            </small>
        </div>
        
        <div class="stat-card" style="background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%);">
            <h3>Approval Rate</h3>
            <p style="font-size: 2rem; font-weight: 700; color: #00796b; margin: 0.5rem 0;">
                <?= $approvalRate; ?>%
            </p>
            <small style="color: #5b6888;">
                <?= number_format($approvedReservations); ?> of <?= number_format($totalReservations); ?> approved
            </small>
        </div>
        
        <div class="stat-card" style="background: linear-gradient(135deg, #fce4ec 0%, #f8bbd0 100%);">
            <h3>Avg per User</h3>
            <p style="font-size: 2rem; font-weight: 700; color: #c2185b; margin: 0.5rem 0;">
                <?= $avgReservationsPerUser; ?>
            </p>
            <small style="color: #5b6888;">
                Reservations per user
            </small>
        </div>
        
        <div class="stat-card">
            <h3>Notifications</h3>
            <p style="font-size: 2rem; font-weight: 600; color: #ff4b5c; margin: 0.5rem 0;">
                <?= $unreadNotifications; ?>
            </p>
            <small style="color: #8b95b5;">
                Unread notifications
            </small>
        </div>
    <?php else: ?>
        <!-- Resident Statistics -->
        <div class="stat-card">
            <h3>My Upcoming Reservations</h3>
            <p style="font-size: 2rem; font-weight: 600; color: var(--gov-blue); margin: 0.5rem 0;">
                <?= $upcomingCount; ?>
            </p>
            <small style="color: #8b95b5;">
                <?= $upcomingCount === 1 ? 'reservation' : 'reservations'; ?> in the next 7 days
            </small>
        </div>
        
        <div class="stat-card">
            <h3>Total Reservations</h3>
            <p style="font-size: 2rem; font-weight: 600; color: var(--gov-blue-dark); margin: 0.5rem 0;">
                <?= $totalReservations; ?>
            </p>
            <small style="color: #8b95b5;">
                <?= $approvedReservations; ?> approved
            </small>
        </div>
        
        <div class="stat-card">
            <h3>Notifications</h3>
            <p style="font-size: 2rem; font-weight: 600; color: #ff4b5c; margin: 0.5rem 0;">
                <?= $unreadNotifications; ?>
            </p>
            <small style="color: #8b95b5;">
                <?= $unreadNotifications === 1 ? 'unread notification' : 'unread notifications'; ?>
            </small>
        </div>
    <?php endif; ?>
</div>

<div class="booking-wrapper" style="margin-top: 2rem;">
    <section class="booking-card collapsible-card">
        <button class="collapsible-header" data-collapse-target="upcoming-reservations">
            <span><?= in_array($userRole, ['Admin', 'Staff']) ? 'Upcoming Reservations (All Users)' : 'My Upcoming Reservations'; ?></span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="upcoming-reservations">
        <div class="table-responsive">
        <?php if (empty($upcomingReservations)): ?>
            <p style="color: #8b95b5; padding: 1rem 0;">
                No upcoming reservations.
                <?php if (!in_array($userRole, ['Admin', 'Staff'])): ?>
                    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/book_facility.php" style="color: var(--gov-blue);">Book a facility</a> to get started.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
                            <th>Requester</th>
                        <?php endif; ?>
                        <th>Facility</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcomingReservations as $res): ?>
                        <tr>
                            <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
                                <td><?= htmlspecialchars($res['requester_name'] ?? 'N/A'); ?></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($res['facility_name']); ?></td>
                            <td><?= date('M j, Y', strtotime($res['reservation_date'])); ?></td>
                            <td><?= htmlspecialchars($res['time_slot']); ?></td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($res['status']); ?>">
                                    <?= ucfirst($res['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
                                    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php?id=<?= (int)$res['id']; ?>" class="btn-outline" style="padding: 0.4rem 0.75rem; text-decoration: none; font-size: 0.85rem;">Manage</a>
                                <?php else: ?>
                                    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/my_reservations.php" class="btn-outline" style="padding: 0.4rem 0.75rem; text-decoration: none; font-size: 0.85rem;">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 1rem;">
                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/<?= in_array($userRole, ['Admin', 'Staff']) ? 'reservations_manage.php' : 'my_reservations.php'; ?>" class="btn-primary" style="text-decoration: none; display: inline-block;">View All Reservations</a>
            </div>
        <?php endif; ?>
        </div>
        </div>
    </section>
    
    <?php if (in_array($userRole, ['Admin', 'Staff']) && !empty($pendingReservations)): ?>
    <aside class="booking-card collapsible-card">
        <button class="collapsible-header" data-collapse-target="pending-requests">
            <span>Recent Pending Requests</span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="pending-requests">
        <div style="display: flex; flex-direction: column; gap: 0.75rem;" class="table-responsive" aria-label="Pending requests">
            <?php foreach ($pendingReservations as $pending): ?>
                <div style="padding: 0.75rem; background: #f9fafc; border-radius: 8px; border: 1px solid #e8ecf4;">
                    <strong style="display: block; margin-bottom: 0.25rem; color: var(--gov-blue-dark);">
                        <?= htmlspecialchars($pending['facility_name']); ?>
                    </strong>
                    <small style="color: #8b95b5; display: block; margin-bottom: 0.25rem;">
                        <?= htmlspecialchars($pending['requester_name']); ?>
                    </small>
                    <small style="color: #5b6888;">
                        <?= date('M j, Y', strtotime($pending['reservation_date'])); ?> - <?= htmlspecialchars($pending['time_slot']); ?>
                    </small>
                    <div style="margin-top: 0.5rem;">
                        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php" class="btn-outline" style="padding: 0.35rem 0.65rem; text-decoration: none; font-size: 0.8rem;">Review</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($pendingCount > 5): ?>
            <div style="margin-top: 1rem; text-align: center;">
                <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reservations_manage.php" class="btn-primary" style="text-decoration: none; display: inline-block; padding: 0.5rem 1rem;">View All (<?= $pendingCount; ?>)</a>
            </div>
        <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>
</div>

<div class="reports-grid" style="margin-top: 2rem;">
    <section class="booking-card">
        <h2>Reservation Trends</h2>
        <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
            <?= in_array($userRole, ['Admin', 'Staff']) ? 'Total reservations over the last 6 months' : 'Your reservations over the last 6 months'; ?>
        </p>
        <canvas id="monthlyChart" style="max-height: 300px;"></canvas>
    </section>
    
    <section class="booking-card">
        <h2>Status Breakdown</h2>
        <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
            Distribution of reservation statuses
        </p>
        <canvas id="statusChart" style="max-height: 300px;"></canvas>
    </section>
</div>

<?php if (!empty($facilityLabels)): ?>
<div class="booking-card" style="margin-top: 2rem;">
    <h2><?= in_array($userRole, ['Admin', 'Staff']) ? 'Top Facilities by Bookings' : 'Your Most Booked Facilities'; ?></h2>
    <p style="color: #8b95b5; font-size: 0.9rem; margin-bottom: 1rem;">
        Facilities with the highest number of <?= in_array($userRole, ['Admin', 'Staff']) ? 'approved reservations' : 'your reservations'; ?>
    </p>
    <canvas id="facilityChart" style="max-height: 300px;"></canvas>
</div>
<?php endif; ?>

<div class="booking-card" style="margin-top: 2rem;">
    <h2>Quick Actions</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/book_facility.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📅 Book Facility</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">Submit a new reservation request</p>
        </a>
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/my_reservations.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📋 My Reservations</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View your booking history</p>
        </a>
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/calendar.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">🗓️ Calendar</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View availability calendar</p>
        </a>
        <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
        <a href="<?= base_path(); ?>/resources/views/pages/dashboard/reports.php" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📊 Reports</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View analytics & insights</p>
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
// Collapsible helper with localStorage persistence (dashboard)
(function() {
    const STORAGE_KEY = 'collapse-state-dashboard';
    let state = {};
    try { state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { state = {}; }

    function save() { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }

    function init() {
        document.querySelectorAll('.collapsible-header').forEach(header => {
            const targetId = header.getAttribute('data-collapse-target');
            const body = document.getElementById(targetId);
            if (!body) return;
            const chevron = header.querySelector('.chevron');
            if (state[targetId]) {
                body.classList.add('is-collapsed');
                if (chevron) chevron.style.transform = 'rotate(-90deg)';
            }
            header.addEventListener('click', () => {
                const isCollapsed = body.classList.toggle('is-collapsed');
                if (chevron) chevron.style.transform = isCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
                state[targetId] = isCollapsed;
                save();
            });
        });
    }
    document.addEventListener('DOMContentLoaded', init);
})();

document.addEventListener('DOMContentLoaded', function() {
    // Monthly Trends Chart
    const monthlyCtx = document.getElementById('monthlyChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($monthlyLabels); ?>,
                datasets: [{
                    label: 'Reservations',
                    data: <?= json_encode($monthlyData); ?>,
                    borderColor: '#0047ab',
                    backgroundColor: 'rgba(0, 71, 171, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2,
                    pointBackgroundColor: '#0047ab',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Status Breakdown Chart
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($statusLabels); ?>,
                datasets: [{
                    data: <?= json_encode($statusCounts); ?>,
                    backgroundColor: <?= json_encode($statusColors); ?>,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
    }

    // Facility Chart
    const facilityCtx = document.getElementById('facilityChart');
    if (facilityCtx) {
        new Chart(facilityCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($facilityLabels); ?>,
                datasets: [{
                    label: 'Bookings',
                    data: <?= json_encode($facilityCounts); ?>,
                    backgroundColor: '#0047ab',
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';



