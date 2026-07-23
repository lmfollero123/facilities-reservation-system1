# LGU Energy Efficiency Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bidirectional integration between CPRF (this repo) and the LGU Energy Efficiency Laravel app — CPRF staff record monthly manual electricity meter readings per facility and push them to the Energy system; CPRF pulls back engineer-approved energy-saving recommendations; everything surfaces in a new "Energy Efficiency" module under the Operations sidebar group.

**Architecture:** CPRF-driven push/pull with local cache. The Energy app gains a passive, token-guarded `v1/cprf` API route group (mirroring its existing CIMM group). CPRF gains a `services/energy_api.php` cURL client, `config/energy_helper.php` business logic, four local tables, a dashboard page, a cron sync script, and a manual sync endpoint (mirroring its existing IPMS integration). Spec: `docs/superpowers/specs/2026-07-23-energy-efficiency-integration-design.md`.

**Tech Stack:** CPRF: plain PHP 8.1, PDO/MySQL, PHPUnit. Energy app: Laravel 11, Eloquent, Pest (sqlite in-memory for tests).

## Global Constraints

- Two working directories. **CPRF repo** = repo root (`facilities-reservation-system1/`), commits on `main`. **Energy repo** = `INTEGRATION/Lgu1-energy/` (its own git clone), commits on the existing `CPRF-Integration` branch — verify with `git branch --show-current` before every energy-repo commit; NEVER commit energy-repo work to `main` and never commit `INTEGRATION/` changes into the CPRF repo (it is a separate clone, so this cannot happen accidentally via `git add`).
- Shared dev token value (both sides' defaults must match exactly): `CPRF_ENERGY_SHARED_KEY_2026`.
- CPRF module permission key: `energy`. Admin full CRUD; Staff create/read/update, no delete; Resident none.
- CPRF route: `/dashboard/energy-efficiency` → view file `energy_efficiency.php`, sidebar page key `energy_efficiency`, sidebar label `Energy Efficiency`, icon key `lightbulb`.
- Energy-side statuses used verbatim: recommendations `status` default filter `approved`; CPRF reading `sync_status` values `pending` / `synced` / `failed`.
- CPRF PHP conventions: `snake_case` functions prefixed `frs_energy_`, PDO prepared statements only, `htmlspecialchars()` on ALL output, CSRF via `csrf_field()` / `verifyCSRFToken($_POST[CSRF_TOKEN_NAME])`.
- Energy-side conventions: Laravel validation arrays, `hash_equals` token comparison, paginated responses via the existing `IntegrationDataController::paginated()` helper (per_page default 25, max 100).
- All external text (recommendation messages) is untrusted: escape on output, never echo raw.

---

## Part A — Energy repo (`INTEGRATION/Lgu1-energy`, branch `CPRF-Integration`)

Run all Part A commands with the energy repo as CWD: `cd INTEGRATION/Lgu1-energy`.
Before Task 1: `git branch --show-current` must print `CPRF-Integration`. Test runner: `php artisan test` (Pest; sqlite in-memory, migrations run automatically via RefreshDatabase).

### Task 1: `input_source` column + shared baseline resolution

**Files:**
- Create: `database/migrations/2026_07_23_000001_add_input_source_to_energy_records_table.php`
- Modify: `app/Models/EnergyRecord.php` (add `'input_source'` to `$fillable`)
- Modify: `app/Models/Facility.php` (add `resolveBaselineKwh()` method)
- Modify: `app/Http/Controllers/Modules/EnergyController.php:~270` (private `resolveBaselineKwh` delegates its fallback chain)
- Test: `tests/Feature/CprfIntegrationSupportTest.php`

**Interfaces:**
- Consumes: existing `Facility::energyProfiles()` relation, `facilities.baseline_kwh`, `energy_profiles.baseline_kwh`.
- Produces: `Facility::resolveBaselineKwh(): ?float` (latest energy profile baseline → facility baseline → null) and fillable `energy_records.input_source` (string, default `'manual'`). Task 2 relies on both.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CprfIntegrationSupportTest.php`:

```php
<?php

use App\Models\EnergyProfile;
use App\Models\EnergyRecord;
use App\Models\Facility;

test('facility baseline resolves from latest energy profile first', function () {
    $facility = Facility::factory()->create(['baseline_kwh' => 500]);
    EnergyProfile::create(['facility_id' => $facility->id, 'baseline_kwh' => 750]);

    expect($facility->fresh()->resolveBaselineKwh())->toBe(750.0);
});

test('facility baseline falls back to facility column then null', function () {
    $withColumn = Facility::factory()->create(['baseline_kwh' => 500]);
    $without = Facility::factory()->create(['baseline_kwh' => null]);

    expect($withColumn->resolveBaselineKwh())->toBe(500.0)
        ->and($without->resolveBaselineKwh())->toBeNull();
});

test('energy records accept an input_source value', function () {
    $facility = Facility::factory()->create();

    $record = EnergyRecord::create([
        'facility_id' => $facility->id,
        'year' => 2026,
        'month' => 7,
        'actual_kwh' => 120,
        'input_source' => 'cprf',
    ]);

    expect($record->fresh()->input_source)->toBe('cprf');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CprfIntegrationSupport`
Expected: FAIL — `Call to undefined method App\Models\Facility::resolveBaselineKwh()` and/or `input_source` column not found.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_23_000001_add_input_source_to_energy_records_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('energy_records', function (Blueprint $table) {
            // Where the record came from: 'manual' (this app's UI) or 'cprf'
            // (pushed by the facilities-reservation system). Same convention
            // as submeter_readings.input_source / main_meter_readings.
            $table->string('input_source', 20)->default('manual')->after('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('energy_records', function (Blueprint $table) {
            $table->dropColumn('input_source');
        });
    }
};
```

- [ ] **Step 4: Add `'input_source'` to `EnergyRecord::$fillable`**

In `app/Models/EnergyRecord.php`, in the `$fillable` array, add one line after `'recorded_by',`:

```php
        'recorded_by',
        'input_source',
```

- [ ] **Step 5: Add `Facility::resolveBaselineKwh()`**

In `app/Models/Facility.php`, add this method inside the class (place near the other helper methods / relations):

```php
    /**
     * Baseline used for deviation/alert computation on new energy records:
     * latest energy profile baseline first, then the facility's own column.
     * Shared by the Energy Monitoring UI and the CPRF integration endpoint
     * so both compute deviations identically.
     */
    public function resolveBaselineKwh(): ?float
    {
        $profile = $this->energyProfiles()->latest()->first();
        if ($profile && $profile->baseline_kwh !== null) {
            return (float) $profile->baseline_kwh;
        }

        return $this->baseline_kwh !== null ? (float) $this->baseline_kwh : null;
    }
```

- [ ] **Step 6: Delegate `EnergyController::resolveBaselineKwh` fallback chain**

In `app/Http/Controllers/Modules/EnergyController.php`, replace the body of the private `resolveBaselineKwh` method's profile/facility fallback (the part after the two input checks) so the method reads:

```php
    private function resolveBaselineKwh(Request $request, ?Facility $facility, array $validated): ?float
    {
        if (array_key_exists('baseline_kwh', $validated) && $validated['baseline_kwh'] !== null && $validated['baseline_kwh'] !== '') {
            return (float) $validated['baseline_kwh'];
        }

        $baselineInput = $request->input('baseline_kwh');
        if ($baselineInput !== null && $baselineInput !== '') {
            return (float) $baselineInput;
        }

        return $facility?->resolveBaselineKwh();
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=CprfIntegrationSupport`
Expected: PASS (3 tests). Also run `php artisan test` once — the full suite must stay green (the controller refactor must not break existing tests).

- [ ] **Step 8: Commit (energy repo, CPRF-Integration branch)**

```bash
git add database/migrations/2026_07_23_000001_add_input_source_to_energy_records_table.php app/Models/EnergyRecord.php app/Models/Facility.php app/Http/Controllers/Modules/EnergyController.php tests/Feature/CprfIntegrationSupportTest.php
git commit -m "Add energy_records input_source and shared facility baseline resolution for CPRF integration"
```

---

### Task 2: CPRF auth middleware + facility-readings endpoint

**Files:**
- Create: `app/Http/Middleware/AuthenticateCprfIntegration.php`
- Create: `app/Http/Controllers/Api/CprfFacilityReadingController.php`
- Modify: `bootstrap/app.php:24-31` (middleware alias)
- Modify: `config/services.php` (add `cprf_integration` entry after `cimm_maintenance_sync`)
- Modify: `routes/api.php` (add `v1/cprf` route group at end of file)
- Test: `tests/Feature/CprfFacilityReadingTest.php`

**Interfaces:**
- Consumes: `Facility::resolveBaselineKwh()` and `energy_records.input_source` from Task 1; existing `EnergyRecord::calculateDeviation(?float, ?float): ?float` and `EnergyRecord::resolveAlertLevel(?float $deviation, ?float $baselineKwh): string` statics.
- Produces: `POST /api/v1/cprf/facility-readings` (bearer `CPRF_INTEGRATION_TOKEN`), upserting facility-level (`meter_id` NULL) `energy_records` rows. Response JSON documented in Step 5 — CPRF's client (Task 7) parses `record.id`, `record.actual_kwh`, `record.alert`. Also produces middleware alias `cprf.integration` that Task 3 reuses.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CprfFacilityReadingTest.php`:

```php
<?php

use App\Models\EnergyRecord;
use App\Models\Facility;

function validReadingPayload(Facility $facility, array $overrides = []): array
{
    return array_merge([
        'facility_id' => $facility->id,
        'year' => 2026,
        'month' => 7,
        'previous_reading_kwh' => 500,
        'current_reading_kwh' => 620,
        'reading_date' => '2026-07-21',
    ], $overrides);
}

test('readings endpoint returns 503 when token is not configured', function () {
    config(['services.cprf_integration.token' => '']);
    $facility = Facility::factory()->create();

    $this->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility))
        ->assertStatus(503);
});

test('readings endpoint rejects a missing or wrong token', function () {
    config(['services.cprf_integration.token' => 'right-token']);
    $facility = Facility::factory()->create();

    $this->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility))
        ->assertStatus(401);

    $this->withToken('wrong-token')
        ->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility))
        ->assertStatus(401);
});

test('a valid reading stores a cprf-sourced energy record with computed kwh', function () {
    config(['services.cprf_integration.token' => 'test-token']);
    $facility = Facility::factory()->create(['baseline_kwh' => 100]);

    $response = $this->withToken('test-token')
        ->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility));

    $response->assertCreated()
        ->assertJsonPath('record.actual_kwh', 120.0)
        ->assertJsonPath('record.input_source', 'cprf');

    $this->assertDatabaseHas('energy_records', [
        'facility_id' => $facility->id,
        'year' => 2026,
        'month' => 7,
        'input_source' => 'cprf',
    ]);
    expect((float) EnergyRecord::first()->actual_kwh)->toBe(120.0);
});

test('pushing the same period twice updates instead of duplicating', function () {
    config(['services.cprf_integration.token' => 'test-token']);
    $facility = Facility::factory()->create();

    $this->withToken('test-token')
        ->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility))
        ->assertCreated();

    $this->withToken('test-token')
        ->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility, [
            'current_reading_kwh' => 650,
        ]))
        ->assertOk();

    expect(EnergyRecord::count())->toBe(1)
        ->and((float) EnergyRecord::first()->actual_kwh)->toBe(150.0);
});

test('validation rejects a current reading below the previous one', function () {
    config(['services.cprf_integration.token' => 'test-token']);
    $facility = Facility::factory()->create();

    $this->withToken('test-token')
        ->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility, [
            'current_reading_kwh' => 400,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_reading_kwh']);
});

test('validation rejects an unknown facility', function () {
    config(['services.cprf_integration.token' => 'test-token']);
    $facility = Facility::factory()->create();

    $this->withToken('test-token')
        ->postJson('/api/v1/cprf/facility-readings', validReadingPayload($facility, [
            'facility_id' => 999999,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['facility_id']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CprfFacilityReading`
Expected: FAIL — 404s (route does not exist).

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/AuthenticateCprfIntegration.php` (mirror of `AuthenticateIntegrationApi`):

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCprfIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = trim((string) config('services.cprf_integration.token', ''));

        if ($configuredToken === '') {
            return new JsonResponse([
                'message' => 'The CPRF integration API is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $providedToken = (string) $request->bearerToken();

        if ($providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return new JsonResponse([
                'message' => 'Invalid or missing CPRF integration token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register alias and token config**

In `bootstrap/app.php`, inside the `$middleware->alias([...])` array, add after the `'cimm.maintenance.sync'` line:

```php
            'cprf.integration' => \App\Http\Middleware\AuthenticateCprfIntegration::class,
```

In `config/services.php`, add after the `cimm_maintenance_sync` entry (keep its comment style):

```php
    // CPRF (facilities reservation) <-> Energy integration. Same isolation
    // rationale as cimm_maintenance_sync above: CPRF gets its own token so it
    // can be set or rotated independently of the generic integration_api
    // token. Defaults to a shared dev key so local integration works out of
    // the box; override CPRF_INTEGRATION_TOKEN in production.
    'cprf_integration' => [
        'token' => env('CPRF_INTEGRATION_TOKEN', 'CPRF_ENERGY_SHARED_KEY_2026'),
    ],
```

- [ ] **Step 5: Create the controller**

Create `app/Http/Controllers/Api/CprfFacilityReadingController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnergyRecord;
use App\Models\Facility;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CprfFacilityReadingController extends Controller
{
    /**
     * Inbound manual meter readings pushed by CPRF (facilities reservation).
     *
     * Upserts the facility-level (meter_id NULL) monthly energy_records row so
     * CPRF-sourced data flows through the same baseline/deviation/alert
     * pipeline as records encoded on this app's own Energy Monitoring page.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'previous_reading_kwh' => ['required', 'numeric', 'min:0'],
            'current_reading_kwh' => ['required', 'numeric', 'gte:previous_reading_kwh'],
            'reading_date' => ['required', 'date'],
            'energy_cost' => ['nullable', 'numeric', 'min:0'],
            'rate_per_kwh' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'external_ref' => ['nullable', 'string', 'max:100'],
            'recorded_by_name' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Facility $facility */
        $facility = Facility::query()->findOrFail((int) $validated['facility_id']);

        $actualKwh = round((float) $validated['current_reading_kwh'] - (float) $validated['previous_reading_kwh'], 2);
        $baseline = $facility->resolveBaselineKwh();
        $deviation = EnergyRecord::calculateDeviation($actualKwh, $baseline);
        $alert = EnergyRecord::resolveAlertLevel($deviation, $baseline);

        $record = EnergyRecord::query()->firstOrNew([
            'facility_id' => $facility->id,
            'meter_id' => null,
            'year' => (int) $validated['year'],
            'month' => (int) $validated['month'],
        ]);
        $wasExisting = $record->exists;

        $record->fill([
            'day' => Carbon::parse($validated['reading_date'])->day,
            'actual_kwh' => $actualKwh,
            'baseline_kwh' => $baseline,
            'deviation' => $deviation,
            'alert' => $alert,
            'energy_cost' => $validated['energy_cost'] ?? null,
            'rate_per_kwh' => $validated['rate_per_kwh'] ?? null,
            'input_source' => 'cprf',
        ]);
        $record->save();

        // notes / external_ref / recorded_by_name have no energy_records
        // columns; keep them in the log for traceability.
        Log::info('CPRF facility reading received', [
            'energy_record_id' => $record->id,
            'external_ref' => $validated['external_ref'] ?? null,
            'recorded_by_name' => $validated['recorded_by_name'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => $wasExisting ? 'Facility reading updated.' : 'Facility reading received.',
            'record' => [
                'id' => $record->id,
                'facility_id' => $record->facility_id,
                'period' => ['year' => (int) $record->year, 'month' => (int) $record->month],
                'actual_kwh' => (float) $record->actual_kwh,
                'baseline_kwh' => $record->baseline_kwh !== null ? (float) $record->baseline_kwh : null,
                'deviation_percent' => $record->deviation,
                'alert' => $record->alert,
                'input_source' => $record->input_source,
            ],
        ], $wasExisting ? 200 : 201);
    }
}
```

- [ ] **Step 6: Register the route group**

In `routes/api.php`, add the import at the top with the other `use` lines:

```php
use App\Http\Controllers\Api\CprfFacilityReadingController;
```

And append at the end of the file:

```php
// CPRF (facilities reservation) <-> Energy integration. CPRF pushes manual
// facility meter readings in and pulls facilities/recommendations out. Same
// per-partner token pattern as the CIMM group above (services.cprf_integration).
// GET endpoints reuse IntegrationDataController methods -- only the auth differs.
Route::prefix('v1/cprf')->middleware(['cprf.integration', 'throttle:60,1'])->group(function () {
    Route::post('/facility-readings', [CprfFacilityReadingController::class, 'store']);
});
```

(The two GET routes are added inside this same group in Task 3.)

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=CprfFacilityReading`
Expected: PASS (6 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/AuthenticateCprfIntegration.php app/Http/Controllers/Api/CprfFacilityReadingController.php bootstrap/app.php config/services.php routes/api.php tests/Feature/CprfFacilityReadingTest.php
git commit -m "Add CPRF integration auth and facility-readings intake endpoint"
```

---

### Task 3: Recommendations + facilities read endpoints for CPRF

**Files:**
- Modify: `app/Http/Controllers/Api/IntegrationDataController.php` (add `recommendations()` method before the private `paginated()` helper; add model import)
- Modify: `routes/api.php` (two GET routes inside the `v1/cprf` group)
- Test: `tests/Feature/CprfRecommendationsTest.php`

**Interfaces:**
- Consumes: `cprf.integration` middleware (Task 2), existing `EnergySavingRecommendation` model, existing `IntegrationDataController::facilities()` and `paginated()`.
- Produces: `GET /api/v1/cprf/recommendations` (filters: `facility_id`, `year`, `month`, `status` default `approved`, `updated_since`; Laravel paginator JSON whose `data[]` rows have keys `id`, `facility` `{id,name}`, `year`, `month`, `generated_message`, `engineer_recommendation`, `status`, `expected_savings_kwh`, `target_date`, `reviewed_at`, `updated_at`) and `GET /api/v1/cprf/facilities` (same shape as existing `/api/v1/facilities`). CPRF's client (Task 6) consumes both.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CprfRecommendationsTest.php`:

```php
<?php

use App\Models\EnergySavingRecommendation;
use App\Models\Facility;

function makeRecommendation(Facility $facility, array $overrides = []): EnergySavingRecommendation
{
    return EnergySavingRecommendation::create(array_merge([
        'facility_id' => $facility->id,
        'year' => 2026,
        'month' => 6,
        'generated_message' => 'Shift aircon pre-cooling 30 minutes later.',
        'status' => 'approved',
    ], $overrides));
}

test('recommendations endpoint requires the cprf token', function () {
    config(['services.cprf_integration.token' => 'right-token']);

    $this->getJson('/api/v1/cprf/recommendations')->assertStatus(401);
});

test('recommendations default to approved status only', function () {
    config(['services.cprf_integration.token' => 'test-token']);
    $facility = Facility::factory()->create();
    makeRecommendation($facility);
    makeRecommendation($facility, ['month' => 7, 'status' => 'for_review']);

    $response = $this->withToken('test-token')->getJson('/api/v1/cprf/recommendations');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.status'))->toBe('approved')
        ->and($response->json('data.0.facility.id'))->toBe($facility->id);
});

test('recommendations can be filtered by facility, period, status and updated_since', function () {
    config(['services.cprf_integration.token' => 'test-token']);
    $facilityA = Facility::factory()->create();
    $facilityB = Facility::factory()->create();
    makeRecommendation($facilityA);
    makeRecommendation($facilityB, ['month' => 5]);

    $byFacility = $this->withToken('test-token')
        ->getJson('/api/v1/cprf/recommendations?facility_id=' . $facilityA->id);
    expect($byFacility->json('data'))->toHaveCount(1);

    $byStatus = $this->withToken('test-token')
        ->getJson('/api/v1/cprf/recommendations?status=for_review');
    expect($byStatus->json('data'))->toHaveCount(0);

    $future = now()->addDay()->toIso8601String();
    $sinceFuture = $this->withToken('test-token')
        ->getJson('/api/v1/cprf/recommendations?updated_since=' . urlencode($future));
    expect($sinceFuture->json('data'))->toHaveCount(0);
});

test('cprf facilities endpoint lists facilities with the shared token', function () {
    config(['services.cprf_integration.token' => 'test-token']);
    Facility::factory()->count(2)->create();

    $response = $this->withToken('test-token')->getJson('/api/v1/cprf/facilities');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CprfRecommendations`
Expected: FAIL — 404 (routes not registered).

- [ ] **Step 3: Add the `recommendations()` controller method**

In `app/Http/Controllers/Api/IntegrationDataController.php`:

Add the import with the other model imports:

```php
use App\Models\EnergySavingRecommendation;
```

Add this method directly before the `private function paginated(` declaration:

```php
    /**
     * Engineer-reviewed energy-saving recommendations for CPRF. Defaults to
     * status=approved so the reservation system only surfaces vetted advice;
     * pass status=all to lift the filter.
     */
    public function recommendations(Request $request): JsonResponse
    {
        $request->validate([
            'facility_id' => ['nullable', 'integer', 'min:1'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'status' => ['nullable', 'string', 'max:20'],
            'updated_since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $request->filled('status') ? $request->string('status')->toString() : 'approved';

        $query = EnergySavingRecommendation::query()
            ->with('facility:id,name')
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->when($request->filled('facility_id'), fn (Builder $q) => $q->where('facility_id', $request->integer('facility_id')))
            ->when($request->filled('year'), fn (Builder $q) => $q->where('year', $request->integer('year')))
            ->when($request->filled('month'), fn (Builder $q) => $q->where('month', $request->integer('month')))
            ->when($request->filled('updated_since'), fn (Builder $q) => $q->where('updated_at', '>=', $request->date('updated_since')))
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('id');

        return $this->paginated($query, $request, fn (EnergySavingRecommendation $reco) => [
            'id' => $reco->id,
            'facility' => ['id' => $reco->facility_id, 'name' => $reco->facility?->name],
            'year' => $reco->year,
            'month' => $reco->month,
            'generated_message' => $reco->generated_message,
            'engineer_recommendation' => $reco->engineer_recommendation,
            'status' => $reco->status,
            'expected_savings_kwh' => $this->number($reco->expected_savings_kwh),
            'target_date' => $reco->target_date?->toDateString(),
            'reviewed_at' => $reco->reviewed_at?->toIso8601String(),
            'updated_at' => $reco->updated_at?->toIso8601String(),
        ]);
    }
```

- [ ] **Step 4: Register the GET routes**

In `routes/api.php`, inside the `v1/cprf` group added in Task 2, add above the POST route:

```php
    Route::get('/facilities', [IntegrationDataController::class, 'facilities']);
    Route::get('/recommendations', [IntegrationDataController::class, 'recommendations']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=Cprf`
Expected: PASS (all Cprf* test files — Support, FacilityReading, Recommendations).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/IntegrationDataController.php routes/api.php tests/Feature/CprfRecommendationsTest.php
git commit -m "Expose recommendations and facilities to CPRF via v1/cprf read endpoints"
```

---

### Task 4: Energy-side docs, env template, and branch push

**Files:**
- Modify: `docs/integration-api.md` (append CPRF section)
- Modify: `.env.example` (append `CPRF_INTEGRATION_TOKEN`)

**Interfaces:**
- Consumes: endpoint contracts from Tasks 2–3.
- Produces: partner-facing documentation; nothing downstream consumes it programmatically.

- [ ] **Step 1: Append the CPRF section to `docs/integration-api.md`**

Match the doc's existing tone/format (read its CIMM section first for heading style), appending:

```markdown
## CPRF (Facilities Reservation) Integration

CPRF pushes manual facility meter readings in and pulls facilities and
engineer-approved recommendations out. Auth: `Authorization: Bearer
{CPRF_INTEGRATION_TOKEN}` (defaults to the shared dev key
`CPRF_ENERGY_SHARED_KEY_2026`; override in production). Rate limit: 60
requests/minute.

### POST /api/v1/cprf/facility-readings

Upserts the facility-level monthly `energy_records` row (one per facility +
year + month, `meter_id` NULL, `input_source=cprf`). Baseline, deviation, and
alert are computed exactly as for manually encoded records.

Request body:

| Field | Type | Required | Notes |
|---|---|---|---|
| facility_id | integer | yes | must exist in `facilities` |
| year | integer | yes | 2000–2100 |
| month | integer | yes | 1–12 |
| previous_reading_kwh | number | yes | >= 0 |
| current_reading_kwh | number | yes | >= previous_reading_kwh |
| reading_date | date | yes | e.g. `2026-07-21` |
| energy_cost | number | no | |
| rate_per_kwh | number | no | |
| notes | string | no | logged only, not stored |
| external_ref | string | no | CPRF's local reading id, logged only |
| recorded_by_name | string | no | logged only |

Responses: `201` created / `200` updated with `{message, record{id, facility_id,
period{year,month}, actual_kwh, baseline_kwh, deviation_percent, alert,
input_source}}`; `422` validation error; `401`/`503` auth.

### GET /api/v1/cprf/facilities

Same response shape and filters as `GET /api/v1/facilities` (status, search,
page, per_page) — only the auth token differs.

### GET /api/v1/cprf/recommendations

Rows from `energy_saving_recommendations`. Filters: `facility_id`, `year`,
`month`, `status` (default `approved`; pass `all` to lift), `updated_since`,
`page`, `per_page` (max 100). Row shape: `id, facility{id,name}, year, month,
generated_message, engineer_recommendation, status, expected_savings_kwh,
target_date, reviewed_at, updated_at`.
```

- [ ] **Step 2: Append to `.env.example`**

```
# CPRF (facilities reservation) integration token. Shared bearer key CPRF uses
# for /api/v1/cprf/* (push facility readings, pull recommendations).
CPRF_INTEGRATION_TOKEN=CPRF_ENERGY_SHARED_KEY_2026
```

- [ ] **Step 3: Full suite + commit + push the branch**

Run: `php artisan test`
Expected: PASS (entire suite).

```bash
git add docs/integration-api.md .env.example
git commit -m "Document CPRF integration endpoints and env token"
git push origin CPRF-Integration
```

---

## Part B — CPRF repo (repo root, branch `main`)

Test runner: `vendor/bin/phpunit`. Lint any new PHP file with `php -l <file>`.

### Task 5: Database migration + permission defaults

**Files:**
- Create: `database/migration_add_energy_integration.sql`
- Modify: `config/permissions.php:142,158,174` (add `energy` defaults per role, after each `'utilities'` line)
- Modify: `.env.example` (energy integration keys)

**Interfaces:**
- Consumes: existing `facilities`, `users`, `role_permissions` tables.
- Produces: tables `energy_facility_map`, `energy_meter_readings`, `energy_recommendations_cache`, `energy_sync_state` (single row id=1); permission key `energy`; env keys `ENERGY_API_URL`, `ENERGY_API_TOKEN`, `ENERGY_SYNC_ENABLED`. All later tasks depend on these exact table/column names.

- [ ] **Step 1: Create `database/migration_add_energy_integration.sql`**

```sql
-- LGU Energy Efficiency integration: manual meter readings pushed to the
-- Energy system and engineer-approved recommendations pulled back.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE.

USE facilities_reservation;

CREATE TABLE IF NOT EXISTS energy_facility_map (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    energy_facility_id INT UNSIGNED NOT NULL,
    energy_facility_name VARCHAR(150) NOT NULL DEFAULT '',
    mapped_by INT UNSIGNED NULL,
    mapped_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_map_facility (facility_id),
    CONSTRAINT fk_energy_map_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS energy_meter_readings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    reading_date DATE NOT NULL,
    previous_reading_kwh DECIMAL(14,2) NOT NULL,
    current_reading_kwh DECIMAL(14,2) NOT NULL,
    consumption_kwh DECIMAL(14,2) NOT NULL,
    notes TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    sync_status ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
    synced_at DATETIME NULL,
    sync_error TEXT NULL,
    external_record_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_reading_period (facility_id, year, month),
    KEY idx_energy_readings_sync (sync_status),
    CONSTRAINT fk_energy_reading_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS energy_recommendations_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_recommendation_id INT UNSIGNED NOT NULL,
    energy_facility_id INT UNSIGNED NOT NULL,
    facility_id INT UNSIGNED NULL,
    year SMALLINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    generated_message TEXT NOT NULL,
    engineer_recommendation TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'approved',
    expected_savings_kwh DECIMAL(14,2) NULL,
    target_date DATE NULL,
    reviewed_at DATETIME NULL,
    fetched_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_energy_reco_remote (energy_recommendation_id),
    KEY idx_energy_reco_facility (facility_id),
    CONSTRAINT fk_energy_reco_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS energy_sync_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    last_pull_at DATETIME NULL,
    last_push_at DATETIME NULL,
    last_summary TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO energy_sync_state (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO role_permissions (role, permission_key, can_create, can_read, can_update, can_delete) VALUES
('Admin', 'energy', 1, 1, 1, 1),
('Staff', 'energy', 1, 1, 1, 0),
('Resident', 'energy', 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE
    can_create = VALUES(can_create),
    can_read = VALUES(can_read),
    can_update = VALUES(can_update),
    can_delete = VALUES(can_delete);
```

- [ ] **Step 2: Add hardcoded permission fallbacks**

In `config/permissions.php`, add one line after each role's `'utilities'` line:

After line 142 (Admin block):
```php
            'energy' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
```
After line 158 (Staff block):
```php
            'energy' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false],
```
After line 174 (Resident block):
```php
            'energy' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
```

- [ ] **Step 3: Add env keys to `.env.example`**

Append after the IPMS block (around line 81):

```
# LGU Energy Efficiency integration (optional; pushes manual meter readings,
# pulls engineer-approved energy-saving recommendations)
ENERGY_API_URL=http://localhost:8000
# Bearer token shared with the Energy system (their CPRF_INTEGRATION_TOKEN)
ENERGY_API_TOKEN=CPRF_ENERGY_SHARED_KEY_2026
# Gates the cron script and the Sync Now button (module UI still renders when false)
ENERGY_SYNC_ENABLED=true
```

- [ ] **Step 4: Run the migration and verify**

Run: `mysql -u root facilities_reservation < database/migration_add_energy_integration.sql` (or via the project's usual MySQL credentials)
Then: `mysql -u root facilities_reservation -e "SHOW TABLES LIKE 'energy_%'; SELECT role, permission_key, can_create, can_read, can_update FROM role_permissions WHERE permission_key='energy';"`
Expected: 4 `energy_*` tables; 3 permission rows matching Step 1's values.

- [ ] **Step 5: Commit**

```bash
git add database/migration_add_energy_integration.sql config/permissions.php .env.example
git commit -m "Add energy integration tables, permission key, and env template"
```

---

### Task 6: API client + pure helper functions (TDD)

**Files:**
- Create: `services/energy_api.php`
- Create: `config/energy_helper.php` (pure functions only in this task; DB functions come in Task 7)
- Modify: `tests/bootstrap.php` (require the two new files)
- Test: `tests/Unit/EnergyHelperTest.php`

**Interfaces:**
- Consumes: `env_value()` from `config/app.php`.
- Produces (client — all return `['success' => bool, 'data' => ?array, 'error' => ?string, 'http_code' => int]`):
  - `energy_api_base_url(): string`, `energy_api_token(): string`, `energy_api_enabled(): bool`
  - `energy_api_request(string $method, string $path, ?array $body = null, array $query = []): array`
  - `fetchEnergyFacilities(): array` — aggregates up to 5 pages of `GET /api/v1/cprf/facilities?per_page=100`; `data` is a flat list of facility rows
  - `fetchEnergyRecommendations(array $query = []): array` — `GET /api/v1/cprf/recommendations`; `data` is the raw paginator array
  - `pushEnergyFacilityReading(array $payload): array` — `POST /api/v1/cprf/facility-readings`
- Produces (pure helpers):
  - `frs_energy_compute_consumption(mixed $previous, mixed $current): ?float`
  - `frs_energy_normalize_name(string $name): string`
  - `frs_energy_suggest_match(string $facilityName, array $energyFacilities): ?array` — `['id' => int, 'name' => string, 'score' => int]` or null
  - `frs_energy_build_reading_payload(array $reading, int $energyFacilityId): array` — request body for the push endpoint

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/EnergyHelperTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnergyHelperTest extends TestCase
{
    public function test_compute_consumption_returns_difference(): void
    {
        $this->assertSame(120.0, frs_energy_compute_consumption(500, 620));
        $this->assertSame(0.0, frs_energy_compute_consumption('500', '500'));
    }

    public function test_compute_consumption_rejects_invalid_input(): void
    {
        $this->assertNull(frs_energy_compute_consumption(500, 400)); // rollback impossible
        $this->assertNull(frs_energy_compute_consumption(null, 400));
        $this->assertNull(frs_energy_compute_consumption('abc', 400));
        $this->assertNull(frs_energy_compute_consumption(-1, 400)); // negative meter value
    }

    public function test_suggest_match_prefers_exact_name(): void
    {
        $remote = [
            ['id' => 1, 'name' => 'Culiat Covered Court'],
            ['id' => 2, 'name' => 'Barangay Hall'],
        ];

        $match = frs_energy_suggest_match('  barangay   hall ', $remote);
        $this->assertSame(2, $match['id']);
        $this->assertSame(100, $match['score']);
    }

    public function test_suggest_match_falls_back_to_substring(): void
    {
        $remote = [['id' => 7, 'name' => 'Culiat Covered Court (Main)']];

        $match = frs_energy_suggest_match('Culiat Covered Court', $remote);
        $this->assertSame(7, $match['id']);
        $this->assertSame(80, $match['score']);
    }

    public function test_suggest_match_returns_null_below_threshold(): void
    {
        $remote = [['id' => 3, 'name' => 'Water Pumping Station']];

        $this->assertNull(frs_energy_suggest_match('Multipurpose Hall', $remote));
    }

    public function test_build_reading_payload_maps_local_row(): void
    {
        $reading = [
            'id' => 42,
            'year' => 2026,
            'month' => 7,
            'reading_date' => '2026-07-21',
            'previous_reading_kwh' => '500.00',
            'current_reading_kwh' => '620.00',
            'notes' => 'July reading',
            'recorded_by_name' => 'Juan Dela Cruz',
        ];

        $payload = frs_energy_build_reading_payload($reading, 9);

        $this->assertSame(9, $payload['facility_id']);
        $this->assertSame(2026, $payload['year']);
        $this->assertSame(7, $payload['month']);
        $this->assertSame(500.0, $payload['previous_reading_kwh']);
        $this->assertSame(620.0, $payload['current_reading_kwh']);
        $this->assertSame('2026-07-21', $payload['reading_date']);
        $this->assertSame('CPRF-42', $payload['external_ref']);
        $this->assertSame('July reading', $payload['notes']);
        $this->assertSame('Juan Dela Cruz', $payload['recorded_by_name']);
    }
}
```

- [ ] **Step 2: Wire the bootstrap and run tests to verify they fail**

In `tests/bootstrap.php`, append:

```php
require_once $root . '/services/energy_api.php';
require_once $root . '/config/energy_helper.php';
```

Run: `vendor/bin/phpunit tests/Unit/EnergyHelperTest.php`
Expected: FAIL — file not found / undefined function.

- [ ] **Step 3: Create `services/energy_api.php`**

```php
<?php
/**
 * LGU Energy Efficiency integration client.
 *
 * Push/pull integration: CPRF pushes manual facility meter readings to the
 * Energy system's POST /api/v1/cprf/facility-readings, and pulls facilities
 * and engineer-approved recommendations from its GET /api/v1/cprf/* endpoints.
 * Auth is a shared bearer token (ENERGY_API_TOKEN, matching the Energy app's
 * CPRF_INTEGRATION_TOKEN).
 */

declare(strict_types=1);

function energy_api_base_url(): string
{
    $url = trim((string)(function_exists('env_value') ? env_value('ENERGY_API_URL', '') : (getenv('ENERGY_API_URL') ?: '')));
    return rtrim($url, '/');
}

function energy_api_token(): string
{
    return trim((string)(function_exists('env_value') ? env_value('ENERGY_API_TOKEN', '') : (getenv('ENERGY_API_TOKEN') ?: '')));
}

function energy_api_enabled(): bool
{
    $flag = strtolower(trim((string)(function_exists('env_value') ? env_value('ENERGY_SYNC_ENABLED', 'true') : (getenv('ENERGY_SYNC_ENABLED') ?: 'true'))));
    return !in_array($flag, ['0', 'false', 'off', 'no'], true);
}

/**
 * Low-level request against the Energy system's CPRF API.
 *
 * @param array<string, mixed>|null $body
 * @param array<string, mixed> $query
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function energy_api_request(string $method, string $path, ?array $body = null, array $query = []): array
{
    $baseUrl = energy_api_base_url();
    $token = energy_api_token();
    if ($baseUrl === '' || $token === '') {
        return [
            'success' => false,
            'data' => null,
            'error' => 'Energy API is not configured (set ENERGY_API_URL and ENERGY_API_TOKEN in .env).',
            'http_code' => 0,
        ];
    }

    $url = $baseUrl . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'User-Agent: CPRF-Facilities-Reservation/1.0',
    ];

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        $msg = 'Connection failed: ' . ($curlError ?: 'Unable to reach Energy API');
        error_log('Energy API Error: ' . $msg);
        return ['success' => false, 'data' => null, 'error' => $msg, 'http_code' => $httpCode];
    }

    $json = json_decode((string)$response, true);

    if ($httpCode === 401 || $httpCode === 503) {
        $msg = is_array($json) ? (string)($json['message'] ?? 'Invalid or missing Energy API token') : 'Invalid or missing Energy API token';
        return ['success' => false, 'data' => null, 'error' => $msg, 'http_code' => $httpCode];
    }

    if ($httpCode === 422) {
        $msg = is_array($json) ? (string)($json['message'] ?? 'Validation failed') : 'Validation failed';
        return ['success' => false, 'data' => is_array($json) ? $json : null, 'error' => $msg, 'http_code' => $httpCode];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'data' => null,
            'error' => 'HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200),
            'http_code' => $httpCode,
        ];
    }

    if (!is_array($json)) {
        return ['success' => false, 'data' => null, 'error' => 'Invalid JSON from Energy API', 'http_code' => $httpCode];
    }

    return ['success' => true, 'data' => $json, 'error' => null, 'http_code' => $httpCode];
}

/**
 * Fetch the Energy system's facility list (for the mapping tab).
 * Aggregates up to 5 pages of 100; 'data' is a flat list of facility rows.
 *
 * @return array{success: bool, data: array<int, array<string, mixed>>, error: ?string}
 */
function fetchEnergyFacilities(): array
{
    $all = [];
    $page = 1;
    do {
        $result = energy_api_request('GET', '/api/v1/cprf/facilities', null, ['per_page' => 100, 'page' => $page]);
        if (!$result['success']) {
            return ['success' => false, 'data' => $all, 'error' => $result['error']];
        }
        $rows = $result['data']['data'] ?? [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $all[] = $row;
            }
        }
        $hasNext = !empty($result['data']['next_page_url']);
        $page++;
    } while ($hasNext && $page <= 5);

    return ['success' => true, 'data' => $all, 'error' => null];
}

/**
 * Fetch recommendations (raw Laravel paginator array in 'data').
 *
 * @param array<string, mixed> $query e.g. ['status' => 'approved', 'updated_since' => '...', 'page' => 1]
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function fetchEnergyRecommendations(array $query = []): array
{
    return energy_api_request('GET', '/api/v1/cprf/recommendations', null, $query);
}

/**
 * Push one facility meter reading.
 *
 * @param array<string, mixed> $payload from frs_energy_build_reading_payload()
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function pushEnergyFacilityReading(array $payload): array
{
    return energy_api_request('POST', '/api/v1/cprf/facility-readings', $payload);
}
```

- [ ] **Step 4: Create `config/energy_helper.php` with the pure functions**

```php
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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/EnergyHelperTest.php`
Expected: PASS (6 tests). Also run the full unit suite: `vendor/bin/phpunit` — must stay green.

- [ ] **Step 6: Commit**

```bash
git add services/energy_api.php config/energy_helper.php tests/bootstrap.php tests/Unit/EnergyHelperTest.php
git commit -m "Add Energy API client and pure energy helper functions"
```

---

### Task 7: DB-backed reading save/push, recommendation pull, mapping, and sync state

**Files:**
- Modify: `config/energy_helper.php` (append the PDO-backed functions)

**Interfaces:**
- Consumes: Task 5 tables; Task 6 client + pure functions; `logAudit($action, $module, $details)` from `config/audit.php`.
- Produces (Task 8 page and Task 9 scripts call these — signatures are contractual):
  - `frs_energy_tables_exist(PDO $pdo): bool`
  - `frs_energy_get_mapping(PDO $pdo): array` — `facility_id => ['energy_facility_id' => int, 'energy_facility_name' => string]`
  - `frs_energy_save_mapping(PDO $pdo, int $facilityId, int $energyFacilityId, string $energyFacilityName, ?int $userId): void`
  - `frs_energy_last_reading(PDO $pdo, int $facilityId): ?array` — latest row by (year, month)
  - `frs_energy_save_reading(PDO $pdo, array $data): int` — inserts, returns new id; throws `InvalidArgumentException` on duplicate period or invalid values
  - `frs_energy_push_reading(PDO $pdo, int $readingId): array` — `['success' => bool, 'error' => ?string]`; updates `sync_status`/`synced_at`/`sync_error`/`external_record_id`
  - `frs_energy_pull_recommendations(PDO $pdo): array` — `['success' => bool, 'upserted' => int, 'error' => ?string]`
  - `frs_energy_run_sync(PDO $pdo): array` — `['success' => bool, 'pushed' => int, 'push_failed' => int, 'recommendations_upserted' => int, 'errors' => string[], 'ran_at' => string]`
  - `frs_energy_load_sync_state(PDO $pdo): array` — `['last_pull_at' => ?string, 'last_push_at' => ?string, 'last_summary' => ?array]`

- [ ] **Step 1: Append the functions to `config/energy_helper.php`**

```php
function frs_energy_tables_exist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM energy_meter_readings LIMIT 1');
        $pdo->query('SELECT 1 FROM energy_facility_map LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<int, array{energy_facility_id: int, energy_facility_name: string}> keyed by facility_id */
function frs_energy_get_mapping(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT facility_id, energy_facility_id, energy_facility_name FROM energy_facility_map')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['facility_id']] = [
            'energy_facility_id' => (int)$row['energy_facility_id'],
            'energy_facility_name' => (string)$row['energy_facility_name'],
        ];
    }
    return $map;
}

function frs_energy_save_mapping(PDO $pdo, int $facilityId, int $energyFacilityId, string $energyFacilityName, ?int $userId): void
{
    $stmt = $pdo->prepare('
        INSERT INTO energy_facility_map (facility_id, energy_facility_id, energy_facility_name, mapped_by)
        VALUES (:facility_id, :energy_facility_id, :energy_facility_name, :mapped_by)
        ON DUPLICATE KEY UPDATE
            energy_facility_id = VALUES(energy_facility_id),
            energy_facility_name = VALUES(energy_facility_name),
            mapped_by = VALUES(mapped_by)
    ');
    $stmt->execute([
        'facility_id' => $facilityId,
        'energy_facility_id' => $energyFacilityId,
        'energy_facility_name' => $energyFacilityName,
        'mapped_by' => $userId,
    ]);

    require_once __DIR__ . '/audit.php';
    logAudit('Mapped facility to Energy system', 'Energy Efficiency', "facility_id={$facilityId} -> energy_facility_id={$energyFacilityId} ({$energyFacilityName})");
}

/** Latest reading for a facility (by year, month), or null. */
function frs_energy_last_reading(PDO $pdo, int $facilityId): ?array
{
    $stmt = $pdo->prepare('
        SELECT * FROM energy_meter_readings
        WHERE facility_id = :facility_id
        ORDER BY year DESC, month DESC
        LIMIT 1
    ');
    $stmt->execute(['facility_id' => $facilityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Insert a manual reading. When a previous reading exists, its
 * current_reading_kwh overrides the submitted previous value (meter
 * continuity); the first-ever reading uses the submitted previous value.
 *
 * @param array{facility_id: int, year: int, month: int, reading_date: string,
 *   previous_reading_kwh: float, current_reading_kwh: float, notes: ?string,
 *   recorded_by: ?int} $data
 * @return int new reading id
 * @throws InvalidArgumentException on invalid values or duplicate period
 */
function frs_energy_save_reading(PDO $pdo, array $data): int
{
    $facilityId = (int)$data['facility_id'];
    $last = frs_energy_last_reading($pdo, $facilityId);
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
            (facility_id, year, month, reading_date, previous_reading_kwh, current_reading_kwh, consumption_kwh, notes, recorded_by, sync_status)
        VALUES
            (:facility_id, :year, :month, :reading_date, :previous_kwh, :current_kwh, :consumption_kwh, :notes, :recorded_by, \'pending\')
    ');
    $stmt->execute([
        'facility_id' => $facilityId,
        'year' => (int)$data['year'],
        'month' => (int)$data['month'],
        'reading_date' => (string)$data['reading_date'],
        'previous_kwh' => $previous,
        'current_kwh' => $current,
        'consumption_kwh' => $consumption,
        'notes' => $data['notes'] !== null && $data['notes'] !== '' ? (string)$data['notes'] : null,
        'recorded_by' => $data['recorded_by'],
    ]);
    $id = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/audit.php';
    logAudit('Recorded energy meter reading', 'Energy Efficiency', "facility_id={$facilityId} {$data['year']}-{$data['month']}: {$consumption} kWh");

    return $id;
}

/**
 * Push one local reading to the Energy system and record the outcome.
 *
 * @return array{success: bool, error: ?string}
 */
function frs_energy_push_reading(PDO $pdo, int $readingId): array
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

    $mapping = frs_energy_get_mapping($pdo);
    $facilityId = (int)$reading['facility_id'];
    if (!isset($mapping[$facilityId])) {
        $fail = $pdo->prepare("UPDATE energy_meter_readings SET sync_status = 'failed', sync_error = :err WHERE id = :id");
        $fail->execute(['err' => 'Facility is not mapped to an Energy-system facility yet.', 'id' => $readingId]);
        return ['success' => false, 'error' => 'Facility is not mapped to an Energy-system facility yet.'];
    }

    $payload = frs_energy_build_reading_payload($reading, $mapping[$facilityId]['energy_facility_id']);
    $result = pushEnergyFacilityReading($payload);

    if (!$result['success']) {
        $fail = $pdo->prepare("UPDATE energy_meter_readings SET sync_status = 'failed', sync_error = :err WHERE id = :id");
        $fail->execute(['err' => (string)($result['error'] ?? 'Unknown error'), 'id' => $readingId]);
        return ['success' => false, 'error' => $result['error']];
    }

    $remoteId = isset($result['data']['record']['id']) ? (int)$result['data']['record']['id'] : null;
    $ok = $pdo->prepare("
        UPDATE energy_meter_readings
        SET sync_status = 'synced', synced_at = NOW(), sync_error = NULL, external_record_id = :remote_id
        WHERE id = :id
    ");
    $ok->execute(['remote_id' => $remoteId, 'id' => $readingId]);

    return ['success' => true, 'error' => null];
}

/**
 * Pull engineer-approved recommendations (updated_since watermark) into the
 * local cache, resolving CPRF facilities via the mapping table.
 *
 * @return array{success: bool, upserted: int, error: ?string}
 */
function frs_energy_pull_recommendations(PDO $pdo): array
{
    $state = frs_energy_load_sync_state($pdo);
    $query = ['status' => 'approved', 'per_page' => 100];
    if (!empty($state['last_pull_at'])) {
        $query['updated_since'] = $state['last_pull_at'];
    }

    // Reverse map: energy_facility_id => CPRF facility_id
    $reverse = [];
    foreach (frs_energy_get_mapping($pdo) as $facilityId => $m) {
        $reverse[$m['energy_facility_id']] = $facilityId;
    }

    $upserted = 0;
    $page = 1;
    do {
        $query['page'] = $page;
        $result = fetchEnergyRecommendations($query);
        if (!$result['success']) {
            return ['success' => false, 'upserted' => $upserted, 'error' => $result['error']];
        }
        $rows = $result['data']['data'] ?? [];
        $stmt = $pdo->prepare('
            INSERT INTO energy_recommendations_cache
                (energy_recommendation_id, energy_facility_id, facility_id, year, month,
                 generated_message, engineer_recommendation, status, expected_savings_kwh,
                 target_date, reviewed_at, fetched_at)
            VALUES
                (:remote_id, :energy_facility_id, :facility_id, :year, :month,
                 :generated_message, :engineer_recommendation, :status, :expected_savings_kwh,
                 :target_date, :reviewed_at, NOW())
            ON DUPLICATE KEY UPDATE
                energy_facility_id = VALUES(energy_facility_id),
                facility_id = VALUES(facility_id),
                year = VALUES(year),
                month = VALUES(month),
                generated_message = VALUES(generated_message),
                engineer_recommendation = VALUES(engineer_recommendation),
                status = VALUES(status),
                expected_savings_kwh = VALUES(expected_savings_kwh),
                target_date = VALUES(target_date),
                reviewed_at = VALUES(reviewed_at),
                fetched_at = NOW()
        ');
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $energyFacilityId = (int)($row['facility']['id'] ?? 0);
            $reviewedAt = isset($row['reviewed_at']) && $row['reviewed_at'] !== null
                ? date('Y-m-d H:i:s', strtotime((string)$row['reviewed_at']))
                : null;
            $stmt->execute([
                'remote_id' => (int)$row['id'],
                'energy_facility_id' => $energyFacilityId,
                'facility_id' => $reverse[$energyFacilityId] ?? null,
                'year' => (int)($row['year'] ?? 0),
                'month' => (int)($row['month'] ?? 0),
                'generated_message' => (string)($row['generated_message'] ?? ''),
                'engineer_recommendation' => $row['engineer_recommendation'] !== null ? (string)$row['engineer_recommendation'] : null,
                'status' => (string)($row['status'] ?? 'approved'),
                'expected_savings_kwh' => isset($row['expected_savings_kwh']) && is_numeric($row['expected_savings_kwh']) ? (float)$row['expected_savings_kwh'] : null,
                'target_date' => !empty($row['target_date']) ? (string)$row['target_date'] : null,
                'reviewed_at' => $reviewedAt,
            ]);
            $upserted++;
        }
        $hasNext = !empty($result['data']['next_page_url']);
        $page++;
    } while ($hasNext && $page <= 10);

    $pdo->prepare('UPDATE energy_sync_state SET last_pull_at = NOW() WHERE id = 1')->execute();

    return ['success' => true, 'upserted' => $upserted, 'error' => null];
}

/**
 * Full sync: retry pending/failed pushes, then pull recommendations.
 *
 * @return array{success: bool, pushed: int, push_failed: int, recommendations_upserted: int, errors: string[], ran_at: string}
 */
function frs_energy_run_sync(PDO $pdo): array
{
    $errors = [];
    $pushed = 0;
    $pushFailed = 0;

    $pending = $pdo->query("SELECT id FROM energy_meter_readings WHERE sync_status IN ('pending','failed') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pending as $readingId) {
        $result = frs_energy_push_reading($pdo, (int)$readingId);
        if ($result['success']) {
            $pushed++;
        } else {
            $pushFailed++;
            if ($result['error']) {
                $errors[] = 'Reading #' . $readingId . ': ' . $result['error'];
            }
        }
    }
    if ($pushed > 0) {
        $pdo->prepare('UPDATE energy_sync_state SET last_push_at = NOW() WHERE id = 1')->execute();
    }

    $pull = frs_energy_pull_recommendations($pdo);
    if (!$pull['success'] && $pull['error']) {
        $errors[] = 'Recommendations pull: ' . $pull['error'];
    }

    $summary = [
        'success' => $errors === [],
        'pushed' => $pushed,
        'push_failed' => $pushFailed,
        'recommendations_upserted' => $pull['upserted'],
        'errors' => $errors,
        'ran_at' => date('c'),
    ];

    $save = $pdo->prepare('UPDATE energy_sync_state SET last_summary = :summary WHERE id = 1');
    $save->execute(['summary' => json_encode($summary)]);

    require_once __DIR__ . '/audit.php';
    logAudit('Ran Energy integration sync', 'Energy Efficiency', "pushed={$pushed} failed={$pushFailed} recos={$pull['upserted']}");

    return $summary;
}

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

- [ ] **Step 2: Lint and re-run unit tests**

Run: `php -l config/energy_helper.php` — expected: `No syntax errors detected`.
Run: `vendor/bin/phpunit` — expected: PASS (pure-function tests unaffected).

- [ ] **Step 3: Commit**

```bash
git add config/energy_helper.php
git commit -m "Add energy reading persistence, push/pull sync, and mapping helpers"
```

---

### Task 8: Dashboard module page + route + sidebar entry

**Files:**
- Create: `resources/views/pages/dashboard/energy_efficiency.php`
- Modify: `index.php:~175` (route map: add after `'utilities-integration'` line)
- Modify: `resources/views/components/sidebar_dashboard.php` (icon at `$iconPaths` ~line 47; group entry after the UMAN block ~line 155)

**Interfaces:**
- Consumes: every `frs_energy_*` function from Tasks 6–7; `frs_can_read/create/update($role, 'energy')`; `csrf_field()`, `verifyCSRFToken()`, `CSRF_TOKEN_NAME`; `frs_page_title()`; `dashboard_layout.php`.
- Produces: `/dashboard/energy-efficiency?tab=readings|recommendations|mapping` page. POST actions (same URL): `action=add_reading`, `action=save_mapping`, `action=sync_now`.

- [ ] **Step 1: Register the route**

In `index.php`, in `$dashboardRouteMap`, add after `'utilities-integration' => 'utilities_integration.php',`:

```php
        'energy-efficiency' => 'energy_efficiency.php',
```

- [ ] **Step 2: Add the sidebar icon and entry**

In `resources/views/components/sidebar_dashboard.php`, add to `$iconPaths` (after the `'bolt'` entry):

```php
    'lightbulb' => '<path d="M9 18H15M10 21H14M12 3C8.7 3 6 5.7 6 9C6 11.2 7.2 13.2 9 14.2V16H15V14.2C16.8 13.2 18 11.2 18 9C18 5.7 15.3 3 12 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
```

After the UMAN Integration block (the `if (frs_can_read($role, 'utilities')) { ... }` lines), add:

```php
    if (frs_can_read($role, 'energy')) {
        $integrationsGroup[] = ['label' => 'Energy Efficiency', 'href' => $base . '/dashboard/energy-efficiency', 'icon' => 'lightbulb', 'page' => 'energy_efficiency'];
    }
```

- [ ] **Step 3: Create the page**

Create `resources/views/pages/dashboard/energy_efficiency.php`:

```php
<?php
/**
 * Energy Efficiency module — LGU Energy system integration.
 *
 * Tabs: Meter Readings (record + push monthly manual readings), Recommendations
 * (engineer-approved advice pulled from the Energy system), Facility Mapping
 * (link CPRF facilities to Energy-system facilities).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'energy')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/energy_helper.php';

$pdo = db();
$pageTitle = 'Energy Efficiency | LGU Facilities Reservation';

$canCreate = frs_can_create($role, 'energy');
$canUpdate = frs_can_update($role, 'energy');
$syncEnabled = energy_api_enabled();

$message = '';
$messageType = '';
$hasTables = frs_energy_tables_exist($pdo);

$tab = (string)($_GET['tab'] ?? 'readings');
if (!in_array($tab, ['readings', 'recommendations', 'mapping'], true)) {
    $tab = 'readings';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $hasTables) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } elseif ($_POST['action'] === 'add_reading' && $canCreate) {
        $month = (string)($_POST['reading_month'] ?? ''); // "YYYY-MM" from <input type=month>
        $parts = explode('-', $month);
        try {
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                throw new InvalidArgumentException('Please choose a valid reading month.');
            }
            $readingId = frs_energy_save_reading($pdo, [
                'facility_id' => (int)($_POST['facility_id'] ?? 0),
                'year' => (int)$parts[0],
                'month' => (int)$parts[1],
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'previous_reading_kwh' => (float)($_POST['previous_reading_kwh'] ?? 0),
                'current_reading_kwh' => (float)($_POST['current_reading_kwh'] ?? 0),
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'recorded_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $push = $syncEnabled
                ? frs_energy_push_reading($pdo, $readingId)
                : ['success' => false, 'error' => 'Sync disabled — reading saved locally as pending.'];
            if ($push['success']) {
                $message = 'Reading saved and pushed to the Energy system.';
                $messageType = 'success';
            } else {
                $message = 'Reading saved locally. Push to Energy system pending: ' . (string)$push['error'];
                $messageType = 'success';
            }
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to save reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'save_mapping' && $canUpdate) {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $pair = trim((string)($_POST['energy_facility'] ?? '')); // "id|name"
        $sep = strpos($pair, '|');
        if ($facilityId <= 0 || $sep === false) {
            $message = 'Please choose an Energy-system facility.';
            $messageType = 'error';
        } else {
            $energyFacilityId = (int)substr($pair, 0, $sep);
            $energyFacilityName = substr($pair, $sep + 1);
            frs_energy_save_mapping($pdo, $facilityId, $energyFacilityId, $energyFacilityName, (int)($_SESSION['user_id'] ?? 0) ?: null);
            $message = 'Facility mapping saved.';
            $messageType = 'success';
        }
        $tab = 'mapping';
    } elseif ($_POST['action'] === 'sync_now' && $canUpdate) {
        if (!$syncEnabled) {
            $message = 'Sync is disabled (ENERGY_SYNC_ENABLED=false).';
            $messageType = 'error';
        } else {
            $summary = frs_energy_run_sync($pdo);
            $message = sprintf(
                'Sync finished: %d reading(s) pushed, %d failed, %d recommendation(s) updated.%s',
                $summary['pushed'],
                $summary['push_failed'],
                $summary['recommendations_upserted'],
                $summary['errors'] !== [] ? ' First issue: ' . $summary['errors'][0] : ''
            );
            $messageType = $summary['errors'] === [] ? 'success' : 'error';
        }
    }
}

$facilities = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$mapping = $hasTables ? frs_energy_get_mapping($pdo) : [];
$syncState = $hasTables ? frs_energy_load_sync_state($pdo) : ['last_pull_at' => null, 'last_push_at' => null, 'last_summary' => null];
$configured = energy_api_base_url() !== '' && energy_api_token() !== '';

$latestReadings = [];
$pendingCount = 0;
if ($hasTables) {
    $rows = $pdo->query('
        SELECT r.*, f.name AS facility_name, u.name AS recorded_by_name
        FROM energy_meter_readings r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN users u ON u.id = r.recorded_by
        ORDER BY r.year DESC, r.month DESC, r.id DESC
        LIMIT 200
    ')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $fid = (int)$row['facility_id'];
        if (!isset($latestReadings[$fid])) {
            $latestReadings[$fid] = $row;
        }
        if (in_array($row['sync_status'], ['pending', 'failed'], true)) {
            $pendingCount++;
        }
    }
}

$recommendations = [];
if ($hasTables && $tab === 'recommendations') {
    $filterFacility = (int)($_GET['facility_id'] ?? 0);
    $sql = '
        SELECT c.*, f.name AS facility_name
        FROM energy_recommendations_cache c
        LEFT JOIN facilities f ON f.id = c.facility_id
        ' . ($filterFacility > 0 ? 'WHERE c.facility_id = :fid' : '') . '
        ORDER BY c.year DESC, c.month DESC, c.id DESC
        LIMIT 100
    ';
    $stmt = $pdo->prepare($sql);
    if ($filterFacility > 0) {
        $stmt->bindValue('fid', $filterFacility, PDO::PARAM_INT);
    }
    $stmt->execute();
    $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$energyFacilities = [];
$energyFacilitiesError = null;
if ($tab === 'mapping' && $canUpdate) {
    $result = fetchEnergyFacilities();
    $energyFacilities = $result['data'];
    $energyFacilitiesError = $result['error'];
}

$monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$tabUrl = static fn (string $t): string => base_path() . '/dashboard/energy-efficiency?tab=' . $t;

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Energy Efficiency</span>
    </div>
    <?= frs_page_title('Energy Efficiency (LGU Energy)', 'Record monthly electricity meter readings per facility, push them to the LGU Energy system, and review engineer-approved energy-saving recommendations.'); ?>
</div>

<?php if ($message): ?>
    <div class="message <?= htmlspecialchars($messageType); ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!$hasTables): ?>
    <section class="booking-card">
        <h2>Setup required</h2>
        <p style="color:#8b95b5;">Run <code>database/migration_add_energy_integration.sql</code> to create the energy integration tables.</p>
    </section>
<?php else: ?>

<section class="booking-card" style="margin-bottom:1.25rem;">
    <div style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:center; justify-content:space-between;">
        <div>
            <h2 style="margin-bottom:0.25rem;">Connection</h2>
            <p style="color:#8b95b5; margin:0;">
                <?php if (!$configured): ?>
                    Not configured — set <code>ENERGY_API_URL</code> and <code>ENERGY_API_TOKEN</code> in .env.
                <?php elseif (!$syncEnabled): ?>
                    Configured, but sync is disabled (<code>ENERGY_SYNC_ENABLED=false</code>).
                <?php else: ?>
                    Configured.
                    Last push: <?= htmlspecialchars($syncState['last_push_at'] ?? 'never'); ?> ·
                    Last recommendations pull: <?= htmlspecialchars($syncState['last_pull_at'] ?? 'never'); ?> ·
                    Unsynced readings: <?= (int)$pendingCount; ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($canUpdate): ?>
            <form method="POST" action="<?= htmlspecialchars($tabUrl($tab)); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="sync_now">
                <button type="submit" class="btn-primary" <?= ($configured && $syncEnabled) ? '' : 'disabled'; ?>>Sync Now</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<nav class="booking-hub-tabs" aria-label="Energy sections">
    <a class="booking-hub-tab <?= $tab === 'readings' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('readings')); ?>">Meter Readings</a>
    <a class="booking-hub-tab <?= $tab === 'recommendations' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('recommendations')); ?>">Recommendations</a>
    <?php if ($canUpdate): ?>
        <a class="booking-hub-tab <?= $tab === 'mapping' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a>
    <?php endif; ?>
</nav>

<?php if ($tab === 'readings'): ?>
    <div class="booking-wrapper">
        <?php if ($canCreate): ?>
        <section class="booking-card">
            <h2>Add Meter Reading</h2>
            <p style="color:#8b95b5; margin-bottom:1rem;">One reading per facility per month. The previous value auto-fills from the facility's last reading.</p>
            <form method="POST" action="<?= htmlspecialchars($tabUrl('readings')); ?>" class="booking-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="add_reading">
                <label>
                    Facility
                    <select name="facility_id" id="energy-facility-select" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <option value="">— Select facility —</option>
                        <?php foreach ($facilities as $f): ?>
                            <?php $last = $latestReadings[(int)$f['id']] ?? null; ?>
                            <option value="<?= (int)$f['id']; ?>" data-prev="<?= $last !== null ? htmlspecialchars((string)$last['current_reading_kwh']) : ''; ?>">
                                <?= htmlspecialchars($f['name']); ?><?= isset($mapping[(int)$f['id']]) ? '' : ' (unmapped)'; ?>
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
                <label style="margin-top:0.75rem; display:block;">
                    Previous Meter Reading (kWh)
                    <input type="number" step="0.01" min="0" name="previous_reading_kwh" id="energy-prev-input" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <small style="color:#8b95b5;">Auto-filled and locked when the facility already has a reading.</small>
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Current Meter Reading (kWh)
                    <input type="number" step="0.01" min="0" name="current_reading_kwh" id="energy-curr-input" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <p id="energy-consumption-preview" style="margin-top:0.5rem; color:#0066cc; font-weight:600;"></p>
                <label style="margin-top:0.75rem; display:block;">
                    Notes (optional)
                    <textarea name="notes" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn-primary" style="margin-top:1rem;">Save Reading</button>
            </form>
            <script>
            (function () {
                'use strict';
                var sel = document.getElementById('energy-facility-select');
                var prev = document.getElementById('energy-prev-input');
                var curr = document.getElementById('energy-curr-input');
                var preview = document.getElementById('energy-consumption-preview');
                if (!sel || !prev || !curr || !preview) return;
                function updatePreview() {
                    var p = parseFloat(prev.value), c = parseFloat(curr.value);
                    preview.textContent = (!isNaN(p) && !isNaN(c) && c >= p)
                        ? 'Consumption: ' + (c - p).toFixed(2) + ' kWh'
                        : '';
                }
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    var last = opt ? opt.getAttribute('data-prev') : '';
                    if (last) { prev.value = last; prev.readOnly = true; }
                    else { prev.value = ''; prev.readOnly = false; }
                    updatePreview();
                });
                prev.addEventListener('input', updatePreview);
                curr.addEventListener('input', updatePreview);
            })();
            </script>
        </section>
        <?php endif; ?>

        <section class="booking-card">
            <h2>Latest Readings per Facility</h2>
            <?php if ($latestReadings === []): ?>
                <p style="color:#8b95b5; text-align:center; padding:2rem;">No readings recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Facility</th><th>Period</th><th>Consumption</th><th>Sync</th><th>Recorded By</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($latestReadings as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$r['facility_name']); ?></td>
                                    <td><?= htmlspecialchars(($monthNames[(int)$r['month']] ?? $r['month']) . ' ' . $r['year']); ?></td>
                                    <td><?= number_format((float)$r['consumption_kwh'], 2); ?> kWh</td>
                                    <td>
                                        <span class="status-badge <?= $r['sync_status'] === 'synced' ? 'active' : ($r['sync_status'] === 'failed' ? 'offline' : 'maintenance'); ?>"
                                              <?= $r['sync_error'] !== null ? 'title="' . htmlspecialchars((string)$r['sync_error']) . '"' : ''; ?>>
                                            <?= htmlspecialchars(ucfirst((string)$r['sync_status'])); ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string)($r['recorded_by_name'] ?? '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

<?php elseif ($tab === 'recommendations'): ?>
    <section class="booking-card">
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:center; justify-content:space-between; margin-bottom:1rem;">
            <h2 style="margin:0;">Energy-Saving Recommendations</h2>
            <form method="GET" action="<?= htmlspecialchars(base_path() . '/dashboard/energy-efficiency'); ?>">
                <input type="hidden" name="tab" value="recommendations">
                <select name="facility_id" onchange="this.form.submit()" style="padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <option value="0">All facilities</option>
                    <?php foreach ($facilities as $f): ?>
                        <option value="<?= (int)$f['id']; ?>" <?= ((int)($_GET['facility_id'] ?? 0)) === (int)$f['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($f['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <p style="color:#8b95b5;">Engineer-approved advice from the LGU Energy system. Last pulled: <?= htmlspecialchars($syncState['last_pull_at'] ?? 'never'); ?>.</p>
        <?php if ($recommendations === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No recommendations cached yet. Use Sync Now after readings have been pushed and reviewed in the Energy system.</p>
        <?php else: ?>
            <?php foreach ($recommendations as $reco): ?>
                <article style="border:1px solid #edf2f7; border-radius:8px; padding:1rem; margin-bottom:0.9rem;">
                    <div style="display:flex; flex-wrap:wrap; gap:0.5rem 1rem; align-items:baseline; justify-content:space-between;">
                        <strong><?= htmlspecialchars((string)($reco['facility_name'] ?? ('Energy facility #' . (int)$reco['energy_facility_id'] . ' (unmapped)'))); ?></strong>
                        <span style="color:#8b95b5;">
                            <?= htmlspecialchars(($monthNames[(int)$reco['month']] ?? $reco['month']) . ' ' . $reco['year']); ?>
                            · <span class="status-badge active"><?= htmlspecialchars(ucfirst((string)$reco['status'])); ?></span>
                        </span>
                    </div>
                    <p style="margin:0.6rem 0 0.3rem;"><?= nl2br(htmlspecialchars((string)$reco['generated_message'])); ?></p>
                    <?php if (!empty($reco['engineer_recommendation'])): ?>
                        <p style="margin:0.3rem 0; color:#0d7a43;"><strong>Engineer:</strong> <?= nl2br(htmlspecialchars((string)$reco['engineer_recommendation'])); ?></p>
                    <?php endif; ?>
                    <small style="color:#8b95b5;">
                        <?php if ($reco['expected_savings_kwh'] !== null): ?>Expected savings: <?= number_format((float)$reco['expected_savings_kwh'], 2); ?> kWh · <?php endif; ?>
                        <?php if (!empty($reco['target_date'])): ?>Target: <?= htmlspecialchars((string)$reco['target_date']); ?> · <?php endif; ?>
                        Fetched: <?= htmlspecialchars((string)$reco['fetched_at']); ?>
                    </small>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'mapping' && $canUpdate): ?>
    <section class="booking-card">
        <h2>Facility Mapping</h2>
        <p style="color:#8b95b5;">Link each CPRF facility to its counterpart in the Energy system. Suggested matches are pre-selected — confirm or override, then save per row.</p>
        <?php if ($energyFacilitiesError !== null): ?>
            <p style="color:#b23030; padding:1rem; background:#fdecee; border-radius:8px;"><?= htmlspecialchars($energyFacilitiesError); ?></p>
        <?php elseif ($energyFacilities === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No facilities returned from the Energy system.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>CPRF Facility</th><th>Energy-System Facility</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($facilities as $f): ?>
                            <?php
                            $fid = (int)$f['id'];
                            $current = $mapping[$fid] ?? null;
                            $suggested = $current === null ? frs_energy_suggest_match((string)$f['name'], $energyFacilities) : null;
                            $selectedId = $current['energy_facility_id'] ?? ($suggested['id'] ?? 0);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($f['name']); ?></td>
                                <td>
                                    <form method="POST" action="<?= htmlspecialchars($tabUrl('mapping')); ?>" style="display:flex; gap:0.5rem; align-items:center;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="save_mapping">
                                        <input type="hidden" name="facility_id" value="<?= $fid; ?>">
                                        <select name="energy_facility" required style="padding:0.4rem; border:1px solid #e0e6ed; border-radius:6px; min-width:220px;">
                                            <option value="">— Select —</option>
                                            <?php foreach ($energyFacilities as $ef): ?>
                                                <?php $efId = (int)($ef['id'] ?? 0); $efName = (string)($ef['name'] ?? ''); ?>
                                                <option value="<?= $efId . '|' . htmlspecialchars($efName); ?>" <?= $efId === $selectedId ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($efName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-primary" style="padding:0.4rem 0.9rem;">Save</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($current !== null): ?>
                                        <span class="status-badge active">Mapped</span>
                                    <?php elseif ($suggested !== null): ?>
                                        <span class="status-badge maintenance" title="Name match score: <?= (int)$suggested['score']; ?>">Suggested</span>
                                    <?php else: ?>
                                        <span class="status-badge offline">Unmapped</span>
                                    <?php endif; ?>
                                </td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php endif; // hasTables ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
```

- [ ] **Step 4: Lint and smoke-test**

Run: `php -l resources/views/pages/dashboard/energy_efficiency.php`
Expected: `No syntax errors detected`.
Manual smoke test (XAMPP or `php -S localhost:8080 index.php`): log in as Admin → sidebar Operations shows "Energy Efficiency" → all three tabs render → adding a reading for a mapped facility succeeds; unmapped facility saves locally with a `Failed` badge (tooltip shows the mapping error). Log in as a Resident → the sidebar entry is absent and `/dashboard/energy-efficiency` redirects to `/dashboard`.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/dashboard/energy_efficiency.php index.php resources/views/components/sidebar_dashboard.php
git commit -m "Add Energy Efficiency module page under Operations"
```

---

### Task 9: Cron sync script, manual sync endpoint, integration status card

**Files:**
- Create: `scripts/sync_energy_integration.php`
- Create: `public/api/sync-energy.php`
- Modify: `config/integration_status.php` (add `frs_integration_energy_status()`; register in `frs_integration_status_all()`)
- Modify: `README.md` (add cron row to the Scheduled tasks table)

**Interfaces:**
- Consumes: `frs_energy_run_sync()`, `frs_energy_load_sync_state()`, `energy_api_base_url()/token()/enabled()` from Tasks 6–7.
- Produces: `php scripts/sync_energy_integration.php` (CLI, exit 0/1) and `POST /public/api/sync-energy.php` (Admin/Staff session). System Settings integration card slug `energy`.

- [ ] **Step 1: Create `scripts/sync_energy_integration.php`** (mirror of `sync_ipms_projects.php`)

```php
<?php
declare(strict_types=1);

/**
 * Sync CPRF <-> LGU Energy Efficiency integration.
 *
 * Push side: retries all pending/failed manual meter readings to the Energy
 * system's POST /api/v1/cprf/facility-readings. Pull side: fetches
 * engineer-approved recommendations into energy_recommendations_cache.
 *
 * Usage (cron example, hourly):
 *   php /path/to/scripts/sync_energy_integration.php
 *   php /path/to/scripts/sync_energy_integration.php --dry-run   # report only, no push/pull
 *   php /path/to/scripts/sync_energy_integration.php --verbose   # full error list
 *
 * Manual (Admin/Staff session via browser POST) also supported via
 * public/api/sync-energy.php.
 */

if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/energy_helper.php';

$isCli = (PHP_SAPI === 'cli');
$dryRun = $isCli && in_array('--dry-run', $argv ?? [], true);
$verbose = $isCli && in_array('--verbose', $argv ?? [], true);

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('Content-Type: application/json; charset=utf-8');
    $isStaff = ($_SESSION['user_authenticated'] ?? false)
        && in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);
    if (!$isStaff) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin or Staff login required.']);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Use POST for manual sync.']);
        exit;
    }
}

function energySyncOutputAndExit(array $payload, int $exitCode = 0): void
{
    if (PHP_SAPI === 'cli') {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        if (empty($payload['success']) && $exitCode !== 0) {
            http_response_code($exitCode >= 400 ? $exitCode : 500);
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    exit($exitCode === 0 ? 0 : 1);
}

try {
    if (!energy_api_enabled()) {
        energySyncOutputAndExit([
            'success' => false,
            'message' => 'Energy sync is disabled (ENERGY_SYNC_ENABLED=false).',
        ], 1);
    }

    $pdo = db();
    if (!frs_energy_tables_exist($pdo)) {
        energySyncOutputAndExit([
            'success' => false,
            'message' => 'Energy integration tables missing. Run database/migration_add_energy_integration.sql.',
        ], 1);
    }

    if ($dryRun) {
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM energy_meter_readings WHERE sync_status IN ('pending','failed')")->fetchColumn();
        energySyncOutputAndExit([
            'success' => true,
            'message' => 'Dry run: nothing pushed or pulled.',
            'would_push' => $pending,
            'configured' => energy_api_base_url() !== '' && energy_api_token() !== '',
        ], 0);
    }

    $summary = frs_energy_run_sync($pdo);

    energySyncOutputAndExit([
        'success' => $summary['success'],
        'message' => $summary['success'] ? 'Energy sync completed.' : 'Energy sync completed with errors.',
        'pushed' => $summary['pushed'],
        'push_failed' => $summary['push_failed'],
        'recommendations_upserted' => $summary['recommendations_upserted'],
        'errors' => $verbose ? $summary['errors'] : array_slice($summary['errors'], 0, 3),
        'ran_at' => $summary['ran_at'],
    ], $summary['success'] ? 0 : 1);
} catch (Throwable $e) {
    energySyncOutputAndExit([
        'success' => false,
        'message' => 'Energy sync crashed.',
        'error' => $e->getMessage(),
    ], 1);
}
```

- [ ] **Step 2: Create `public/api/sync-energy.php`** (mirror of `sync-ipms-projects.php`)

```php
<?php
/**
 * API Endpoint for Manual Energy Integration Sync
 *
 * Lets Admin/Staff trigger the Energy push/pull sync via the Energy Efficiency
 * page or System Settings "Sync Now" button. Pushes pending manual meter
 * readings and pulls engineer-approved recommendations (see
 * config/energy_helper.php).
 *
 * Authentication: Admin/Staff session required
 * Methods: POST
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/energy_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isStaff = ($_SESSION['user_authenticated'] ?? false)
    && in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);

if (!$isStaff) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin or Staff login required.']);
    exit;
}

try {
    if (!energy_api_enabled()) {
        echo json_encode(['success' => false, 'message' => 'Energy sync is disabled (ENERGY_SYNC_ENABLED=false).']);
        exit;
    }

    $pdo = db();
    $summary = frs_energy_run_sync($pdo);

    echo json_encode([
        'success' => $summary['success'],
        'message' => $summary['success'] ? 'Energy sync completed.' : 'Energy sync completed with errors.',
        'pushed' => $summary['pushed'],
        'push_failed' => $summary['push_failed'],
        'recommendations_upserted' => $summary['recommendations_upserted'],
        'errors' => $summary['errors'],
        'ran_at' => $summary['ran_at'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Energy sync crashed.', 'error' => $e->getMessage()]);
}
```

- [ ] **Step 3: Register the integration status card**

In `config/integration_status.php`, add before `frs_integration_status_all()`:

```php
/**
 * @return array<string, mixed>
 */
function frs_integration_energy_status(PDO $pdo): array
{
    require_once __DIR__ . '/energy_helper.php';

    $configured = energy_api_base_url() !== '' && energy_api_token() !== '';
    $tablesReady = frs_energy_tables_exist($pdo);
    $state = $tablesReady ? frs_energy_load_sync_state($pdo) : ['last_pull_at' => null, 'last_push_at' => null, 'last_summary' => null];
    $summary = is_array($state['last_summary']) ? $state['last_summary'] : [];
    $lastSync = $state['last_pull_at'] ?? $state['last_push_at'];
    $hasSynced = $lastSync !== null && $lastSync !== '';

    $pendingReadings = 0;
    if ($tablesReady) {
        try {
            $pendingReadings = (int)$pdo->query("SELECT COUNT(*) FROM energy_meter_readings WHERE sync_status IN ('pending','failed')")->fetchColumn();
        } catch (Throwable $e) {
            $pendingReadings = 0;
        }
    }

    return [
        'slug' => 'energy',
        'name' => 'Energy Efficiency (LGU Energy)',
        'description' => 'Pushes manual facility meter readings to the LGU Energy system and pulls engineer-approved energy-saving recommendations.',
        'connected' => $configured && $hasSynced && empty($summary['errors']),
        'status_label' => !$configured
            ? 'Not configured'
            : ($hasSynced ? (empty($summary['errors']) ? 'Connected' : 'Sync warnings') : 'Not synced yet'),
        'status_class' => !$configured
            ? 'offline'
            : (($hasSynced && empty($summary['errors'])) ? 'active' : ($hasSynced ? 'maintenance' : 'offline')),
        'last_sync' => $lastSync,
        'preview' => !$configured,
        'can_sync' => $configured && $tablesReady && energy_api_enabled(),
        'sync_type' => 'ajax',
        'sync_url' => function_exists('base_path') ? base_path() . '/public/api/sync-energy.php' : '/public/api/sync-energy.php',
        'manage_url' => function_exists('base_path') ? base_path() . '/dashboard/energy-efficiency' : '/dashboard/energy-efficiency',
        'metrics' => [
            'Readings pushed (last run)' => (int)($summary['pushed'] ?? 0),
            'Push failures (last run)' => (int)($summary['push_failed'] ?? 0),
            'Recommendations updated' => (int)($summary['recommendations_upserted'] ?? 0),
            'Unsynced readings' => $pendingReadings,
        ],
        'errors' => (array)($summary['errors'] ?? []),
        'cron_hint' => 'php scripts/sync_energy_integration.php',
    ];
}
```

And in `frs_integration_status_all()`, add to the returned array after `frs_integration_uman_status($pdo),`:

```php
        frs_integration_energy_status($pdo),
```

- [ ] **Step 4: Add the cron row to `README.md`**

In the "Scheduled tasks (cron)" table, add:

```markdown
| `scripts/sync_energy_integration.php` | Push meter readings to LGU Energy; pull recommendations |
```

- [ ] **Step 5: Verify**

Run: `php -l scripts/sync_energy_integration.php && php -l public/api/sync-energy.php && php -l config/integration_status.php`
Expected: no syntax errors.
Run: `php scripts/sync_energy_integration.php`
Expected (with the energy app not running locally): JSON with `"success": false` and a connection/config error — proves wiring without the partner up. If the energy app IS running locally (`php artisan serve` in `INTEGRATION/Lgu1-energy`), expected `"success": true` with counts.

- [ ] **Step 6: Commit**

```bash
git add scripts/sync_energy_integration.php public/api/sync-energy.php config/integration_status.php README.md
git commit -m "Add energy sync cron script, manual sync endpoint, and status card"
```

---

### Task 10: End-to-end verification (both systems)

**Files:** none created — verification only.

**Interfaces:**
- Consumes: everything above.
- Produces: verified integration + green suites on both repos.

- [ ] **Step 1: Run both test suites**

```bash
vendor/bin/phpunit
cd INTEGRATION/Lgu1-energy && php artisan test && cd ../..
```
Expected: both fully green.

- [ ] **Step 2: End-to-end manual UAT**

1. Start the energy app: `cd INTEGRATION/Lgu1-energy && php artisan serve` (http://localhost:8000). Ensure its `.env` has `CPRF_INTEGRATION_TOKEN` unset (dev default applies) and migrations ran (`php artisan migrate`).
2. In CPRF `.env`: `ENERGY_API_URL=http://localhost:8000`, `ENERGY_API_TOKEN=CPRF_ENERGY_SHARED_KEY_2026`, `ENERGY_SYNC_ENABLED=true`.
3. CPRF Admin → Energy Efficiency → Facility Mapping: map one CPRF facility to an energy facility (create one in the energy app first if empty).
4. Meter Readings tab: add a reading for the mapped facility → badge shows **Synced**.
5. Energy app UI → Energy Monitoring: the record appears for that facility/month with the computed kWh (`input_source=cprf` in DB).
6. Energy app: create + approve an `energy_saving_recommendations` row for that facility/period (or via tinker: `EnergySavingRecommendation::create([...status => 'approved'...])`).
7. CPRF → Sync Now → Recommendations tab shows the recommendation under the mapped facility.
8. Stop the energy app; add another reading → saves locally with **Failed** badge; restart app → Sync Now → badge flips to **Synced**.
9. Resident login: no sidebar entry; direct URL redirects to dashboard.
10. System Settings integrations: "Energy Efficiency (LGU Energy)" card shows status + metrics and its Sync button works.

- [ ] **Step 3: Record any fixes, final commits on both repos, push energy branch**

```bash
cd INTEGRATION/Lgu1-energy && git push origin CPRF-Integration && cd ../..
git log --oneline -8
```
