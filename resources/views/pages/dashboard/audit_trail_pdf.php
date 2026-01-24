<?php
/**
 * Audit Trail PDF Export
 * Generates a professional print-ready report of audit logs with filters and pagination
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

// RBAC: Audit Trail is Admin-only
if (!($_SESSION['user_authenticated'] ?? false) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
$pdo = db();

// Get filter parameters
$filterModule = $_GET['module'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10; // Match the web view pagination (10 per page)

// Build query with filters
$whereConditions = [];
$params = [];

if ($filterModule && $filterModule !== 'all') {
    $whereConditions[] = 'a.module = :module';
    $params['module'] = $filterModule;
}

if ($filterUser && $filterUser !== 'all') {
    $whereConditions[] = 'a.user_id = :user_id';
    $params['user_id'] = (int)$filterUser;
}

if ($filterDateFrom) {
    $whereConditions[] = 'DATE(a.created_at) >= :date_from';
    $params['date_from'] = $filterDateFrom;
}

if ($filterDateTo) {
    $whereConditions[] = 'DATE(a.created_at) <= :date_to';
    $params['date_to'] = $filterDateTo;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Count total entries
$countSql = 'SELECT COUNT(*) FROM audit_log a ' . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

// Calculate pagination
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch audit entries
$sql = 'SELECT a.id, a.action, a.module, a.details, a.created_at, 
               u.name AS user_name, u.email AS user_email
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        ' . $whereClause . '
        ORDER BY a.created_at DESC
        LIMIT :limit OFFSET :offset';
        
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter labels for display
$moduleLabel = $filterModule && $filterModule !== 'all' ? $filterModule : 'All Modules';
$userLabel = 'All Users';
if ($filterUser && $filterUser !== 'all') {
    $userStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id');
    $userStmt->execute(['id' => $filterUser]);
    $userName = $userStmt->fetchColumn();
    $userLabel = $userName ?: 'Unknown User';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Audit Trail Report - <?= date('Y-m-d'); ?></title>
    <style>
        @media print {
            @page {
                margin: 1.5cm 1cm;
                size: A4;
            }
            
            .no-print {
                display: none !important;
            }
            
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
        
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .print-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .print-container {
                max-width: 100%;
                box-shadow: none;
                padding: 0;
            }
        }
        
        .header {
            background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%);
            color: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        .header h1 {
            margin: 0 0 5px 0;
            font-size: 20pt;
            font-weight: 600;
        }
        
        .header p {
            margin: 0;
            font-size: 10pt;
            opacity: 0.9;
        }
        
        .meta-info {
            background: #f8f9fa;
            border-left: 4px solid #6384d2;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .meta-info table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .meta-info td {
            padding: 4px 8px;
            font-size: 9pt;
        }
        
        .meta-info td:first-child {
            font-weight: 600;
            color: #555;
            width: 120px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9pt;
        }
        
        .data-table thead {
            background: #285ccd;
            color: white;
        }
        
        .data-table th {
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #1e4ba8;
        }
        
        .data-table td {
            padding: 8px;
            border-bottom: 1px solid #e0e6ed;
            vertical-align: top;
        }
        
        .data-table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .module-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 8pt;
            font-weight: 600;
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #e0e6ed;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
        
        .summary-box {
            background: #fff4e5;
            border-left: 4px solid #ffc107;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .summary-box p {
            margin: 5px 0;
            font-size: 9pt;
        }
        
        .summary-box strong {
            color: #856404;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-style: italic;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #285ccd;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(40, 92, 205, 0.3);
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #1e4ba8;
        }
        
        @media print {
            .print-button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">🖨️ Print / Save as PDF</button>
    
    <div class="print-container">
        <div class="header">
            <h1>🏛️ Audit Trail Report</h1>
            <p>Barangay Culiat Facilities Reservation System</p>
        </div>
        
        <div class="meta-info">
            <table>
                <tr>
                    <td>Generated:</td>
                    <td><?= date('F j, Y g:i A'); ?></td>
                    <td>Generated By:</td>
                    <td><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></td>
                </tr>
                <tr>
                    <td>Module Filter:</td>
                    <td><?= htmlspecialchars($moduleLabel); ?></td>
                    <td>User Filter:</td>
                    <td><?= htmlspecialchars($userLabel); ?></td>
                </tr>
                <tr>
                    <td>Date Range:</td>
                    <td colspan="3"><?php
                    if ($filterDateFrom || $filterDateTo) {
                        echo htmlspecialchars($filterDateFrom ?: 'Any') . ' to ' . htmlspecialchars($filterDateTo ?: 'Any');
                    } else {
                        echo 'All Dates';
                    }
                    ?></td>
                </tr>
            </table>
        </div>
        
        <div class="summary-box">
            <p><strong>Total Entries:</strong> <?= number_format($totalRows); ?> record(s) found</p>
            <p><strong>Showing:</strong> Page <?= $page; ?> of <?= $totalPages; ?> (<?= count($entries); ?> entries on this page)</p>
        </div>
        
        <?php if (empty($entries)): ?>
            <div class="no-data">No audit entries found matching the selected filters.</div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">Date & Time</th>
                        <th style="width: 15%;">User</th>
                        <th style="width: 20%;">Action</th>
                        <th style="width: 12%;">Module</th>
                        <th style="width: 38%;">Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td><?= date('M d, Y<br>H:i:s', strtotime($row['created_at'])); ?></td>
                        <td><?= $row['user_name'] ? htmlspecialchars($row['user_name']) : '<em>System</em>'; ?></td>
                        <td><?= htmlspecialchars($row['action']); ?></td>
                        <td><span class="module-badge"><?= htmlspecialchars($row['module']); ?></span></td>
                        <td><?= $row['details'] ? htmlspecialchars($row['details']) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div class="footer">
            <p><strong>Barangay Culiat Facilities Reservation System</strong></p>
            <p>This is a system-generated report. All activities are logged for security and accountability purposes.</p>
            <p>© <?= date('Y'); ?> Barangay Culiat, Quezon City. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
