# UMAN Utility Readings — Design Spec

**Date:** 2026-08-05
**App:** facilities-reservation-system1 (CPRF), UMAN Utilities integration

## Motivation

CPRF currently submits monthly electricity meter readings directly to the LGU Energy system (Energy Efficiency page → `energy_meter_readings` table → `pushEnergyFacilityReading()`). The owner wants to change this relationship: CPRF submits **both electricity and water** readings to **UMAN** instead, UMAN monitors them for high consumption, and UMAN is the one that forwards data on to Energy. Energy's existing recommendation pull (Energy → CPRF, already working, unrelated to this change) stays as-is.

**This replaces, not adds to, the direct CPRF → Energy reading push.** One submission flow, moved from the Energy Efficiency page to the UMAN Integration page.

## Current state (baseline)

- `config/energy_helper.php` (~1050 lines) owns the whole electricity-reading lifecycle: `frs_energy_save_reading()` (insert, with chronological-order + duplicate-period guards), `frs_energy_update_reading()` (edit latest-only), `frs_energy_delete_reading()`, `frs_energy_push_reading()` (looks up an Energy-side facility mapping via `energy_facility_map`, builds a payload via `frs_energy_build_reading_payload()`, calls `pushEnergyFacilityReading()` from `services/energy_api.php`, tracks `sync_status`/`sync_error`/`external_record_id`).
- `energy_meter_readings` table: `facility_id, year, month, reading_date, previous_reading_kwh, current_reading_kwh, consumption_kwh, rate_per_kwh (default 12.00), notes, recorded_by, sync_status, synced_at, sync_error, external_record_id`. One row per facility per month (`UNIQUE KEY (facility_id, year, month)`).
- `resources/views/pages/dashboard/energy_efficiency.php`: 4 tabs — **Meter Readings** (add/edit/delete form + history table, ~250 lines of POST handling + ~250 lines of form/table HTML), Recommendations, Facility Mapping, Profiles. This spec only touches the Meter Readings tab.
- UMAN's own facility identity is simpler than Energy's — CPRF and UMAN already share a stable `facility_id` directly (see `services/uman_api.php`, `facilities-share.php`). **No facility-mapping table is needed for UMAN**, unlike the Energy path.
- Current Philippine utility rates (verified via web search, 2026-08-05):
  - **Electricity (Meralco)**: ₱14.8261/kWh residential all-in rate, July 2026 (latest published; Meralco adjusts monthly, so this is a starting default, not a fixed tariff).
  - **Water (Manila Water East Zone**, which serves Quezon City including Barangay Culiat**)**: ≈₱68.02/m³ unsewered, Q2 2026 tier (also a default, not a full tiered calculator — mirrors how `rate_per_kwh` already works today: a sane default the recorder can override).

## Flow

1. Staff opens the **UMAN Integration page**, goes to a new "Utility Readings" section.
2. Picks a facility, reading month, previous/current electric reading (kWh) and previous/current water reading (m³). Consumption for both is computed the same way the electric-only flow does today (current − previous, chronologically continuous from the facility's last reading). Rate fields default to the current Meralco/Manila Water figures above, editable.
3. On save: one row is written locally (extended `energy_meter_readings` table — kept as-is, not renamed, to avoid unnecessary migration churn beyond adding columns) with both electric and water data, `sync_status = 'pending'`.
4. Immediately after saving, CPRF pushes the reading to **UMAN** (new `submitUMANUtilityReading()` client + `frs_uman_push_utility_reading()` wrapper) instead of Energy. No facility-mapping lookup needed — the facility_id already means the same thing to both systems.
5. UMAN's own systems decide what (if anything) to forward to Energy and when — entirely UMAN's responsibility, out of scope for CPRF's code.
6. The old "Meter Readings" tab is removed from the Energy Efficiency page; that page keeps only Recommendations/Mapping/Profiles (Recommendations' empty-state copy, which currently says "Submit a monthly meter reading first," is updated to point at the UMAN page instead).

## Data model changes

`energy_meter_readings` gains (migration, following the existing `migration_add_rate_per_kwh_to_energy_meter_readings.sql` idempotent-column pattern):
- `previous_reading_water DECIMAL(14,2) NULL`
- `current_reading_water DECIMAL(14,2) NULL`
- `consumption_water DECIMAL(14,2) NULL`
- `rate_per_water DECIMAL(10,2) NOT NULL DEFAULT 68.02`

All nullable/defaulted so existing electric-only rows remain valid. `rate_per_kwh`'s existing default (12.00) is left alone (changing a column default doesn't retroactively change stored rows anyway) — new submissions will just use the new page's own default of 14.83 passed explicitly from the form, same pattern as today.

## New functions

- `services/uman_api.php`: `submitUMANUtilityReading(array $payload): array` — POSTs to `{UMAN_BASE}/api/utility-readings.php` (new endpoint contract, not yet deployed on UMAN's side — same situation CIMM's schedule endpoints were in before their team built them; errors gracefully with "endpoint not found" via the existing generic `uman_api_post()` helper until UMAN implements it).
- `config/energy_helper.php` (kept here since it already owns all the local reading CRUD/validation — renaming the file is out of scope, see below): extend `frs_energy_save_reading()` / `frs_energy_update_reading()` to accept and validate the four new water fields (same numeric/chronological rules, water consumption must be ≥ 0 same as electric). Add `frs_uman_push_utility_reading(PDO $pdo, int $readingId): array` — a simpler sibling of `frs_energy_push_reading()` that skips the facility-mapping lookup entirely and calls `submitUMANUtilityReading()` directly.

## Naming note (accepted inconsistency)

Functions/table keep their `energy_*` names even though the sync target is now UMAN. Renaming `energy_meter_readings` → something like `utility_meter_readings` and renaming every `frs_energy_*` reading function would touch significantly more surface area (Recommendations/Mapping/Profiles tabs, audit log strings, existing production data) for a purely cosmetic gain. Accepted as tech debt; a future rename is a separate, optional cleanup.

## File map

| File | Change |
|---|---|
| `database/migration_add_water_readings.sql` | New — adds the 4 water columns to `energy_meter_readings`, idempotent (mirrors `migration_add_rate_per_kwh_to_energy_meter_readings.sql`) |
| `config/energy_helper.php` | Extend `frs_energy_save_reading()` / `frs_energy_update_reading()` for water fields; add `frs_uman_push_utility_reading()` |
| `services/uman_api.php` | Add `submitUMANUtilityReading()` |
| `resources/views/pages/dashboard/utilities_integration.php` | New "Utility Readings" section: form (facility, month, electric prev/current/rate, water prev/current/rate, notes) + history table + edit/delete, mirroring the UI patterns already in `energy_efficiency.php`'s Meter Readings tab and the CIMM "recent requests" list style |
| `resources/views/pages/dashboard/energy_efficiency.php` | Remove the Meter Readings tab (POST handling for `add_reading`/`update_reading`/`delete_reading` and its HTML); update Recommendations empty-state copy to point at the UMAN page |

## Edge cases

- **Facility with only electric history, now adding water for the first time**: `previous_reading_water` for that facility's first-ever water entry comes from the form (like the very first electric reading does today); subsequent months auto-carry forward from the last row's `current_reading_water`, same continuity rule as electric.
- **UMAN endpoint not yet deployed**: reading saves locally as `pending` regardless (matches today's behavior when Energy push fails) — never blocks the staff-facing save action.
- **Existing electric-only rows**: `previous_reading_water`/`current_reading_water`/`consumption_water` stay `NULL` for old rows; no backfill attempted.

## Out of scope

- Any UMAN-side implementation (their endpoint, their forwarding-to-Energy logic) — not our codebase.
- A real tiered water/electric bill calculator — both rates are simple editable defaults, same fidelity as today's `rate_per_kwh`.
- Renaming `energy_meter_readings`/`frs_energy_*` for naming purity (see Naming note above).
- Changing anything about the Energy Recommendations/Mapping/Profiles tabs beyond the one empty-state copy edit.
