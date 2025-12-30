<?php
/**
 * Cleanup Old Data Script
 * Run this via cron job to clean up old audit logs, security logs, and other data based on retention policies
 * 
 * Usage: php scripts/cleanup_old_data.php [--dry-run] [--type=audit_log|security_log]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';

// Parse command line arguments
$options = getopt('', ['dry-run', 'type:', 'verbose']);
$dryRun = isset($options['dry-run']);
$type = $options['type'] ?? 'all';
$verbose = isset($options['verbose']);

$pdo = db();

echo "=== Cleanup Old Data Script ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Dry Run: " . ($dryRun ? 'Yes' : 'No') . "\n";
echo "Type: {$type}\n";
echo "\n";

// Get retention policies
$policies = [];
$stmt = $pdo->query('SELECT * FROM document_retention_policy');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $policies[$row['document_type']] = $row;
}

$totalDeleted = 0;

// Clean up audit logs
if ($type === 'all' || $type === 'audit_log') {
    $policy = $policies['audit_log'] ?? null;
    if ($policy && $policy['auto_delete_after_days']) {
        echo "=== Cleaning up Audit Logs ===\n";
        echo "Retention: {$policy['auto_delete_after_days']} days\n";
        
        $deleteDate = date('Y-m-d', strtotime("-{$policy['auto_delete_after_days']} days"));
        
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) as count FROM audit_log WHERE created_at < ?"
        );
        $stmt->execute([$deleteDate]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        echo "Found {$count} audit log entries older than {$deleteDate}\n";
        
        if ($dryRun) {
            echo "[DRY RUN] Would delete {$count} entries\n";
        } else {
            if ($count > 0) {
                $delStmt = $pdo->prepare("DELETE FROM audit_log WHERE created_at < ?");
                $delStmt->execute([$deleteDate]);
                echo "Deleted {$count} audit log entries\n";
                $totalDeleted += $count;
            }
        }
        echo "\n";
    }
}

// Clean up security logs
if ($type === 'all' || $type === 'security_log') {
    $policy = $policies['security_log'] ?? null;
    if ($policy && $policy['auto_delete_after_days']) {
        echo "=== Cleaning up Security Logs ===\n";
        echo "Retention: {$policy['auto_delete_after_days']} days\n";
        
        $deleteDate = date('Y-m-d', strtotime("-{$policy['auto_delete_after_days']} days"));
        
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) as count FROM security_logs WHERE created_at < ?"
        );
        $stmt->execute([$deleteDate]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        echo "Found {$count} security log entries older than {$deleteDate}\n";
        
        if ($dryRun) {
            echo "[DRY RUN] Would delete {$count} entries\n";
        } else {
            if ($count > 0) {
                $delStmt = $pdo->prepare("DELETE FROM security_logs WHERE created_at < ?");
                $delStmt->execute([$deleteDate]);
                echo "Deleted {$count} security log entries\n";
                $totalDeleted += $count;
            }
        }
        echo "\n";
    }
}

// Clean up old login attempts (older than 90 days)
if ($type === 'all' || $type === 'login_attempts') {
    echo "=== Cleaning up Old Login Attempts ===\n";
    $deleteDate = date('Y-m-d', strtotime('-90 days'));
    
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) as count FROM login_attempts WHERE attempted_at < ?"
    );
    $stmt->execute([$deleteDate]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "Found {$count} login attempt entries older than {$deleteDate}\n";
    
    if ($dryRun) {
        echo "[DRY RUN] Would delete {$count} entries\n";
    } else {
        if ($count > 0) {
            $delStmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < ?");
            $delStmt->execute([$deleteDate]);
            echo "Deleted {$count} login attempt entries\n";
            $totalDeleted += $count;
        }
    }
    echo "\n";
}

// Clean up expired rate limits
if ($type === 'all' || $type === 'rate_limits') {
    echo "=== Cleaning up Expired Rate Limits ===\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM rate_limits WHERE expires_at < NOW()");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "Found {$count} expired rate limit entries\n";
    
    if ($dryRun) {
        echo "[DRY RUN] Would delete {$count} entries\n";
    } else {
        if ($count > 0) {
            $delStmt = $pdo->exec("DELETE FROM rate_limits WHERE expires_at < NOW()");
            echo "Deleted {$delStmt} rate limit entries\n";
            $totalDeleted += $delStmt;
        }
    }
    echo "\n";
}

echo "=== Summary ===\n";
if ($dryRun) {
    echo "Dry run completed - no data was actually deleted\n";
} else {
    echo "Total deleted: {$totalDeleted} entries\n";
}

echo "Completed at: " . date('Y-m-d H:i:s') . "\n";



