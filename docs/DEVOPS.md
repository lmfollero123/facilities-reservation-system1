# DevOps Runbook (Concise)

## CI/CD essentials
- Build: `composer install`, lint PHP/JS/CSS, validate migrations (incl. lock_reason, contact_inquiries, auto_approval fields, user_violations, facility_blackout_dates, performance indexes).
- Smoke (staging): login+OTP, forgot/reset, booking limits (≤3/30d, ≤60d advance, ≤1/day) with holiday/event risk banner stable, **AI conflict/recs (OPTIMIZED: ~60% faster)**, auto-approval evaluation (8 conditions), flexible time slot validation, violations recording, rescheduling, approvals, exports, contact submit (inbox+email), dashboard filters/charts.
- Deploy: run migrations (including performance indexes), zero-downtime sync/rolling update; post-deploy check emails (OTP/reset/approval/lock/inquiry); verify auto-approval logic, violation tracking, time slot parsing, conflict detection performance.

## Monitoring & alerts
- Health: HTTP `/health` (or `/`).
- Watch: 5xx/4xx, latency, auth/OTP failures, reset failures, booking-limit denials, auto-approval evaluation failures, time slot parsing errors, violation recording errors, reschedule validation failures, contact submit errors, DB errors/slow queries, disk for `public/uploads`, **conflict detection response times (target <500ms)**, **ML recommendation timeout fallbacks**, **API call frequency**.
- Alerts: uptime, error spikes, email send failures, auto-approval logic errors, disk near-full, DB slow/connection issues, **conflict detection slow queries (>500ms)**, **high ML timeout fallback rate (>20%)**.

## Security & config
- CSP allowlist: fonts.googleapis.com, fonts.gstatic.com, cdn.jsdelivr.net (Bootstrap Icons/SimpleLightbox), cdnjs.cloudflare.com, Chart.js CDN.
- Secrets: SMTP (Brevo/Gmail), DB, app keys, OTP/pepper, reset token secret, email “from”.
- Tunables via env: rate limits/lockout, booking limits.

## Backups
- DB: scheduled snapshots/PITR.
- Files: snapshot `public/uploads`; verify restore.

## Environments
- Dev: mailhog/mailtrap SMTP; verbose logs; minimal seed data.
- Staging: mirrors prod CSP/CDN; dry-run migrations; sandbox email.
- Prod: zero-downtime deploy; post-deploy email smoke (OTP/reset/inquiry).




