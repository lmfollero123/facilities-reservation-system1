# IPMS New-Facility Candidates — Design Spec

**Date:** 2026-08-04
**App:** facilities-reservation-system1 (CPRF), Infrastructure Projects (IPMS) integration

## Motivation

The owner assumed that when IPMS finishes building a new public facility in Barangay Culiat, it would automatically show up as a facility on this system, editable afterward. It doesn't — verified during this session that the IPMS sync only ever matches *existing* facilities (by fuzzy name/location, now pinned per the earlier bug fix) to flip their status/blackout dates. Unmatched projects sit in a read-only "needs review" list; nothing ever creates a facility row from them.

This spec adds that missing link, without going fully automatic — IPMS never supplies enough data (capacity, amenities, rate, image, description, operating hours) to responsibly auto-create a real bookable facility.

## Current state (baseline)

- `services/ipms_api.php`: `syncFacilitiesFromIPMS()` builds `$summary['needs_review']` from every active-bucket project that fails `ipmsMatchFacilityId()`'s confidence threshold (`IPMS_MATCH_THRESHOLD = 70`). This array is ephemeral per sync but persisted via `frs_ipms_save_sync_state()` into `storage/ipms_sync_state.json`, so it does survive across page loads.
- `resources/views/pages/dashboard/infrastructure_projects_integration.php`: reads `$summary['needs_review']` and renders it as a plain list (line ~153-174), no actions.
- `resources/views/pages/dashboard/facility_management.php`: has an Add Facility modal (`openFacilityModal(true)`) with fields for name, location, latitude, longitude, capacity, amenities, rules, rate, image, operating hours, status. No query-string prefill support today.
- Pin infrastructure already exists from the earlier CIMM/IPMS rename-drift fix: `frs_ipms_load_schedule_pins()` / `frs_ipms_save_schedule_pins()` in `services/ipms_api.php`, keyed by `ipmsProjectStableKey($project)` (project_code, falling back to `IPMS-{project_id}`).

## Trigger condition

IPMS's status vocabulary (`active`, `delayed`, `on_hold`, `completion_inspection` for the blocking bucket; `approved`, `bidding`, `awarded`, `assigned` for upcoming) has no explicit "done"/"completed" value we've observed. `completion_inspection` — the last stage before a project presumably exits the feed — is used as the trigger signal instead of waiting for a status that may never come.

**A project is a "new facility candidate" when:**
1. Its normalized status is `completion_inspection`, AND
2. It fails `ipmsMatchFacilityId()`'s threshold (i.e. it's currently in `needs_review`), AND
3. It has not been dismissed (see below).

## Flow

1. **Surfacing** — Infrastructure Projects page gets a new "🏗️ New Facility Candidates" section, separate from the existing generic needs-review list, showing only projects meeting the trigger condition above. Each candidate shows project name, location, and two actions: **Add as Facility** and **Not a facility**.
2. **Add as Facility** — links to `facility_management.php` with query params carrying the project's `name`, `location`, `latitude`, `longitude`, and its stable project key. On load, if those params are present, the page auto-opens the Add Facility modal and pre-fills name/location/latitude/longitude. Capacity, amenities, rules, rate, image, description, and operating hours are left blank — staff fills those in exactly like creating any other facility.
3. **Save (insert)** — on successful facility creation, if the request carried an IPMS project key (a hidden field carrying it through the form), the backend pins that project key to the new facility's ID via `frs_ipms_save_schedule_pins()` — merging into the existing pin store, not overwriting it. This guarantees the link survives regardless of whether the entered facility name/location still fuzzy-matches the project text.
4. **Not a facility (dismiss)** — writes the project's stable key into a new `storage/ipms_dismissed_projects.json` array. Dismissed projects are filtered out of the candidates section on every future sync (but still appear in the pre-existing generic needs-review list, since that's a different, broader-purpose list unrelated to facility creation).

## File map

| File | Change |
|---|---|
| `services/ipms_api.php` | Add `frs_ipms_dismissed_projects_path()`, `frs_ipms_load_dismissed_projects()`, `frs_ipms_save_dismissed_projects()` (array<string> of project keys). Add `frs_ipms_new_facility_candidates(array $needsReview): array` — filters `needs_review` entries down to `completion_inspection` status + not dismissed, returns `[project_key, name, location, latitude, longitude]` per candidate (latitude/longitude come from the original project data, not currently included in `needs_review`'s stored shape — needs adding there too). |
| `resources/views/pages/dashboard/infrastructure_projects_integration.php` | New "New Facility Candidates" section rendering `frs_ipms_new_facility_candidates()`. Add-as-Facility link builds the query string to `facility_management.php`. Dismiss action (POST) calls `frs_ipms_save_dismissed_projects()`. |
| `resources/views/pages/dashboard/facility_management.php` | On page load, read `$_GET['prefill_name']`, `$_GET['prefill_location']`, `$_GET['prefill_lat']`, `$_GET['prefill_lng']`, `$_GET['prefill_ipms_key']`. If present, emit a small inline script that calls `openFacilityModal(true)` then sets the corresponding form fields, plus a hidden `<input type="hidden" name="ipms_project_key">` carrying the key through submission. In the existing INSERT branch (~line 491), after a successful insert, if `$_POST['ipms_project_key']` is non-empty, load IPMS pins, set `pins[$key] = $newFacilityId`, save. |
| `storage/ipms_dismissed_projects.json` | New — simple JSON array of dismissed project stable keys. |

## needs_review shape change

`$summary['needs_review']` entries currently carry `project_id`, `project_code`, `name`, `location`, `status`, `best_score` (see `syncFacilitiesFromIPMS()`). Latitude/longitude must be added to this array (already present on the normalized `$project` — `ipmsNormalizeProject()` includes `latitude`/`longitude`) so the candidates feature can prefill coordinates without a second IPMS fetch.

## Edge cases

- **Project later gets un-dismissed status change** (e.g. someone dismissed it, then IPMS reactivates the same project under a different code): stable key changes if `project_code` changes, so it would reappear as a fresh candidate. Acceptable — no way to detect this without a persistent IPMS-side identity guarantee we don't have.
- **Staff creates the facility with a name/location IPMS wouldn't recognize at all**: irrelevant, since the pin is written explicitly at creation time using the project key carried through the form, not re-derived by fuzzy matching.
- **Same project clicked "Add as Facility" twice** (two facilities created from one project): out of scope for this spec — no de-duplication beyond the dismiss list; staff discipline required, same as any manual facility creation today.

## Out of scope

- Auto-inserting a facility with placeholder values — rejected during brainstorming (risk of an incomplete/wrong facility going live unreviewed).
- Detecting a genuine IPMS "completed" status — no such value has been observed in the feed; `completion_inspection` is the practical proxy.
- Any change to the CIMM side — this spec is IPMS-only, since CIMM's maintenance-request flow is a different, already-complete workflow (CPRF-initiated requests, not IPMS-initiated new-construction).
