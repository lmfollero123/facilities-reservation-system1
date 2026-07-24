<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'reports')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/gemini_chatbot.php';
require_once __DIR__ . '/../../../../config/time_helpers.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';
require_once __DIR__ . '/../../../../config/analytics_chart_filters.php';
require_once __DIR__ . '/../../../../config/lookups.php';
$pdo = db();
$pageTitle = 'Reports & Analytics | LGU Facilities Reservation';

$defaultYear = (int)date('Y');
$defaultMonth = (int)date('m');

$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name ASC');
$allFacilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Legacy global query params → overview (kpi) filters
if (!isset($_GET['kpi_month']) && (isset($_GET['month']) || isset($_GET['year']) || isset($_GET['facility']))) {
    if (isset($_GET['month'])) {
        $_GET['kpi_month'] = $_GET['month'];
    }
    if (isset($_GET['year'])) {
        $_GET['kpi_year'] = $_GET['year'];
    }
    if (isset($_GET['facility'])) {
        $_GET['kpi_facility'] = $_GET['facility'];
    }
}

// Independent filter period per widget (defaults: current month, all facilities)
$kpiPeriod = frs_parse_reports_period('kpi', $defaultYear, $defaultMonth, null);
$trendPeriod = frs_parse_reports_period('trend', $defaultYear, $defaultMonth, null);
$statusPeriod = frs_parse_reports_period('status', $defaultYear, $defaultMonth, null);
$topfacPeriod = frs_parse_reports_period('topfac', $defaultYear, $defaultMonth, null);
$forecastPeriod = frs_parse_reports_period('forecast', $defaultYear, $defaultMonth, null);
$utilPeriod = frs_parse_reports_period('util', $defaultYear, $defaultMonth, null);
$outcomesPeriod = frs_parse_reports_period('outcomes', $defaultYear, $defaultMonth, null);

$occFacilityFilter = (isset($_GET['occ_facility']) && $_GET['occ_facility'] !== '' && $_GET['occ_facility'] !== 'all')
    ? (int)$_GET['occ_facility'] : null;

// KPI / overview block uses kpi period
$reportYear = $kpiPeriod['year'];
$reportMonth = $kpiPeriod['month'];
$facilityFilter = $kpiPeriod['facility'];
$startDate = $kpiPeriod['start'];
$endDate = $kpiPeriod['end'];
$filterLabel = $kpiPeriod['label'];
$dateFilterClause = $kpiPeriod['clause'];
$dateParams = $kpiPeriod['params'];

$facilityName = 'All Facilities';
if ($kpiPeriod['facility']) {
    foreach ($allFacilities as $fac) {
        if ((int)$fac['id'] === $kpiPeriod['facility']) {
            $facilityName = $fac['name'];
            break;
        }
    }
}

// Export CSV / printable HTML (uses Overview KPIs filter: kpi_year, kpi_month, kpi_facility)
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $exportPeriod = $kpiPeriod;
    $exportLabel = $exportPeriod['label'];
    $reportYear = $exportPeriod['year'] ?? (int)date('Y');
    $reportMonth = $exportPeriod['month'] ?? (int)date('m');

    $exportConditions = [];
    $exportParams = [];
    if ($exportPeriod['start'] && $exportPeriod['end']) {
        $exportConditions[] = 'r.reservation_date >= :start AND r.reservation_date <= :end';
        $exportParams['start'] = $exportPeriod['start'];
        $exportParams['end'] = $exportPeriod['end'];
    }
    if ($exportPeriod['facility']) {
        $exportConditions[] = 'r.facility_id = :facility_id';
        $exportParams['facility_id'] = $exportPeriod['facility'];
    }
    $exportWhere = $exportConditions !== []
        ? ' WHERE ' . implode(' AND ', $exportConditions)
        : '';

    $exportSql = 'SELECT r.reservation_date, f.name AS facility_name, u.name AS requester_name,
            r.time_slot, r.status, r.purpose
            FROM reservations r
            INNER JOIN facilities f ON r.facility_id = f.id
            INNER JOIN users u ON r.user_id = u.id'
        . $exportWhere
        . ' ORDER BY r.reservation_date DESC';

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reservations_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Period', $exportLabel]);
        fputcsv($output, ['Date', 'Facility', 'Requester', 'Time Slot', 'Status', 'Purpose']);
        $exportStmt = $pdo->prepare($exportSql);
        $exportStmt->execute($exportParams);
        $rowCount = 0;
        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['reservation_date'],
                $row['facility_name'],
                $row['requester_name'],
                $row['time_slot'],
                $row['status'],
                substr((string)$row['purpose'], 0, 100),
            ]);
            $rowCount++;
        }
        if ($rowCount === 0) {
            fputcsv($output, ['No reservations found for the selected period.']);
        }
        fclose($output);
        exit;
    }

    if ($exportType === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reservations_' . date('Y-m-d') . '.html"');
        $exportStmt = $pdo->prepare($exportSql);
        $exportStmt->execute($exportParams);
        $reservations = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCount = count($reservations);
        $approvedCount = $deniedCount = $pendingCount = $cancelledCount = 0;
        foreach ($reservations as $res) {
            $status = strtolower((string)$res['status']);
            if (frs_reservation_status_is_final($pdo, $status) && $status !== 'completed') {
                if ($status === 'denied') {
                    $deniedCount++;
                } else {
                    $cancelledCount++;
                }
            } elseif ($status === 'approved') {
                $approvedCount++;
            } else {
                $pendingCount++;
            }
        }
        $periodTitle = $exportLabel;
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Reservations Report — <?= htmlspecialchars($periodTitle, ENT_QUOTES, 'UTF-8'); ?></title>
            <style>
                @media print { @page { margin: 1cm; } body { margin: 0; } }
                body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
                .header { border-bottom: 3px solid #2563eb; padding-bottom: 15px; margin-bottom: 25px; }
                .header h1 { margin: 0; color: #2563eb; font-size: 24px; }
                .header p { margin: 5px 0 0; color: #666; }
                .summary { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 25px; }
                .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 10px; }
                .summary-item { text-align: center; }
                .summary-item strong { display: block; font-size: 24px; color: #2563eb; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background: #2563eb; color: white; padding: 10px; text-align: left; }
                td { padding: 8px 10px; border-bottom: 1px solid #ddd; }
                tr:nth-child(even) { background: #f9f9f9; }
                .status { padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
                .status-approved { background: #28a745; color: white; }
                .status-denied { background: #dc3545; color: white; }
                .status-pending { background: #ffc107; color: #856404; }
                .status-cancelled { background: #6c757d; color: white; }
                .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Reservations Report</h1>
                <p>Period: <?= htmlspecialchars($periodTitle, ENT_QUOTES, 'UTF-8'); ?><?= $facilityName !== 'All Facilities' ? ' — ' . htmlspecialchars($facilityName, ENT_QUOTES, 'UTF-8') : ''; ?></p>
                <p>Generated: <?= date('F j, Y g:i A'); ?></p>
            </div>
            <div class="summary">
                <h2>Summary Statistics</h2>
                <div class="summary-grid">
                    <div class="summary-item"><strong><?= $totalCount; ?></strong><span>Total</span></div>
                    <div class="summary-item"><strong><?= $approvedCount; ?></strong><span>Approved</span></div>
                    <div class="summary-item"><strong><?= $deniedCount; ?></strong><span>Denied</span></div>
                    <div class="summary-item"><strong><?= $pendingCount; ?></strong><span>Pending</span></div>
                </div>
            </div>
            <h2>Reservation Details</h2>
            <?php if (empty($reservations)): ?>
                <p>No reservations found for this period.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Date</th><th>Facility</th><th>Requester</th><th>Time Slot</th><th>Status</th><th>Purpose</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['reservation_date']); ?></td>
                                <td><?= htmlspecialchars($row['facility_name']); ?></td>
                                <td><?= htmlspecialchars($row['requester_name']); ?></td>
                                <td><?= htmlspecialchars($row['time_slot']); ?></td>
                                <td><span class="status status-<?= htmlspecialchars(strtolower($row['status'])); ?>"><?= htmlspecialchars(ucfirst($row['status'])); ?></span></td>
                                <td><?= htmlspecialchars(mb_substr((string)$row['purpose'], 0, 100)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="footer">
                <p>LGU Facilities Reservation System — print to PDF via browser (Ctrl+P)</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

if ($facilityFilter && strpos($filterLabel, $facilityName) === false) {
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

// Cancelled count - use lookup values for final statuses (excluding denied)
$finalStatuses = [];
if (frs_lookups_table_ready($pdo)) {
    foreach (frs_lookup_values($pdo, 'reservation_status', false) as $status) {
        if (($status['metadata']['is_final'] ?? false) && $status['slug'] !== 'denied') {
            $finalStatuses[] = $status['slug'];
        }
    }
} else {
    // Fallback to hardcoded statuses
    $finalStatuses = ['cancelled', 'completed'];
}
$cancelledSql = 'SELECT COUNT(*) as count FROM reservations';
if ($dateFilterClause) {
    $cancelledSql .= ' ' . $dateFilterClause . ' AND status IN ("' . implode('", "', $finalStatuses) . '")';
} else {
    $cancelledSql .= ' WHERE status IN ("' . implode('", "', $finalStatuses) . '")';
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

// Facility utilization bars (util chart filters)
if ($utilPeriod['start'] && $utilPeriod['end']) {
    $utilParams = [
        'start' => $utilPeriod['start'],
        'end' => $utilPeriod['end'],
        'start2' => $utilPeriod['start'],
        'end2' => $utilPeriod['end'],
    ];
    $facilityUtilSql = 'SELECT f.name, COUNT(r.id) as booking_count,
                (SELECT COUNT(*) FROM reservations r2
                 WHERE r2.facility_id = f.id
                 AND r2.reservation_date >= :start
                 AND r2.reservation_date <= :end
                 AND r2.status = "approved") as approved_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id
             AND r.reservation_date >= :start2
             AND r.reservation_date <= :end2';
    if ($utilPeriod['facility']) {
        $facilityUtilSql .= ' AND f.id = :facility_id';
        $utilParams['facility_id'] = $utilPeriod['facility'];
    }
    $facilityUtilSql .= ' GROUP BY f.id, f.name ORDER BY approved_count DESC';
    $facilityUtilStmt = $pdo->prepare($facilityUtilSql);
    $facilityUtilStmt->execute($utilParams);
} else {
    $utilParams = [];
    $facilityUtilSql = 'SELECT f.name, COUNT(r.id) as booking_count,
                (SELECT COUNT(*) FROM reservations r2
                 WHERE r2.facility_id = f.id
                 AND r2.status = "approved") as approved_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id';
    if ($utilPeriod['facility']) {
        $facilityUtilSql .= ' WHERE f.id = :facility_id';
        $utilParams['facility_id'] = $utilPeriod['facility'];
    }
    $facilityUtilSql .= ' GROUP BY f.id, f.name ORDER BY approved_count DESC';
    $facilityUtilStmt = $pdo->prepare($facilityUtilSql);
    $facilityUtilStmt->execute($utilParams);
}
$facilityData = $facilityUtilStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate max bookings for percentage (find highest count)
$maxBookings = 0;
foreach ($facilityData as $fac) {
    $maxBookings = max($maxBookings, (int)$fac['approved_count']);
}

// Reservation outcomes (outcomes chart filters)
$outcomesSql = 'SELECT status, COUNT(*) as count FROM reservations';
if ($outcomesPeriod['clause']) {
    $outcomesSql .= ' ' . $outcomesPeriod['clause'];
}
$outcomesSql .= ' GROUP BY status';
$outcomesStmt = $pdo->prepare($outcomesSql);
$outcomesStmt->execute($outcomesPeriod['params']);
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

// Charts data - Reservation Trends (trend chart filters)
$monthlyLabels = [];
$monthlyData = [];

if ($trendPeriod['year'] === null || $trendPeriod['month'] === null) {
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime("-$i months"));
        $monthlyLabels[] = date('M Y', strtotime("-$i months"));
        $sql = 'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end';
        $params = ['start' => $monthStart, 'end' => $monthEnd];
        if ($trendPeriod['facility']) {
            $sql .= ' AND facility_id = :facility_id';
            $params['facility_id'] = $trendPeriod['facility'];
        }
        $monthStmt = $pdo->prepare($sql);
        $monthStmt->execute($params);
        $monthlyData[] = (int)$monthStmt->fetchColumn();
    }
} else {
    $selectedDate = mktime(0, 0, 0, (int)$trendPeriod['month'], 1, (int)$trendPeriod['year']);
    for ($i = 2; $i >= -3; $i--) {
        $monthTimestamp = strtotime("$i months", $selectedDate);
        $monthStart = date('Y-m-01', $monthTimestamp);
        $monthEnd = date('Y-m-t', $monthTimestamp);
        $monthlyLabels[] = date('M Y', $monthTimestamp);
        $sql = 'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end';
        $params = ['start' => $monthStart, 'end' => $monthEnd];
        if ($trendPeriod['facility']) {
            $sql .= ' AND facility_id = :facility_id';
            $params['facility_id'] = $trendPeriod['facility'];
        }
        $monthStmt = $pdo->prepare($sql);
        $monthStmt->execute($params);
        $monthlyData[] = (int)$monthStmt->fetchColumn();
    }
}

// Forecast series (forecast chart filters)
$forecastMonthlyLabels = [];
$forecastMonthlyData = [];
if ($forecastPeriod['year'] === null || $forecastPeriod['month'] === null) {
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime("-$i months"));
        $forecastMonthlyLabels[] = date('M Y', strtotime("-$i months"));
        $sql = 'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end';
        $params = ['start' => $monthStart, 'end' => $monthEnd];
        if ($forecastPeriod['facility']) {
            $sql .= ' AND facility_id = :facility_id';
            $params['facility_id'] = $forecastPeriod['facility'];
        }
        $monthStmt = $pdo->prepare($sql);
        $monthStmt->execute($params);
        $forecastMonthlyData[] = (int)$monthStmt->fetchColumn();
    }
} else {
    $selectedDate = mktime(0, 0, 0, (int)$forecastPeriod['month'], 1, (int)$forecastPeriod['year']);
    for ($i = 2; $i >= -3; $i--) {
        $monthTimestamp = strtotime("$i months", $selectedDate);
        $monthStart = date('Y-m-01', $monthTimestamp);
        $monthEnd = date('Y-m-t', $monthTimestamp);
        $forecastMonthlyLabels[] = date('M Y', $monthTimestamp);
        $sql = 'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end';
        $params = ['start' => $monthStart, 'end' => $monthEnd];
        if ($forecastPeriod['facility']) {
            $sql .= ' AND facility_id = :facility_id';
            $params['facility_id'] = $forecastPeriod['facility'];
        }
        $monthStmt = $pdo->prepare($sql);
        $monthStmt->execute($params);
        $forecastMonthlyData[] = (int)$monthStmt->fetchColumn();
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

$forecastValues = buildSimpleForecast($forecastMonthlyData, 3);
$forecastLabels = [];
if ($forecastPeriod['year'] !== null && $forecastPeriod['month'] !== null) {
    $baseTs = mktime(0, 0, 0, (int)$forecastPeriod['month'], 1, (int)$forecastPeriod['year']);
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

if ($occFacilityFilter && !empty($occupancyNow['items'])) {
    $occupancyNow['items'] = array_values(array_filter(
        $occupancyNow['items'],
        static fn(array $item): bool => (int)($item['facility_id'] ?? 0) === $occFacilityFilter
    ));
    $occupied = 0;
    foreach ($occupancyNow['items'] as $item) {
        if (!empty($item['is_occupied'])) {
            $occupied++;
        }
    }
    $total = count($occupancyNow['items']);
    $occupancyNow['total_facilities'] = $total;
    $occupancyNow['occupied_facilities'] = $occupied;
    $occupancyNow['available_now'] = max(0, $total - $occupied);
    $occupancyNow['occupancy_rate'] = $total > 0 ? round(($occupied / $total) * 100, 1) : 0;
}

// Status distribution (status chart filters) - use lookup values
$statusSql = 'SELECT status, COUNT(*) as count FROM reservations';
if ($statusPeriod['clause']) {
    $statusSql .= ' ' . $statusPeriod['clause'];
}
$statusSql .= ' GROUP BY status';
$statusStmt = $pdo->prepare($statusSql);
$statusStmt->execute($statusPeriod['params']);
$statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

// Build status map from lookup values
$statusMap = [];
$statusLabels = [];
$statusColors = [];
if (frs_lookups_table_ready($pdo)) {
    $lookupStatuses = frs_lookup_values($pdo, 'reservation_status');
    foreach ($lookupStatuses as $status) {
        $statusMap[$status['slug']] = 0;
        $statusLabels[] = $status['label'];
        // Use badge_class from metadata or fallback to slug
        $badgeClass = $status['metadata']['badge_class'] ?? $status['slug'];
        // Map badge classes to colors
        $colorMap = [
            'approved' => '#28a745',
            'pending' => '#ff9800',
            'denied' => '#e53935',
            'cancelled' => '#6c757d',
            'postponed' => '#9c27b0',
            'pending_payment' => '#ff5722',
            'completed' => '#2196f3'
        ];
        $statusColors[] = $colorMap[$badgeClass] ?? '#999999';
    }
} else {
    // Fallback to hardcoded statuses
    $statusMap = ['approved' => 0, 'pending' => 0, 'denied' => 0, 'cancelled' => 0];
    $statusLabels = ['Approved','Pending','Denied','Cancelled'];
    $statusColors = ['#28a745', '#ff9800', '#e53935', '#6c757d'];
}

foreach ($statusData as $row) {
    $k = strtolower($row['status']);
    if (isset($statusMap[$k])) {
        $statusMap[$k] = (int)$row['count'];
    }
}
$statusCounts = array_values($statusMap);

// Top facilities bar chart (topfac chart filters)
if ($topfacPeriod['start'] && $topfacPeriod['end']) {
    $topfacParams = ['start' => $topfacPeriod['start'], 'end' => $topfacPeriod['end']];
    $facilitySql = 'SELECT f.name, COUNT(r.id) as booking_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id
             AND r.status = "approved"
             AND r.reservation_date >= :start
             AND r.reservation_date <= :end';
    if ($topfacPeriod['facility']) {
        $facilitySql .= ' AND r.facility_id = :facility_id';
        $topfacParams['facility_id'] = $topfacPeriod['facility'];
    }
    $facilitySql .= ' GROUP BY f.id, f.name ORDER BY booking_count DESC LIMIT 5';
    $facilityStmt = $pdo->prepare($facilitySql);
    $facilityStmt->execute($topfacParams);
} else {
    $topfacParams = [];
    $facilitySql = 'SELECT f.name, COUNT(r.id) as booking_count
         FROM facilities f
         LEFT JOIN reservations r ON f.id = r.facility_id AND r.status = "approved"';
    if ($topfacPeriod['facility']) {
        $facilitySql .= ' AND r.facility_id = :facility_id';
        $topfacParams['facility_id'] = $topfacPeriod['facility'];
    }
    $facilitySql .= ' GROUP BY f.id, f.name ORDER BY booking_count DESC LIMIT 5';
    $facilityStmt = $pdo->prepare($facilitySql);
    $facilityStmt->execute($topfacParams);
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
    $summaryUserId = (int)($_SESSION['user_id'] ?? 0);
    if (!checkGeminiReportSummaryRateLimit($summaryUserId)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'rate_limited',
            'message' => 'AI summary limit reached. Showing rule-based summary instead. Please wait before generating again.',
            'filter_label' => $filterLabel,
            'generated_at' => date('Y-m-d H:i:s'),
            'insights' => buildRuleBasedReportInsights($reportStatsForAI),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
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
    $liveOccFacility = (isset($_GET['occ_facility']) && $_GET['occ_facility'] !== '' && $_GET['occ_facility'] !== 'all')
        ? (int)$_GET['occ_facility'] : null;
    $liveOcc = $occupancyNow;
    if ($liveOccFacility && !empty($liveOcc['items'])) {
        $liveOcc['items'] = array_values(array_filter(
            $liveOcc['items'],
            static fn(array $item): bool => (int)($item['facility_id'] ?? 0) === $liveOccFacility
        ));
        $occupied = 0;
        foreach ($liveOcc['items'] as $item) {
            if (!empty($item['is_occupied'])) {
                $occupied++;
            }
        }
        $total = count($liveOcc['items']);
        $liveOcc['total_facilities'] = $total;
        $liveOcc['occupied_facilities'] = $occupied;
        $liveOcc['available_now'] = max(0, $total - $occupied);
        $liveOcc['occupancy_rate'] = $total > 0 ? round(($occupied / $total) * 100, 1) : 0;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'occupancy' => $liveOcc,
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
            <?= frs_page_title('Reports & Analytics', 'Each chart has its own filter. Use the global filter below to apply settings to all charts at once.'); ?>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <a href="<?= htmlspecialchars(frs_reports_export_href('csv'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-outline" style="padding: 0.5rem 1rem; white-space: nowrap; text-decoration: none;" title="Uses Overview KPIs month/year/facility filter">
            Export CSV
        </a>
        <button type="button" onclick="printSummary()" class="btn-primary" style="padding: 0.5rem 1rem; white-space: nowrap;">
            Print Summary
        </button>
        <button type="button" onclick="openAiSummaryModal()" class="btn-outline" style="padding: 0.5rem 1rem; white-space: nowrap;">
            Generate AI Summary
        </button>
        </div>
    </div>
</div>

<!-- Global Filter for All Charts -->
<div class="booking-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3 style="margin: 0; font-size: 1.1rem; color: var(--gov-blue-dark);">Global Filter (Apply to All Charts)</h3>
        <button type="button" onclick="applyGlobalFilter()" class="btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.9rem;">Apply to All</button>
    </div>
    <form id="global-filter-form" class="chart-filter-bar">
        <div class="chart-filter-fields">
            <label class="chart-filter-item">
                <span>Facility</span>
                <select id="global-facility" class="booking-form-control chart-filter-control">
                    <option value="all">All Facilities</option>
                    <?php foreach ($allFacilities as $fac): ?>
                        <option value="<?= (int)$fac['id']; ?>"<?= ($kpiPeriod['facility'] === (int)$fac['id']) ? ' selected' : ''; ?>><?= htmlspecialchars($fac['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="chart-filter-item">
                <span>Month</span>
                <select id="global-month" class="booking-form-control chart-filter-control">
                    <option value="all"<?= ($kpiPeriod['month'] === null) ? ' selected' : ''; ?>>All Time</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m; ?>"<?= ($kpiPeriod['month'] === $m) ? ' selected' : ''; ?>><?= date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label class="chart-filter-item">
                <span>Year</span>
                <select id="global-year" class="booking-form-control chart-filter-control">
                    <option value="all"<?= ($kpiPeriod['year'] === null) ? ' selected' : ''; ?>>All Years</option>
                    <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 2; $y--): ?>
                        <option value="<?= $y; ?>"<?= ($kpiPeriod['year'] === $y) ? ' selected' : ''; ?>><?= $y; ?></option>
                    <?php endfor; ?>
                </select>
            </label>
        </div>
    </form>
</div>

<div id="frsAiSummaryModal" class="frs-modal-overlay" role="dialog" aria-labelledby="aiSummaryTitle" aria-modal="true">
    <div class="frs-modal-panel">
        <div class="frs-modal-panel-header">
            <div>
                <h3 id="aiSummaryTitle">AI Summary</h3>
                <small id="aiSummaryMeta" class="frs-modal-meta">Uses Overview KPIs filter.</small>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <button class="btn-outline" type="button" onclick="printAiSummary()">🖨️ Print</button>
                <button class="btn-outline" type="button" onclick="closeAiSummaryModal()">✕ Close</button>
            </div>
        </div>
        <div id="aiSummaryContent" class="frs-modal-panel-body">
            <p>Click “Generate AI Summary” to load insights.</p>
        </div>
    </div>
</div>

<div class="reports-grid" style="margin-bottom: 1.5rem;">
    <section class="booking-card">
        <?= frs_heading_with_tip('Reservation Trends', 'Monthly count of reservations for the selected facility and period.'); ?>
        <?= frs_reports_period_filter_form('rpt-trend', 'trend', $allFacilities, $trendPeriod, []); ?>
        <canvas id="monthlyChart" style="max-height: 320px;"></canvas>
    </section>
    <section class="booking-card">
        <?= frs_heading_with_tip('Status Breakdown', 'Share of approved, pending, denied, and cancelled reservations in the selected period.'); ?>
        <?= frs_reports_period_filter_form('rpt-status', 'status', $allFacilities, $statusPeriod, []); ?>
        <canvas id="statusChart" style="max-height: 320px;"></canvas>
    </section>
</div>

<div class="booking-card" style="margin-bottom: 1.5rem;">
    <?= frs_heading_with_tip('Top Facilities by Approved Bookings', 'Facilities ranked by approved bookings in the selected period (top 5).'); ?>
    <?= frs_reports_period_filter_form('rpt-topfac', 'topfac', $allFacilities, $topfacPeriod, []); ?>
    <?php if (!empty($facilityLabels)): ?>
    <canvas id="facilityChart" style="max-height: 320px;"></canvas>
    <?php else: ?>
    <p style="color:#8b95b5; padding:1rem 0;">No data for the selected filters.</p>
    <?php endif; ?>
</div>

<div class="reports-grid" style="margin-bottom: 1.5rem;">
    <section class="booking-card">
        <?= frs_heading_with_tip('Predictive Analytics Forecast', 'Simple linear trend projection for the next 3 months based on historical monthly counts in the selected period.'); ?>
        <?= frs_reports_period_filter_form('rpt-forecast', 'forecast', $allFacilities, $forecastPeriod, []); ?>
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
        <?= frs_heading_with_tip(
            'Operational Occupancy',
            ($occupancyNow['disclaimer'] ?: 'Estimated from today’s bookings, check-in/out, and staff overrides.') . ' Open Live Occupancy Board for real-time updates.'
        ); ?>
        <?= frs_reports_occ_filter_form($allFacilities, $occFacilityFilter, []); ?>
        <p style="margin:0 0 0.85rem;"><a href="<?= base_path(); ?>/dashboard/occupancy-monitor">Open live occupancy board →</a></p>
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
        <?= frs_heading_with_tip('Monthly Reservation Volume (Overview KPIs)', 'Headline counts for the selected month/facility. Also drives Print Summary and AI Summary.'); ?>
        <?= frs_reports_period_filter_form('rpt-kpi', 'kpi', $allFacilities, $kpiPeriod, []); ?>
        <div class="kpi-row">
            <div class="kpi">
                <span>Total Reservations (<?= htmlspecialchars($filterLabel); ?>)</span>
                <strong><?= number_format($totalReservations); ?></strong>
                <small>Period: <?= htmlspecialchars($filterLabel); ?></small>
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
                    <div style="font-size: 0.8rem; color: #8b95b5; margin-top: 0.25rem;"><?= number_format($activeUsers); ?> active in period</div>
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
                    <div style="font-size: 0.8rem; color: #8b95b5; margin-top: 0.25rem;"><?= htmlspecialchars($filterLabel); ?></div>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e8ecf4;">
            <h3 style="margin: 0 0 1rem; font-size: 1.1rem; color: var(--gov-blue-dark);">Status Breakdown (<?= htmlspecialchars($filterLabel); ?>)</h3>
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
            <?= frs_heading_with_tip('Facility Utilization', 'Approved bookings per facility vs. the busiest facility in the selected period (horizontal bars).', 'h3'); ?>
            <?= frs_reports_period_filter_form('rpt-util', 'util', $allFacilities, $utilPeriod, []); ?>
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
        <?= frs_heading_with_tip('Reservation Outcomes', 'Count and percentage share of each final status in the selected period.'); ?>
        <?= frs_reports_period_filter_form('rpt-outcomes', 'outcomes', $allFacilities, $outcomesPeriod, []); ?>
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
    const modal = document.getElementById('frsAiSummaryModal');
    if (modal) {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }
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

function getKpiFilterQuery() {
    const form = document.getElementById('filter-rpt-kpi');
    if (!form) return '';
    const params = new URLSearchParams(new FormData(form));
    return params.toString();
}

function printSummary() {
    const q = getKpiFilterQuery();
    window.open('?' + (q ? q + '&' : '') + 'print=1', '_blank');
}

function openAiSummaryModal() {
    const modal = document.getElementById('frsAiSummaryModal');
    const contentEl = document.getElementById('aiSummaryContent');
    if (!modal || !contentEl) return;
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    contentEl.innerHTML = '<p style="margin:0; color:#6b7280;">Generating AI summary...</p>';

    const q = getKpiFilterQuery();
    const url = '?' + (q ? q + '&' : '') + 'ai_summary=1';

    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json().then(data => ({ status: r.status, data: data })))
        .then(({ status, data }) => {
            if (data && data.insights && (data.error === 'rate_limited' || status === 429)) {
                renderAiSummaryContent(data);
                const notice = document.createElement('p');
                notice.style.cssText = 'margin:0 0 0.85rem; padding:0.65rem 0.75rem; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; color:#92400e; font-size:0.9rem;';
                notice.textContent = data.message || 'AI limit reached. Showing rule-based summary.';
                contentEl.prepend(notice);
                return;
            }
            if (!data || !data.success) {
                throw new Error((data && data.message) ? data.message : 'Unable to generate AI summary.');
            }
            renderAiSummaryContent(data);
        })
        .catch(err => {
            contentEl.innerHTML = `<p style="margin:0; color:#b91c1c;">${err.message || 'Failed to generate AI summary.'}</p>`;
        });
}

function applyGlobalFilter() {
    const facility = document.getElementById('global-facility').value;
    const month = document.getElementById('global-month').value;
    const year = document.getElementById('global-year').value;

    // Build URL with the new filter values
    const url = new URL(window.location.href);
    url.searchParams.set('kpi_facility', facility);
    url.searchParams.set('kpi_month', month);
    url.searchParams.set('kpi_year', year);

    // Also set for all chart prefixes
    const prefixes = ['trend', 'status', 'topfac', 'forecast', 'util', 'outcomes'];
    prefixes.forEach(prefix => {
        url.searchParams.set(prefix + '_facility', facility);
        url.searchParams.set(prefix + '_month', month);
        url.searchParams.set(prefix + '_year', year);
    });

    // Redirect to apply filters
    window.location.href = url.toString();
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
    const modal = document.getElementById('frsAiSummaryModal');
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
    if (window.frsInitReservationCharts) {
        window.frsInitReservationCharts({
            monthlyLabels: <?= json_encode($monthlyLabels); ?>,
            monthlyData: <?= json_encode($monthlyData); ?>,
            statusLabels: <?= json_encode($statusLabels); ?>,
            statusCounts: <?= json_encode($statusCounts); ?>,
            statusColors: <?= json_encode($statusColors); ?>,
            facilityLabels: <?= json_encode($facilityLabels); ?>,
            facilityCounts: <?= json_encode($facilityCounts); ?>,
            showValueLabels: true
        });
    }

    // Realtime occupancy polling (every 30s).
    function refreshRealtimeOccupancy() {
        const occFac = document.querySelector('#filter-occ [name="occ_facility"]')?.value || 'all';
        const url = '?live_occupancy=1&occ_facility=' + encodeURIComponent(occFac);
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

<style>
.frs-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
}

.frs-modal-overlay.is-open {
    display: flex;
}

.frs-modal-panel {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    max-width: 700px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.frs-modal-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
    border-radius: 12px 12px 0 0;
}

.frs-modal-panel-header h3 {
    margin: 0 0 0.25rem 0;
    font-size: 1.25rem;
    color: #111827;
}

.frs-modal-meta {
    color: #6b7280;
    font-size: 0.85rem;
}

.frs-modal-panel-body {
    padding: 1.5rem;
    overflow-y: auto;
    color: #374151;
    line-height: 1.6;
}

html[data-theme="dark"] .frs-modal-panel {
    background: var(--bg-secondary, #1e293b);
    color: var(--text-primary, #f1f5f9);
}

html[data-theme="dark"] .frs-modal-panel-header {
    background: var(--bg-tertiary, #334155);
    border-color: var(--border-color, #475569);
}

html[data-theme="dark"] .frs-modal-panel-header h3 {
    color: var(--text-primary, #f1f5f9);
}

html[data-theme="dark"] .frs-modal-meta {
    color: var(--text-secondary, #cbd5e1);
}

html[data-theme="dark"] .frs-modal-panel-body {
    color: var(--text-primary, #f1f5f9);
}

html[data-theme="dark"] .frs-modal-panel-body p {
    color: var(--text-primary, #f1f5f9);
}

html[data-theme="dark"] .frs-modal-panel-body ul {
    color: var(--text-primary, #f1f5f9);
}

html[data-theme="dark"] .frs-modal-panel-body li {
    color: var(--text-primary, #f1f5f9);
}

@media (max-width: 768px) {
    .frs-modal-panel {
        max-height: 95vh;
        margin: 0.5rem;
    }
    .frs-modal-panel-header,
    .frs-modal-panel-body {
        padding: 1rem;
    }
    .chart-filter-fields {
        flex-direction: column;
    }
    .chart-filter-item {
        width: 100%;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';





