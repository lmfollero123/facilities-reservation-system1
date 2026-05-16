<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

// Role-based access: Admin/Staff only
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['Admin', 'Staff'])) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/gemini_chatbot.php';
require_once __DIR__ . '/../../../../config/time_helpers.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';
$pdo = db();
$pageTitle = 'Reports & Analytics | LGU Facilities Reservation';

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $reportYear = (int)($_GET['year'] ?? date('Y'));
    $reportMonth = (int)($_GET['month'] ?? date('m'));
    $startDate = date('Y-m-01', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
    $endDate = date('Y-m-t', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
    
    if ($exportType === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="reservations_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Header row
        fputcsv($output, ['Date', 'Facility', 'Requester', 'Time Slot', 'Status', 'Purpose']);
        
        // Data rows
        $exportSql = 'SELECT r.reservation_date, f.name AS facility_name, u.name AS requester_name, 
                    r.time_slot, r.status, r.purpose
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             JOIN users u ON r.user_id = u.id';
        if ($dateFilterClause) {
            $exportSql .= ' WHERE r.reservation_date >= :start AND r.reservation_date <= :end';
        }
        $exportSql .= ' ORDER BY r.reservation_date DESC';
        $exportStmt = $pdo->prepare($exportSql);
        $exportStmt->execute($dateParams);
        
        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['reservation_date'],
                $row['facility_name'],
                $row['requester_name'],
                $row['time_slot'],
                $row['status'],
                substr($row['purpose'], 0, 100) // Limit purpose length
            ]);
        }
        
        fclose($output);
        exit;
    } elseif ($exportType === 'pdf') {
        // Generate HTML-based PDF report (can be printed to PDF by browser)
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="reservations_report_' . date('Y-m-d') . '.html"');
        
        $exportSql = 'SELECT r.reservation_date, f.name AS facility_name, u.name AS requester_name, 
                    r.time_slot, r.status, r.purpose, u.email AS requester_email
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             JOIN users u ON r.user_id = u.id';
        if ($dateFilterClause) {
            $exportSql .= ' WHERE r.reservation_date >= :start AND r.reservation_date <= :end';
        }
        $exportSql .= ' ORDER BY r.reservation_date DESC';
        $exportStmt = $pdo->prepare($exportSql);
        $exportStmt->execute($dateParams);
        $reservations = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate summary stats
        $totalCount = count($reservations);
        $approvedCount = 0;
        $deniedCount = 0;
        $pendingCount = 0;
        $cancelledCount = 0;
        
        foreach ($reservations as $res) {
            $status = strtolower($res['status']);
            if ($status === 'approved') $approvedCount++;
            elseif ($status === 'denied') $deniedCount++;
            elseif ($status === 'pending') $pendingCount++;
            elseif ($status === 'cancelled') $cancelledCount++;
        }
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Reservations Report - <?= date('F Y', mktime(0, 0, 0, $reportMonth, 1, $reportYear)); ?></title>
            <style>
                @media print {
                    @page { margin: 1cm; }
                    body { margin: 0; }
                }
                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                    color: #333;
                }
                .header {
                    border-bottom: 3px solid #2563eb;
                    padding-bottom: 15px;
                    margin-bottom: 25px;
                }
                .header h1 {
                    margin: 0;
                    color: #2563eb;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0 0 0;
                    color: #666;
                }
                .summary {
                    background: #f5f5f5;
                    padding: 15px;
                    border-radius: 5px;
                    margin-bottom: 25px;
                }
                .summary h2 {
                    margin: 0 0 10px 0;
                    font-size: 18px;
                    color: #2563eb;
                }
                .summary-grid {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 15px;
                    margin-top: 10px;
                }
                .summary-item {
                    text-align: center;
                }
                .summary-item strong {
                    display: block;
                    font-size: 24px;
                    color: #2563eb;
                }
                .summary-item span {
                    font-size: 12px;
                    color: #666;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }
                th {
                    background: #2563eb;
                    color: white;
                    padding: 10px;
                    text-align: left;
                    font-weight: bold;
                }
                td {
                    padding: 8px 10px;
                    border-bottom: 1px solid #ddd;
                }
                tr:nth-child(even) {
                    background: #f9f9f9;
                }
                .status {
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: bold;
                    display: inline-block;
                }
                .status-approved { background: #28a745; color: white; }
                .status-denied { background: #dc3545; color: white; }
                .status-pending { background: #ffc107; color: #856404; }
                .status-cancelled { background: #6c757d; color: white; }
                .footer {
                    margin-top: 30px;
                    padding-top: 15px;
                    border-top: 1px solid #ddd;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Reservations Report</h1>
                <p>Period: <?= date('F Y', mktime(0, 0, 0, $reportMonth, 1, $reportYear)); ?></p>
                <p>Generated: <?= date('F j, Y g:i A'); ?></p>
            </div>
            
            <div class="summary">
                <h2>Summary Statistics</h2>
                <div class="summary-grid">
                    <div class="summary-item">
                        <strong><?= $totalCount; ?></strong>
                        <span>Total Reservations</span>
                    </div>
                    <div class="summary-item">
                        <strong><?= $approvedCount; ?></strong>
                        <span>Approved</span>
                    </div>
                    <div class="summary-item">
                        <strong><?= $deniedCount; ?></strong>
                        <span>Denied</span>
                    </div>
                    <div class="summary-item">
                        <strong><?= $pendingCount; ?></strong>
                        <span>Pending</span>
                    </div>
                </div>
            </div>
            
            <h2>Reservation Details</h2>
            <?php if (empty($reservations)): ?>
                <p>No reservations found for this period.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Facility</th>
                            <th>Requester</th>
                            <th>Time Slot</th>
                            <th>Status</th>
                            <th>Purpose</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['reservation_date']); ?></td>
                                <td><?= htmlspecialchars($row['facility_name']); ?></td>
                                <td><?= htmlspecialchars($row['requester_name']); ?></td>
                                <td><?= htmlspecialchars($row['time_slot']); ?></td>
                                <td>
                                    <span class="status status-<?= strtolower($row['status']); ?>">
                                        <?= ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(substr($row['purpose'], 0, 100)); ?><?= strlen($row['purpose']) > 100 ? '...' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <div class="footer">
                <p>LGU Facilities Reservation System - Generated Report</p>
                <p>This report can be printed to PDF using your browser's print function (Ctrl+P / Cmd+P)</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Get date parameters (default to current month, or 'all' for all time)
$reportYear = isset($_GET['year']) ? ($_GET['year'] === 'all' ? null : (int)$_GET['year']) : date('Y');
$reportMonth = isset($_GET['month']) ? ($_GET['month'] === 'all' ? null : (int)$_GET['month']) : date('m');

// Get facility filter parameter
$facilityFilter = isset($_GET['facility']) && $_GET['facility'] !== 'all' ? (int)$_GET['facility'] : null;

// Fetch all facilities for dropdown
$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name ASC');
$allFacilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected facility name for label
$facilityName = 'All Facilities';
if ($facilityFilter) {
    foreach ($allFacilities as $fac) {
        if ($fac['id'] == $facilityFilter) {
            $facilityName = $fac['name'];
            break;
        }
    }
}

if ($reportYear === null || $reportMonth === null) {
    // Show all time data (no date filter)
    $startDate = null;
    $endDate = null;
    $filterLabel = 'All Time';
    $dateFilterClause = '';
    $dateParams = [];
} else {
    // Filter by specific month/year
    $startDate = date('Y-m-01', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
    $endDate = date('Y-m-t', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
    $filterLabel = date('F Y', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
    $dateFilterClause = 'WHERE reservation_date >= :start AND reservation_date <= :end';
    $dateParams = ['start' => $startDate, 'end' => $endDate];
}

// Add facility filter to clause
if ($facilityFilter) {
    if ($dateFilterClause) {
        $dateFilterClause .= ' AND facility_id = :facility_id';
    } else {
        $dateFilterClause = 'WHERE facility_id = :facility_id';
    }
    $dateParams['facility_id'] = $facilityFilter;
    $filterLabel .= ' - ' . $facilityName;
}

// Calculate KPIs (Global Statistics for Admin/Staff)
// Total reservations
$totalSql = 'SELECT COUNT(*) as total FROM reservations';
if ($dateFilterClause) {
    $totalSql .= ' ' . $dateFilterClause;
}
$totalStmt = $pdo->prepare($totalSql);
$totalStmt->execute($dateParams);
$totalReservations = (int)$totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Approved count
$approvedSql = 'SELECT COUNT(*) as count FROM reservations';
if ($dateFilterClause) {
    $approvedSql .= ' ' . $dateFilterClause . ' AND status = "approved"';
} else {
    $approvedSql .= ' WHERE status = "approved"';
}
$approvedStmt = $pdo->prepare($approvedSql);
$approvedStmt->execute($dateParams);
$approvedCount = (int)$approvedStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending count
$pendingSql = 'SELECT COUNT(*) as count FROM reservations';
if ($dateFilterClause) {
    $pendingSql .= ' ' . $dateFilterClause . ' AND status = "pending"';
} else {
    $pendingSql .= ' WHERE status = "pending"';
}
$pendingStmt = $pdo->prepare($pendingSql);
$pendingStmt->execute($dateParams);
$pendingCount = (int)$pendingStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Denied count
$deniedSql = 'SELECT COUNT(*) as count FROM reservations';
if ($dateFilterClause) {
    $deniedSql .= ' ' . $dateFilterClause . ' AND status = "denied"';
} else {
    $deniedSql .= ' WHERE status = "denied"';
}
$deniedStmt = $pdo->prepare($deniedSql);
$deniedStmt->execute($dateParams);
$deniedCount = (int)$deniedStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Cancelled count
$cancelledSql = 'SELECT COUNT(*) as count FROM reservations';
if ($dateFilterClause) {
    $cancelledSql .= ' ' . $dateFilterClause . ' AND status = "cancelled"';
} else {
    $cancelledSql .= ' WHERE status = "cancelled"';
}
$cancelledStmt = $pdo->prepare($cancelledSql);
$cancelledStmt->execute($dateParams);
$cancelledCount = (int)$cancelledStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Global system statistics
$totalUsersStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Resident" AND status = "active"');
$totalUsers = (int)$totalUsersStmt->fetchColumn();

$totalFacilitiesStmt = $pdo->query('SELECT COUNT(*) FROM facilities WHERE status = "available"');
$totalFacilities = (int)$totalFacilitiesStmt->fetchColumn();

$activeUsersSql = 'SELECT COUNT(DISTINCT user_id) FROM reservations';
if ($dateFilterClause) {
    $activeUsersSql .= ' ' . $dateFilterClause . ' AND status IN ("approved", "pending")';
} else {
    $activeUsersSql .= ' WHERE status IN ("approved", "pending")';
}
$activeUsersStmt = $pdo->prepare($activeUsersSql);
$activeUsersStmt->execute($dateParams);
$activeUsers = (int)$activeUsersStmt->fetchColumn();

// Approval rate
$approvalRate = $totalReservations > 0 ? round(($approvedCount / $totalReservations) * 100, 1) : 0;

// Utilization (simplified: approved reservations / total days in period * average slots per day)
// Assuming 4 time slots per day as average
if ($startDate && $endDate) {
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
    $totalPossibleSlots = $daysInMonth * 4; // Rough estimate
} else {
    // For all time, use a rough estimate based on total days since first reservation
    $firstResStmt = $pdo->query('SELECT MIN(reservation_date) as first_date FROM reservations');
    $firstDate = $firstResStmt->fetch(PDO::FETCH_ASSOC)['first_date'];
    if ($firstDate) {
        $daysDiff = max(1, (time() - strtotime($firstDate)) / 86400);
        $totalPossibleSlots = $daysDiff * 4;
    } else {
        $totalPossibleSlots = 1;
    }
}
$utilization = $totalPossibleSlots > 0 ? round(($approvedCount / $totalPossibleSlots) * 100, 1) : 0;
$utilization = min($utilization, 100); // Cap at 100%

// Average reservations per user (for this month)
$avgReservationsPerUser = $activeUsers > 0 ? round($totalReservations / $activeUsers, 1) : 0;

// Total reservations (all time)
$totalAllTimeStmt = $pdo->query('SELECT COUNT(*) FROM reservations');
$totalAllTime = (int)$totalAllTimeStmt->fetchColumn();

// Facility utilization
if ($dateFilterClause) {
    $facilityUtilSql = 'SELECT f.name, COUNT(r.id) as booking_count,
                (SELECT COUNT(*) FROM reservations r2 
                 WHERE r2.facility_id = f.id 
                 AND r2.reservation_date >= :start 
                 AND r2.reservation_date <= :end 
                 AND r2.status = "approved") as approved_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id 
             AND r.reservation_date >= :start2 
             AND r.reservation_date <= :end2
         GROUP BY f.id, f.name
         ORDER BY approved_count DESC';
    $facilityUtilStmt = $pdo->prepare($facilityUtilSql);
    $facilityUtilStmt->execute([
        'start' => $startDate,
        'end' => $endDate,
        'start2' => $startDate,
        'end2' => $endDate,
    ]);
} else {
    $facilityUtilSql = 'SELECT f.name, COUNT(r.id) as booking_count,
                (SELECT COUNT(*) FROM reservations r2 
                 WHERE r2.facility_id = f.id 
                 AND r2.status = "approved") as approved_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id
         GROUP BY f.id, f.name
         ORDER BY approved_count DESC';
    $facilityUtilStmt = $pdo->prepare($facilityUtilSql);
    $facilityUtilStmt->execute();
}
$facilityData = $facilityUtilStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate max bookings for percentage (find highest count)
$maxBookings = 0;
foreach ($facilityData as $fac) {
    $maxBookings = max($maxBookings, (int)$fac['approved_count']);
}

// Reservation outcomes breakdown
$outcomesSql = 'SELECT status, COUNT(*) as count FROM reservations';
if ($dateFilterClause) {
    $outcomesSql .= ' ' . $dateFilterClause;
}
$outcomesSql .= ' GROUP BY status';
$outcomesStmt = $pdo->prepare($outcomesSql);
$outcomesStmt->execute($dateParams);
$outcomes = $outcomesStmt->fetchAll(PDO::FETCH_ASSOC);

$outcomesMap = [
    'approved' => 0,
    'denied' => 0,
    'cancelled' => 0,
    'pending' => 0
];

foreach ($outcomes as $outcome) {
    $status = strtolower($outcome['status']);
    if (isset($outcomesMap[$status])) {
        $outcomesMap[$status] = (int)$outcome['count'];
    }
}

// Calculate shares
$outcomesTotal = array_sum($outcomesMap);
$outcomesShare = [];
foreach ($outcomesMap as $status => $count) {
    $share = $outcomesTotal > 0 ? round(($count / $outcomesTotal) * 100) : 0;
    $outcomesShare[$status] = $share;
}

// Charts data - Reservation Trends (respects filters)
$monthlyLabels = [];
$monthlyData = [];

if ($reportYear === null || $reportMonth === null) {
    // All Time: Show last 6 months
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime("-$i months"));
        $monthLabel = date('M Y', strtotime("-$i months"));
        $monthlyLabels[] = $monthLabel;
        $monthStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end'
        );
        $monthStmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
        $monthlyData[] = (int)$monthStmt->fetchColumn();
    }
} else {
    // Specific month selected: Show 6 months centered around selected month (2 before, selected, 3 after)
    $selectedDate = mktime(0, 0, 0, $reportMonth, 1, $reportYear);
    for ($i = 2; $i >= -3; $i--) {
        $monthTimestamp = strtotime("$i months", $selectedDate);
        $monthStart = date('Y-m-01', $monthTimestamp);
        $monthEnd = date('Y-m-t', $monthTimestamp);
        $monthLabel = date('M Y', $monthTimestamp);
        $monthlyLabels[] = $monthLabel;
        $monthStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end'
        );
        $monthStmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
        $monthlyData[] = (int)$monthStmt->fetchColumn();
    }
}

/**
 * Very lightweight linear-trend forecast (next N periods).
 *
 * @param array<int,int|float> $series
 * @return array<int,int>
 */
function buildSimpleForecast(array $series, int $periods = 3): array
{
    $n = count($series);
    if ($n === 0) {
        return array_fill(0, max(0, $periods), 0);
    }
    if ($n === 1) {
        return array_fill(0, max(0, $periods), max(0, (int)round((float)$series[0])));
    }

    $sumX = 0.0;
    $sumY = 0.0;
    $sumXY = 0.0;
    $sumX2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = (float)($i + 1);
        $y = (float)$series[$i];
        $sumX += $x;
        $sumY += $y;
        $sumXY += $x * $y;
        $sumX2 += $x * $x;
    }

    $den = (($n * $sumX2) - ($sumX * $sumX));
    $slope = $den != 0.0 ? ((($n * $sumXY) - ($sumX * $sumY)) / $den) : 0.0;
    $intercept = ($sumY - ($slope * $sumX)) / $n;

    $forecast = [];
    for ($i = 1; $i <= $periods; $i++) {
        $x = (float)($n + $i);
        $y = $intercept + ($slope * $x);
        $forecast[] = max(0, (int)round($y));
    }
    return $forecast;
}

$forecastValues = buildSimpleForecast($monthlyData, 3);
$forecastLabels = [];
if ($reportYear !== null && $reportMonth !== null) {
    $baseTs = mktime(0, 0, 0, $reportMonth, 1, $reportYear);
} else {
    $baseTs = strtotime(date('Y-m-01'));
}
for ($i = 1; $i <= 3; $i++) {
    $forecastLabels[] = date('M Y', strtotime('+' . $i . ' month', $baseTs));
}

// Operational occupancy (bookings + check-in/out + staff overrides).
$occupancyNow = [
    'as_of' => date('Y-m-d H:i:s'),
    'total_facilities' => 0,
    'occupied_facilities' => 0,
    'available_now' => 0,
    'occupancy_rate' => 0,
    'checked_in' => 0,
    'no_show_risk' => 0,
    'disclaimer' => '',
    'items' => [],
];
try {
    $occSnapshot = frs_build_operational_occupancy_snapshot($pdo);
    $occSum = $occSnapshot['summary'];
    $occupancyNow = [
        'as_of' => $occSnapshot['as_of'],
        'total_facilities' => (int)$occSum['total_facilities'],
        'occupied_facilities' => (int)$occSum['occupied'],
        'available_now' => (int)$occSum['available'],
        'occupancy_rate' => $occSnapshot['occupancy_rate'],
        'checked_in' => (int)$occSum['checked_in'],
        'no_show_risk' => (int)$occSum['no_show_risk'],
        'disclaimer' => $occSnapshot['disclaimer'],
        'items' => $occSnapshot['facilities'],
    ];
} catch (Throwable $e) {
    // Keep page resilient; fall back to empty occupancy card.
}

// Status distribution (for selected period)
$statusSql = 'SELECT status, COUNT(*) as count FROM reservations';
if ($dateFilterClause) {
    $statusSql .= ' ' . $dateFilterClause;
}
$statusSql .= ' GROUP BY status';
$statusStmt = $pdo->prepare($statusSql);
$statusStmt->execute($dateParams);
$statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
$statusMap = ['approved' => 0, 'pending' => 0, 'denied' => 0, 'cancelled' => 0];
foreach ($statusData as $row) {
    $k = strtolower($row['status']);
    if (isset($statusMap[$k])) {
        $statusMap[$k] = (int)$row['count'];
    }
}
$statusLabels = ['Approved','Pending','Denied','Cancelled'];
$statusCounts = [$statusMap['approved'], $statusMap['pending'], $statusMap['denied'], $statusMap['cancelled']];
$statusColors = ['#28a745', '#ff9800', '#e53935', '#6c757d'];

// Top facilities by approved bookings (for selected period)
if ($dateFilterClause) {
    // Create date-only params (without facility_id) for this query
    $dateOnlyParams = ['start' => $startDate, 'end' => $endDate];
    
    $facilitySql = 'SELECT f.name, COUNT(r.id) as booking_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id 
             AND r.status = "approved"
             AND r.reservation_date >= :start 
             AND r.reservation_date <= :end
         GROUP BY f.id, f.name
         ORDER BY booking_count DESC
         LIMIT 5';
    $facilityStmt = $pdo->prepare($facilitySql);
    $facilityStmt->execute($dateOnlyParams);
} else {
    $facilitySql = 'SELECT f.name, COUNT(r.id) as booking_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id 
             AND r.status = "approved"
         GROUP BY f.id, f.name
         ORDER BY booking_count DESC
         LIMIT 5';
    $facilityStmt = $pdo->prepare($facilitySql);
    $facilityStmt->execute();
}
$facilityDataChart = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
$facilityLabels = [];
$facilityCounts = [];
foreach ($facilityDataChart as $fac) {
    $facilityLabels[] = $fac['name'];
    $facilityCounts[] = (int)$fac['booking_count'];
}

// Handle print view request (after all data is calculated)
if (isset($_GET['print'])) {
    include __DIR__ . '/reports_print.php';
    exit;
}

/**
 * Build deterministic fallback insights so reports still work without AI API.
 */
function buildRuleBasedReportInsights(array $stats): array {
    $summary = 'Reservation demand is steady for the selected period.';
    if (($stats['approval_rate'] ?? 0) >= 70) {
        $summary = 'Reservation performance is healthy with a strong approval rate.';
    } elseif (($stats['approval_rate'] ?? 0) < 40) {
        $summary = 'Reservation performance needs attention due to a low approval rate.';
    }

    $highlights = [];
    $highlights[] = 'Total reservations: ' . number_format((int)($stats['total_reservations'] ?? 0));
    $highlights[] = 'Approved: ' . number_format((int)($stats['approved_count'] ?? 0)) . ' (' . round((float)($stats['approval_rate'] ?? 0), 1) . '%)';
    $highlights[] = 'Pending: ' . number_format((int)($stats['pending_count'] ?? 0));

    $riskFlags = [];
    if (($stats['pending_count'] ?? 0) > ($stats['approved_count'] ?? 0)) {
        $riskFlags[] = 'Pending reservations exceed approved reservations.';
    }
    if (($stats['denied_count'] ?? 0) > 0 && ($stats['denied_count'] / max(1, $stats['total_reservations'])) > 0.25) {
        $riskFlags[] = 'High denial share detected (>25%).';
    }
    if (($stats['cancelled_count'] ?? 0) > 0 && ($stats['cancelled_count'] / max(1, $stats['total_reservations'])) > 0.20) {
        $riskFlags[] = 'Cancellation share is elevated (>20%).';
    }

    $recommendations = [];
    $recommendations[] = 'Review top denied reasons and publish clearer booking guidance.';
    if (!empty($riskFlags)) {
        $recommendations[] = 'Prioritize queue cleanup and follow-up for pending requests.';
    }
    $recommendations[] = 'Monitor top utilized facilities and prepare overflow alternatives.';

    return [
        'source' => 'Rule-based',
        'summary' => $summary,
        'highlights' => $highlights,
        'risk_flags' => $riskFlags,
        'recommendations' => $recommendations,
    ];
}

/**
 * Attempt Gemini-generated narrative insights from computed report stats.
 */
function buildGeminiReportInsights(array $stats): ?array {
    if (!function_exists('geminiChatbotResponse')) {
        return null;
    }

    $systemPrompt = "You are a municipal analytics assistant. Return concise actionable insights for booking analytics.\n" .
        "Output STRICT JSON only with keys: summary (string), highlights (array of 3-5 strings), risk_flags (array of strings), recommendations (array of 3-5 strings).\n" .
        "No markdown, no code fences.";

    $userMessage = "Generate insights for this report stats JSON:\n" . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $resp = geminiChatbotResponse($systemPrompt, $userMessage, []);
    if (!$resp || empty($resp['reply'])) {
        return null;
    }

    $reply = trim((string)$resp['reply']);
    $parsed = json_decode($reply, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{[\s\S]*\}/', $reply, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }
    if (!is_array($parsed)) {
        return null;
    }

    return [
        'source' => 'AI (Gemini)',
        'summary' => (string)($parsed['summary'] ?? ''),
        'highlights' => array_values(array_filter((array)($parsed['highlights'] ?? []), 'is_string')),
        'risk_flags' => array_values(array_filter((array)($parsed['risk_flags'] ?? []), 'is_string')),
        'recommendations' => array_values(array_filter((array)($parsed['recommendations'] ?? []), 'is_string')),
    ];
}

$reportStatsForAI = [
    'period_label' => $filterLabel,
    'total_reservations' => $totalReservations,
    'approved_count' => $approvedCount,
    'pending_count' => $pendingCount,
    'denied_count' => $deniedCount,
    'cancelled_count' => $cancelledCount,
    'approval_rate' => $approvalRate,
    'utilization' => $utilization,
    'avg_reservations_per_user' => $avgReservationsPerUser,
    'top_facilities' => array_slice(array_map(function ($f) {
        return [
            'name' => $f['name'] ?? '',
            'approved_count' => (int)($f['approved_count'] ?? 0),
        ];
    }, $facilityData), 0, 5),
    'monthly_trend' => [
        'labels' => $monthlyLabels,
        'values' => $monthlyData,
    ],
];

if (isset($_GET['ai_summary'])) {
    header('Content-Type: application/json; charset=utf-8');
    $insights = buildGeminiReportInsights($reportStatsForAI);
    if (!$insights) {
        $insights = buildRuleBasedReportInsights($reportStatsForAI);
    }
    echo json_encode([
        'success' => true,
        'filter_label' => $filterLabel,
        'generated_at' => date('Y-m-d H:i:s'),
        'insights' => $insights,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['live_occupancy'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'occupancy' => $occupancyNow,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Analytics</span><span class="sep">/</span><span>Reports</span>
    </div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>Reports & Analytics</h1>
            <small>Review reservation statistics and usage patterns.</small>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <form method="GET" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <select name="facility" id="facility-filter" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                <option value="all" <?= ($facilityFilter === null) ? 'selected' : ''; ?>>All Facilities</option>
                <?php foreach ($allFacilities as $facility): ?>
                    <option value="<?= $facility['id']; ?>" <?= ($facilityFilter !== null && $facilityFilter == $facility['id']) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($facility['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="month" id="month-filter" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                <option value="all" <?= ($reportMonth === null) ? 'selected' : ''; ?>>All Time</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m; ?>" <?= ($reportMonth !== null && $m == $reportMonth) ? 'selected' : ''; ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 1)); ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year" id="year-filter" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                <option value="all" <?= ($reportYear === null) ? 'selected' : ''; ?>>All Years</option>
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?= $y; ?>" <?= ($reportYear !== null && $y == $reportYear) ? 'selected' : ''; ?>>
                        <?= $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn-primary" style="padding: 0.5rem 1rem;">Update</button>
        </form>
        <button type="button" onclick="printSummary()" class="btn-primary" style="padding: 0.5rem 1rem; white-space: nowrap;">
            📄 Print Summary
        </button>
        <button type="button" onclick="openAiSummaryModal()" class="btn-outline" style="padding: 0.5rem 1rem; white-space: nowrap;">
            ✨ Generate AI Summary
        </button>
        <script>
        // Auto-submit when "All Time" is selected in month or year
        document.getElementById('month-filter')?.addEventListener('change', function() {
            if (this.value === 'all') {
                document.getElementById('year-filter').value = 'all';
                this.form.submit();
            }
        });
        document.getElementById('year-filter')?.addEventListener('change', function() {
            if (this.value === 'all') {
                document.getElementById('month-filter').value = 'all';
                this.form.submit();
            }
        });
        
        // Print Summary function
        function printSummary() {
            const facility = document.getElementById('facility-filter').value;
            const month = document.getElementById('month-filter').value;
            const year = document.getElementById('year-filter').value;
            const printUrl = `?print=1&facility=${facility}&month=${month}&year=${year}`;
            window.open(printUrl, '_blank');
        }
        </script>
        </div>
    </div>
</div>

<div id="aiSummaryModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:100050; align-items:center; justify-content:center; padding:1rem;">
    <div style="width:min(920px,95vw); max-height:92vh; overflow:auto; background:#fff; border-radius:12px; box-shadow:0 20px 40px rgba(0,0,0,0.25);">
        <div style="padding:1rem 1.1rem; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
            <div>
                <h3 style="margin:0; color:#111827;">AI Summary</h3>
                <small id="aiSummaryMeta" style="color:#6b7280;">Generate insights based on current filters.</small>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <button class="btn-outline" type="button" onclick="printAiSummary()">🖨️ Print</button>
                <button class="btn-outline" type="button" onclick="closeAiSummaryModal()">✕ Close</button>
            </div>
        </div>
        <div id="aiSummaryContent" style="padding:1rem 1.1rem;">
            <p style="margin:0; color:#6b7280;">Click "Generate AI Summary" to load insights.</p>
        </div>
    </div>
</div>

<div class="reports-grid" style="margin-bottom: 1.5rem;">
    <section class="booking-card">
        <h2>Reservation Trends<?= ($reportYear !== null && $reportMonth !== null) ? ' (Around ' . date('F Y', mktime(0, 0, 0, $reportMonth, 1, $reportYear)) . ')' : ' (Last 6 Months)'; ?></h2>
        <p style="color:#8b95b5; font-size:0.9rem; margin-bottom:1rem;">Total reservations per month<?= ($reportYear !== null && $reportMonth !== null) ? ' - showing 6 months around selected period' : ''; ?></p>
        <canvas id="monthlyChart" style="max-height: 320px;"></canvas>
    </section>
    <section class="booking-card">
        <h2>Status Breakdown</h2>
        <p style="color:#8b95b5; font-size:0.9rem; margin-bottom:1rem;">Distribution of reservation statuses</p>
        <canvas id="statusChart" style="max-height: 320px;"></canvas>
    </section>
</div>

<?php if (!empty($facilityLabels)): ?>
<div class="booking-card" style="margin-bottom: 1.5rem;">
    <h2>Top Facilities by Approved Bookings</h2>
    <p style="color:#8b95b5; font-size:0.9rem; margin-bottom:1rem;">Highest utilized facilities</p>
    <canvas id="facilityChart" style="max-height: 320px;"></canvas>
</div>
<?php endif; ?>

<div class="reports-grid" style="margin-bottom: 1.5rem;">
    <section class="booking-card">
        <h2>Predictive Analytics Forecast</h2>
        <p style="color:#8b95b5; font-size:0.9rem; margin-bottom:1rem;">
            Projected reservation demand for the next 3 months (trend-based).
        </p>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:0.75rem;">
            <?php foreach ($forecastLabels as $idx => $label): ?>
                <div style="padding:0.85rem; border-radius:10px; background:#f8fafc; border:1px solid #e5e7eb;">
                    <div style="font-size:0.82rem; color:#6b7280;"><?= htmlspecialchars($label); ?></div>
                    <div style="font-size:1.45rem; font-weight:800; color:#1d4ed8; margin-top:0.2rem;">
                        <?= (int)($forecastValues[$idx] ?? 0); ?>
                    </div>
                    <div style="font-size:0.78rem; color:#64748b;">forecasted reservations</div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="booking-card">
        <h2>Operational Occupancy</h2>
        <p style="color:#8b95b5; font-size:0.9rem; margin-bottom:1rem;">
            <?= htmlspecialchars($occupancyNow['disclaimer'] ?: 'Estimated status from bookings, check-in/out, and staff updates.'); ?>
            <a href="<?= base_path(); ?>/dashboard/occupancy-monitor" style="margin-left:0.35rem;">Open live board</a>
        </p>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap:0.7rem; margin-bottom:0.85rem;">
            <div style="padding:0.75rem; background:#f8fafc; border-radius:8px;">
                <div style="font-size:0.75rem; color:#6b7280;">Total Facilities</div>
                <div id="occ-total" style="font-size:1.25rem; font-weight:800; color:#0f172a;"><?= (int)$occupancyNow['total_facilities']; ?></div>
            </div>
            <div style="padding:0.75rem; background:#ecfdf5; border-radius:8px;">
                <div style="font-size:0.75rem; color:#047857;">Occupied / busy</div>
                <div id="occ-occupied" style="font-size:1.25rem; font-weight:800; color:#047857;"><?= (int)$occupancyNow['occupied_facilities']; ?></div>
            </div>
            <div style="padding:0.75rem; background:#dcfce7; border-radius:8px;">
                <div style="font-size:0.75rem; color:#14532d;">On-site</div>
                <div id="occ-checkedin" style="font-size:1.25rem; font-weight:800; color:#14532d;"><?= (int)($occupancyNow['checked_in'] ?? 0); ?></div>
            </div>
            <div style="padding:0.75rem; background:#fef3c7; border-radius:8px;">
                <div style="font-size:0.75rem; color:#92400e;">No-show risk</div>
                <div id="occ-noshow" style="font-size:1.25rem; font-weight:800; color:#92400e;"><?= (int)($occupancyNow['no_show_risk'] ?? 0); ?></div>
            </div>
            <div style="padding:0.75rem; background:#eff6ff; border-radius:8px;">
                <div style="font-size:0.75rem; color:#1d4ed8;">Occupancy Rate</div>
                <div id="occ-rate" style="font-size:1.25rem; font-weight:800; color:#1d4ed8;"><?= htmlspecialchars((string)$occupancyNow['occupancy_rate']); ?>%</div>
            </div>
        </div>
        <small id="occ-asof" style="color:#6b7280;">
            As of <?= htmlspecialchars((string)$occupancyNow['as_of']); ?>
        </small>
    </section>
</div>

<section>
    <div class="report-card">
        <h2>Monthly Reservation Volume</h2>
        <div class="kpi-row">
            <div class="kpi">
                <span>Total Reservations (This Month)</span>
                <strong><?= number_format($totalReservations); ?></strong>
                <small>Across all facilities in <?= date('F Y', mktime(0, 0, 0, $reportMonth, 1, $reportYear)); ?></small>
            </div>
            <div class="kpi">
                <span>Approval Rate</span>
                <strong><?= $approvalRate; ?>%</strong>
                <small><?= number_format($approvedCount); ?> of <?= number_format($totalReservations); ?> approved</small>
            </div>
            <div class="kpi">
                <span>Utilization</span>
                <strong><?= $utilization; ?>%</strong>
                <small>Occupied time slots vs. available</small>
            </div>
        </div>
        
        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e8ecf4;">
            <h3 style="margin: 0 0 1rem; font-size: 1.1rem; color: var(--gov-blue-dark);">Global System Statistics</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div style="padding: 1rem; background: #f9fafc; border-radius: 8px;">
                    <div style="font-size: 0.85rem; color: #5b6888; margin-bottom: 0.25rem;">Total Users</div>
                    <div style="font-size: 1.75rem; font-weight: 700; color: var(--gov-blue-dark);"><?= number_format($totalUsers); ?></div>
                    <div style="font-size: 0.8rem; color: #8b95b5; margin-top: 0.25rem;"><?= number_format($activeUsers); ?> active this month</div>
                </div>
                <div style="padding: 1rem; background: #f9fafc; border-radius: 8px;">
                    <div style="font-size: 0.85rem; color: #5b6888; margin-bottom: 0.25rem;">Available Facilities</div>
                    <div style="font-size: 1.75rem; font-weight: 700; color: var(--gov-blue-dark);"><?= number_format($totalFacilities); ?></div>
                    <div style="font-size: 0.8rem; color: #8b95b5; margin-top: 0.25rem;">Facilities in system</div>
                </div>
                <div style="padding: 1rem; background: #f9fafc; border-radius: 8px;">
                    <div style="font-size: 0.85rem; color: #5b6888; margin-bottom: 0.25rem;">Total All-Time</div>
                    <div style="font-size: 1.75rem; font-weight: 700; color: var(--gov-blue-dark);"><?= number_format($totalAllTime); ?></div>
                    <div style="font-size: 0.8rem; color: #8b95b5; margin-top: 0.25rem;">All reservations ever</div>
                </div>
                <div style="padding: 1rem; background: #f9fafc; border-radius: 8px;">
                    <div style="font-size: 0.85rem; color: #5b6888; margin-bottom: 0.25rem;">Avg per User</div>
                    <div style="font-size: 1.75rem; font-weight: 700; color: var(--gov-blue-dark);"><?= $avgReservationsPerUser; ?></div>
                    <div style="font-size: 0.8rem; color: #8b95b5; margin-top: 0.25rem;">This month</div>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e8ecf4;">
            <h3 style="margin: 0 0 1rem; font-size: 1.1rem; color: var(--gov-blue-dark);">Status Breakdown (This Month)</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem;">
                <div style="padding: 0.75rem; background: #e8f5e9; border-radius: 6px; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #388e3c;"><?= number_format($approvedCount); ?></div>
                    <div style="font-size: 0.85rem; color: #5b6888; margin-top: 0.25rem;">Approved</div>
                </div>
                <div style="padding: 0.75rem; background: #fff3e0; border-radius: 6px; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #f57c00;"><?= number_format($pendingCount); ?></div>
                    <div style="font-size: 0.85rem; color: #5b6888; margin-top: 0.25rem;">Pending</div>
                </div>
                <div style="padding: 0.75rem; background: #ffebee; border-radius: 6px; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #d32f2f;"><?= number_format($deniedCount); ?></div>
                    <div style="font-size: 0.85rem; color: #5b6888; margin-top: 0.25rem;">Denied</div>
                </div>
                <div style="padding: 0.75rem; background: #f5f5f5; border-radius: 6px; text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #616161;"><?= number_format($cancelledCount); ?></div>
                    <div style="font-size: 0.85rem; color: #5b6888; margin-top: 0.25rem;">Cancelled</div>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e8ecf4;">
            <h3 style="margin: 0 0 1rem; font-size: 1.1rem; color: var(--gov-blue-dark);">Facility Utilization</h3>
            <?php if (empty($facilityData)): ?>
                <p style="color: #8b95b5; padding: 1rem 0;">No facility data available for this period.</p>
            <?php else: ?>
                <?php foreach ($facilityData as $facility): ?>
                    <?php
                    $bookings = (int)$facility['approved_count'];
                    $percentage = $maxBookings > 0 ? round(($bookings / $maxBookings) * 100) : 0;
                    ?>
                    <div class="bar-row">
                        <span><?= htmlspecialchars($facility['name']); ?> 
                            <small style="color: #8b95b5; font-size: 0.85rem;">(<?= $bookings; ?> approved)</small>
                        </span>
                        <div class="bar-track">
                            <div class="bar-fill" style="width: <?= $percentage; ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="report-card" style="margin-top: 1.5rem;">
        <h2>Reservation Outcomes</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Outcome</th>
                <th>Count</th>
                <th>Share</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><span class="status-badge status-approved">Approved</span></td>
                <td><strong><?= number_format($outcomesMap['approved']); ?></strong></td>
                <td><?= $outcomesShare['approved']; ?>%</td>
            </tr>
            <tr>
                <td><span class="status-badge status-denied">Denied</span></td>
                <td><strong><?= number_format($outcomesMap['denied']); ?></strong></td>
                <td><?= $outcomesShare['denied']; ?>%</td>
            </tr>
            <tr>
                <td><span class="status-badge status-cancelled">Cancelled</span></td>
                <td><strong><?= number_format($outcomesMap['cancelled']); ?></strong></td>
                <td><?= $outcomesShare['cancelled']; ?>%</td>
            </tr>
            <?php if ($outcomesMap['pending'] > 0): ?>
            <tr>
                <td><span class="status-badge status-pending">Pending</span></td>
                <td><strong><?= number_format($outcomesMap['pending']); ?></strong></td>
                <td><?= $outcomesShare['pending']; ?>%</td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
function closeAiSummaryModal() {
    const modal = document.getElementById('aiSummaryModal');
    if (modal) modal.style.display = 'none';
}

function renderAiSummaryContent(payload) {
    const contentEl = document.getElementById('aiSummaryContent');
    const metaEl = document.getElementById('aiSummaryMeta');
    if (!contentEl || !payload) return;

    const insights = payload.insights || {};
    const esc = (v) => {
        const div = document.createElement('div');
        div.textContent = String(v ?? '');
        return div.innerHTML;
    };
    const list = (arr) => Array.isArray(arr) && arr.length
        ? `<ul style="margin:0; padding-left:1.1rem;">${arr.map(i => `<li>${esc(i)}</li>`).join('')}</ul>`
        : '<p style="margin:0; color:#6b7280;">No data.</p>';

    if (metaEl) {
        metaEl.textContent = `Source: ${insights.source || 'N/A'} | Filter: ${payload.filter_label || ''} | Generated: ${payload.generated_at || ''}`;
    }

    contentEl.innerHTML = `
        <div style="margin-bottom:0.9rem;">
            <div style="font-weight:700; margin-bottom:0.35rem;">Executive Summary</div>
            <p style="margin:0; color:#374151;">${esc(insights.summary || 'No summary available.')}</p>
        </div>
        <div style="margin-bottom:0.9rem;">
            <div style="font-weight:700; margin-bottom:0.35rem;">Highlights</div>
            ${list(insights.highlights || [])}
        </div>
        <div style="margin-bottom:0.9rem;">
            <div style="font-weight:700; margin-bottom:0.35rem;">Risk Flags</div>
            ${list(insights.risk_flags || [])}
        </div>
        <div style="margin-bottom:0.2rem;">
            <div style="font-weight:700; margin-bottom:0.35rem;">Recommended Actions</div>
            ${list(insights.recommendations || [])}
        </div>
    `;
}

function openAiSummaryModal() {
    const modal = document.getElementById('aiSummaryModal');
    const contentEl = document.getElementById('aiSummaryContent');
    if (!modal || !contentEl) return;
    modal.style.display = 'flex';
    contentEl.innerHTML = '<p style="margin:0; color:#6b7280;">Generating AI summary...</p>';

    const facility = document.getElementById('facility-filter')?.value || 'all';
    const month = document.getElementById('month-filter')?.value || 'all';
    const year = document.getElementById('year-filter')?.value || 'all';
    const url = `?ai_summary=1&facility=${encodeURIComponent(facility)}&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`;

    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                throw new Error('Unable to generate AI summary.');
            }
            renderAiSummaryContent(data);
        })
        .catch(err => {
            contentEl.innerHTML = `<p style="margin:0; color:#b91c1c;">${err.message || 'Failed to generate AI summary.'}</p>`;
        });
}

function printAiSummary() {
    const contentEl = document.getElementById('aiSummaryContent');
    const metaEl = document.getElementById('aiSummaryMeta');
    if (!contentEl) return;

    const win = window.open('', '_blank');
    if (!win) return;

    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>AI Summary Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; color: #111827; }
                h1 { margin: 0 0 8px; color: #4f46e5; }
                .meta { color: #6b7280; font-size: 12px; margin-bottom: 14px; }
                .card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; }
                ul { margin-top: 0; }
            </style>
        </head>
        <body>
            <h1>AI Summary</h1>
            <div class="meta">${metaEl ? metaEl.textContent : ''}</div>
            <div class="card">${contentEl.innerHTML}</div>
        </body>
        </html>
    `;
    win.document.open();
    win.document.write(html);
    win.document.close();
    win.onload = function () { win.print(); };
}

document.addEventListener('DOMContentLoaded', function() {
    // Draw values directly on chart elements so users don't need hover/tooltips.
    const alwaysValueLabelsPlugin = {
        id: 'alwaysValueLabels',
        afterDatasetsDraw(chart, args, pluginOptions) {
            const opts = pluginOptions || {};
            const color = opts.color || '#111827';
            const fontSize = opts.fontSize || 12;
            const weight = opts.fontWeight || '700';
            const formatter = typeof opts.formatter === 'function'
                ? opts.formatter
                : (v) => String(v);

            const ctx = chart.ctx;
            ctx.save();
            ctx.fillStyle = color;
            ctx.font = `${weight} ${fontSize}px Arial`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            chart.data.datasets.forEach((dataset, datasetIndex) => {
                const meta = chart.getDatasetMeta(datasetIndex);
                if (meta.hidden) return;
                meta.data.forEach((element, dataIndex) => {
                    const raw = dataset.data[dataIndex];
                    if (raw === null || raw === undefined || Number(raw) === 0) return;
                    const label = formatter(raw, dataIndex, dataset, chart);
                    const pos = element.tooltipPosition();
                    ctx.fillText(label, pos.x, pos.y);
                });
            });
            ctx.restore();
        }
    };

    const modal = document.getElementById('aiSummaryModal');
    if (modal) {
        // Move modal to <body> so fixed positioning is viewport-based
        // (avoids transformed ancestors affecting placement).
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeAiSummaryModal();
        });
    }
    const monthlyCtx = document.getElementById('monthlyChart');
    if (monthlyCtx && window.Chart) {
        new Chart(monthlyCtx, {
            type: 'line',
            plugins: [alwaysValueLabelsPlugin],
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
                    legend: { display: false },
                    alwaysValueLabels: { color: '#1f2937', fontSize: 11 }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    const statusCtx = document.getElementById('statusChart');
    if (statusCtx && window.Chart) {
        new Chart(statusCtx, {
            type: 'doughnut',
            plugins: [alwaysValueLabelsPlugin],
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
                        labels: { padding: 15, font: { size: 12 } }
                    },
                    alwaysValueLabels: { color: '#ffffff', fontSize: 12 }
                },
                cutout: '60%'
            }
        });
    }

    const facilityCtx = document.getElementById('facilityChart');
    if (facilityCtx && window.Chart) {
        new Chart(facilityCtx, {
            type: 'bar',
            plugins: [alwaysValueLabelsPlugin],
            data: {
                labels: <?= json_encode($facilityLabels); ?>,
                datasets: [{
                    label: 'Approved Bookings',
                    data: <?= json_encode($facilityCounts); ?>,
                    backgroundColor: 'rgba(0, 71, 171, 0.85)',
                    borderColor: '#0047ab',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    alwaysValueLabels: { color: '#111827', fontSize: 11 }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Realtime occupancy polling (every 30s).
    function refreshRealtimeOccupancy() {
        const url = '?live_occupancy=1&facility='
            + encodeURIComponent(document.getElementById('facility-filter')?.value || 'all')
            + '&month=' + encodeURIComponent(document.getElementById('month-filter')?.value || 'all')
            + '&year=' + encodeURIComponent(document.getElementById('year-filter')?.value || 'all');
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.success || !data.occupancy) return;
                const occ = data.occupancy;
                const totalEl = document.getElementById('occ-total');
                const occupiedEl = document.getElementById('occ-occupied');
                const rateEl = document.getElementById('occ-rate');
                const asofEl = document.getElementById('occ-asof');
                if (totalEl) totalEl.textContent = String(occ.total_facilities ?? 0);
                if (occupiedEl) occupiedEl.textContent = String(occ.occupied_facilities ?? 0);
                if (rateEl) rateEl.textContent = String(occ.occupancy_rate ?? 0) + '%';
                const checkedEl = document.getElementById('occ-checkedin');
                const noshowEl = document.getElementById('occ-noshow');
                if (checkedEl) checkedEl.textContent = String(occ.checked_in ?? 0);
                if (noshowEl) noshowEl.textContent = String(occ.no_show_risk ?? 0);
                if (asofEl) asofEl.textContent = 'As of ' + String(occ.as_of ?? '');
            })
            .catch(() => {});
    }
    setInterval(refreshRealtimeOccupancy, 30000);
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';





