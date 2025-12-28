<?php
/**
 * Database Optimization Script
 * Run this weekly via cron job to optimize database tables
 * 
 * Usage: php scripts/optimize_database.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = db();

echo "=== Database Optimization Script ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$tables = [
    'users',
    'facilities',
    'reservations',
    'reservation_history',
    'user_documents',
    'notifications',
    'audit_log',
    'user_violations',
    'facility_blackout_dates',
    'data_exports'
];

$optimized = 0;
$failed = 0;

foreach ($tables as $table) {
    try {
        echo "Optimizing table: {$table}... ";
        $pdo->exec("OPTIMIZE TABLE `{$table}`");
        echo "✓ Done\n";
        $optimized++;
    } catch (Exception $e) {
        echo "✗ Failed: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n=== Summary ===\n";
echo "Optimized: {$optimized} tables\n";
echo "Failed: {$failed} tables\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";


