<?php
/**
 * Tracked database migration runner.
 *
 * Applies database/migration_*.sql files in filename order and records each
 * applied file in the schema_migrations table, so every environment
 * (production, local, teammates) can tell exactly which migrations it has —
 * the previous workflow (each file imported by hand via phpMyAdmin) is how
 * schema drift like the missing notifications.image_path column happened.
 *
 * Usage (CLI only):
 *   php run_migrations.php                 Apply pending migrations
 *   php run_migrations.php --status       List applied / pending migrations
 *   php run_migrations.php --baseline     Mark ALL current files as applied
 *                                         WITHOUT executing them. Run once on
 *                                         a database that is already up to
 *                                         date (e.g. production, dev copies).
 *   php run_migrations.php --tolerant     Apply pending, treating
 *                                         "already exists" errors (duplicate
 *                                         column/table/key/row) as skips.
 *                                         For fresh installs where schema.sql
 *                                         already contains later columns.
 *   php run_migrations.php --mark-applied=<file>
 *                                         Record one file as applied without
 *                                         executing it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden: run this script from the command line.';
    exit;
}

require_once __DIR__ . '/config/database.php';

/** MySQL/MariaDB "object already exists" error codes tolerated in --tolerant mode. */
const FRS_MIGRATION_TOLERATED_ERRNOS = [
    1050, // table already exists
    1060, // duplicate column
    1061, // duplicate key name
    1062, // duplicate entry (seed rows already present)
    1091, // can't DROP; column/key does not exist
];

function frs_migrations_dir(): string
{
    return __DIR__ . '/database';
}

/** @return list<string> migration filenames (basename), sorted */
function frs_migration_files(): array
{
    $files = glob(frs_migrations_dir() . '/migration_*.sql') ?: [];
    $names = array_map('basename', $files);
    sort($names, SORT_STRING);
    return $names;
}

function frs_migrations_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

/** @return list<string> */
function frs_migrations_applied(PDO $pdo): array
{
    return $pdo->query('SELECT filename FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN);
}

function frs_migrations_record(PDO $pdo, string $filename): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)');
    $stmt->execute([$filename]);
}

/**
 * Split an SQL file into individual statements, respecting single/double/
 * backtick quotes and -- / # / C-style comments. Multi-statement PDO::exec
 * silently ignores errors after the first statement, so we run one at a time.
 *
 * @return list<string>
 */
function frs_split_sql_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inString = null; // ', " or `
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inString !== null) {
            $current .= $ch;
            if ($ch === '\\' && $inString !== '`') {
                // consume escaped char inside ' or "
                if ($next !== '') {
                    $current .= $next;
                    $i += 2;
                    continue;
                }
            } elseif ($ch === $inString) {
                $inString = null;
            }
            $i++;
            continue;
        }

        // comments
        if ($ch === '-' && $next === '-') {
            $eol = strpos($sql, "\n", $i);
            $i = $eol === false ? $len : $eol + 1;
            continue;
        }
        if ($ch === '#') {
            $eol = strpos($sql, "\n", $i);
            $i = $eol === false ? $len : $eol + 1;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inString = $ch;
            $current .= $ch;
            $i++;
            continue;
        }

        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * Statements that switch the active database/connection. Many migration
 * files start with a hard-coded `USE facilities_reservation;` (and a few
 * with `CREATE DATABASE`), copied from schema.sql. The runner already owns
 * the connection via db(), so these are skipped: honoring them would let a
 * migration hijack a run pointed at a differently-named DB (e.g. staging).
 */
function frs_is_connection_switching_statement(string $statement): bool
{
    return (bool)preg_match('/^\s*(USE\s+|CREATE\s+DATABASE\b|DROP\s+DATABASE\b)/i', $statement);
}

/**
 * @return array{applied: bool, skipped_statements: int}
 */
function frs_apply_migration_file(PDO $pdo, string $filename, bool $tolerant): array
{
    $path = frs_migrations_dir() . '/' . $filename;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }

    $skipped = 0;
    foreach (frs_split_sql_statements($sql) as $index => $statement) {
        if (frs_is_connection_switching_statement($statement)) {
            $skipped++;
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $errno = (int)($e->errorInfo[1] ?? 0);
            if ($tolerant && in_array($errno, FRS_MIGRATION_TOLERATED_ERRNOS, true)) {
                $skipped++;
                continue;
            }
            throw new RuntimeException(
                "{$filename}: statement " . ($index + 1) . " failed (errno {$errno}): " . $e->getMessage()
            );
        }
    }

    frs_migrations_record($pdo, $filename);
    return ['applied' => true, 'skipped_statements' => $skipped];
}

function frs_migrations_main(array $argv): int
{
    $pdo = db();
    frs_migrations_ensure_table($pdo);

    $args = array_slice($argv, 1);
    $baseline = in_array('--baseline', $args, true);
    $status = in_array('--status', $args, true);
    $tolerant = in_array('--tolerant', $args, true);
    $markApplied = null;
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--mark-applied=')) {
            $markApplied = substr($arg, strlen('--mark-applied='));
        }
    }

    $all = frs_migration_files();
    $applied = frs_migrations_applied($pdo);
    $pending = array_values(array_diff($all, $applied));

    if ($status) {
        echo count($applied) . " applied, " . count($pending) . " pending\n";
        foreach ($pending as $file) {
            echo "  pending: {$file}\n";
        }
        return 0;
    }

    if ($markApplied !== null) {
        if (!in_array($markApplied, $all, true)) {
            echo "ERROR: unknown migration file: {$markApplied}\n";
            return 1;
        }
        frs_migrations_record($pdo, $markApplied);
        echo "Marked as applied (not executed): {$markApplied}\n";
        return 0;
    }

    if ($baseline) {
        $count = 0;
        foreach ($pending as $file) {
            frs_migrations_record($pdo, $file);
            $count++;
        }
        echo "Baseline recorded: {$count} migration(s) marked as applied without executing.\n";
        echo "Future migration files will be applied normally by php run_migrations.php\n";
        return 0;
    }

    if ($pending === []) {
        echo "Nothing to do: all " . count($all) . " migrations are recorded as applied.\n";
        return 0;
    }

    echo 'Applying ' . count($pending) . " pending migration(s)" . ($tolerant ? ' (tolerant mode)' : '') . ":\n";
    foreach ($pending as $file) {
        try {
            $result = frs_apply_migration_file($pdo, $file, $tolerant);
            $note = $result['skipped_statements'] > 0
                ? " ({$result['skipped_statements']} already-applied statement(s) skipped)"
                : '';
            echo "  OK    {$file}{$note}\n";
        } catch (RuntimeException $e) {
            echo "  FAIL  " . $e->getMessage() . "\n";
            echo "\nStopped. Fix the migration (or use --mark-applied={$file} if it was already applied by hand), then re-run.\n";
            return 1;
        }
    }

    echo "\nAll pending migrations applied.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(frs_migrations_main($argv));
}
