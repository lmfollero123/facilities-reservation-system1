<?php
/**
 * Check Database Indexes
 * Verifies that performance indexes are created on reservations table
 */

require_once __DIR__ . '/../config/database.php';

$pdo = db();

echo "=== Database Index Check ===\n\n";

// Check reservations table indexes
$stmt = $pdo->query("
    SHOW INDEX FROM reservations
");
$indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Reservations Table Indexes:\n";
echo str_repeat("-", 80) . "\n";
printf("%-40s %-20s %-10s\n", "Index Name", "Column", "Cardinality");
echo str_repeat("-", 80) . "\n";

$indexGroups = [];
foreach ($indexes as $index) {
    $key = $index['Key_name'];
    if (!isset($indexGroups[$key])) {
        $indexGroups[$key] = [];
    }
    $indexGroups[$key][] = $index['Column_name'];
}

foreach ($indexGroups as $indexName => $columns) {
    $columnStr = implode(', ', $columns);
    $cardinality = $indexes[array_search($indexName, array_column($indexes, 'Key_name'))]['Cardinality'] ?? 'N/A';
    printf("%-40s %-20s %-10s\n", $indexName, $columnStr, $cardinality);
}

// Check table statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_rows,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT facility_id) as unique_facilities
    FROM reservations
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

echo "\nTable Statistics:\n";
echo str_repeat("-", 80) . "\n";
echo "Total Reservations: " . $stats['total_rows'] . "\n";
echo "Unique Users: " . $stats['unique_users'] . "\n";
echo "Unique Facilities: " . $stats['unique_facilities'] . "\n";

// Check for missing critical indexes
echo "\n=== Index Recommendations ===\n";
$criticalIndexes = [
    'idx_reservations_status_date' => 'status, reservation_date',
    'idx_reservations_facility_date' => 'facility_id, reservation_date',
    'idx_reservations_user' => 'user_id',
];

$existingIndexNames = array_keys($indexGroups);
foreach ($criticalIndexes as $indexName => $columns) {
    if (in_array($indexName, $existingIndexNames)) {
        echo "✓ $indexName exists\n";
    } else {
        echo "✗ $indexName MISSING - should index: $columns\n";
    }
}

echo "\n=== Performance Test ===\n";
// Test query performance
$start = microtime(true);
$stmt = $pdo->query("
    SELECT COUNT(*) 
    FROM reservations 
    WHERE status = 'approved' 
    AND reservation_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->fetch();
$time = (microtime(true) - $start) * 1000;
echo "Query time: " . round($time, 2) . " ms\n";

if ($time > 100) {
    echo "⚠️  WARNING: Query is slow (>100ms). Consider adding/optimizing indexes.\n";
} else {
    echo "✓ Query performance is acceptable.\n";
}
