<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

// Role-based access: Admin/Staff only
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['Admin', 'Staff'])) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
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
    $facilityStmt->execute($dateParams);
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

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Analytics</span><span class="sep">/</span><span>Reports</span>
    </div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>Reports & Analytics</h1>
            <small>Review reservation statistics, usage patterns, and AI-generated scheduling insights.</small>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <form method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
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
        </script>
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

<div class="reports-grid">
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

    <aside>
        <div class="ai-panel">
            <div class="ai-chip">
                <span>AI</span> <span>Predictive Insight</span>
            </div>
            <h3>Usage Patterns (<?= htmlspecialchars($filterLabel); ?>)</h3>
            <?php
            // Get peak day of week
            $dayOfWeekSql = 'SELECT DAYNAME(reservation_date) as day_name, COUNT(*) as count
                 FROM reservations
                 WHERE status = "approved"';
            if ($dateFilterClause) {
                $dayOfWeekSql .= ' AND reservation_date >= :start AND reservation_date <= :end';
            }
            $dayOfWeekSql .= ' GROUP BY DAYNAME(reservation_date)
                 ORDER BY count DESC
                 LIMIT 1';
            $dayOfWeekStmt = $pdo->prepare($dayOfWeekSql);
            $dayOfWeekStmt->execute($dateParams);
            $peakDay = $dayOfWeekStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get most popular time slot
            $timeSlotSql = 'SELECT time_slot, COUNT(*) as count
                 FROM reservations
                 WHERE status = "approved"';
            if ($dateFilterClause) {
                $timeSlotSql .= ' AND reservation_date >= :start AND reservation_date <= :end';
            }
            $timeSlotSql .= ' GROUP BY time_slot
                 ORDER BY count DESC
                 LIMIT 1';
            $timeSlotStmt = $pdo->prepare($timeSlotSql);
            $timeSlotStmt->execute($dateParams);
            $peakTimeSlot = $timeSlotStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get most booked facility
            $topFacility = !empty($facilityData) ? $facilityData[0] : null;
            ?>
            <ul>
                <?php if ($peakDay): ?>
                    <li><strong>Peak Day:</strong> <?= htmlspecialchars($peakDay['day_name']); ?>s with <?= $peakDay['count']; ?> approved booking(s).</li>
                <?php endif; ?>
                <?php if ($peakTimeSlot): ?>
                    <li><strong>Popular Time:</strong> "<?= htmlspecialchars($peakTimeSlot['time_slot']); ?>" slot has <?= $peakTimeSlot['count']; ?> approved reservation(s).</li>
                <?php endif; ?>
                <?php if ($topFacility && (int)$topFacility['approved_count'] > 0): ?>
                    <li><strong>Most Active Facility:</strong> <?= htmlspecialchars($topFacility['name']); ?> with <?= $topFacility['approved_count']; ?> approved booking(s).</li>
                <?php endif; ?>
                <?php if ($totalReservations == 0): ?>
                    <li><em>No data available for this period. Insights will appear as reservations are made.</em></li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="report-card" style="margin-top: 1.5rem;">
            <h2>Quick Export</h2>
            <p class="resource-meta">Export reservation data for the selected period.</p>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" class="btn btn-primary" style="display: block; text-align: center; text-decoration: none; margin-bottom: 0.75rem;">Download Monthly Report (PDF)</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline" style="display: block; text-align: center; text-decoration: none;">Download Raw Data (CSV)</a>
        </div>
    </aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const monthlyCtx = document.getElementById('monthlyChart');
    if (monthlyCtx && window.Chart) {
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
                plugins: { legend: { display: false } },
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
                    }
                },
                cutout: '60%'
            }
        });
    }

    const facilityCtx = document.getElementById('facilityChart');
    if (facilityCtx && window.Chart) {
        new Chart(facilityCtx, {
            type: 'bar',
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
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';





