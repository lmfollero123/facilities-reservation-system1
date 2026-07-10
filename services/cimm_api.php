<?php
/**
 * CIMM API Integration Helper
 * Fetches maintenance schedules from the Community Infrastructure Maintenance Management system
 */

/**
 * Fetches maintenance schedules from CIMM API
 * 
 * @return array Array with 'data' (schedules) and 'error' (error message if any)
 */
function fetchCIMMMaintenanceSchedules(): array {
    // API Configuration
    $apiUrl = 'https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php';
    $apiKey = trim((string)(function_exists('env_value') ? env_value('CIMM_API_KEY', '') : (getenv('CIMM_API_KEY') ?: '')));
    if ($apiKey === '') {
        return ['data' => [], 'error' => 'CIMM API key is not configured (set CIMM_API_KEY in .env).'];
    }
    
    $url = $apiUrl . '?key=' . urlencode($apiKey);
    
    // Initialize cURL
    $ch = curl_init($url);
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: CPRF-Facilities-Reservation/1.0'
        ]
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    // Handle errors
    if ($response === false || !empty($curlError)) {
        $errorMsg = 'Connection failed: ' . ($curlError ?: 'Unable to reach CIMM API');
        error_log('CIMM API Error: ' . $errorMsg);
        return ['data' => [], 'error' => $errorMsg];
    }
    
    if ($httpCode === 0) {
        $errorMsg = 'Connection timeout: CIMM API endpoint may not be accessible';
        error_log('CIMM API Error: ' . $errorMsg);
        return ['data' => [], 'error' => $errorMsg];
    }
    
    if ($httpCode === 404) {
        $errorMsg = 'API endpoint not found: CIMM needs to create /api/maintenance-schedules.php';
        error_log('CIMM API Error: ' . $errorMsg);
        return ['data' => [], 'error' => $errorMsg];
    }
    
    if ($httpCode === 401) {
        $errorMsg = 'Unauthorized: API key may be incorrect or missing';
        error_log('CIMM API Error: ' . $errorMsg);
        return ['data' => [], 'error' => $errorMsg];
    }
    
    if ($httpCode !== 200) {
        $errorMsg = 'HTTP Error ' . $httpCode . ': ' . substr($response, 0, 200);
        error_log('CIMM API HTTP Error: ' . $httpCode);
        return ['data' => [], 'error' => $errorMsg];
    }
    
    // Decode JSON response
    $json = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $errorMsg = 'Invalid JSON response: ' . json_last_error_msg();
        error_log('CIMM API JSON Error: ' . $errorMsg);
        return ['data' => [], 'error' => $errorMsg];
    }
    
    // Check if response has error field
    if (isset($json['error'])) {
        return ['data' => [], 'error' => $json['error']];
    }
    
    // Return data array
    return ['data' => $json['data'] ?? [], 'error' => null];
}

/**
 * Maps CIMM data format to CPRF format
 * 
 * @param array $rawSchedules Raw schedules from CIMM API
 * @return array Mapped schedules in CPRF format
 */
function mapCIMMToCPRF(array $rawSchedules): array {
    $mappedSchedules = [];
    
    foreach ($rawSchedules as $row) {
        $start = strtotime($row['starting_date'] ?? '');
        $end = strtotime($row['estimated_completion_date'] ?? '');
        
        // Calculate duration
        $duration = '';
        if ($start && $end) {
            $hours = round(($end - $start) / 3600);
            if ($hours < 24) {
                $duration = $hours . ' hours';
            } else {
                $days = round($hours / 24);
                $duration = $days . ' day' . ($days > 1 ? 's' : '');
            }
        }
        
        // Map status
        $status = strtolower(str_replace(' ', '_', $row['status'] ?? 'scheduled'));
        
        // Map priority
        $priority = strtolower($row['priority'] ?? 'low');
        
        $source = strtolower((string)($row['source'] ?? 'schedule'));
        $schedId = $row['sched_id'] ?? null;
        $repId = $row['rep_id'] ?? null;
        if ($source === 'report' || (empty($schedId) && !empty($repId))) {
            $normalizedSource = 'report';
            $stableId = 'CIMM-R-' . (string)$repId;
        } else {
            $normalizedSource = 'schedule';
            $stableId = 'CIMM-S-' . (string)$schedId;
        }

        $location = trim((string)($row['location'] ?? ''));
        $matchedFacilityName = trim((string)($row['facility_name'] ?? ''));
        $cprfFacilityId = isset($row['facility_id']) ? (int)$row['facility_id'] : 0;

        $mappedSchedules[] = [
            'id' => $stableId,
            'source' => $normalizedSource,
            'sched_id' => $schedId ?? '',
            'rep_id' => $repId ?? '',
            'facility_name' => $matchedFacilityName !== '' ? $matchedFacilityName : $location,
            'matched_facility_name' => $matchedFacilityName,
            'cprf_facility_id' => $cprfFacilityId > 0 ? $cprfFacilityId : null,
            'maintenance_type' => $row['task'] ?? '',
            'scheduled_start' => $row['starting_date'] ?? '',
            'scheduled_end' => $row['estimated_completion_date'] ?? '',
            'status' => $status,
            'status_label' => $row['status'] ?? 'Scheduled',
            'priority' => $priority,
            'description' => $row['category'] ?? '',
            'category' => $row['category'] ?? 'General Maintenance',
            'assigned_team' => $row['assigned_team'] ?? '',
            'estimated_duration' => $duration,
            'affected_reservations' => 0, // Will be calculated separately
            'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
            // Additional fields for calendar compatibility
            'task' => $row['task'] ?? '',
            'location' => $location,
            'schedule_date' => date('Y-m-d', strtotime($row['starting_date'] ?? 'now'))
        ];
    }
    
    return $mappedSchedules;
}

/**
 * Normalize CIMM maintenance status labels to snake_case tokens.
 */
function cimmNormalizeMaintenanceStatus(string $status): string
{
    return strtolower(str_replace([' ', '-'], '_', trim($status)));
}

/**
 * Whether today's date falls inside a schedule's start/end day range.
 */
function cimmScheduleWithinDateWindow(array $schedule, ?int $now = null): bool
{
    $now = $now ?? time();
    $startTs = strtotime((string)($schedule['scheduled_start'] ?? ''));
    $endTs = strtotime((string)($schedule['scheduled_end'] ?? ''));
    if (!$startTs) {
        return false;
    }

    $startDay = strtotime(date('Y-m-d', $startTs));
    $endDay = $endTs ? strtotime(date('Y-m-d', $endTs)) : $startDay;
    $today = strtotime(date('Y-m-d', $now));

    return $today >= $startDay && $today <= $endDay;
}

/**
 * True when CIMM indicates the facility should be treated as under maintenance now.
 */
function cimmIsActiveMaintenanceSchedule(array $schedule, ?int $now = null): bool
{
    $now = $now ?? time();
    $status = cimmNormalizeMaintenanceStatus((string)($schedule['status'] ?? ''));

    if (in_array($status, ['completed', 'cancelled'], true)) {
        return false;
    }

    $inWindow = cimmScheduleWithinDateWindow($schedule, $now);
    $endTs = strtotime((string)($schedule['scheduled_end'] ?? ''));

    if (in_array($status, ['in_progress', 'delayed', 'under_maintenance'], true)) {
        if (!$endTs) {
            return true;
        }
        return $inWindow || $now <= $endTs;
    }

    if ($status === 'scheduled' && $inWindow) {
        return true;
    }

    return false;
}

/**
 * Resolve a mapped CIMM schedule to a local facilities.id.
 *
 * @param array<int,array<string,mixed>> $facilities
 */
function cimmResolveScheduleFacilityId(array $schedule, array $facilities): ?int
{
    $cprfFacilityId = (int)($schedule['cprf_facility_id'] ?? 0);
    if ($cprfFacilityId > 0) {
        foreach ($facilities as $facility) {
            if ((int)($facility['id'] ?? 0) === $cprfFacilityId) {
                return $cprfFacilityId;
            }
        }
    }

    $candidates = array_filter([
        trim((string)($schedule['matched_facility_name'] ?? '')),
        trim((string)($schedule['facility_name'] ?? '')),
        trim((string)($schedule['location'] ?? '')),
    ]);

    foreach ($candidates as $candidate) {
        $facilityId = cimmMatchFacilityId($candidate, $facilities);
        if ($facilityId) {
            return $facilityId;
        }
    }

    return null;
}

/**
 * Normalize text for loose facility matching.
 */
function cimmNormalizeText(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)$value);
}

/**
 * Compute token overlap similarity (0.0 to 1.0).
 */
function cimmTokenSimilarity(string $left, string $right): float
{
    $leftNorm = cimmNormalizeText($left);
    $rightNorm = cimmNormalizeText($right);
    if ($leftNorm === '' || $rightNorm === '') {
        return 0.0;
    }

    $leftTokens = array_values(array_unique(array_filter(explode(' ', $leftNorm))));
    $rightTokens = array_values(array_unique(array_filter(explode(' ', $rightNorm))));
    if (empty($leftTokens) || empty($rightTokens)) {
        return 0.0;
    }

    $intersection = array_intersect($leftTokens, $rightTokens);
    $union = array_unique(array_merge($leftTokens, $rightTokens));
    if (empty($union)) {
        return 0.0;
    }

    return count($intersection) / count($union);
}

/**
 * Try to match a CIMM location text to a local facility.
 *
 * @param array<int,array<string,mixed>> $facilities
 * @return int|null
 */
function cimmMatchFacilityId(string $cimmLocation, array $facilities): ?int
{
    $needle = cimmNormalizeText($cimmLocation);
    if ($needle === '') {
        return null;
    }

    $bestId = null;
    $bestScore = 0;

    foreach ($facilities as $facility) {
        $facilityId = (int)($facility['id'] ?? 0);
        if ($facilityId <= 0) {
            continue;
        }

        $name = cimmNormalizeText((string)($facility['name'] ?? ''));
        $location = cimmNormalizeText((string)($facility['location'] ?? ''));

        if ($name === '' && $location === '') {
            continue;
        }

        $score = 0;
        if ($needle === $name || $needle === $location) {
            $score = 100;
        } elseif ($name !== '' && (strpos($needle, $name) !== false || strpos($name, $needle) !== false)) {
            $score = max($score, 80);
        } elseif ($location !== '' && (strpos($needle, $location) !== false || strpos($location, $needle) !== false)) {
            $score = max($score, 75);
        }

        // Fuzzy fallback using token overlap, useful for minor naming variations
        // like "Pael Site" vs "Pael Burial Site".
        $nameSimilarity = cimmTokenSimilarity($needle, $name);
        $locationSimilarity = cimmTokenSimilarity($needle, $location);
        $maxSimilarity = max($nameSimilarity, $locationSimilarity);
        if ($maxSimilarity >= 0.66) {
            $score = max($score, 70);
        } elseif ($maxSimilarity >= 0.5) {
            $score = max($score, 60);
        }

        // Shared primary identifier (e.g. "Cassanova" in both names).
        $needlePrimary = explode(' ', $needle)[0] ?? '';
        $namePrimary = explode(' ', $name)[0] ?? '';
        if ($needlePrimary !== '' && strlen($needlePrimary) >= 5 && $needlePrimary === $namePrimary) {
            $score = max($score, 85);
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = $facilityId;
        }
    }

    return $bestScore >= 70 ? $bestId : null;
}

/**
 * Path to persisted CIMM sync metadata (last run time, summary).
 */
function frs_cimm_sync_state_path(): string
{
    $root = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__);
    return $root . '/storage/cimm_sync_state.json';
}

/**
 * Path to facility IDs that CIMM sync set to maintenance (not manual staff changes).
 */
function frs_cimm_managed_maintenance_path(): string
{
    $root = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__);
    return $root . '/storage/cimm_managed_maintenance.json';
}

/**
 * @return array{last_sync_at: ?string, last_summary: array<string,mixed>}
 */
function frs_cimm_load_sync_state(): array
{
    $path = frs_cimm_sync_state_path();
    if (!is_file($path)) {
        return ['last_sync_at' => null, 'last_summary' => []];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return ['last_sync_at' => null, 'last_summary' => []];
    }
    return [
        'last_sync_at' => isset($data['last_sync_at']) ? (string)$data['last_sync_at'] : null,
        'last_summary' => is_array($data['last_summary'] ?? null) ? $data['last_summary'] : [],
    ];
}

function frs_cimm_save_sync_state(array $summary): void
{
    $path = frs_cimm_sync_state_path();
    $payload = [
        'last_sync_at' => date('c'),
        'last_summary' => $summary,
    ];
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * @return array<int, true>
 */
function frs_cimm_load_managed_maintenance_ids(): array
{
    $path = frs_cimm_managed_maintenance_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    return $out;
}

/**
 * @param array<int, true> $ids
 */
function frs_cimm_save_managed_maintenance_ids(array $ids): void
{
    $path = frs_cimm_managed_maintenance_path();
    file_put_contents($path, json_encode(array_values(array_map('intval', array_keys($ids))), JSON_PRETTY_PRINT));
}

/**
 * Fetch CIMM schedules, sync facilities/blackouts, persist last-run metadata.
 *
 * @return array{success: bool, fetched: int, mapped: int, summary: array<string,mixed>, error: ?string, ran_at: string}
 */
function frs_cimm_run_sync(PDO $pdo): array
{
    $apiResult = fetchCIMMMaintenanceSchedules();
    $rawSchedules = $apiResult['data'] ?? [];
    $apiError = $apiResult['error'] ?? null;

    if ($apiError) {
        return [
            'success' => false,
            'fetched' => 0,
            'mapped' => 0,
            'summary' => [],
            'error' => (string)$apiError,
            'ran_at' => date('c'),
        ];
    }

    $mappedSchedules = mapCIMMToCPRF($rawSchedules);
    $syncSummary = syncFacilitiesFromCIMM($pdo, $mappedSchedules);
    frs_cimm_save_sync_state($syncSummary);

    return [
        'success' => true,
        'fetched' => count($rawSchedules),
        'mapped' => count($mappedSchedules),
        'summary' => $syncSummary,
        'error' => null,
        'ran_at' => date('c'),
    ];
}

/**
 * Expand a schedule window to Y-m-d date strings.
 *
 * @return string[]
 */
function cimmScheduleDateRange(array $schedule): array
{
    $startTs = strtotime((string)($schedule['scheduled_start'] ?? ''));
    $endTs = strtotime((string)($schedule['scheduled_end'] ?? ''));
    if (!$startTs) {
        return [];
    }
    if (!$endTs || $endTs < $startTs) {
        $endTs = $startTs;
    }

    $periodStart = new DateTime(date('Y-m-d', $startTs));
    $periodEnd = new DateTime(date('Y-m-d', $endTs));
    $periodEnd->modify('+1 day');
    $period = new DatePeriod($periodStart, new DateInterval('P1D'), $periodEnd);

    $dates = [];
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
    }
    return $dates;
}

/**
 * Sync facility maintenance status and blackout dates from mapped CIMM schedules.
 *
 * Rules:
 * - Facility goes `maintenance` when there is an active CIMM maintenance now
 *   (`in_progress`/`delayed`, and current time within schedule window).
 * - Facility returns to `available` only if CIMM sync previously set it to maintenance
 *   (storage/cimm_managed_maintenance.json) and CIMM has no active window now.
 * - Future maintenance windows sync into `facility_blackout_dates` (`CIMM Sync:` prefix).
 * - Completed/cancelled schedules remove their CIMM Sync blackouts.
 *
 * @param PDO $pdo
 * @param array<int,array<string,mixed>> $mappedSchedules
 * @return array<string,mixed>
 */
function syncFacilitiesFromCIMM(PDO $pdo, array $mappedSchedules): array
{
    $summary = [
        'updated_to_maintenance' => 0,
        'updated_to_available' => 0,
        'blackouts_added' => 0,
        'blackouts_removed' => 0,
        'matched_schedule_count' => 0,
        'unmatched_schedule_count' => 0,
        'errors' => [],
    ];

    $facilitiesStmt = $pdo->query('SELECT id, name, location, status FROM facilities');
    $facilities = $facilitiesStmt ? $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (empty($facilities)) {
        return $summary;
    }

    $facilityById = [];
    foreach ($facilities as $facility) {
        $facilityById[(int)$facility['id']] = $facility;
    }

    $activeMaintenanceFacilityIds = [];
    $matchedSchedulesForBlackout = [];
    $desiredBlackouts = [];
    $now = time();
    $cimmManagedMaintenance = frs_cimm_load_managed_maintenance_ids();
    $facilitiesWentToMaintenance = [];
    $facilitiesWentToAvailable = [];

    foreach ($mappedSchedules as $schedule) {
        $facilityId = cimmResolveScheduleFacilityId($schedule, $facilities);
        if (!$facilityId) {
            $summary['unmatched_schedule_count']++;
            continue;
        }

        $summary['matched_schedule_count']++;
        $matchedSchedulesForBlackout[] = ['facility_id' => $facilityId, 'schedule' => $schedule];

        if (cimmIsActiveMaintenanceSchedule($schedule, $now)) {
            $activeMaintenanceFacilityIds[$facilityId] = true;
        }
    }

    try {
        $pdo->beginTransaction();

        foreach ($facilityById as $facilityId => $facility) {
            $currentStatus = strtolower((string)($facility['status'] ?? 'available'));
            $isActiveNow = isset($activeMaintenanceFacilityIds[$facilityId]);
            $cimmManaged = isset($cimmManagedMaintenance[$facilityId]);
            $facilityName = (string)($facility['name'] ?? 'Facility');

            if ($isActiveNow && $currentStatus !== 'maintenance') {
                $upd = $pdo->prepare('UPDATE facilities SET status = "maintenance", updated_at = NOW() WHERE id = :id');
                $upd->execute(['id' => $facilityId]);
                $cimmManagedMaintenance[$facilityId] = true;
                $summary['updated_to_maintenance']++;
                $facilitiesWentToMaintenance[$facilityId] = $facilityName;
            } elseif (!$isActiveNow && $currentStatus === 'maintenance' && $cimmManaged) {
                $upd = $pdo->prepare('UPDATE facilities SET status = "available", updated_at = NOW() WHERE id = :id');
                $upd->execute(['id' => $facilityId]);
                unset($cimmManagedMaintenance[$facilityId]);
                $summary['updated_to_available']++;
                $facilitiesWentToAvailable[$facilityId] = $facilityName;
            }
        }

        frs_cimm_save_managed_maintenance_ids($cimmManagedMaintenance);

        // Build desired CIMM blackout dates from active (non-completed) schedules.
        foreach ($matchedSchedulesForBlackout as $row) {
            $facilityId = (int)$row['facility_id'];
            $schedule = $row['schedule'];
            $status = strtolower((string)($schedule['status'] ?? 'scheduled'));
            if (in_array($status, ['completed', 'cancelled'], true)) {
                continue;
            }
            if (!isset($desiredBlackouts[$facilityId])) {
                $desiredBlackouts[$facilityId] = [];
            }
            foreach (cimmScheduleDateRange($schedule) as $dateStr) {
                $desiredBlackouts[$facilityId][$dateStr] = true;
            }
        }

        // Remove stale CIMM Sync blackouts (completed schedules or no longer in feed).
        $existingStmt = $pdo->query(
            "SELECT id, facility_id, blackout_date FROM facility_blackout_dates WHERE reason LIKE 'CIMM Sync:%'"
        );
        $deleteStmt = $pdo->prepare('DELETE FROM facility_blackout_dates WHERE id = :id');
        if ($existingStmt) {
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $facilityId = (int)($row['facility_id'] ?? 0);
                $dateStr = (string)($row['blackout_date'] ?? '');
                if ($facilityId <= 0 || $dateStr === '') {
                    continue;
                }
                if (!isset($desiredBlackouts[$facilityId][$dateStr])) {
                    $deleteStmt->execute(['id' => (int)$row['id']]);
                    $summary['blackouts_removed']++;
                }
            }
        }

        $existsStmt = $pdo->prepare(
            'SELECT id FROM facility_blackout_dates WHERE facility_id = :facility_id AND blackout_date = :blackout_date LIMIT 1'
        );
        $insertStmt = $pdo->prepare(
            'INSERT INTO facility_blackout_dates (facility_id, blackout_date, reason, created_at) VALUES (:facility_id, :blackout_date, :reason, NOW())'
        );

        foreach ($matchedSchedulesForBlackout as $row) {
            $facilityId = (int)$row['facility_id'];
            $schedule = $row['schedule'];
            $status = strtolower((string)($schedule['status'] ?? 'scheduled'));
            if (in_array($status, ['completed', 'cancelled'], true)) {
                continue;
            }

            $reason = 'CIMM Sync: ' . ((string)($schedule['maintenance_type'] ?? 'Maintenance'));

            foreach (cimmScheduleDateRange($schedule) as $dateStr) {
                $existsStmt->execute([
                    'facility_id' => $facilityId,
                    'blackout_date' => $dateStr,
                ]);
                if ($existsStmt->fetch(PDO::FETCH_ASSOC)) {
                    continue;
                }
                $insertStmt->execute([
                    'facility_id' => $facilityId,
                    'blackout_date' => $dateStr,
                    'reason' => $reason,
                ]);
                $summary['blackouts_added']++;
            }
        }

        $pdo->commit();

        if (!empty($facilitiesWentToMaintenance) || !empty($facilitiesWentToAvailable)) {
            $maintenanceHelper = dirname(__DIR__) . '/config/maintenance_helper.php';
            if (is_file($maintenanceHelper)) {
                require_once $maintenanceHelper;
                foreach ($facilitiesWentToMaintenance as $facilityId => $facilityName) {
                    handleFacilityMaintenanceStatusChange((int)$facilityId, (string)$facilityName);
                }
                foreach ($facilitiesWentToAvailable as $facilityId => $facilityName) {
                    handleFacilityAvailableStatusChange((int)$facilityId, (string)$facilityName);
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $summary['errors'][] = $e->getMessage();
        error_log('CIMM facility sync error: ' . $e->getMessage());
    }

    return $summary;
}
