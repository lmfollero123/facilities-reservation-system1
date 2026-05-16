<?php
require_once __DIR__ . '/../config/database.php';
$pdo = db();

$createLive = <<<'SQL'
CREATE TABLE IF NOT EXISTS facility_live_status (
    facility_id INT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('auto', 'in_use', 'vacant', 'event_ending', 'closed') NOT NULL DEFAULT 'auto',
    note VARCHAR(255) NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_facility_live_status_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_facility_live_status_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$pdo->exec($createLive);
echo "facility_live_status: OK\n";
