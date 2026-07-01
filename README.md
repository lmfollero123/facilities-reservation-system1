# Barangay Culiat Facilities Reservation System

Web-based LGU facility booking for **Barangay Culiat, Quezon City** — resident registration, admin approval, AI-assisted scheduling, check-in, reports, and notifications.

## Requirements

- PHP 8.1+ with extensions: `pdo_mysql`, `curl`, `json`, `mbstring`, `openssl`
- MySQL 8.0+ / MariaDB 10.4+
- Composer
- Optional: Python 3.10+ (ML models in `ai/`)
- Optional: Node not required (vanilla JS + CDN assets)

## Quick start (local)

```bash
# 1. Clone and install PHP dependencies (vendor/ is not in Git)
composer install
```

Production server after deploy:

```bash
composer install --no-dev --no-interaction
```

```bash
# Continue local setup

# 2. Environment
cp .env.example .env
# Edit .env: DB_*, MAIL_*, GEMINI_API_KEY, SMS_* as needed

# 3. Database
mysql -u root -p < database/schema.sql
# Then apply migrations in database/ (newest first if fresh install, or run missing ones)

# 4. Gemini (optional — chatbot falls back to rule-based replies)
cp config/gemini_config.example.php config/gemini_config.php
# Or set GEMINI_API_KEY in .env

# 5. Web server — document root = project root (index.php front controller)
# XAMPP: point vhost to this folder, or:
php -S localhost:8080 index.php

# 6. Login
# Seed admin/user via database/seed or your existing test accounts
```

**Base URL:** If the app lives in a subdirectory, `base_path()` is derived from `SCRIPT_NAME` automatically.

## Environment variables

See `.env.example` for:

| Area | Keys |
|------|------|
| Database | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| Mail | `MAIL_*` |
| Gemini chatbot | `GEMINI_API_KEY` |
| SMS | `SMS_ENABLED`, `SMS_DRIVER`, `IPROG_API_TOKEN` |
| Payments | `PAYMENTS_ENABLED`, `PAYMONGO_*` |
| Captcha | `CAPTCHA_ENABLED`, `TURNSTILE_*` |

**Never commit** `.env` or `config/gemini_config.php`.

## AI / Python models

```bash
cd ai
python -m venv venv
# Windows: venv\Scripts\activate
# Linux/macOS: source venv/bin/activate
pip install -r requirements.txt
python train_models.py   # if models missing
```

Set `FRS_PYTHON` or `PYTHON_EXECUTABLE` in `.env` if PHP cannot find `python`.

Test integration: `php test_integration.php`

## Scheduled tasks (cron)

| Script | Purpose |
|--------|---------|
| `scripts/auto_decline_expired.php` | Decline stale pending reservations |
| `scripts/send_booking_reminders.php` | 24h reminders for approved bookings |
| `scripts/process_expired_reservations.php` | Expired / cleanup flows |

```bash
php scripts/send_booking_reminders.php --dry-run
php scripts/send_booking_reminders.php --verbose
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

CI: GitHub Actions workflow in `.github/workflows/ci.yml`

## Documentation

Full capstone artifacts are under `docs/`:

- `docs/DEFENSE_DOCUMENT.md` — panel narrative
- `docs/MODULES_LIST.md` — **current product catalog** (all modules & features in the system)
- `docs/BACKLOG.md` — shipped vs. partial vs. planned backlog
- `docs/CAPSTONE_IMPLEMENTATION_PLAN.md` — enhancement roadmap
- `docs/DEPLOYMENT_CPANEL.md` — live server setup

## Key routes

| URL | Description |
|-----|-------------|
| `/` | Public home |
| `/login`, `/register` | Auth |
| `/dashboard` | Resident/staff/admin hub |
| `/dashboard/book-facility` | Book + My Reservations tabs |
| `/dashboard/ai-chatbot` | AI assistant API (POST JSON) |
| `/terms`, `/legal`, `/privacy` | Public policies |

## License / academic use

Capstone project — Barangay Culiat LGU context. See course and institution requirements for submission.
