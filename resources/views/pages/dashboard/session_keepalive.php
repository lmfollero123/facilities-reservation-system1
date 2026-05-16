<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!($_SESSION['user_authenticated'] ?? false) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$token = $_POST[CSRF_TOKEN_NAME] ?? '';
if (!is_string($token) || !verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Refresh activity timestamp
$_SESSION['last_activity'] = time();

echo json_encode([
    'success' => true,
    'remaining' => defined('SESSION_TIMEOUT') ? (int)SESSION_TIMEOUT : 120,
]);

