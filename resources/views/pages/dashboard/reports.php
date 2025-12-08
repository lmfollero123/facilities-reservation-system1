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
    header('Location: ' . base_path() . '/resources/views/pages/dashboard/index.php');
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
        $exportStmt = $pdo->prepare(
            'SELECT r.reservation_date, f.name AS facility_name, u.name AS requester_name, 
                    r.time_slot, r.status, r.purpose
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             JOIN users u ON r.user_id = u.id
             WHERE r.reservation_date >= :start AND r.reservation_date <= :end
             ORDER BY r.reservation_date DESC'
        );
        $exportStmt->execute(['start' => $startDate, 'end' => $endDate]);
        
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
        
        $exportStmt = $pdo->prepare(
            'SELECT r.reservation_date, f.name AS facility_name, u.name AS requester_name, 
                    r.time_slot, r.status, r.purpose, u.email AS requester_email
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             JOIN users u ON r.user_id = u.id
             WHERE r.reservation_date >= :start AND r.reservation_date <= :end
             ORDER BY r.reservation_date DESC'
        );
        $exportStmt->execute(['start' => $startDate, 'end' => $endDate]);
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

// Get date parameters (default to current month)
$reportYear = (int)($_GET['year'] ?? date('Y'));
$reportMonth = (int)($_GET['month'] ?? date('m'));
$startDate = date('Y-m-01', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
$endDate = date('Y-m-t', mktime(0, 0, 0, $reportMonth, 1, $reportYear));

// Calculate KPIs
// Total reservations this month
$totalStmt = $pdo->prepare(
    'SELECT COUNT(*) as total 
     FROM reservations 
     WHERE reservation_date >= :start AND reservation_date <= :end'
);
$totalStmt->execute(['start' => $startDate, 'end' => $endDate]);
$totalReservations = (int)$totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Approved count
$approvedStmt = $pdo->prepare(
    'SELECT COUNT(*) as count 
     FROM reservations 
     WHERE reservation_date >= :start AND reservation_date <= :end 
     AND status = "approved"'
);
$approvedStmt->execute(['start' => $startDate, 'end' => $endDate]);
$approvedCount = (int)$approvedStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Approval rate
$approvalRate = $totalReservations > 0 ? round(($approvedCount / $totalReservations) * 100) : 0;

// Utilization (simplified: approved reservations / total days in month * average slots per day)
// Assuming 4 time slots per day as average (Morning, Afternoon, Evening, Full Day)
$daysInMonth = (int)date('t', mktime(0, 0, 0, $reportMonth, 1, $reportYear));
$totalPossibleSlots = $daysInMonth * 4; // Rough estimate
$utilization = $totalPossibleSlots > 0 ? round(($approvedCount / $totalPossibleSlots) * 100) : 0;
$utilization = min($utilization, 100); // Cap at 100%

// Facility utilization
$facilityUtilStmt = $pdo->prepare(
    'SELECT f.name, COUNT(r.id) as booking_count,
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
     ORDER BY approved_count DESC'
);
$facilityUtilStmt->execute([
    'start' => $startDate,
    'end' => $endDate,
    'start2' => $startDate,
    'end2' => $endDate,
]);
$facilityData = $facilityUtilStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate max bookings for percentage (find highest count)
$maxBookings = 0;
foreach ($facilityData as $fac) {
    $maxBookings = max($maxBookings, (int)$fac['approved_count']);
}

// Reservation outcomes breakdown
$outcomesStmt = $pdo->prepare(
    'SELECT status, COUNT(*) as count 
     FROM reservations 
     WHERE reservation_date >= :start AND reservation_date <= :end 
     GROUP BY status'
);
$outcomesStmt->execute(['start' => $startDate, 'end' => $endDate]);
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
            <select name="month" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m; ?>" <?= $m == $reportMonth ? 'selected' : ''; ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 1)); ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?= $y; ?>" <?= $y == $reportYear ? 'selected' : ''; ?>>
                        <?= $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn-primary" style="padding: 0.5rem 1rem;">Update</button>
        </form>
        </div>
    </div>
</div>

<div class="reports-grid">
    <section>
        <div class="report-card">
            <h2>Monthly Reservation Volume</h2>
            <div class="kpi-row">
                <div class="kpi">
                    <span>Total Reservations</span>
                    <strong><?= number_format($totalReservations); ?></strong>
                    <small>Across all facilities in <?= date('F Y', mktime(0, 0, 0, $reportMonth, 1, $reportYear)); ?></small>
                </div>
                <div class="kpi">
                    <span>Approval Rate</span>
                    <strong><?= $approvalRate; ?>%</strong>
                    <small>Approved vs. total requests</small>
                </div>
                <div class="kpi">
                    <span>Utilization</span>
                    <strong><?= $utilization; ?>%</strong>
                    <small>Occupied time slots vs. available</small>
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
            <h3>Usage Patterns (<?= date('F Y', mktime(0, 0, 0, $reportMonth, 1, $reportYear)); ?>)</h3>
            <?php
            // Get peak day of week
            $dayOfWeekStmt = $pdo->prepare(
                'SELECT DAYNAME(reservation_date) as day_name, COUNT(*) as count
                 FROM reservations
                 WHERE reservation_date >= :start AND reservation_date <= :end
                 AND status = "approved"
                 GROUP BY DAYNAME(reservation_date)
                 ORDER BY count DESC
                 LIMIT 1'
            );
            $dayOfWeekStmt->execute(['start' => $startDate, 'end' => $endDate]);
            $peakDay = $dayOfWeekStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get most popular time slot
            $timeSlotStmt = $pdo->prepare(
                'SELECT time_slot, COUNT(*) as count
                 FROM reservations
                 WHERE reservation_date >= :start AND reservation_date <= :end
                 AND status = "approved"
                 GROUP BY time_slot
                 ORDER BY count DESC
                 LIMIT 1'
            );
            $timeSlotStmt->execute(['start' => $startDate, 'end' => $endDate]);
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
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';





