# Barangay Culiat Public Facilities Reservation System
## Product Catalog — Modules & Features (Current System)

**Last updated:** June 2026  
**Purpose:** Single reference for what is **currently built and deployed in this codebase** — modules, sub-features, roles, routes, and integrations.  
**Audience:** Capstone panel, LGU stakeholders, developers onboarding to the project.

**Related docs:** `BACKLOG.md` (done vs. planned items), `CAPSTONE_IMPLEMENTATION_PLAN.md` (sprint history), `DEFENSE_DOCUMENT.md` (narrative), `STRUCTURE.md` (folder layout).

**Roles:** `Resident` · `Staff` · `Admin` (Staff and Admin inherit resident booking capabilities unless noted)

**Routing:** All live URLs are defined in `index.php`. `routes/web.php` is not maintained.

---

## System at a Glance

| Area | Summary |
|------|---------|
| **Public portal** | Landing, facility browse, announcements, FAQ, contact, legal pages |
| **Auth & accounts** | Registration, email verification, login OTP/TOTP, password reset, profile |
| **Booking** | Request, approve/deny, reschedule, cancel, extensions, walk-in (Staff), limits & anti-abuse |
| **Facilities** | CRUD, images, hours, geocoding, blackouts, maintenance status, facility QR check-in |
| **Attendance** | Manual check-in/out, facility QR scan, occupancy monitoring, violation tracking |
| **AI** | Conflict detection, recommendations, risk scoring, purpose analysis, Gemini chatbot |
| **Comms** | In-app notifications, email, SMS (opt-in), announcements, contact inquiries |
| **Admin** | User management, document archival, audit trail, reports, integrations dashboard |
| **Privacy** | Secure document storage, retention/archival, user data export (Data Privacy Act) |

---

## 1. Authentication & User Management

**Purpose:** Secure onboarding, login, roles, and account lifecycle for Barangay Culiat residents and LGU staff.

| # | Submodule | Status | Access | Route / entry |
|---|-----------|--------|--------|---------------|
| 1.1 | Resident registration (Barangay Culiat streets, optional Valid ID upload) | Implemented | Public | `/register` |
| 1.2 | Terms & Data Privacy acceptance (modal + checkbox) | Implemented | Public | `/register` |
| 1.3 | Cloudflare Turnstile captcha on registration | Implemented | Public | `/register` |
| 1.4 | Email verification before full access | Implemented | Public | `/verify-email` |
| 1.5 | Email + password login | Implemented | Public | `/login` |
| 1.6 | Email OTP (second factor) | Implemented | Public | `/login-otp` |
| 1.7 | Google Authenticator (TOTP) setup | Implemented | Authenticated | `/login-setup-2fa`, Profile |
| 1.8 | Forgot / reset password (token email) | Implemented | Public | `/forgot-password`, `/reset-password` |
| 1.9 | Session security (timeout, CSRF, rate limits, lockout) | Implemented | System | `config/security.php` |
| 1.10 | Profile (name, contact, address, geocoding, photo, notification prefs) | Implemented | All | `/dashboard/profile` |
| 1.11 | Admin/staff user directory (search, filter, approve, verify ID, lock, reset password) | Implemented | Staff+ (limited), Admin (full) | `/dashboard/user-management` |
| 1.12 | Admin/staff **create account** (Resident or Staff, email credentials) | Implemented | Staff+ (Resident only), Admin (Resident + Staff) | User Management modal |
| 1.13 | Role change (Resident / Staff / Admin) | Implemented | Admin | User Management |
| 1.14 | Account deletion with reason + email notice | Implemented | Admin | User Management |
| 1.15 | Violation count & history per user | Implemented | Admin | User Management |
| 1.16 | Secure document download (registration IDs) | Implemented | Owner, Admin | `/dashboard/download-document` |
| 1.17 | Data Privacy Act self-export (JSON) | Implemented | All | Profile → download export |

**Key files:** `resources/views/pages/auth/*`, `resources/views/pages/dashboard/user_management.php`, `config/user_admin.php`, `config/culiat_streets.php`, `config/secure_documents.php`, `config/data_export.php`

---

## 2. Public Portal

**Purpose:** Information and self-service entry points for citizens before login.

| # | Submodule | Status | Route |
|---|-----------|--------|-------|
| 2.1 | Home / landing (featured facilities, announcements) | Implemented | `/` |
| 2.2 | Public facilities listing | Implemented | `/facilities` |
| 2.3 | Facility details (specs, calendar snapshot, image citations) | Implemented | `/facility-details` |
| 2.4 | Public announcements archive (search, categories, pagination) | Implemented | `/announcements` |
| 2.5 | FAQ page | Implemented | `/faqs` |
| 2.6 | Contact form → DB + admin email | Implemented | `/contact`, `/contact-handler` |
| 2.7 | Privacy, Terms, Legal pages | Implemented | `/privacy`, `/terms`, `/legal` |
| 2.8 | Public availability API (for guest assistant widget) | Implemented | `/api/public/availability` |
| 2.9 | Guest facility assistant widget (availability Q&A) | Implemented | Guest layout component |
| 2.10 | Payment return page (when payments enabled) | Optional | `/payment-return` |

**Key files:** `resources/views/pages/public/*`, `resources/views/components/facility_assistant.php`

---

## 3. Facility Management

**Purpose:** Maintain facility inventory, availability rules, and operational status.

| # | Submodule | Status | Access | Route |
|---|-----------|--------|--------|-------|
| 3.1 | Facility CRUD (name, capacity, description, status) | Implemented | Staff+ | `/dashboard/facility-management` |
| 3.2 | Facility images + image citation/attribution | Implemented | Staff+ | Facility Management |
| 3.3 | Operating hours configuration | Implemented | Staff+ | Facility Management |
| 3.4 | Geocoding (lat/long for distance scoring) | Implemented | Staff+ | Facility form, `/dashboard/geocode-api` |
| 3.5 | Auto-approval settings per facility | Implemented | Staff+ | Facility Management |
| 3.6 | Extension fee configuration | Implemented | Staff+ | Facility Management + migration |
| 3.7 | Facility status (Available, Maintenance, Offline) | Implemented | Staff+ | Facility Management |
| 3.8 | **Blackout dates** (block booking windows) | Implemented | Staff+ | `/dashboard/blackout-dates` |
| 3.9 | **Facility check-in QR** (generate, regenerate, print poster) | Implemented | Staff+ | Facility Management, `/dashboard/facility-qr-print` |
| 3.10 | CIMM maintenance sync → facility status & blackouts | Implemented* | Staff+ | `/dashboard/maintenance-integration` |
| 3.11 | Public facility display fields | Implemented | Public | Public facility pages |

\* Requires external CIMM API key (`CIMM_API_KEY`) and cron `scripts/sync_cimm_maintenance.php`.

**Key files:** `resources/views/pages/dashboard/facility_management.php`, `facility_qr_print.php`, `config/blackout_dates.php`, `config/occupancy_monitoring.php`, `services/cimm_api.php`

---

## 4. Reservation & Booking

**Purpose:** End-to-end facility reservation lifecycle from request to completion.

| # | Submodule | Status | Access | Route |
|---|-----------|--------|--------|-------|
| 4.1 | Book a facility (date, time range, purpose, attendees, commercial flag) | Implemented | All | `/dashboard/book-facility` |
| 4.2 | Event permit / supporting document upload | Implemented | All | Book a Facility |
| 4.3 | AI conflict warnings + alternative slot suggestions | Implemented | All | Book a Facility |
| 4.4 | AI facility recommendations (purpose + distance) | Implemented | All | Book a Facility |
| 4.5 | Booking limits (active cap, advance window, per-day limit) | Implemented | System | `config/reservation_helpers.php` |
| 4.6 | Auto-approval (verified residents + low risk) | Implemented | System | `config/auto_approval.php` |
| 4.7 | **My Reservations** (calendar + list, past styling, reschedule, cancel) | Implemented | All | `/dashboard/book-facility?module=mine` |
| 4.8 | Reschedule (limited count, minimum days before event) | Implemented | Resident | My Reservations |
| 4.9 | Reservation extension (extra hours + fee logic) | Implemented | All | Reservation detail / helpers |
| 4.10 | **Reservation approvals** (approve, deny, postpone, on hold, modify, cancel) | Implemented | Staff+ | `/dashboard/reservations-manage` |
| 4.11 | Reservation detail + timeline/history | Implemented | Staff+ / owner | `/dashboard/reservation-detail` |
| 4.12 | Staff walk-in booking (book on behalf of resident) | Implemented | Staff+ | Book a Facility |
| 4.13 | Violation recording on reservations | Implemented | Staff+ | Reservation management |
| 4.14 | Auto-decline expired pending reservations | Implemented | Cron + dashboard load | `scripts/auto_decline_expired.php` |
| 4.15 | Booking reminders (24h before, email/SMS/in-app) | Implemented | Cron | `scripts/send_booking_reminders.php` |
| 4.16 | Secure reservation document download | Implemented | Authorized | `/dashboard/download-reservation-document` |
| 4.17 | PayMongo checkout for reservations | Optional | All | `/dashboard/pay-now` (`PAYMENTS_ENABLED`) |

**Key files:** `book_facility.php`, `reservations_manage.php`, `reservation_detail.php`, `includes/reservations_hub_mine_tab.php`, `config/reservation_helpers.php`, `config/violations.php`

---

## 5. Attendance, Check-In & Live Occupancy

**Purpose:** Track who is on-site, enforce fair use, and give staff live facility utilization visibility.

| # | Submodule | Status | Access | Route |
|---|-----------|--------|--------|-------|
| 5.1 | Manual check-in / check-out (time tracking) | Implemented | All | `/dashboard/time-tracking` |
| 5.2 | Optional photo proof on check-in/out | Implemented | All | Time Tracking |
| 5.3 | **Facility QR check-in** (scan posted QR → auto in/out for today’s booking) | Implemented | All | `/dashboard/facility-check-in` |
| 5.4 | Per-reservation QR check-in gate (legacy path) | Implemented | All | `/dashboard/check-in` |
| 5.5 | Attendance reminders + late/no-show violation flags | Implemented | Cron | `scripts/attendance_reminders.php` |
| 5.6 | Operational occupancy processing (no-show after grace) | Implemented | Cron | `scripts/process_operational_occupancy.php` |
| 5.7 | **Live Occupancy** dashboard strip (carousel, View All modal) | Implemented | Staff+ | Dashboard home |
| 5.8 | Live Occupancy monitor (staff override board) | Implemented | Staff+ | `/dashboard/occupancy-monitor` |
| 5.9 | Occupancy live JSON API (polling) | Implemented | Staff+ | `/dashboard/occupancy-live` |

**Key files:** `time_tracking.php`, `facility_check_in_gate.php`, `occupancy_monitor.php`, `components/occupancy_dashboard_strip.php`, `config/attendance.php`, `config/occupancy_monitoring.php`

---

## 6. AI & Smart Scheduling

**Purpose:** Intelligent assistance for booking decisions, risk assessment, and user support.

| # | Submodule | Status | Access | Route / entry |
|---|-----------|--------|--------|---------------|
| 6.1 | Real-time conflict detection (hard/soft) | Implemented | All | `/dashboard/ai-conflict-check` |
| 6.2 | Smart facility recommendations (ML + rule fallback) | Implemented | All | `/dashboard/facility-recommendations` |
| 6.3 | Booking smart hints API | Implemented | All | `/dashboard/booking-smart-hints` |
| 6.4 | Distance scoring (Haversine) | Implemented | System | `config/reservation_helpers.php` |
| 6.5 | Holiday & local event risk tagging | Implemented | System | Booking + calendar |
| 6.6 | Auto-approval risk scoring (Python ML) | Implemented | System | `ai/api/predict_risk.py` |
| 6.7 | Purpose classification / unclear-purpose detection | Implemented | System | `ai/api/classify_purpose.py` |
| 6.8 | **Smart Scheduler** dashboard (AI scheduling insights) | Implemented | All | `/dashboard/ai-scheduling` |
| 6.9 | **AI Chatbot** (Gemini primary, ML intent + rule fallback) | Implemented | Dashboard | Floating widget, `/dashboard/chatbot-api` |
| 6.10 | Chatbot booking prefill from conversation | Implemented | All | Chatbot widget |
| 6.11 | Demand forecasting | Partial | System | `ai/api/forecast_demand.py` (not full UI) |
| 6.12 | Performance DB indexes for AI queries | Implemented | System | `database/migration_add_performance_indexes.sql` |

**Key files:** `config/ai_ml_integration.php`, `config/gemini_chatbot.php`, `ai/api/*.py`, `ai_scheduling.php`, `ai_chatbot.php`

---

## 7. Calendar & Visualization

**Purpose:** Visual scheduling views and exports.

| # | Submodule | Status | Access | Route |
|---|-----------|--------|--------|-------|
| 7.1 | Month / week / day calendar views | Implemented | All | `/dashboard/calendar` |
| 7.2 | Clickable events → reservation detail | Implemented | All | Calendar |
| 7.3 | Holiday & event markers | Implemented | All | Calendar |
| 7.4 | Past reservation grey styling (history) | Implemented | All | My Reservations calendar |
| 7.5 | Facility detail calendar snapshot | Implemented | Public | Facility details |
| 7.6 | iCal export | Implemented | All | `/dashboard/calendar-export` |
| 7.7 | Dashboard charts (filterable KPIs, trends) | Implemented | All | `/dashboard` |

**Key files:** `calendar.php`, `calendar_export_ics.php`, `config/booking_calendar_status.php`, `config/analytics_chart_filters.php`

---

## 8. Notifications & Communications

**Purpose:** Keep users and staff informed across channels.

| # | Submodule | Status | Access | Route |
|---|-----------|--------|--------|-------|
| 8.1 | In-app notifications | Implemented | All | Navbar bell, `/dashboard/notifications` |
| 8.2 | Notifications API (lazy load, mark read) | Implemented | All | `/dashboard/notifications-api` |
| 8.3 | Email templates (approval, lock, reset, booking, verified, welcome) | Implemented | System | `config/email_templates.php` |
| 8.4 | SMTP email delivery | Implemented | System | `config/mail_helper.php` |
| 8.5 | SMS (IPROG / PhilSMS, opt-in per user) | Implemented | System | `config/sms_helper.php` |
| 8.6 | Notification preferences (in-app / email / SMS) | Implemented | All | Profile |
| 8.7 | Reservation status notifications (all channels per prefs) | Implemented | System | Booking workflow |
| 8.8 | **Announcements management** (create, image, category, link) | Implemented | Staff+ | `/dashboard/announcements-manage` |
| 8.9 | Public announcements archive | Implemented | Public | `/announcements` |
| 8.10 | **Contact inquiries inbox** (view/respond) | Implemented | Staff+ | `/dashboard/contact-inquiries` |
| 8.11 | **Contact information management** (public page content) | Implemented | Staff+ | `/dashboard/contact-info` |
| 8.12 | SMS test tool | Implemented | Admin | `/dashboard/sms-test` |

**Key files:** `config/notifications.php`, `config/notification_preferences.php`, `announcements_manage.php`, `contact_inquiries.php`, `contact_info_manage.php`

---

## 9. Reports & Analytics

**Purpose:** Operational metrics and exportable reports for LGU decision-making.

| # | Submodule | Status | Access | Route |
|---|-----------|--------|--------|-------|
| 9.1 | Reports dashboard (charts: monthly, status, top facilities) | Implemented | Staff+ | `/dashboard/reports` |
| 9.2 | CSV export | Implemented | Staff+ | Reports |
| 9.3 | Printable HTML / PDF-style export | Implemented | Staff+ | `reports_print.php`, `export_pdf.php` |
| 9.4 | Dashboard role-aware statistics | Implemented | All | `/dashboard` |
| 9.5 | Audit trail export (Staff+) | Implemented | Staff+ | `/dashboard/export-audit-trail` |

**Key files:** `reports.php`, `export_view.php`, `export_pdf.php`

---

## 10. External Integrations

**Purpose:** Connect to LGU microservices and third-party services.

| Integration | Status | UI route | Notes |
|-------------|--------|----------|-------|
| **CIMM (Maintenance Management)** | Implemented* | `/dashboard/maintenance-integration` | Live sync when API configured; cron every 15 min |
| **Infrastructure Projects** | Mock / preview | `/dashboard/infrastructure-projects` | UI with sample data; external API not wired |
| **Utilities (outages/costs)** | Mock / preview | `/dashboard/utilities-integration` | UI with sample data; external API not wired |
| **PayMongo payments** | Optional | Pay Now, webhook | Off by default; facilities free in capstone narrative |
| **Google Gemini** | Implemented | Chatbot | Falls back to rules/ML if no API key |
| **Python ML services** | Implemented | Background APIs | Subprocess calls from PHP |
| **OpenStreetMap / Mapbox geocoding** | Implemented | Profile, facilities | Configurable in `config/geocoding.php` |
| **Cloudflare Turnstile** | Implemented | Registration | `config/captcha.php` |
| **Integrations API gateway stub** | Placeholder | `/api/integrations/*` | Returns not implemented |

**Key files:** `services/cimm_api.php`, `scripts/sync_cimm_maintenance.php`, `config/paymongo_helper.php`, `docs/CIMM_API_INTEGRATION.md`, `docs/LGU_INTEGRATIONS.md`

---

## 11. System Administration, Security & Compliance

**Purpose:** Governance, auditability, document lifecycle, and security controls.

| # | Submodule | Status | Access | Route |
|---|-----------|--------|--------|-------|
| 11.1 | **Audit trail** (filter, paginate, PDF) | Implemented | Admin | `/dashboard/audit-trail` |
| 11.2 | Audit logging across modules | Implemented | System | `config/audit.php` |
| 11.3 | **Document management** (archival, retention, restore, stats) | Implemented | Admin | `/dashboard/document-management` |
| 11.4 | Secure document storage + access logging | Implemented | System | `config/secure_documents.php` |
| 11.5 | Document archival cron | Implemented | Cron | `scripts/archive_documents.php` |
| 11.6 | Data cleanup / retention cron | Implemented | Cron | `scripts/cleanup_old_data.php` |
| 11.7 | Rate limiting & security event logs | Implemented | System | `config/security.php` |
| 11.8 | CSRF protection on forms | Implemented | System | All POST handlers |
| 11.9 | Security headers / CSP | Implemented | System | App bootstrap |
| 11.10 | Database migrations (40+ incremental) | Implemented | DevOps | `database/` |
| 11.11 | PHPUnit smoke tests + GitHub Actions CI | Implemented | DevOps | `tests/`, `.github/workflows/ci.yml` |
| 11.12 | Session keepalive for long forms | Implemented | All | `/dashboard/session-keepalive` |

**Key files:** `audit_trail.php`, `document_management.php`, `config/document_archival.php`, `docs/DATA_PRIVACY_ACT_COMPLIANCE.md`

---

## Role Access Matrix (Dashboard Navigation)

| Module | Resident | Staff | Admin |
|--------|:--------:|:-----:|:-----:|
| Dashboard, Book, My Reservations, Check In/Out | ✓ | ✓ | ✓ |
| Smart Scheduler, AI Chatbot | ✓ | ✓ | ✓ |
| Calendar, Notifications, Profile | ✓ | ✓ | ✓ |
| Reservation Approvals, Facility Mgmt, Blackouts | — | ✓ | ✓ |
| Announcements, Contact Inquiries, Contact Info | — | ✓ | ✓ |
| Maintenance / Infrastructure / Utilities integrations | — | ✓ | ✓ |
| Live Occupancy, Reports | — | ✓ | ✓ |
| User Management (create residents; limited for Staff) | — | ✓ | ✓ |
| Document Management, SMS Test, Audit Trail | — | — | ✓ |

**Not in sidebar but available:** Calendar (`/dashboard/calendar`), Reservation Detail, Facility QR Print, Pay Now (optional), JSON/API endpoints.

---

## Background Jobs (Cron / Task Scheduler)

| Script | Recommended schedule | Purpose |
|--------|---------------------|---------|
| `scripts/auto_decline_expired.php` | Daily | Decline stale pending reservations |
| `scripts/send_booking_reminders.php` | Daily | 24-hour booking reminders |
| `scripts/attendance_reminders.php` | Every 5–10 min | Check-in/out reminders; late/no-show violations |
| `scripts/process_operational_occupancy.php` | Every 5–10 min | No-show detection after grace period |
| `scripts/sync_cimm_maintenance.php` | Every 15 min | CIMM → facility status & blackouts |
| `scripts/archive_documents.php` | Daily | Archive documents per retention policy |
| `scripts/cleanup_old_data.php` | Weekly | Purge aged data per policy |
| `scripts/optimize_database.php` | Weekly | DB maintenance |

See `README.md` and `docs/DEPLOYMENT.md` for setup.

---

## Status Legend

| Label | Meaning |
|-------|---------|
| **Implemented** | Built, wired to UI and/or cron, usable in production with correct `.env` |
| **Partial** | Core logic exists; UI or coverage incomplete |
| **Optional** | Feature-flagged or disabled by default (e.g. payments) |
| **Mock / preview** | Dashboard UI only; no live external API |
| **Implemented*** | Requires external service configuration to be fully live |

---

## What Is Not in Scope (Yet)

Items below are **not** part of the current shipped product — see `BACKLOG.md` for the ordered remaining work.

- Filipino/Tagalog UI (i18n)
- Live Infrastructure Projects & Utilities APIs (beyond preview UI)
- Full demand-forecasting UI in Reports
- Brevo SMTP migration (if still on legacy mail provider)
- Mobile native app
- IoT / automatic door sensors for check-in

---

*This document replaces the prior short MODULES_LIST (8 modules, ~170 lines) and should be updated whenever a major feature ships or is retired.*
