<?php
/**
 * IPMS (Infrastructure Project Management System) integration — READ ONLY.
 *
 * IPMS tracks infrastructure projects (road work, building renovations, etc.) affecting
 * Culiat facilities. This is a pull/poll integration: we call IPMS's endpoint on a schedule,
 * IPMS never calls into us (no webhook, nothing pushes data into this system), and this file
 * never issues anything but GET requests — there is no function here that creates, edits, or
 * deletes an IPMS project record. We don't even have write access to their data; this is
 * purely informational, used to avoid letting residents book a facility that IPMS says is
 * mid-renovation or under construction.
 *
 * Endpoint contract: GET {IPMS_API_URL} with header X-API-Key: {IPMS_API_KEY}.
 */

declare(strict_types=1);

/** Feed statuses (facilities_affected) that mean "treat this facility as unavailable now". */
const IPMS_BLOCKING_STATUSES = ['active', 'delayed', 'on_hold', 'completion_inspection'];

/** Feed statuses (upcoming_work) — approved but not started; heads-up only, never blocks booking. */
const IPMS_UPCOMING_STATUSES = ['approved', 'bidding', 'awarded', 'assigned'];

/** Minimum match score (0-100) required to auto-block a facility from a matched project. */
const IPMS_MATCH_THRESHOLD = 70;

/** Safety cap on how many days a single project window can black out, in case of bad IPMS data. */
const IPMS_MAX_BLACKOUT_DAYS = 1096; // ~3 years

function ipms_api_base_url(): string
{
    $default = 'https://ipms.infragovservices.com/integrations/facilities-reservation/facility-status-feed.php';
    $url = trim((string)(function_exists('env_value') ? env_value('IPMS_API_URL', '') : (getenv('IPMS_API_URL') ?: '')));
    return $url !== '' ? $url : $default;
}

/**
 * Accepts either IPMS_API_KEY or FACILITIES_RESERVATION_API_KEY (either name may be used
 * in .env — IPMS_API_KEY takes precedence if both are set).
 */
function ipms_api_key(): string
{
    $key = trim((string)(function_exists('env_value') ? env_value('IPMS_API_KEY', '') : (getenv('IPMS_API_KEY') ?: '')));
    if ($key !== '') {
        return $key;
    }
    return trim((string)(function_exists('env_value') ? env_value('FACILITIES_RESERVATION_API_KEY', '') : (getenv('FACILITIES_RESERVATION_API_KEY') ?: '')));
}

/**
 * Low-level GET against the IPMS facility-status-feed endpoint. Read-only: GET is the only
 * HTTP method this integration ever sends.
 *
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function ipms_api_get(bool $includeUpcoming = false): array
{
    $apiKey = ipms_api_key();
    if ($apiKey === '') {
        return [
            'success' => false,
            'data' => null,
            'error' => 'IPMS API key is not configured (set IPMS_API_KEY in .env).',
            'http_code' => 0,
        ];
    }

    $url = ipms_api_base_url();
    if ($includeUpcoming) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'include_upcoming=1';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-Key: ' . $apiKey,
            'User-Agent: CPRF-Facilities-Reservation/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        $msg = 'Connection failed: ' . ($curlError ?: 'Unable to reach IPMS API');
        error_log('IPMS API Error: ' . $msg);
        return ['success' => false, 'data' => null, 'error' => $msg, 'http_code' => $httpCode];
    }

    $json = json_decode((string)$response, true);

    if ($httpCode === 401) {
        $msg = is_array($json) ? (string)($json['message'] ?? 'Invalid or missing API key') : 'Invalid or missing API key';
        return ['success' => false, 'data' => null, 'error' => $msg, 'http_code' => $httpCode];
    }

    if ($httpCode === 405) {
        $msg = is_array($json) ? (string)($json['message'] ?? 'Method not allowed') : 'Method not allowed';
        return ['success' => false, 'data' => null, 'error' => $msg, 'http_code' => $httpCode];
    }

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'data' => null,
            'error' => 'HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200),
            'http_code' => $httpCode,
        ];
    }

    if (!is_array($json)) {
        return ['success' => false, 'data' => null, 'error' => 'Invalid JSON from IPMS', 'http_code' => $httpCode];
    }

    if (empty($json['success'])) {
        return [
            'success' => false,
            'data' => null,
            'error' => (string)($json['message'] ?? 'IPMS reported failure'),
            'http_code' => $httpCode,
        ];
    }

    return ['success' => true, 'data' => $json, 'error' => null, 'http_code' => $httpCode];
}

/**
 * Normalize one raw IPMS project row into a stable, typed shape.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function ipmsNormalizeProject(array $raw, string $bucket): array
{
    $progress = null;
    if (isset($raw['progress']) && is_numeric($raw['progress'])) {
        $progress = max(0, min(100, (int)$raw['progress']));
    }

    return [
        'project_id' => isset($raw['project_id']) ? (int)$raw['project_id'] : null,
        'project_code' => trim((string)($raw['project_code'] ?? '')),
        'name' => trim((string)($raw['name'] ?? '')),
        'category' => trim((string)($raw['category'] ?? '')),
        'location' => trim((string)($raw['location'] ?? '')),
        'status' => strtolower(trim((string)($raw['status'] ?? ''))),
        'progress' => $progress,
        'start_date' => trim((string)($raw['start_date'] ?? '')),
        'expected_completion' => trim((string)($raw['expected_completion'] ?? '')),
        'latitude' => isset($raw['latitude']) && is_numeric($raw['latitude']) ? (float)$raw['latitude'] : null,
        'longitude' => isset($raw['longitude']) && is_numeric($raw['longitude']) ? (float)$raw['longitude'] : null,
        // 'active' = facilities_affected bucket (blocking); 'upcoming' = upcoming_work bucket (heads-up only)
        'bucket' => $bucket,
    ];
}

/**
 * Fetch and typed-parse the IPMS facility-status feed.
 *
 * @return array{barangay: ?string, active: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, error: ?string}
 */
function fetchIPMSFacilityStatus(bool $includeUpcoming = false): array
{
    $result = ipms_api_get($includeUpcoming);
    if (!$result['success']) {
        return ['barangay' => null, 'active' => [], 'upcoming' => [], 'error' => $result['error']];
    }

    $json = (array)$result['data'];
    $rawActive = is_array($json['facilities_affected'] ?? null) ? $json['facilities_affected'] : [];
    $rawUpcoming = is_array($json['upcoming_work'] ?? null) ? $json['upcoming_work'] : [];

    $active = array_values(array_map(
        static fn($row) => ipmsNormalizeProject((array)$row, 'active'),
        $rawActive
    ));
    $upcoming = array_values(array_map(
        static fn($row) => ipmsNormalizeProject((array)$row, 'upcoming'),
        $rawUpcoming
    ));

    return [
        'barangay' => isset($json['barangay']) ? (string)$json['barangay'] : null,
        'active' => $active,
        'upcoming' => $upcoming,
        'error' => null,
    ];
}

function ipmsIsBlockingStatus(string $status): bool
{
    return in_array(strtolower(trim($status)), IPMS_BLOCKING_STATUSES, true);
}

/**
 * Normalize text for loose facility matching (no hardcoded facility aliases — IPMS location
 * strings are barangay-level, not facility-specific, so we don't have real data to calibrate
 * aliases against; unmatched projects are surfaced for manual review instead of guessed).
 */
function ipmsNormalizeText(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)$value);
}

/**
 * Token overlap similarity (Jaccard index, 0.0 to 1.0).
 */
function ipmsTokenSimilarity(string $left, string $right): float
{
    if ($left === '' || $right === '') {
        return 0.0;
    }
    $leftTokens = array_values(array_unique(array_filter(explode(' ', $left))));
    $rightTokens = array_values(array_unique(array_filter(explode(' ', $right))));
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
 * Score one candidate text against a single facility's name/location (0-100).
 */
function ipmsScoreCandidate(string $needle, string $facilityName, string $facilityLocation): int
{
    $needleNorm = ipmsNormalizeText($needle);
    if ($needleNorm === '') {
        return 0;
    }
    $nameNorm = ipmsNormalizeText($facilityName);
    $locationNorm = ipmsNormalizeText($facilityLocation);
    if ($nameNorm === '' && $locationNorm === '') {
        return 0;
    }

    $score = 0;
    if ($needleNorm === $nameNorm || $needleNorm === $locationNorm) {
        $score = 100;
    } elseif ($nameNorm !== '' && (str_contains($needleNorm, $nameNorm) || str_contains($nameNorm, $needleNorm))) {
        $score = max($score, 80);
    } elseif ($locationNorm !== '' && (str_contains($needleNorm, $locationNorm) || str_contains($locationNorm, $needleNorm))) {
        $score = max($score, 65);
    }

    $maxSimilarity = max(
        ipmsTokenSimilarity($needleNorm, $nameNorm),
        ipmsTokenSimilarity($needleNorm, $locationNorm)
    );
    if ($maxSimilarity >= 0.66) {
        $score = max($score, 75);
    } elseif ($maxSimilarity >= 0.5) {
        $score = max($score, 60);
    }

    return $score;
}

/**
 * Best-scoring facility for one candidate text across all facilities.
 *
 * @param array<int, array<string, mixed>> $facilities
 * @return array{facility_id: ?int, score: int}
 */
function ipmsBestFacilityForText(string $candidate, array $facilities): array
{
    $bestId = null;
    $bestScore = 0;
    foreach ($facilities as $facility) {
        $facilityId = (int)($facility['id'] ?? 0);
        if ($facilityId <= 0) {
            continue;
        }
        $score = ipmsScoreCandidate(
            $candidate,
            (string)($facility['name'] ?? ''),
            (string)($facility['location'] ?? '')
        );
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = $facilityId;
        }
    }
    return $bestId !== null && $bestScore >= IPMS_MATCH_THRESHOLD
        ? ['facility_id' => $bestId, 'score' => $bestScore]
        : ['facility_id' => null, 'score' => $bestScore];
}

/**
 * Try to match an IPMS project to a local facility.
 *
 * IPMS's "location" field is barangay-level free text (e.g. "Barangay Culiat, District 1,
 * Quezon City") and does not by itself identify a facility. The project "name" is far more
 * likely to carry a facility-identifying keyword (e.g. "Culiat Multi-Purpose Hall Renovation"),
 * so it's tried first; "location" is only consulted as a fallback if "name" doesn't clear the
 * confidence threshold. Anything below threshold returns facility_id = null — the caller must
 * treat that as "needs manual review", never guess.
 *
 * @param array<string, mixed> $project
 * @param array<int, array<string, mixed>> $facilities
 * @return array{facility_id: ?int, score: int}
 */
function ipmsMatchFacilityId(array $project, array $facilities): array
{
    $candidates = array_filter([
        trim((string)($project['name'] ?? '')),
        trim((string)($project['location'] ?? '')),
    ], static fn($v) => $v !== '');

    $best = ['facility_id' => null, 'score' => 0];
    foreach ($candidates as $candidate) {
        $result = ipmsBestFacilityForText($candidate, $facilities);
        if ($result['score'] > $best['score']) {
            $best = $result;
        }
        if ($result['facility_id'] !== null) {
            return $result;
        }
    }

    return $best;
}

/**
 * Expand a project's start_date..expected_completion window to Y-m-d date strings.
 * Capped at IPMS_MAX_BLACKOUT_DAYS as a safety net against bad upstream dates.
 *
 * @return list<string>
 */
function ipmsProjectDateRange(array $project): array
{
    $startTs = strtotime((string)($project['start_date'] ?? ''));
    if (!$startTs) {
        return [];
    }
    $endTs = strtotime((string)($project['expected_completion'] ?? ''));
    if (!$endTs || $endTs < $startTs) {
        $endTs = $startTs;
    }

    $periodStart = new DateTime(date('Y-m-d', $startTs));
    $periodEnd = new DateTime(date('Y-m-d', $endTs));

    $dayCount = (int)$periodStart->diff($periodEnd)->days + 1;
    if ($dayCount > IPMS_MAX_BLACKOUT_DAYS) {
        $periodEnd = (clone $periodStart)->modify('+' . (IPMS_MAX_BLACKOUT_DAYS - 1) . ' days');
    }

    $periodEndExclusive = (clone $periodEnd)->modify('+1 day');
    $period = new DatePeriod($periodStart, new DateInterval('P1D'), $periodEndExclusive);

    $dates = [];
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
    }
    return $dates;
}

/**
 * Path to persisted IPMS sync metadata (last run time, summary).
 */
function frs_ipms_sync_state_path(): string
{
    $root = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__);
    return $root . '/storage/ipms_sync_state.json';
}

/**
 * Path to facility IDs that IPMS sync set to maintenance (not manual staff changes, not CIMM).
 */
function frs_ipms_managed_maintenance_path(): string
{
    $root = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__);
    return $root . '/storage/ipms_managed_maintenance.json';
}

/**
 * @return array{last_sync_at: ?string, last_summary: array<string,mixed>}
 */
function frs_ipms_load_sync_state(): array
{
    $path = frs_ipms_sync_state_path();
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

function frs_ipms_save_sync_state(array $summary): void
{
    $path = frs_ipms_sync_state_path();
    $payload = [
        'last_sync_at' => date('c'),
        'last_summary' => $summary,
    ];
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * @return array<int, true>
 */
function frs_ipms_load_managed_maintenance_ids(): array
{
    $path = frs_ipms_managed_maintenance_path();
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
function frs_ipms_save_managed_maintenance_ids(array $ids): void
{
    $path = frs_ipms_managed_maintenance_path();
    file_put_contents($path, json_encode(array_values(array_map('intval', array_keys($ids))), JSON_PRETTY_PRINT));
}

/**
 * Sync facility maintenance status and blackout dates from active (blocking-status) IPMS projects.
 *
 * Rules (mirrors the CIMM sync in services/cimm_api.php):
 * - Facility goes `maintenance` when a high-confidence-matched project is active/delayed/
 *   on_hold/completion_inspection.
 * - The matched project's start_date..expected_completion window syncs into
 *   `facility_blackout_dates` (`IPMS Sync:` prefix) so residents cannot book those dates.
 * - Facility only reverts to `available` if IPMS sync previously put it into maintenance AND
 *   no other integration (e.g. CIMM) still holds it — see frs_facility_has_other_maintenance_hold().
 * - Projects that don't clear IPMS_MATCH_THRESHOLD never touch a facility's status or
 *   blackout dates; they're returned in `needs_review` instead.
 *
 * @param PDO $pdo
 * @param array<int, array<string, mixed>> $activeProjects Normalized 'active' bucket from fetchIPMSFacilityStatus()
 * @return array<string, mixed>
 */
function syncFacilitiesFromIPMS(PDO $pdo, array $activeProjects): array
{
    require_once dirname(__DIR__) . '/config/maintenance_helper.php';

    $summary = [
        'updated_to_maintenance' => 0,
        'updated_to_available' => 0,
        'blackouts_added' => 0,
        'blackouts_removed' => 0,
        'matched_project_count' => 0,
        'unmatched_project_count' => 0,
        'needs_review' => [],
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
    $matchedProjectsForBlackout = [];
    $desiredBlackouts = [];
    $ipmsManagedMaintenance = frs_ipms_load_managed_maintenance_ids();
    $facilitiesWentToMaintenance = [];
    $facilitiesWentToAvailable = [];

    foreach ($activeProjects as $project) {
        if (!ipmsIsBlockingStatus((string)($project['status'] ?? ''))) {
            continue;
        }

        $match = ipmsMatchFacilityId($project, $facilities);
        $facilityId = $match['facility_id'];

        if (!$facilityId) {
            $summary['unmatched_project_count']++;
            $summary['needs_review'][] = [
                'project_id' => $project['project_id'],
                'project_code' => $project['project_code'],
                'name' => $project['name'],
                'location' => $project['location'],
                'status' => $project['status'],
                'best_score' => $match['score'],
            ];
            continue;
        }

        $summary['matched_project_count']++;
        $matchedProjectsForBlackout[] = ['facility_id' => $facilityId, 'project' => $project];
        $activeMaintenanceFacilityIds[$facilityId] = true;
    }

    try {
        $pdo->beginTransaction();

        foreach ($facilityById as $facilityId => $facility) {
            $currentStatus = strtolower((string)($facility['status'] ?? 'available'));
            $isActiveNow = isset($activeMaintenanceFacilityIds[$facilityId]);
            $ipmsManaged = isset($ipmsManagedMaintenance[$facilityId]);
            $facilityName = (string)($facility['name'] ?? 'Facility');

            if ($isActiveNow && $currentStatus !== 'maintenance') {
                $upd = $pdo->prepare('UPDATE facilities SET status = "maintenance", updated_at = NOW() WHERE id = :id');
                $upd->execute(['id' => $facilityId]);
                $ipmsManagedMaintenance[$facilityId] = true;
                $summary['updated_to_maintenance']++;
                $facilitiesWentToMaintenance[$facilityId] = $facilityName;
            } elseif ($isActiveNow) {
                // Already under maintenance (possibly set by another source) — register our own hold too.
                $ipmsManagedMaintenance[$facilityId] = true;
            } elseif (!$isActiveNow && $currentStatus === 'maintenance' && $ipmsManaged) {
                unset($ipmsManagedMaintenance[$facilityId]);
                if (frs_facility_has_other_maintenance_hold($facilityId, 'ipms')) {
                    continue;
                }
                $upd = $pdo->prepare('UPDATE facilities SET status = "available", updated_at = NOW() WHERE id = :id');
                $upd->execute(['id' => $facilityId]);
                $summary['updated_to_available']++;
                $facilitiesWentToAvailable[$facilityId] = $facilityName;
            }
        }

        frs_ipms_save_managed_maintenance_ids($ipmsManagedMaintenance);

        foreach ($matchedProjectsForBlackout as $row) {
            $facilityId = (int)$row['facility_id'];
            if (!isset($desiredBlackouts[$facilityId])) {
                $desiredBlackouts[$facilityId] = [];
            }
            foreach (ipmsProjectDateRange($row['project']) as $dateStr) {
                $desiredBlackouts[$facilityId][$dateStr] = true;
            }
        }

        // Remove stale IPMS Sync blackouts (project completed/cancelled or no longer matched).
        $existingStmt = $pdo->query(
            "SELECT id, facility_id, blackout_date FROM facility_blackout_dates WHERE reason LIKE 'IPMS Sync:%'"
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

        foreach ($matchedProjectsForBlackout as $row) {
            $facilityId = (int)$row['facility_id'];
            $project = $row['project'];
            $label = $project['project_code'] !== '' ? $project['project_code'] : ($project['name'] ?: 'Infrastructure project');
            $reason = 'IPMS Sync: ' . $label;
            if (strlen($reason) > 255) {
                $reason = substr($reason, 0, 255);
            }

            foreach (ipmsProjectDateRange($project) as $dateStr) {
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

        foreach ($facilitiesWentToMaintenance as $facilityId => $facilityName) {
            handleFacilityMaintenanceStatusChange((int)$facilityId, (string)$facilityName);
        }
        foreach ($facilitiesWentToAvailable as $facilityId => $facilityName) {
            handleFacilityAvailableStatusChange((int)$facilityId, (string)$facilityName);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $summary['errors'][] = $e->getMessage();
        error_log('IPMS facility sync error: ' . $e->getMessage());
    }

    return $summary;
}

/**
 * Fetch the IPMS feed, sync facilities/blackouts from the active bucket, best-effort match the
 * upcoming bucket (heads-up only — never blocks booking), and persist last-run metadata.
 *
 * @return array{success: bool, active_count: int, upcoming_count: int, matched: int, summary: array<string,mixed>, upcoming: list<array<string,mixed>>, error: ?string, ran_at: string}
 */
function frs_ipms_run_sync(PDO $pdo): array
{
    $feed = fetchIPMSFacilityStatus(true);

    if ($feed['error']) {
        return [
            'success' => false,
            'active_count' => 0,
            'upcoming_count' => 0,
            'matched' => 0,
            'summary' => [],
            'upcoming' => [],
            'error' => (string)$feed['error'],
            'ran_at' => date('c'),
        ];
    }

    $summary = syncFacilitiesFromIPMS($pdo, $feed['active']);

    // Best-effort match for upcoming work so the UI can show "heads up" per facility.
    // This never writes to facilities/blackout tables — informational only.
    $facilitiesStmt = $pdo->query('SELECT id, name, location FROM facilities');
    $facilities = $facilitiesStmt ? $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $upcomingEnriched = [];
    foreach ($feed['upcoming'] as $project) {
        $match = ipmsMatchFacilityId($project, $facilities);
        $project['matched_facility_id'] = $match['facility_id'];
        $project['match_score'] = $match['score'];
        $upcomingEnriched[] = $project;
    }

    $summary['upcoming_projects'] = $upcomingEnriched;
    $summary['barangay'] = $feed['barangay'];

    frs_ipms_save_sync_state($summary);

    return [
        'success' => true,
        'active_count' => count($feed['active']),
        'upcoming_count' => count($feed['upcoming']),
        'matched' => $summary['matched_project_count'],
        'summary' => $summary,
        'upcoming' => $upcomingEnriched,
        'error' => null,
        'ran_at' => date('c'),
    ];
}
