# Culiat Resident Companion — Mobile API (`/api/mobile/v1`)

Resident-only JSON API for the Flutter companion app. Uses **Bearer JWT** access tokens + rotatable refresh tokens. Web session/CSRF auth is unchanged.

## Setup

1. Run migration: [`database/migration_add_mobile_api.sql`](../database/migration_add_mobile_api.sql)
2. Add to `.env` / `cprf.env`:

```env
MOBILE_JWT_SECRET=change-me-to-a-long-random-string
MOBILE_ACCESS_TTL=900
MOBILE_REFRESH_TTL=2592000
# MOBILE_OTP_DEV_ACCEPT=1   # local only — accepts any 6-digit OTP
# FCM_SERVICE_ACCOUNT_PATH=/home/cprf.infragovservices.com/private/firebase-adminsdk.json
```

3. Base URL: `{APP_URL}/api/mobile/v1`

## Auth

| Method | Path | Auth | Notes |
|--------|------|------|--------|
| POST | `/auth/login` | — | Body: `email`, `password`, optional `device_name`. Residents only. May return `otp_required` + `challenge_id`. |
| POST | `/auth/verify-otp` | — | Body: `challenge_id`, `code` |
| POST | `/auth/resend-otp` | — | Body: `email`+`password` and/or prior `challenge_id` → new code + `challenge_id` |
| POST | `/auth/refresh` | — | Body: `refresh_token` → new pair |
| POST | `/auth/logout` | — | Body: optional `refresh_token` |
| POST | `/auth/forgot-password` | — | Body: `email` |
| POST | `/auth/reset-password` | — | Body: `token`, `password` |
| POST | `/auth/register` | — | Body: `name`, `email`, `password`, optional `mobile`, `address` |

Successful login/refresh:

```json
{
  "ok": true,
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 900,
  "token_type": "Bearer",
  "user": { "id": 1, "name": "...", "email": "...", "role": "Resident" }
}
```

Header for protected routes: `Authorization: Bearer {access_token}`

## Profile

| Method | Path |
|--------|------|
| GET | `/me` |
| PATCH | `/me` (`name`, `mobile`, `address`) |
| POST | `/me/password` (`current_password`, `new_password`) |
| POST | `/me/avatar` | Multipart form field `profile_picture` (JPEG/PNG/GIF/WebP, max 2MB). Returns updated `user` with `avatar_url`. |

## Home / facilities / reservations

| Method | Path |
|--------|------|
| GET | `/home` |
| GET | `/facilities?q=&status=` |
| GET | `/facilities/{id}` |
| GET | `/facilities/{id}/availability?date=YYYY-MM-DD` |
| GET | `/facilities/{id}/calendar?year=&month=` | Day tones: green / yellow / red / blackout |
| GET | `/reservations?status=` |
| GET | `/reservations/{id}` | Includes `amount`, `is_free`, `payment_due_at`, `payment_status` |
| POST | `/reservations` | Body: `facility_id`, `reservation_date`, `time_slot`, `purpose`, **`expected_attendees` (≥1)**, optional `notes`. Same resident rules as the website (ID/identity, advance window, quotas, blackouts, conflicts, duration 30m–12h, capacity). May land in `pending_payment` when PayMongo hybrid mode is on. |
| GET | `/booking/policy` | Resident limits + rules (`per_day`/`week`/`month`/`year`, `max_upcoming_active`, `advance_max_days`, `can_book`, identity message). |
| POST | `/reservations/{id}/cancel` | Cancels; attempts PayMongo refund if paid |
| POST | `/reservations/{id}/pay` | Starts PayMongo checkout → `checkout_url` (GCash / card / QRPh) |
| POST | `/reservations/{id}/payment-sync` | Polls PayMongo after browser checkout |
| POST | `/reservations/{id}/reschedule` | Body: `reservation_date`, `time_slot`, **`reason` (required)**. Same date/slot/blackout/conflict rules as create. Once only · ≥3 days before event · not while `pending_payment` · not after start. |
| GET | `/reservations/{id}/pass` | Approved only — QR payload |
| POST | `/check-in/facility` | Body: `token` (facility QR token or full URL) |
| GET | `/occupancy/live` |
| GET | `/announcements` |
| GET | `/notifications` |
| POST | `/notifications/{id}/read` |
| POST | `/devices` | Body: `fcm_token`, optional `platform`, `device_name`. System-tray push needs: app `google-services.json` + server `FCM_SERVICE_ACCOUNT_PATH` (Firebase Admin SDK JSON) + `createNotification` → FCM HTTP v1. In-app inbox works without FCM. |
| GET / PATCH | `/me/preferences` | Notification channels (`booking_*`, `reminder_*` × in_app/email/sms) + security (`email_otp`, disable `google_authenticator`). Same prefs as website Profile. |
| GET | `/smart-scheduler` | Personalized / popular reservation recommendations (same `RecommendationService` as website Smart Scheduler). Optional Gemini insight blurb when `GEMINI_API_KEY` is set. |
| POST | `/assistant/chat` | Body: `message`, optional `history` (last 10 turns). Gemini-backed PFRS assistant; may return `action: prefill_booking`. If Gemini is down but a key is configured, returns a rule-based `reply` with `error: gemini_unavailable` (still HTTP 200). |

## Errors

```json
{ "ok": false, "error": "invalid_credentials", "message": "..." }
```

Common HTTP codes: `401` unauthorized, `403` role/inactive, `404` not found, `409` conflict, `422` validation.

## PHP entry

Routed from [`index.php`](../index.php) → [`resources/views/pages/public/api/mobile/index.php`](../resources/views/pages/public/api/mobile/index.php)  
Helpers: [`config/mobile_jwt.php`](../config/mobile_jwt.php), [`config/mobile_auth.php`](../config/mobile_auth.php)
