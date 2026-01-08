<?php
/**
 * Audit Trail CSV Export Handler
 * Exports audit logs to CSV format with proper formatting
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';

// Check authentication and authorization (Admin/Staff only)
if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    http_response_code(403);
    die('Unauthorized: Only Admin and Staff can export audit trails.');
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';

$pdo = db();

// Get filter parameters (same as audit_trail.php)
$filterModule = $_GET['module'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// Build query with filters (same logic as audit_trail.php)
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

// Fetch all audit entries (no pagination for export)
$sql = 'SELECT a.id, a.action, a.module, a.details, a.created_at, a.ip_address, a.user_agent,
               u.name AS user_name, u.email AS user_email, u.role AS user_role
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        ' . $whereClause . '
        ORDER BY a.created_at DESC';
        
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->execute();
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log the export action
logAudit(
    'Exported audit trail to CSV',
    'Audit Trail',
    'Filters: ' . json_encode([
        'module' => $filterModule,
        'user' => $filterUser,
        'date_from' => $filterDateFrom,
        'date_to' => $filterDateTo
    ]) . ' | Total records: ' . count($entries)
);

// Generate filename with filters and timestamp
$filename = 'audit_trail_';
if ($filterDateFrom || $filterDateTo) {
    $dateRange = ($filterDateFrom ?: 'all') . '_to_' . ($filterDateTo ?: 'all');
    $filename .= $dateRange . '_';
}
$filename .= date('Y-m-d_His') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Expires: 0');

// Output UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

// Open output stream
$output = fopen('php://output', 'w');

// CSV Headers
$headers = [
    'ID',
    'Date & Time',
    'User Name',
    'User Email',
    'User Role',
    'Action',
    'Module',
    'Details',
    'IP Address',
    'User Agent'
];

fputcsv($output, $headers);

// Write data rows
foreach ($entries as $row) {
    $csvRow = [
        $row['id'],
        date('Y-m-d H:i:s', strtotime($row['created_at'])),
        $row['user_name'] ?? 'System',
        $row['user_email'] ?? '',
        $row['user_role'] ?? '',
        $row['action'],
        $row['module'],
        $row['details'] ?? '',
        $row['ip_address'] ?? '',
        $row['user_agent'] ?? ''
    ];
    
    fputcsv($output, $csvRow);
}

// Add summary row if filters are applied
if (!empty($whereConditions)) {
    fputcsv($output, []); // Empty row
    fputcsv($output, ['Summary']);
    fputcsv($output, ['Total Records', count($entries)]);
    fputcsv($output, ['Exported By', $_SESSION['name'] ?? 'Unknown']);
    fputcsv($output, ['Export Date', date('Y-m-d H:i:s')]);
    
    if ($filterModule && $filterModule !== 'all') {
        fputcsv($output, ['Filter: Module', $filterModule]);
    }
    if ($filterUser && $filterUser !== 'all') {
        fputcsv($output, ['Filter: User ID', $filterUser]);
    }
    if ($filterDateFrom) {
        fputcsv($output, ['Filter: Date From', $filterDateFrom]);
    }
    if ($filterDateTo) {
        fputcsv($output, ['Filter: Date To', $filterDateTo]);
    }
}

fclose($output);
exit;





