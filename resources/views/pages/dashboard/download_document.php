<?php
/**
 * Secure Document Download Handler
 * Provides secure, logged access to user documents with RBAC enforcement
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/secure_documents.php';
require_once __DIR__ . '/../../../../config/security.php';

// Check authentication
if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    die('Unauthorized: Please log in to access documents.');
}

$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? '';
$documentId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$accessType = isset($_GET['type']) ? $_GET['type'] : 'view';

if (!$documentId || !$userId) {
    http_response_code(400);
    die('Invalid request: Missing document ID or user ID.');
}

// Validate access type
if (!in_array($accessType, ['view', 'download', 'view_thumbnail'], true)) {
    $accessType = 'view';
}

// Check if user can access this document
$accessCheck = canUserAccessDocument($documentId, $userId, $role);
if (!$accessCheck['allowed']) {
    http_response_code(403);
    logSecurityEvent('document_access_denied', 
        "User #{$userId} attempted to access document #{$documentId}: {$accessCheck['reason']}", 
        'warning');
    die('Forbidden: ' . $accessCheck['reason']);
}

// Get document file path
$filePath = getDocumentFilePath($documentId);

if (!$filePath || !file_exists($filePath)) {
    http_response_code(404);
    logSecurityEvent('document_not_found', 
        "Document #{$documentId} file not found on filesystem", 
        'error');
    die('Document not found or has been moved.');
}

// Get document info for logging and headers
$pdo = db();
$stmt = $pdo->prepare(
    'SELECT file_name, file_size, document_type FROM user_documents WHERE id = ?'
);
$stmt->execute([$documentId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    die('Document record not found.');
}

// Log access
logDocumentAccess($documentId, $userId, $accessType);

// Determine MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

// Set appropriate headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
header('Pragma: no-cache');

// For downloads, force download dialog
if ($accessType === 'download') {
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['file_name']);
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
} else {
    // For viewing, inline display
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
}

// Security headers
setSecurityHeaders();

// Stream file
readfile($filePath);
exit;




