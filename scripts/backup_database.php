<?php
/**
 * Database Backup Script
 * Dumps the database to a gzip-compressed .sql.gz file via mysqldump and
 * prunes dumps older than the retention window. Intended to run daily via
 * cron; safe to run manually too.
 *
 * Usage: php scripts/backup_database.php [--dir=/path/to/backups] [--retention-days=14] [--dry-run]
 */

require_once __DIR__ . '/../config/database.php';

$options = getopt('', ['dir:', 'retention-days:', 'dry-run']);
$dryRun = isset($options['dry-run']);
$retentionDays = isset($options['retention-days']) ? max(1, (int)$options['retention-days']) : 14;
$backupDir = isset($options['dir'])
    ? rtrim($options['dir'], '/')
    : rtrim((string)(getenv('DB_BACKUP_DIR') ?: (dirname(__DIR__) . '/storage/backups')), '/');

echo "=== Database Backup Script ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Backup directory: {$backupDir}\n";
echo "Retention: {$retentionDays} days\n\n";

if (!is_dir($backupDir)) {
    if ($dryRun) {
        echo "[DRY RUN] Would create directory {$backupDir}\n";
    } elseif (!mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Unable to create backup directory {$backupDir}\n");
        exit(1);
    }
}

$dbHost = trim((string)(getenv('DB_HOST') !== false ? getenv('DB_HOST') : DB_HOST));
$dbName = trim((string)(getenv('DB_NAME') !== false ? getenv('DB_NAME') : DB_NAME));
$dbUser = (string)(getenv('DB_USER') !== false ? getenv('DB_USER') : DB_USER);
$dbPass = (string)(getenv('DB_PASS') !== false ? getenv('DB_PASS') : DB_PASS);

$timestamp = date('Y-m-d_His');
$outFile = "{$backupDir}/{$dbName}_{$timestamp}.sql.gz";

if ($dryRun) {
    echo "[DRY RUN] Would dump database '{$dbName}' to {$outFile}\n";
} else {
    // Password goes through the MYSQL_PWD env var, not -p on the command line
    // (which `ps` would expose) and not a --defaults-extra-file (whose INI
    // parsing can silently mangle a password containing '#', quotes, or
    // leading/trailing space -- exactly the kind of failure that produced an
    // empty-but-"successful" backup the first time this script ran).
    $sqlFile = "{$backupDir}/.{$dbName}_{$timestamp}.sql.tmp";
    $cmd = sprintf(
        'mysqldump --user=%s --host=%s --single-transaction --quick --routines --triggers %s > %s 2>&1',
        escapeshellarg($dbUser),
        escapeshellarg($dbHost),
        escapeshellarg($dbName),
        escapeshellarg($sqlFile)
    );

    putenv("MYSQL_PWD={$dbPass}");
    exec($cmd, $output, $exitCode);
    putenv('MYSQL_PWD'); // unset immediately, don't leave it in this process's env

    // A real dump of this schema is at minimum tens of KB; anything tiny means
    // mysqldump failed but the shell still reported success (or dumped an
    // auth-error message instead of SQL) -- exit code alone isn't enough,
    // as the first run of this script demonstrated.
    if ($exitCode !== 0 || !file_exists($sqlFile) || filesize($sqlFile) < 1024) {
        $preview = file_exists($sqlFile) ? file_get_contents($sqlFile) : implode("\n", $output);
        echo "\xe2\x9c\x97 Backup FAILED (exit code {$exitCode}):\n{$preview}\n";
        if (file_exists($sqlFile)) {
            unlink($sqlFile);
        }
        exit(1);
    }

    exec(sprintf('gzip -f %s', escapeshellarg($sqlFile)), $gzOutput, $gzExitCode);
    if ($gzExitCode !== 0 || !file_exists("{$sqlFile}.gz")) {
        echo "\xe2\x9c\x97 gzip FAILED (exit code {$gzExitCode}): " . implode("\n", $gzOutput) . "\n";
        exit(1);
    }
    rename("{$sqlFile}.gz", $outFile);
    chmod($outFile, 0600); // contains full user data -- owner-readable only

    $sizeKb = round(filesize($outFile) / 1024, 1);
    echo "\xe2\x9c\x93 Backup created: {$outFile} ({$sizeKb} KB)\n";
}

echo "\n=== Pruning dumps older than {$retentionDays} days ===\n";
$cutoff = time() - ($retentionDays * 86400);
$pruned = 0;
foreach (glob("{$backupDir}/{$dbName}_*.sql.gz") ?: [] as $file) {
    if (filemtime($file) < $cutoff) {
        if ($dryRun) {
            echo "[DRY RUN] Would delete {$file}\n";
        } else {
            unlink($file);
            echo "Deleted old backup: {$file}\n";
        }
        $pruned++;
    }
}
echo "Pruned {$pruned} old backup(s)\n";

echo "\nCompleted at: " . date('Y-m-d H:i:s') . "\n";
