# Changelog Baseline

This document serves as a baseline changelog for the Facilities Reservation System. It documents the current state of features and known issues as of the documentation generation date. Future changes should be added to this file following the format below.

## [Unreleased]

### Added
- Comprehensive documentation suite (AI_CONTEXT.md, ARCHITECTURE.md, DATABASE.md, CODE_INDEX.md, API_REFERENCE.md, AI_RULES.md)

### Changed
- None

### Deprecated
- None

### Removed
- None

### Fixed
- None

### Security
- None

## [Current Version] - 2024-01-XX

### Added

#### Core Features
- User authentication with email/password
- Email verification for new accounts
- Two-factor authentication (Email OTP and TOTP/Google Authenticator)
- Role-based access control (Admin, Staff, Resident)
- Facility browsing and details
- Reservation booking system
- Auto-approval rules for reservations
- Reservation management (approve, deny, reschedule, cancel)
- My Reservations dashboard for users
- Check-in/check-out attendance tracking
- Document upload for user verification
- Document upload for reservation support
- Secure document storage system
- Audit trail logging
- In-app notifications
- Email notifications (booking confirmations, reminders)
- SMS notifications (booking confirmations, reminders, OTP)
- Contact form for public inquiries
- Contact inquiry management for staff
- Announcements system
- User management (CRUD)
- Facility management (CRUD)
- Blackout date management
- Reports and analytics
- Occupancy monitoring dashboard
- Calendar view with availability
- ICS calendar export
- Data export (CSV/Excel)
- Profile management
- Password reset functionality
- Session timeout with keep-alive
- CSRF protection
- Rate limiting for sensitive operations
- Security headers
- File upload validation
- Dark mode support
- Responsive design
- Mobile-friendly navigation

#### AI/ML Features
- AI-powered chatbot (Gemini API)
- Smart scheduling recommendations
- AI conflict detection
- Purpose analysis (unclear purpose detection)
- Facility recommendations
- Booking smart hints
- Rule-based chatbot fallback

#### Payment Features
- PayMongo payment integration
- Payment gateway checkout
- Payment status tracking
- Payment webhooks
- Payment hold system (pending_payment status)
- Payment due date tracking

#### Integration Features
- CIMM maintenance sync
- Blackout date sync from external systems
- Geocoding (Mapbox/Google Maps)
- SMS integration (IPROG, PhilSMS, email-to-SMS)
- Email integration (PHPMailer)

#### Dashboard Features
- AJAX navigation with fade transitions
- Progress indicator
- Active state highlighting
- Session timeout modal
- Confirmation modal
- Toast notifications
- AI chatbot widget
- Real-time occupancy board
- Compact occupancy strip

#### Public Pages
- Home page
- Facilities listing
- Facility details
- Announcements
- FAQ
- Contact page
- Legal page
- Privacy policy
- Terms of service
- Login page
- Registration page
- Forgot password
- Email verification

### Changed

#### Database Schema
- Added `is_free` column to `facilities` table (migration_add_facility_free_flag.sql)
- Added `operating_hours` column to `facilities` table (migration_add_operating_hours.sql)
- Changed `facilities.status` from ENUM to VARCHAR(64) (migration_add_system_lookups.sql)
- Added `mobile` column to `users` table (migration_add_user_documents.sql)
- Added `pending_payment` status to `reservations` and `reservation_history` (migration_add_payment_module.sql)
- Added `priority_level`, `expires_at`, `payment_due_at` columns to `reservations` (migration_add_payment_module.sql)
- Added `is_archived` column to `user_documents` (inferred from usage)

### Deprecated

- None

### Removed

- None

### Fixed

- None documented (this is a baseline)

### Security

- CSRF token implementation with 1-hour expiry
- Rate limiting for login attempts (5 per 15 minutes per email)
- Rate limiting for registration (5 per hour per IP)
- Rate limiting for email verification (3 per hour per email)
- Rate limiting for Gemini chatbot (10 per minute per user)
- Account lock after 5 failed login attempts (30-minute lock)
- Session timeout after 5 minutes of inactivity
- Secure session configuration (HttpOnly, Secure, SameSite=Lax cookies)
- Password strength requirements (min 8 chars, uppercase, lowercase, number)
- File upload validation (MIME type, size limits)
- Secure document storage with path validation
- SQL injection prevention via PDO prepared statements
- XSS prevention via output escaping
- Security headers (X-Frame-Options, X-XSS-Protection, X-Content-Type-Options, Referrer-Policy, CSP, Permissions-Policy)
- HTTPS enforcement (except for localhost/lgu.test)

## Known Issues

### Technical Issues

1. **Session-based authentication only**
   - No JWT or token-based auth for APIs
   - Not suitable for mobile app integration without modification

2. **File-based sessions**
   - PHP default session handler uses files
   - Not suitable for horizontal scaling
   - Consider Redis or database session storage for scaling

3. **No queue system**
   - Email and SMS sent synchronously
   - Can slow down request handling
   - Consider implementing job queue (e.g., Redis, RabbitMQ)

4. **No caching layer**
   - Database queried on every request
   - No result caching for frequently accessed data
   - Consider implementing Redis or Memcached

5. **Limited test coverage**
   - Minimal PHPUnit test coverage
   - No integration tests
   - Consider expanding test suite

6. **No API versioning**
   - API endpoints not versioned
   - Breaking changes will affect all clients
   - Consider implementing `/api/v1/`, `/api/v2/` pattern

### Business Logic Issues

1. **Single LGU scope**
   - Designed specifically for Barangay Culiat
   - Not multi-tenant
   - Would require significant changes for multi-LGU deployment

2. **Philippines-specific features**
   - Address formats optimized for Philippines
   - SMS providers are Philippines-specific (IPROG, PhilSMS)
   - Payment gateway is Philippines-specific (PayMongo)
   - Not suitable for international use without modification

3. **Hardcoded timezone**
   - Timezone hardcoded to Asia/Manila
   - Not configurable per user
   - Consider user-specific timezone settings

4. **English only**
   - No internationalization (i18n)
   - No localization (l10n)
   - All text is in English

### Performance Issues

1. **No database connection pooling**
   - New PDO connection per request
   - Can be inefficient under high load
   - Consider connection pooling or persistent connections

2. **No CDN for assets**
   - All assets served from application server
   - No geographic distribution
   - Consider using CDN for static assets

3. **No image optimization**
   - Images stored at original size
   - No thumbnails or responsive images
   - Consider image processing library (e.g., Intervention Image)

4. **Synchronous email/SMS**
   - Email and SMS sent during request
   - Can cause slow response times
   - Consider moving to background jobs

### Security Considerations

1. **CSRF token expiry**
   - Tokens expire after 1 hour
   - May affect long forms
   - Consider extending expiry or refresh mechanism

2. **Rate limiting**
   - Database-based rate limiting
   - Can be bypassed with multiple IPs
   - Consider IP-based or more sophisticated rate limiting

3. **File upload storage**
   - Files stored in public directory
   - Relies on .htaccess for protection
   - Consider storing outside web root with serve script

4. **Session timeout**
   - 5-minute timeout may be too short
   - Can frustrate users filling long forms
   - Consider configurable timeout or warning

5. **2FA requirement**
   - 2FA required for Admin/Staff
   - May cause friction for new users
   - Consider optional 2FA with grace period

### UI/UX Issues

1. **No offline support**
   - Application requires internet connection
   - No service worker or offline caching
   - Consider PWA implementation

2. **No progressive enhancement**
   - JavaScript required for full functionality
   - May not work with JS disabled
   - Consider graceful degradation

3. **Limited accessibility**
   - Some components may not be fully accessible
   - Consider WCAG compliance audit

## Migration Notes

### Database Migrations

Migrations should be run in the following order:

1. `schema.sql` - Base schema
2. `migration_add_payment_module.sql` - Add payment functionality
3. `migration_add_user_documents.sql` - Add user document upload
4. `migration_add_reservation_documents.sql` - Add reservation document upload
5. `migration_add_operating_hours.sql` - Add operating hours
6. `migration_add_system_lookups.sql` - Add lookup system
7. `migration_add_facility_free_flag.sql` - Add free facility flag
8. `migration_add_reservation_attendance.sql` - Add attendance tracking
9. `migration_add_contact_inquiries.sql` - Add contact inquiries and password reset
10. `performance_indexes.sql` - Add performance indexes

### Running Migrations

Use the provided migration runner:

```bash
php run_migrations.php
```

Or manually execute SQL files in phpMyAdmin or MySQL client.

### Configuration Migration

Environment variables have been added over time. Check `.env.example` for the latest required variables:

- Database credentials (DB_HOST, DB_NAME, DB_USER, DB_PASS)
- Email configuration (MAIL_*)
- SMS configuration (SMS_ENABLED, SMS_DRIVER, IPROG_*, PHILSMS_*)
- Payment configuration (PAYMENTS_ENABLED, PAYMONGO_*)
- AI configuration (GEMINI_API_KEY)
- Captcha configuration (CAPTCHA_ENABLED, TURNSTILE_*)
- Geocoding configuration (MAPBOX_TOKEN, GOOGLE_MAPS_KEY)

## Upgrade Notes

### From Earlier Versions

If upgrading from an earlier version without these features:

1. **Run all migrations** in order
2. **Update .env file** with new environment variables
3. **Run composer install** to ensure dependencies are up to date
4. **Clear browser cache** for CSS/JS changes
5. **Test payment flow** if enabling PayMongo
6. **Test AI features** if enabling Gemini API
7. **Configure SMS provider** if enabling SMS notifications

### Breaking Changes

No breaking changes documented in this baseline version.

## Dependencies

### PHP Dependencies (composer.json)

- **phpmailer/phpmailer** ^7.0 - Email sending
- **robthree/twofactorauth** ^3.0 - TOTP (Google Authenticator)
- **phpunit/phpunit** ^9.5 (dev) - Testing

### JavaScript Dependencies (CDN)

- **TailwindCSS** - CSS framework
- **Chart.js** ^4.4.0 - Data visualization
- **Leaflet** ^1.9.4 - Maps
- **GSAP** ^3.12.5 - Animations
- **Bootstrap Icons** ^1.5.0 - Iconography

### Python Dependencies (ai/requirements.txt)

- **Gemini API** - AI chatbot
- **scikit-learn** - ML models
- **pandas** - Data processing
- **numpy** - Numerical computing

(Note: Specific Python dependencies not analyzed - above are typical for this type of application)

## System Requirements

### Server Requirements

- **PHP**: 8.1 or higher
- **MySQL**: 8.0+ or MariaDB 10.4+
- **Apache**: 2.4+ with mod_rewrite enabled
- **Composer**: For PHP dependency management

### Optional Requirements

- **Python**: 3.10+ (for AI/ML features)
- **Node.js**: For TailwindCSS build (optional, CDN used in production)
- **Redis**: Recommended for session storage and caching (not currently used)

### Client Requirements

- Modern web browser (Chrome, Firefox, Safari, Edge)
- JavaScript enabled
- Internet connection

## Support

For issues, questions, or contributions:

1. Check this documentation first
2. Review the code comments in relevant files
3. Check the README.md for quick start guide
4. Contact the development team (contact information in system settings)

## License

[License information should be added here - not found in analyzed files]

## Contributors

[Contributor list should be added here - not found in analyzed files]

## Changelog Format

### Adding New Entries

When adding new entries to this changelog, follow this format:

```markdown
## [Version] - YYYY-MM-DD

### Added
- Feature description

### Changed
- Change description

### Deprecated
- Deprecated feature description

### Removed
- Removed feature description

### Fixed
- Bug fix description

### Security
- Security fix description
```

### Version Numbering

Consider using semantic versioning (SemVer):
- **MAJOR**: Incompatible API changes
- **MINOR**: Backwards-compatible functionality additions
- **PATCH**: Backwards-compatible bug fixes

Example: `1.0.0`, `1.1.0`, `1.1.1`
