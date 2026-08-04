# CPRF Integration Map

What each CPRF page connects to externally, what it sends, what it receives, and whether the wire is one-way or two-way. Verified against `services/*_api.php` and `public/api/*` — 2026-08-04.

Format: `CPRF page/module → connection → external module/page`

---

## Facility Management → UMAN Facility Equipment / Asset Requests

`resources/views/pages/dashboard/facility_management.php` ↔ UMAN's **Facility Equipment** module (`api/facility-equipment.php`) and **Asset Requests** module (`api/asset-requests.php`, staff-facing `external_asset_requests.php`, and the `cprf_facility_assignments` page)

- **Direction:** Two-way
- **CPRF sends:** which UMAN assets are checked into a facility's equipment locker (`facility_id`, `uman_asset_id`); return requests (`RETURN_ONLY` / `RETURN_AND_REPLACE` / `RETURN_DECOMMISSION`, condition, reason) when staff sends equipment back
- **CPRF receives:** real-time custody webhooks the instant UMAN staff act — asset assigned, unassigned/recalled, return accepted or decommissioned, replacement shipped
- **Effect in CPRF:** every event writes to `facility_equipment` (current state) and `facility_equipment_events` (permanent chain-of-custody log, COA-compliant)
- **Trigger:** webhooks fire live from UMAN; CPRF pushes the moment a Facility Management save or return action happens
- **Auth:** shared API key both directions (`X-API-Key`)

## Facility Management → CIMM (facility directory only)

`facilities` table (edited from Facility Management) → CIMM's facility-matching logic

- **Direction:** One-way (CPRF → CIMM)
- **CPRF sends:** its full facility directory — id, name, location, capacity, amenities, coordinates, current status, matching keywords/aliases
- **CPRF receives:** nothing on this specific feed
- **Effect:** lets CIMM resolve "which CPRF facility is this schedule for" by `facility_id` first, name/location matching as fallback
- **Trigger:** CIMM pulls on its own schedule
- **Auth:** static API key (`?key=`), served from `public/api/facilities-share.php`

## Utilities Integration → UMAN Asset Requests

`resources/views/pages/dashboard/utilities_integration.php` ↔ UMAN's **Asset Requests** module (`api/asset-requests.php`)

- **Direction:** Two-way
- **CPRF sends:** new asset requests — asset type, quantity, urgency, date needed, booking reference, event purpose, exact asset code if one is wanted
- **CPRF receives:** the live asset catalog, asset types, and current status of every request CPRF has filed (pending / approved / fulfilled / rejected)
- **Effect in CPRF:** approved + fulfilled requests with a linked asset auto-assign into `facility_equipment` on next sync
- **Trigger:** pulled on page load / on demand; request sent the moment staff submits the form
- **Auth:** shared API key (`X-API-Key`)

## Maintenance Integration → CIMM Maintenance Schedules

`resources/views/pages/dashboard/maintenance_integration.php` ↔ CIMM's **Maintenance Schedules & Infrastructure Reports** module (`lgu-portal/public/api/maintenance-schedules.php`, `maintenance-request.php`)

- **Direction:** Two-way
- **CPRF sends:** new maintenance requests raised from the Maintenance Insights panel — `facility_id`, task, category, priority, target dates, risk score/band, notes
- **CPRF receives:** every schedule and infrastructure report CIMM has on file — status (`Scheduled` / `In Progress` / `Delayed` / `Completed`), start/end dates, priority, assigned team, engineer, budget
- **Effect in CPRF:** matched facilities flip to `maintenance` status while the window is active, blackout dates are written with a `CIMM Sync:` reason, and a public announcement can auto-publish via Gemini
- **Trigger:** pulled on every page load + cron (`scripts/sync_cimm_maintenance.php`); request sent the moment staff submits one
- **Auth:** shared API key (`?key=`)

## Energy Efficiency → LGU Energy Recommendations

`resources/views/pages/dashboard/energy_efficiency.php` ↔ LGU Energy's **Facility Profiles & Recommendations** module (`/api/v1/cprf/*`)

- **Direction:** Two-way
- **CPRF sends:** manual facility meter readings; implementation-progress updates against a given recommendation
- **CPRF receives:** the Energy system's own facility list, facility energy profiles, and engineer-approved energy-saving recommendations
- **Effect in CPRF:** recommendations surface on this dashboard for staff to action; nothing here touches a facility's booking status
- **Trigger:** scheduled sync (`scripts/sync_energy_integration.php`) + dashboard load; a reading pushes the moment staff logs one
- **Auth:** shared bearer token

## Infrastructure Projects → IPMS Facility Status Feed

`resources/views/pages/dashboard/infrastructure_projects_integration.php` ← IPMS's **Facility Status Feed** module (`integrations/facilities-reservation/facility-status-feed.php`)

- **Direction:** One-way (CPRF pulls only — nothing is ever written back to IPMS)
- **CPRF receives:** active projects (status `active` / `delayed` / `on_hold` / `completion_inspection`, progress %, location, start/expected-completion dates) that block booking, plus upcoming projects (`approved` / `bidding` / `awarded`) that are heads-up only
- **Effect in CPRF:** high-confidence text matches (name/location similarity ≥ threshold) flip a facility to `maintenance` and add `IPMS Sync:` blackout dates; low-confidence matches are surfaced as "needs manual review," never guessed
- **Trigger:** cron (`scripts/sync_ipms_projects.php`) + dashboard load
- **Auth:** static API key (`X-API-Key`)

## Book Facility → (downstream consumer, no live external call)

`resources/views/pages/dashboard/book_facility.php` reads `facility_blackout_dates` and `facilities.status`

- **Direction:** N/A — internal only
- **What it consumes:** the combined result of all four syncs above. A date blocked by CIMM, IPMS, or a UMAN-triggered outage all show up here as the same blackout row, tagged by its `... Sync:` reason prefix so the source is still traceable
- **Why it matters:** this is the page residents actually feel the integrations through — a facility they can't book on a given day is very likely because one of the four systems above said so

## System Settings → status dashboard only

`resources/views/pages/dashboard/system_settings.php` reads `config/integration_status.php`

- **Direction:** N/A — read-only summary
- **What it shows:** connected/offline state, last sync time, and per-integration metrics (matched/unmatched counts, blackouts added, pending requests) for all four integrations above, pulled from each one's saved sync-state file — not a live call on page load

---

## Shared foundations

| Foundation | Role |
|---|---|
| **Facilities Directory Feed** (`public/api/facilities-share.php`) | CPRF's outbound facility catalog. Confirmed consumed by CIMM to resolve facility matches. |
| **Generic Inbound Hub** (`/api/integrations/*`) | One endpoint family receiving every inbound push — all UMAN custody webhooks, plus generic maintenance/infrastructure/utility blackout writes any authorized LGU system can call. |

## Legend

- **Two-way** — CPRF and the external system both send and receive data
- **Pull-only** — CPRF reads; nothing is written back
- Direction reflects what the code actually calls, not the intended design
