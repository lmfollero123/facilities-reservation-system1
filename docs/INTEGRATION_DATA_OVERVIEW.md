# Integration Data Overview (Plain Language)

**Project:** Barangay Culiat Public Facilities Reservation System (CPRF)  
**Purpose:** Describe what each partner system shares with CPRF, and what CPRF does with that information — in simple terms for meetings, emails, and capstone documentation.  
**Last updated:** May 2026

---

## How the systems work together

CPRF is the **facility booking system** used by residents and barangay staff. Three other LGU systems hold information that affects whether a facility can be booked:

| Partner system | What they manage |
|----------------|------------------|
| **CIMM** — Community Infrastructure Maintenance Management | Scheduled repairs, inspections, and upkeep |
| **Infrastructure Management** | Construction, renovation, and expansion projects |
| **Utilities Billing & Management** | Water, electricity, and service interruptions |

CPRF does **not** replace these systems. It **reads** their updates so bookings stay accurate and residents are notified when something changes.

```
Partner system records an event  →  CPRF receives the update  →  CPRF updates booking rules + notifies users
```

---

## System 1 — CIMM (Maintenance)

### What CIMM will send

CIMM will send information about **upcoming and ongoing maintenance** for barangay facilities, such as:

- Which facility is affected (e.g. Community Convention Hall)
- What type of work is planned (electrical, HVAC, plumbing, civil works, etc.)
- When maintenance starts and when it is expected to finish
- How urgent it is (low, medium, high, critical)
- Current status (scheduled, in progress, completed, delayed, cancelled)
- Who or which team is assigned (optional)
- Any notes for staff (optional)

This can be sent as a **regular list** CPRF pulls (e.g. daily or when staff open the Maintenance Integration page). For **emergency** work, CIMM may also **push** an alert immediately so CPRF does not wait for the next sync.

### What CPRF will do with it

When CPRF receives maintenance data for a facility:

1. **Block new bookings** for that facility on the affected dates (same as a blackout).
2. **Set the facility to “Maintenance”** while work is scheduled or in progress, so it no longer appears as available for booking.
3. **Return the facility to “Available”** when CIMM marks the work as completed or the maintenance window has ended.
4. **Notify residents and staff** who already have pending or approved reservations on those dates, so they know about the conflict.
5. **Show maintenance on the integration dashboard** so Admin/Staff can review schedules and apply blackouts manually if needed.

**In short:** CIMM tells CPRF *when a facility cannot be used because of maintenance*; CPRF enforces that in the booking calendar and alerts affected users.

### What CPRF can send back to CIMM (optional)

- A list of **all bookable facilities** (names and IDs) so CIMM uses the same facility names.
- **How busy each facility is** on specific dates (number of approved bookings), so maintenance can be planned during quieter periods.

---

## System 2 — Infrastructure Management

### What Infrastructure Management will send

Infrastructure Management will send information about **projects** that affect facilities, such as:

- Project name and type (new construction, renovation, expansion, repair)
- Which existing facility is affected — or if a **new facility** will be created when the project finishes
- Project start date and expected completion date
- Current status (planned, in progress, completed, on hold, cancelled)
- Whether the project should **stop public bookings** during construction
- Optional details: progress percentage, phase (planning, construction, etc.), budget, description
- When complete: any **change in capacity** (e.g. larger hall, more seats) or handover details for a **brand-new facility**

### What CPRF will do with it

When CPRF receives project data:

1. **Block new bookings** for the affected facility from project start until completion, if the project is marked as affecting bookings.
2. **Add blackout dates** on the calendar for the full project period (or the dates Infrastructure provides).
3. **Notify users** with existing reservations that overlap the project timeline.
4. **Update facility capacity** when a project completes and a new capacity is reported (e.g. after expansion).
5. **Create a new facility in CPRF** when a “new construction” project is completed and handover data is provided (name, location, capacity, amenities).

**In short:** Infrastructure Management tells CPRF *when a facility is closed or changed because of a capital project*; CPRF keeps reservations aligned and updates facility records when projects finish.

### What CPRF can send back (optional)

- Facility list and IDs for mapping projects to the correct hall or court.
- Booking load per date so project planners can see high-traffic periods.

---

## System 3 — Utilities Billing & Management

### What Utilities will send

Utilities will send two kinds of information:

#### A. Service outages and interruptions (required for booking)

When water, electricity, or other services will be unavailable at a facility:

- Which facility is affected
- Type of utility (electricity, water, internet, gas, etc.)
- When the outage starts and when service is expected to be restored
- Reason (e.g. water main repair, scheduled power maintenance)
- Status (scheduled, active, restored, cancelled)
- Whether bookings should be blocked during the outage

For **emergency** outages (e.g. sudden power loss), Utilities may **push** an alert immediately.

#### B. Utility usage and cost (optional, for reports)

Monthly or periodic data per facility, such as:

- Electricity and water usage and cost in pesos
- Billing period (month/year)

This helps CPRF show **cost-related reports** and understand facility usage — it does not block bookings by itself unless tied to an outage.

### What CPRF will do with it

When CPRF receives outage data:

1. **Block new bookings** for that facility during the outage window, when Utilities says bookings must be blocked.
2. **Show outage information** on the Utilities Integration dashboard for staff.
3. **Notify residents and staff** with reservations on the same date/time.
4. **Restore normal booking** when Utilities reports service is restored or the outage is cancelled.

When CPRF receives usage/cost data (if provided):

5. **Display trends** in reports (e.g. utility cost per facility per month) for Admin/Staff analysis.

**In short:** Utilities tells CPRF *when a facility cannot be used because power or water is off*; CPRF blocks those slots and warns booked users. Optional cost data supports reporting only.

### What CPRF can send back (optional)

- Facility list and IDs.
- Approved reservation dates/times per facility so Utilities can relate consumption to actual use (future enhancement).

---

## Summary table (one-page view)

| Partner sends… | CPRF automatically… |
|----------------|---------------------|
| **CIMM:** Maintenance schedule for Facility X, June 10–12 | Blocks June 10–12, sets facility to Maintenance, notifies users with bookings on those dates |
| **CIMM:** Maintenance marked Completed | Sets facility back to Available when window is over |
| **Infrastructure:** Renovation on Facility Y, Jul–Sep, bookings blocked | Blocks Jul–Sep, blackouts on calendar, notifies overlapping reservations |
| **Infrastructure:** Project completed, capacity +200 | Updates Facility Y capacity in CPRF |
| **Infrastructure:** New hall completed | Creates new facility record in CPRF for booking |
| **Utilities:** Water outage at Facility Z, 8 AM–2 PM | Blocks that day/time slot range, notifies affected users |
| **Utilities:** Outage restored | Allows booking again for that period |

---

## What we need from each team (non-technical)

To make integration work, each partner system should be able to answer:

1. **Which facility?** — Use the same facility names as CPRF, or store CPRF’s facility ID on their side.
2. **When?** — Clear start and end date/time for maintenance, projects, or outages.
3. **Is it still active?** — Status so CPRF knows whether to block or release bookings.
4. **Should bookings stop?** — Yes/no (especially for projects and outages).
5. **How do we connect?** — Secure HTTPS link and a shared API key; contact person for testing.

---

## Current status in CPRF

| Integration | In CPRF today |
|-------------|----------------|
| **CIMM / Maintenance** | Integration page live; API design documented; sync can pull schedules when CIMM endpoint is available |
| **Infrastructure Projects** | Integration page live (preview); waiting for Infrastructure system API |
| **Utilities** | Integration page live (preview); waiting for Utilities system API |

Until partner APIs are live, CPRF continues to use **manual** facility status and **manual** blackout dates entered by Admin/Staff.

---

## Related documents

- **Technical field list and API details:** `docs/INTEGRATION_DATA_REQUIREMENTS.md`
- **CIMM API setup (for CIMM developers):** `docs/CIMM_API_INTEGRATION.md`
- **High-level integration diagram:** `docs/INTEGRATION.md`

---

*For capstone defense or LGU coordination: this document explains the **business flow**; the requirements document lists the **exact data fields** for developers.*
