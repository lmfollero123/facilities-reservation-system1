<?php
require_once __DIR__ . '/../config/database.php';
$pdo = db();

echo "facility_live_status table: ";
$st = $pdo->query("SHOW TABLES LIKE 'facility_live_status'");
echo $st->fetchColumn() ? "yes\n" : "no\n";

foreach (['attendance_checkin_token', 'no_show_flagged_at'] as $col) {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['reservations', $col]);
    echo "reservations.$col: " . ($st->fetchColumn() ? "yes\n" : "no\n");
}
