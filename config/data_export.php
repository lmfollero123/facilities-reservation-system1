<?php
/**
 * Data Export Helper Functions
 * Handles user data export for Data Privacy Act compliance
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/audit.php';

// Export file expiration (7 days)
define('DATA_EXPORT_EXPIRATION_DAYS', 7);

/**
 * Export user data (full export)
 */
function exportUserData(int $userId, string $exportType = 'full', ?int $createdBy = null): ?string
{
    $pdo = db();
    
    // Get user data
    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return null;
    }
    
    // Create export data structure
    $exportData = [
        'export_type' => $exportType,
        'exported_at' => date('Y-m-d H:i:s'),
        'user' => []
    ];
    
    // Always include basic user info
    $exportData['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'mobile' => $user['mobile'],
        'address' => $user['address'],
        'role' => $user['role'],
        'status' => $user['status'],
        'created_at' => $user['created_at'],
        'last_login_at' => $user['last_login_at']
    ];
    
    // Include documents if requested
    if ($exportType === 'full' || $exportType === 'documents') {
        $docStmt = $pdo->prepare(
            'SELECT id, document_type, file_name, file_size, uploaded_at, is_archived 
             FROM user_documents 
             WHERE user_id = ? 
             ORDER BY uploaded_at DESC'
        );
        $docStmt->execute([$userId]);
        $exportData['user']['documents'] = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Include reservations if requested
    if ($exportType === 'full' || $exportType === 'reservations') {
        $resStmt = $pdo->prepare(
            'SELECT r.*, f.name as facility_name, f.location as facility_location
             FROM reservations r
             JOIN facilities f ON r.facility_id = f.id
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC'
        );
        $resStmt->execute([$userId]);
        $exportData['user']['reservations'] = $resStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Include reservation history
        if (!empty($exportData['user']['reservations'])) {
            $reservationIds = array_column($exportData['user']['reservations'], 'id');
            $placeholders = str_repeat('?,', count($reservationIds) - 1) . '?';
            $histStmt = $pdo->prepare(
                "SELECT * FROM reservation_history 
                 WHERE reservation_id IN ($placeholders)
                 ORDER BY created_at DESC"
            );
            $histStmt->execute($reservationIds);
            $exportData['user']['reservation_history'] = $histStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Include profile data if requested
    if ($exportType === 'full' || $exportType === 'profile') {
        $exportData['user']['profile'] = [
            'latitude' => $user['latitude'],
            'longitude' => $user['longitude'],
            'profile_picture' => $user['profile_picture']
        ];
    }
    
    // Include violations if any
    if ($exportType === 'full') {
        $violStmt = $pdo->prepare(
            'SELECT * FROM user_violations 
             WHERE user_id = ? 
             ORDER BY created_at DESC'
        );
        $violStmt->execute([$userId]);
        $exportData['user']['violations'] = $violStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Include notifications if requested
    if ($exportType === 'full') {
        $notifStmt = $pdo->prepare(
            'SELECT * FROM notifications 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT 100'
        );
        $notifStmt->execute([$userId]);
        $exportData['user']['notifications'] = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Create export directory if it doesn't exist
    $exportDir = app_root_path() . '/storage/exports/';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0775, true);
    }
    
    // Generate filename
    $filename = 'user_' . $userId . '_' . date('Y-m-d_His') . '_' . $exportType . '.json';
    $filepath = $exportDir . $filename;
    
    // Write JSON file
    file_put_contents($filepath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Store export record in database
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . DATA_EXPORT_EXPIRATION_DAYS . ' days'));
    $stmt = $pdo->prepare(
        'INSERT INTO data_exports (user_id, export_type, file_path, expires_at, created_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $relativePath = 'storage/exports/' . $filename;
    $stmt->execute([$userId, $exportType, $relativePath, $expiresAt, $createdBy]);
    
    // Log audit event
    logAudit(
        'User data exported',
        'Data Export',
        "User ID: {$userId}, Export Type: {$exportType}, Created By: " . ($createdBy ?: 'User')
    );
    
    return $filepath;
}

/**
 * Get export file for download
 */
function getExportFile(int $exportId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT de.*, u.name as user_name, u.email as user_email
         FROM data_exports de
         JOIN users u ON de.user_id = u.id
         WHERE de.id = ? AND de.expires_at > NOW()'
    );
    $stmt->execute([$exportId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Clean up expired export files
 */
function cleanupExpiredExports(): int
{
    $pdo = db();
    
    // Get expired exports
    $stmt = $pdo->query(
        'SELECT id, file_path FROM data_exports WHERE expires_at < NOW()'
    );
    $exports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $deleted = 0;
    foreach ($exports as $export) {
        $filepath = app_root_path() . '/' . $export['file_path'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        // Delete database record
        $delStmt = $pdo->prepare('DELETE FROM data_exports WHERE id = ?');
        $delStmt->execute([$export['id']]);
        $deleted++;
    }
    
    return $deleted;
}

/**
 * Get user's export history
 */
function getUserExportHistory(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, export_type, file_path, expires_at, created_at, created_by
         FROM data_exports
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

