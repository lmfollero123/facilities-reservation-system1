<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
$pdo = db();
$sql = file_get_contents(__DIR__ . '/../database/migration_add_mobile_api.sql');
$pdo->exec($sql);
echo "Mobile API tables created/verified.\n";
