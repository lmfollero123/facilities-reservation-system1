<?php
/**
 * Secure Document Storage & Access Helper Functions
 * Handles secure document storage (outside public/) and access control
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/security.php';

// Secure document storage path (outside public/, not web-accessible)
define('SECURE_DOCUMENT_STORAGE_PATH', 'storage/private/documents/');

// Archive storage path (also outside public/)
define('ARCHIVE_STORAGE_PATH', 'storage/archive/documents/');

/**
 * Get the absolute path to secure document storage directory
 */
function getSecureDocumentStoragePath(): string
{
    $storagePath = app_root_path() . '/' . SECURE_DOCUMENT_STORAGE_PATH;
    if (!is_dir($storagePath)) {
        mkdir($storagePath, 0700, true); // Restrictive permissions (owner only)
    }
    return $storagePath;
}

/**
 * Get the absolute path to archive storage directory
 */
function getArchiveStoragePath(): string
{
    $archivePath = app_root_path() . '/' . ARCHIVE_STORAGE_PATH;
    if (!is_dir($archivePath)) {
        mkdir($archivePath, 0700, true); // Restrictive permissions
    }
    return $archivePath;
}

/**
 * Get secure document storage path for a specific user
 */
function getUserDocumentStoragePath(int $userId): string
{
    $userDir = getSecureDocumentStoragePath() . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0700, true);
    }
    return $userDir;
}

/**
 * Get archive storage path for a specific user
 */
function getUserArchiveStoragePath(int $userId): string
{
    $userDir = getArchiveStoragePath() . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0700, true);
    }
    return $userDir;
}

/**
 * Generate secure download URL for a document
 * This URL goes through the secure download handler
 */
function getSecureDocumentUrl(int $documentId, string $accessType = 'view'): string
{
    return base_path() . '/resources/views/pages/dashboard/download_document.php?id=' . 
           urlencode($documentId) . '&type=' . urlencode($accessType);
}

/**
 * Log document access
 */
function logDocumentAccess(int $documentId, int $accessedBy, string $accessType = 'view'): void
{
    $pdo = db();
    
    // Get document owner
    $docStmt = $pdo->prepare('SELECT user_id FROM user_documents WHERE id = ?');
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        return; // Document doesn't exist
    }
    
    $userId = $doc['user_id'];
    
    // Log access
    $stmt = $pdo->prepare(
        'INSERT INTO document_access_log (document_id, user_id, accessed_by, access_type, ip_address, user_agent) 
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    
    $stmt->execute([
        $documentId,
        $userId,
        $accessedBy,
        $accessType,
        getClientIP(),
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);
    
    // Also log to audit trail for admin visibility
    logAudit(
        "Document #{$documentId} accessed (type: {$accessType})",
        'Document Access',
        "Document #{$documentId} accessed by user #{$accessedBy}",
        $accessedBy
    );
}

/**
 * Check if user can access a document
 * Returns: ['allowed' => bool, 'reason' => string]
 */
function canUserAccessDocument(int $documentId, ?int $userId, ?string $role): array
{
    $pdo = db();
    
    // Get document info
    $stmt = $pdo->prepare(
        'SELECT user_id, is_archived FROM user_documents WHERE id = ?'
    );
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        return ['allowed' => false, 'reason' => 'Document not found'];
    }
    
    // Admin and Staff can access any document
    if (in_array($role, ['Admin', 'Staff'], true)) {
        return ['allowed' => true, 'reason' => 'Admin/Staff access'];
    }
    
    // Users can only access their own documents
    if ($userId && $doc['user_id'] == $userId) {
        return ['allowed' => true, 'reason' => 'Owner access'];
    }
    
    return ['allowed' => false, 'reason' => 'Access denied: Not document owner'];
}

/**
 * Get document file path (absolute filesystem path)
 */
function getDocumentFilePath(int $documentId): ?string
{
    $pdo = db();
    
    $stmt = $pdo->prepare(
        'SELECT file_path, is_archived, archive_path FROM user_documents WHERE id = ?'
    );
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        return null;
    }
    
    if ($doc['is_archived'] && $doc['archive_path']) {
        // Archived document
        return app_root_path() . '/' . $doc['archive_path'];
    } else {
        // Active document - convert URL path to filesystem path
        $filePath = $doc['file_path'];
        
        // Handle both old (public/uploads) and new (storage/private) paths
        if (strpos($filePath, '/public/uploads/documents/') !== false) {
            // Old path - convert to new secure path
            $parts = explode('/public/uploads/documents/', $filePath);
            if (count($parts) === 2) {
                $userId = explode('/', $parts[1])[0];
                $filename = basename($parts[1]);
                return getUserDocumentStoragePath((int)$userId) . '/' . $filename;
            }
        } elseif (strpos($filePath, SECURE_DOCUMENT_STORAGE_PATH) !== false) {
            // New path
            return app_root_path() . '/' . $filePath;
        }
        
        // Fallback: try direct path
        $fsPath = str_replace(base_path() . '/public/uploads/documents/', 
                             app_root_path() . '/public/uploads/documents/', 
                             $filePath);
        if (file_exists($fsPath)) {
            return $fsPath;
        }
        
        return null;
    }
}

/**
 * Save uploaded document to secure storage
 * Returns: ['success' => bool, 'file_path' => string|null, 'error' => string|null]
 */
function saveDocumentToSecureStorage(array $file, int $userId, string $documentType): array
{
    $errors = validateFileUpload($file, ['image/jpeg','image/png','image/gif','image/webp','application/pdf']);
    if (!empty($errors)) {
        return ['success' => false, 'file_path' => null, 'error' => implode(' ', $errors)];
    }
    
    $userDir = getUserDocumentStoragePath($userId);
    
    // Generate safe filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = $safeName . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $userDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'file_path' => null, 'error' => 'Failed to save uploaded document'];
    }
    
    // Set restrictive permissions
    chmod($destPath, 0600); // Owner read/write only
    
    // Return relative path (for database storage)
    $relativePath = SECURE_DOCUMENT_STORAGE_PATH . $userId . '/' . $filename;
    
    return ['success' => true, 'file_path' => $relativePath, 'error' => null];
}

