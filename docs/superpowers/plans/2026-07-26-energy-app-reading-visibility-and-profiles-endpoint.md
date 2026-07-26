# Energy App: Reading Visibility + CPRF Facility Profiles Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the Laravel "Energy" app (`INTEGRATION/Lgu1-energy`), surface CPRF-pushed facility-level meter readings in the app's own itemized views (they currently vanish silently), and expose a new read-only `GET /api/v1/cprf/facility-profiles` endpoint so CPRF can pull each mapped facility's `EnergyProfile`.

**Architecture:** Both changes extend the existing CPRF integration surface with no new middleware or auth: the new endpoint reuses the `cprf.integration` bearer-token middleware already guarding `/api/v1/cprf/*`, and the reading-visibility fix loosens existing `whereHas('meter', ...)` query filters to also admit rows with `meter_id IS NULL AND input_source = 'cprf'`, tagging them with a distinct badge wherever a meter name is shown.

**Tech Stack:** Laravel (PHP), Eloquent, Pest (Feature tests), Blade views, MySQL/MariaDB.

## Global Constraints

- Auth: reuse the existing `cprf.integration` middleware and `services.cprf_integration.token` config — no new secret, no new middleware.
- No schema changes: `energy_profiles` and `energy_records` already have every column needed; this plan is endpoint + query-filter + view work only.
- Reading-visibility fix scope is deliberately narrower than "every `whereHas('meter', ...)` in the codebase" — see Task 2's rationale for which of the 12 call sites are touched and which are intentionally left alone.
- All new/modified Feature tests use the existing Pest conventions: `config(['services.cprf_integration.token' => 'test-token']); $this->withToken('test-token')->getJson(...)` for API auth, `$this->actingAs(User::factory()->create(['role' => 'admin']))->get(...)` for web routes. `RefreshDatabase` is applied globally to all Feature tests via `tests/Pest.php` — do not add it per-file.
- `EnergyProfile::$fillable` does NOT include `engineer_approved` or `baseline_locked` — these can only be set via direct property assignment + `save()` (e.g. `$profile->engineer_approved = true; $profile->save();`), never via `::create([...])` or `->fill([...])`.

---

### Task 1: `GET /api/v1/cprf/facility-profiles` endpoint

**Files:**
- Create: `app/Http/Controllers/Api/CprfFacilityProfileController.php`
- Modify: `routes/api.php:5` (add import), `routes/api.php:62-66` (add route)
- Test: `tests/Feature/CprfFacilityProfileTest.php`
- Modify: `docs/integration-api.md` (append endpoint docs, same style as the existing CPRF section)

**Interfaces:**
- Produces: `GET /api/v1/cprf/facility-profiles?updated_since=<ISO8601>&page=<int>&per_page=<int>` → Laravel default paginator JSON (`data`, `links`, `meta`), each row shaped `{facility_external_ref: int, energy_facility_id: int, electric_meter_no: ?string, utility_provider: ?string, contract_account_no: ?string, main_energy_source: ?string, backup_power: ?string, transformer_capacity: ?string, number_of_meters: ?int, baseline_kwh: ?float, engineer_approved: bool, baseline_locked: bool, baseline_source: ?string, updated_at: ?string}`.

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/CprfFacilityProfileTest.php`:

```php
<?php

use App\Models\EnergyProfile;
use App\Models\Facility;

function makeCprfMappedFacility(array $overrides = []): Facility
{
    return Facility::factory()->create(array_merge([
        'source' => 'cprf',
    ], $overrides));
}

test('facility-profiles endpoint requires the cprf token', function () {
    config(['services.cprf_integration.token' => 'right-token']);

    $this->getJson('/api/v1/cprf/facility-profiles')->assertStatus(401);
});

test('facility-profiles returns only cprf-mapped facilities that have a profile', function () {
    config(['services.cprf_integration.token' => 'test-token']);

    $withProfile = makeCprfMappedFacility(['external_ref' => 501]);
    $profile = EnergyProfile::create([
        'facility_id' => $withProfile->id,
        'utility_provider' => 'Meralco',
        'contract_account_no' => '1234-5678',
        'baseline_kwh' => 7820,
        'main_energy_source' => 'Grid',
        'backup_power' => 'Generator',
        'number_of_meters' => 3,
    ]);
    $profile->engineer_approved = true;
    $profile->baseline_locked = true;
    $profile->save();

    makeCprfMappedFacility(['external_ref' => 502]); // no profile yet — must be omitted
    Facility::factory()->create(['source' => 'local']); // not cprf-mapped — must be omitted

    $response = $this->withToken('test-token')->getJson('/api/v1/cprf/facility-profiles');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.facility_external_ref'))->toBe(501)
        ->and($response->json('data.0.utility_provider'))->toBe('Meralco')
        ->and($response->json('data.0.contract_account_no'))->toBe('1234-5678')
        ->and($response->json('data.0.baseline_kwh'))->toBe(7820.0)
        ->and($response->json('data.0.engineer_approved'))->toBeTrue()
        ->and($response->json('data.0.baseline_locked'))->toBeTrue();
});

test('facility-profiles omits facilities with no external_ref even if source is cprf', function () {
    config(['services.cprf_integration.token' => 'test-token']);

    $facility = Facility::factory()->create(['source' => 'cprf', 'external_ref' => null]);
    EnergyProfile::create(['facility_id' => $facility->id, 'utility_provider' => 'Meralco']);

    $response = $this->withToken('test-token')->getJson('/api/v1/cprf/facility-profiles');

    expect($response->json('data'))->toHaveCount(0);
});

test('facility-profiles can be filtered by updated_since', function () {
    config(['services.cprf_integration.token' => 'test-token']);

    $facility = makeCprfMappedFacility(['external_ref' => 501]);
    EnergyProfile::create(['facility_id' => $facility->id, 'utility_provider' => 'Meralco']);

    $future = now()->addDay()->toIso8601String();
    $response = $this->withToken('test-token')
        ->getJson('/api/v1/cprf/facility-profiles?updated_since=' . urlencode($future));

    expect($response->json('data'))->toHaveCount(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest tests/Feature/CprfFacilityProfileTest.php`
Expected: FAIL — route `/api/v1/cprf/facility-profiles` does not exist (404), or class `CprfFacilityProfileController` not found.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/CprfFacilityProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CprfFacilityProfileController extends Controller
{
    /**
     * Energy profiles for CPRF-mapped facilities, keyed by CPRF's own
     * facility id (external_ref) so CPRF can resolve rows directly without
     * a name-matching step. Facilities with no energy profile set yet are
     * omitted from the response, not returned as an error.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'updated_since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Facility::query()
            ->where('source', 'cprf')
            ->whereNotNull('external_ref')
            ->whereHas('energyProfiles')
            ->when($request->filled('updated_since'), function (Builder $q) use ($request) {
                $q->whereHas('energyProfiles', function (Builder $pq) use ($request) {
                    $pq->where('updated_at', '>=', $request->date('updated_since'));
                });
            })
            ->with(['energyProfiles' => fn (Builder $q) => $q->latest()])
            ->orderBy('external_ref');

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginator = $query->paginate($perPage)->withQueryString();

        $paginator->getCollection()->transform(function (Facility $facility) {
            $profile = $facility->energyProfiles->first();

            return [
                'facility_external_ref' => (int) $facility->external_ref,
                'energy_facility_id' => $facility->id,
                'electric_meter_no' => $profile->electric_meter_no ?? null,
                'utility_provider' => $profile->utility_provider ?? null,
                'contract_account_no' => $profile->contract_account_no ?? null,
                'main_energy_source' => $profile->main_energy_source ?? null,
                'backup_power' => $profile->backup_power ?? null,
                'transformer_capacity' => $profile->transformer_capacity ?? null,
                'number_of_meters' => $profile->number_of_meters ?? null,
                'baseline_kwh' => $profile && $profile->baseline_kwh !== null ? (float) $profile->baseline_kwh : null,
                'engineer_approved' => (bool) ($profile->engineer_approved ?? false),
                'baseline_locked' => (bool) ($profile->baseline_locked ?? false),
                'baseline_source' => $profile->baseline_source ?? null,
                'updated_at' => $profile?->updated_at?->toIso8601String(),
            ];
        });

        return response()->json($paginator);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, add the import after line 5 (`use App\Http\Controllers\Api\CprfFacilityReadingController;`):

```php
use App\Http\Controllers\Api\CprfFacilityProfileController;
```

Then in the `v1/cprf` group (currently lines 62-66), add the new route after `facility-readings`:

```php
Route::prefix('v1/cprf')->middleware(['cprf.integration', 'throttle:60,1'])->group(function () {
    Route::get('/facilities', [IntegrationDataController::class, 'facilities']);
    Route::get('/recommendations', [IntegrationDataController::class, 'recommendations']);
    Route::post('/facility-readings', [CprfFacilityReadingController::class, 'store']);
    Route::get('/facility-profiles', [CprfFacilityProfileController::class, 'index']);
});
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest tests/Feature/CprfFacilityProfileTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Document the endpoint**

Open `docs/integration-api.md`, find the existing CPRF section (documenting `facility-readings` and `recommendations`), and append a matching entry for `facility-profiles`:

```markdown
### `GET /api/v1/cprf/facility-profiles`

Auth: same `cprf.integration` bearer token as the rest of this section.

Query params: `updated_since` (ISO 8601, optional), `page`, `per_page` (default 25, max 100).

Returns only facilities where `source = 'cprf'`, `external_ref` is set, and an `EnergyProfile` exists. Facilities without a profile yet are omitted, not errored.

Sample row:
```json
{
    "facility_external_ref": 501,
    "energy_facility_id": 14,
    "electric_meter_no": "MTR-0042",
    "utility_provider": "Meralco",
    "contract_account_no": "1234-5678",
    "main_energy_source": "Grid",
    "backup_power": "Generator",
    "transformer_capacity": "75 kVA",
    "number_of_meters": 3,
    "baseline_kwh": 7820.00,
    "engineer_approved": true,
    "baseline_locked": true,
    "baseline_source": "Manual entry",
    "updated_at": "2026-07-26T08:00:00+00:00"
}
```
```

- [ ] **Step 7: Commit**

```bash
cd INTEGRATION/Lgu1-energy
git add app/Http/Controllers/Api/CprfFacilityProfileController.php routes/api.php tests/Feature/CprfFacilityProfileTest.php docs/integration-api.md
git commit -m "Add GET /api/v1/cprf/facility-profiles endpoint for CPRF profile sync"
```

---

### Task 2: Surface CPRF facility-level readings in Monthly Records (query + badge)

**Files:**
- Modify: `routes/modules.php:126-130`
- Modify: `resources/views/modules/facilities/monthly-record/records.blade.php:1435-1438` and `:1507-1510`
- Test: `tests/Feature/CprfFacilityLevelReadingVisibilityTest.php`

**Interfaces:**
- Consumes: `EnergyRecord` rows where `meter_id IS NULL AND input_source = 'cprf'` (written by `CprfFacilityReadingController::store`, Task 1 of the companion CPRF-side plan already ships these).
- Produces: the Monthly Records page (`facilities.monthly-records` route) now includes these rows, each rendered with a `CPRF` scope pill and "Facility-Level (CPRF)" label instead of being silently excluded.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/CprfFacilityLevelReadingVisibilityTest.php`:

```php
<?php

use App\Models\EnergyRecord;
use App\Models\Facility;
use App\Models\User;

test('cprf facility-level readings appear in monthly records with a distinct badge', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $facility = Facility::factory()->create();

    EnergyRecord::create([
        'facility_id' => $facility->id,
        'meter_id' => null,
        'year' => 2026,
        'month' => 7,
        'actual_kwh' => 7820,
        'input_source' => 'cprf',
    ]);

    $response = $this->actingAs($admin)->get("/modules/facilities/{$facility->id}/monthly-records");

    $response->assertOk();
    $response->assertSee('Facility-Level (CPRF)');
    $response->assertSee('7,820.00');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest tests/Feature/CprfFacilityLevelReadingVisibilityTest.php`
Expected: FAIL — `assertSee('Facility-Level (CPRF)')` fails because the query in `routes/modules.php` excludes `meter_id IS NULL` rows entirely, so the row never reaches the view.

- [ ] **Step 3: Loosen the query filter in `routes/modules.php`**

At lines 126-130, change:

```php
        $allRecords = \App\Models\EnergyRecord::with('meter')
            ->where('facility_id', $facilityId)
            ->whereHas('meter', function ($meterQuery) {
                $meterQuery->where('meter_type', 'main');
            })
            ->orderByDesc('year')
```

to:

```php
        $allRecords = \App\Models\EnergyRecord::with('meter')
            ->where('facility_id', $facilityId)
            ->where(function ($q) {
                $q->whereHas('meter', function ($meterQuery) {
                    $meterQuery->where('meter_type', 'main');
                })->orWhere('input_source', 'cprf');
            })
            ->orderByDesc('year')
```

- [ ] **Step 4: Add the CPRF badge in the Blade view**

In `resources/views/modules/facilities/monthly-record/records.blade.php`, at lines 1435-1438, change:

```php
                            $scopeLabelRow = 'MAIN';
                            $scopeNameRow = (string) ($record->meter->meter_name ?? 'Main Meter');
                            $scopeBg = '#eff6ff';
                            $scopeColor = '#1d4ed8';
```

to:

```php
                            $isCprfFacilityLevel = $record->meter_id === null && ($record->input_source ?? null) === 'cprf';
                            $scopeLabelRow = $isCprfFacilityLevel ? 'CPRF' : 'MAIN';
                            $scopeNameRow = $isCprfFacilityLevel
                                ? 'Facility-Level (CPRF)'
                                : (string) ($record->meter->meter_name ?? 'Main Meter');
                            $scopeBg = $isCprfFacilityLevel ? '#f3e8ff' : '#eff6ff';
                            $scopeColor = $isCprfFacilityLevel ? '#7c3aed' : '#1d4ed8';
```

(This block already renders via `.scope-pill` at lines 1507-1510 using these four variables — no change needed there since it just consumes them.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest tests/Feature/CprfFacilityLevelReadingVisibilityTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd INTEGRATION/Lgu1-energy
git add routes/modules.php resources/views/modules/facilities/monthly-record/records.blade.php tests/Feature/CprfFacilityLevelReadingVisibilityTest.php
git commit -m "Surface CPRF facility-level readings in Monthly Records with a distinct badge"
```

---

### Task 3: Include CPRF facility-level readings in dashboard totals, trend, and exports

**Files:**
- Modify: `app/Http/Controllers/Modules/EnergyMonitoringController.php` (3 of its 4 `whereHas('meter', ...)` sites)
- Modify: `routes/energy.php` (5 of its 7 `whereHas('meter', ...)` sites)
- Test: `tests/Feature/CprfFacilityLevelReadingVisibilityTest.php` (extend from Task 2)

**Scope decision — which sites are touched and which are not:**

Touched (aggregate/listing views a human reads totals or history from):
- `EnergyMonitoringController.php:60-66` — dashboard `totalEnergyCost` sum
- `EnergyMonitoringController.php:68-74` — dashboard `totalConsumptionKwh` sum
- `EnergyMonitoringController.php:283-293` (`loadRecentRecordsByFacility`) — per-facility recent-months trend used on dashboard cards
- `routes/energy.php:29-32` — trend page's distinct-years list
- `routes/energy.php:56-61` — trend page's chart records for the selected facility/year
- `routes/energy.php:179-183` — Monthly Energy Excel/CSV export
- `routes/energy.php:234-238` — Annual Summary Excel/CSV export
- `routes/energy.php:339-342` — Annual Summary PDF export

**Not touched (left exactly as-is), with rationale:**
- `EnergyMonitoringController.php:302-310` (`loadCurrentMonthMainMeterSnapshots`) — this query already has an explicit `->whereNotNull('meter_id')` immediately before the `whereHas`, and groups results `->groupBy('meter_id')` for a per-meter breakdown table. A CPRF facility-level row has no meter to group by, so including it here would produce a nonsensical `null`-keyed group. Leave untouched.
- `routes/energy.php:145` (`check-duplicate` AJAX endpoint) — this guards against re-encoding the *same main meter's* reading twice for a period. A facility can legitimately have both a main-meter reading and a separate CPRF facility-level reading for the same period (they are additive, different measurement channels per the design spec) — loosening this filter would incorrectly block a staff member from encoding a real meter reading just because a CPRF row already exists for that period. Leave untouched.
- `routes/energy.php:163` (`get-kwh-consumed` AJAX endpoint) — uses `->first()` to fetch a single row for a facility+month. If both a main-meter row and a CPRF row existed for the same period, loosening this filter would make which one `->first()` returns non-deterministic (no explicit tiebreak order). Leave untouched to avoid introducing an ambiguous-result bug; this is a single-row lookup, not an aggregate/listing.

- [ ] **Step 1: Extend the failing feature test**

Add a second test to `tests/Feature/CprfFacilityLevelReadingVisibilityTest.php` (same file as Task 2):

```php
test('cprf facility-level readings are included in dashboard consumption totals', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $facility = Facility::factory()->create();
    $currentYear = (int) date('Y');
    $currentMonth = (int) date('n');

    EnergyRecord::create([
        'facility_id' => $facility->id,
        'meter_id' => null,
        'year' => $currentYear,
        'month' => $currentMonth,
        'actual_kwh' => 500,
        'energy_cost' => 100,
        'input_source' => 'cprf',
    ]);

    $response = $this->actingAs($admin)->get('/modules/energy-monitoring');

    $response->assertOk();
    $response->assertViewHas('totalConsumptionKwh', fn ($total) => $total >= 500);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest tests/Feature/CprfFacilityLevelReadingVisibilityTest.php`
Expected: FAIL — `totalConsumptionKwh` does not include the CPRF row's 500 kWh because `EnergyMonitoringController.php:68-74`'s `whereHas('meter', ...)` excludes it.

(If `assertViewHas` doesn't resolve the variable name on this Laravel version, replace the assertion with `$response->assertSee('500')` scoped to wherever the dashboard renders `$totalConsumptionKwh` — check the corresponding Blade view for the exact display markup before finalizing this step.)

- [ ] **Step 3: Loosen the two dashboard sum filters in `EnergyMonitoringController.php`**

At lines 60-66, change:

```php
        $totalEnergyCost = EnergyRecord::where('month', $currentMonth)
            ->where('year', $currentYear)
            ->whereHas('meter', function ($meterQuery) {
                $meterQuery->where('meter_type', 'main');
            })
            ->when(!empty($facilityIds), fn ($q) => $q->whereIn('facility_id', $facilityIds))
            ->sum('energy_cost');
```

to:

```php
        $totalEnergyCost = EnergyRecord::where('month', $currentMonth)
            ->where('year', $currentYear)
            ->where(function ($q) {
                $q->whereHas('meter', function ($meterQuery) {
                    $meterQuery->where('meter_type', 'main');
                })->orWhere('input_source', 'cprf');
            })
            ->when(!empty($facilityIds), fn ($q) => $q->whereIn('facility_id', $facilityIds))
            ->sum('energy_cost');
```

At lines 68-74 (the immediately following block), change:

```php
        $totalConsumptionKwh = EnergyRecord::where('month', $currentMonth)
            ->where('year', $currentYear)
            ->whereHas('meter', function ($meterQuery) {
                $meterQuery->where('meter_type', 'main');
            })
            ->when(!empty($facilityIds), fn ($q) => $q->whereIn('facility_id', $facilityIds))
            ->sum('actual_kwh');
```

to:

```php
        $totalConsumptionKwh = EnergyRecord::where('month', $currentMonth)
            ->where('year', $currentYear)
            ->where(function ($q) {
                $q->whereHas('meter', function ($meterQuery) {
                    $meterQuery->where('meter_type', 'main');
                })->orWhere('input_source', 'cprf');
            })
            ->when(!empty($facilityIds), fn ($q) => $q->whereIn('facility_id', $facilityIds))
            ->sum('actual_kwh');
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest tests/Feature/CprfFacilityLevelReadingVisibilityTest.php`
Expected: PASS (both tests in this file)

- [ ] **Step 5: Apply the identical fix to the remaining 6 aggregate/listing sites**

These are mechanically identical to Step 3's change (same closure body, same replacement pattern) — no new test per site, since they share the exact same filter logic already proven correct by Step 4's passing test. Apply the same `->whereHas('meter', ...)` → `->where(fn ($q) => $q->whereHas('meter', ...)->orWhere('input_source', 'cprf'))` transformation to:

`EnergyMonitoringController.php:283-293` (`loadRecentRecordsByFacility`):
```php
        return EnergyRecord::query()
            ->whereIn('facility_id', $facilityIds)
            ->where(function ($q) {
                $q->whereHas('meter', function ($meterQuery) {
                    $meterQuery->where('meter_type', 'main');
                })->orWhere('input_source', 'cprf');
            })
            ->whereRaw('(year * 100 + month) BETWEEN ? AND ?', [$startYm, $currentYm])
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->groupBy('facility_id')
            ->map(fn (Collection $rows) => $this->aggregateFacilityRecordsByMonth($rows));
```

`routes/energy.php:29-32` (trend years list):
```php
    $years = EnergyRecord::query()
        ->where(function ($q) {
            $q->whereHas('meter', function ($meterQuery) {
                $meterQuery->where('meter_type', 'main');
            })->orWhere('input_source', 'cprf');
        })
        ->when(!empty($scopeFacilityIds), fn($q) => $q->whereIn('facility_id', $scopeFacilityIds))
        ->select('year')
        ->distinct()
        ->orderByDesc('year')
        ->pluck('year')
        ->toArray();
```

`routes/energy.php:56-61` (trend chart records):
```php
        $query = EnergyRecord::query()
            ->where('facility_id', $selectedFacilityId)
            ->where(function ($q) {
                $q->whereHas('meter', function ($meterQuery) {
                    $meterQuery->where('meter_type', 'main');
                })->orWhere('input_source', 'cprf');
            });
```

`routes/energy.php:179-183` (Monthly Excel/CSV export):
```php
    $query = EnergyRecord::with('facility')
        ->where(function ($q) {
            $q->whereHas('meter', function ($meterQuery) {
                $meterQuery->where('meter_type', 'main');
            })->orWhere('input_source', 'cprf');
        });
```

`routes/energy.php:234-238` (Annual Summary Excel/CSV export):
```php
        $query = EnergyRecord::with('facility')
            ->where(function ($q) {
                $q->whereHas('meter', function ($meterQuery) {
                    $meterQuery->where('meter_type', 'main');
                })->orWhere('input_source', 'cprf');
            });
```

`routes/energy.php:339-342` (Annual Summary PDF export):
```php
        $query = EnergyRecord::with('facility')
            ->where(function ($q) {
                $q->whereHas('meter', function ($meterQuery) {
                    $meterQuery->where('meter_type', 'main');
                })->orWhere('input_source', 'cprf');
            });
```

- [ ] **Step 6: Run the full Feature test suite to confirm nothing else broke**

Run: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest tests/Feature`
Expected: PASS — all existing Feature tests (including `RoleRouteAccessTest.php`'s export-related assertions) still pass.

- [ ] **Step 7: Commit**

```bash
cd INTEGRATION/Lgu1-energy
git add app/Http/Controllers/Modules/EnergyMonitoringController.php routes/energy.php tests/Feature/CprfFacilityLevelReadingVisibilityTest.php
git commit -m "Include CPRF facility-level readings in dashboard totals, trend, and exports"
```

---

## Final Verification

- [ ] Run the complete suite once more: `cd INTEGRATION/Lgu1-energy && ./vendor/bin/pest`
- [ ] Manually confirm: push a reading from CPRF (`POST /api/v1/cprf/facility-readings`), then load `/modules/facilities/{id}/monthly-records` in a browser and visually confirm the "Facility-Level (CPRF)" pill renders with the purple scheme (`#f3e8ff` / `#7c3aed`), distinct from the blue "MAIN" pill.
- [ ] Manually confirm `GET /api/v1/cprf/facility-profiles` (with a valid bearer token) returns the expected shape for a facility that has an `EnergyProfile` set, and omits one that doesn't.
