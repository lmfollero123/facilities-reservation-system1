<?php
/**
 * Secure download handler for reservation supporting documents.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/reservation_documents.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/audit.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    die('Unauthorized: Please log in to access documents.');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$documentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$accessType = isset($_GET['type']) ? (string)$_GET['type'] : 'view';

if ($documentId <= 0 || $userId <= 0) {
    http_response_code(400);
    die('Invalid request: Missing document ID or user ID.');
}

if (!in_array($accessType, ['view', 'download'], true)) {
    $accessType = 'view';
}

$accessCheck = frs_can_access_reservation_document($documentId, $userId, $role);
if (!$accessCheck['allowed']) {
    http_response_code(403);
    logSecurityEvent(
        'reservation_document_access_denied',
        "User #{$userId} attempted reservation doc #{$documentId}: {$accessCheck['reason']}",
        'warning'
    );
    die('Forbidden: ' . $accessCheck['reason']);
}

$filePath = frs_get_reservation_document_file_path($documentId);
if (!$filePath) {
    http_response_code(404);
    die('Document not found or has been moved.');
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT rd.file_name, rd.file_size, rd.document_type, rd.reservation_id
     FROM reservation_documents rd WHERE rd.id = :id LIMIT 1'
);
$stmt->execute(['id' => $documentId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    die('Document record not found.');
}

logAudit(
    'Accessed reservation document',
    'Reservations',
    'RES-' . (int)$doc['reservation_id'] . ' doc #' . $documentId . ' (' . $accessType . ')',
    $userId
);

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $filePath) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}
if (!$mimeType) {
    $mimeType = 'application/octet-stream';
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
header('Pragma: no-cache');

$safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)($doc['file_name'] ?? 'document')) ?: 'document';
if ($accessType === 'download') {
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safeFilename . '"');
}

if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

readfile($filePath);
exit;
