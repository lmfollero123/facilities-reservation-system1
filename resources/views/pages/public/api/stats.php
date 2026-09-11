<?php
/**
 * Read-only headline metric for the Main LGU SSO hub dashboard.
 * Auth: Authorization: Bearer <SSO_SHARED_SECRET> (same secret used for SSO).
 */
require_once __DIR__ . '/../../../../../config/app.php';
require_once __DIR__ . '/../../../../../config/database.php';
require_once __DIR__ . '/../../../../../config/sso_helper.php';

header('Content-Type: application/json; charset=utf-8');

$ssoSecret = frs_sso_shared_secret();

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
$token = preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) ? $m[1] : '';

if ($ssoSecret === null || $token === '' || !hash_equals($ssoSecret, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$count = (int) db()->query('SELECT COUNT(*) FROM reservations')->fetchColumn();

echo json_encode(['count' => $count, 'label' => 'Facility Reservations']);
