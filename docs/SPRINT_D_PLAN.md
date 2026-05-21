# Sprint D — Phase 2 (Planned)

**Project:** Barangay Culiat Public Facilities Reservation System  
**Last updated:** May 2026  
**Status:** Planning / partial UI polish delivered in May 2026 pass

---

## Goals

Extend the capstone beyond Sprint C without blocking defense: operational polish, export/reporting UX, dark-mode consistency, and stubs for external LGU integrations.

---

## Sprint D — Items

| # | Item | Deliverable | Priority | Status |
|---|------|-------------|----------|--------|
| D1 | Reports export UI | CSV + printable HTML/PDF links on Reports page; respects Overview KPIs (`kpi_*`) filter | High | **Done** (May 2026) |
| D2 | Dark mode — Document Management | Theme-aware retention policy cards (`.frs-doc-mgmt-page`) | Medium | **Done** (May 2026) |
| D3 | Dark mode — All Reservations modal | `.frs-all-reservations-modal` CSS variables + dark overrides | Medium | **Done** (May 2026) |
| D4 | Auth page field tips | ⓘ on Login, Register, Forgot/Reset, OTP, Email verification | Medium | **Done** (May 2026) |
| D5 | CIMM integration stub | Config flag + preview page “API pending”; no live sync until host provides endpoint | Low | Planned |
| D6 | Live maintenance API | Webhook/cron to set facility `maintenance` and notify affected bookings | Low | Planned |
| D7 | Filipino/Tagalog UI | `lang/` strings or JSON i18n; language toggle in profile | Low | Deferred (was Sprint C4) |
| D8 | Reports export enhancements | True PDF (TCPDF/Dompdf) optional; include facility name in CSV header row | Low | Planned |
| D9 | Production cron checklist | Document in README: reminders, auto-decline, archival scripts | Medium | Planned |

---

## D5 — CIMM stub (implementation notes)

When the CIMM host provides API credentials:

1. Add `.env`: `CIMM_API_URL`, `CIMM_API_KEY`, `CIMM_SYNC_ENABLED=false`
2. Create `config/cimm_integration.php` with `cimm_fetch_facilities()` returning `['ok' => false, 'message' => 'Not configured']` when disabled
3. Extend `infrastructure_projects_integration.php` (or dedicated route) to show last sync time and sample payload
4. Cron: `scripts/sync_cimm_facilities.php` — map external IDs to local `facilities.external_id`

**Defense narrative:** “Integration UI and routing exist; live sync is gated on LGU API access.”

---

## D6 — Maintenance notifications (implementation notes)

1. On maintenance schedule create/update → query `reservations` where `facility_id` and `status IN ('pending','approved')` and dates overlap
2. `createNotification()` + optional SMS via existing prefs
3. Optionally set facility status to `maintenance` and block new bookings (already partially supported)

---

## Verification (Sprint D completed items)

- [ ] Reports → set Overview KPIs filter → **Export CSV** downloads rows for that period/facility
- [ ] Reports → **Export PDF** downloads HTML; print to PDF in browser
- [ ] Dark mode → Document Management retention cards readable
- [ ] Dark mode → Reservations manage → **All Reservations** modal
- [ ] Auth → hover ⓘ on Forgot password / OTP / Register

---

## Relation to prior sprints

| Sprint | Focus |
|--------|--------|
| A | Defense-ready (README, CI, legal routes, reminders) |
| B | Notification preferences + SMS gating |
| C | Permits, walk-in booking, iCal, integration previews |
| **D** | Export UI, dark-mode polish, auth tips, integration/API phase 2 |

See also: `docs/CAPSTONE_IMPLEMENTATION_PLAN.md`, `docs/BACKLOG.md`, `docs/INTEGRATION.md`.
