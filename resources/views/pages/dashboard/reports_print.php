<?php
// This file is included by reports.php when print=1 parameter is present
// All variables from reports.php are available here

// Recalculate data for print view (in case it's needed)
// The parent file already has all the data we need
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Summary - <?= htmlspecialchars($filterLabel); ?> | LGU Facilities Reservation</title>
    <style>
        /* Print-optimized styles */
        @media print {
            @page {
                size: A4;
                margin: 1.5cm;
            }
            body {
                margin: 0;
            }
            .no-print {
                display: none !important;
            }
            .page-break {
                page-break-after: always;
            }
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', 'Arial', sans-serif;
            font-size: 11pt;
            color: #333;
            line-height: 1.5;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            border-bottom: 3px solid #0047ab;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        
        .header h1 {
            margin: 0 0 5px 0;
            color: #0047ab;
            font-size: 24pt;
        }
        
        .header .meta {
            margin: 5px 0 0 0;
            color: #666;
            font-size: 10pt;
        }
        
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .section h2 {
            margin: 0 0 15px 0;
            font-size: 16pt;
            color: #0047ab;
            border-bottom: 2px solid #e0e6ed;
            padding-bottom: 8px;
        }
        
        .section h3 {
            margin: 15px 0 10px 0;
            font-size: 13pt;
            color: #333;
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 15px 0;
        }
        
        .kpi-box {
            border: 1px solid #ddd;
            padding: 15px;
            text-align: center;
            border-radius: 6px;
            background: #f9fafc;
        }
        
        .kpi-box .label {
            font-size: 9pt;
            color: #666;
            margin-bottom: 5px;
        }
        
        .kpi-box .value {
            font-size: 20pt;
            font-weight: 700;
            color: #0047ab;
            margin: 5px 0;
        }
        
        .kpi-box .subtext {
            font-size: 8pt;
            color: #999;
            margin-top: 5px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin: 15px 0;
        }
        
        .stat-box {
            padding: 12px;
            background: #f9fafc;
            border-radius: 6px;
            border: 1px solid #e0e6ed;
        }
        
        .stat-box .label {
            font-size: 8pt;
            color: #666;
            margin-bottom: 3px;
        }
        
        .stat-box .value {
            font-size: 16pt;
            font-weight: 700;
            color: #0047ab;
        }
        
        .stat-box .subtext {
            font-size: 7pt;
            color: #999;
            margin-top: 3px;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        
        .status-box {
            padding: 12px;
            border-radius: 6px;
            text-align: center;
        }
        
        .status-box.approved {
            background: #e8f5e9;
            border: 1px solid #4caf50;
        }
        
        .status-box.pending {
            background: #fff3e0;
            border: 1px solid #ff9800;
        }
        
        .status-box.denied {
            background: #ffebee;
            border: 1px solid #f44336;
        }
        
        .status-box.cancelled {
            background: #f5f5f5;
            border: 1px solid #9e9e9e;
        }
        
        .status-box .value {
            font-size: 18pt;
            font-weight: 700;
        }
        
        .status-box.approved .value {
            color: #2e7d32;
        }
        
        .status-box.pending .value {
            color: #f57c00;
        }
        
        .status-box.denied .value {
            color: #c62828;
        }
        
        .status-box.cancelled .value {
            color: #616161;
        }
        
        .status-box .label {
            font-size: 9pt;
            color: #666;
            margin-top: 3px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        th {
            background: #0047ab;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            font-size: 10pt;
        }
        
        td {
            padding: 8px 10px;
            border-bottom: 1px solid #ddd;
            font-size: 10pt;
        }
        
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 9pt;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-badge.status-approved {
            background: #4caf50;
            color: white;
        }
        
        .status-badge.status-denied {
            background: #f44336;
            color: white;
        }
        
        .status-badge.status-pending {
            background: #ff9800;
            color: white;
        }
        
        .status-badge.status-cancelled {
            background: #9e9e9e;
            color: white;
        }
        
        .facility-bar {
            margin: 8px 0;
        }
        
        .facility-bar .name {
            font-size: 10pt;
            margin-bottom: 4px;
            color: #333;
        }
        
        .facility-bar .count {
            font-size: 8pt;
            color: #999;
        }
        
        .bar-track {
            width: 100%;
            height: 20px;
            background: #e0e6ed;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #0047ab, #2563eb);
            transition: none;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #666;
            font-size: 9pt;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #0047ab;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11pt;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .print-button:hover {
            background: #003580;
        }
        
        @media print {
            .print-button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Print Button (hidden when printing) -->
    <button class="print-button no-print" onclick="window.print()">🖨️ Print to PDF</button>
    
    <!-- Header -->
    <div class="header">
        <h1>Reports & Analytics Summary</h1>
        <div class="meta">
            <strong>Period:</strong> <?= htmlspecialchars($filterLabel); ?>
            <?php if ($facilityFilter): ?>
                <br><strong>Facility:</strong> <?= htmlspecialchars($facilityName); ?>
            <?php endif; ?>
            <br><strong>Generated:</strong> <?= date('F j, Y g:i A'); ?>
        </div>
    </div>
    
    <!-- KPI Summary -->
    <div class="section">
        <h2>Key Performance Indicators</h2>
        <div class="kpi-grid">
            <div class="kpi-box">
                <div class="label">Total Reservations</div>
                <div class="value"><?= number_format($totalReservations); ?></div>
                <div class="subtext">For selected period</div>
            </div>
            <div class="kpi-box">
                <div class="label">Approval Rate</div>
                <div class="value"><?= $approvalRate; ?>%</div>
                <div class="subtext"><?= number_format($approvedCount); ?> of <?= number_format($totalReservations); ?> approved</div>
            </div>
            <div class="kpi-box">
                <div class="label">Utilization</div>
                <div class="value"><?= $utilization; ?>%</div>
                <div class="subtext">Occupied vs. available slots</div>
            </div>
        </div>
    </div>
    
    <!-- Global System Statistics -->
    <div class="section">
        <h2>Global System Statistics</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="label">Total Users</div>
                <div class="value"><?= number_format($totalUsers); ?></div>
                <div class="subtext"><?= number_format($activeUsers); ?> active this period</div>
            </div>
            <div class="stat-box">
                <div class="label">Available Facilities</div>
                <div class="value"><?= number_format($totalFacilities); ?></div>
                <div class="subtext">Facilities in system</div>
            </div>
            <div class="stat-box">
                <div class="label">Total All-Time</div>
                <div class="value"><?= number_format($totalAllTime); ?></div>
                <div class="subtext">All reservations ever</div>
            </div>
            <div class="stat-box">
                <div class="label">Avg per User</div>
                <div class="value"><?= $avgReservationsPerUser; ?></div>
                <div class="subtext">This period</div>
            </div>
        </div>
    </div>
    
    <!-- Status Breakdown -->
    <div class="section">
        <h2>Status Breakdown</h2>
        <div class="status-grid">
            <div class="status-box approved">
                <div class="value"><?= number_format($approvedCount); ?></div>
                <div class="label">Approved</div>
            </div>
            <div class="status-box pending">
                <div class="value"><?= number_format($pendingCount); ?></div>
                <div class="label">Pending</div>
            </div>
            <div class="status-box denied">
                <div class="value"><?= number_format($deniedCount); ?></div>
                <div class="label">Denied</div>
            </div>
            <div class="status-box cancelled">
                <div class="value"><?= number_format($cancelledCount); ?></div>
                <div class="label">Cancelled</div>
            </div>
        </div>
    </div>
    
    <!-- Facility Utilization -->
    <div class="section">
        <h2>Facility Utilization</h2>
        <?php if (empty($facilityData)): ?>
            <p style="color: #999;">No facility data available for this period.</p>
        <?php else: ?>
            <?php foreach ($facilityData as $facility): ?>
                <?php
                $bookings = (int)$facility['approved_count'];
                $percentage = $maxBookings > 0 ? round(($bookings / $maxBookings) * 100) : 0;
                ?>
                <div class="facility-bar">
                    <div class="name">
                        <?= htmlspecialchars($facility['name']); ?>
                        <span class="count">(<?= $bookings; ?> approved bookings)</span>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= $percentage; ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Page Break -->
    <div class="page-break"></div>
    
    <!-- Reservation Outcomes -->
    <div class="section">
        <h2>Reservation Outcomes</h2>
        <table>
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
    
    <!-- Reservation Trends Data -->
    <div class="section">
        <h2>Reservation Trends</h2>
        <p style="margin-bottom: 15px; color: #666;">Monthly reservation counts for the selected period:</p>
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Reservations</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyLabels as $index => $label): ?>
                    <tr>
                        <td><?= htmlspecialchars($label); ?></td>
                        <td><strong><?= number_format($monthlyData[$index]); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Top Facilities -->
    <?php if (!empty($facilityLabels)): ?>
    <div class="section">
        <h2>Top Facilities by Approved Bookings</h2>
        <table>
            <thead>
                <tr>
                    <th>Facility</th>
                    <th>Approved Bookings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facilityLabels as $index => $label): ?>
                    <tr>
                        <td><?= htmlspecialchars($label); ?></td>
                        <td><strong><?= number_format($facilityCounts[$index]); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <div class="footer">
        <p><strong>LGU Facilities Reservation System</strong></p>
        <p>This report was generated on <?= date('F j, Y \a\t g:i A'); ?></p>
        <p>To save as PDF, use your browser's print function (Ctrl+P / Cmd+P) and select "Save as PDF"</p>
    </div>
    
    <script>
        // Auto-trigger print dialog on load
        window.onload = function() {
            // Small delay to ensure page is fully rendered
            setTimeout(function() {
                // Uncomment the line below to auto-trigger print dialog
                // window.print();
            }, 500);
        };
        
        // Close window after printing (optional)
        window.onafterprint = function() {
            // Uncomment to auto-close after printing
            // window.close();
        };
    </script>
</body>
</html>
