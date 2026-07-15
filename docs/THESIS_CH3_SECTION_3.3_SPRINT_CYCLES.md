# 3.3 Sprint Cycles

**Project:** AI-Driven Facilities Reservation System with Predictive Scheduling Features  
**Barangay:** Culiat, Quezon City  
**Last updated:** July 2026  
**Sources:** `MODULES_LIST.md`, `BACKLOG.md`, `CAPSTONE_IMPLEMENTATION_PLAN.md`, `THESIS_CH3_SECTION_3.4_SCRUM_ARTIFACTS_COMPLETE.md`, `SCRUM_BOARD.md`

---

Sed ut perspiciatis unde omnis… *(Replace this lorem paragraph in Word with the narrative below.)*

The development of the Barangay Culiat Public Facilities Reservation System followed an **Agile Scrum** lifecycle. Work was organized into time-boxed sprints (approximately one to two weeks each). At the start of every sprint, the team pulled prioritized items from the Product Backlog into a Sprint Backlog and tracked them on a Scrum board with three columns—**To Do**, **In Progress**, and **Done**—matching the board convention illustrated in Figure 3.x of this chapter.

**Definition of Done (DoD)** for each item: (1) feature coded and routed, (2) role permissions applied, (3) smoke-tested on key user paths, and (4) documentation or module catalog updated when user-facing behavior changed.

The tables below summarize the sprint cycles for the **entire system**. Items under **Done** reflect shipped capabilities. **In Progress** and **To Do** capture remaining or deferred work for future iterations after the current product increment.

---

## Sprint overview

| Sprint | Theme | Focus |
|--------|--------|--------|
| Sprint 1 | Foundation — Auth & Security | Registration, login OTP, password reset, session hardening |
| Sprint 2 | Public portal & facilities | Public site, facility CRUD, profiles, documents, geocoding |
| Sprint 3 | Booking & AI scheduling | Book facility, conflict detection, recommendations, auto-approval, staff approvals |
| Sprint 4 | Attendance & analytics | Check-in/QR, occupancy, calendar, reports, Smart Scheduler shell |
| Sprint 5 | Integrations & compliance | CIMM, Gemini chatbot/announcements, notifications, audit, privacy export |
| Sprint 6 | Capstone polish (A–D) | Reminders, CI, walk-in, iCal, reports export, dark mode, auth tips |
| Sprint 7 | UX hardening (current) | Auth redesign, blackouts mobile, walk-in search, live occupancy slideshow |

---

## Sprint 1 — Foundation (Auth & Security)

| To Do | In Progress | Done |
|-------|-------------|------|
| — | — | Resident registration (Barangay Culiat streets) |
| | | Email verification before full access |
| | | Login with email/password |
| | | Email OTP second factor |
| | | Forgot / reset password (token email) |
| | | CSRF protection, session timeout, rate limits |
| | | Account lockout after failed attempts |
| | | Password policy enforcement |
| | | Login attempt logging |

---

## Sprint 2 — Public Portal & Facility Management

| To Do | In Progress | Done |
|-------|-------------|------|
| — | — | Public home / landing page |
| | | Facilities listing & facility details |
| | | FAQ, Contact, Privacy, Terms, Legal pages |
| | | Facility CRUD (name, capacity, hours, status) |
| | | Facility images + citations |
| | | Geocoding (lat/long) for distance scoring |
| | | User profile (contact, address, photo) |
| | | Valid ID upload & admin document review |
| | | User Management: approve / deny / lock |
| | | Terms & Data Privacy acceptance on register |

---

## Sprint 3 — Booking Core & AI Scheduling

| To Do | In Progress | Done |
|-------|-------------|------|
| — | — | Book a Facility (date, time, purpose, attendees) |
| | | Flexible start/end time slots |
| | | AI conflict detection + alternative slots |
| | | AI facility recommendations (purpose + distance) |
| | | Auto-approval engine (rule-based conditions) |
| | | Booking limits (active cap, advance window, per-day) |
| | | My Reservations (list / calendar, reschedule, cancel) |
| | | Staff approvals (approve, deny, postpone, modify) |
| | | Reservation detail + timeline / history |
| | | Blackout dates (block booking windows) |
| | | Event permit / supporting document upload |

---

## Sprint 4 — Attendance, Occupancy & Analytics

| To Do | In Progress | Done |
|-------|-------------|------|
| — | — | Manual check-in / check-out (Time Tracking) |
| | | Facility QR check-in posters & scan gate |
| | | Live Occupancy board (staff overrides) |
| | | Live Occupancy dashboard strip |
| | | No-show / late violation flags |
| | | Calendar (month view) + iCal export |
| | | Dashboard KPI charts |
| | | Reports page (charts + filters) |
| | | Smart Scheduler page (ML recommendations) |
| | | AI chatbot shell / assistant widget |

---

## Sprint 5 — Integrations, Communications & Compliance

| To Do | In Progress | Done |
|-------|-------------|------|
| — | — | CIMM maintenance sync → status / blackouts |
| | | Gemini chatbot + fallback responses |
| | | Auto public announcements (maintenance / blackout) |
| | | In-app notifications + email templates |
| | | SMS (opt-in) for OTP / status / reminders |
| | | Announcements management |
| | | Contact inquiries inbox |
| | | Audit trail + export |
| | | Document management & archival cron |
| | | Data Privacy Act self-export (JSON) |
| | | Infrastructure Projects dashboard (preview) |
| | | Utilities / UMAN integration page (preview) |
| | | Optional PayMongo checkout (flag-gated) |

---

## Sprint 6 — Capstone Polish (Sprints A–D)

| To Do | In Progress | Done |
|-------|-------------|------|
| — | — | README, PHPUnit tests, GitHub Actions CI |
| | | Public legal routes (`/terms`, `/legal`) |
| | | 24h booking reminders (email / SMS / in-app) |
| | | Notification preferences (SMS gating) |
| | | Staff walk-in / assisted booking |
| | | Reports CSV / printable HTML export |
| | | Dark mode (dashboard & document pages) |
| | | Auth page field tips (ⓘ) |
| | | Responsive public & dashboard UX fixes |
| | | Security remediation sprints (redirect, CSRF, payments integrity) |

---

## Sprint 7 — UX Hardening (July 2026)

| To Do | In Progress | Done |
|-------|-------------|------|
| Filipino / Tagalog UI (i18n) | — | Split login / register redesign (Culiat theme) |
| Demand forecasting dashboard UI | | Auth facility slideshow (available venues) |
| Live Infrastructure / Utilities APIs | | Hide navbar on full auth flow (OTP, 2FA, reset) |
| True PDF report engine (optional) | | Blackout dates mobile calendar improvements |
| PWA / offline-friendly install | | Walk-in resident searchable picker (max 10 + search API) |
| Health-check endpoint (ops) | | Live Occupancy modern full-bleed slideshow |
| Staging parity checklist execution | | Walk-in resident load performance (no full user fetch) |

---

## Overall Scrum Board — Current System Snapshot

Use this table when the chapter needs a **single system-wide board** (all remaining work vs. major shipped areas).

| To Do | In Progress | Done |
|-------|-------------|------|
| Connect live Infrastructure Projects API (replace mock) | — | Authentication (OTP, TOTP, lockout, password reset) |
| Connect live Utilities / outages API | | Public portal (home, facilities, FAQ, contact, legal) |
| Filipino / Tagalog UI language toggle | | Facility management + blackouts + QR posters |
| Demand forecasting surfaced in Reports / Scheduler | | Full booking lifecycle + AI conflict & recommendations |
| Bulk user CSV import for barangay registry | | Auto-approval + staff approval queue |
| Forgot check-in waiver workflow | | Attendance (manual + QR) + occupancy monitoring |
| Staging / production cron parity & restore drill | | Calendar, reports, Smart Scheduler, Gemini chatbot |
| Production Brevo SMTP cutover (if still on prior mail) | | CIMM sync + Gemini announcements (when configured) |
| Unified LGU inbound API gateway (beyond HTTP 501 stubs) | | Notifications (in-app, email, SMS opt-in) |
| | | Audit trail, document archival, DPA export |
| | | Capstone polish (CI, reminders, walk-in, dark mode) |
| | | July 2026 UX: auth redesign, walk-in search, occupancy slideshow |

---

## How to paste into Microsoft Word

1. Copy each three-column table into Word.
2. Apply a light blue header row for **To Do | In Progress | Done** (as in your sample page).
3. Keep borders on all cells.
4. Place a short narrative paragraph **above** Sprint 1 (use the opening paragraphs of this file).
5. Optional: insert a Gantt chart / timeline figure between the overview table and Sprint 1.

---

## Related documents

| Document | Role |
|----------|------|
| `docs/THESIS_CH3_SECTION_3.4_SCRUM_ARTIFACTS_COMPLETE.md` | Product backlog IDs (F1–F40), DoD, Increment table |
| `docs/MODULES_LIST.md` | What is currently implemented |
| `docs/BACKLOG.md` | Shipped vs. planned future items |
| `docs/CAPSTONE_IMPLEMENTATION_PLAN.md` | Sprints A–D detail |
| `docs/SCRUM_BOARD.md` | Older board notes (superseded by this section for thesis §3.3) |

---

*End of §3.3 Sprint Cycles — ready for Word paste.*
