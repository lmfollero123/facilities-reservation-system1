<?php
/**
 * Document Archival Helper Functions
 * Handles document archival, restoration, and retention policy management
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/secure_documents.php';

// Retention periods (in days)
define('DOCUMENT_ACTIVE_RETENTION_DAYS', 1095); // 3 years
define('DOCUMENT_ARCHIVE_GRACE_PERIOD_DAYS', 30); // 30 days before archiving deleted users' docs

// getArchiveStoragePath() is now defined in secure_documents.php

/**
 * Check if documents should be archived for a user
 */
function shouldArchiveUserDocuments(int $userId): bool
{
    $pdo = db();
    
    // Check user status
    $userStmt = $pdo->prepare('SELECT status FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return false; // User doesn't exist
    }
    
    // If user is locked/deleted, archive after grace period
    if (in_array($user['status'], ['locked'])) {
        $stmt = $pdo->prepare(
            'SELECT MAX(updated_at) as last_update 
             FROM users 
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([$userId, $user['status']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['last_update']) {
            $daysSinceUpdate = (time() - strtotime($result['last_update'])) / 86400;
            return $daysSinceUpdate >= DOCUMENT_ARCHIVE_GRACE_PERIOD_DAYS;
        }
    }
    
    // Check if documents are older than retention period
    $docStmt = $pdo->prepare(
        'SELECT COUNT(*) as count 
         FROM user_documents 
         WHERE user_id = ? 
           AND is_archived = FALSE 
           AND uploaded_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $docStmt->execute([$userId, DOCUMENT_ACTIVE_RETENTION_DAYS]);
    $result = $docStmt->fetch(PDO::FETCH_ASSOC);
    
    return $result && $result['count'] > 0;
}

/**
 * Archive documents for a user
 */
function archiveUserDocuments(int $userId, ?int $archivedBy = null): array
{
    $pdo = db();
    $archivePath = getArchiveStoragePath();
    $userArchiveDir = $archivePath . $userId;
    
    if (!is_dir($userArchiveDir)) {
        mkdir($userArchiveDir, 0775, true);
    }
    
    // Get all non-archived documents for the user
    $stmt = $pdo->prepare(
        'SELECT id, file_path, file_name 
         FROM user_documents 
         WHERE user_id = ? AND is_archived = FALSE'
    );
    $stmt->execute([$userId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $archived = [];
    $failed = [];
    
    foreach ($documents as $doc) {
        // Get actual filesystem path (handles both old public/uploads and new storage/private paths)
        $filePath = $doc['file_path'];
        $actualPath = null;
        
        // Check if it's new secure storage path
        if (strpos($filePath, 'storage/private/documents/') === 0) {
            $actualPath = app_root_path() . '/' . $filePath;
        } 
        // Check if it's old public/uploads path
        elseif (strpos($filePath, '/public/uploads/documents/') !== false) {
            $actualPath = str_replace(
                base_path() . '/public/uploads/documents/',
                app_root_path() . '/public/uploads/documents/',
                $filePath
            );
        }
        // Try direct path
        else {
            $actualPath = app_root_path() . '/' . $filePath;
        }
        
        if (!$actualPath || !file_exists($actualPath)) {
            $failed[] = $doc['file_name'] . ' (file not found)';
            continue;
        }
        
        // Create archive filename with timestamp
        $archiveFileName = date('Y-m-d_') . $doc['file_name'];
        $archiveFilePath = $userArchiveDir . '/' . $archiveFileName;
        
        // Move file to archive
        if (rename($actualPath, $archiveFilePath)) {
            // Update database record
            $updateStmt = $pdo->prepare(
                'UPDATE user_documents 
                 SET is_archived = TRUE,
                     archived_at = NOW(),
                     archived_by = ?,
                     archive_path = ?
                 WHERE id = ?'
            );
            $relativeArchivePath = ARCHIVE_STORAGE_PATH . $userId . '/' . $archiveFileName;
            $updateStmt->execute([
                $archivedBy,
                $relativeArchivePath,
                $doc['id']
            ]);
            
            $archived[] = $doc['file_name'];
            
            // Log audit event
            logAudit(
                'Archived user document',
                'Document Management',
                "User ID: {$userId}, Document: {$doc['file_name']}, Document ID: {$doc['id']}"
            );
        } else {
            $failed[] = $doc['file_name'] . ' (move failed)';
        }
    }
    
    return [
        'archived' => $archived,
        'failed' => $failed,
        'count' => count($archived)
    ];
}

/**
 * Restore archived documents for a user
 */
function restoreArchivedDocuments(int $userId, ?int $restoredBy = null): array
{
    $pdo = db();
    $archivePath = getArchiveStoragePath();
    $userArchiveDir = $archivePath . $userId;
    $activeDocDir = app_root_path() . '/public/uploads/documents/' . $userId;
    
    if (!is_dir($activeDocDir)) {
        mkdir($activeDocDir, 0775, true);
    }
    
    // Get all archived documents for the user
    $stmt = $pdo->prepare(
        'SELECT id, archive_path, file_name 
         FROM user_documents 
         WHERE user_id = ? AND is_archived = TRUE AND archive_path IS NOT NULL'
    );
    $stmt->execute([$userId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $restored = [];
    $failed = [];
    
    foreach ($documents as $doc) {
        $archiveFilePath = app_root_path() . '/' . $doc['archive_path'];
        
        if (!file_exists($archiveFilePath)) {
            $failed[] = $doc['file_name'] . ' (archive file not found)';
            continue;
        }
        
        // Restore to secure active directory (storage/private/documents/)
        $activeDocDir = getUserDocumentStoragePath($userId);
        $restoreFileName = $doc['file_name'];
        $restoreFilePath = $activeDocDir . '/' . $restoreFileName;
        
        // Handle filename conflicts
        $counter = 1;
        while (file_exists($restoreFilePath)) {
            $pathInfo = pathinfo($restoreFileName);
            $restoreFileName = $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
            $restoreFilePath = $activeDocDir . '/' . $restoreFileName;
            $counter++;
        }
        
        // Move file back to secure active storage
        if (rename($archiveFilePath, $restoreFilePath)) {
            // Update database record with new secure path
            $relativeRestorePath = SECURE_DOCUMENT_STORAGE_PATH . $userId . '/' . $restoreFileName;
            $updateStmt = $pdo->prepare(
                'UPDATE user_documents 
                 SET is_archived = FALSE,
                     archived_at = NULL,
                     archived_by = NULL,
                     archive_path = NULL,
                     file_path = ?
                 WHERE id = ?'
            );
            $updateStmt->execute([$relativeRestorePath, $doc['id']]);
            
            $restored[] = $doc['file_name'];
            
            // Log audit event
            logAudit(
                'Restored archived document',
                'Document Management',
                "User ID: {$userId}, Document: {$doc['file_name']}, Document ID: {$doc['id']}"
            );
        } else {
            $failed[] = $doc['file_name'] . ' (restore failed)';
        }
    }
    
    return [
        'restored' => $restored,
        'failed' => $failed,
        'count' => count($restored)
    ];
}

/**
 * Get retention policy for a document type
 */
function getRetentionPolicy(string $documentType): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM document_retention_policy WHERE document_type = ?'
    );
    $stmt->execute([$documentType]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Check if a document should be archived based on retention policy
 */
function shouldArchiveByRetentionPolicy(int $documentId, string $documentType): bool
{
    $policy = getRetentionPolicy($documentType);
    if (!$policy) {
        return false;
    }
    
    $pdo = db();
    
    // Get document creation/upload date
    $stmt = $pdo->prepare(
        'SELECT uploaded_at FROM user_documents WHERE id = ?'
    );
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        return false;
    }
    
    $daysSinceUpload = (time() - strtotime($doc['uploaded_at'])) / 86400;
    return $daysSinceUpload >= $policy['archive_after_days'];
}

/**
 * Get list of users whose documents should be archived
 */
function getUsersForArchival(): array
{
    $pdo = db();
    
    // Get users with documents older than retention period
    $stmt = $pdo->prepare(
        'SELECT DISTINCT ud.user_id, u.name, u.email, u.status
         FROM user_documents ud
         JOIN users u ON ud.user_id = u.id
         WHERE ud.is_archived = FALSE
           AND ud.uploaded_at < DATE_SUB(NOW(), INTERVAL ? DAY)
         ORDER BY ud.uploaded_at ASC'
    );
    $stmt->execute([DOCUMENT_ACTIVE_RETENTION_DAYS]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get storage statistics
 */
function getStorageStatistics(): array
{
    $pdo = db();
    
    // Count active documents
    $activeStmt = $pdo->query(
        'SELECT COUNT(*) as count, SUM(file_size) as total_size 
         FROM user_documents 
         WHERE is_archived = FALSE'
    );
    $active = $activeStmt->fetch(PDO::FETCH_ASSOC);
    
    // Count archived documents
    $archivedStmt = $pdo->query(
        'SELECT COUNT(*) as count, SUM(file_size) as total_size 
         FROM user_documents 
         WHERE is_archived = TRUE'
    );
    $archived = $archivedStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate directory sizes (if possible)
    $activeDirSize = 0;
    $archiveDirSize = 0;
    
    // Check both old location (public/uploads) and new secure location (storage/private)
    $activeDirOld = app_root_path() . '/public/uploads/documents/';
    $activeDirNew = getSecureDocumentStoragePath();
    $archiveDir = getArchiveStoragePath();
    
    // Calculate size from both old and new locations
    foreach ([$activeDirOld, $activeDirNew] as $dir) {
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $activeDirSize += $file->getSize();
                }
            }
        }
    }
    
    if (is_dir($archiveDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($archiveDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $archiveDirSize += $file->getSize();
            }
        }
    }
    
    return [
        'active' => [
            'count' => (int)($active['count'] ?? 0),
            'database_size' => (int)($active['total_size'] ?? 0),
            'filesystem_size' => $activeDirSize,
            'size_mb' => round($activeDirSize / 1048576, 2)
        ],
        'archived' => [
            'count' => (int)($archived['count'] ?? 0),
            'database_size' => (int)($archived['total_size'] ?? 0),
            'filesystem_size' => $archiveDirSize,
            'size_mb' => round($archiveDirSize / 1048576, 2)
        ],
        'total' => [
            'count' => (int)($active['count'] ?? 0) + (int)($archived['count'] ?? 0),
            'database_size' => (int)($active['total_size'] ?? 0) + (int)($archived['total_size'] ?? 0),
            'filesystem_size' => $activeDirSize + $archiveDirSize,
            'size_mb' => round(($activeDirSize + $archiveDirSize) / 1048576, 2)
        ]
    ];
}


