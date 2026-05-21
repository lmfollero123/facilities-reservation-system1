# Capstone Enhancement Implementation Plan

**Project:** Barangay Culiat Public Facilities Reservation System  
**Last updated:** May 2026

---

## Goals

Close high-value gaps for capstone defense: reproducibility (README, CI), LGU-realistic communications (reminders, SMS prefs), routing polish, and aligned documentation—without rebuilding existing modules.

---

## Sprint A — Defense-ready (implemented)

| # | Item | Deliverable | Status |
|---|------|-------------|--------|
| A1 | Root onboarding | `README.md` — install, `.env`, cron, Python AI | Done |
| A2 | Public legal routes | `terms`, `legal` in `index.php` | Done |
| A3 | Admin sidebar | Contact Inquiries link for Staff/Admin | Done |
| A4 | Booking reminders | `scripts/send_booking_reminders.php` + DB column + cron docs | Done |
| A5 | Automated tests | `tests/` + PHPUnit + `.github/workflows/ci.yml` | Done |
| A6 | Defense narrative | Payments/free-facilities section in `DEFENSE_DOCUMENT.md` | Done |
| A7 | Docs drift | `MODULES_LIST.md` chatbot status updated | Done |

---

## Sprint B — Differentiators (implemented)

| # | Item | Deliverable | Status |
|---|------|-------------|--------|
| B1 | Notification preferences | `config/notification_preferences.php`, migration, Profile UI | Done |
| B2 | SMS gated by prefs | `sendReservationStatusSms()` respects user SMS opt-in | Done |
| B3 | Reminder channels | In-app + email + SMS per prefs in reminder cron | Done |

---

## Sprint C — LGU features (implemented)

| # | Item | Deliverable | Status |
|---|------|-------------|--------|
| C1 | Event permit attachments | Upload on book + view/download on reservation detail & My Reservations | Done |
| C2 | Staff walk-in booking | Staff/Admin resident picker on Book a Facility | Done |
| C5 | Calendar iCal export | `/dashboard/calendar-export` + button on Calendar | Done |
| C6 | Integration pages | Routes + sidebar: Maintenance, Infrastructure Projects, Utilities | Done |

## Sprint C — Deferred

| # | Item | Notes |
|---|------|-------|
| C3 | Live CIMM API | Requires external API from CIMM host |
| C4 | Filipino/Tagalog UI | Partial i18n — large scope |

---

## Sprint D — Phase 2 (in progress)

See **`docs/SPRINT_D_PLAN.md`** for full backlog.

| # | Item | Status |
|---|------|--------|
| D1 | Reports CSV/PDF export buttons (KPI filter) | Done |
| D2 | Document Management dark mode | Done |
| D3 | All Reservations modal dark mode | Done |
| D4 | Auth page field tips | Done |
| D5–D9 | CIMM stub, maintenance API, i18n, cron docs | Planned |

---

## Cron jobs (production)

Run daily (or hourly for reminders):

```bash
# Decline expired pending reservations
php scripts/auto_decline_expired.php

# 24h booking reminders (approved reservations for tomorrow)
php scripts/send_booking_reminders.php

# Optional: process expired / archival (existing scripts)
php scripts/process_expired_reservations.php
```

Example crontab:

```
0 6 * * * cd /path/to/app && php scripts/send_booking_reminders.php >> storage/logs/reminders.log 2>&1
0 2 * * * cd /path/to/app && php scripts/auto_decline_expired.php >> storage/logs/auto_decline.log 2>&1
```

---

## Payments narrative (defense)

- **Base facility use:** Free for Barangay Culiat residents (per facility policy).
- **Optional fees:** Extension hours and PayMongo flow apply only when `PAYMENTS_ENABLED` and facility rates require payment.
- **Chatbot / UI:** Messaging aligned to “free booking; fees only when configured.”

---

## Verification checklist

- [ ] Run `composer install` and `vendor/bin/phpunit`
- [ ] Apply `database/migration_add_notification_preferences.sql`
- [ ] Set Profile → Notification Preferences and test approve/deny SMS
- [ ] Run `php scripts/send_booking_reminders.php --dry-run`
- [ ] Visit `/terms`, `/legal`, `/dashboard/contact-inquiries` (admin)
- [ ] GitHub Actions CI green on push
