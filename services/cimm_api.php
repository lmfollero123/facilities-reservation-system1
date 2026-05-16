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

        $mappedSchedules[] = [
            'id' => $stableId,
            'source' => $normalizedSource,
            'sched_id' => $schedId ?? '',
            'rep_id' => $repId ?? '',
            'facility_name' => $row['location'] ?? '',
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
            'location' => $row['location'] ?? '',
            'schedule_date' => date('Y-m-d', strtotime($row['starting_date'] ?? 'now'))
        ];
    }
    
    return $mappedSchedules;
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

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = $facilityId;
        }
    }

    return $bestScore >= 75 ? $bestId : null;
}

/**
 * Sync facility maintenance status and blackout dates from mapped CIMM schedules.
 *
 * Rules:
 * - Facility goes `maintenance` when there is an active CIMM maintenance now
 *   (`in_progress`/`delayed`, and current time within schedule window).
 * - Facility returns to `available` only if it is CIMM-managed and has no active
 *   CIMM maintenance now.
 * - Future maintenance windows are synced into `facility_blackout_dates`.
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

    $managedFacilityIds = [];
    $activeMaintenanceFacilityIds = [];
    $matchedSchedulesForBlackout = [];
    $now = time();

    foreach ($mappedSchedules as $schedule) {
        $rawLocation = (string)($schedule['facility_name'] ?? $schedule['location'] ?? '');
        $facilityId = cimmMatchFacilityId($rawLocation, $facilities);
        if (!$facilityId) {
            $summary['unmatched_schedule_count']++;
            continue;
        }

        $summary['matched_schedule_count']++;
        $managedFacilityIds[$facilityId] = true;
        $matchedSchedulesForBlackout[] = ['facility_id' => $facilityId, 'schedule' => $schedule];

        $status = strtolower((string)($schedule['status'] ?? 'scheduled'));
        $isActiveStatus = in_array($status, ['in_progress', 'delayed'], true);

        $startTs = strtotime((string)($schedule['scheduled_start'] ?? ''));
        $endTs = strtotime((string)($schedule['scheduled_end'] ?? ''));
        $withinWindow = false;
        if ($startTs && $endTs) {
            $withinWindow = ($now >= $startTs && $now <= $endTs);
        } elseif ($startTs) {
            $withinWindow = ($now >= $startTs);
        }

        if ($isActiveStatus && $withinWindow) {
            $activeMaintenanceFacilityIds[$facilityId] = true;
        }
    }

    try {
        $pdo->beginTransaction();

        foreach ($facilityById as $facilityId => $facility) {
            $currentStatus = strtolower((string)($facility['status'] ?? 'available'));
            $isManaged = isset($managedFacilityIds[$facilityId]);
            $isActiveNow = isset($activeMaintenanceFacilityIds[$facilityId]);

            if ($isActiveNow && $currentStatus !== 'maintenance') {
                $upd = $pdo->prepare('UPDATE facilities SET status = "maintenance", updated_at = NOW() WHERE id = :id');
                $upd->execute(['id' => $facilityId]);
                $summary['updated_to_maintenance']++;
            } elseif ($isManaged && !$isActiveNow && $currentStatus === 'maintenance') {
                $upd = $pdo->prepare('UPDATE facilities SET status = "available", updated_at = NOW() WHERE id = :id');
                $upd->execute(['id' => $facilityId]);
                $summary['updated_to_available']++;
            }
        }

        // Sync blackout dates for non-completed schedules.
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

            $startTs = strtotime((string)($schedule['scheduled_start'] ?? ''));
            $endTs = strtotime((string)($schedule['scheduled_end'] ?? ''));
            if (!$startTs) {
                continue;
            }
            if (!$endTs || $endTs < $startTs) {
                $endTs = $startTs;
            }

            $startDate = date('Y-m-d', $startTs);
            $endDate = date('Y-m-d', $endTs);
            $reason = 'CIMM Sync: ' . ((string)($schedule['maintenance_type'] ?? 'Maintenance'));

            $periodStart = new DateTime($startDate);
            $periodEnd = new DateTime($endDate);
            $periodEnd->modify('+1 day');
            $period = new DatePeriod($periodStart, new DateInterval('P1D'), $periodEnd);

            foreach ($period as $date) {
                $dateStr = $date->format('Y-m-d');
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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $summary['errors'][] = $e->getMessage();
        error_log('CIMM facility sync error: ' . $e->getMessage());
    }

    return $summary;
}
