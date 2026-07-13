<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../../config/app.php';
require_once __DIR__ . '/../../../../../config/health_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$result = frs_run_health_checks();
$code = $result['status'] === 'healthy' ? 200 : 503;
http_response_code($code);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
