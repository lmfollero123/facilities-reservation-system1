<?php
/**
 * Secure download handler for violation evidence photos.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/violations.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/audit.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    die('Unauthorized: Please log in to access this photo.');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$violationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($violationId <= 0 || $userId <= 0) {
    http_response_code(400);
    die('Invalid request: Missing violation ID or user ID.');
}

$accessCheck = frs_can_access_violation_photo($violationId, $userId, $role);
if (!$accessCheck['allowed']) {
    http_response_code(403);
    logSecurityEvent(
        'violation_photo_access_denied',
        "User #{$userId} attempted violation photo #{$violationId}: {$accessCheck['reason']}",
        'warning'
    );
    die('Forbidden: ' . $accessCheck['reason']);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT photo_path, user_id FROM user_violations WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $violationId]);
$violation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$violation || empty($violation['photo_path']) || !is_file($violation['photo_path'])) {
    http_response_code(404);
    die('Photo not found or has been moved.');
}

logAudit('Accessed violation photo', 'Violations', 'Violation #' . $violationId, $userId);

$filePath = $violation['photo_path'];
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
header('Content-Disposition: inline; filename="violation-' . $violationId . '.jpg"');

if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

readfile($filePath);
exit;
