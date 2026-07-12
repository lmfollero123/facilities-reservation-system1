<?php
/**
 * Curated AI demo scenarios for capstone presentations.
 * Used by Book a Facility (prefill) and AI Model Lab (quick links).
 */

declare(strict_types=1);

/**
 * Whether AI dev tools (Model Lab, demo scenario panel) are shown in the UI.
 * Hidden by default for panel presentations and production.
 * Set FRS_AI_DEV_TOOLS=true in .env to re-enable locally.
 */
function frs_ai_dev_tools_visible(): bool
{
    $raw = getenv('FRS_AI_DEV_TOOLS');
    if ($raw === false || $raw === '') {
        return false;
    }
    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Whether the current role may load demo booking scenarios.
 */
function frs_ai_demo_can_load_scenarios(string $role): bool
{
    if (!frs_ai_dev_tools_visible()) {
        return false;
    }
    return in_array($role, ['Admin', 'Staff'], true);
}

/**
 * Static scenario templates (facility_id and dates resolved at runtime).
 *
 * @return array<string, array<string, mixed>>
 */
function frs_ai_demo_scenario_templates(): array
{
    return [
        'low' => [
            'label' => 'Low risk',
            'description' => 'Verified-style community meeting: small group, weekday morning, clear purpose.',
            'badge' => 'success',
            'purpose' => 'Barangay health seminar for senior citizens — blood pressure screening and nutrition talk.',
            'expected_attendees' => 15,
            'time_slot' => '09:00 - 12:00',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'days_ahead' => 14,
            'weekday' => 2, // Tuesday
            'facility_preference' => 'largest', // community hall / covered court
        ],
        'medium' => [
            'label' => 'Medium risk',
            'description' => 'Weekend sports event with moderate crowd — may need staff review.',
            'badge' => 'warning',
            'purpose' => 'Inter-barangay youth basketball tournament — elimination round.',
            'expected_attendees' => 80,
            'time_slot' => '14:00 - 18:00',
            'start_time' => '14:00',
            'end_time' => '18:00',
            'days_ahead' => 21,
            'weekday' => 6, // Saturday
            'facility_preference' => 'court',
        ],
        'high' => [
            'label' => 'High risk',
            'description' => 'Large commercial event on peak Saturday evening — triggers risk and conflict checks.',
            'badge' => 'danger',
            'purpose' => 'Commercial wedding reception with live band and catering (for-profit event).',
            'expected_attendees' => 250,
            'time_slot' => '18:00 - 22:00',
            'start_time' => '18:00',
            'end_time' => '22:00',
            'days_ahead' => 28,
            'weekday' => 6,
            'facility_preference' => 'largest',
        ],
        'unclear' => [
            'label' => 'Unclear purpose',
            'description' => 'Vague purpose text — tests purpose-analysis and unclear-purpose models.',
            'badge' => 'muted',
            'purpose' => 'Gathering / TBD — details to follow.',
            'expected_attendees' => 30,
            'time_slot' => '10:00 - 14:00',
            'start_time' => '10:00',
            'end_time' => '14:00',
            'days_ahead' => 10,
            'weekday' => null,
            'facility_preference' => 'any',
        ],
        'demand' => [
            'label' => 'High demand slot',
            'description' => 'Same facility and date as seeded peak demand — tests demand forecasting hints.',
            'badge' => 'info',
            'purpose' => 'Community zumba and fitness class — regular weekend session.',
            'expected_attendees' => 45,
            'time_slot' => '08:00 - 10:00',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'days_ahead' => null, // resolved from DB peak
            'weekday' => null,
            'facility_preference' => 'peak_demand',
        ],
    ];
}

/**
 * Pick next calendar date matching weekday (1=Mon … 7=Sun), at least $minDays ahead.
 */
function frs_ai_demo_next_weekday(int $weekday, int $minDays = 7): string
{
    $date = new DateTimeImmutable('today');
    $date = $date->modify('+' . max(1, $minDays) . ' days');
    while ((int)$date->format('N') !== $weekday) {
        $date = $date->modify('+1 day');
    }
    return $date->format('Y-m-d');
}

/**
 * Resolve facility id for a scenario preference.
 */
function frs_ai_demo_resolve_facility_id(PDO $pdo, string $preference): ?int
{
    if ($preference === 'peak_demand') {
        $peak = frs_ai_demo_peak_demand_slot($pdo);
        if ($peak) {
            return $peak['facility_id'];
        }
    }

    $stmt = $pdo->query(
        "SELECT id, name, capacity FROM facilities WHERE status = 'available' ORDER BY id ASC"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$rows) {
        return null;
    }

    if ($preference === 'largest') {
        usort($rows, static function (array $a, array $b): int {
            $capA = (int)preg_replace('/\D/', '', (string)($a['capacity'] ?? '0'));
            $capB = (int)preg_replace('/\D/', '', (string)($b['capacity'] ?? '0'));
            return $capB <=> $capA ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return (int)$rows[0]['id'];
    }

    if ($preference === 'court') {
        usort($rows, static function (array $a, array $b): int {
            $score = static function (array $r): int {
                $n = strtolower((string)($r['name'] ?? ''));
                return (int)(
                    str_contains($n, 'court')
                    || str_contains($n, 'gym')
                    || str_contains($n, 'sports')
                );
            };
            return $score($b) <=> $score($a) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return (int)$rows[0]['id'];
    }

    return (int)$rows[0]['id'];
}

/**
 * Peak demand facility + date from seeded data (for demand scenario).
 *
 * @return array{facility_id: int, reservation_date: string}|null
 */
function frs_ai_demo_peak_demand_slot(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT facility_id, reservation_date, COUNT(*) AS cnt
         FROM reservations
         WHERE status IN ('approved', 'pending')
           AND reservation_date >= CURDATE()
         GROUP BY facility_id, reservation_date
         ORDER BY cnt DESC, facility_id ASC
         LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) {
        return null;
    }
    return [
        'facility_id' => (int)$row['facility_id'],
        'reservation_date' => (string)$row['reservation_date'],
    ];
}

/**
 * Resolve one scenario into booking query params.
 *
 * @return array<string, mixed>|null
 */
function frs_ai_demo_resolve_scenario(PDO $pdo, string $key): ?array
{
    $templates = frs_ai_demo_scenario_templates();
    if (!isset($templates[$key])) {
        return null;
    }

    $t = $templates[$key];
    $facilityId = frs_ai_demo_resolve_facility_id($pdo, (string)($t['facility_preference'] ?? 'any'));
    $reservationDate = null;

    if (($t['facility_preference'] ?? '') === 'peak_demand') {
        $peak = frs_ai_demo_peak_demand_slot($pdo);
        if ($peak) {
            $facilityId = $peak['facility_id'];
            $reservationDate = $peak['reservation_date'];
        }
    }

    if ($reservationDate === null) {
        $daysAhead = (int)($t['days_ahead'] ?? 14);
        $weekday = $t['weekday'] ?? null;
        if ($weekday !== null) {
            $reservationDate = frs_ai_demo_next_weekday((int)$weekday, $daysAhead);
        } else {
            $reservationDate = (new DateTimeImmutable('today'))
                ->modify('+' . max(1, $daysAhead) . ' days')
                ->format('Y-m-d');
        }
    }

    if (!$facilityId) {
        return null;
    }

    return [
        'key' => $key,
        'label' => $t['label'],
        'description' => $t['description'],
        'badge' => $t['badge'],
        'params' => [
            'facility_id' => $facilityId,
            'reservation_date' => $reservationDate,
            'time_slot' => $t['time_slot'],
            'start_time' => $t['start_time'],
            'end_time' => $t['end_time'],
            'purpose' => $t['purpose'],
            'expected_attendees' => (int)$t['expected_attendees'],
            'open_booking' => '1',
            'demo_loaded' => $key,
        ],
    ];
}

/**
 * All scenarios with resolved params for UI listing.
 *
 * @return array<int, array<string, mixed>>
 */
function frs_ai_demo_list_scenarios(PDO $pdo): array
{
    $out = [];
    foreach (array_keys(frs_ai_demo_scenario_templates()) as $key) {
        $resolved = frs_ai_demo_resolve_scenario($pdo, $key);
        if ($resolved) {
            $out[] = $resolved;
        }
    }
    return $out;
}

/**
 * Build book-facility URL for a scenario.
 */
function frs_ai_demo_booking_url(string $scenarioKey): string
{
    return base_path() . '/dashboard/book-facility?demo_scenario=' . rawurlencode($scenarioKey);
}
