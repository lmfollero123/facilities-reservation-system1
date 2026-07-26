# CPRF Facility Profile Sync + UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pull each mapped facility's energy profile from the Energy system's new `GET /api/v1/cprf/facility-profiles` endpoint into a local cache, wire the pull into the existing Sync Now/cron cycle, and display it on a new "Facility Profiles" tab on the Energy Efficiency page.

**Architecture:** Mirrors the existing recommendations pull exactly: a thin API client function, a PDO-backed pull/upsert helper called from `frs_energy_run_sync()`, and a read-only display tab. No new button, no new schedule — profiles refresh whenever readings/recommendations already do.

**Tech Stack:** Plain PHP (no framework), PDO with prepared statements, PHPUnit, MySQL/MariaDB.

## Global Constraints

- This plan depends on `INTEGRATION/Lgu1-energy`'s `GET /api/v1/cprf/facility-profiles` endpoint existing (companion plan: `docs/superpowers/plans/2026-07-26-energy-app-reading-visibility-and-profiles-endpoint.md`, Task 1). If that endpoint isn't deployed yet, this plan's code still works correctly — failed pulls are caught and logged without blocking the rest of sync (see Task 2).
- Follow the existing file-header convention: `declare(strict_types=1);` at the top of any new/modified PHP file that doesn't already have it (services/energy_api.php and config/energy_helper.php both already declare it).
- `energy_helper.php`'s existing convention: pure functions (no PDO) go first and get unit tests in `tests/Unit/EnergyHelperTest.php`; PDO-backed orchestration functions are NOT unit tested in this codebase (confirmed: `frs_energy_pull_recommendations()`, the direct precedent for this feature, has zero unit tests) — they're covered by manual UAT instead. Follow this same split: extract a pure row-transform function and unit test *that*; leave the PDO orchestration wrapper for manual verification.
- No new permission key needed — the existing `energy` permission (Admin/Staff read, Resident none) already gates the whole Energy Efficiency page including the new tab.
- No new CSS: reuse the already-defined `.status-badge.approved` (green) and `.status-badge.pending` (yellow) classes from `public/css/style.css:5376,5382` for the engineer-approval pill, and `.status-badge.admin` (purple, `public/css/style.css:5447`) for the "Baseline Locked" pill. Do not add new classes or touch the CSS files.

---

### Task 1: Database migration — `energy_profile_cache` table + sync-state column

**Files:**
- Create: `database/migration_add_energy_profile_cache.sql`

**Interfaces:**
- Produces: table `energy_profile_cache` (columns: `id`, `facility_id` UNIQUE FK→facilities, `electric_meter_no`, `utility_provider`, `contract_account_no`, `main_energy_source`, `backup_power`, `transformer_capacity`, `number_of_meters`, `baseline_kwh`, `engineer_approved`, `baseline_locked`, `baseline_source`, `energy_updated_at`, `synced_at`, `created_at`, `updated_at`); and `energy_sync_state.last_profile_pull_at` (new nullable DATETIME column).

- [ ] **Step 1: Create the migration file**

Create `database/migration_add_energy_profile_cache.sql`:

```sql
-- Energy facility profile cache: read-only mirror of the Energy system's
-- per-facility EnergyProfile, pulled via GET /api/v1/cprf/facility-profiles
-- inside the existing Sync Now / cron cycle. Safe to re-run.

USE facilities_reservation;

CREATE TABLE IF NOT EXISTS energy_profile_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    electric_meter_no VARCHAR(100) NULL,
    utility_provider VARCHAR(100) NULL,
    contract_account_no VARCHAR(100) NULL,
    main_energy_source VARCHAR(100) NULL,
    backup_power VARCHAR(100) NULL,
    transformer_capacity VARCHAR(100) NULL,
    number_of_meters INT UNSIGNED NULL,
    baseline_kwh DECIMAL(14,2) NULL,
    engineer_approved TINYINT(1) NOT NULL DEFAULT 0,
    baseline_locked TINYINT(1) NOT NULL DEFAULT 0,
    baseline_source VARCHAR(100) NULL,
    energy_updated_at DATETIME NULL,
    synced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_profile_cache_facility (facility_id),
    CONSTRAINT fk_energy_profile_cache_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE energy_sync_state
    ADD COLUMN IF NOT EXISTS last_profile_pull_at DATETIME NULL AFTER last_pull_at;
```

- [ ] **Step 2: Run the migration against the local database**

Run: `mysql -u root -p facilities_reservation < database/migration_add_energy_profile_cache.sql`
Expected: no errors. Verify with: `mysql -u root -p facilities_reservation -e "DESCRIBE energy_profile_cache; DESCRIBE energy_sync_state;"` — confirm `energy_profile_cache` exists with all 16 columns, and `energy_sync_state` now has `last_profile_pull_at`.

- [ ] **Step 3: Commit**

```bash
git add database/migration_add_energy_profile_cache.sql
git commit -m "Add energy_profile_cache table and sync-state column for facility profile pull"
```

---

### Task 2: API client + pull helper + sync wiring

**Files:**
- Modify: `services/energy_api.php` (add `fetchEnergyFacilityProfiles()`)
- Modify: `config/energy_helper.php` (add `frs_energy_parse_profile_row()`, `frs_energy_pull_profiles()`; update `frs_energy_run_sync()` and `frs_energy_load_sync_state()`)
- Test: `tests/Unit/EnergyHelperTest.php` (add tests for `frs_energy_parse_profile_row()`)

**Interfaces:**
- Consumes: `GET /api/v1/cprf/facility-profiles` (Task 1 of the companion Energy-app plan), `frs_energy_load_sync_state()` (existing), `energy_profile_cache` table (Task 1 of this plan).
- Produces: `fetchEnergyFacilityProfiles(array $query = []): array{success: bool, data: ?array, error: ?string, http_code: int}`; `frs_energy_parse_profile_row(array $row): ?array` (pure, unit tested); `frs_energy_pull_profiles(PDO $pdo): array{success: bool, upserted: int, error: ?string}`; `frs_energy_run_sync()`'s summary array gains a `profiles_upserted` key; `frs_energy_load_sync_state()`'s return array gains a `last_profile_pull_at` key.

- [ ] **Step 1: Write the failing unit tests for the pure row-transform function**

In `tests/Unit/EnergyHelperTest.php`, add these test methods inside the existing `EnergyHelperTest` class (after `test_build_reading_payload_maps_local_row`):

```php
    public function test_parse_profile_row_maps_all_fields(): void
    {
        $row = [
            'facility_external_ref' => 501,
            'energy_facility_id' => 14,
            'electric_meter_no' => 'MTR-0042',
            'utility_provider' => 'Meralco',
            'contract_account_no' => '1234-5678',
            'main_energy_source' => 'Grid',
            'backup_power' => 'Generator',
            'transformer_capacity' => '75 kVA',
            'number_of_meters' => 3,
            'baseline_kwh' => '7820.00',
            'engineer_approved' => true,
            'baseline_locked' => true,
            'baseline_source' => 'Manual entry',
            'updated_at' => '2026-07-26T08:00:00+00:00',
        ];

        $parsed = frs_energy_parse_profile_row($row);

        $this->assertSame(501, $parsed['facility_id']);
        $this->assertSame('MTR-0042', $parsed['electric_meter_no']);
        $this->assertSame('Meralco', $parsed['utility_provider']);
        $this->assertSame('1234-5678', $parsed['contract_account_no']);
        $this->assertSame('Grid', $parsed['main_energy_source']);
        $this->assertSame('Generator', $parsed['backup_power']);
        $this->assertSame('75 kVA', $parsed['transformer_capacity']);
        $this->assertSame(3, $parsed['number_of_meters']);
        $this->assertSame(7820.0, $parsed['baseline_kwh']);
        $this->assertTrue($parsed['engineer_approved']);
        $this->assertTrue($parsed['baseline_locked']);
        $this->assertSame('Manual entry', $parsed['baseline_source']);
        $this->assertSame('2026-07-26 08:00:00', $parsed['energy_updated_at']);
    }

    public function test_parse_profile_row_handles_null_optional_fields(): void
    {
        $row = [
            'facility_external_ref' => 501,
            'electric_meter_no' => null,
            'utility_provider' => null,
            'contract_account_no' => null,
            'main_energy_source' => null,
            'backup_power' => null,
            'transformer_capacity' => null,
            'number_of_meters' => null,
            'baseline_kwh' => null,
            'engineer_approved' => false,
            'baseline_locked' => false,
            'baseline_source' => null,
            'updated_at' => null,
        ];

        $parsed = frs_energy_parse_profile_row($row);

        $this->assertSame(501, $parsed['facility_id']);
        $this->assertNull($parsed['electric_meter_no']);
        $this->assertNull($parsed['baseline_kwh']);
        $this->assertFalse($parsed['engineer_approved']);
        $this->assertFalse($parsed['baseline_locked']);
        $this->assertNull($parsed['energy_updated_at']);
    }

    public function test_parse_profile_row_returns_null_without_facility_external_ref(): void
    {
        $this->assertNull(frs_energy_parse_profile_row(['utility_provider' => 'Meralco']));
        $this->assertNull(frs_energy_parse_profile_row(['facility_external_ref' => 'not-a-number']));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/EnergyHelperTest.php`
Expected: FAIL — `frs_energy_parse_profile_row` is not defined.

- [ ] **Step 3: Add `frs_energy_parse_profile_row()` to `config/energy_helper.php`**

Add this function after `frs_energy_build_reading_payload()` (after line 107), before `frs_energy_tables_exist()`:

```php
/**
 * Transform one facility-profiles API row into an energy_profile_cache
 * upsert-ready row. Pure — no PDO — so the field-mapping logic is unit
 * tested independently of the pull orchestration in frs_energy_pull_profiles().
 *
 * @param array<string, mixed> $row one row from GET /api/v1/cprf/facility-profiles
 * @return array{facility_id: int, electric_meter_no: ?string, utility_provider: ?string,
 *   contract_account_no: ?string, main_energy_source: ?string, backup_power: ?string,
 *   transformer_capacity: ?string, number_of_meters: ?int, baseline_kwh: ?float,
 *   engineer_approved: bool, baseline_locked: bool, baseline_source: ?string,
 *   energy_updated_at: ?string}|null null when the row has no usable facility_external_ref
 */
function frs_energy_parse_profile_row(array $row): ?array
{
    if (!isset($row['facility_external_ref']) || !is_numeric($row['facility_external_ref'])) {
        return null;
    }

    return [
        'facility_id' => (int)$row['facility_external_ref'],
        'electric_meter_no' => isset($row['electric_meter_no']) && $row['electric_meter_no'] !== null ? (string)$row['electric_meter_no'] : null,
        'utility_provider' => isset($row['utility_provider']) && $row['utility_provider'] !== null ? (string)$row['utility_provider'] : null,
        'contract_account_no' => isset($row['contract_account_no']) && $row['contract_account_no'] !== null ? (string)$row['contract_account_no'] : null,
        'main_energy_source' => isset($row['main_energy_source']) && $row['main_energy_source'] !== null ? (string)$row['main_energy_source'] : null,
        'backup_power' => isset($row['backup_power']) && $row['backup_power'] !== null ? (string)$row['backup_power'] : null,
        'transformer_capacity' => isset($row['transformer_capacity']) && $row['transformer_capacity'] !== null ? (string)$row['transformer_capacity'] : null,
        'number_of_meters' => isset($row['number_of_meters']) && is_numeric($row['number_of_meters']) ? (int)$row['number_of_meters'] : null,
        'baseline_kwh' => isset($row['baseline_kwh']) && is_numeric($row['baseline_kwh']) ? (float)$row['baseline_kwh'] : null,
        'engineer_approved' => (bool)($row['engineer_approved'] ?? false),
        'baseline_locked' => (bool)($row['baseline_locked'] ?? false),
        'baseline_source' => isset($row['baseline_source']) && $row['baseline_source'] !== null ? (string)$row['baseline_source'] : null,
        'energy_updated_at' => isset($row['updated_at']) && $row['updated_at'] !== null
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at']))
            : null,
    ];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/EnergyHelperTest.php`
Expected: PASS (all tests, including the 3 new ones)

- [ ] **Step 5: Add `fetchEnergyFacilityProfiles()` to `services/energy_api.php`**

Add this function after `fetchEnergyRecommendations()` (after line 154), before `pushEnergyFacilityReading()`:

```php
/**
 * Fetch facility energy profiles (raw Laravel paginator array in 'data').
 *
 * @param array<string, mixed> $query e.g. ['updated_since' => '...', 'page' => 1, 'per_page' => 100]
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function fetchEnergyFacilityProfiles(array $query = []): array
{
    return energy_api_request('GET', '/api/v1/cprf/facility-profiles', null, $query);
}
```

- [ ] **Step 6: Add `frs_energy_pull_profiles()` to `config/energy_helper.php`**

Add this function after `frs_energy_pull_recommendations()` (after line 587), before `frs_energy_run_sync()`:

```php
/**
 * Pull facility energy profiles (updated_since watermark) into the local
 * cache. Unlike frs_energy_pull_recommendations(), no reverse facility-id
 * mapping lookup is needed: the Energy endpoint already scopes its response
 * to CPRF-mapped facilities and returns facility_external_ref, which IS the
 * CPRF facility_id directly.
 *
 * @return array{success: bool, upserted: int, error: ?string}
 */
function frs_energy_pull_profiles(PDO $pdo): array
{
    $state = frs_energy_load_sync_state($pdo);
    $query = ['per_page' => 100];
    if (!empty($state['last_profile_pull_at'])) {
        $query['updated_since'] = $state['last_profile_pull_at'];
    }

    $upserted = 0;
    $maxUpdatedAt = null;
    $page = 1;
    do {
        $query['page'] = $page;
        $result = fetchEnergyFacilityProfiles($query);
        if (!$result['success']) {
            return ['success' => false, 'upserted' => $upserted, 'error' => $result['error']];
        }
        $rows = $result['data']['data'] ?? [];
        $stmt = $pdo->prepare('
            INSERT INTO energy_profile_cache
                (facility_id, electric_meter_no, utility_provider, contract_account_no,
                 main_energy_source, backup_power, transformer_capacity, number_of_meters,
                 baseline_kwh, engineer_approved, baseline_locked, baseline_source,
                 energy_updated_at, synced_at)
            VALUES
                (:facility_id, :electric_meter_no, :utility_provider, :contract_account_no,
                 :main_energy_source, :backup_power, :transformer_capacity, :number_of_meters,
                 :baseline_kwh, :engineer_approved, :baseline_locked, :baseline_source,
                 :energy_updated_at, NOW())
            ON DUPLICATE KEY UPDATE
                electric_meter_no = VALUES(electric_meter_no),
                utility_provider = VALUES(utility_provider),
                contract_account_no = VALUES(contract_account_no),
                main_energy_source = VALUES(main_energy_source),
                backup_power = VALUES(backup_power),
                transformer_capacity = VALUES(transformer_capacity),
                number_of_meters = VALUES(number_of_meters),
                baseline_kwh = VALUES(baseline_kwh),
                engineer_approved = VALUES(engineer_approved),
                baseline_locked = VALUES(baseline_locked),
                baseline_source = VALUES(baseline_source),
                energy_updated_at = VALUES(energy_updated_at),
                synced_at = NOW()
        ');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = frs_energy_parse_profile_row($row);
            if ($parsed === null) {
                continue;
            }
            $stmt->execute([
                'facility_id' => $parsed['facility_id'],
                'electric_meter_no' => $parsed['electric_meter_no'],
                'utility_provider' => $parsed['utility_provider'],
                'contract_account_no' => $parsed['contract_account_no'],
                'main_energy_source' => $parsed['main_energy_source'],
                'backup_power' => $parsed['backup_power'],
                'transformer_capacity' => $parsed['transformer_capacity'],
                'number_of_meters' => $parsed['number_of_meters'],
                'baseline_kwh' => $parsed['baseline_kwh'],
                'engineer_approved' => $parsed['engineer_approved'] ? 1 : 0,
                'baseline_locked' => $parsed['baseline_locked'] ? 1 : 0,
                'baseline_source' => $parsed['baseline_source'],
                'energy_updated_at' => $parsed['energy_updated_at'],
            ]);
            $upserted++;

            if ($parsed['energy_updated_at'] !== null) {
                if ($maxUpdatedAt === null || strtotime($parsed['energy_updated_at']) > strtotime($maxUpdatedAt)) {
                    $maxUpdatedAt = $parsed['energy_updated_at'];
                }
            }
        }
        $hasNext = !empty($result['data']['next_page_url']);
        $page++;
    } while ($hasNext && $page <= 10);

    if ($maxUpdatedAt !== null) {
        $pdo->prepare('UPDATE energy_sync_state SET last_profile_pull_at = :w WHERE id = 1')->execute(['w' => $maxUpdatedAt]);
    }

    return ['success' => true, 'upserted' => $upserted, 'error' => null];
}
```

- [ ] **Step 7: Wire the profile pull into `frs_energy_run_sync()`**

In `config/energy_helper.php`, inside `frs_energy_run_sync()`, change:

```php
    $pull = frs_energy_pull_recommendations($pdo);
    if (!$pull['success'] && $pull['error']) {
        $errors[] = 'Recommendations pull: ' . $pull['error'];
    }

    // Read the previous run's failure streak before it's overwritten below,
```

to:

```php
    $pull = frs_energy_pull_recommendations($pdo);
    if (!$pull['success'] && $pull['error']) {
        $errors[] = 'Recommendations pull: ' . $pull['error'];
    }

    $profilePull = frs_energy_pull_profiles($pdo);
    if (!$profilePull['success'] && $profilePull['error']) {
        $errors[] = 'Facility profiles pull: ' . $profilePull['error'];
    }

    // Read the previous run's failure streak before it's overwritten below,
```

Then update the `$summary` array a few lines below (currently `'recommendations_upserted' => $pull['upserted'],`) to add the new key immediately after it:

```php
        'recommendations_upserted' => $pull['upserted'],
        'profiles_upserted' => $profilePull['upserted'],
```

And update the trailing `logAudit()` call at the end of the function from:

```php
    logAudit('Ran Energy integration sync', 'Energy Efficiency', "pushed={$pushed} failed={$pushFailed} recos={$pull['upserted']}");
```

to:

```php
    logAudit('Ran Energy integration sync', 'Energy Efficiency', "pushed={$pushed} failed={$pushFailed} recos={$pull['upserted']} profiles={$profilePull['upserted']}");
```

- [ ] **Step 8: Extend `frs_energy_load_sync_state()` to return `last_profile_pull_at`**

Change:

```php
/** @return array{last_pull_at: ?string, last_push_at: ?string, last_summary: ?array} */
function frs_energy_load_sync_state(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT last_pull_at, last_push_at, last_summary FROM energy_sync_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }
    if ($row === false) {
        return ['last_pull_at' => null, 'last_push_at' => null, 'last_summary' => null];
    }
    $summary = null;
    if (!empty($row['last_summary'])) {
        $decoded = json_decode((string)$row['last_summary'], true);
        $summary = is_array($decoded) ? $decoded : null;
    }
    return [
        'last_pull_at' => $row['last_pull_at'] ?: null,
        'last_push_at' => $row['last_push_at'] ?: null,
        'last_summary' => $summary,
    ];
}
```

to:

```php
/** @return array{last_pull_at: ?string, last_push_at: ?string, last_profile_pull_at: ?string, last_summary: ?array} */
function frs_energy_load_sync_state(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT last_pull_at, last_push_at, last_profile_pull_at, last_summary FROM energy_sync_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }
    if ($row === false) {
        return ['last_pull_at' => null, 'last_push_at' => null, 'last_profile_pull_at' => null, 'last_summary' => null];
    }
    $summary = null;
    if (!empty($row['last_summary'])) {
        $decoded = json_decode((string)$row['last_summary'], true);
        $summary = is_array($decoded) ? $decoded : null;
    }
    return [
        'last_pull_at' => $row['last_pull_at'] ?: null,
        'last_push_at' => $row['last_push_at'] ?: null,
        'last_profile_pull_at' => $row['last_profile_pull_at'] ?: null,
        'last_summary' => $summary,
    ];
}
```

- [ ] **Step 9: Run the full unit test suite to confirm nothing else broke**

Run: `./vendor/bin/phpunit tests/Unit`
Expected: PASS (all tests)

- [ ] **Step 10: Commit**

```bash
git add services/energy_api.php config/energy_helper.php tests/Unit/EnergyHelperTest.php
git commit -m "Add facility profile pull, wired into the existing Energy sync cycle"
```

---

### Task 3: "Facility Profiles" tab on the Energy Efficiency page

**Files:**
- Modify: `resources/views/pages/dashboard/energy_efficiency.php`

**Interfaces:**
- Consumes: `energy_profile_cache` table (Task 1), `energy_facility_map` table (existing, via `frs_energy_get_mapping()`).
- Produces: a new `profiles` tab, reachable at `?tab=profiles`, rendered for anyone who can read the `energy` module (no `$canUpdate` gate, matching the existing Recommendations tab's visibility).

- [ ] **Step 1: Add `profiles` to the tab whitelist**

Change line 39:

```php
if (!in_array($tab, ['readings', 'recommendations', 'mapping'], true)) {
```

to:

```php
if (!in_array($tab, ['readings', 'recommendations', 'mapping', 'profiles'], true)) {
```

- [ ] **Step 2: Add the tab nav link**

In the `<nav class="booking-hub-tabs" ...>` block (currently lines 299-305), add a new tab link after Recommendations and before the `$canUpdate` mapping tab:

```php
<nav class="booking-hub-tabs" aria-label="Energy sections">
    <a class="booking-hub-tab <?= $tab === 'readings' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('readings')); ?>">Meter Readings</a>
    <a class="booking-hub-tab <?= $tab === 'recommendations' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('recommendations')); ?>">Recommendations</a>
    <a class="booking-hub-tab <?= $tab === 'profiles' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('profiles')); ?>">Facility Profiles</a>
    <?php if ($canUpdate): ?>
        <a class="booking-hub-tab <?= $tab === 'mapping' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a>
    <?php endif; ?>
</nav>
```

- [ ] **Step 3: Load the profile data when the tab is active**

After the existing `$energyFacilities` block (currently lines 239-245, right before `$monthNames = [...]` at line 247), add:

```php
$profiles = [];
if ($hasTables && $tab === 'profiles') {
    $profiles = $pdo->query('
        SELECT f.id AS facility_id, f.name AS facility_name,
               p.electric_meter_no, p.utility_provider, p.contract_account_no, p.main_energy_source,
               p.backup_power, p.transformer_capacity, p.number_of_meters, p.baseline_kwh,
               p.engineer_approved, p.baseline_locked, p.baseline_source, p.energy_updated_at
        FROM facilities f
        JOIN energy_facility_map m ON m.facility_id = f.id
        LEFT JOIN energy_profile_cache p ON p.facility_id = f.id
        ORDER BY f.name
    ')->fetchAll(PDO::FETCH_ASSOC);
}
```

- [ ] **Step 4: Render the tab content**

Immediately after the `<?php elseif ($tab === 'mapping' && $canUpdate): ?>` block's closing `</section>` (currently right before line 587's `<?php endif; ?>`), insert a new tab branch. Change:

```php
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
```

to:

```php
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'profiles'): ?>
    <section class="booking-card">
        <h2>Facility Energy Profiles</h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">
            Utility, meter, and baseline details configured for each facility by the LGU Energy team. This data is read-only here and refreshes automatically whenever a sync runs.
            <?php if ($canUpdate): ?>
                Only facilities mapped to the Energy system are listed below — see <a href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a> to map more.
            <?php else: ?>
                Only facilities mapped to the Energy system are listed below.
            <?php endif; ?>
        </p>
        <?php if ($profiles === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No facilities are mapped to the Energy system yet. Set up mapping first under Facility Mapping.</p>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:1rem;">
                <?php foreach ($profiles as $p): ?>
                    <?php $hasProfile = $p['utility_provider'] !== null || $p['baseline_kwh'] !== null || $p['electric_meter_no'] !== null; ?>
                    <article class="booking-card" style="margin:0;">
                        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:baseline; justify-content:space-between; margin-bottom:0.6rem;">
                            <strong><?= htmlspecialchars((string)$p['facility_name']); ?></strong>
                            <?php if ($hasProfile): ?>
                                <span>
                                    <span class="status-badge <?= $p['engineer_approved'] ? 'approved' : 'pending'; ?>"><?= $p['engineer_approved'] ? 'Approved' : 'Pending Approval'; ?></span>
                                    <?php if ($p['baseline_locked']): ?>
                                        <span class="status-badge admin">Baseline Locked</span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$hasProfile): ?>
                            <p style="color:#8b95b5; margin:0;">No energy profile set yet — the Energy team hasn't configured this facility.</p>
                        <?php else: ?>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.4rem 1rem; font-size:0.9rem;">
                                <div><span style="color:#8b95b5;">Utility Provider</span><br><?= htmlspecialchars((string)($p['utility_provider'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Contract Acct.</span><br><?= htmlspecialchars((string)($p['contract_account_no'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Electric Meter No.</span><br><?= htmlspecialchars((string)($p['electric_meter_no'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Main Energy Source</span><br><?= htmlspecialchars((string)($p['main_energy_source'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Backup Power</span><br><?= htmlspecialchars((string)($p['backup_power'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Transformer Capacity</span><br><?= htmlspecialchars((string)($p['transformer_capacity'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Number of Meters</span><br><?= htmlspecialchars((string)($p['number_of_meters'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Baseline kWh</span><br><?= $p['baseline_kwh'] !== null ? number_format((float)$p['baseline_kwh'], 2) : '—'; ?></div>
                                <div><span style="color:#8b95b5;">Baseline Source</span><br><?= htmlspecialchars((string)($p['baseline_source'] ?? '—')); ?></div>
                            </div>
                            <p style="color:#8b95b5; font-size:0.8rem; margin:0.75rem 0 0;">Last updated from Energy: <?= htmlspecialchars((string)($p['energy_updated_at'] ?? 'never')); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
```

(Note: the outer `<?php if ($tab === 'mapping' && $canUpdate): ... elseif ($tab === 'profiles'): ... endif; ?>` chain means the `profiles` branch is reachable regardless of `$canUpdate`, matching the Recommendations tab's visibility — only the Facility Mapping tab itself is gated on `$canUpdate`.)

- [ ] **Step 5: Manually verify in a browser**

Start the local PHP server (see project's existing dev-server instructions), log in as Admin, navigate to Energy Efficiency → Facility Profiles tab. Confirm:
- A mapped facility with cached profile data shows all 9 fields plus the Approved/Pending and (if applicable) Baseline Locked pills.
- A mapped facility with no cached profile shows the "No energy profile set yet" empty state.
- Cards lay out in a responsive 2-column grid on desktop width and collapse to 1 column on narrow viewports.
- Toggle the browser/OS dark theme and confirm the cards remain readable (inherited from `.booking-card`'s existing dark-mode CSS — no new dark-mode rules needed since no new classes were introduced).

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/dashboard/energy_efficiency.php
git commit -m "Add Facility Profiles tab to the Energy Efficiency page"
```

---

## Final Verification

- [ ] Run `./vendor/bin/phpunit` (full suite) — all pass.
- [ ] End-to-end manual UAT (requires the companion Energy-app plan's Task 1 deployed): set/update a profile in the Energy app for a mapped facility → click "Sync Now" on the CPRF Energy Efficiency page → confirm it appears under Facility Profiles with correct fields and badges → toggle `engineer_approved` in the Energy app → Sync Now again → confirm the badge updates.
- [ ] Confirm a sync run when the Energy API is unreachable (e.g. temporarily set a wrong `ENERGY_API_TOKEN`) still completes the reading push step and shows a "Facility profiles pull: ..." error in the Sync Now result message, rather than aborting the whole sync.
