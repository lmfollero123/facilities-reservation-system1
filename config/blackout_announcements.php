<?php
/**
 * Auto-publish public announcements when CPRF staff create manual blackout dates.
 */
declare(strict_types=1);

require_once __DIR__ . '/public_facility_announcements.php';
require_once __DIR__ . '/blackout_dates.php';
require_once __DIR__ . '/gemini_maintenance_announcements.php';

function frs_cprf_auto_announcements_enabled(): bool
{
    return frs_env_flag_enabled('CPRF_AUTO_ANNOUNCEMENTS', true);
}

function frs_cprf_blackout_announcements_state_path(): string
{
    $root = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__);
    return $root . '/storage/cprf_blackout_announcements.json';
}

/**
 * @return array<string, array{notification_id: int, facility_id: int, created_at: string}>
 */
function frs_cprf_load_blackout_announcement_state(): array
{
    $path = frs_cprf_blackout_announcements_state_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string)$key);
        $notificationId = (int)($row['notification_id'] ?? 0);
        if ($key === '' || $notificationId <= 0) {
            continue;
        }
        $out[$key] = [
            'notification_id' => $notificationId,
            'facility_id' => (int)($row['facility_id'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @param array<string, array{notification_id: int, facility_id: int, created_at: string}> $state
 */
function frs_cprf_save_blackout_announcement_state(array $state): void
{
    $path = frs_cprf_blackout_announcements_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function frs_cprf_blackout_announcement_key(int $facilityId, string $startDate, string $endDate): string
{
    return 'CPRF-' . $facilityId . '-' . $startDate . '-' . $endDate;
}

/**
 * @return array{published: bool, notification_id: ?int, title: ?string, error: ?string}
 */
function frs_publish_cprf_blackout_announcement(
    PDO $pdo,
    int $facilityId,
    string $startDate,
    string $endDate,
    string $reason
): array {
    $empty = ['published' => false, 'notification_id' => null, 'title' => null, 'error' => null];

    if (!frs_cprf_auto_announcements_enabled()) {
        return $empty;
    }

    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return $empty;
    }

    if (!frs_blackout_reason_is_cprf_manual($reason)) {
        return $empty;
    }

    $endTs = strtotime($endDate);
    $today = strtotime(date('Y-m-d'));
    if (!$endTs || $today > strtotime(date('Y-m-d', $endTs))) {
        return $empty;
    }

    $stateKey = frs_cprf_blackout_announcement_key($facilityId, $startDate, $endDate);
    $state = frs_cprf_load_blackout_announcement_state();
    if (isset($state[$stateKey])) {
        return $empty;
    }

    $facilityStmt = $pdo->prepare(
        'SELECT id, name, location, image_path FROM facilities WHERE id = ? AND status != "deleted" LIMIT 1'
    );
    $facilityStmt->execute([$facilityId]);
    $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
    if (!$facility) {
        return ['published' => false, 'notification_id' => null, 'title' => null, 'error' => 'Facility not found'];
    }

    $windowLabel = frs_format_cimm_maintenance_window([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $context = [
        'facility_name' => (string)($facility['name'] ?? 'Facility'),
        'location' => (string)($facility['location'] ?? ''),
        'reason' => trim($reason) !== '' ? trim($reason) : 'Facility unavailable',
        'start_label' => date('F j, Y', strtotime($startDate)),
        'end_label' => ($endDate !== $startDate) ? date('F j, Y', strtotime($endDate)) : '',
        'window_label' => $windowLabel,
        'day_count' => max(1, (int)((strtotime($endDate) - strtotime($startDate)) / 86400) + 1),
    ];

    $copy = geminiGenerateBlackoutAnnouncementText($context);
    if ($copy === null) {
        $copy = frs_fallback_blackout_announcement_text($context);
    }

    $title = trim($copy['title']);
    $message = trim($copy['message']);
    if ($title === '' || $message === '') {
        return ['published' => false, 'notification_id' => null, 'title' => null, 'error' => 'Empty announcement copy'];
    }

    try {
        $notificationId = frs_insert_public_facility_announcement(
            $pdo,
            $title,
            $message,
            $facilityId,
            $facility['image_path'] ?? null
        );
        if ($notificationId === null) {
            return ['published' => false, 'notification_id' => null, 'title' => null, 'error' => 'Insert failed'];
        }

        $state[$stateKey] = [
            'notification_id' => $notificationId,
            'facility_id' => $facilityId,
            'created_at' => date('c'),
        ];
        frs_cprf_save_blackout_announcement_state($state);

        if (function_exists('logAudit')) {
            require_once __DIR__ . '/audit.php';
            logAudit(
                'Auto-created CPRF blackout announcement',
                'Announcements',
                "{$stateKey} → notification #{$notificationId}: {$title}"
            );
        }

        return [
            'published' => true,
            'notification_id' => $notificationId,
            'title' => $title,
            'error' => null,
        ];
    } catch (Throwable $e) {
        error_log('CPRF blackout announcement failed: ' . $e->getMessage());
        return ['published' => false, 'notification_id' => null, 'title' => null, 'error' => $e->getMessage()];
    }
}
