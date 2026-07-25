<?php
/**
 * Read-only headline metric for the Main LGU SSO hub dashboard.
 * Auth: Authorization: Bearer <SSO_SHARED_SECRET> (same secret used for SSO).
 */
require_once __DIR__ . '/../../../../../config/app.php';
require_once __DIR__ . '/../../../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$ssoSecret = env_value('SSO_SHARED_SECRET', '6724201881389f70d4d233dcd87caa15d507ebfd56f3fc73e0ad2b1c61e2d825');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
$token = preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) ? $m[1] : '';

if (!hash_equals($ssoSecret, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$count = (int) db()->query('SELECT COUNT(*) FROM reservations')->fetchColumn();

echo json_encode(['count' => $count, 'label' => 'Facility Reservations']);
