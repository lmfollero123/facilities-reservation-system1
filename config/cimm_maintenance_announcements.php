<?php
/**
 * Auto-publish public announcements when CIMM schedules upcoming facility maintenance.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/blackout_dates.php';
require_once __DIR__ . '/gemini_maintenance_announcements.php';

if (!function_exists('cimmResolveScheduleFacilityId')) {
    require_once __DIR__ . '/../services/cimm_api.php';
}

function frs_cimm_maintenance_announcements_state_path(): string
{
    $root = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__);
    return $root . '/storage/cimm_maintenance_announcements.json';
}

/**
 * @return array<string, array{notification_id: int, facility_id: int, created_at: string}>
 */
function frs_cimm_load_maintenance_announcement_state(): array
{
    $path = frs_cimm_maintenance_announcements_state_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $cimmId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $cimmId = trim((string)$cimmId);
        $notificationId = (int)($row['notification_id'] ?? 0);
        if ($cimmId === '' || $notificationId <= 0) {
            continue;
        }
        $out[$cimmId] = [
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
function frs_cimm_save_maintenance_announcement_state(array $state): void
{
    $path = frs_cimm_maintenance_announcements_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function frs_cimm_auto_announcements_enabled(): bool
{
    $flag = function_exists('env_value')
        ? strtolower(trim((string)env_value('CIMM_AUTO_ANNOUNCEMENTS', '')))
        : strtolower(trim((string)(getenv('CIMM_AUTO_ANNOUNCEMENTS') ?: '')));

    if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    // Default: on when Gemini is configured (user opted in by setting the API key).
    return defined('GEMINI_API_KEY')
        && GEMINI_API_KEY !== ''
        && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';
}

function frs_notifications_supports_image_path(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'image_path'");
        $cached = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function frs_facility_announcement_image_path(?string $imagePath): ?string
{
    if ($imagePath === null || trim($imagePath) === '') {
        return null;
    }
    $path = trim($imagePath);
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    return $path;
}

/**
 * @param array<string, mixed> $schedule
 */
function frs_cimm_schedule_is_announceable(array $schedule, ?int $now = null): bool
{
    $now = $now ?? time();
    $status = cimmNormalizeMaintenanceStatus((string)($schedule['status'] ?? ''));
    if (in_array($status, ['completed', 'cancelled'], true)) {
        return false;
    }

    $endTs = strtotime((string)($schedule['scheduled_end'] ?? ''));
    $startTs = strtotime((string)($schedule['scheduled_start'] ?? ''));
    if (!$startTs) {
        return false;
    }

    $today = strtotime(date('Y-m-d', $now));
    $endDay = $endTs ? strtotime(date('Y-m-d', $endTs)) : strtotime(date('Y-m-d', $startTs));

    // Announce while maintenance is upcoming or currently in progress (not after it ended).
    return $today <= $endDay;
}

/**
 * @param array<int,array<string,mixed>> $mappedSchedules
 * @return array{created: int, skipped: int, errors: list<string>, created_titles: list<string>}
 */
function frs_sync_cimm_maintenance_announcements(PDO $pdo, array $mappedSchedules): array
{
    $result = [
        'created' => 0,
        'skipped' => 0,
        'errors' => [],
        'created_titles' => [],
    ];

    if (!frs_cimm_auto_announcements_enabled()) {
        return $result;
    }

    $facilitiesStmt = $pdo->query('SELECT id, name, location, image_path FROM facilities WHERE status != "deleted"');
    $facilities = $facilitiesStmt ? $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (empty($facilities)) {
        return $result;
    }

    $facilityById = [];
    foreach ($facilities as $facility) {
        $facilityById[(int)$facility['id']] = $facility;
    }

    $state = frs_cimm_load_maintenance_announcement_state();
    $supportsImage = frs_notifications_supports_image_path($pdo);
    $base = function_exists('base_path') ? base_path() : '';
    $now = time();

    foreach ($mappedSchedules as $schedule) {
        $cimmId = trim((string)($schedule['id'] ?? ''));
        if ($cimmId === '') {
            $result['skipped']++;
            continue;
        }

        if (isset($state[$cimmId])) {
            $result['skipped']++;
            continue;
        }

        if (!frs_cimm_schedule_is_announceable($schedule, $now)) {
            $result['skipped']++;
            continue;
        }

        $facilityId = cimmResolveScheduleFacilityId($schedule, $facilities);
        if (!$facilityId || !isset($facilityById[$facilityId])) {
            $result['skipped']++;
            continue;
        }

        $facility = $facilityById[$facilityId];
        $startDate = (string)($schedule['scheduled_start'] ?? '');
        $endDate = (string)($schedule['scheduled_end'] ?? '');
        $window = [
            'start_date' => $startDate !== '' ? date('Y-m-d', strtotime($startDate)) : '',
            'end_date' => $endDate !== '' ? date('Y-m-d', strtotime($endDate)) : '',
        ];
        $windowLabel = frs_format_cimm_maintenance_window([
            'start_date' => $window['start_date'],
            'end_date' => $window['end_date'] ?: $window['start_date'],
        ]);

        $context = [
            'facility_name' => (string)($facility['name'] ?? $schedule['facility_name'] ?? 'Facility'),
            'location' => (string)($facility['location'] ?? $schedule['location'] ?? ''),
            'maintenance_type' => (string)($schedule['maintenance_type'] ?? 'Maintenance'),
            'description' => (string)($schedule['description'] ?? $schedule['category'] ?? ''),
            'status_label' => (string)($schedule['status_label'] ?? ucfirst(str_replace('_', ' ', (string)($schedule['status'] ?? 'scheduled')))),
            'duration' => (string)($schedule['estimated_duration'] ?? ''),
            'start_label' => $window['start_date'] !== '' ? date('F j, Y', strtotime($window['start_date'])) : '',
            'end_label' => ($window['end_date'] !== '' && $window['end_date'] !== $window['start_date'])
                ? date('F j, Y', strtotime($window['end_date']))
                : '',
            'window_label' => $windowLabel,
        ];

        $copy = geminiGenerateMaintenanceAnnouncementText($context);
        if ($copy === null) {
            $copy = frs_fallback_maintenance_announcement_text($context);
        }

        $title = trim($copy['title']);
        $message = trim($copy['message']);
        if ($title === '' || $message === '') {
            $result['errors'][] = "Empty announcement copy for {$cimmId}";
            continue;
        }

        $imagePath = frs_facility_announcement_image_path($facility['image_path'] ?? null);
        $link = '/facility-details?id=' . $facilityId;

        try {
            if ($supportsImage) {
                $stmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, type, title, message, link, image_path, created_at)
                     VALUES (NULL, :type, :title, :message, :link, :image_path, NOW())'
                );
                $stmt->execute([
                    'type' => 'system',
                    'title' => $title,
                    'message' => $message,
                    'link' => $link,
                    'image_path' => $imagePath,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, type, title, message, link, created_at)
                     VALUES (NULL, :type, :title, :message, :link, NOW())'
                );
                $stmt->execute([
                    'type' => 'system',
                    'title' => $title,
                    'message' => $message,
                    'link' => $link,
                ]);
            }

            $notificationId = (int)$pdo->lastInsertId();
            if ($notificationId <= 0) {
                $result['errors'][] = "Failed to save announcement for {$cimmId}";
                continue;
            }

            $state[$cimmId] = [
                'notification_id' => $notificationId,
                'facility_id' => $facilityId,
                'created_at' => date('c'),
            ];
            frs_cimm_save_maintenance_announcement_state($state);

            $result['created']++;
            $result['created_titles'][] = $title;

            if (function_exists('logAudit')) {
                require_once __DIR__ . '/audit.php';
                logAudit(
                    'Auto-created CIMM maintenance announcement',
                    'Announcements',
                    "CIMM {$cimmId} → notification #{$notificationId}: {$title}"
                );
            }
        } catch (Throwable $e) {
            $result['errors'][] = "{$cimmId}: " . $e->getMessage();
            error_log('CIMM maintenance announcement failed: ' . $e->getMessage());
        }
    }

    return $result;
}
