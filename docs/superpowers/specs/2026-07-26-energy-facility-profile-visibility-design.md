# Energy Facility Profile Visibility + Reading Surfacing — Design

**Date:** 2026-07-26
**Status:** Approved (pending user spec review)
**Scope:** Two codebases — CPRF (this repo) and the LGU Energy Efficiency Laravel app (`INTEGRATION/Lgu1-energy`, its own git clone; the user controls both and can merge/deploy either).

## 1. Background

The existing Energy Efficiency integration (see `2026-07-23-energy-efficiency-integration-design.md`) assumed pushed readings would "surface automatically in their existing monitoring screens because they live in `energy_records`." Investigation for this design found that assumption was wrong:

- `CprfFacilityReadingController::store` correctly writes to `energy_records` with `input_source = 'cprf'`, but with **`meter_id = NULL`** (it's a facility-level aggregate, not tied to one physical meter).
- Every itemized Energy-side view (dashboard, Monthly Records, trend charts, exports) filters with `whereHas('meter', fn($q) => $q->where('meter_type', 'main'))`. Rows with `meter_id = NULL` fail this join and silently disappear from every itemized screen the Energy team actually looks at.

Separately, the Energy team can set a per-facility **`EnergyProfile`** (utility provider, contract account, baseline kWh, meter details, engineer-approval status) via their own UI at `/modules/facilities/{facility}/energy-profile`. This data has never had any path back to CPRF — CPRF has no visibility into what profile the Energy team has configured for any of its facilities.

This design covers two fixes:

1. **Reading visibility fix (Energy side):** surface CPRF-pushed facility-level readings in Energy's own itemized views, tagged so they're distinguishable from meter-based readings.
2. **Facility profile visibility (both sides):** a new Energy-side endpoint exposing profile data per CPRF-mapped facility, and a new CPRF-side pull + "Facility Profiles" tab to display it.

## 2. Chosen approach

Both fixes extend the existing CPRF-driven push/pull pattern rather than introducing a new integration style:

- The profile pull is a **new step inside the existing `frs_energy_run_sync()`** cycle (alongside the existing reading push and recommendations pull) — same "Sync Now" button and cron, no new schedule or button.
- The reading-visibility fix is a **query-level change** on the Energy side (loosen the `whereHas('meter', ...)` filters), not a data-model change — no new tables on the Energy side, no re-ingestion of already-pushed readings needed.

Rejected alternative: giving facility profiles their own separate pull button/timestamp — rejected because it adds an operational surface (a sync path that can silently fall out of date) for no real benefit; profiles change infrequently and piggybacking keeps one mental model ("Sync Now refreshes everything from Energy").

## 3. Energy-side changes (`Lgu1-energy`)

### 3.1 Reading visibility fix

- `EnergyMonitoringController` (dashboard queries), `routes/energy.php` (trend, check-duplicate, get-kwh-consumed, export-excel, annual exports), and `routes/modules.php:126-130` (facility Monthly Records page): change
  `->whereHas('meter', fn($q) => $q->where('meter_type', 'main'))`
  to
  `->where(fn($q) => $q->whereHas('meter', fn($mq) => $mq->where('meter_type', 'main'))->orWhere('input_source', 'cprf'))`.
- Aggregate sums/totals must include these rows exactly once per facility/period. Implementation must verify whether a facility can have both meter-based rows and a CPRF facility-level row for the same period; if so, the CPRF row is additive (it represents consumption not already captured by a tracked meter) and must not be excluded or double-counted against meter totals.
- Blade views for Monthly Records / dashboard: wherever a meter name/number is displayed, CPRF rows (`meter_id IS NULL`) render a **"Facility-Level (CPRF)"** badge/label instead of blank.

### 3.2 New endpoint: `GET /api/v1/cprf/facility-profiles`

New controller `App\Http\Controllers\Api\CprfFacilityProfileController@index`, registered in the existing `Route::prefix('v1/cprf')->middleware(['cprf.integration', 'throttle:60,1'])` group (same auth as the existing reading/recommendations endpoints — no new middleware needed).

- **Filters:** `updated_since` (ISO timestamp, matches `EnergyProfile.updated_at`).
- **Scope:** only facilities with a non-null `external_ref` (i.e. CPRF-mapped), returning each facility's latest `EnergyProfile`.
- **Pagination:** same convention as `/recommendations` (`page`, `per_page`, default 25 / max 100).
- **Row shape:** `facility_external_ref` (CPRF facility id), `energy_facility_id`, `electric_meter_no`, `utility_provider`, `contract_account_no`, `main_energy_source`, `backup_power`, `transformer_capacity`, `number_of_meters`, `baseline_kwh`, `engineer_approved`, `baseline_locked`, `baseline_source`, `updated_at`.
- Facilities with no `EnergyProfile` row yet are simply omitted from the response (CPRF renders these as "no profile set yet", not an error).

### 3.3 Docs & tests

- `docs/integration-api.md`: add the `facility-profiles` endpoint (auth, filters, sample payload) alongside the existing CPRF section.
- Pest Feature tests: `CprfFacilityProfileTest.php` (auth required, only `external_ref`-mapped facilities returned, `updated_since` filtering, pagination) — modeled on the existing `CprfRecommendationsTest.php`.
- Extend the Monthly Records / dashboard feature tests to assert a `meter_id=NULL, input_source='cprf'` record appears with the CPRF badge and is counted exactly once in totals.

## 4. CPRF-side changes (this repo)

### 4.1 Data model — new migration `database/migration_add_energy_profile_cache.sql`

**`energy_profile_cache`**
- `id` PK, `facility_id` INT UNIQUE FK→facilities, `electric_meter_no` VARCHAR NULL, `utility_provider` VARCHAR NULL, `contract_account_no` VARCHAR NULL, `main_energy_source` VARCHAR NULL, `backup_power` VARCHAR NULL, `transformer_capacity` VARCHAR NULL, `number_of_meters` INT NULL, `baseline_kwh` DECIMAL(14,2) NULL, `engineer_approved` TINYINT(1) DEFAULT 0, `baseline_locked` TINYINT(1) DEFAULT 0, `baseline_source` VARCHAR NULL, `energy_updated_at` DATETIME NULL (their `updated_at`, drives the watermark), `synced_at` DATETIME NULL.

### 4.2 Service client & helper

- **`services/energy_api.php`**: add `fetchEnergyFacilityProfiles($query)` — same GET/bearer-auth shape as `fetchEnergyRecommendations()`.
- **`config/energy_helper.php`**: add `frs_energy_pull_profiles(PDO $pdo)` — modeled directly on `frs_energy_pull_recommendations()`: loads watermark from `energy_sync_state`, paginates through the endpoint, resolves `facility_external_ref` → CPRF `facility_id` via `energy_facility_map`, upserts into `energy_profile_cache`, tracks the max `updated_at` as the new watermark. Skips rows for unmapped facilities (shouldn't occur since the endpoint already filters to mapped facilities, but defensive).
- **`frs_energy_run_sync()`**: add the profile pull as a new step (after the recommendations pull), with its own try/catch so a profile-pull failure doesn't block or roll back the reading push / recommendations pull already completed in the same run. Failure is recorded in the sync run summary (same structure used for the existing 3-consecutive-failure admin notification).

### 4.3 Module UI — `resources/views/pages/dashboard/energy_efficiency.php`

- **New 4th tab: "Facility Profiles."** One card per CPRF facility with a mapping (`.booking-card` styling), showing: Utility Provider, Electric Meter No., Contract Account No., Main Energy Source, Backup Power, Transformer Capacity, Number of Meters, Baseline kWh, Baseline Source, plus `[Approved]`/`[Pending Approval]` and `[Baseline Locked]` status pills. Fields with no value render as "—". A "Last updated from Energy" timestamp shows `energy_updated_at`.
  - Mapped facility with no profile pulled yet: empty-state card — "No energy profile set yet — the Energy team hasn't configured this facility."
  - Unmapped facilities: not listed; a small note points to the Facility Mapping tab.
- **Page-wide modernization** (all 4 tabs, same design tokens — `--gov-blue`, `.booking-card`, `.btn-primary`): normalize status-pill classes into purpose-named modifiers (`.status-badge--approved`, `.status-badge--pending`, `.status-badge--locked`, `.status-badge--failed`) instead of the current repurposed `active`/`maintenance`/`offline` classes, consistent card spacing/hierarchy, and a responsive two-column grid for profile cards on wider viewports. No new colors or component library introduced.

### 4.4 Sync flow

- No new button, no new "last pull" timestamp shown separately — the profile pull is invisible plumbing inside the existing "Sync Now" flow and hourly cron (`scripts/sync_energy_integration.php`), which already covers push + recommendations pull.
- Audit: profile-cache updates are not separately audit-logged (read-only mirrored data, same treatment as the recommendations cache).

## 5. Security

- Same bearer-token auth reused for the new endpoint — no new secret to manage.
- CSRF tokens on any CPRF form; PDO prepared statements only; `htmlspecialchars()` on all profile field output (externally sourced data).
- No new write path from CPRF into Energy for profiles — this is pull-only, one direction.

## 6. Testing

- **CPRF (PHPUnit, `tests/Unit/EnergyHelperTest.php`):** `frs_energy_pull_profiles()` — success upsert, partial/auth failure (rest of sync still completes), unmapped-facility rows skipped, null-field handling, watermark advancement.
- **Energy side (Pest):** `CprfFacilityProfileTest.php` (auth, mapped-only filtering, `updated_since`, pagination); extended Monthly Records/dashboard tests for CPRF-row visibility and correct-once totals.
- **Manual UAT:** set/update a profile in Energy for a mapped facility → click Sync Now in CPRF → confirm it appears in the new tab with correct fields/badges → toggle `engineer_approved` in Energy → Sync Now again → confirm badge updates. Push a reading from CPRF → confirm it now appears in Energy's Monthly Records page tagged "Facility-Level (CPRF)" and is included in totals. Check light/dark theme rendering of the new tab and all four status-pill variants.

## 7. Out of scope

- Any write-back from CPRF into Energy's profile data (profiles remain Energy-team-owned and Energy-team-edited).
- Real-time delivery (webhooks) for profile changes — pull-based via existing sync cycle only.
- Deleting/soft-removing a cached profile if the Energy-side profile is ever deleted (not a supported flow today; cache would go stale until manually addressed — acceptable given profiles are rarely deleted).
- Submeter-level profile detail, resident-facing views, i18n.
