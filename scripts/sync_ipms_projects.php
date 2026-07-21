<?php
declare(strict_types=1);

/**
 * Sync IPMS infrastructure projects to local facility status/blackout dates.
 *
 * IPMS is a pull/poll integration: this script is the poller. It only ever issues a GET
 * against IPMS's facility-status-feed endpoint (see services/ipms_api.php) — nothing here
 * pushes data to IPMS.
 *
 * Usage (cron example every 15-30 minutes):
 *   php /path/to/scripts/sync_ipms_projects.php
 *
 * Manual (Admin/Staff session via browser POST) also supported via
 * public/api/sync-ipms-projects.php.
 */

if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ipms_api.php';

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

function ipmsOutputAndExit(array $payload, int $exitCode = 0): void
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
    $result = frs_ipms_run_sync($pdo);

    if (!$result['success']) {
        ipmsOutputAndExit([
            'success' => false,
            'message' => 'Failed to fetch IPMS facility-status feed.',
            'error' => $result['error'] ?? 'Unknown error',
        ], 1);
    }

    ipmsOutputAndExit([
        'success' => true,
        'message' => 'IPMS project sync completed.',
        'active_count' => $result['active_count'],
        'upcoming_count' => $result['upcoming_count'],
        'matched' => $result['matched'],
        'summary' => $result['summary'],
        'ran_at' => $result['ran_at'],
    ], 0);
} catch (Throwable $e) {
    ipmsOutputAndExit([
        'success' => false,
        'message' => 'IPMS project sync crashed.',
        'error' => $e->getMessage(),
    ], 1);
}
