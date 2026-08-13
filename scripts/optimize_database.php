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
        // OPTIMIZE TABLE returns a result set (Table/Op/Msg_type/Msg_text), not
        // just an affected-row count -- exec() doesn't fetch it, which leaves
        // the connection stuck on a pending result and makes every subsequent
        // query in this loop fail with "unbuffered queries are active". query()
        // + closeCursor() actually consumes the result before moving on.
        $pdo->query("OPTIMIZE TABLE `{$table}`")->closeCursor();
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






