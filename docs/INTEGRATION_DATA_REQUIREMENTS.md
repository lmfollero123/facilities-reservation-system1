# Integration Data Requirements

**Project:** Barangay Culiat Public Facilities Reservation System (CPRF)  
**Document purpose:** Reference for communicating with partner system developers — exactly what data CPRF needs from each external system, what CPRF will send back, and what triggers actions on our side.  
**Last updated:** May 2026  
**Status:** Active — share this with each system's developer team

---

## Contents

1. [How Integration Works](#1-how-integration-works)
2. [System 1 — CIMM (Maintenance Management)](#2-system-1--cimm-community-infrastructure-maintenance-management)
3. [System 2 — Infrastructure Management](#3-system-2--infrastructure-management)
4. [System 3 — Utilities Billing & Management](#4-system-3--utilities-billing--management)
5. [What CPRF Provides to All Systems](#5-what-cprf-provides-to-all-systems)
6. [Security & Authentication](#6-security--authentication)
7. [Communication Checklist](#7-communication-checklist)

---

## 1. How Integration Works

CPRF (the reservation system) acts as the **consumer** of data from the three partner systems. The partner systems expose simple **GET API endpoints**; CPRF polls them on a schedule or on page load.

For events that must be pushed immediately (e.g. emergency outages), the partner system can call a **POST webhook** on CPRF.

```
Partner System  ──GET response──►  CPRF pulls data
CPRF            ◄──POST webhook──  Partner pushes urgent alerts
```

All communication must be over **HTTPS**. A shared API key in the request header or query string is used for authentication.

---

## 2. System 1 — CIMM (Community Infrastructure Maintenance Management)

**Domain:** `cimm.infragovservices.com`  
**Integration status:** API structure agreed; endpoint `maintenance-schedules.php` defined (see `docs/CIMM_API_INTEGRATION.md`)  
**Priority:** HIGH — active booking blocking depends on this

### 2.1 What CPRF needs from CIMM

#### A. Maintenance Schedules (required)

> Used to block facility bookings during maintenance and notify residents with existing reservations.

| Field | Type | Required | Description | Example |
|-------|------|----------|-------------|---------|
| `sched_id` | string / int | ✅ | Unique ID of the maintenance record | `"42"` |
| `task` | string | ✅ | Short name / title of the task | `"HVAC Inspection"` |
| `location` | string | ✅ | Name of the facility — must match CPRF facility names | `"Community Convention Hall"` |
| `category` | string | ✅ | Type of work (e.g. Electrical, Plumbing, HVAC, Civil) | `"Power & Electrical"` |
| `priority` | string | ✅ | `Low` / `Medium` / `High` / `Critical` | `"High"` |
| `status` | string | ✅ | Current state of the schedule | `"Scheduled"` |
| `assigned_team` | string | ⬜ optional | Team or personnel responsible | `"HVAC Team"` |
| `starting_date` | datetime | ✅ | Start of maintenance window `YYYY-MM-DD HH:MM:SS` | `"2026-06-10 08:00:00"` |
| `estimated_completion_date` | datetime | ✅ | Expected end of maintenance window | `"2026-06-10 17:00:00"` |
| `remarks` / `notes` | string | ⬜ optional | Free-text details for staff | `"Requires power cut from 8–10 AM"` |
| `created_at` | datetime | ✅ | When the record was created | `"2026-06-01 09:00:00"` |
| `updated_at` | datetime | ⬜ optional | Last modification — helps CPRF detect changes | `"2026-06-05 14:30:00"` |

**Valid `status` values CPRF recognises:**
- `Scheduled`
- `In Progress`
- `Completed`
- `Delayed`
- `Cancelled`

#### B. Facility Name Mapping (recommended)

CPRF matches `location` to its own facility list by exact name. If CIMM uses different names (e.g. `"Covered Court"` vs `"Barangay Covered Court"`), please provide a mapping table or use a shared `facility_id`.

> **Ask CIMM developer:** Can `facility_id` (CPRF's integer facility ID) be added to the schedule response so matching is exact?

Ideal addition:

| Field | Type | Description |
|-------|------|-------------|
| `cprf_facility_id` | int | CPRF's `facilities.id` — removes name-matching ambiguity |

#### C. What CPRF does with this data

| CPRF Action | Trigger |
|-------------|---------|
| Block bookings on affected dates | `status = Scheduled / In Progress` + date overlap |
| Set facility status to `maintenance` | Active maintenance window starts |
| Restore facility status to `available` | `status = Completed` or window has passed |
| Send in-app + email notification to users | Any approved/pending reservation on the same date |
| Add blackout dates to calendar | Automatically on sync |

#### D. CIMM endpoint CPRF calls

```
GET https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php
    ?key=<API_KEY>
    &from=YYYY-MM-DD       ← optional date filter (upcoming only)
    &status=Scheduled      ← optional status filter
```

Response format:
```json
{
  "success": true,
  "count": 3,
  "data": [ { ...fields above... } ]
}
```

#### E. Optional — emergency push webhook (CPRF receives)

For critical/emergency maintenance CIMM can call:

```
POST https://cprf.infragovservices.com/api/integrations/maintenance/alert
Content-Type: application/json
Authorization: Bearer <SHARED_TOKEN>

{
  "sched_id": "99",
  "task": "Burst pipe emergency",
  "location": "Community Convention Hall",
  "cprf_facility_id": 1,
  "priority": "Critical",
  "starting_date": "2026-06-08 06:00:00",
  "estimated_completion_date": "2026-06-08 18:00:00"
}
```

---

## 3. System 2 — Infrastructure Management

**Domain / system:** LGU Infrastructure Projects Management  
**Integration status:** CPRF page exists (`/dashboard/infrastructure-projects`); currently using mock data  
**Priority:** MEDIUM — needed when construction/renovation will close or change a facility

### 3.1 What CPRF needs from Infrastructure Management

#### A. Active Projects (required)

> Used to block reservations during construction/renovation and update facility capacity after completion.

| Field | Type | Required | Description | Example |
|-------|------|----------|-------------|---------|
| `project_id` | string | ✅ | Unique project identifier | `"PROJ-2026-004"` |
| `project_name` | string | ✅ | Full name of the project | `"Sports Complex Roof Repair"` |
| `project_type` | string | ✅ | `New Construction` / `Renovation` / `Expansion` / `Repair` / `Demolition` | `"Renovation"` |
| `facility_name` | string | ✅ | Name of affected facility (must match CPRF) | `"Municipal Sports Complex"` |
| `cprf_facility_id` | int | ⬜ recommended | CPRF facility ID to avoid name mismatch | `2` |
| `start_date` | date | ✅ | Project start `YYYY-MM-DD` | `"2026-07-01"` |
| `end_date` | date | ✅ | Projected completion `YYYY-MM-DD` | `"2026-09-30"` |
| `actual_end_date` | date | ⬜ optional | Actual completion date once done | `"2026-10-05"` |
| `status` | string | ✅ | Current project status | `"in_progress"` |
| `phase` | string | ⬜ optional | Current phase name | `"Construction"` |
| `progress_pct` | int (0–100) | ⬜ optional | Completion percentage | `45` |
| `description` | string | ⬜ optional | Brief description for staff | `"Roof replacement + bleacher repair"` |
| `affects_bookings` | boolean | ✅ | Whether bookings should be blocked during this project | `true` |
| `capacity_change` | string | ⬜ optional | Change to facility capacity once complete (e.g. `+200`, `-50`, `New Facility`) | `"+200"` |
| `budget` | decimal | ⬜ optional | Approved budget in PHP | `5000000.00` |
| `created_at` | datetime | ✅ | When the project record was created | `"2026-05-10 08:00:00"` |
| `updated_at` | datetime | ⬜ optional | Last update timestamp | `"2026-06-01 14:00:00"` |

**Valid `status` values:**
- `planned`
- `in_progress`
- `completed`
- `on_hold`
- `cancelled`

#### B. New / Modified Facility Data (on project completion)

When a **New Construction** or **Expansion** project completes, CPRF needs to create or update a facility record.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `facility_name` | string | ✅ | Name of the new/expanded facility |
| `location` | string | ✅ | Physical address / location description |
| `capacity` | string | ✅ | New total capacity (e.g. `"500 persons"`) |
| `description` | string | ⬜ optional | Facility description |
| `amenities` | string | ⬜ optional | List of amenities |
| `project_id` | string | ✅ | Reference to the project that created it |
| `completion_date` | date | ✅ | Official handover date |

#### C. What CPRF does with this data

| CPRF Action | Trigger |
|-------------|---------|
| Block new bookings on facility | `affects_bookings = true` + status `planned` or `in_progress` + date range |
| Notify users with existing bookings | Any reservation overlapping the project dates |
| Add project dates as blackout dates | Automatically on sync |
| Update facility capacity | `capacity_change` received + status `completed` |
| Create new facility entry | `project_type = New Construction` + status `completed` |

#### D. Infrastructure endpoint CPRF calls

```
GET https://<infra-system-domain>/api/projects
    ?key=<API_KEY>
    &status=in_progress,planned     ← active + upcoming only
    &from=YYYY-MM-DD

GET https://<infra-system-domain>/api/projects/<project_id>
    ?key=<API_KEY>
```

Response:
```json
{
  "success": true,
  "count": 2,
  "data": [ { ...fields above... } ]
}
```

---

## 4. System 3 — Utilities Billing & Management

**Domain / system:** LGU Utilities Billing System  
**Integration status:** CPRF page exists (`/dashboard/utilities-integration`); currently using mock data  
**Priority:** MEDIUM — needed for outage-based booking blocks and cost tracking reports

### 4.1 What CPRF needs from Utilities

#### A. Utility Outages / Service Interruptions (required)

> Used to block bookings when water, electricity, or internet will be unavailable at a facility.

| Field | Type | Required | Description | Example |
|-------|------|----------|-------------|---------|
| `outage_id` | string | ✅ | Unique outage identifier | `"OUTAGE-2026-015"` |
| `utility_type` | string | ✅ | `Electricity` / `Water` / `Internet` / `Gas` | `"Water"` |
| `facility_name` | string | ✅ | Affected facility (must match CPRF) | `"Community Convention Hall"` |
| `cprf_facility_id` | int | ⬜ recommended | CPRF facility ID for exact matching | `1` |
| `scheduled_start` | datetime | ✅ | Outage start `YYYY-MM-DD HH:MM:SS` | `"2026-06-15 08:00:00"` |
| `scheduled_end` | datetime | ✅ | Outage end (estimated) `YYYY-MM-DD HH:MM:SS` | `"2026-06-15 14:00:00"` |
| `actual_end` | datetime | ⬜ optional | Real end time once restored | `"2026-06-15 13:20:00"` |
| `status` | string | ✅ | Current outage status | `"scheduled"` |
| `reason` | string | ✅ | Plain-language description of why | `"Water main repair on Zone 1"` |
| `severity` | string | ⬜ optional | `minor` / `moderate` / `major` / `emergency` | `"moderate"` |
| `affects_bookings` | boolean | ✅ | Whether this blocks facility use | `true` |
| `created_at` | datetime | ✅ | When the outage was logged | `"2026-06-10 09:00:00"` |

**Valid `status` values:**
- `scheduled`
- `active`
- `restored`
- `cancelled`

#### B. Utility Consumption Data (optional but useful for reports)

> Used in Reports & Analytics to show cost per reservation and energy efficiency trends.

| Field | Type | Required | Description | Example |
|-------|------|----------|-------------|---------|
| `facility_name` | string | ✅ | Facility name | `"Municipal Sports Complex"` |
| `cprf_facility_id` | int | ⬜ recommended | CPRF facility ID | `2` |
| `period_month` | string | ✅ | Month of reading `YYYY-MM` | `"2026-05"` |
| `electricity_kwh` | decimal | ⬜ optional | Electricity consumed in kWh | `1250.50` |
| `electricity_cost_php` | decimal | ⬜ optional | Electricity cost in PHP | `12500.00` |
| `water_cubic_m` | decimal | ⬜ optional | Water consumed in m³ | `32.0` |
| `water_cost_php` | decimal | ⬜ optional | Water cost in PHP | `3200.00` |
| `total_cost_php` | decimal | ✅ | Total utility cost for the period | `15700.00` |
| `reading_date` | date | ⬜ optional | When meter was read | `"2026-05-31"` |

#### C. What CPRF does with this data

| CPRF Action | Trigger |
|-------------|---------|
| Block new bookings on facility | `affects_bookings = true` + status `scheduled` or `active` + date overlap |
| Display outage badge on calendar | Outage falls within displayed date range |
| Notify users with reservations | Any reservation on the same date/time |
| Restore facility availability | Status changes to `restored` or `cancelled` |
| Show cost-per-reservation in reports | Monthly consumption data received |

#### D. Utilities endpoint CPRF calls

```
GET https://<utilities-system-domain>/api/outages
    ?key=<API_KEY>
    &status=scheduled,active
    &from=YYYY-MM-DD

GET https://<utilities-system-domain>/api/consumption
    ?key=<API_KEY>
    &month=YYYY-MM           ← monthly cost data
    &facility_id=<CPRF_ID>   ← optional, filter by facility
```

#### E. Optional — push webhook for emergency outages

```
POST https://cprf.infragovservices.com/api/integrations/utilities/alert
Authorization: Bearer <SHARED_TOKEN>
Content-Type: application/json

{
  "outage_id": "OUTAGE-2026-099",
  "utility_type": "Electricity",
  "facility_name": "Community Convention Hall",
  "cprf_facility_id": 1,
  "scheduled_start": "2026-06-08 07:00:00",
  "scheduled_end": "2026-06-08 16:00:00",
  "status": "active",
  "reason": "Transformer failure on Feeder 3",
  "severity": "major",
  "affects_bookings": true
}
```

---

## 5. What CPRF Provides to All Systems

Partner systems may also need data from CPRF. Here is what we can expose.

### Facility List

```
GET https://cprf.infragovservices.com/api/facilities
    ?key=<API_KEY>
```

Returns all facilities with their CPRF IDs — use this to set up `cprf_facility_id` mapping.

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Community Convention Hall",
      "status": "available",
      "capacity": "500",
      "location": "Brgy. Culiat Hall Compound"
    }
  ]
}
```

### Reservation Load (for Maintenance Scheduling)

```
GET https://cprf.infragovservices.com/api/facility-usage
    ?key=<API_KEY>
    &facility_id=1
    &from=YYYY-MM-DD
    &to=YYYY-MM-DD
```

Returns count of approved bookings per date — useful for CIMM to pick low-impact maintenance windows.

```json
{
  "success": true,
  "facility_id": 1,
  "data": [
    { "date": "2026-06-10", "bookings": 3 },
    { "date": "2026-06-11", "bookings": 0 }
  ]
}
```

---

## 6. Security & Authentication

All integration endpoints must use:

| Requirement | Details |
|-------------|---------|
| Transport | HTTPS only — no plain HTTP |
| Auth method | API key via `Authorization: Bearer <token>` header (preferred) OR `?key=<token>` query param |
| CORS | Each system should only allow the other's domain |
| Key rotation | Keys should be rotated every 6 months or immediately on suspected compromise |
| Rate limiting | Max 60 requests/minute per API key |
| Error format | All errors return `{ "success": false, "error": "reason" }` with appropriate HTTP status code |

**CPRF environment variables (set in `.env`):**

```env
CIMM_API_KEY=<key provided by CIMM>
CIMM_API_URL=https://cimm.infragovservices.com/...

INFRA_API_KEY=<key provided by Infra Management>
INFRA_API_URL=https://<infra-domain>/api/...

UTILITIES_API_KEY=<key provided by Utilities>
UTILITIES_API_URL=https://<utilities-domain>/api/...

# Key CPRF gives to partner systems for reverse calls
CPRF_WEBHOOK_TOKEN=<generate strong random string>
```

---

## 7. Communication Checklist

Use this as a checklist when talking to each system's developer.

### For CIMM Developer

- [ ] Confirm `maintenance_schedule` table column names match the fields in Section 2.1
- [ ] Add `updated_at` column if not already present (helps CPRF detect changes)
- [ ] Add `cprf_facility_id` optional field to the API response
- [ ] Agree on API key value and rotate from `CIMM_SECURE_KEY_2025` to a new key
- [ ] Confirm the endpoint URL is live: `https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php`
- [ ] Test with: `curl "https://cimm.infragovservices.com/.../maintenance-schedules.php?key=<KEY>"`

### For Infrastructure Management Developer

- [ ] Share the project data fields listed in Section 3.1
- [ ] Confirm what the base URL for their API will be
- [ ] Agree on `cprf_facility_id` mapping — CPRF will provide the facility list endpoint (Section 5)
- [ ] Clarify: does Infrastructure Management also handle facility closures separately from CIMM?
- [ ] Confirm how new facility handover data will be sent (push or pull)

### For Utilities Developer

- [ ] Share the outage data fields in Section 4.1A
- [ ] Share the consumption data fields in Section 4.1B (confirm if they track per-facility)
- [ ] Confirm API base URL
- [ ] Agree on `cprf_facility_id` mapping
- [ ] Confirm whether emergency outages will be pushed (webhook) or only pulled

### All systems

- [ ] Exchange API keys securely (not via chat/email in plain text)
- [ ] Set CORS to allow only `https://cprf.infragovservices.com`
- [ ] Agree on a test date for end-to-end integration testing
- [ ] Confirm contact person for each system (name + email)

---

## Appendix — Facility Name Reference

Share this with all three teams so `facility_name` fields in their systems match exactly:

| CPRF `facilities.id` | CPRF Facility Name | Notes |
|---------------------|-------------------|-------|
| *(run `SELECT id, name FROM facilities ORDER BY id` on live DB to fill this table)* | | |

> **Tip:** Rather than matching by name, ask each system to use `cprf_facility_id` (our integer ID). CPRF exposes a facilities list at `/api/facilities` so they can look up the IDs once and store them.

---

*Prepared by CPRF development team — May 2026*  
*For questions: share this document with each system's lead developer and use the checklist in Section 7 as your agenda.*
