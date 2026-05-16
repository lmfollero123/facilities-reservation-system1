<?php
declare(strict_types=1);

/**
 * Sync CIMM maintenance to local facilities status/blackout dates.
 *
 * Usage:
 *   php scripts/sync_cimm_maintenance.php
 */

if (PHP_SAPI === 'cli') {
    // Provide sane defaults expected by web-oriented security/session helpers.
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
    header('Content-Type: application/json; charset=utf-8');
}

function outputAndExit(array $payload, int $exitCode = 0): void
{
    $isCliMode = (PHP_SAPI === 'cli');
    if ($isCliMode) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    exit($exitCode);
}

try {
    $pdo = db();
    $apiResult = fetchCIMMMaintenanceSchedules();
    $rawSchedules = $apiResult['data'] ?? [];
    $apiError = $apiResult['error'] ?? null;

    if ($apiError) {
        outputAndExit([
            'success' => false,
            'message' => 'Failed to fetch CIMM schedules.',
            'error' => $apiError,
            'fetched' => 0,
        ], 1);
    }

    $mappedSchedules = mapCIMMToCPRF($rawSchedules);
    $syncSummary = syncFacilitiesFromCIMM($pdo, $mappedSchedules);

    outputAndExit([
        'success' => true,
        'message' => 'CIMM maintenance sync completed.',
        'fetched' => count($rawSchedules),
        'mapped' => count($mappedSchedules),
        'summary' => $syncSummary,
        'ran_at' => date('c'),
    ], 0);
} catch (Throwable $e) {
    outputAndExit([
        'success' => false,
        'message' => 'CIMM maintenance sync crashed.',
        'error' => $e->getMessage(),
    ], 1);
}

