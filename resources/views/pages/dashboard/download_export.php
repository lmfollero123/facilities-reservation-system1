<?php
/**
 * Data Export Download Handler
 * Secure download endpoint for user data exports
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/data_export.php';

// Check authentication
if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    die('Unauthorized');
}

$userId = $_SESSION['user_id'] ?? null;
$exportId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$exportId || !$userId) {
    http_response_code(400);
    die('Invalid request');
}

// Get export record
$export = getExportFile($exportId);

if (!$export) {
    http_response_code(404);
    die('Export not found or expired');
}

// Security check: Users can only download their own exports
// Admins can download any export
$role = $_SESSION['role'] ?? '';
if ($export['user_id'] !== $userId && !in_array($role, ['Admin', 'Staff'], true)) {
    http_response_code(403);
    die('Forbidden: You can only download your own exports');
}

$filepath = app_root_path() . '/' . $export['file_path'];

if (!file_exists($filepath)) {
    http_response_code(404);
    die('Export file not found');
}

// Set headers for download
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Stream file
readfile($filepath);
exit;





