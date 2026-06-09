# CPRF Deployment Smoke Test Checklist

**Project:** Barangay Culiat Public Facilities Reservation System (CPRF)  
**Use after:** Any production deploy following audit remediation (Sprints 1–5 + Auth hardening)  
**Last updated:** May 27, 2026

Run these tests on **staging first**, then repeat on production after deploy. Check each box when passed. If a test fails, note the URL, role, and error in your deploy log before rollback.

---

## Pre-deploy checklist

| # | Check | Pass |
|---|--------|:----:|
| P1 | `composer install --no-dev` completed on server | ☐ |
| P2 | `~/private/cprf.env` (or `.env`) has current secrets — DB, mail, SMS, PayMongo, Turnstile, Gemini, CIMM | ☐ |
| P3 | `PAYMONGO_WEBHOOK_SECRET` matches PayMongo Dashboard → Webhooks | ☐ |
| P4 | Cron jobs active (see [Cron verification](#cron-verification)) | ☐ |
| P5 | Root test scripts **not** present: `test_simple.php`, `test_routing.php`, `test_integration.php`, `test_cimm_connection.php`, `clear_cache.php` | ☐ |
| P6 | `CAPTCHA_ENABLED=true` and Turnstile keys set (if using Cloudflare) | ☐ |

---

## Cron verification

| Job | Schedule | Command / script | Pass |
|-----|----------|------------------|:----:|
| C1 | `*/15 * * * *` | `php …/scripts/sync_cimm_maintenance.php` | ☐ |
| C2 | Every 5–15 min | `php …/scripts/auto_decline_expired.php` (or `process_expired_reservations.php`) | ☐ |
| C3 | Daily (optional) | `php …/scripts/cleanup_old_data.php` | ☐ |

After C1 runs, confirm `storage/cimm_managed_maintenance.json` updates and Maintenance Integration page shows last sync time.

---

## Sprint 1 — Security

| # | Test | Steps | Expected | Pass |
|---|------|-------|----------|:----:|
| 1.1 | Open redirect blocked | Log in, then visit `/login?next=//evil.com` and complete login | Lands on `/dashboard` (or safe default), **not** external site | ☐ |
| 1.2 | Email verify bypass blocked | Visit `/verify-email?email=victim@example.com` without `pending_email_verify_user_id` session | Redirect to `/login` | ☐ |
| 1.3 | PayMongo webhook signature | PayMongo Dashboard → Webhooks → **Send test event** with wrong secret | HTTP 401/403; with correct secret → 200 | ☐ |
| 1.4 | OTP CSRF | POST to `/login-otp` without CSRF token | Rejected; form with token works | ☐ |
| 1.5 | Profile OTP toggle CSRF | Toggle OTP in profile via forged POST | Rejected | ☐ |
| 1.6 | Reset password — no debug | Open `/reset-password?token=…` | No token hashes or debug output visible | ☐ |
| 1.7 | AI models page secured | As Resident, open `/dashboard/test-ai-models` | Access denied / redirect | ☐ |
| 1.7b | As Admin, same URL | Page loads; AJAX requires auth | ☐ |

---

## Sprint 2 — Booking integrity

| # | Test | Steps | Expected | Pass |
|---|------|-------|----------|:----:|
| 2.1 | `pending_payment` blocks slot | Staff approves booking → `pending_payment`; resident tries same slot | Second booking rejected / slot unavailable | ☐ |
| 2.2 | Payment hold expiry | Let `payment_due_at` pass (or run expiry cron) | Hold released; status `cancelled` or `denied` per policy | ☐ |
| 2.3 | Resident calendar privacy | Log in as Resident → `/dashboard/calendar` | Only own reservations visible | ☐ |
| 2.4 | `scope=all` gated | As Resident, open `/dashboard/book-facility?module=mine&scope=all` | Shows own bookings only | ☐ |
| 2.4b | As Staff/Admin, same URL | Can see all (if feature enabled) | ☐ |
| 2.5 | Overlap on reschedule | Staff modifies approved booking to overlapping time | Error: overlap rejected | ☐ |
| 2.6 | Resident detail links | My Reservations → view detail | Correct `/dashboard/reservation-detail?id=` (staff) or allowed resident view | ☐ |
| 2.7 | Approve → `pending_payment` | With payments enabled, staff approves pending request | Status `pending_payment`; `payment_due_at` set | ☐ |
| 2.8 | Past slot detection | Try to modify/cancel a reservation whose `HH:MM - HH:MM` slot has passed | Blocked with clear message | ☐ |
| 2.9 | Concurrent booking race | Two browsers, same facility/date/time, submit simultaneously | One succeeds; second fails with conflict | ☐ |

---

## Sprint 3 — Broken UX

| # | Test | Steps | Expected | Pass |
|---|------|-------|----------|:----:|
| 3.1 | AI Assistant page | `/dashboard/ai-chatbot` — send “book basketball court tomorrow” | Gemini reply or clear fallback; **Book this slot** prefill if applicable | ☐ |
| 3.2 | Announcements sort (AJAX nav) | Home → Announcements via nav → change sort | List re-sorts without full reload break | ☐ |
| 3.3 | Facility calendar (AJAX nav) | Facilities → facility detail → click calendar day | Modal / booking flow works | ☐ |
| 3.4 | Reports AI 429 fallback | Trigger rate limit on reports AI summary (or simulate 429) | Rule-based fallback text shown, not blank error | ☐ |
| 3.5 | Contact form | `/contact` — submit valid inquiry | Success message; appears in Admin → Contact Inquiries | ☐ |
| 3.6 | Export label | Reports → export control | Label says **Export HTML (Print to PDF)** | ☐ |
| 3.7 | Dashboard 7-day KPI | Dashboard home — “next 7 days” stat | Count matches upcoming approved/pending in next 7 days | ☐ |
| 3.8 | Smart Scheduler prefill | AI Scheduling → **Book this slot** | Book form opens with `reservation_date` + time prefilled | ☐ |

---

## Sprint 4 — Integrations honesty

| # | Test | Steps | Expected | Pass |
|---|------|-------|----------|:----:|
| 4.1 | Infrastructure preview | `/dashboard/infrastructure-projects` | **Preview — Not Connected**; sample data labeled | ☐ |
| 4.1b | Utilities preview | `/dashboard/utilities-integration` | Same honest labeling | ☐ |
| 4.2 | CIMM no page-load sync | Load Maintenance Integration; watch network | No automatic CIMM API call on page load | ☐ |
| 4.2b | Manual sync | Click **Sync Now** (Staff) | Sync runs; success/error message | ☐ |
| 4.3 | Blackout cleanup | Complete CIMM maintenance item | Stale `CIMM Sync:` blackout removed | ☐ |
| 4.4 | Manual maintenance preserved | Set facility maintenance manually; run CIMM sync | Manual status not reset unless CIMM owned it | ☐ |
| 4.5 | Docs accurate | Skim `docs/MICROSERVICES.md`, `docs/LGU_INTEGRATIONS.md` | Matches live behavior | ☐ |
| 4.6 | Inbound API 501 | `GET /api/integrations/anything` | HTTP 501 + JSON/message per `integrations_not_implemented.php` | ☐ |

---

## Sprint 5 — Polish & cleanup

| # | Test | Steps | Expected | Pass |
|---|------|-------|----------|:----:|
| 5.1 | Auth dark mode | `/login` — toggle dark theme | Auth card readable; inputs not forced white | ☐ |
| 5.2 | Dashboard dark mode | Dashboard — dark theme | Cards, charts, session timeout modal readable | ☐ |
| 5.3 | Staff approve (shared handler) | Approve/deny from Reservations Manage **and** Reservation Detail | Same notifications/email; no PHP errors | ☐ |
| 5.4 | Calendar month only | `/dashboard/calendar?view=week` | Redirects to month view | ☐ |
| 5.5 | Sidebar links | Sidebar → **Calendar**, **AI Assistant** | Routes work | ☐ |
| 5.6 | FAQ path | Visit `/faq` | 301 to `/faqs` | ☐ |
| 5.7 | Test scripts removed | `curl` root `/test_simple.php` etc. | 404 / not found | ☐ |
| 5.8 | Charts render | Dashboard home + Reports | Monthly, status, facility charts load (no JS console errors) | ☐ |
| 5.9 | Display name | Log in; check dashboard header | Shows user name (not generic “User”) | ☐ |
| 5.10 | Register copy | `/register` heading | Mentions email verification, not admin approval | ☐ |

---

## Auth hardening (post–Sprint 5)

| # | Test | Steps | Expected | Pass |
|---|------|-------|----------|:----:|
| A1 | Login rate limit — successes exempt | Log in successfully 6+ times (same email, correct password) | No “too many attempts” from rate limiter | ☐ |
| A1b | Failed attempts limited | Wrong password 5+ times | Rate limit message after threshold | ☐ |
| A2 | Email verify rate limit | Wrong verification code 10+ times | Blocked for 15 minutes | ☐ |
| A3 | Dashboard router gate | Set session `user_id` without `user_authenticated`; open `/dashboard` | Redirect to login | ☐ |
| A4 | Login Turnstile | With `CAPTCHA_ENABLED=true`, submit login without completing Turnstile | Rejected | ☐ |
| A5 | Mandatory 2FA (Staff/Admin) | Staff login with OTP disabled in DB | Still required to complete OTP/TOTP step | ☐ |
| A5b | Disable OTP in profile (Staff) | Toggle OTP off in profile | Error: cannot disable for Admin/Staff | ☐ |
| A6 | Logout POST + CSRF | GET `/logout` | Confirmation page (not instant logout) | ☐ |
| A6b | Navbar logout | Confirm → POST logout | Session cleared; login required | ☐ |
| A6c | GET `/logout` without CSRF POST | Shows form only; session remains until POST | ☐ |

---

## Gemini / chatbot (post-remediation)

| # | Test | Steps | Expected | Pass |
|---|------|-------|----------|:----:|
| G1 | API key from env | Ensure `GEMINI_API_KEY` only in `cprf.env` (not stale `gemini_config.php`) | Chatbot returns real replies, not generic fallback | ☐ |
| G2 | Widget + standalone | Dashboard widget + `/dashboard/ai-chatbot` | Both reach `/dashboard/ai-chatbot` backend | ☐ |
| G3 | Rate limit | Send 25+ chat messages in 1 hour | Polite rate-limit message | ☐ |

---

## Quick regression (5-minute smoke)

Minimum checks if time is limited:

1. ☐ Login (resident) → OTP → dashboard  
2. ☐ Book facility → pending → staff approve → (payment if enabled)  
3. ☐ Public: home, facilities, contact form, `/faqs`  
4. ☐ PayMongo test webhook (signature)  
5. ☐ Logout via navbar (POST)  
6. ☐ CIMM cron last run < 20 minutes ago  

---

## Sign-off

| Role | Name | Date | Environment |
|------|------|------|-------------|
| Developer | | | Staging / Production |
| QA / Tester | | | |
| Admin / LGU | | | |

---

*Related: `docs/AUDIT_REMEDIATION_PLAN.md`, `docs/DEPLOYMENT_CPANEL.md`, `docs/GEMINI_APPEAL_AND_SECURITY.md`*
