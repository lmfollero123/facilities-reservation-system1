# User Stories & Product Backlog — Complete (Implementation-Verified)

**System:** Barangay Culiat Public Facilities Reservation System (CPRF)  
**Last updated:** July 2026  
**Rule:** Stories reflect **implemented** behavior unless marked **Not implemented**.

---

# PART A — USER STORIES

## Epic 1: Registration & Authentication

| ID | User Story | Status |
|----|------------|--------|
| US-1.1 | As a **resident**, I can register with name, email, password, mobile, and Barangay Culiat street address so only eligible residents sign up. | Implemented |
| US-1.2 | As a **resident**, I can upload a Valid ID during or after registration so staff can verify my residency. | Implemented |
| US-1.3 | As a **resident**, I must accept Terms & Data Privacy before registering. | Implemented |
| US-1.4 | As a **resident**, I complete email verification before full system access. | Implemented |
| US-1.5 | As a **resident**, I log in with email and password, then complete email OTP verification. | Implemented |
| US-1.6 | As a **resident**, I can enable Google Authenticator (TOTP) for login. | Implemented |
| US-1.7 | As a **resident** who lost TOTP access, I can request a one-time email recovery code at login (without re-enabling email OTP in profile). | Implemented |
| US-1.8 | As a **user**, I can reset my password via emailed token. | Implemented |
| US-1.9 | As a **locked user**, I see the lock reason on login and receive email notification. | Implemented |
| US-1.10 | As a **resident**, I can update profile (name, contact, address, geocoordinates, photo, notification preferences). | Implemented |
| US-1.11 | As a **resident**, I can export my personal data (Data Privacy Act JSON export). | Implemented |
| US-1.12 | As a **visitor**, I complete Cloudflare Turnstile captcha on registration when enabled. | Implemented |
| US-1.13 | As a **resident**, I register using only social media without email verification. | **Not implemented** |

## Epic 2: User Management (Staff/Admin)

| ID | User Story | Status |
|----|------------|--------|
| US-2.1 | As **staff/admin**, I can view, search, and filter the user directory. | Implemented |
| US-2.2 | As **staff/admin**, I can approve, deny, lock, or unlock resident accounts with notes. | Implemented |
| US-2.3 | As **staff/admin**, I can verify resident IDs from an **ID Verification** tab (queue of pending ID uploads). | Implemented |
| US-2.4 | As **staff/admin**, I can reset a user’s password and email credentials. | Implemented |
| US-2.5 | As **staff**, I can create new Resident accounts; as **admin**, I can create Staff accounts. | Implemented |
| US-2.6 | As **admin**, I can change user roles (Resident / Staff / Admin). | Implemented |
| US-2.7 | As **admin**, I can delete accounts with reason and email notice. | Implemented |
| US-2.8 | As **staff/admin**, I can view violation history per user. | Implemented |
| US-2.9 | As **staff/admin**, I can download user registration documents securely. | Implemented |
| US-2.10 | As **staff**, I can bulk-import users from CSV. | Implemented |

## Epic 3: Public Portal

| ID | User Story | Status |
|----|------------|--------|
| US-3.1 | As a **visitor**, I can view the home page with featured facilities and latest announcements. | Implemented |
| US-3.2 | As a **visitor**, I can browse public facility listings with images and citations. | Implemented |
| US-3.3 | As a **visitor**, I can view facility details and calendar snapshot. | Implemented |
| US-3.4 | As a **visitor**, I can read announcements (search, categories, pagination). | Implemented |
| US-3.5 | As a **visitor**, I can read FAQ, Privacy, Terms, and Legal pages. | Implemented |
| US-3.6 | As a **visitor**, I can submit a contact form stored in admin inbox. | Implemented |
| US-3.7 | As a **visitor**, I can use the guest facility assistant (availability widget). | Implemented |
| US-3.8 | As a **visitor**, I can check public availability via `/api/public/availability`. | Implemented |

## Epic 4: Facility Management

| ID | User Story | Status |
|----|------------|--------|
| US-4.1 | As **staff/admin**, I can create, edit, and retire facilities (name, capacity, description, status). | Implemented |
| US-4.2 | As **staff/admin**, I can upload facility images with citation/attribution. | Implemented |
| US-4.3 | As **staff/admin**, I can set operating hours and geocode facility location. | Implemented |
| US-4.4 | As **staff/admin**, I can configure per-facility auto-approval and extension fees. | Implemented |
| US-4.5 | As **staff/admin**, I can add **blackout dates** (single day or range) blocking bookings. | Implemented |
| US-4.6 | As **staff/admin**, when I add CPRF blackouts, the system can auto-publish a Gemini-written public announcement with facility photo. | Implemented |
| US-4.7 | As **staff/admin**, I can generate, regenerate, and print facility check-in QR posters. | Implemented |
| US-4.8 | As **staff/admin**, I cannot manually delete CIMM-synced blackout rows (managed by CIMM sync). | Implemented |
| US-4.9 | As **staff/admin**, I can assign UMAN utility assets to facilities when UMAN is configured. | Implemented* |

\* Requires `UMAN_API_KEY`.

## Epic 5: Booking & Reservations

| ID | User Story | Status |
|----|------------|--------|
| US-5.1 | As a **resident**, I can book a facility (date, time range, purpose, attendees, commercial flag, supporting documents). | Implemented |
| US-5.2 | As a **resident**, I must be ID-verified (or Staff/Admin) before booking. | Implemented |
| US-5.3 | As a **resident**, I see AI conflict warnings and alternative slots. | Implemented |
| US-5.4 | As a **resident**, I see AI facility recommendations with distance scoring. | Implemented |
| US-5.5 | As a **resident**, I am blocked by booking limits (1/day, 3/week, 8/month, 96/year, max 2 upcoming active, ≤60-day advance). Staff/Admin bypass. | Implemented |
| US-5.6 | As a **resident**, my booking is auto-approved when all auto-approval rules pass. | Implemented |
| US-5.7 | As a **resident**, I can view **My Reservations** (calendar + list) and reschedule/cancel per rules. | Implemented |
| US-5.8 | As **staff**, I can book on behalf of a resident (walk-in). | Implemented |
| US-5.9 | As **staff/admin**, I manage approvals in **Pending** and **Approved** tabs with filters and pagination. | Implemented |
| US-5.10 | As **staff/admin**, I can approve, deny, postpone, hold, modify, or cancel reservations with reasons. | Implemented |
| US-5.11 | As **staff/admin**, I can view reservation detail with full timeline/history. | Implemented |
| US-5.12 | As **staff/admin**, I can record violations linked to reservations. | Implemented |
| US-5.13 | As a **user**, I receive notifications (in-app/email/SMS per prefs) on status changes. | Implemented |
| US-5.14 | As a **resident**, I can pay for a reservation via PayMongo when payments are enabled. | Optional |
| US-5.15 | As **staff**, I receive a daily digest email of all pending approvals. | Implemented* |

## Epic 6: Attendance & Occupancy

| ID | User Story | Status |
|----|------------|--------|
| US-6.1 | As a **user**, I can manually check in and check out with optional photo proof. | Implemented |
| US-6.2 | As a **user**, I can scan the facility QR poster to check in/out for today’s approved booking. | Implemented |
| US-6.3 | As **staff**, I see a live occupancy dashboard strip and full monitor with overrides. | Implemented |
| US-6.4 | As the **system**, I send attendance reminders and flag no-shows after grace period. | Implemented |
| US-6.5 | As a **resident**, I can self-request a check-in waiver without staff approval. | Implemented (staff reviews waiver) |

## Epic 7: AI & Smart Scheduling

| ID | User Story | Status |
|----|------------|--------|
| US-7.1 | As a **user**, I can use the **Smart Scheduler** page for AI scheduling insights. | Implemented |
| US-7.2 | As a **user**, I can chat with the **Gemini AI assistant** (fallback to rules/ML if no API key). | Implemented |
| US-7.3 | As a **user**, the chatbot can prefill the booking form from conversation. | Implemented |
| US-7.4 | As the **system**, I score booking risk and classify purpose via Python ML. | Implemented |
| US-7.5 | As a **user**, I see holiday and local event risk on the booking calendar. | Implemented |
| US-7.6 | As **admin/dev**, I can use **AI Model Lab** to test models and demo scenarios. | Implemented |
| US-7.7 | As **staff**, I see a full demand-forecasting dashboard in Reports. | **Partial** — API exists; limited UI |
| US-7.8 | As a **user**, I can use voice input with the chatbot. | Implemented (browser Web Speech API) |

## Epic 8: Calendar & Reports

| ID | User Story | Status |
|----|------------|--------|
| US-8.1 | As a **user**, I can view month/week/day calendar with clickable events. | Implemented |
| US-8.2 | As a **user**, I can export calendar to iCal. | Implemented |
| US-8.3 | As **staff/admin**, I can view Reports with charts and export CSV/PDF. | Implemented |
| US-8.4 | As **staff/admin**, I can filter dashboard KPI charts. | Implemented |
| US-8.5 | As **admin**, I can export audit trail to PDF. | Implemented |

## Epic 9: Communications

| ID | User Story | Status |
|----|------------|--------|
| US-9.1 | As a **user**, I receive in-app notifications and mark them read. | Implemented |
| US-9.2 | As **staff/admin**, I can create public announcements (title, message, image, link, category). | Implemented |
| US-9.3 | As the **system**, I auto-publish Gemini announcements when CIMM schedules new maintenance. | Implemented |
| US-9.4 | As **staff/admin**, I can manage contact inquiries and public contact info. | Implemented |
| US-9.5 | As **admin**, I can send SMS test messages. | Implemented |
| US-9.6 | As a **resident**, I receive SMS two-way booking confirmation (reply YES). | **Not implemented** |

## Epic 10: Integrations

| ID | User Story | Status |
|----|------------|--------|
| US-10.1 | As **staff**, I can view CIMM maintenance schedules and sync facility status/blackouts. | Implemented* |
| US-10.2 | As a **resident**, I see upcoming CIMM maintenance on the booking calendar before maintenance starts. | Implemented |
| US-10.3 | As **staff**, I can submit maintenance requests to CIMM from CPRF. | Implemented* |
| US-10.4 | As **staff**, I can view **Infrastructure Projects** integration dashboard. | **Connected (thesis)** — preview UI with sample data in codebase; live QC Infrastructure report ingestion documented as connected at LGU layer |
| US-10.5 | As the **system**, I automatically block facilities from QC Infrastructure construction timelines. | **Partial** — webhook `POST /api/integrations/projects/timeline` creates blackouts when key configured |
| US-10.6 | As **staff**, I can view UMAN utilities integration and sync asset requests. | Implemented* |
| US-10.7 | As **staff**, I receive live utility outage auto-blocks on facilities. | **Partial** — webhook `POST /api/integrations/utilities/outage` |
| US-10.8 | As an **external system**, I can POST to `/api/integrations/*` gateway. | Implemented* |

\* Requires API keys / cron.

## Epic 11: Administration & Compliance

| ID | User Story | Status |
|----|------------|--------|
| US-11.1 | As **admin**, I can view and filter audit trail. | Implemented |
| US-11.2 | As **admin**, I can manage document archival, retention, and restore. | Implemented |
| US-11.3 | As **admin**, I can view system settings and integration health. | Implemented |
| US-11.4 | As the **system**, I run cron jobs (reminders, CIMM sync, archival, auto-decline). | Implemented |
| US-11.5 | As **admin**, I can view consolidated health check endpoint (DB, mail, ML, CIMM). | Implemented (`GET /api/health`) |

## Epic 12: UX & Accessibility

| ID | User Story | Status |
|----|------------|--------|
| US-12.1 | As a **user**, I can use the system on mobile (responsive layouts). | Implemented |
| US-12.2 | As a **user**, I can use dark mode on public/dashboard surfaces. | Implemented |
| US-12.3 | As a **user**, I can use the UI in Filipino/Tagalog. | **Not implemented** |
| US-12.4 | As a **user**, I can install a PWA offline app. | **Not implemented** |

---

# PART B — PRODUCT BACKLOG

## B.1 Shipped (Implemented) — Summary by Module

| Module | Key delivered items |
|--------|---------------------|
| Auth | Registration, OTP, TOTP, TOTP recovery, reset password, sessions, Turnstile |
| Users | Management, ID verification tab, create user, violations, DPA export |
| Public | Home, facilities, announcements, FAQ, contact, legal, availability API |
| Facilities | CRUD, blackouts, QR, CIMM sync, auto-announcements on blackout |
| Booking | Full lifecycle, auto-approval, tabs, walk-in, extensions, limits |
| Attendance | Manual + QR check-in, occupancy monitor, no-show processing |
| AI | Conflict, recommendations, risk, chatbot, Smart Scheduler, Model Lab |
| Calendar/Reports | Views, iCal, KPIs, CSV/PDF |
| Comms | Notifications, email, SMS, announcements (manual + AI auto) |
| Admin | Audit, documents, system settings, migrations, PHPUnit CI |
| Integrations | CIMM (live), UMAN (when configured), Gemini, PayMongo (optional) |

## B.2 Partial / Optional

| ID | Item | State |
|----|------|-------|
| PB-P1 | Demand forecasting UI | Python API trained; Reports UI incomplete |
| PB-P2 | PayMongo payments | Wired; `PAYMENTS_ENABLED=false` by default |
| PB-P3 | CIMM live sync | Requires `CIMM_API_KEY` + cron on production |
| PB-P4 | UMAN utilities | Requires `UMAN_API_KEY` |
| PB-P5 | Gemini features | Requires `GEMINI_API_KEY` |
| PB-P6 | Infrastructure dashboard | UI + sample data; thesis marks LGU connection; auto-block **not implemented** |
| PB-P7 | Filipino/Tagalog UI | Deferred |

## B.3 Not Implemented (Future Backlog)

| ID | Priority | Item | Notes |
|----|----------|------|-------|
| PB-F5 | Medium | Filipino/Tagalog UI and message templates | Large i18n scope |
| PB-F6 | Medium | PWA / installable web app | Deferred (mobile-adjacent) |
| PB-F7 | Medium | SMS two-way confirm | Requires SMS provider reply webhook |
| PB-F12 | Medium | Barangay ID / third-party identity verify | External service needed |
| PB-F13 | AI | SHAP explainability for auto-approval denials | Research scope |
| PB-F15 | AI | Anomaly detection for suspicious booking patterns | ML pipeline |
| PB-F18 | Security | Separate S3-compatible document bucket | Infrastructure change |
| PB-F19 | Security | WAF beyond Turnstile | Hosting / Cloudflare config |
| PB-F21 | Mobile | Native iOS/Android app | Out of scope — use responsive web |
| PB-F22 | Equipment | Equipment inventory and reservation module | Removed from scope |

## B.3.1 Recently implemented (July 2026)

| ID | Item |
|----|------|
| PB-F1 | Check-in waiver (resident request + staff approve at `/dashboard/checkin-waivers`) |
| PB-F2 | Bulk user CSV import (User Management) |
| PB-F3 | Staff daily pending digest (`scripts/send_staff_pending_digest.php`) |
| PB-F4 | Hard block when attendees exceed facility capacity (booking form) |
| PB-F8 | Public embeddable availability widget (`/embed/availability?facility_id=`) |
| PB-F9/F10 | Integration webhooks auto-blackout (`/api/integrations/projects/timeline`, `utilities/outage`) |
| PB-F11 | Unified `/api/integrations/*` gateway |
| PB-F14 | Chatbot voice input (Web Speech API) |
| PB-F16 | Health check endpoint (`GET /api/health`) |
| PB-F17 | Staging parity checklist (`docs/STAGING_PARITY_CHECKLIST.md`) |
| PB-F20 | PIA template (`docs/PRIVACY_IMPACT_ASSESSMENT_TEMPLATE.md`) |

## B.4 Explicitly Removed from Scope (Do Not Backlog as Planned)

| Item | Reason |
|------|--------|
| Equipment reservation | Never implemented; facilities only |
| Social login | Not implemented |
| Microservice container deployment | Modular monolith chosen |

---

# PART C — STORY POINT REFERENCE (for Scrum artifacts)

| Epic | Implemented stories | Not implemented |
|------|---------------------|-----------------|
| 1 Auth | 12 | 1 |
| 2 User Mgmt | 10 | 0 |
| 3 Public | 8 | 0 |
| 4 Facilities | 8 | 0 |
| 5 Booking | 14 | 1 |
| 6 Attendance | 5 | 0 |
| 7 AI | 7 | 1 |
| 8 Calendar/Reports | 5 | 0 |
| 9 Comms | 5 | 1 |
| 10 Integrations | 4 | 2 |
| 11 Admin | 5 | 0 |
| 12 UX | 2 | 2 |

---

*Cross-reference: `docs/MODULES_LIST.md`, `docs/BACKLOG.md`, `docs/THESIS_COMPLETE_CH1_TO_CH3.md`, `docs/THESIS_CH3_SECTION_3.4_SCRUM_ARTIFACTS_COMPLETE.md`*
