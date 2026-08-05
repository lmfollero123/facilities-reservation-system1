# UMAN Utility Readings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move electricity+water meter-reading submission from a direct CPRF→Energy push to a CPRF→UMAN push (UMAN forwards to Energy on its own), with a new water dimension added alongside the existing electricity one.

**Architecture:** Reuse the existing mature `energy_meter_readings` table and its CRUD/validation functions in `config/energy_helper.php` (chronological-order guard, duplicate-period guard, edit-latest-only, audit logging) — extend them for water fields rather than building a parallel pipeline. Add one new, simpler push path (no facility-mapping lookup needed, since UMAN already shares CPRF's `facility_id` directly) targeting a new `services/uman_api.php` client function. Move the whole submission UI from the Energy Efficiency page to the UMAN Integration page.

**Tech Stack:** PHP (no framework), PDO/MySQL, vanilla JS.

## Global Constraints

- No PHPUnit/test runner in this repo, and no reachable local dev database in this environment — verification is `php -l` (syntax) + careful code review against the exact behavior of the functions being extended; final correctness check is a manual walkthrough on the live server.
- CSRF: `csrf_field()` / `verifyCSRFToken($_POST[CSRF_TOKEN_NAME])`, same as every other POST form in this codebase.
- Rate defaults: electricity ₱14.83/kWh (Meralco, latest published July 2026 residential all-in rate), water ₱68.02/m³ (Manila Water East Zone, serves Quezon City/Culiat, Q2 2026 unsewered tier). Both are editable per submission, same fidelity as the existing `rate_per_kwh` field today — not a full tiered tariff calculator.
- `energy_meter_readings` table and `frs_energy_*` function names are kept as-is (not renamed) — accepted tech debt, see spec's "Naming note."

---

## File Map

| File | Change |
|---|---|
| `database/migration_add_water_readings.sql` | New — adds 4 water columns to `energy_meter_readings`, idempotent |
| `config/energy_helper.php` | Extend `frs_energy_save_reading()` / `frs_energy_update_reading()` for water fields; add `frs_uman_build_utility_reading_payload()` and `frs_uman_push_utility_reading()` |
| `services/uman_api.php` | Add `submitUMANUtilityReading()` |
| `resources/views/pages/dashboard/utilities_integration.php` | New "Utility Readings" section: add/edit form + history table, POST handling for `add_utility_reading` / `update_utility_reading` / `delete_utility_reading` |
| `resources/views/pages/dashboard/energy_efficiency.php` | Remove the Meter Readings tab entirely; update Recommendations empty-state copy |

---

### Task 1: Add water columns to `energy_meter_readings`

**Files:**
- Create: `database/migration_add_water_readings.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Add water meter reading columns alongside the existing electricity ones.
-- Safe to re-run: guards each ALTER on INFORMATION_SCHEMA before applying.

USE facilities_reservation;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'previous_reading_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN previous_reading_water DECIMAL(14,2) NULL AFTER rate_per_kwh',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'current_reading_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN current_reading_water DECIMAL(14,2) NULL AFTER previous_reading_water',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'consumption_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN consumption_water DECIMAL(14,2) NULL AFTER current_reading_water',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'energy_meter_readings'
      AND COLUMN_NAME = 'rate_per_water'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE energy_meter_readings ADD COLUMN rate_per_water DECIMAL(10,2) NOT NULL DEFAULT 68.02 AFTER consumption_water',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

- [ ] **Step 2: Note for manual application**

This repo has no local dev database in this environment. Run this migration against the live server's database the next time you have DB access (same way `migration_add_rate_per_kwh_to_energy_meter_readings.sql` was applied). The rest of this plan's PHP code assumes these 4 columns exist.

- [ ] **Step 3: Commit**

```bash
git add database/migration_add_water_readings.sql
git commit -m "feat: add water meter reading columns migration"
```

---

### Task 2: Extend local reading CRUD for water fields

**Files:**
- Modify: `config/energy_helper.php`

**Interfaces:**
- Consumes: existing `frs_energy_compute_consumption()`, `frs_energy_last_reading()`, `frs_energy_is_latest_reading()`.
- Produces: `frs_energy_save_reading()` / `frs_energy_update_reading()` now also accept `previous_reading_water`, `current_reading_water`, `rate_per_water` in their `$data` array (all optional — a facility can still be recorded electric-only, matching the spec's "first time adding water" edge case).

- [ ] **Step 1: Extend `frs_energy_save_reading()`**

Find the existing function (starts `function frs_energy_save_reading(PDO $pdo, array $data): int`). Its current body:

```php
function frs_energy_save_reading(PDO $pdo, array $data): int
{
    foreach (['previous_reading_kwh', 'current_reading_kwh'] as $key) {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            throw new InvalidArgumentException('Meter readings must be numeric values.');
        }
    }
    if (!isset($data['rate_per_kwh']) || !is_numeric($data['rate_per_kwh']) || (float)$data['rate_per_kwh'] <= 0) {
        throw new InvalidArgumentException('Rate per kWh must be greater than zero.');
    }

    $facilityId = (int)$data['facility_id'];
    $last = frs_energy_last_reading($pdo, $facilityId);
    if ($last !== null) {
        $lastPeriod = ((int)$last['year']) * 100 + (int)$last['month'];
        $newPeriod = ((int)$data['year']) * 100 + (int)$data['month'];
        if ($newPeriod <= $lastPeriod) {
            throw new InvalidArgumentException(sprintf(
                'Readings must be recorded in chronological order. The latest recorded period for this facility is %04d-%02d.',
                (int)$last['year'],
                (int)$last['month']
            ));
        }
    }
    $previous = $last !== null ? (float)$last['current_reading_kwh'] : (float)$data['previous_reading_kwh'];
    $current = (float)$data['current_reading_kwh'];

    $consumption = frs_energy_compute_consumption($previous, $current);
    if ($consumption === null) {
        throw new InvalidArgumentException('Current reading must be greater than or equal to the previous reading (' . number_format($previous, 2) . ' kWh).');
    }

    $dupe = $pdo->prepare('SELECT COUNT(*) FROM energy_meter_readings WHERE facility_id = :f AND year = :y AND month = :m');
    $dupe->execute(['f' => $facilityId, 'y' => (int)$data['year'], 'm' => (int)$data['month']]);
    if ((int)$dupe->fetchColumn() > 0) {
        throw new InvalidArgumentException('A reading for this facility and month already exists.');
    }

    $stmt = $pdo->prepare('
        INSERT INTO energy_meter_readings
            (facility_id, year, month, reading_date, previous_reading_kwh, current_reading_kwh, consumption_kwh, rate_per_kwh, notes, recorded_by, sync_status)
        VALUES
            (:facility_id, :year, :month, :reading_date, :previous_kwh, :current_kwh, :consumption_kwh, :rate_per_kwh, :notes, :recorded_by, \'pending\')
    ');
    try {
        $stmt->execute([
            'facility_id' => $facilityId,
            'year' => (int)$data['year'],
            'month' => (int)$data['month'],
            'reading_date' => (string)$data['reading_date'],
            'previous_kwh' => $previous,
            'current_kwh' => $current,
            'consumption_kwh' => $consumption,
            'rate_per_kwh' => round((float)$data['rate_per_kwh'], 2),
            'notes' => $data['notes'] !== null && $data['notes'] !== '' ? (string)$data['notes'] : null,
            'recorded_by' => $data['recorded_by'],
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) == 1062) {
            throw new InvalidArgumentException('A reading for this facility and month already exists.');
        }
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/audit.php';
    logAudit('Recorded energy meter reading', 'Energy Efficiency', "facility_id={$facilityId} {$data['year']}-{$data['month']}: {$consumption} kWh");

    return $id;
}
```

Replace it with (water fields are optional — omitted/blank means "not recorded this period," same as a facility with no water meter yet):

```php
function frs_energy_save_reading(PDO $pdo, array $data): int
{
    foreach (['previous_reading_kwh', 'current_reading_kwh'] as $key) {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            throw new InvalidArgumentException('Meter readings must be numeric values.');
        }
    }
    if (!isset($data['rate_per_kwh']) || !is_numeric($data['rate_per_kwh']) || (float)$data['rate_per_kwh'] <= 0) {
        throw new InvalidArgumentException('Rate per kWh must be greater than zero.');
    }

    $hasWaterInput = isset($data['current_reading_water']) && $data['current_reading_water'] !== '' && $data['current_reading_water'] !== null;
    if ($hasWaterInput) {
        if (!is_numeric($data['current_reading_water'])) {
            throw new InvalidArgumentException('Water meter readings must be numeric values.');
        }
        if (!isset($data['rate_per_water']) || !is_numeric($data['rate_per_water']) || (float)$data['rate_per_water'] <= 0) {
            throw new InvalidArgumentException('Rate per cubic meter must be greater than zero.');
        }
    }

    $facilityId = (int)$data['facility_id'];
    $last = frs_energy_last_reading($pdo, $facilityId);
    if ($last !== null) {
        $lastPeriod = ((int)$last['year']) * 100 + (int)$last['month'];
        $newPeriod = ((int)$data['year']) * 100 + (int)$data['month'];
        if ($newPeriod <= $lastPeriod) {
            throw new InvalidArgumentException(sprintf(
                'Readings must be recorded in chronological order. The latest recorded period for this facility is %04d-%02d.',
                (int)$last['year'],
                (int)$last['month']
            ));
        }
    }
    $previous = $last !== null ? (float)$last['current_reading_kwh'] : (float)$data['previous_reading_kwh'];
    $current = (float)$data['current_reading_kwh'];

    $consumption = frs_energy_compute_consumption($previous, $current);
    if ($consumption === null) {
        throw new InvalidArgumentException('Current reading must be greater than or equal to the previous reading (' . number_format($previous, 2) . ' kWh).');
    }

    $previousWater = null;
    $currentWater = null;
    $consumptionWater = null;
    $rateWater = null;
    if ($hasWaterInput) {
        $lastWaterKnown = $last !== null && $last['current_reading_water'] !== null;
        $previousWater = $lastWaterKnown
            ? (float)$last['current_reading_water']
            : (isset($data['previous_reading_water']) && is_numeric($data['previous_reading_water']) ? (float)$data['previous_reading_water'] : null);
        if ($previousWater === null) {
            throw new InvalidArgumentException('Previous water reading is required for this facility\'s first water entry.');
        }
        $currentWater = (float)$data['current_reading_water'];
        $consumptionWater = frs_energy_compute_consumption($previousWater, $currentWater);
        if ($consumptionWater === null) {
            throw new InvalidArgumentException('Current water reading must be greater than or equal to the previous reading (' . number_format($previousWater, 2) . ' m³).');
        }
        $rateWater = round((float)$data['rate_per_water'], 2);
    }

    $dupe = $pdo->prepare('SELECT COUNT(*) FROM energy_meter_readings WHERE facility_id = :f AND year = :y AND month = :m');
    $dupe->execute(['f' => $facilityId, 'y' => (int)$data['year'], 'm' => (int)$data['month']]);
    if ((int)$dupe->fetchColumn() > 0) {
        throw new InvalidArgumentException('A reading for this facility and month already exists.');
    }

    $stmt = $pdo->prepare('
        INSERT INTO energy_meter_readings
            (facility_id, year, month, reading_date, previous_reading_kwh, current_reading_kwh, consumption_kwh, rate_per_kwh,
             previous_reading_water, current_reading_water, consumption_water, rate_per_water,
             notes, recorded_by, sync_status)
        VALUES
            (:facility_id, :year, :month, :reading_date, :previous_kwh, :current_kwh, :consumption_kwh, :rate_per_kwh,
             :previous_water, :current_water, :consumption_water, :rate_per_water,
             :notes, :recorded_by, \'pending\')
    ');
    try {
        $stmt->execute([
            'facility_id' => $facilityId,
            'year' => (int)$data['year'],
            'month' => (int)$data['month'],
            'reading_date' => (string)$data['reading_date'],
            'previous_kwh' => $previous,
            'current_kwh' => $current,
            'consumption_kwh' => $consumption,
            'rate_per_kwh' => round((float)$data['rate_per_kwh'], 2),
            'previous_water' => $previousWater,
            'current_water' => $currentWater,
            'consumption_water' => $consumptionWater,
            'rate_per_water' => $rateWater ?? 68.02,
            'notes' => $data['notes'] !== null && $data['notes'] !== '' ? (string)$data['notes'] : null,
            'recorded_by' => $data['recorded_by'],
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) == 1062) {
            throw new InvalidArgumentException('A reading for this facility and month already exists.');
        }
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/audit.php';
    $auditMsg = "facility_id={$facilityId} {$data['year']}-{$data['month']}: {$consumption} kWh";
    if ($consumptionWater !== null) {
        $auditMsg .= ", {$consumptionWater} m³ water";
    }
    logAudit('Recorded utility meter reading', 'UMAN Utilities', $auditMsg);

    return $id;
}
```

- [ ] **Step 2: Extend `frs_energy_update_reading()`**

Find the existing function (starts `function frs_energy_update_reading(PDO $pdo, int $readingId, array $data): void`) — its full current body is in `config/energy_helper.php` lines 386-457. Replace the `UPDATE` statement portion — specifically this block:

```php
    $notes = $data['notes'] !== null && $data['notes'] !== '' ? (string)$data['notes'] : null;

    $update = $pdo->prepare('
        UPDATE energy_meter_readings
        SET previous_reading_kwh = :previous_kwh,
            current_reading_kwh = :current_kwh,
            consumption_kwh = :consumption_kwh,
            rate_per_kwh = :rate_per_kwh,
            reading_date = :reading_date,
            notes = :notes,
            sync_status = \'pending\',
            synced_at = NULL,
            sync_error = NULL
        WHERE id = :id
    ');
    $update->execute([
        'previous_kwh' => $previous,
        'current_kwh' => $current,
        'consumption_kwh' => $consumption,
        'rate_per_kwh' => $ratePerKwh,
        'reading_date' => (string)$data['reading_date'],
        'notes' => $notes,
        'id' => $readingId,
    ]);
```

with (water is optional here too — if the reading being edited never had water, and the edit doesn't supply it either, it stays `NULL`; if it already had water, editing keeps the same continuity rule the electric side uses):

```php
    $notes = $data['notes'] !== null && $data['notes'] !== '' ? (string)$data['notes'] : null;

    $previousWater = $reading['previous_reading_water'] !== null ? (float)$reading['previous_reading_water'] : null;
    $currentWater = $reading['current_reading_water'] !== null ? (float)$reading['current_reading_water'] : null;
    $consumptionWater = $reading['consumption_water'] !== null ? (float)$reading['consumption_water'] : null;
    $rateWater = $reading['rate_per_water'] !== null ? (float)$reading['rate_per_water'] : null;

    if (isset($data['current_reading_water']) && $data['current_reading_water'] !== '' && $data['current_reading_water'] !== null) {
        if (!is_numeric($data['current_reading_water'])) {
            throw new InvalidArgumentException('Water meter readings must be numeric values.');
        }
        if (!isset($data['rate_per_water']) || !is_numeric($data['rate_per_water']) || (float)$data['rate_per_water'] <= 0) {
            throw new InvalidArgumentException('Rate per cubic meter must be greater than zero.');
        }
        if ($isOnlyReading && array_key_exists('previous_reading_water', $data) && $data['previous_reading_water'] !== null && $data['previous_reading_water'] !== '') {
            if (!is_numeric($data['previous_reading_water'])) {
                throw new InvalidArgumentException('Water meter readings must be numeric values.');
            }
            $previousWater = (float)$data['previous_reading_water'];
        } elseif ($previousWater === null) {
            throw new InvalidArgumentException('Previous water reading is required for this facility\'s first water entry.');
        }
        $currentWater = (float)$data['current_reading_water'];
        $consumptionWater = frs_energy_compute_consumption($previousWater, $currentWater);
        if ($consumptionWater === null) {
            throw new InvalidArgumentException('Current water reading must be greater than or equal to the previous reading (' . number_format($previousWater, 2) . ' m³).');
        }
        $rateWater = round((float)$data['rate_per_water'], 2);
    }

    $update = $pdo->prepare('
        UPDATE energy_meter_readings
        SET previous_reading_kwh = :previous_kwh,
            current_reading_kwh = :current_kwh,
            consumption_kwh = :consumption_kwh,
            rate_per_kwh = :rate_per_kwh,
            previous_reading_water = :previous_water,
            current_reading_water = :current_water,
            consumption_water = :consumption_water,
            rate_per_water = :rate_per_water,
            reading_date = :reading_date,
            notes = :notes,
            sync_status = \'pending\',
            synced_at = NULL,
            sync_error = NULL
        WHERE id = :id
    ');
    $update->execute([
        'previous_kwh' => $previous,
        'current_kwh' => $current,
        'consumption_kwh' => $consumption,
        'rate_per_kwh' => $ratePerKwh,
        'previous_water' => $previousWater,
        'current_water' => $currentWater,
        'consumption_water' => $consumptionWater,
        'rate_per_water' => $rateWater,
        'reading_date' => (string)$data['reading_date'],
        'notes' => $notes,
        'id' => $readingId,
    ]);
```

- [ ] **Step 3: Add the UMAN payload builder and push function**

Add these two functions right after `frs_energy_build_reading_payload()` in `config/energy_helper.php`:

```php
/**
 * Map a local energy_meter_readings row to UMAN's utility-reading intake
 * payload. Unlike the Energy push, no facility-mapping lookup is needed —
 * UMAN and CPRF already share the same facility_id.
 *
 * @param array<string, mixed> $reading local row
 */
function frs_uman_build_utility_reading_payload(array $reading): array
{
    $payload = [
        'facility_id' => (int)$reading['facility_id'],
        'year' => (int)$reading['year'],
        'month' => (int)$reading['month'],
        'reading_date' => (string)$reading['reading_date'],
        'electric' => [
            'previous_reading_kwh' => (float)$reading['previous_reading_kwh'],
            'current_reading_kwh' => (float)$reading['current_reading_kwh'],
            'consumption_kwh' => (float)$reading['consumption_kwh'],
            'rate_per_kwh' => (float)$reading['rate_per_kwh'],
            'cost' => round((float)$reading['consumption_kwh'] * (float)$reading['rate_per_kwh'], 2),
        ],
        'external_ref' => 'CPRF-' . (int)$reading['id'],
    ];
    if ($reading['current_reading_water'] !== null) {
        $payload['water'] = [
            'previous_reading_cbm' => (float)$reading['previous_reading_water'],
            'current_reading_cbm' => (float)$reading['current_reading_water'],
            'consumption_cbm' => (float)$reading['consumption_water'],
            'rate_per_cbm' => (float)$reading['rate_per_water'],
            'cost' => round((float)$reading['consumption_water'] * (float)$reading['rate_per_water'], 2),
        ];
    }
    if (!empty($reading['notes'])) {
        $payload['notes'] = (string)$reading['notes'];
    }
    if (!empty($reading['recorded_by_name'])) {
        $payload['recorded_by_name'] = (string)$reading['recorded_by_name'];
    }
    return $payload;
}

/**
 * Push one local reading to UMAN and record the outcome. Simpler sibling of
 * frs_energy_push_reading() — no facility-mapping lookup, since UMAN already
 * shares CPRF's facility_id directly (see services/uman_api.php).
 *
 * @return array{success: bool, error: ?string}
 */
function frs_uman_push_utility_reading(PDO $pdo, int $readingId): array
{
    $stmt = $pdo->prepare('
        SELECT r.*, u.name AS recorded_by_name
        FROM energy_meter_readings r
        LEFT JOIN users u ON u.id = r.recorded_by
        WHERE r.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $readingId]);
    $reading = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reading === false) {
        return ['success' => false, 'error' => 'Reading not found.'];
    }

    $payload = frs_uman_build_utility_reading_payload($reading);
    $result = submitUMANUtilityReading($payload);

    // uman_api_post() (and everything built on it, like this function) returns
    // {data, error} with no 'success' key — absence of an error IS success,
    // same contract every other UMAN call site in this codebase checks
    // (e.g. utilities_integration.php's `!empty($result['error'])`).
    if (!empty($result['error'])) {
        $fail = $pdo->prepare("UPDATE energy_meter_readings SET sync_status = 'failed', sync_error = :err WHERE id = :id");
        $fail->execute(['err' => (string)$result['error'], 'id' => $readingId]);
        return ['success' => false, 'error' => $result['error']];
    }

    $ok = $pdo->prepare("
        UPDATE energy_meter_readings
        SET sync_status = 'synced', synced_at = NOW(), sync_error = NULL
        WHERE id = :id
    ");
    $ok->execute(['id' => $readingId]);

    return ['success' => true, 'error' => null];
}
```

Add `require_once dirname(__DIR__) . '/services/uman_api.php';` alongside the existing `require_once dirname(__DIR__) . '/services/energy_api.php';` near the top of `config/energy_helper.php`.

- [ ] **Step 4: Lint**

Run: `php -l config/energy_helper.php`
Expected: `No syntax errors detected in config/energy_helper.php`

- [ ] **Step 5: Commit**

```bash
git add config/energy_helper.php
git commit -m "feat: extend meter reading CRUD for water, add UMAN push path"
```

---

### Task 3: Add the UMAN client function

**Files:**
- Modify: `services/uman_api.php`

**Interfaces:**
- Consumes: existing `uman_api_post()`.
- Produces: `submitUMANUtilityReading(array $payload): array` — `{data: array, error: ?string}` (same shape every `uman_api_post()`-based function returns), consumed by `frs_uman_push_utility_reading()` (Task 2).

- [ ] **Step 1: Add the function**

Add near `submitUMANAssetRequest()` in `services/uman_api.php`:

```php
/**
 * Submit an electric+water utility meter reading to UMAN. UMAN monitors
 * these for high consumption and forwards to the LGU Energy system on its
 * own — this call's only job is to hand the reading over.
 *
 * @param array<string, mixed> $payload from frs_uman_build_utility_reading_payload()
 * @return array{data: array, error: ?string}
 */
function submitUMANUtilityReading(array $payload): array
{
    return uman_api_post('/api/utility-readings.php', $payload);
}
```

`uman_api_post()` returns `{data, error}` (confirmed by reading its source — no `success` key) — `frs_uman_push_utility_reading()` in Task 2 checks `!empty($result['error'])`, matching this exactly.

- [ ] **Step 2: Lint**

Run: `php -l services/uman_api.php`
Expected: `No syntax errors detected in services/uman_api.php`

- [ ] **Step 3: Commit**

```bash
git add services/uman_api.php
git commit -m "feat: add submitUMANUtilityReading client"
```

---

### Task 4: Utility Readings section on the UMAN Integration page

**Files:**
- Modify: `resources/views/pages/dashboard/utilities_integration.php`

**Interfaces:**
- Consumes: `frs_energy_save_reading()`, `frs_energy_update_reading()`, `frs_energy_delete_reading()`, `frs_uman_push_utility_reading()`, `frs_energy_tables_exist()`, `frs_energy_last_reading()` (all from Task 2 / existing `energy_helper.php`).

- [ ] **Step 1: Require `energy_helper.php` and add permission flags**

Near the top of `utilities_integration.php`, alongside the existing requires, add:

```php
require_once __DIR__ . '/../../../../config/energy_helper.php';
```

Right after the existing `$hasUmanTables = frs_uman_tables_exist($pdo);` line, add:

```php
$hasReadingTables = frs_energy_tables_exist($pdo);
$canCreateReadings = frs_can_create($role, 'utilities');
$canUpdateReadings = frs_can_update($role, 'utilities');
$canDeleteReadings = frs_can_delete($role, 'utilities');
```

- [ ] **Step 2: Add POST handling for the three reading actions**

In the existing `if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { ... elseif ($_POST['action'] === 'request_asset') { ... }` chain, add three more `elseif` branches (after the existing asset-request handling, before its closing brace):

```php
    } elseif ($_POST['action'] === 'add_utility_reading' && $canCreateReadings && $hasReadingTables) {
        $month = (string)($_POST['reading_month'] ?? '');
        $parts = explode('-', $month);
        try {
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int)$parts[1] < 1 || (int)$parts[1] > 12) {
                throw new InvalidArgumentException('Please choose a valid reading month.');
            }
            $readingId = frs_energy_save_reading($pdo, [
                'facility_id' => (int)($_POST['facility_id'] ?? 0),
                'year' => (int)$parts[0],
                'month' => (int)$parts[1],
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'previous_reading_kwh' => (float)($_POST['previous_reading_kwh'] ?? 0),
                'current_reading_kwh' => (float)($_POST['current_reading_kwh'] ?? 0),
                'rate_per_kwh' => $_POST['rate_per_kwh'] ?? null,
                'previous_reading_water' => $_POST['previous_reading_water'] ?? null,
                'current_reading_water' => $_POST['current_reading_water'] ?? null,
                'rate_per_water' => $_POST['rate_per_water'] ?? null,
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'recorded_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $push = frs_uman_push_utility_reading($pdo, $readingId);
            $message = $push['success']
                ? 'Reading saved and sent to UMAN.'
                : 'Reading saved locally. Send to UMAN pending: ' . (string)$push['error'];
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to save reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'update_utility_reading' && $canUpdateReadings && $hasReadingTables) {
        $readingId = (int)($_POST['reading_id'] ?? 0);
        try {
            frs_energy_update_reading($pdo, $readingId, [
                'current_reading_kwh' => $_POST['current_reading_kwh'] ?? null,
                'previous_reading_kwh' => $_POST['previous_reading_kwh'] ?? null,
                'rate_per_kwh' => $_POST['rate_per_kwh'] ?? null,
                'current_reading_water' => $_POST['current_reading_water'] ?? null,
                'previous_reading_water' => $_POST['previous_reading_water'] ?? null,
                'rate_per_water' => $_POST['rate_per_water'] ?? null,
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ]);
            $push = frs_uman_push_utility_reading($pdo, $readingId);
            $message = $push['success']
                ? 'Reading corrected and re-sent to UMAN.'
                : 'Reading corrected. Send to UMAN pending: ' . (string)$push['error'];
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to correct reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'delete_utility_reading' && $canDeleteReadings && $hasReadingTables) {
        $readingId = (int)($_POST['reading_id'] ?? 0);
        try {
            frs_energy_delete_reading($pdo, $readingId);
            $message = 'Reading deleted.';
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to delete reading: ' . $e->getMessage();
            $messageType = 'error';
        }
```

- [ ] **Step 3: Fetch data for the section**

Right before `ob_start();`, add:

```php
$utilityFacilities = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$utilityLatestReadings = [];
if ($hasReadingTables) {
    $utilityRows = $pdo->query('
        SELECT r.*, f.name AS facility_name, u.name AS recorded_by_name
        FROM energy_meter_readings r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN users u ON u.id = r.recorded_by
        ORDER BY r.year DESC, r.month DESC, r.id DESC
        LIMIT 200
    ')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($utilityRows as $row) {
        $fid = (int)$row['facility_id'];
        if (!isset($utilityLatestReadings[$fid])) {
            $utilityLatestReadings[$fid] = $row;
        }
    }
}
$utilityEditReadingId = (int)($_GET['edit_reading'] ?? 0);
$utilityEditReading = null;
$utilityEditReadingIsOnly = false;
if ($hasReadingTables && $utilityEditReadingId > 0 && $canUpdateReadings) {
    foreach ($utilityLatestReadings as $r) {
        if ((int)$r['id'] === $utilityEditReadingId) {
            $utilityEditReading = $r;
            break;
        }
    }
    if ($utilityEditReading !== null) {
        $onlyStmt = $pdo->prepare('SELECT COUNT(*) FROM energy_meter_readings WHERE facility_id = :facility_id AND id != :id');
        $onlyStmt->execute(['facility_id' => (int)$utilityEditReading['facility_id'], 'id' => $utilityEditReadingId]);
        $utilityEditReadingIsOnly = (int)$onlyStmt->fetchColumn() === 0;
    }
}
$utilityMonthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
```

- [ ] **Step 4: Render the section**

Add this new section right before the closing `<section class="booking-card" style="margin-top:1.5rem;"><h2>Asset Requests</h2>` section (i.e. insert it between the Asset Catalog section and the Asset Requests section — search for `<h2>Asset Requests</h2>` and insert immediately before its enclosing `<section` tag):

```php
<section class="booking-card" style="margin-top:1.5rem;">
    <h2>💧⚡ Utility Readings (Electric &amp; Water)</h2>
    <p style="color:#8b95b5; margin-bottom:1rem;">
        Monthly readings sent to UMAN for consumption monitoring — UMAN forwards them to the LGU Energy system.
        One reading per facility per month.
    </p>

    <?php if (!$hasReadingTables): ?>
        <p style="color:#8b95b5;">Run <code>database/migration_add_energy_integration.sql</code> and <code>database/migration_add_water_readings.sql</code> to enable utility readings.</p>
    <?php else: ?>

    <?php if ($utilityEditReading !== null): ?>
        <div class="booking-form" style="margin-bottom:1.5rem; padding:1rem; border:1px solid #e0e6ed; border-radius:8px;">
            <h3 style="margin-top:0;">Edit Reading</h3>
            <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/utilities-integration'); ?>" class="booking-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update_utility_reading">
                <input type="hidden" name="reading_id" value="<?= (int)$utilityEditReading['id']; ?>">
                <label>
                    Facility
                    <input type="text" value="<?= htmlspecialchars((string)$utilityEditReading['facility_name']); ?>" readonly style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px; background:#f4f6fa;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars((string)$utilityEditReading['reading_date']); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <fieldset style="margin-top:1rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>⚡ Electricity</legend>
                    <label>
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" value="<?= htmlspecialchars((string)$utilityEditReading['previous_reading_kwh']); ?>" <?= $utilityEditReadingIsOnly ? '' : 'readonly'; ?> style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" required value="<?= htmlspecialchars((string)$utilityEditReading['current_reading_kwh']); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" required value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_kwh'] ?? 14.83), 2, '.', '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                </fieldset>
                <fieldset style="margin-top:0.75rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>💧 Water</legend>
                    <label>
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['previous_reading_water'] ?? '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['current_reading_water'] ?? '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_water'] ?? 68.02), 2, '.', '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                </fieldset>
                <label style="margin-top:0.75rem; display:block;">
                    Notes (optional)
                    <textarea name="notes" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"><?= htmlspecialchars((string)($utilityEditReading['notes'] ?? '')); ?></textarea>
                </label>
                <div style="margin-top:1rem; display:flex; gap:0.75rem; align-items:center;">
                    <button type="submit" class="btn-primary">Save Correction</button>
                    <a href="<?= htmlspecialchars(base_path() . '/dashboard/utilities-integration'); ?>">Cancel</a>
                </div>
            </form>
        </div>
    <?php elseif ($canCreateReadings): ?>
        <div class="booking-form" style="margin-bottom:1.5rem; padding:1rem; border:1px solid #e0e6ed; border-radius:8px;">
            <h3 style="margin-top:0;">Add Reading</h3>
            <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/utilities-integration'); ?>" class="booking-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="add_utility_reading">
                <label>
                    Facility
                    <select name="facility_id" id="utility-facility-select" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <option value="">— Select facility —</option>
                        <?php foreach ($utilityFacilities as $f): ?>
                            <?php $last = $utilityLatestReadings[(int)$f['id']] ?? null; ?>
                            <option value="<?= (int)$f['id']; ?>"
                                data-prev-kwh="<?= $last !== null ? htmlspecialchars((string)$last['current_reading_kwh']) : ''; ?>"
                                data-rate-kwh="<?= $last !== null ? htmlspecialchars((string)($last['rate_per_kwh'] ?? '14.83')) : '14.83'; ?>"
                                data-prev-water="<?= ($last !== null && $last['current_reading_water'] !== null) ? htmlspecialchars((string)$last['current_reading_water']) : ''; ?>"
                                data-rate-water="<?= ($last !== null && $last['rate_per_water'] !== null) ? htmlspecialchars((string)$last['rate_per_water']) : '68.02'; ?>">
                                <?= htmlspecialchars($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Month
                    <input type="month" name="reading_month" required value="<?= htmlspecialchars(date('Y-m')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars(date('Y-m-d')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <fieldset style="margin-top:1rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>⚡ Electricity</legend>
                    <label>
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" id="utility-prev-kwh" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Auto-filled and locked when the facility already has a reading.</small>
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" id="utility-curr-kwh" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" id="utility-rate-kwh" required value="14.83" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Meralco residential all-in rate, July 2026 — adjust to the current tariff.</small>
                    </label>
                </fieldset>
                <fieldset style="margin-top:0.75rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>💧 Water (optional)</legend>
                    <label>
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" id="utility-prev-water" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Auto-filled and locked when the facility already has a water reading.</small>
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" id="utility-curr-water" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" id="utility-rate-water" value="68.02" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Manila Water East Zone (Quezon City), Q2 2026 tier — adjust to the current tariff.</small>
                    </label>
                </fieldset>
                <p id="utility-consumption-preview" style="margin-top:0.75rem; color:#0066cc; font-weight:600;"></p>
                <label style="margin-top:0.75rem; display:block;">
                    Notes (optional)
                    <textarea name="notes" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn-primary" style="margin-top:1rem;">Save Reading</button>
            </form>
            <script>
            (function () {
                'use strict';
                var sel = document.getElementById('utility-facility-select');
                var prevKwh = document.getElementById('utility-prev-kwh');
                var currKwh = document.getElementById('utility-curr-kwh');
                var rateKwh = document.getElementById('utility-rate-kwh');
                var prevWater = document.getElementById('utility-prev-water');
                var currWater = document.getElementById('utility-curr-water');
                var rateWater = document.getElementById('utility-rate-water');
                var preview = document.getElementById('utility-consumption-preview');
                if (!sel || !prevKwh || !currKwh || !rateKwh || !preview) return;
                function updatePreview() {
                    var lines = [];
                    var pk = parseFloat(prevKwh.value), ck = parseFloat(currKwh.value), rk = parseFloat(rateKwh.value);
                    if (!isNaN(pk) && !isNaN(ck) && ck >= pk) {
                        lines.push('Electric: ' + (ck - pk).toFixed(2) + ' kWh' + (!isNaN(rk) && rk > 0 ? ' | PHP ' + ((ck - pk) * rk).toFixed(2) : ''));
                    }
                    var pw = parseFloat(prevWater.value), cw = parseFloat(currWater.value), rw = parseFloat(rateWater.value);
                    if (!isNaN(pw) && !isNaN(cw) && cw >= pw) {
                        lines.push('Water: ' + (cw - pw).toFixed(2) + ' m³' + (!isNaN(rw) && rw > 0 ? ' | PHP ' + ((cw - pw) * rw).toFixed(2) : ''));
                    }
                    preview.innerHTML = lines.join('<br>');
                }
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    if (!opt) return;
                    var lastKwh = opt.getAttribute('data-prev-kwh');
                    if (lastKwh) { prevKwh.value = lastKwh; prevKwh.readOnly = true; }
                    else { prevKwh.value = ''; prevKwh.readOnly = false; }
                    rateKwh.value = opt.getAttribute('data-rate-kwh') || '14.83';

                    var lastWater = opt.getAttribute('data-prev-water');
                    if (lastWater) { prevWater.value = lastWater; prevWater.readOnly = true; }
                    else { prevWater.value = ''; prevWater.readOnly = false; }
                    rateWater.value = opt.getAttribute('data-rate-water') || '68.02';
                    updatePreview();
                });
                [prevKwh, currKwh, rateKwh, prevWater, currWater, rateWater].forEach(function (el) {
                    el.addEventListener('input', updatePreview);
                });
            })();
            </script>
        </div>
    <?php endif; ?>

    <?php if ($utilityLatestReadings === []): ?>
        <p style="color:#8b95b5; text-align:center; padding:2rem;">No utility readings recorded yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Facility</th><th>Period</th><th>Electric</th><th>Water</th><th>Sync</th><th>Recorded By</th>
                        <?php if ($canUpdateReadings || $canDeleteReadings): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($utilityLatestReadings as $r): ?>
                        <tr>
                            <td data-label="Facility"><?= htmlspecialchars((string)$r['facility_name']); ?></td>
                            <td data-label="Period"><?= htmlspecialchars(($utilityMonthNames[(int)$r['month']] ?? $r['month']) . ' ' . $r['year']); ?></td>
                            <td data-label="Electric"><?= number_format((float)$r['consumption_kwh'], 2); ?> kWh · PHP <?= number_format((float)$r['consumption_kwh'] * (float)($r['rate_per_kwh'] ?? 14.83), 2); ?></td>
                            <td data-label="Water">
                                <?php if ($r['current_reading_water'] !== null): ?>
                                    <?= number_format((float)$r['consumption_water'], 2); ?> m³ · PHP <?= number_format((float)$r['consumption_water'] * (float)($r['rate_per_water'] ?? 68.02), 2); ?>
                                <?php else: ?>
                                    <span style="color:#8b95b5;">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Sync">
                                <span class="status-badge <?= $r['sync_status'] === 'synced' ? 'active' : ($r['sync_status'] === 'failed' ? 'offline' : 'maintenance'); ?>"
                                      <?= $r['sync_error'] !== null ? 'title="' . htmlspecialchars((string)$r['sync_error']) . '"' : ''; ?>>
                                    <?= htmlspecialchars(ucfirst((string)$r['sync_status'])); ?>
                                </span>
                            </td>
                            <td data-label="Recorded By"><?= htmlspecialchars((string)($r['recorded_by_name'] ?? '—')); ?></td>
                            <?php if ($canUpdateReadings || $canDeleteReadings): ?>
                            <td data-label="Actions" style="white-space:nowrap;">
                                <?php if ($canUpdateReadings): ?>
                                    <a href="<?= htmlspecialchars(base_path() . '/dashboard/utilities-integration?edit_reading=' . (int)$r['id']); ?>" class="btn-secondary" style="padding:0.3rem 0.7rem; font-size:0.85rem;">Edit</a>
                                <?php endif; ?>
                                <?php if ($canDeleteReadings && $r['sync_status'] !== 'synced'): ?>
                                    <form method="POST" action="<?= htmlspecialchars(base_path() . '/dashboard/utilities-integration'); ?>" style="display:inline;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_utility_reading">
                                        <input type="hidden" name="reading_id" value="<?= (int)$r['id']; ?>">
                                        <button type="submit" class="btn-secondary" style="padding:0.3rem 0.7rem; font-size:0.85rem; color:#b23030;" onclick="return confirm('Delete this reading?')">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
```

- [ ] **Step 5: Lint**

Run: `php -l resources/views/pages/dashboard/utilities_integration.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/dashboard/utilities_integration.php
git commit -m "feat: add Utility Readings section to UMAN Integration page"
```

---

### Task 5: Remove the old Meter Readings tab from Energy Efficiency

**Files:**
- Modify: `resources/views/pages/dashboard/energy_efficiency.php`

- [ ] **Step 1: Remove the `add_reading`/`update_reading`/`delete_reading` POST branches**

Delete the three `elseif` branches for `'add_reading'`, `'update_reading'`, and `'delete_reading'` (lines ~47-121 in the current file — the whole chain from `} elseif ($_POST['action'] === 'add_reading' && $canCreate) {` through the closing of the `delete_reading` branch, right before `} elseif ($_POST['action'] === 'save_mapping' && $canUpdate) {`).

- [ ] **Step 2: Remove the Meter Readings tab nav link**

Find:

```php
<nav class="booking-hub-tabs" aria-label="Energy sections">
    <a class="booking-hub-tab <?= $tab === 'readings' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('readings')); ?>">Meter Readings</a>
    <a class="booking-hub-tab <?= $tab === 'recommendations' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('recommendations')); ?>">Recommendations</a>
```

Replace with:

```php
<nav class="booking-hub-tabs" aria-label="Energy sections">
    <a class="booking-hub-tab <?= $tab === 'recommendations' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('recommendations')); ?>">Recommendations</a>
```

Also change the default tab fallback from `$tab = (string)($_GET['tab'] ?? 'readings');` to `$tab = (string)($_GET['tab'] ?? 'recommendations');`, and remove `'readings'` from the `in_array($tab, ['readings', 'recommendations', 'mapping', 'profiles'], true)` allow-list (leaving `['recommendations', 'mapping', 'profiles']`), updating the fallback assignment below it from `$tab = 'readings';` to `$tab = 'recommendations';`.

- [ ] **Step 3: Remove the whole `<?php if ($tab === 'readings'): ?> ... <?php elseif ($tab === 'recommendations'): ?>` block's readings half**

Delete everything from `<?php if ($tab === 'readings'): ?>` through the line right before `<?php elseif ($tab === 'recommendations'): ?>`, and change that line to `<?php if ($tab === 'recommendations'): ?>` (since readings no longer exists as a branch).

- [ ] **Step 4: Update the Recommendations empty-state copy**

Find:

```php
                <p style="color:#8b95b5; text-align:center; padding:2rem;">No recommendations yet. Submit a monthly meter reading first.</p>
```

Replace with:

```php
                <p style="color:#8b95b5; text-align:center; padding:2rem;">No recommendations yet. Submit a monthly utility reading on the <a href="<?= htmlspecialchars(base_path() . '/dashboard/utilities-integration'); ?>">UMAN Integration page</a> first.</p>
```

- [ ] **Step 5: Clean up now-unused variables**

`$latestReadings`, `$pendingCount`, `$hasSyncedReadings`, `$facilitiesMissingThisMonth`, `$editReadingId`/`$editReading`/`$editReadingIsOnly`, and the `$rows` query that built them are only used by the removed tab **except** `$hasSyncedReadings` and `$latestReadings` are still referenced by the Recommendations tab's empty-state branch (`<?php if ($hasSyncedReadings): ?>`) and `$approvedRecommendationPeriods` computation loops over `$rows`. Keep the block that computes `$rows`/`$latestReadings`/`$hasSyncedReadings`/`$approvedRecommendationPeriods` (still needed), but delete only `$facilitiesMissingThisMonth`'s computation and the `$editReadingId`/`$editReading`/`$editReadingIsOnly` block (lines ~200-241 in the current file — the `$curYear`/`$curMonth`/`$readFacilityIdsThisMonth`/`$facilitiesMissingThisMonth` block and the `// Edit-reading target` block), since nothing left on the page reads them.

- [ ] **Step 6: Lint**

Run: `php -l resources/views/pages/dashboard/energy_efficiency.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add resources/views/pages/dashboard/energy_efficiency.php
git commit -m "feat: remove Meter Readings tab from Energy Efficiency page"
```

---

## Explicitly out of scope for this plan

- UMAN actually implementing `/api/utility-readings.php` — external system, not our codebase. Until deployed, every push saves locally as `pending`/`failed`, same graceful-degrade behavior the Energy push always had.
- A real tiered electricity/water bill calculator — both rates stay simple editable defaults.
- Backfilling water data for facilities' historical electric-only reading rows.
