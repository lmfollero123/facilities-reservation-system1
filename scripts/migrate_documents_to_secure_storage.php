<?php
/**
 * Migration Script: Move Documents from public/uploads to storage/private
 * 
 * This script migrates existing documents from the old public/uploads/documents/
 * location to the new secure storage/private/documents/ location.
 * 
 * Usage: php scripts/migrate_documents_to_secure_storage.php [--dry-run]
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/secure_documents.php';

$dryRun = in_array('--dry-run', $argv ?? []);

echo "=== Document Migration Script ===\n";
echo "Moving documents from public/uploads/documents/ to storage/private/documents/\n";
if ($dryRun) {
    echo "DRY RUN MODE - No files will be moved\n";
}
echo "\n";

$pdo = db();

// Get all non-archived documents
$stmt = $pdo->query("SELECT id, user_id, file_path, file_name FROM user_documents WHERE is_archived = FALSE");
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$migrated = 0;
$skipped = 0;
$failed = 0;
$errors = [];

foreach ($documents as $doc) {
    $filePath = $doc['file_path'];
    $docId = $doc['id'];
    $userId = $doc['user_id'];
    
    // Check if already in secure storage
    if (strpos($filePath, 'storage/private/documents/') === 0) {
        echo "✓ Document #{$docId} already in secure storage\n";
        $skipped++;
        continue;
    }
    
    // Find source file (handle old public/uploads path)
    $sourcePath = null;
    if (strpos($filePath, '/public/uploads/documents/') !== false) {
        $sourcePath = str_replace(
            base_path() . '/public/uploads/documents/',
            app_root_path() . '/public/uploads/documents/',
            $filePath
        );
    } else {
        // Try direct path
        $sourcePath = app_root_path() . '/' . $filePath;
    }
    
    if (!file_exists($sourcePath)) {
        echo "✗ Document #{$docId}: Source file not found: {$sourcePath}\n";
        $failed++;
        $errors[] = "Document #{$docId}: File not found";
        continue;
    }
    
    // Destination: secure storage
    $destDir = getUserDocumentStoragePath($userId);
    $destPath = $destDir . '/' . basename($sourcePath);
    
    // Handle filename conflicts
    $counter = 1;
    $originalDestPath = $destPath;
    while (file_exists($destPath)) {
        $pathInfo = pathinfo($originalDestPath);
        $destPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
        $counter++;
    }
    
    if ($dryRun) {
        echo "  [DRY RUN] Would move: {$sourcePath} → {$destPath}\n";
        $migrated++;
    } else {
        // Move file
        if (rename($sourcePath, $destPath)) {
            // Set restrictive permissions
            chmod($destPath, 0600);
            
            // Update database with new relative path
            $relativePath = SECURE_DOCUMENT_STORAGE_PATH . $userId . '/' . basename($destPath);
            $updateStmt = $pdo->prepare("UPDATE user_documents SET file_path = ? WHERE id = ?");
            $updateStmt->execute([$relativePath, $docId]);
            
            echo "✓ Migrated document #{$docId} (user #{$userId})\n";
            $migrated++;
        } else {
            echo "✗ Failed to move document #{$docId}: {$sourcePath}\n";
            $failed++;
            $errors[] = "Document #{$docId}: Failed to move file";
        }
    }
}

echo "\n=== Migration Summary ===\n";
echo "Migrated: {$migrated}\n";
echo "Skipped (already secure): {$skipped}\n";
echo "Failed: {$failed}\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

if ($dryRun) {
    echo "\nThis was a DRY RUN. Run without --dry-run to perform actual migration.\n";
} else {
    echo "\nMigration complete!\n";
}

