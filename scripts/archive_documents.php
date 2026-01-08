<?php
/**
 * Document Archival Script
 * Run this via cron job daily to archive old documents
 * 
 * Usage: php scripts/archive_documents.php [--dry-run] [--user-id=ID]
 */

require_once __DIR__ . '/../config/document_archival.php';
require_once __DIR__ . '/../config/database.php';

// Parse command line arguments
$options = getopt('', ['dry-run', 'user-id:', 'verbose', 'limit:']);
$dryRun = isset($options['dry-run']);
$userId = isset($options['user-id']) ? (int)$options['user-id'] : null;
$verbose = isset($options['verbose']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 50;

$pdo = db();

echo "=== Document Archival Script ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Dry Run: " . ($dryRun ? 'Yes' : 'No') . "\n";
echo "\n";

$totalArchived = 0;
$totalFailed = 0;

if ($userId) {
    // Archive documents for specific user
    echo "Archiving documents for User ID: {$userId}\n";
    
    if ($dryRun) {
        if (shouldArchiveUserDocuments($userId)) {
            echo "  [DRY RUN] Documents for user {$userId} should be archived\n";
            
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) as count FROM user_documents WHERE user_id = ? AND is_archived = FALSE'
            );
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "  [DRY RUN] Would archive {$result['count']} documents\n";
        } else {
            echo "  [DRY RUN] No documents need archiving for user {$userId}\n";
        }
    } else {
        $result = archiveUserDocuments($userId);
        $totalArchived += $result['count'];
        $totalFailed += count($result['failed']);
        
        echo "  Archived: {$result['count']} documents\n";
        if (!empty($result['failed'])) {
            echo "  Failed: " . count($result['failed']) . " documents\n";
            if ($verbose) {
                foreach ($result['failed'] as $failed) {
                    echo "    - {$failed}\n";
                }
            }
        }
    }
} else {
    // Get users whose documents should be archived
    $users = getUsersForArchival();
    $processed = 0;
    
    echo "Found " . count($users) . " users with documents to archive\n";
    echo "Processing limit: {$limit} users\n\n";
    
    foreach (array_slice($users, 0, $limit) as $user) {
        $processed++;
        echo "Processing User {$processed}: {$user['name']} (ID: {$user['user_id']})\n";
        
        if ($dryRun) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) as count FROM user_documents WHERE user_id = ? AND is_archived = FALSE'
            );
            $stmt->execute([$user['user_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "  [DRY RUN] Would archive {$result['count']} documents\n";
        } else {
            $result = archiveUserDocuments($user['user_id']);
            $totalArchived += $result['count'];
            $totalFailed += count($result['failed']);
            
            if ($result['count'] > 0) {
                echo "  ✓ Archived: {$result['count']} documents\n";
            } else {
                echo "  - No documents to archive\n";
            }
            
            if (!empty($result['failed']) && $verbose) {
                echo "  ✗ Failed: " . count($result['failed']) . " documents\n";
                foreach ($result['failed'] as $failed) {
                    echo "    - {$failed}\n";
                }
            }
        }
        
        echo "\n";
    }
    
    echo "Processed: {$processed} users\n";
}

echo "\n=== Summary ===\n";
if ($dryRun) {
    echo "Dry run completed - no files were actually archived\n";
} else {
    echo "Total archived: {$totalArchived} documents\n";
    echo "Total failed: {$totalFailed} documents\n";
}

echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

// Clean up expired export files
echo "\n=== Cleaning up expired export files ===\n";
require_once __DIR__ . '/../config/data_export.php';
$deleted = cleanupExpiredExports();
echo "Deleted {$deleted} expired export files\n";






