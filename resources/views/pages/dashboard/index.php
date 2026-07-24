<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/reservation_helpers.php';
require_once __DIR__ . '/../../../../config/time_helpers.php';
require_once __DIR__ . '/../../../../config/analytics_chart_filters.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';
require_once __DIR__ . '/../../../../config/lookups.php';

$pdo = db();

// Auto-decline expired pending reservations before querying
autoDeclineExpiredReservations();

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

// Filters (Recent/Upcoming) - use lookup values for allowed statuses
$allowedStatuses = [];
if (frs_lookups_table_ready($pdo)) {
    foreach (frs_lookup_values($pdo, 'reservation_status') as $status) {
        $allowedStatuses[] = $status['slug'];
    }
} else {
    // Fallback to hardcoded statuses
    $allowedStatuses = ['approved', 'pending', 'denied', 'cancelled', 'postponed', 'pending_payment', 'completed'];
}
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

// Live occupancy strip (today's operational snapshot)
$occDashSnapshot = ['facilities' => [], 'summary' => ['occupied' => 0, 'total_facilities' => 0]];
try {
    $occDashSnapshot = frs_build_operational_occupancy_snapshot($pdo);
    if (!in_array($userRole, ['Admin', 'Staff'], true)) {
        $occDashSnapshot = frs_sanitize_occupancy_snapshot_for_public($occDashSnapshot);
    }
} catch (Throwable $e) {
    error_log('Dashboard occupancy strip: ' . $e->getMessage());
}
$occDashLiveUrl = base_path() . '/dashboard/occupancy-live';
$occDashStaffLink = in_array($userRole, ['Admin', 'Staff'], true);

// ===== Prompt: Reservation today (Check In/Out) =====
$todayPrompt = null;
try {
    if (!in_array($userRole, ['Admin', 'Staff'], true)) {
        $hasAttendanceTable = false;
        $checkStmt = $pdo->query("SHOW TABLES LIKE 'reservation_attendance'");
        $hasAttendanceTable = (bool)$checkStmt->fetchColumn();

        if ($hasAttendanceTable) {
            $stmt = $pdo->prepare(
                "SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name,
                        a.time_in_at, a.time_out_at
                 FROM reservations r
                 JOIN facilities f ON f.id = r.facility_id
                 LEFT JOIN reservation_attendance a ON a.reservation_id = r.id
                 WHERE r.user_id = ? AND r.status = 'approved' AND r.reservation_date = ?
                   AND (a.time_out_at IS NULL)
                 ORDER BY r.time_slot ASC
                 LIMIT 1"
            );
            $stmt->execute([$userId, $today]);
            $todayPrompt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
} catch (Throwable $e) {
    $todayPrompt = null;
}

// Facility options for filter
$facilityOptionsStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name ASC');
$facilityOptions = $facilityOptionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming/reservations (filtered, with pagination)
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

// Pagination for upcoming reservations
$upcomingPerPage = max(5, (int)($_GET['upcoming_page_size'] ?? 5)); // Minimum 5 per page
$upcomingPage = max(1, (int)($_GET['upcoming_page'] ?? 1));
$upcomingOffset = ($upcomingPage - 1) * $upcomingPerPage;

// Get total count
$upcomingCountSql = '
    SELECT COUNT(*) FROM reservations r
    JOIN facilities f ON r.facility_id = f.id' .
    (in_array($userRole, ['Admin', 'Staff']) ? ' JOIN users u ON r.user_id = u.id' : '') . '
    WHERE ' . (empty($whereUpcoming) ? '1=1' : implode(' AND ', $whereUpcoming));
$upcomingCountStmt = $pdo->prepare($upcomingCountSql);
foreach ($paramsUpcoming as $k => $v) {
    $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $upcomingCountStmt->bindValue(':' . $k, $v, $type);
}
$upcomingCountStmt->execute();
$upcomingTotal = (int)$upcomingCountStmt->fetchColumn();
$upcomingTotalPages = max(1, (int)ceil($upcomingTotal / $upcomingPerPage));

// Get paginated results
$upcomingSql = '
    SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name' . 
    (in_array($userRole, ['Admin', 'Staff']) ? ', u.name AS requester_name' : '') . '
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id' .
    (in_array($userRole, ['Admin', 'Staff']) ? ' JOIN users u ON r.user_id = u.id' : '') . '
    WHERE ' . (empty($whereUpcoming) ? '1=1' : implode(' AND ', $whereUpcoming)) . '
    ORDER BY r.reservation_date ASC
    LIMIT :limit OFFSET :offset';

$upcomingStmt = $pdo->prepare($upcomingSql);
foreach ($paramsUpcoming as $k => $v) {
    $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $upcomingStmt->bindValue(':' . $k, $v, $type);
}
$upcomingStmt->bindValue(':limit', $upcomingPerPage, PDO::PARAM_INT);
$upcomingStmt->bindValue(':offset', $upcomingOffset, PDO::PARAM_INT);
$upcomingStmt->execute();
$upcomingReservations = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
$upcomingCount = count($upcomingReservations);

$upcomingWeekCount = 0;
if (!in_array($userRole, ['Admin', 'Staff'], true)) {
    $weekCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations r
         WHERE r.user_id = :user_id
           AND r.reservation_date >= :today
           AND r.reservation_date <= :week_end
           AND r.status IN ("approved", "pending")'
    );
    $weekCountStmt->execute([
        'user_id' => $userId,
        'today' => $today,
        'week_end' => $weekFromNow,
    ]);
    $upcomingWeekCount = (int)$weekCountStmt->fetchColumn();
}

// Pending count will be calculated with filters in the statistics section below
$pendingCount = 0;
$pendingReservations = [];

// Get total reservations (global for Admin/Staff, user-specific for Residents) - RESPECT FILTERS
if (in_array($userRole, ['Admin', 'Staff'])) {
    // Build conditions for filtered statistics
    $statConditions = [];
    $statParams = [];
    if ($statusFilter) {
        $statConditions[] = "status = :f_status_stat";
        $statParams['f_status_stat'] = $statusFilter;
    }
    if ($facilityFilter > 0) {
        $statConditions[] = "facility_id = :f_facility_stat";
        $statParams['f_facility_stat'] = $facilityFilter;
    }
    if ($startDateFilter) {
        $statConditions[] = "reservation_date >= :f_start_stat";
        $statParams['f_start_stat'] = $startDateFilter;
    }
    if ($endDateFilter) {
        $statConditions[] = "reservation_date <= :f_end_stat";
        $statParams['f_end_stat'] = $endDateFilter;
    }
    
    $whereClause = empty($statConditions) ? '' : ' WHERE ' . implode(' AND ', $statConditions);
    
    // Total reservations (filtered)
    $totalResSql = 'SELECT COUNT(*) FROM reservations' . $whereClause;
    $totalResStmt = $pdo->prepare($totalResSql);
    $totalResStmt->execute($statParams);
    $totalReservations = (int)$totalResStmt->fetchColumn();
    
    // Approved reservations (filtered, but status filter might override)
    $approvedConditions = $statConditions;
    $approvedParams = $statParams;
    if (!$statusFilter) {
        $approvedConditions[] = "status = 'approved'";
    }
    $approvedWhereClause = empty($approvedConditions) ? ' WHERE status = "approved"' : ' WHERE ' . implode(' AND ', $approvedConditions);
    $approvedResSql = 'SELECT COUNT(*) FROM reservations' . $approvedWhereClause;
    $approvedResStmt = $pdo->prepare($approvedResSql);
    $approvedResStmt->execute($approvedParams);
    $approvedReservations = (int)$approvedResStmt->fetchColumn();
    
    // Denied reservations
    $deniedConditions = $statConditions;
    $deniedParams = $statParams;
    if (!$statusFilter) {
        $deniedConditions[] = "status = 'denied'";
    }
    $deniedWhereClause = empty($deniedConditions) ? ' WHERE status = "denied"' : ' WHERE ' . implode(' AND ', $deniedConditions);
    $deniedResSql = 'SELECT COUNT(*) FROM reservations' . $deniedWhereClause;
    $deniedResStmt = $pdo->prepare($deniedResSql);
    $deniedResStmt->execute($deniedParams);
    $deniedReservations = (int)$deniedResStmt->fetchColumn();
    
    // Cancelled reservations
    $cancelledConditions = $statConditions;
    $cancelledParams = $statParams;
    if (!$statusFilter) {
        $cancelledConditions[] = "status = 'cancelled'";
    }
    $cancelledWhereClause = empty($cancelledConditions) ? ' WHERE status = "cancelled"' : ' WHERE ' . implode(' AND ', $cancelledConditions);
    $cancelledResSql = 'SELECT COUNT(*) FROM reservations' . $cancelledWhereClause;
    $cancelledResStmt = $pdo->prepare($cancelledResSql);
    $cancelledResStmt->execute($cancelledParams);
    $cancelledReservations = (int)$cancelledResStmt->fetchColumn();
    
    // Pending count (already calculated above, but recalculate with filters)
    $pendingConditions = ['status = "pending"'];
    $pendingParams = [];
    if ($facilityFilter > 0) {
        $pendingConditions[] = "facility_id = :f_facility_pending";
        $pendingParams['f_facility_pending'] = $facilityFilter;
    }
    if ($startDateFilter) {
        $pendingConditions[] = "reservation_date >= :f_start_pending";
        $pendingParams['f_start_pending'] = $startDateFilter;
    }
    if ($endDateFilter) {
        $pendingConditions[] = "reservation_date <= :f_end_pending";
        $pendingParams['f_end_pending'] = $endDateFilter;
    }
    $pendingWhereClause = ' WHERE ' . implode(' AND ', $pendingConditions);
    $pendingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations' . $pendingWhereClause);
    $pendingCountStmt->execute($pendingParams);
    $pendingCount = (int)$pendingCountStmt->fetchColumn();
    
    // Additional global statistics (not filtered by date/facility)
    $totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Resident" AND status = "active"');
    $totalUsers = (int)$totalUsersStmt->fetchColumn();
    
    $totalFacilitiesStmt = $pdo->query('SELECT COUNT(*) FROM facilities WHERE status = "available"');
    $totalFacilities = (int)$totalFacilitiesStmt->fetchColumn();
    
    // Active users (with filters)
    $activeConditions = ['status IN ("approved", "pending")'];
    $activeParams = [];
    if ($facilityFilter > 0) {
        $activeConditions[] = "facility_id = :f_facility_active";
        $activeParams['f_facility_active'] = $facilityFilter;
    }
    if ($startDateFilter) {
        $activeConditions[] = "reservation_date >= :f_start_active";
        $activeParams['f_start_active'] = $startDateFilter;
    }
    if ($endDateFilter) {
        $activeConditions[] = "reservation_date <= :f_end_active";
        $activeParams['f_end_active'] = $endDateFilter;
    }
    $activeWhereClause = ' WHERE ' . implode(' AND ', $activeConditions);
    $activeUsersStmt = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM reservations' . $activeWhereClause);
    $activeUsersStmt->execute($activeParams);
    $activeUsers = (int)$activeUsersStmt->fetchColumn();
    
    // Today's reservations (respect facility filter)
    $todayConditions = ['reservation_date = CURDATE()', 'status IN ("approved", "pending")'];
    $todayParams = [];
    if ($facilityFilter > 0) {
        $todayConditions[] = "facility_id = :f_facility_today";
        $todayParams['f_facility_today'] = $facilityFilter;
    }
    $todayWhereClause = ' WHERE ' . implode(' AND ', $todayConditions);
    $todayResStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations' . $todayWhereClause);
    $todayResStmt->execute($todayParams);
    $todayReservations = (int)$todayResStmt->fetchColumn();
    
    // This week's reservations (respect facility filter)
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    $weekConditions = ['reservation_date BETWEEN :start AND :end', 'status IN ("approved", "pending")'];
    $weekParams = ['start' => $weekStart, 'end' => $weekEnd];
    if ($facilityFilter > 0) {
        $weekConditions[] = "facility_id = :f_facility_week";
        $weekParams['f_facility_week'] = $facilityFilter;
    }
    $weekWhereClause = ' WHERE ' . implode(' AND ', $weekConditions);
    $weekResStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations' . $weekWhereClause);
    $weekResStmt->execute($weekParams);
    $weekReservations = (int)$weekResStmt->fetchColumn();
    
    // Approval rate calculation (respect filters)
    $approvalRate = $totalReservations > 0 
        ? round(($approvedReservations / $totalReservations) * 100, 1) 
        : 0;
    
    // Average reservations per user (respect filters)
    $avgReservationsPerUser = $totalUsers > 0 
        ? round($totalReservations / $totalUsers, 1) 
        : 0;
    
    // Expired pending (expiring soon - within 24 hours, not filtered)
    $expiringSoonStmt = $pdo->query('SELECT COUNT(*) FROM reservations WHERE status = "pending" AND expires_at IS NOT NULL AND expires_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)');
    $expiringSoon = (int)$expiringSoonStmt->fetchColumn();
    
    // Get pending list for display (with filters)
    $pendingListConditions = ['r.status = "pending"'];
    $pendingListParams = [];
    if ($facilityFilter > 0) {
        $pendingListConditions[] = "r.facility_id = :f_facility_list";
        $pendingListParams['f_facility_list'] = $facilityFilter;
    }
    if ($startDateFilter) {
        $pendingListConditions[] = "r.reservation_date >= :f_start_list";
        $pendingListParams['f_start_list'] = $startDateFilter;
    }
    if ($endDateFilter) {
        $pendingListConditions[] = "r.reservation_date <= :f_end_list";
        $pendingListParams['f_end_list'] = $endDateFilter;
    }
    
    $pendingPerPage = 3;
    $pendingPage = max(1, (int)($_GET['pending_page'] ?? 1));
    $pendingOffset = ($pendingPage - 1) * $pendingPerPage;
    $pendingTotalPages = max(1, (int)ceil($pendingCount / $pendingPerPage));

    $pendingListWhereClause = ' WHERE ' . implode(' AND ', $pendingListConditions);
    $pendingListSql = 'SELECT r.id, r.reservation_date, r.time_slot, f.name AS facility_name, u.name AS requester_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         JOIN users u ON r.user_id = u.id' . $pendingListWhereClause . '
         ORDER BY r.created_at ASC
         LIMIT :limit OFFSET :offset';
         
    $pendingListStmt = $pdo->prepare($pendingListSql);
    foreach ($pendingListParams as $k => $v) {
        $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $pendingListStmt->bindValue(':' . $k, $v, $type);
    }
    $pendingListStmt->bindValue(':limit', $pendingPerPage, PDO::PARAM_INT);
    $pendingListStmt->bindValue(':offset', $pendingOffset, PDO::PARAM_INT);
    $pendingListStmt->execute();
    $pendingReservations = $pendingListStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Resident view - user-specific data (with filters)
    $residentConditions = ['user_id = :user_id'];
    $residentParams = ['user_id' => $userId];
    
    if ($statusFilter) {
        $residentConditions[] = "status = :f_status_resident";
        $residentParams['f_status_resident'] = $statusFilter;
    }
    if ($facilityFilter > 0) {
        $residentConditions[] = "facility_id = :f_facility_resident";
        $residentParams['f_facility_resident'] = $facilityFilter;
    }
    if ($startDateFilter) {
        $residentConditions[] = "reservation_date >= :f_start_resident";
        $residentParams['f_start_resident'] = $startDateFilter;
    }
    if ($endDateFilter) {
        $residentConditions[] = "reservation_date <= :f_end_resident";
        $residentParams['f_end_resident'] = $endDateFilter;
    }
    
    $residentWhereClause = ' WHERE ' . implode(' AND ', $residentConditions);
    
    $totalResSql = 'SELECT COUNT(*) FROM reservations' . $residentWhereClause;
    $totalResStmt = $pdo->prepare($totalResSql);
    $totalResStmt->execute($residentParams);
    $totalReservations = (int)$totalResStmt->fetchColumn();
    
    // Approved reservations for resident
    $approvedConditions = $residentConditions;
    $approvedParams = $residentParams;
    if (!$statusFilter) {
        $approvedConditions[] = "status = 'approved'";
    }
    $approvedWhereClause = ' WHERE ' . implode(' AND ', $approvedConditions);
    $approvedResSql = 'SELECT COUNT(*) FROM reservations' . $approvedWhereClause;
    $approvedResStmt = $pdo->prepare($approvedResSql);
    $approvedResStmt->execute($approvedParams);
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

// Helper function to build URL with filters (lists/stats + per-chart filters preserved)
function buildFilterUrl($basePath, $page, $statusFilter, $facilityFilter, $startDateFilter, $endDateFilter, $additionalParams = []) {
    $params = [];
    if ($statusFilter) $params['status'] = $statusFilter;
    if ($facilityFilter > 0) $params['facility_id'] = $facilityFilter;
    if ($startDateFilter) $params['start_date'] = $startDateFilter;
    if ($endDateFilter) $params['end_date'] = $endDateFilter;
    $params = array_merge($params, $additionalParams);
    foreach ($_GET as $key => $value) {
        if (!is_string($value) && !is_numeric($value)) {
            continue;
        }
        foreach (['trend_', 'status_', 'topfac_'] as $prefix) {
            if (str_starts_with((string)$key, $prefix)) {
                $params[$key] = $value;
            }
        }
    }

    $queryString = !empty($params) ? '?' . http_build_query($params) : '';
    return $basePath . $page . $queryString;
}

// Get unread notifications
$unreadNotifications = getUnreadNotificationCount($userId);

// Check if user is verified
// Note: Staff and Admin are automatically considered verified
$userVerificationStmt = $pdo->prepare('SELECT is_verified, role FROM users WHERE id = :user_id');
$userVerificationStmt->execute(['user_id' => $userId]);
$userVerificationData = $userVerificationStmt->fetch(PDO::FETCH_ASSOC);
$isVerified = (bool)($userVerificationData['is_verified'] ?? false);
$userRole = $userVerificationData['role'] ?? 'Resident';

// Staff and Admin are automatically verified (no ID upload required)
$isVerifiedOrPrivileged = $isVerified || in_array($userRole, ['Staff', 'Admin'], true);

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

// Per-chart filters (independent from list/stat filters above)
$trendChartFilter = frs_parse_dashboard_chart_filter('trend');
$statusChartFilter = frs_parse_dashboard_chart_filter('status');
$topfacChartFilter = frs_parse_dashboard_chart_filter('topfac');

// Chart data: Monthly reservations
$monthlyLabels = [];
$monthlyData = [];
$trendMonths = max(1, (int)$trendChartFilter['months']);
for ($i = $trendMonths - 1; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $monthLabel = date('M Y', strtotime("-$i months"));

    $monthlyLabels[] = $monthLabel;

    $cond = ['reservation_date >= :start AND reservation_date <= :end'];
    $params = ['start' => $monthStart, 'end' => $monthEnd];
    if (!in_array($userRole, ['Admin', 'Staff'], true)) {
        $cond[] = 'user_id = :user_id';
        $params['user_id'] = $userId;
    }
    frs_dashboard_apply_chart_sql_filters($trendChartFilter, $cond, $params, 'trend_st', 'trend_fc', 'trend_sd', 'trend_ed');

    $sql = 'SELECT COUNT(*) FROM reservations WHERE ' . implode(' AND ', $cond);
    $monthStmt = $pdo->prepare($sql);
    $monthStmt->execute($params);
    $monthlyData[] = (int)$monthStmt->fetchColumn();
}

// Chart data: Status breakdown
$statusData = [];
$cond = [];
$params = [];
if (!in_array($userRole, ['Admin', 'Staff'], true)) {
    $cond[] = 'user_id = :user_id';
    $params['user_id'] = $userId;
}
frs_dashboard_apply_chart_sql_filters($statusChartFilter, $cond, $params, 'status_st', 'status_fc', 'status_sd', 'status_ed');
$sql = 'SELECT status, COUNT(*) as count FROM reservations'
    . (empty($cond) ? '' : ' WHERE ' . implode(' AND ', $cond))
    . ' GROUP BY status';
$statusStmt = $pdo->prepare($sql);
$statusStmt->execute($params);
$statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Chart data: Top facilities
$topLimit = max(1, (int)($topfacChartFilter['limit'] ?? 5));
if (in_array($userRole, ['Admin', 'Staff'], true)) {
    $joinCond = ['r.status = "approved"'];
    $params = [];
    if ($topfacChartFilter['status'] !== '') {
        $joinCond[] = 'LOWER(r.status) = :topfac_st';
        $params['topfac_st'] = $topfacChartFilter['status'];
    }
    if ($topfacChartFilter['facility'] > 0) {
        $joinCond[] = 'r.facility_id = :topfac_fc';
        $params['topfac_fc'] = $topfacChartFilter['facility'];
    }
    if ($topfacChartFilter['start'] !== '') {
        $joinCond[] = 'r.reservation_date >= :topfac_sd';
        $params['topfac_sd'] = $topfacChartFilter['start'];
    }
    if ($topfacChartFilter['end'] !== '') {
        $joinCond[] = 'r.reservation_date <= :topfac_ed';
        $params['topfac_ed'] = $topfacChartFilter['end'];
    }
    $sql = 'SELECT f.name, COUNT(r.id) as booking_count
            FROM facilities f
            LEFT JOIN reservations r ON f.id = r.facility_id AND ' . implode(' AND ', $joinCond) . '
            GROUP BY f.id, f.name
            ORDER BY booking_count DESC
            LIMIT ' . (int)$topLimit;
    $facilityStmt = $pdo->prepare($sql);
    $facilityStmt->execute($params);
    $facilityData = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $cond = ['r.user_id = :user_id'];
    $params = ['user_id' => $userId];
    if ($topfacChartFilter['status'] !== '') {
        $cond[] = 'LOWER(r.status) = :topfac_st';
        $params['topfac_st'] = $topfacChartFilter['status'];
    }
    if ($topfacChartFilter['facility'] > 0) {
        $cond[] = 'r.facility_id = :topfac_fc';
        $params['topfac_fc'] = $topfacChartFilter['facility'];
    }
    if ($topfacChartFilter['start'] !== '') {
        $cond[] = 'r.reservation_date >= :topfac_sd';
        $params['topfac_sd'] = $topfacChartFilter['start'];
    }
    if ($topfacChartFilter['end'] !== '') {
        $cond[] = 'r.reservation_date <= :topfac_ed';
        $params['topfac_ed'] = $topfacChartFilter['end'];
    }
    $sql = 'SELECT f.name, COUNT(r.id) as booking_count
            FROM facilities f
            JOIN reservations r ON f.id = r.facility_id
            WHERE ' . implode(' AND ', $cond) . '
            GROUP BY f.id, f.name
            ORDER BY booking_count DESC
            LIMIT ' . (int)$topLimit;
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

<?php if (!empty($todayPrompt) && $userRole === 'Resident'): ?>
    <?php
        $slotParsed = parseTimeSlot($todayPrompt['time_slot'] ?? '');
        $startStr = $slotParsed ? $slotParsed['start']->format('H:i') : null;
        $endStr = $slotParsed ? $slotParsed['end']->format('H:i') : null;
        $startDt = ($startStr) ? DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $startStr) : null;
        $endDt = ($endStr) ? DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $endStr) : null;
        $nowDt = new DateTime();
        $canTimeIn = $startDt && $endDt && $nowDt >= $startDt && $nowDt <= $endDt && empty($todayPrompt['time_in_at']);
        $canTimeOut = $endDt && $nowDt >= $endDt && !empty($todayPrompt['time_in_at']) && empty($todayPrompt['time_out_at']);
        $statusText = $canTimeIn ? 'Check In is available now.' : ($canTimeOut ? 'Check Out is available now.' : 'Your reservation is scheduled today.');
    ?>
    <div class="booking-card" style="margin-bottom: 1rem; padding: 0.85rem; border: 1px solid #bfdbfe; background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);">
        <div style="display:flex; gap:1rem; align-items:flex-start; flex-wrap:wrap;">
            <div style="font-size:1.75rem; line-height:1;">⏱️</div>
            <div style="flex:1; min-width:240px;">
                <div style="font-weight:800; color:#1e3a5f; margin-bottom:0.25rem;">
                    Reservation today: <?= htmlspecialchars($todayPrompt['facility_name'] ?? 'Facility'); ?>
                </div>
                <div style="color:#334155;">
                    <?= htmlspecialchars($todayPrompt['time_slot'] ?? ''); ?> • <?= htmlspecialchars($statusText); ?>
                </div>
                <div style="margin-top:0.6rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <a href="<?= base_path(); ?>/dashboard/time-tracking" class="btn-primary" style="text-decoration:none; padding:0.5rem 0.9rem;">Open Check In/Out</a>
                    <a href="<?= base_path(); ?>/dashboard/book-facility?module=mine" class="btn-outline" style="text-decoration:none; padding:0.5rem 0.9rem;">View Reservations</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$isVerified && $userRole === 'Resident'): ?>
<div class="booking-card id-verification-card" style="background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%); border: 2px solid #ffc107; margin-bottom: 1rem; padding: 0.85rem;">
    <div style="display: flex; align-items: start; gap: 1rem;">
        <div style="font-size: 2rem; flex-shrink: 0;">⚠️</div>
        <div style="flex: 1; min-width: 0;">
            <h3 style="margin: 0 0 0.5rem 0; color: #856404;">Account Verification Required</h3>
            <p style="margin: 0 0 0.75rem 0; color: #856404; line-height: 1.6;">
                Your account is active, but you haven't submitted a valid ID yet. To enable <strong>auto-approval features</strong> for facility bookings, please upload a valid government-issued ID. You can still make reservations, but they will require manual approval and you'll need to submit an ID during the booking process.
            </p>
            <div class="id-verification-actions">
                <a href="<?= base_path(); ?>/dashboard/profile#verification" class="btn-primary" style="text-decoration: none;">Upload Valid ID Now</a>
                <a href="<?= base_path(); ?>/dashboard/book-facility" class="btn-outline id-verification-btn-outline" style="text-decoration: none;">Book Facility Anyway</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="GET" class="booking-card" style="margin-bottom: 1rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; align-items: end;" data-frs-partial="dash-filtered">
    <?= frs_chart_hidden_preserve(); ?>
    <div style="grid-column: 1 / -1; margin-bottom: 0.25rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:wrap;">
        <strong style="color:#334155; font-size:0.9rem;">Filter statistics, upcoming list &amp; pending requests</strong>
        <?= frs_field_tip('Applies to stat cards and lists only. Each chart below has its own filter.'); ?>
    </div>
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
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; min-width:0;">
        <button type="submit" class="btn-primary" style="flex:1 1 8rem; min-width:8rem;">Apply Filters</button>
        <a href="<?= base_path(); ?>/dashboard" class="btn-outline" style="flex:1 1 8rem; min-width:8rem; text-align:center; text-decoration:none;">Reset</a>
    </div>
</form>

<div class="dash-filtered-region" data-frs-partial-id="dash-filtered" data-frs-partial-root>
<div class="stat-grid">
    <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
        <!-- Admin/Staff Global Statistics -->
        <a href="<?= buildFilterUrl(base_path(), '/dashboard/reservations-manage', '', $facilityFilter, $startDateFilter, $endDateFilter); ?>" class="stat-card stat-card-clickable" style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); text-decoration: none; color: inherit;">
            <h3>Total Reservations</h3>
            <p style="font-size: 1.5rem; font-weight: 700; color: #1976d2; margin: 0.3rem 0;">
                <?= number_format($totalReservations); ?>
            </p>
            <small style="color: #5b6888;">
                <?= number_format($approvedReservations); ?> approved • 
                <?= number_format($pendingCount); ?> pending
            </small>
        </a>
        
        <a href="<?= buildFilterUrl(base_path(), '/dashboard/reservations-manage', 'pending', $facilityFilter, $startDateFilter, $endDateFilter); ?>" class="stat-card stat-card-clickable" style="background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); text-decoration: none; color: inherit;">
            <h3>Pending Approvals</h3>
            <p style="font-size: 1.5rem; font-weight: 700; color: #f57c00; margin: 0.3rem 0;">
                <?= number_format($pendingCount); ?>
            </p>
            <small style="color: #5b6888;">
                <?php if ($expiringSoon > 0): ?>
                    <strong style="color: #d32f2f;"><?= $expiringSoon; ?></strong> expiring in 24h
                <?php else: ?>
                    Awaiting review
                <?php endif; ?>
            </small>
        </a>
        
        <a href="<?= base_path(); ?>/dashboard/user-management" class="stat-card stat-card-clickable" style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); text-decoration: none; color: inherit;">
            <h3>Total Users</h3>
            <p style="font-size: 1.5rem; font-weight: 700; color: #388e3c; margin: 0.3rem 0;">
                <?= number_format($totalUsers); ?>
            </p>
            <small style="color: #5b6888;">
                <?= number_format($activeUsers); ?> active users
            </small>
        </a>
        
        <a href="<?= base_path(); ?>/dashboard/facility-management" class="stat-card stat-card-clickable" style="background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%); text-decoration: none; color: inherit;">
            <h3>Available Facilities</h3>
            <p style="font-size: 1.5rem; font-weight: 700; color: #7b1fa2; margin: 0.3rem 0;">
                <?= number_format($totalFacilities); ?>
            </p>
            <small style="color: #5b6888;">
                Facilities in system
            </small>
        </a>
        
        <a href="<?= buildFilterUrl(base_path(), '/dashboard/reservations-manage', '', $facilityFilter, date('Y-m-d'), date('Y-m-d')); ?>" class="stat-card stat-card-clickable" style="background: linear-gradient(135deg, #fff9c4 0%, #fff59d 100%); text-decoration: none; color: inherit;">
            <h3>Today's Bookings</h3>
            <p style="font-size: 1.5rem; font-weight: 700; color: #f9a825; margin: 0.3rem 0;">
                <?= number_format($todayReservations); ?>
            </p>
            <small style="color: #5b6888;">
                <?= number_format($weekReservations); ?> this week
            </small>
        </a>
        
        <a href="<?= buildFilterUrl(base_path(), '/dashboard/reservations-manage', 'approved', $facilityFilter, $startDateFilter, $endDateFilter); ?>" class="stat-card stat-card-clickable" style="background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%); text-decoration: none; color: inherit;">
            <h3>Approval Rate</h3>
            <p style="font-size: 1.5rem; font-weight: 700; color: #00796b; margin: 0.3rem 0;">
                <?= $approvalRate; ?>%
            </p>
            <small style="color: #5b6888;">
                <?= number_format($approvedReservations); ?> of <?= number_format($totalReservations); ?> approved
            </small>
        </a>
    <?php else: ?>
        <!-- Resident Statistics -->
        <a href="<?= buildFilterUrl(base_path(), '/dashboard/my-reservations', '', $facilityFilter, $today, $weekFromNow); ?>" class="stat-card stat-card-clickable" style="text-decoration: none; color: inherit;">
            <h3>My Upcoming Reservations</h3>
            <p style="font-size: 1.5rem; font-weight: 600; color: var(--gov-blue); margin: 0.3rem 0;">
                <?= $upcomingWeekCount; ?>
            </p>
            <small style="color: #8b95b5;">
                <?= $upcomingWeekCount === 1 ? 'reservation' : 'reservations'; ?> in the next 7 days
            </small>
        </a>
        
        <a href="<?= buildFilterUrl(base_path(), '/dashboard/my-reservations', '', $facilityFilter, $startDateFilter, $endDateFilter); ?>" class="stat-card stat-card-clickable" style="text-decoration: none; color: inherit;">
            <h3>Total Reservations</h3>
            <p style="font-size: 1.5rem; font-weight: 600; color: var(--gov-blue-dark); margin: 0.3rem 0;">
                <?= $totalReservations; ?>
            </p>
            <small style="color: #8b95b5;">
                <?= $approvedReservations; ?> approved
            </small>
        </a>
    <?php endif; ?>
</div>

<div class="booking-wrapper" style="margin-top: 1rem;">
    <section class="booking-card collapsible-card">
        <button type="button" class="collapsible-header" data-collapse-target="upcoming-reservations">
            <span><?= in_array($userRole, ['Admin', 'Staff']) ? 'Upcoming Reservations (All Users)' : 'My Upcoming Reservations'; ?></span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="upcoming-reservations">
        <div class="table-responsive">
        <?php if (empty($upcomingReservations)): ?>
            <p style="color: #8b95b5; padding: 1rem 0;">
                No upcoming reservations.
                <?php if (!in_array($userRole, ['Admin', 'Staff'])): ?>
                    <a href="<?= base_path(); ?>/dashboard/book-facility" style="color: var(--gov-blue);">Book a facility</a> to get started.
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
                                    <a href="<?= base_path(); ?>/dashboard/reservation-detail?id=<?= (int)$res['id']; ?>" class="btn-outline" style="padding: 0.4rem 0.75rem; text-decoration: none; font-size: 0.85rem;">Manage</a>
                                <?php else: ?>
                                    <a href="<?= base_path(); ?>/dashboard/my-reservations" class="btn-outline" style="padding: 0.4rem 0.75rem; text-decoration: none; font-size: 0.85rem;">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($upcomingTotalPages > 1): ?>
                <div class="pagination" style="margin-top: 1rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
                    <?php if ($upcomingPage > 1): ?>
                        <a href="<?= buildFilterUrl(base_path(), '/dashboard', $statusFilter, $facilityFilter, $startDateFilter, $endDateFilter, ['upcoming_page' => $upcomingPage - 1, 'upcoming_page_size' => $upcomingPerPage]); ?>" data-frs-partial="dash-filtered" style="padding: 0.5rem 1rem; text-decoration: none; color: var(--gov-blue); border: 1px solid var(--gov-blue); border-radius: 6px; background: white;">&larr; Prev</a>
                    <?php endif; ?>
                    <span style="padding: 0.5rem 1rem; color: #5b6888;">Page <?= $upcomingPage; ?> of <?= $upcomingTotalPages; ?> (<?= $upcomingTotal; ?> total)</span>
                    <?php if ($upcomingPage < $upcomingTotalPages): ?>
                        <a href="<?= buildFilterUrl(base_path(), '/dashboard', $statusFilter, $facilityFilter, $startDateFilter, $endDateFilter, ['upcoming_page' => $upcomingPage + 1, 'upcoming_page_size' => $upcomingPerPage]); ?>" data-frs-partial="dash-filtered" style="padding: 0.5rem 1rem; text-decoration: none; color: var(--gov-blue); border: 1px solid var(--gov-blue); border-radius: 6px; background: white;">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div style="margin-top: 1rem;">
                <a href="<?= base_path(); ?>/dashboard/<?= in_array($userRole, ['Admin', 'Staff']) ? 'reservations-manage' : 'my-reservations'; ?>" class="btn-primary" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; padding: 0.75rem 1.5rem; min-width: fit-content; text-align: center;">View All Reservations</a>
            </div>
        <?php endif; ?>
        </div>
        </div>
    </section>
    
    <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
    <aside class="booking-card collapsible-card">
        <button type="button" class="collapsible-header" data-collapse-target="pending-requests">
            <span>Recent Pending Requests</span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="pending-requests">
        <div style="display: flex; flex-direction: column; gap: 0.75rem;" class="table-responsive" aria-label="Pending requests">
            <?php if (empty($pendingReservations)): ?>
                <p style="color: #8b95b5; padding: 1rem 0; text-align: center;">No pending requests found.</p>
            <?php else: ?>
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
                            <a href="<?= base_path(); ?>/dashboard/reservations-manage" class="btn-outline" style="padding: 0.35rem 0.65rem; text-decoration: none; font-size: 0.8rem;">Review</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="pagination" style="margin-top: 1rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <?php if ($pendingPage > 1): ?>
                <a href="<?= buildFilterUrl(base_path(), '/dashboard', $statusFilter, $facilityFilter, $startDateFilter, $endDateFilter, ['pending_page' => $pendingPage - 1]); ?>" data-frs-partial="dash-filtered" style="padding: 0.5rem 1rem; text-decoration: none; color: var(--gov-blue); border: 1px solid var(--gov-blue); border-radius: 6px; background: white;">&larr; Prev</a>
            <?php endif; ?>
            
            <span style="padding: 0.5rem 1rem; color: #5b6888; font-size: 0.9rem;">
                <?= $pendingPage; ?> / <?= $pendingTotalPages; ?>
            </span>
            
            <?php if ($pendingPage < $pendingTotalPages): ?>
                <a href="<?= buildFilterUrl(base_path(), '/dashboard', $statusFilter, $facilityFilter, $startDateFilter, $endDateFilter, ['pending_page' => $pendingPage + 1]); ?>" data-frs-partial="dash-filtered" style="padding: 0.5rem 1rem; text-decoration: none; color: var(--gov-blue); border: 1px solid var(--gov-blue); border-radius: 6px; background: white;">Next &rarr;</a>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 0.5rem; text-align: center;">
            <a href="<?= base_path(); ?>/dashboard/reservations-manage" class="btn-primary" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 1rem; min-width: fit-content; text-align: center; font-size: 0.9rem;">View All (<?= $pendingCount; ?>)</a>
        </div>
        </div>
    </aside>
    <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/../../components/occupancy_dashboard_strip.php'; ?>

<!-- Global Filter for All Charts -->
<div class="booking-card dash-global-chart-filter" style="margin-top: 1rem;">
    <div class="dash-global-chart-filter__head">
        <h3 style="margin: 0; font-size: 1.1rem; color: var(--gov-blue-dark);">Global Filter (Apply to All Charts)</h3>
        <button type="button" onclick="applyGlobalFilter()" class="btn-primary dash-global-chart-filter__btn">Apply to All</button>
    </div>
    <form id="global-filter-form" class="chart-filter-bar">
        <div class="chart-filter-fields">
            <label class="chart-filter-item">
                <span>Status</span>
                <select id="global-status" class="booking-form-control chart-filter-control">
                    <option value=""<?= ($trendChartFilter['status'] === '') ? ' selected' : ''; ?>>All</option>
                    <?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'denied' => 'Denied', 'cancelled' => 'Cancelled'] as $key => $label): ?>
                        <option value="<?= $key; ?>"<?= ($trendChartFilter['status'] === $key) ? ' selected' : ''; ?>><?= $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="chart-filter-item">
                <span>Facility</span>
                <select id="global-facility" class="booking-form-control chart-filter-control">
                    <option value="0"<?= ($trendChartFilter['facility'] === 0) ? ' selected' : ''; ?>>All Facilities</option>
                    <?php foreach ($facilityOptions as $facility): ?>
                        <option value="<?= (int)$facility['id']; ?>"<?= ($trendChartFilter['facility'] === (int)$facility['id']) ? ' selected' : ''; ?>><?= htmlspecialchars($facility['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="chart-filter-item">
                <span>Months</span>
                <select id="global-months" class="booking-form-control chart-filter-control">
                    <option value="6"<?= ($trendChartFilter['months'] === 6) ? ' selected' : ''; ?>>Last 6 months</option>
                    <option value="12"<?= ($trendChartFilter['months'] === 12) ? ' selected' : ''; ?>>Last 12 months</option>
                </select>
            </label>
        </div>
    </form>
</div>

<div class="dash-charts-region" data-frs-partial-id="dash-charts" data-frs-partial-root>
<script type="application/json" id="dash-charts-config"><?= json_encode([
    'monthlyLabels' => $monthlyLabels,
    'monthlyData' => $monthlyData,
    'statusLabels' => $statusLabels,
    'statusCounts' => $statusCounts,
    'statusColors' => $statusColors,
    'facilityLabels' => $facilityLabels,
    'facilityCounts' => $facilityCounts,
    'rotateFacilityLabels' => true,
    'showValueLabels' => true,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
<div class="reports-grid" style="margin-top: 1rem;">
    <section class="booking-card">
        <?= frs_heading_with_tip('Reservation Trends', 'Monthly reservation counts. Use the filter to change status, facility, date range, or 6 vs 12 months.'); ?>
        <?= frs_dashboard_chart_filter_form('dash-trend', 'trend', $facilityOptions, $trendChartFilter, true, false, [], 'dash-charts'); ?>
        <canvas id="monthlyChart" style="max-height: 300px;"></canvas>
    </section>

    <section class="booking-card">
        <?= frs_heading_with_tip('Status Breakdown', 'Distribution of approved, pending, denied, and cancelled reservations for the filter below.'); ?>
        <?= frs_dashboard_chart_filter_form('dash-status', 'status', $facilityOptions, $statusChartFilter, false, false, [], 'dash-charts'); ?>
        <canvas id="statusChart" style="max-height: 300px;"></canvas>
    </section>
</div>

<div class="booking-card" style="margin-top: 1rem;">
    <?= frs_heading_with_tip(
        in_array($userRole, ['Admin', 'Staff']) ? 'Top Facilities by Bookings' : 'Your Most Booked Facilities',
        in_array($userRole, ['Admin', 'Staff'])
            ? 'Facilities with the most approved bookings in the selected period (top N from filter).'
            : 'Your most frequently booked facilities in the selected period.'
    ); ?>
    <?= frs_dashboard_chart_filter_form('dash-topfac', 'topfac', $facilityOptions, $topfacChartFilter, false, true, [], 'dash-charts'); ?>
    <?php if (!empty($facilityLabels)): ?>
    <canvas id="facilityChart" style="max-height: 300px;"></canvas>
    <?php else: ?>
    <p style="color: #8b95b5; padding: 1rem 0;">No data for the selected filters.</p>
    <?php endif; ?>
</div>
</div>

<div class="booking-card" style="margin-top: 1rem;">
    <h2>Quick Actions</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-top: 0.75rem;">
        <a href="<?= base_path(); ?>/dashboard/book-facility" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📅 Book Facility</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">Submit a new reservation request</p>
        </a>
        <a href="<?= base_path(); ?>/dashboard/my-reservations" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">📋 My Reservations</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View your booking history</p>
        </a>
        <a href="<?= base_path(); ?>/dashboard/book-facility?module=mine" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
            <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue);">🗓️ My Calendar</h3>
            <p style="margin: 0; color: #8b95b5; font-size: 0.9rem;">View bookings on your calendar</p>
        </a>
        <?php if (in_array($userRole, ['Admin', 'Staff'])): ?>
        <a href="<?= base_path(); ?>/dashboard/reports" class="stat-card" style="text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s ease;">
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

    function applyCollapseState() {
        document.querySelectorAll('.booking-card .collapsible-header, .collapsible-card .collapsible-header').forEach(function (header) {
            const targetId = header.getAttribute('data-collapse-target');
            if (!targetId) return;
            const body = document.getElementById(targetId);
            if (!body) return;
            const chevron = header.querySelector('.chevron');
            if (state[targetId]) {
                body.classList.add('is-collapsed');
                if (chevron) chevron.style.transform = 'rotate(-90deg)';
            } else {
                body.classList.remove('is-collapsed');
                if (chevron) chevron.style.transform = 'rotate(0deg)';
            }
        });
    }

    if (!document.documentElement.dataset.frsCollapseDelegated) {
        document.documentElement.dataset.frsCollapseDelegated = '1';
        document.addEventListener('click', function (e) {
            const header = e.target.closest('.booking-card .collapsible-header, .collapsible-card .collapsible-header');
            if (!header) return;
            const targetId = header.getAttribute('data-collapse-target');
            if (!targetId) return;
            const body = document.getElementById(targetId);
            if (!body) return;
            e.preventDefault();
            e.stopPropagation();
            const chevron = header.querySelector('.chevron');
            const isCollapsed = body.classList.toggle('is-collapsed');
            if (chevron) {
                chevron.style.transform = isCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
            }
            state[targetId] = isCollapsed;
            save();
        });
    }

    window.frsReinitDashboardCollapsibles = applyCollapseState;
    applyCollapseState();
})();

function applyGlobalFilter() {
    const status = document.getElementById('global-status').value;
    const facility = document.getElementById('global-facility').value;
    const months = document.getElementById('global-months').value;
    const url = new URL(window.location.href);
    ['trend', 'status', 'topfac'].forEach(function (prefix) {
        url.searchParams.set(prefix + '_status', status);
        url.searchParams.set(prefix + '_facility', facility);
        url.searchParams.set(prefix + '_months', months);
    });
    if (typeof window.frsPartialLoad === 'function') {
        window.frsPartialLoad(url.toString(), 'dash-charts');
    } else {
        window.location.href = url.toString();
    }
}

function frsReadDashChartsConfig() {
    const el = document.getElementById('dash-charts-config');
    if (!el || !el.textContent) return window.frsChartConfig || {};
    try {
        return JSON.parse(el.textContent);
    } catch (e) {
        return window.frsChartConfig || {};
    }
}

function frsInitDashboardCharts() {
    const cfg = frsReadDashChartsConfig();
    window.frsChartConfig = cfg;
    if (window.frsInitReservationCharts) {
        window.frsInitReservationCharts(cfg);
    }
}

window.frsOnPartialLoaded = function (partialId) {
    if (partialId === 'dash-charts') {
        frsInitDashboardCharts();
    }
    if (partialId === 'dash-filtered' && typeof window.frsReinitDashboardCollapsibles === 'function') {
        window.frsReinitDashboardCollapsibles();
    }
};

document.addEventListener('DOMContentLoaded', function() {
    frsInitDashboardCharts();
});
</script>

<style>
@media (max-width: 768px) {
    .chart-filter-fields {
        flex-direction: column;
    }
    .chart-filter-item {
        width: 100%;
    }
    .stat-grid {
        grid-template-columns: 1fr;
    }
    .reports-grid {
        grid-template-columns: 1fr;
    }
    .booking-wrapper {
        grid-template-columns: 1fr;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';



