# LGU Energy Efficiency Integration — Design

**Date:** 2026-07-23
**Status:** Approved (pending user spec review)
**Scope:** Two codebases — CPRF (this repo) and the LGU Energy Efficiency Laravel app (`INTEGRATION/Lgu1-energy`, its own git clone; all energy-side work goes on the existing `CPRF-Integration` branch and is pushed to `origin/CPRF-Integration`).

## 1. Goal

CPRF becomes the fourth system integrated with the LGU network (alongside CIMM, IPMS, UMAN). The integration is bidirectional:

1. **Outbound:** CPRF staff record manual monthly electricity meter readings per CPRF facility; CPRF pushes them to the energy system, where they feed its baseline/deviation/alert/recommendation pipeline.
2. **Inbound:** CPRF periodically pulls **engineer-approved** energy-saving recommendations from the energy system and displays them per facility.

A new **Energy Efficiency** module appears under the **Operations** group in the CPRF dashboard sidebar.

## 2. Chosen approach

**CPRF-driven push/pull with local cache.** CPRF is the active party; the energy system stays passive and only gains two stateless API endpoints. This mirrors:

- the energy app's existing pattern of per-partner token-guarded route groups (their CIMM group: dedicated prefix, dedicated middleware, dedicated shared token), and
- CPRF's existing integration pattern (IPMS/CIMM: `services/*_api.php` client + `config/*_helper.php` + dashboard page + cron sync script + manual sync endpoint).

Rejected alternatives: pull-only on both sides (requires building a poller inside their Laravel app — invasive), webhooks (no webhook infra on either side; overkill for monthly data).

## 3. Energy-side changes (`Lgu1-energy`, branch `CPRF-Integration`)

### 3.1 Auth & routing

- New middleware `App\Http\Middleware\AuthenticateCprfIntegration`: bearer token compared with `hash_equals` against `config('services.cprf_integration.token')` (env `CPRF_INTEGRATION_TOKEN`). Returns 503 if unset, 401 if invalid — identical behavior to their `AuthenticateIntegrationApi`.
- Middleware alias `cprf.integration` registered in `bootstrap/app.php` (same place as `integration.api` and `cimm.maintenance.sync`).
- Route group in `routes/api.php`: `Route::prefix('v1/cprf')->middleware(['cprf.integration', 'throttle:60,1'])`.
- `config/services.php` gains a `cprf_integration` entry; `.env.example` gains `CPRF_INTEGRATION_TOKEN`.

### 3.2 `POST /api/v1/cprf/facility-readings`

New controller `App\Http\Controllers\Api\CprfFacilityReadingController@store`.

**Request payload:**

| Field | Rules |
|---|---|
| `facility_id` | required, exists:facilities, facility not archived |
| `year` | required, integer |
| `month` | required, 1–12 |
| `previous_reading_kwh` | required, numeric ≥ 0 |
| `current_reading_kwh` | required, numeric, ≥ previous_reading_kwh |
| `reading_date` | required, date |
| `energy_cost` | optional, numeric |
| `rate_per_kwh` | optional, numeric |
| `notes` | optional, string |
| `external_ref` | optional, string — CPRF's local reading ID for traceability |
| `recorded_by_name` | optional, string |

**Behavior:**

- Computes `actual_kwh = current_reading_kwh − previous_reading_kwh`.
- **Upserts** into their `energy_records` table for (facility_id, year, month) — reusing the same record-creation/update code path their manual encoding UI uses, so `baseline_kwh`, `deviation`, and `alert` are computed identically and their recommendation engine consumes the data with no extra plumbing. (Exact reuse point — service vs. controller-extracted method — is an implementation-plan detail; the requirement is: identical computation, no duplicated business logic.)
- New migration adds nullable `input_source` (string, values `manual`/`cprf`, default `manual`) to `energy_records` — consistent with the `input_source` column their other reading tables already carry. CPRF-pushed rows get `cprf`.
- Response: 201/200 JSON with the stored record including computed `actual_kwh`, `deviation`, `alert`, and the energy-side record `id` (CPRF stores this as `external_record_id`).
- Repeat push for the same (facility, year, month) updates the row rather than erroring (idempotent upsert).

### 3.3 `GET /api/v1/cprf/recommendations`

Reads `energy_saving_recommendations` (existing table: facility_id, year, month, generated_message, engineer_recommendation, status, expected_savings_kwh, target_date, reviewed_by/at).

- **Filters:** `facility_id`, `year`, `month`, `status` (default `approved`), `updated_since` (ISO timestamp).
- **Pagination:** their `/api/v1` convention — `page`, `per_page` (default 25, max 100), Laravel paginator response shape.
- **Row shape:** `id`, `facility_id`, `facility_name`, `year`, `month`, `generated_message`, `engineer_recommendation`, `status`, `expected_savings_kwh`, `target_date`, `reviewed_at`, `updated_at`.

### 3.4 Docs & tests (their conventions)

- `docs/integration-api.md` updated with the CPRF section (auth, both endpoints, sample payloads/responses) — same documentation style as their existing integration API section.
- Laravel Feature tests: auth (missing token → 401, unset token → 503), validation failures, reading upsert (second push same period updates, not duplicates), recommendation status/`updated_since` filtering, pagination caps.
- **No energy-side UI changes.** Pushed readings surface automatically in their existing monitoring screens because they live in `energy_records`.

## 4. CPRF-side changes (this repo)

### 4.1 Data model — `database/migration_add_energy_integration.sql`

**`energy_facility_map`**
- `id` PK, `facility_id` INT UNIQUE FK→facilities, `energy_facility_id` INT, `energy_facility_name` VARCHAR (cached label), `mapped_by` INT FK→users, `mapped_at`, `updated_at`.

**`energy_meter_readings`**
- `id` PK, `facility_id` FK→facilities, `year`, `month`, UNIQUE(`facility_id`,`year`,`month`), `reading_date` DATE, `previous_reading_kwh` DECIMAL, `current_reading_kwh` DECIMAL, `consumption_kwh` DECIMAL (stored, = current − previous), `notes` TEXT NULL, `recorded_by` FK→users, `sync_status` ENUM(`pending`,`synced`,`failed`) DEFAULT `pending`, `synced_at` DATETIME NULL, `sync_error` TEXT NULL, `external_record_id` INT NULL (their `energy_records.id`), timestamps.
- Previous reading auto-fills from the facility's most recent reading's `current_reading_kwh`; only the first-ever reading for a facility requires typing the previous value manually.

**`energy_recommendations_cache`**
- `id` PK, `energy_recommendation_id` INT UNIQUE (their id), `energy_facility_id` INT, `facility_id` INT NULL FK→facilities (resolved via map; NULL and flagged when unmapped), `year`, `month`, `generated_message` TEXT, `engineer_recommendation` TEXT NULL, `status` VARCHAR, `expected_savings_kwh` DECIMAL NULL, `target_date` DATE NULL, `reviewed_at` DATETIME NULL, `fetched_at` DATETIME, timestamps.

**Permissions seed (same migration):** new `energy` module key inserted into `role_permissions` — Admin: full CRUD; Staff: create/read/update; Resident: none. Hardcoded fallback defaults added to `config/permissions.php`.

### 4.2 Configuration

- `.env.example`: `ENERGY_API_URL`, `ENERGY_API_TOKEN` (the value of their `CPRF_INTEGRATION_TOKEN`), `ENERGY_SYNC_ENABLED` (gates the cron script and Sync Now button; the module UI still renders with a "sync disabled" notice when off).
- `config/integration_status.php`: register the Energy integration alongside CIMM/IPMS/UMAN (connection health, last-sync tracking).

### 4.3 Service client & helper

- **`services/energy_api.php`** — thin cURL client (same shape as `ipms_api.php`): bearer auth, GET/POST JSON, timeouts, normalized `['ok' => bool, 'data' => ..., 'error' => ...]` returns. Functions: fetch energy facilities (for mapping), push facility reading, fetch recommendations.
- **`config/energy_helper.php`** — business logic: consumption computation/validation, previous-reading lookup, reading save + push orchestration, recommendation pull/upsert with `updated_since` watermark, name-based mapping auto-suggest, integration status reporting, audit log calls.

### 4.4 Module UI — `resources/views/pages/dashboard/energy_efficiency.php`

- **Sidebar:** Operations group entry "Energy Efficiency", gated `frs_can_read($role, 'energy')`, route `/dashboard/energy-efficiency`, lightbulb icon (`bolt` is taken by UMAN). Route added to `index.php` dashboard route map.
- **Layout:** standard `dashboard_layout.php` page, AJAX-nav compatible, with a connection status card on top (reachable? token configured? last successful sync?) and three tabs:

1. **Meter Readings** — facilities table: latest reading, monthly consumption, sync badge (`pending`/`synced`/`failed` + error tooltip). "Add Reading" modal: auto-filled previous reading (editable only for first entry), current reading, reading date, optional notes, live consumption preview. Validation: current ≥ previous; one reading per facility per month; warn (not block) when facility is unmapped — the reading stays local/`pending` until mapped.
2. **Recommendations** — cached approved recommendations grouped by facility: period, generated message, engineer recommendation, expected savings kWh, target date, status badge; filter by facility/month; "last synced" timestamp; "Sync Now" button (requires `update` permission). Empty state explains data originates from the LGU Energy system.
3. **Facility Mapping** (requires `update` permission) — each CPRF facility beside a dropdown of energy-system facilities fetched live via the API, auto-suggested by case-insensitive name similarity, confirm/override per row.

### 4.5 Sync flows

- **Push (on save):** reading is stored locally first (`pending`) — a local save never fails because their API is down — then pushed immediately; success → `synced` + `external_record_id`; failure → `failed` + captured error.
- **Pull (recommendations):** `GET .../recommendations?status=approved&updated_since=<watermark>`; upsert cache rows by `energy_recommendation_id`; resolve CPRF facility via map.
- **Cron:** `scripts/sync_energy_integration.php` (mirrors `sync_ipms_projects.php`) — retries `pending`/`failed` pushes, pulls recommendations, updates integration status; flags `--dry-run`, `--verbose`.
- **Manual:** `public/api/sync-energy.php` — Admin/Staff session check + CSRF + rate limit, triggers the same sync routine (IPMS pattern).
- **Audit:** entries via `config/audit.php` for reading create/edit, mapping change, and sync runs.

## 5. Security

- CSRF tokens on every form/POST; PDO prepared statements only; `htmlspecialchars()` on all output — recommendation text is externally sourced data and must always be escaped.
- API token lives only in `.env` (both repos); never committed.
- Manual sync endpoint rate-limited and permission-checked.

## 6. Testing

- **CPRF (PHPUnit, `tests/Unit/`):** consumption computation and validation edge cases (first reading, equal readings, month uniqueness), push payload building, recommendation response parsing/upsert logic, mapping auto-suggest.
- **Energy side (Laravel Feature tests):** auth 401/503, validation errors, idempotent upsert, filters/pagination on recommendations.
- **Manual UAT:** enter a reading in CPRF → appears in energy monitoring UI with `input_source=cprf` → approve a recommendation there → Sync Now in CPRF → recommendation visible under the correct facility.

## 7. Out of scope

- Real-time delivery (webhooks/MQTT), submeter-level readings, sending CPRF booking/occupancy data to the energy system, energy-side UI changes, resident-facing energy views, i18n.
