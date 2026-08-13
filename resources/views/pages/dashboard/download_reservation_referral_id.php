<?php
/**
 * Secure download handler for a reservation's Barangay Culiat referral ID
 * (the non-Culiat requester's supporting document, stored directly on the
 * reservations row — not the shared reservation_documents table).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/audit.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    die('Unauthorized: Please log in to access documents.');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$reservationId = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;

if ($reservationId <= 0 || $userId <= 0) {
    http_response_code(400);
    die('Invalid request.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT user_id, referral_id_document_path FROM reservations WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $reservationId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['referral_id_document_path'])) {
    http_response_code(404);
    die('Document not found.');
}

$isOwner = (int)$row['user_id'] === $userId;
$isStaffOrAdmin = in_array($role, ['Admin', 'Staff'], true);
if (!$isOwner && !$isStaffOrAdmin) {
    http_response_code(403);
    logSecurityEvent(
        'reservation_referral_id_access_denied',
        "User #{$userId} attempted referral ID for reservation #{$reservationId}",
        'warning'
    );
    die('Forbidden.');
}

$filePath = app_root_path() . '/' . ltrim((string)$row['referral_id_document_path'], '/');
if (!is_file($filePath)) {
    http_response_code(404);
    die('Document not found or has been moved.');
}

logAudit(
    'Accessed reservation referral ID',
    'Reservations',
    'RES-' . $reservationId . ' referral ID viewed',
    $userId
);

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $filePath) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}

header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
header('Pragma: no-cache');
header('Content-Disposition: inline; filename="referral_id_' . $reservationId . '"');

if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

readfile($filePath);
exit;
