# Code Index

## Overview

This document provides a comprehensive index of all code files in the Facilities Reservation System, organized by directory and purpose. Each file includes a brief description of its responsibility.

## Root Directory

### Core Files

| File | Purpose |
|------|---------|
| `index.php` | Front controller - routes all requests, handles authentication, maps URLs to views |
| `.htaccess` | Apache configuration - URL rewriting, security headers, directory protection |
| `composer.json` | PHP dependencies configuration (PHPMailer, TwoFactorAuth, PHPUnit) |
| `package.json` | Node.js dependencies (TailwindCSS build process) |
| `run_migrations.php` | Database migration runner - executes SQL migrations in order |
| `tailwind.config.js` | TailwindCSS configuration - custom theme, plugins, content paths |

## config/

### Core Configuration

| File | Purpose |
|------|---------|
| `app.php` | Core application helpers - URL/path generation, environment loading, timezone, session initialization |
| `database.php` | Database connection - PDO singleton, connection configuration from environment |
| `database.example.php` | Example database configuration template (gitignored version is `database.php`) |
| `security.php` | Security configuration - CSRF tokens, rate limiting, password validation, session security, security headers, file upload validation |
| `permissions.php` | RBAC system - permission checking, role-based access control, permission caching |
| `lookups.php` | Lookup values management - configurable dropdown options, metadata, CRUD operations |

### Authentication & User Management

| File | Purpose |
|------|---------|
| `user_admin.php` | User management functions - CRUD operations, role assignment, verification, deactivation |
| `secure_documents.php` | Secure document storage - file upload validation, secure path generation, archival system |
| `document_archival.php` | Document archival logic - automatic archival of old documents, cleanup |

### Reservation & Booking

| File | Purpose |
|------|---------|
| `reservation_helpers.php` | Reservation business logic - availability checking, conflict detection, booking limits |
| `auto_approval.php` | Auto-approval rules - evaluate conditions for automatic reservation approval |
| `reservation_documents.php` | Reservation document functions - upload, retrieve, delete supporting documents |
| `booking_calendar_status.php` | Calendar status helpers - determine slot availability for calendar display |

### AI & ML Integration

| File | Purpose |
|------|---------|
| `ai_helpers.php` | AI helper functions - purpose analysis, conflict detection, recommendations |
| `ai_ml_integration.php` | ML model integration - facility recommendations, scheduling optimization |
| `gemini_chatbot.php` | Gemini API client - chatbot integration, prompt engineering, response handling |
| `chatbot_responses.php` | Rule-based chatbot responses - fallback responses when AI unavailable |

### Communications

| File | Purpose |
|------|---------|
| `mail.php` | Email configuration - SMTP settings, PHPMailer configuration |
| `mail_helper.php` | Email sending functions - sendEmail, booking confirmations, reminders, templates |
| `email_templates.php` | Email template functions - HTML email templates for various notifications |
| `sms.php` | SMS configuration - SMS driver selection, provider settings |
| `sms_helper.php` | SMS sending functions - sendSms, booking confirmations, OTP codes |
| `notifications.php` | Notification functions - database notifications, read/unread tracking, in-app alerts |
| `notification_preferences.php` | User notification preferences - email/SMS opt-in settings |

### Payments

| File | Purpose |
|------|---------|
| `payments.php` | Payment configuration - payment gateway settings, payment window, currency |
| `paymongo.php` | PayMongo configuration - API keys, webhook secret, checkout settings |
| `paymongo_helper.php` | PayMongo integration - create checkout, handle webhooks, sync payment status |

### External Integrations

| File | Purpose |
|------|---------|
| `geocoding.php` | Geocoding functions - address to coordinates conversion, map display |
| `geocoding_config.example.php` | Geocoding configuration template (Mapbox/Google Maps) |
| `maintenance_helper.php` | Maintenance integration - CIMM sync, blackout date management |
| `blackout_dates.php` | Blackout date functions - CRUD operations, availability checking |

### Analytics & Reporting

| File | Purpose |
|------|---------|
| `analytics_chart_filters.php` | Analytics chart filters - report data filtering, aggregation |
| `data_export.php` | Data export functions - CSV/Excel export, report generation |
| `occupancy_monitoring.php` | Occupancy monitoring - real-time occupancy tracking, live API |

### Attendance & Time Tracking

| File | Purpose |
|------|---------|
| `attendance.php` | Attendance functions - check-in/check-out, proof upload, attendance history |

### Audit & Security

| File | Purpose |
|------|---------|
| `audit.php` | Audit logging - log user actions, module tracking, IP/user agent logging |
| `captcha.php` | Captcha functions - Turnstile integration, verification |
| `violation.php` | Violation tracking - user violations, severity levels, violation history |

### UI Helpers

| File | Purpose |
|------|---------|
| `ui_helpers.php` | UI helper functions - display names, formatting, field tips, heading with tips |
| `time_helpers.php` | Time/date helpers - timezone conversion, formatting, booking time validation |
| `extension_helpers.php` | File extension helpers - allowed file types, MIME validation |

### Other Configuration

| File | Purpose |
|------|---------|
| `announcement_categories.php` | Announcement category management - CRUD operations, category hierarchy |
| `culiat_streets.php` | Culiat streets data - address autocomplete, street list for Barangay Culiat |

## database/

### Schema & Migrations

| File | Purpose |
|------|---------|
| `schema.sql` | Base database schema - initial table creation, indexes, foreign keys |
| `migration_add_payment_module.sql` | Add payment module - payments table, reservation status expansion |
| `migration_add_user_documents.sql` | Add user documents - user_documents table, mobile column |
| `migration_add_reservation_documents.sql` | Add reservation documents - reservation_documents table |
| `migration_add_operating_hours.sql` | Add operating hours - operating_hours column to facilities |
| `migration_add_system_lookups.sql` | Add system lookups - lookup_categories, lookup_values tables |
| `migration_add_facility_free_flag.sql` | Add facility free flag - is_free column to facilities |
| `migration_add_reservation_attendance.sql` | Add reservation attendance - reservation_attendance table |
| `migration_add_contact_inquiries.sql` | Add contact inquiries - contact_inquiries, password_reset_tokens tables |
| `performance_indexes.sql` | Performance indexes - additional indexes for query optimization |

## resources/views/

### layouts/

| File | Purpose |
|------|---------|
| `dashboard_layout.php` | Dashboard layout - sidebar, navbar, content area, AI chatbot, session timeout modal |
| `guest_layout.php` | Public/guest layout - public navigation, footer, content area |

### components/

| File | Purpose |
|------|---------|
| `sidebar_dashboard.php` | Dashboard sidebar - role-based navigation, collapsible sections, user profile |
| `navbar_dashboard.php` | Dashboard navbar - user info, notifications, logout, mobile menu |
| `navbar_guest.php` | Public navbar - navigation links, login/register buttons |
| `footer.php` | Footer - copyright, quick links, contact information |
| `occupancy_board.php` | Occupancy board component - real-time facility occupancy display |
| `occupancy_dashboard_strip.php` | Occupancy strip component - compact occupancy display for dashboard |

### pages/auth/

| File | Purpose |
|------|---------|
| `login.php` | Login page - email/password form, captcha, 2FA redirect |
| `login_otp.php` | OTP verification page - email OTP or TOTP code entry |
| `login_setup_2fa.php` | 2FA setup page - TOTP QR code, email OTP setup |
| `register.php` | Registration page - user signup form, email verification |
| `verify_email.php` | Email verification page - enter verification code |
| `forgot_password.php` | Forgot password page - request password reset |
| `reset_password.php` | Reset password page - enter new password with token |
| `logout.php` | Logout handler - destroy session, redirect to login |

### pages/dashboard/

#### Core Pages

| File | Purpose |
|------|---------|
| `index.php` | Dashboard home - statistics, quick actions, recent activity |
| `profile.php` | User profile - edit profile, change password, 2FA settings |

#### Booking & Reservations

| File | Purpose |
|------|---------|
| `book_facility.php` | Book facility - create reservations, view own reservations, manage bookings |
| `reservations_manage.php` | Reservation approvals - approve/deny reservations, view all reservations |
| `calendar.php` | Calendar view - reservation calendar, availability display |
| `calendar_export_ics.php` | ICS export - export calendar to iCal format |
| `time_tracking.php` | Time tracking - check-in/check-out, attendance management |
| `check_in_gate.php` | Check-in gate - QR code scanning for check-in |

#### Facility Management

| File | Purpose |
|------|---------|
| `facility_management.php` | Facility CRUD - add/edit/delete facilities, images, operating hours |
| `blackout_dates.php` | Blackout dates - manage facility unavailability dates |

#### User Management

| File | Purpose |
|------|---------|
| `user_management.php` | User CRUD - add/edit/delete users, role assignment, verification |
| `document_management.php` | Document management - manage user documents, verification |

#### AI Tools

| File | Purpose |
|------|---------|
| `ai_scheduling.php` | Smart scheduler - AI-powered scheduling recommendations |
| `ai_chatbot.php` | AI chatbot - chatbot API endpoint for dashboard widget |
| `ai_recommendations_api.php` | AI recommendations API - facility recommendations endpoint |
| `ai_conflict_check.php` | AI conflict check - conflict detection API endpoint |
| `booking_smart_hints_api.php` | Smart hints API - booking suggestions endpoint |

#### Communications

| File | Purpose |
|------|---------|
| `announcements_manage.php` | Announcements - create/manage announcements |
| `contact.php` | Contact management - manage contact information |
| `contact_inquiries.php` | Contact inquiries - manage public contact form submissions |
| `contact_info_manage.php` | Contact info - edit organization contact details |

#### Reports & Analytics

| File | Purpose |
|------|---------|
| `reports.php` | Reports - reservation statistics, facility utilization, user activity |
| `occupancy_monitor.php` | Occupancy monitor - real-time occupancy tracking dashboard |
| `download_export.php` | Download export - generate and download report exports |

#### Operations & Integrations

| File | Purpose |
|------|---------|
| `maintenance_integration.php` | Maintenance integration - CIMM sync, maintenance management |
| `infrastructure_projects.php` | Infrastructure projects - infrastructure project management |
| `utilities_integration.php` | Utilities integration - utilities management |

#### Administration

| File | Purpose |
|------|---------|
| `system_settings.php` | System settings - permissions, lookups, system configuration |
| `audit_trail.php` | Audit trail - view system audit log |
| `audit_trail_pdf.php` | Audit trail PDF - export audit log to PDF |
| `notifications.php` | Notifications - view user notifications, mark as read |
| `notifications_api.php` | Notifications API - fetch notifications endpoint |
| `pay_now.php` | Pay now - payment page for reservations |
| `session_keepalive.php` | Session keepalive - extend session via AJAX |
| `geocode_api.php` | Geocode API - address to coordinates endpoint |

#### API Endpoints

| File | Purpose |
|------|---------|
| `api/availability_api.php` | Availability API - get facility availability for calendar |
| `chatbot_api.php` | Chatbot API - legacy chatbot endpoint |

#### Includes

| File | Purpose |
|------|---------|
| `includes/reservations_mine_post_handlers.php` | Reservation post handlers - edit, reschedule, cancel actions |

### pages/public/

| File | Purpose |
|------|---------|
| `home.php` | Home page - public landing page, facility overview |
| `facilities.php` | Facilities listing - public facility catalog |
| `facility_details.php` | Facility details - individual facility information |
| `announcements.php` | Public announcements - view system announcements |
| `faq.php` | FAQ - frequently asked questions |
| `contact.php` | Contact page - contact form, organization info |
| `contact_handler.php` | Contact handler - process contact form submissions |
| `legal.php` | Legal page - legal information |
| `privacy.php` | Privacy policy - privacy policy page |
| `terms.php` | Terms of service - terms and conditions |
| `payment_return.php` | Payment return - PayMongo return handler |

### pages/public/api/

| File | Purpose |
|------|---------|
| `availability.php` | Public availability API - get facility availability (no auth) |
| `paymongo_webhook.php` | PayMongo webhook - payment status updates |
| `integrations_not_implemented.php` | Placeholder - integrations not yet implemented |

## public/

### css/

| File | Purpose |
|------|---------|
| `style.css` | Main stylesheet - global styles, base CSS |
| `tailwind.css` | TailwindCSS output - compiled TailwindCSS utilities |
| `tailwind-input.css` | TailwindCSS input styles - form input styling |
| `public-pages.css` | Public pages styles - public page specific styles |
| `dashboard-pages.css` | Dashboard pages styles - dashboard specific styles |
| `dark-mode-public.css` | Dark mode styles - public dark mode theme |
| `home.css` | Home page styles - homepage specific styles |

### js/

| File | Purpose |
|------|---------|
| `main.js` | Main JavaScript - global functionality, event listeners |
| `dashboard-navigation.js` | Dashboard navigation - SPA-like navigation, progress indicator |
| `dashboard-charts.js` | Dashboard charts - Chart.js initialization, chart rendering |
| `chart-filters.js` | Chart filters - filter controls for dashboard charts |
| `frs-form-validation.js` | Form validation - field validation, error highlighting |
| `frs-animations.js` | Animations - GSAP animations, page transitions |
| `frs-toast.js` | Toast notifications - notification display system |
| `frs-field-tips.js` | Field tips - contextual field help, tooltips |
| `public-navigation.js` | Public navigation - public page navigation logic |
| `home-animations.js` | Home animations - homepage specific animations |
| `occupancy-board.js` | Occupancy board - occupancy display logic |
| `occupancy-dashboard-strip.js` | Occupancy strip - compact occupancy display logic |

### img/

**Purpose**: Static images (logos, icons, placeholder images)

**Contents**:
- `infragov-logo.png` - Application logo
- Facility images (stored in uploads/facilities/)

### uploads/

**Purpose**: User-uploaded files

**Subdirectories**:
- `profile_pictures/` - User profile pictures
- `facilities/` - Facility images
- `documents/` - User documents
- `secure/` - Secure document storage (protected)

## scripts/

### Maintenance Scripts

| File | Purpose |
|------|---------|
| `auto_decline_expired.php` | Auto decline expired - decline stale reservations |
| `send_booking_reminders.php` | Send reminders - send booking reminder emails/SMS |
| `process_expired_reservations.php` | Process expired - clean up expired reservations |
| `archive_documents.php` | Archive documents - archive old user documents |
| `cleanup_old_data.php` | Cleanup old data - remove old audit logs, rate limits |
| `optimize_database.php` | Optimize database - run OPTIMIZE TABLE on all tables |

### Integration Scripts

| File | Purpose |
|------|---------|
| `sync_cimm_maintenance.php` | Sync CIMM maintenance - sync maintenance dates from CIMM |
| `sync_reservation_payment.php` | Sync reservation payment - sync payment status from PayMongo |
| `process_operational_occupancy.php` | Process occupancy - process operational occupancy data |
| `run_operational_occupancy_migration.php` | Run occupancy migration - migration for occupancy tracking |

### Utility Scripts

| File | Purpose |
|------|---------|
| `attendance_reminders.php` | Attendance reminders - send check-in reminders |
| `migrate_documents_to_secure_storage.php` | Migrate documents - move documents to secure storage |
| `check_database_indexes.php` | Check indexes - verify database indexes exist |
| `check_env_token_bytes.php` | Check env tokens - verify token byte length |
| `captcha_env_check.php` | Captcha env check - verify captcha environment variables |
| `iprog_verify_token.php` | Verify IPROG token - test IPROG SMS token |
| `philsms_verify_token.php` | Verify PhilSMS token - test PhilSMS token |
| `philsms_diag.php` | PhilSMS diagnostics - diagnose PhilSMS connection |
| `test_philsms.php` | Test PhilSMS - send test SMS via PhilSMS |
| `verify_operational_occupancy_schema.php` | Verify occupancy schema - verify occupancy table structure |

## ai/

### api/

**Purpose**: AI API endpoints for Python integration

**Contents**:
- API endpoints for ML model inference
- (Specific files not analyzed - directory structure inferred)

### scripts/

**Purpose**: Python scripts for ML model training and utilities

**Contents**:
- `train_models.py` - Train ML models
- Utility scripts for data processing
- (Specific files not analyzed - directory structure inferred)

### src/

**Purpose**: ML model source code

**Contents**:
- Model definitions
- Feature extraction
- (Specific files not analyzed - directory structure inferred)

### requirements.txt

**Purpose**: Python dependencies for AI/ML module

## services/

**Purpose**: External service integration clients

**Note**: Directory exists but specific files not analyzed in this documentation generation

## storage/

**Purpose**: Application storage for logs, cache, temporary files

**Subdirectories**:
- `logs/` - Application logs
- `cache/` - Cache storage
- `temp/` - Temporary files

## tests/

**Purpose**: PHPUnit test cases

**Note**: Directory exists but specific test files not analyzed in this documentation generation

## vendor/

**Purpose**: Composer dependencies (PHPMailer, TwoFactorAuth, PHPUnit)

**Note**: Managed by Composer, not manually edited

## .env.example

**Purpose**: Environment variable template with all possible configuration keys

## .gitignore

**Purpose**: Git ignore patterns for sensitive files and generated content

**Typical ignored items**:
- `.env` - Environment configuration
- `config/database.php` - Database credentials
- `public/uploads/` - User uploads
- `storage/` - Application storage
- `vendor/` - Composer dependencies
- `node_modules/` - Node.js dependencies
