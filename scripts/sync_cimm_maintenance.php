<?php
declare(strict_types=1);

/**
 * Sync CIMM maintenance to local facilities status/blackout dates.
 *
 * Usage (cron example every 15 minutes):
 *   php /path/to/scripts/sync_cimm_maintenance.php
 *
 * Manual (Admin/Staff session via browser POST) also supported.
 */

if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/cimm_api.php';

$isCli = (PHP_SAPI === 'cli');

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

function outputAndExit(array $payload, int $exitCode = 0): void
{
    $isCliMode = (PHP_SAPI === 'cli');
    if ($isCliMode) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        if (!$payload['success'] && $exitCode !== 0) {
            http_response_code($exitCode >= 400 ? $exitCode : 500);
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    exit($exitCode === 0 ? 0 : 1);
}

try {
    $pdo = db();
    $result = frs_cimm_run_sync($pdo);

    if (!$result['success']) {
        outputAndExit([
            'success' => false,
            'message' => 'Failed to fetch CIMM schedules.',
            'error' => $result['error'] ?? 'Unknown error',
            'fetched' => 0,
        ], 1);
    }

    outputAndExit([
        'success' => true,
        'message' => 'CIMM maintenance sync completed.',
        'fetched' => $result['fetched'],
        'mapped' => $result['mapped'],
        'summary' => $result['summary'],
        'ran_at' => $result['ran_at'],
    ], 0);
} catch (Throwable $e) {
    outputAndExit([
        'success' => false,
        'message' => 'CIMM maintenance sync crashed.',
        'error' => $e->getMessage(),
    ], 1);
}
