<?php
/**
 * LGU Energy Efficiency integration — business logic.
 *
 * Pure computation/matching functions live at the top (unit tested); PDO-backed
 * reading/mapping/sync functions follow (exercised via the module page and
 * sync script).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once dirname(__DIR__) . '/services/energy_api.php';

/** Minimum score for an automatic facility-name match suggestion. */
const FRS_ENERGY_MATCH_THRESHOLD = 60;

/**
 * Monthly consumption from two cumulative meter values. Null when either value
 * is non-numeric/negative or the meter appears to have gone backwards.
 */
function frs_energy_compute_consumption(mixed $previous, mixed $current): ?float
{
    if (!is_numeric($previous) || !is_numeric($current)) {
        return null;
    }
    $prev = (float)$previous;
    $curr = (float)$current;
    if ($prev < 0 || $curr < 0 || $curr < $prev) {
        return null;
    }
    return round($curr - $prev, 2);
}

function frs_energy_normalize_name(string $name): string
{
    $normalized = strtolower(trim($name));
    $normalized = (string)preg_replace('/[^a-z0-9 ]+/', ' ', $normalized);
    return trim((string)preg_replace('/\s+/', ' ', $normalized));
}

/**
 * Suggest the best Energy-system facility for a CPRF facility name.
 * Scores: 100 exact (normalized), 80 substring either way, else token overlap
 * percentage. Returns ['id', 'name', 'score'] or null when nothing clears
 * FRS_ENERGY_MATCH_THRESHOLD.
 *
 * @param array<int, array<string, mixed>> $energyFacilities rows with 'id' and 'name'
 */
function frs_energy_suggest_match(string $facilityName, array $energyFacilities): ?array
{
    $target = frs_energy_normalize_name($facilityName);
    if ($target === '') {
        return null;
    }

    $best = null;
    foreach ($energyFacilities as $remote) {
        $remoteName = frs_energy_normalize_name((string)($remote['name'] ?? ''));
        if ($remoteName === '' || !isset($remote['id'])) {
            continue;
        }

        if ($remoteName === $target) {
            $score = 100;
        } elseif (str_contains($remoteName, $target) || str_contains($target, $remoteName)) {
            $score = 80;
        } else {
            $targetTokens = explode(' ', $target);
            $remoteTokens = explode(' ', $remoteName);
            $common = array_intersect($targetTokens, $remoteTokens);
            $score = (int)round((count($common) / max(count($targetTokens), 1)) * 70);
        }

        if ($score >= FRS_ENERGY_MATCH_THRESHOLD && ($best === null || $score > $best['score'])) {
            $best = ['id' => (int)$remote['id'], 'name' => (string)$remote['name'], 'score' => $score];
        }
    }

    return $best;
}

/**
 * Map a local energy_meter_readings row to the push endpoint's request body.
 *
 * @param array<string, mixed> $reading local row (id, year, month, reading_date,
 *   previous_reading_kwh, current_reading_kwh, optional notes/recorded_by_name)
 */
function frs_energy_build_reading_payload(array $reading, int $energyFacilityId): array
{
    $payload = [
        'facility_id' => $energyFacilityId,
        'year' => (int)$reading['year'],
        'month' => (int)$reading['month'],
        'previous_reading_kwh' => (float)$reading['previous_reading_kwh'],
        'current_reading_kwh' => (float)$reading['current_reading_kwh'],
        'reading_date' => (string)$reading['reading_date'],
        'external_ref' => 'CPRF-' . (int)$reading['id'],
    ];
    if (!empty($reading['notes'])) {
        $payload['notes'] = (string)$reading['notes'];
    }
    if (!empty($reading['recorded_by_name'])) {
        $payload['recorded_by_name'] = (string)$reading['recorded_by_name'];
    }
    return $payload;
}
