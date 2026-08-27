<?php
// This file is included by reports.php when print=1 parameter is present
// All variables from reports.php are available here

// Facility ranking, most-approved first (already sorted this way by the query).
$printFacilityTotal = 0;
foreach ($facilityData as $fac) {
    $printFacilityTotal += (int)$fac['approved_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Summary - <?= htmlspecialchars($filterLabel); ?> | LGU Facilities Reservation</title>
    <style>
        /* Print-economy layout: plain tables, no background fills or
           gradients - a solid-color box costs real ink/toner on every
           printed page; a border + bold text conveys the same thing
           for free and still renders correctly if "background graphics"
           is left off in the browser's print dialog (the common case). */
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
            .section {
                page-break-inside: avoid;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 10.5pt;
            color: #111;
            line-height: 1.45;
            margin: 0;
            padding: 20px;
        }

        .header {
            border-bottom: 2px solid #111;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0 0 4px;
            font-size: 20pt;
        }

        .header .meta {
            margin: 0;
            color: #444;
            font-size: 9.5pt;
        }

        .section {
            margin-bottom: 22px;
        }

        .section h2 {
            margin: 0 0 8px;
            font-size: 13pt;
            border-bottom: 1px solid #999;
            padding-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 4px;
        }

        th {
            text-align: left;
            font-weight: 700;
            font-size: 9.5pt;
            padding: 5px 8px;
            border-bottom: 1.5px solid #111;
        }

        td {
            padding: 5px 8px;
            border-bottom: 1px solid #ccc;
            font-size: 10pt;
        }

        td.num, th.num {
            text-align: right;
        }

        .muted {
            color: #666;
            font-size: 9pt;
        }

        .footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #999;
            color: #555;
            font-size: 8.5pt;
        }

        .print-button {
            position: fixed;
            top: 16px;
            right: 16px;
            padding: 8px 16px;
            background: #111;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 10.5pt;
            font-weight: 600;
        }

        @media print {
            .print-button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">🖨️ Print to PDF</button>

    <div class="header">
        <h1>Reports &amp; Analytics Summary</h1>
        <p class="meta">
            <strong>Period:</strong> <?= htmlspecialchars($filterLabel); ?>
            <?php if ($facilityFilter): ?>
                &nbsp;·&nbsp;<strong>Facility:</strong> <?= htmlspecialchars($facilityName); ?>
            <?php endif; ?>
            &nbsp;·&nbsp;<strong>Generated:</strong> <?= date('F j, Y g:i A'); ?>
        </p>
    </div>

    <div class="section">
        <h2>Key Performance Indicators</h2>
        <table>
            <thead>
                <tr><th>Metric</th><th class="num">Value</th><th>Note</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Total Reservations</td>
                    <td class="num"><?= number_format($totalReservations); ?></td>
                    <td class="muted">For the selected period</td>
                </tr>
                <tr>
                    <td>Approval Rate</td>
                    <td class="num"><?= $approvalRate; ?>%</td>
                    <td class="muted"><?= number_format($approvedCount); ?> of <?= number_format($totalReservations); ?> approved</td>
                </tr>
                <tr>
                    <td>Utilization</td>
                    <td class="num"><?= $utilization; ?>%</td>
                    <td class="muted">Occupied vs. available time slots</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>System Statistics</h2>
        <table>
            <thead>
                <tr><th>Metric</th><th class="num">Value</th><th>Note</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Total Users</td>
                    <td class="num"><?= number_format($totalUsers); ?></td>
                    <td class="muted"><?= number_format($activeUsers); ?> active this period</td>
                </tr>
                <tr>
                    <td>Available Facilities</td>
                    <td class="num"><?= number_format($totalFacilities); ?></td>
                    <td class="muted">Facilities in the system</td>
                </tr>
                <tr>
                    <td>Total Reservations (All-Time)</td>
                    <td class="num"><?= number_format($totalAllTime); ?></td>
                    <td class="muted">Since launch</td>
                </tr>
                <tr>
                    <td>Avg. Reservations per User</td>
                    <td class="num"><?= $avgReservationsPerUser; ?></td>
                    <td class="muted">For the selected period</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Reservation Status</h2>
        <table>
            <thead>
                <tr><th>Status</th><th class="num">Count</th><th class="num">Share</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Approved</strong></td>
                    <td class="num"><?= number_format($outcomesMap['approved']); ?></td>
                    <td class="num"><?= $outcomesShare['approved']; ?>%</td>
                </tr>
                <tr>
                    <td><strong>Denied</strong></td>
                    <td class="num"><?= number_format($outcomesMap['denied']); ?></td>
                    <td class="num"><?= $outcomesShare['denied']; ?>%</td>
                </tr>
                <tr>
                    <td><strong>Cancelled</strong></td>
                    <td class="num"><?= number_format($outcomesMap['cancelled']); ?></td>
                    <td class="num"><?= $outcomesShare['cancelled']; ?>%</td>
                </tr>
                <?php if ($outcomesMap['pending'] > 0): ?>
                <tr>
                    <td><strong>Pending</strong></td>
                    <td class="num"><?= number_format($outcomesMap['pending']); ?></td>
                    <td class="num"><?= $outcomesShare['pending']; ?>%</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Facility Rankings</h2>
        <p class="muted" style="margin: 0 0 6px;">Ranked by approved bookings in the selected period.</p>
        <?php if (empty($facilityData)): ?>
            <p class="muted">No facility data available for this period.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr><th>#</th><th>Facility</th><th class="num">Approved Bookings</th><th class="num">Share</th></tr>
            </thead>
            <tbody>
                <?php foreach ($facilityData as $idx => $facility):
                    $bookings = (int)$facility['approved_count'];
                    $share = $printFacilityTotal > 0 ? round(($bookings / $printFacilityTotal) * 100) : 0;
                ?>
                <tr>
                    <td><?= $idx + 1; ?></td>
                    <td><?= htmlspecialchars($facility['name']); ?></td>
                    <td class="num"><?= number_format($bookings); ?></td>
                    <td class="num"><?= $share; ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Reservation Trend</h2>
        <table>
            <thead>
                <tr><th>Period</th><th class="num">Reservations</th></tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyLabels as $index => $label): ?>
                    <tr>
                        <td><?= htmlspecialchars($label); ?></td>
                        <td class="num"><?= number_format($monthlyData[$index]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        <p><strong>LGU Facilities Reservation System</strong> — generated <?= date('F j, Y \a\t g:i A'); ?></p>
        <p>To save as PDF: use your browser's print function (Ctrl+P / Cmd+P) and choose "Save as PDF".</p>
    </div>
</body>
</html>
