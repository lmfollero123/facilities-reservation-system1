# Capstone setup and testing (Sprints A–C)

Step-by-step instructions for **local XAMPP** and **live cPanel** (IndevFinite or similar).

Replace `YOUR_DOMAIN` and paths with your real values.

---

## 0. Deploy the code first

1. Push/pull the latest code to the server (Git or upload).
2. On the server, keep your existing `.env`, `config/database.php`, and `config/gemini_config.php` — do **not** overwrite them with empty examples.
3. Run Composer on the server only if you use tests/CI there (optional for production):

```bash
composer install --no-dev
```

For local development with tests:

```bash
composer install
```

---

## 1. Database migrations (required once)

Run **both** (if not already applied):

1. `database/migration_add_notification_preferences.sql` (Sprint B)
2. `database/migration_add_reservation_documents.sql` (Sprint C)

Sprint B adds:

- `users.notification_preferences` (JSON)
- `reservations.reminder_sent_at` (timestamp)

### Option A — phpMyAdmin (easiest on live)

1. cPanel → **phpMyAdmin** → select your database (e.g. `facilities_reservation`).
2. **SQL** tab → paste and run:

```sql
ALTER TABLE users
  ADD COLUMN notification_preferences JSON NULL
    COMMENT 'User opt-in for in-app, email, SMS by category';

ALTER TABLE reservations
  ADD COLUMN reminder_sent_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'When 24h reminder was sent';
```

If you get “Duplicate column name”, the migration already ran — you can skip.

### Option B — MySQL CLI

```bash
mysql -u YOUR_USER -p YOUR_DATABASE < database/migration_add_notification_preferences.sql
```

### Option C — Auto on first use

The app can create these columns when someone opens **Profile** or when the reminder script runs. Option A is still recommended so you know the schema is correct.

---

## 2. Where to see Terms and Legal

Routes were added in `index.php`. URLs (adjust if your app is in a subfolder):

| Page | URL |
|------|-----|
| Terms & Conditions | `https://YOUR_DOMAIN/terms` |
| Legal notice | `https://YOUR_DOMAIN/legal` |
| Privacy (already existed) | `https://YOUR_DOMAIN/privacy` |

**Examples**

- Live: `https://cprf.infragovservices.com/terms`
- Local XAMPP at root: `http://localhost/terms`
- Local subfolder: `http://localhost/facilities_reservation_system/terms`

**In the UI**

- Public site **footer** → **Legal & Compliance** → Terms & Conditions / Legal Notice / Privacy Policy
- **Register** page still has the full terms modal (checkbox + links)

**Quick test:** Open `/terms` in the browser — you should see the green legal page layout, not a 404.

---

## 3. Contact Inquiries (Sprint A — admin)

**Who:** Admin or Staff, logged in.

**Where:** Dashboard sidebar → **Communications** → **Contact Inquiries**

**Direct URL:** `https://YOUR_DOMAIN/dashboard/contact-inquiries`

**Test:** Submit the public contact form at `/contact`, then check this page and your admin email.

---

## 4. Notification preferences (Sprint B)

**Who:** Any logged-in user.

**Where:** Dashboard → **Profile** → scroll to **Notification Preferences**

**Test steps**

1. Log in as a resident.
2. Profile → uncheck **SMS** under booking status → Save.
3. Have staff **approve** a reservation for that user → user should get in-app + email (if enabled), but **no SMS**.
4. Re-enable SMS → approve another booking → SMS should send (if `SMS_ENABLED=true` and mobile number is set).

Defaults: booking in-app/email/SMS on; reminder in-app/email on, **reminder SMS off**.

---

## 5. Booking reminders cron (Sprint A)

Script: `scripts/send_booking_reminders.php`

**What it does:** For **approved** reservations with `reservation_date` = **tomorrow**, sends reminder (in-app / email / SMS per user prefs) and sets `reminder_sent_at` so it does not send twice.

### Test locally (no cron needed)

```bash
cd E:\Capstone_project\facilities_reservation_system

# See what would run (no emails/SMS/notifications written)
php scripts/send_booking_reminders.php --dry-run --verbose

# Actually send (need an approved reservation dated TOMORROW)
php scripts/send_booking_reminders.php --verbose
```

**Create test data**

1. Approve a reservation whose date is **tomorrow** (same as server date/timezone).
2. Run dry-run — output should list that reservation ID.
3. Run without `--dry-run` — check Notifications panel and email/SMS.

Timezone: app uses **Asia/Manila** (`config/app.php`).

### Live server — cPanel Cron Jobs

1. cPanel → **Cron Jobs**.
2. Add a **once per day** job (e.g. **6:00 AM** server time).

**Command template** (edit paths):

```bash
cd /home/USERNAME/public_html && /usr/local/bin/php scripts/send_booking_reminders.php >> storage/logs/reminders.log 2>&1
```

If the project is in a subfolder:

```bash
cd /home/USERNAME/public_html/facilities_reservation_system && /usr/local/bin/php scripts/send_booking_reminders.php >> storage/logs/reminders.log 2>&1
```

**Find PHP path on cPanel**

- Cron screen often shows something like `/usr/local/bin/php` or `/usr/bin/php`.
- Or: cPanel → **Select PHP Version** → **php.ini** path hint, or run a one-line test cron: `php -v >> ~/php_version.txt`

**Create log folder** (File Manager or SSH):

```bash
mkdir -p storage/logs
chmod 755 storage/logs
```

**Existing job — auto-decline** (recommended daily):

```bash
cd /home/USERNAME/public_html && /usr/local/bin/php scripts/auto_decline_expired.php >> storage/logs/auto_decline.log 2>&1
```

### Live server — SSH (VPS)

```bash
crontab -e
```

Add:

```cron
0 6 * * * cd /var/www/your-app && /usr/bin/php scripts/send_booking_reminders.php >> storage/logs/reminders.log 2>&1
0 2 * * * cd /var/www/your-app && /usr/bin/php scripts/auto_decline_expired.php >> storage/logs/auto_decline.log 2>&1
```

---

## 6. PHPUnit tests (Sprint A — local / CI)

**Local**

```bash
composer install
vendor\bin\phpunit
```

Expected: `OK (7 tests, 15 assertions)`.

**GitHub Actions**

After push to `main`/`master`/`develop`, check the **Actions** tab — workflow `.github/workflows/ci.yml` runs the same tests.

You do **not** need PHPUnit on live hosting for the app to work.

---

## 7. Environment variables (`.env`)

Already on your server; optional new key in `.env.example`:

```env
BOOKING_REMINDER_HOURS_BEFORE=24
```

Reminders currently use **calendar tomorrow** (not this numeric env yet). No change required for basic operation.

Ensure these still work for Sprint B:

| Variable | Purpose |
|----------|---------|
| `GEMINI_API_KEY` | AI chatbot |
| `MAIL_*` | Approval + reminder emails |
| `SMS_ENABLED`, `SMS_DRIVER`, `IPROG_API_TOKEN` | SMS on approve/deny/reminder |

---

## 8. Sprint C — quick tests

| Feature | How to test |
|---------|-------------|
| Event permit upload | Book with **Supporting document** → Staff **Reservation Details** shows View/Download; resident sees file under **View Details** on My Reservations |
| Walk-in booking | Log in as **Staff** → Book a Facility → pick a **Resident** → submit; reservation `user_id` = resident |
| iCal export | **Calendar** → **Export to calendar (.ics)** → open file in Google/Outlook |
| Integration previews | Sidebar **Urban Planning**, **Energy**, **Road Transport** (mock data pages) |

---

## 9. Full checklist

### After deploy

- [ ] Both SQL migrations applied (notification prefs + reservation documents)
- [ ] `/terms` and `/legal` load in browser
- [ ] Footer links open those pages
- [ ] Admin sees **Contact Inquiries** in sidebar
- [ ] Profile shows **Notification Preferences**
- [ ] Cron job added for `send_booking_reminders.php`
- [ ] Cron job for `auto_decline_expired.php` (if not already)
- [ ] Dry-run reminder script on server via SSH or one-time cron

### Demo for panel / defense

1. Show `/terms` and `/privacy` from footer.
2. Show Profile notification toggles.
3. Approve a booking → in-app notification (+ email/SMS if enabled).
4. Explain cron: “24 hours before the event, residents get a reminder.”
5. Run `php scripts/send_booking_reminders.php --dry-run` in terminal (optional).
6. Staff walk-in: book for a resident from **Book a Facility**.
7. Calendar **.ics** export for approved bookings.

---

## 10. Troubleshooting

| Problem | Fix |
|---------|-----|
| `/terms` 404 | Pull latest code; confirm `index.php` has `terms` and `legal` routes; `.htaccess` routes to `index.php` |
| No Contact Inquiries menu | Log in as **Admin** or **Staff**; hard refresh (Ctrl+F5) |
| Reminders never send | Reservation must be **approved**, date = **tomorrow**, `reminder_sent_at` NULL; run script manually with `--verbose` |
| Duplicate reminders | Column `reminder_sent_at` should be set after send; do not delete it between runs |
| SMS not sent | Check Profile prefs, `SMS_ENABLED`, mobile on user profile, `sms_test` page (Admin) |
| PHPUnit fails on server | Use local machine or CI only; run `composer install` first |

---

## Related docs

- [CAPSTONE_IMPLEMENTATION_PLAN.md](./CAPSTONE_IMPLEMENTATION_PLAN.md) — what was built
- [DEPLOYMENT_CPANEL.md](./DEPLOYMENT_CPANEL.md) — general live deploy
- [README.md](../README.md) — project overview
