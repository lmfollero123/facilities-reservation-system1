# Staging Parity Checklist — CPRF

Use before each production deploy or major release.

## Environment

- [ ] `.env` / `~/private/cprf.env` matches production keys (DB, SMTP, CIMM, Gemini, SMS)
- [ ] `APP_URL` points to correct host
- [ ] `INTEGRATIONS_INBOUND_KEY` set if using webhook API
- [ ] Cron jobs registered: `send_booking_reminders.php`, `auto_decline_expired.php`, `sync_cimm_maintenance.php`, `archive_documents.php`, `process_operational_occupancy.php`, `send_staff_pending_digest.php`

## Database

- [ ] Latest migrations applied (`database/migration_*.sql`)
- [ ] `reservation_checkin_waivers` table exists (check-in waiver feature)
- [ ] Smoke query: login, list facilities, pending reservations count

## Functional smoke

- [ ] Register → verify email → login OTP
- [ ] Book facility → auto-approve or pending queue
- [ ] Staff approve/deny from Reservations Manage
- [ ] Check-in via QR or Time Tracking
- [ ] `/api/health` returns `healthy` or documented `degraded`
- [ ] Public embed: `/embed/availability?facility_id=1`

## Backup / restore drill

- [ ] Export MySQL dump from staging
- [ ] Restore to isolated DB and verify login
- [ ] Document restore time and responsible operator

## Sign-off

| Role | Name | Date |
|------|------|------|
| Lead dev | | |
| PM | | |
