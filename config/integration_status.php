<?php
/**
 * Integration health summaries for System Settings (read-only; sync is explicit).
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * @return array<string, mixed>
 */
function frs_integration_cimm_status(): array
{
    require_once dirname(__DIR__) . '/services/cimm_api.php';

    $state = frs_cimm_load_sync_state();
    $summary = is_array($state['last_summary'] ?? null) ? $state['last_summary'] : [];
    $lastSync = $state['last_sync_at'] ?? null;
    $hasSynced = $lastSync !== null && $lastSync !== '';

    return [
        'slug' => 'cimm',
        'name' => 'CIMM Maintenance',
        'description' => 'Pulls maintenance schedules from CIMM and syncs facility status and blackout dates.',
        'connected' => $hasSynced && empty($summary['errors']),
        'status_label' => $hasSynced ? (empty($summary['errors']) ? 'Connected' : 'Sync warnings') : 'Not synced yet',
        'status_class' => $hasSynced && empty($summary['errors']) ? 'active' : ($hasSynced ? 'maintenance' : 'offline'),
        'last_sync' => $lastSync,
        'preview' => false,
        'can_sync' => true,
        'sync_type' => 'ajax',
        'sync_url' => function_exists('base_path') ? base_path() . '/public/api/sync-cimm-maintenance.php' : '/public/api/sync-cimm-maintenance.php',
        'manage_url' => function_exists('base_path') ? base_path() . '/dashboard/maintenance-integration' : '/dashboard/maintenance-integration',
        'metrics' => [
            'Updated to maintenance' => (int)($summary['updated_to_maintenance'] ?? 0),
            'Updated to available' => (int)($summary['updated_to_available'] ?? 0),
            'Blackouts added' => (int)($summary['blackouts_added'] ?? 0),
            'Matched schedules' => (int)($summary['matched_schedule_count'] ?? 0),
            'Unmatched schedules' => (int)($summary['unmatched_schedule_count'] ?? 0),
        ],
        'errors' => (array)($summary['errors'] ?? []),
        'cron_hint' => 'php scripts/sync_cimm_maintenance.php',
    ];
}

/**
 * @return array<string, mixed>
 */
function frs_integration_infrastructure_status(): array
{
    return [
        'slug' => 'infrastructure',
        'name' => 'Infrastructure Projects',
        'description' => 'Preview module for external project timelines that may affect facility capacity.',
        'connected' => false,
        'status_label' => 'Preview — Not Connected',
        'status_class' => 'offline',
        'last_sync' => null,
        'preview' => true,
        'can_sync' => false,
        'sync_type' => 'none',
        'manage_url' => function_exists('base_path') ? base_path() . '/dashboard/infrastructure-projects' : '/dashboard/infrastructure-projects',
        'metrics' => ['Mode' => 'Sample data only'],
        'errors' => [],
        'cron_hint' => null,
    ];
}

/**
 * @return array<string, mixed>
 */
function frs_integration_uman_status(PDO $pdo): array
{
    require_once dirname(__DIR__) . '/services/uman_api.php';

    $connected = uman_api_key() !== '';
    $assetsResult = $connected ? fetchUMANAssets(false) : ['data' => [], 'error' => 'API key not configured'];
    $apiError = $assetsResult['error'] ?? null;
    $assetCount = is_array($assetsResult['data'] ?? null) ? count($assetsResult['data']) : 0;
    $live = $connected && $apiError === null;

    return [
        'slug' => 'uman',
        'name' => 'UMAN Utilities',
        'description' => 'Request utility assets and equipment from UMAN; assign approved items in Facility Management.',
        'connected' => $live,
        'status_label' => $live ? 'Connected' : ($connected ? 'Reachable with errors' : 'Not configured'),
        'status_class' => $live ? 'active' : 'offline',
        'last_sync' => $live ? date('c') : null,
        'preview' => !$connected,
        'can_sync' => $connected && frs_uman_tables_exist($pdo),
        'sync_type' => 'form',
        'sync_action' => 'sync_uman_requests',
        'manage_url' => function_exists('base_path') ? base_path() . '/dashboard/utilities-integration' : '/dashboard/utilities-integration',
        'metrics' => [
            'Assets in catalog' => $assetCount,
            'API base' => uman_api_base_url(),
        ],
        'errors' => $apiError ? [(string)$apiError] : [],
        'cron_hint' => null,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function frs_integration_status_all(PDO $pdo): array
{
    return [
        frs_integration_cimm_status(),
        frs_integration_infrastructure_status(),
        frs_integration_uman_status($pdo),
    ];
}

/**
 * Stream CSV export for maintenance insights.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function frs_export_maintenance_insights_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="maintenance-insights-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fputcsv($out, [
        'Facility',
        'Location',
        'Status',
        'Risk Band',
        'Risk Score',
        '90d Bookings',
        '30d Bookings',
        'Suggested Window',
        'Suggested Date',
        'Priority',
        'Pending Request',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['facility_name'] ?? ''),
            (string)($row['location'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['risk_band'] ?? ''),
            (int)($row['risk_score'] ?? 0),
            (int)($row['usage_90d'] ?? 0),
            (int)($row['usage_30d'] ?? 0),
            (string)($row['recommended_window_label'] ?? ''),
            (string)($row['recommended_date'] ?? ''),
            (string)($row['priority'] ?? ''),
            !empty($row['has_pending_request']) ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
}
