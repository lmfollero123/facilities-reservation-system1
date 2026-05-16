<?php
/**
 * Run Extension Feature Migrations
 * 
 * This script runs the database migrations for the extension feature.
 * Run this file from the browser or command line to add the extension fields.
 */

require_once __DIR__ . '/config/database.php';

$pdo = db();

try {
    // Run extension config migration
    $sql = file_get_contents(__DIR__ . '/database/migration_add_extension_config.sql');
    $pdo->exec($sql);
    echo "Extension config migration completed successfully.\n";
    
    // Run extension tracking migration
    $sql = file_get_contents(__DIR__ . '/database/migration_add_extension_tracking.sql');
    $pdo->exec($sql);
    echo "Extension tracking migration completed successfully.\n";
    
    echo "\nAll migrations completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
