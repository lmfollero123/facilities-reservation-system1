# CPRF Audit Remediation Plan

**Project:** Barangay Culiat Public Facilities Reservation System (CPRF)  
**Audit date:** June 2026  
**Last updated:** June 7, 2026  
**Status:** All planned sprints complete (1–5 + Auth hardening)

This document tracks findings from the full-system audit and remediation work by sprint. Update checkboxes and the status line as each item is completed.

---

## Module scorecard (baseline)

| Module | Baseline score | Target |
|--------|----------------|--------|
| Auth / 2FA | 6/10 | 9/10 |
| Reservations core | 7/10 | 9/10 |
| Payments (PayMongo) | 5/10 | 9/10 |
| Calendar | 5/10 | 8/10 |
| Reports / analytics | 8/10 | 9/10 |
| AI chatbot (widget) | 7/10 | 8/10 |
| AI / ML models | 4/10 | 6/10 |
| CIMM integration | 6/10 | 8/10 |
| Infra / Utilities | 2/10 | 5/10 (honest preview) |
| Public pages | 8/10 | 9/10 |
| Security posture | 7/10 | 9/10 |

---

## Sprint 1 — Security (critical)

**Goal:** Close exploitable auth, verification, and payment webhook gaps before defense / production demo.  
**Status:** ✅ Complete (June 7, 2026)

| # | Item | Severity | Status | Files / notes |
|---|------|----------|--------|----------------|
| 1.1 | Open redirect via `next` param (`//evil.com`) | Critical | ✅ Done | `config/security.php` (`frs_safe_redirect_path`), `login.php`, `login_otp.php` |
| 1.2 | Email verify bypass (auto-login via `?email=` without code) | Critical | ✅ Done | `verify_email.php` — session-bound flow only |
| 1.3 | PayMongo webhook signature verification | Critical | ✅ Done | `paymongo_helper.php`, `paymongo_webhook.php` |
| 1.4 | CSRF on OTP verify + resend | High | ✅ Done | `login_otp.php` |
| 1.5 | CSRF on profile OTP disable toggle | High | ✅ Done | `profile.php` |
| 1.6 | Remove reset-password debug UI (token hashes) | High | ✅ Done | `reset_password.php` |
| 1.7 | Secure `test_ai_models.php` (Admin-only AJAX + page) | Critical | ✅ Done | `test_ai_models.php` |

### Sprint 1 deploy notes

- Ensure `PAYMONGO_WEBHOOK_SECRET` in `~/private/cprf.env` matches the secret shown in PayMongo Dashboard → Webhooks.
- After deploy, test: login → OTP, verify-email flow, PayMongo test webhook (PayMongo dashboard “Send test event”).
- `verify_email` now requires `pending_email_verify_user_id` session (set by register/login before redirect).

---

## Sprint 2 — Booking integrity

**Goal:** Fix payment holds, slot conflicts, and resident privacy leaks.  
**Status:** ✅ Complete (May 27, 2026)

| # | Item | Severity | Status | Files / notes |
|---|------|----------|--------|----------------|
| 2.1 | Include `pending_payment` in conflict / availability queries | Critical | ✅ Done | `ai_helpers.php`, `availability.php`, `availability_api.php` |
| 2.2 | Enforce `payment_due_at` expiry (cron + `pay_now.php`) | Critical | ✅ Done | `reservation_helpers.php` (`autoDeclineExpiredReservations`), `pay_now.php` |
| 2.3 | Calendar privacy — residents see only own bookings | Critical | ✅ Done | `calendar.php` |
| 2.4 | Restrict `scope=all` on My Reservations to Admin/Staff | Critical | ✅ Done | `reservations_hub_mine_tab.php`, `book_facility.php` |
| 2.5 | Overlap-aware conflict checks for modify/reschedule | High | ✅ Done | `reservations_manage.php`, `reservation_detail.php`, `reservations_mine_post_handlers.php` |
| 2.6 | Fix resident detail links + ICS staff-only URLs | High | ✅ Done | `reservations_hub_mine_tab.php`, `calendar_export_ics.php` |
| 2.7 | Set `payment_due_at` when staff approves → `pending_payment` | High | ✅ Done | `reservations_manage.php`, `reservation_detail.php` |
| 2.8 | Fix past-slot logic for `HH:MM - HH:MM` format | High | ✅ Done | `time_helpers.php`, post handlers, hub tab, `reservation_detail.php` |
| 2.9 | Booking race condition (transaction / row lock + recheck) | High | ✅ Done | `book_facility.php` (`frs_lock_facility_for_booking` + `detectBookingConflict` recheck) |

### Sprint 2 deploy notes

- Run or schedule `auto_decline_expired.php` (cron) so expired `pending_payment` holds are released.
- Smoke-test: resident calendar shows only own bookings; `?scope=all` as resident is ignored; overlapping reschedule is rejected; concurrent booking on same slot fails on second submit.

---

## Sprint 3 — Broken UX

**Goal:** Fix user-visible broken flows and orphaned features.  
**Status:** ✅ Complete (May 27, 2026)

| # | Item | Severity | Status | Files / notes |
|---|------|----------|--------|----------------|
| 3.1 | Standalone AI Assistant page wrong fetch URL | Critical | ✅ Done | `ai_chatbot.php` — uses `/dashboard/ai-chatbot` + prefill_booking |
| 3.2 | Announcements sort breaks after AJAX nav | Critical | ✅ Done | `announcements.php`, `public-navigation.js` — delegated sort + query refetch |
| 3.3 | Facility details calendar handlers after AJAX nav | Critical | ✅ Done | `facility_details.php`, `public-navigation.js` |
| 3.4 | Reports AI summary 429 — show rule-based fallback | High | ✅ Done | `reports.php` JS + 429 JSON payload |
| 3.5 | Wire contact form or remove orphan `contact_handler.php` | High | ✅ Done | `index.php` route, `contact.php` form, `public-navigation.js` |
| 3.6 | Fix “Export PDF” label (serves HTML) | High | ✅ Done | `reports.php` — “Export HTML (Print to PDF)” |
| 3.7 | Dashboard “next 7 days” filter + KPI label mismatch | High | ✅ Done | `index.php` week count query; `reports.php` period labels |
| 3.8 | Smart Scheduler “Book this slot” missing day prefill | Medium | ✅ Done | `ai_scheduling.php` — `reservation_date` on book link |

### Sprint 3 deploy notes

- Test standalone AI Assistant at `/dashboard/ai-chatbot` (send message, booking prefill redirect).
- Test public AJAX nav: Announcements sort, facility calendar clicks, contact form submit.
- Contact inquiries appear in Admin → Contact Inquiries after form submit.

---

## Sprint 4 — Integrations honesty

**Goal:** Stop showing fake “Connected” integrations; stabilize CIMM.  
**Status:** ✅ Complete (May 27, 2026)

| # | Item | Severity | Status | Files / notes |
|---|------|----------|--------|----------------|
| 4.1 | Mark Infrastructure/Utilities as Preview / mock | Critical | ✅ Done | `infrastructure_projects_integration.php`, `utilities_integration.php` |
| 4.2 | Move CIMM sync off page-load → cron only | High | ✅ Done | `maintenance_integration.php`, `sync_cimm_maintenance.php`, `frs_cimm_run_sync()` |
| 4.3 | CIMM blackout cleanup when maintenance completes | High | ✅ Done | `cimm_api.php` — removes stale `CIMM Sync:` blackouts |
| 4.4 | Don’t auto-reset manual maintenance to available | High | ✅ Done | `storage/cimm_managed_maintenance.json` tracking in `cimm_api.php` |
| 4.5 | Update stale docs (`MICROSERVICES.md`, `LGU_INTEGRATIONS.md`) | Medium | ✅ Done | `docs/` |
| 4.6 | Implement or remove documented inbound `/api/integrations/*` routes | High | ✅ Done | `index.php` + `integrations_not_implemented.php` (HTTP 501 + docs) |

### Sprint 4 deploy notes

- Add cron: `*/15 * * * * php /home/.../scripts/sync_cimm_maintenance.php`
- Ensure `CIMM_API_KEY` is set in `~/private/cprf.env`
- Infrastructure/Utilities pages now show **Preview — Not Connected**
- Manual staff maintenance is no longer overwritten by CIMM sync unless CIMM previously set that facility to maintenance

---

## Sprint 5 — Polish & cleanup

**Goal:** Dark mode, deduplication, docs, and low-priority hygiene.  
**Status:** ✅ Complete (May 27, 2026)

| # | Item | Severity | Status | Files / notes |
|---|------|----------|--------|----------------|
| 5.1 | Dark mode on auth pages (inline `!important` overrides) | Medium | ✅ Done | `guest_layout.php` light-only inline rules; `style.css` dark body gradient |
| 5.2 | Dashboard dark mode stylesheet | Medium | ✅ Done | `dashboard-pages.css`, session timeout modal classes in `dashboard_layout.php` |
| 5.3 | Deduplicate staff reservation handlers | Medium | ✅ Done | `frs_staff_apply_status_decision()` in `reservation_helpers.php` |
| 5.4 | Calendar week/day views or remove | Medium | ✅ Done | `calendar.php` — month view only; week/day redirect |
| 5.5 | Sidebar: Calendar + AI Assistant links | Low | ✅ Done | `sidebar_dashboard.php` |
| 5.6 | Standardize FAQ path `/faqs` | Low | ✅ Done | `/faq` → 301 `/faqs`; `contact.php`, `public-navigation.js` |
| 5.7 | Delete root test scripts from production | Low | ✅ Done | Removed `test_simple.php`, `test_routing.php`, `test_integration.php`, `test_cimm_connection.php`, `clear_cache.php` |
| 5.8 | Consolidate duplicate Chart.js init | Low | ✅ Done | `public/js/dashboard-charts.js`, `index.php`, `reports.php` |
| 5.9 | Session `name` vs `user_name` consistency | Low | ✅ Done | login/verify set both; `frs_session_display_name()` in `ui_helpers.php` |
| 5.10 | Align register copy with auto-activate behavior | Low | ✅ Done | `register.php` heading tip |

---

## Auth hardening (post–Sprint 5)

**Goal:** Close deferred auth gaps from the original audit.  
**Status:** ✅ Complete (May 27, 2026)  
**Smoke tests:** `docs/DEPLOYMENT_SMOKE_TESTS.md` (section **Auth hardening**)

| Item | Severity | Status | Files / notes |
|------|----------|--------|----------------|
| Login rate limit counts successes | High | ✅ Done | `security.php` — `frs_is_rate_limited` / `recordLoginRateLimitFailure`; `login.php` |
| Email verification brute-force rate limit | High | ✅ Done | `checkEmailVerifyRateLimit`, `verify_email.php` |
| Dashboard router gate on `user_authenticated` | High | ✅ Done | `frs_dashboard_is_authenticated()`, `index.php` |
| Login Turnstile when `CAPTCHA_ENABLED` | Medium | ✅ Done | `login.php` + `captcha.php` |
| Mandatory 2FA for Admin/Staff | Medium | ✅ Done | `frs_role_requires_two_factor()`, `login.php`, `profile.php` |
| Logout via POST + CSRF | Low | ✅ Done | `logout.php`, `frs_logout_form()`, navbar + session modal |
| Single env bootstrap | Medium | ✅ Done | `CPRF_ENV_BOOTSTRAPPED` guard in `app.php` |

---

## Post-remediation testing

Use **`docs/DEPLOYMENT_SMOKE_TESTS.md`** for full per-sprint smoke tests, cron checks, and sign-off after each production deploy.

---

## Changelog

| Date | Sprint | Summary |
|------|--------|---------|
| 2026-06-07 | — | Full-system audit completed; remediation plan created |
| 2026-06-07 | Sprint 1 | Security fixes: redirect, verify-email, PayMongo webhook, CSRF, reset debug, test_ai_models |
| 2026-05-27 | Sprint 5 | Polish: dark mode, staff handler dedup, calendar month-only, FAQ path, chart JS, session name, cleanup |
| 2026-05-27 | Auth hardening | Rate limits, Turnstile on login, mandatory staff 2FA, POST logout, dashboard auth gate, env bootstrap |
| 2026-05-27 | — | Added `docs/DEPLOYMENT_SMOKE_TESTS.md` deployment checklist |

---

## How to update this doc

1. Mark items `✅ Done` when merged/deployed.
2. Update **Last updated** and sprint **Status** line.
3. Add a row to **Changelog** with date and short summary.
4. Optionally bump **Module scorecard** after each sprint.

---

*CPRF capstone / LGU operations — audit remediation tracker*
