<?php
declare(strict_types=1);

/**
 * Sync CPRF <-> LGU Energy Efficiency integration.
 *
 * Push side: retries all pending/failed manual meter readings to the Energy
 * system's POST /api/v1/cprf/facility-readings. Pull side: fetches
 * engineer-approved recommendations into energy_recommendations_cache.
 *
 * Usage (cron example, hourly):
 *   php /path/to/scripts/sync_energy_integration.php
 *   php /path/to/scripts/sync_energy_integration.php --dry-run   # report only, no push/pull
 *   php /path/to/scripts/sync_energy_integration.php --verbose   # full error list
 *
 * Manual (Admin/Staff session via browser POST) also supported via
 * public/api/sync-energy.php.
 */

if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/energy_helper.php';

$isCli = (PHP_SAPI === 'cli');
$dryRun = $isCli && in_array('--dry-run', $argv ?? [], true);
$verbose = $isCli && in_array('--verbose', $argv ?? [], true);

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('Content-Type: application/json; charset=utf-8');
    $isStaff = ($_SESSION['user_authenticated'] ?? false)
        && in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);
    if (!$isStaff) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin or Staff login required.']);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Use POST for manual sync.']);
        exit;
    }
}

function energySyncOutputAndExit(array $payload, int $exitCode = 0): void
{
    if (PHP_SAPI === 'cli') {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        if (empty($payload['success']) && $exitCode !== 0) {
            http_response_code($exitCode >= 400 ? $exitCode : 500);
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    exit($exitCode === 0 ? 0 : 1);
}

try {
    if (!energy_api_enabled()) {
        energySyncOutputAndExit([
            'success' => false,
            'message' => 'Energy sync is disabled (ENERGY_SYNC_ENABLED=false).',
        ], 1);
    }

    $pdo = db();
    if (!frs_energy_tables_exist($pdo)) {
        energySyncOutputAndExit([
            'success' => false,
            'message' => 'Energy integration tables missing. Run database/migration_add_energy_integration.sql.',
        ], 1);
    }

    if ($dryRun) {
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM energy_meter_readings WHERE sync_status IN ('pending','failed')")->fetchColumn();
        energySyncOutputAndExit([
            'success' => true,
            'message' => 'Dry run: nothing pushed or pulled.',
            'would_push' => $pending,
            'configured' => energy_api_base_url() !== '' && energy_api_token() !== '',
        ], 0);
    }

    $summary = frs_energy_run_sync($pdo);

    energySyncOutputAndExit([
        'success' => $summary['success'],
        'message' => $summary['success'] ? 'Energy sync completed.' : 'Energy sync completed with errors.',
        'pushed' => $summary['pushed'],
        'push_failed' => $summary['push_failed'],
        'recommendations_upserted' => $summary['recommendations_upserted'],
        'errors' => $verbose ? $summary['errors'] : array_slice($summary['errors'], 0, 3),
        'ran_at' => $summary['ran_at'],
    ], $summary['success'] ? 0 : 1);
} catch (Throwable $e) {
    energySyncOutputAndExit([
        'success' => false,
        'message' => 'Energy sync crashed.',
        'error' => $e->getMessage(),
    ], 1);
}
