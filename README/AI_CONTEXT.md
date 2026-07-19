# AI Context: Facilities Reservation System

## Project Overview

**Barangay Culiat Facilities Reservation System** is a web-based LGU facility booking platform for Barangay Culiat, Quezon City, Philippines. The system enables residents to register, browse facilities, make reservations, and manage bookings through an AI-assisted interface. Admin and staff users can approve/deny reservations, manage facilities, track occupancy, and handle communications.

**Primary Purpose**: Streamline facility reservations for LGU (Local Government Unit) operations with automated workflows, AI-powered scheduling assistance, and comprehensive management tools.

**Target Users**:
- Residents: Browse facilities, make bookings, manage reservations
- Staff: Approve/deny reservations, manage facilities, handle inquiries
- Admin: Full system administration, user management, settings

## Tech Stack

### Backend
- **PHP 8.1+** (Core application logic)
- **MySQL 8.0+ / MariaDB 10.4+** (Database)
- **Composer** (Dependency management)

### Frontend
- **Vanilla JavaScript** (No frameworks)
- **TailwindCSS** (via CDN for styling)
- **Chart.js** (Data visualization)
- **Leaflet** (Maps)
- **GSAP** (Animations)
- **Bootstrap Icons** (Iconography)

### External Libraries (Composer)
- **PHPMailer 7.0+** (Email sending)
- **RobThree/TwoFactorAuth 3.0+** (TOTP 2FA)

### AI/ML (Optional)
- **Python 3.10+** (ML models in `ai/` directory)
- **Gemini API** (Chatbot, purpose analysis, conflict detection)
- **Custom ML models** (Facility recommendations, scheduling optimization)

### Payment Integration
- **PayMongo** (Payment gateway for reservations)

### SMS Integration
- **IPROG SMS** (SMS notifications)
- **Email-to-SMS gateway** (Alternative SMS delivery)

## Overall Architecture

### Pattern: Custom MVC-lite with Front Controller

The application uses a custom routing system with `index.php` as the front controller. There is no traditional MVC framework; instead, it uses:

- **Front Controller**: `index.php` routes all requests
- **Config Directory**: Shared helpers and business logic (`config/*.php`)
- **Views Directory**: Page templates (`resources/views/`)
- **Layouts**: Reusable page structures (`resources/views/layouts/`)
- **Components**: Reusable UI parts (`resources/views/components/`)

### Request Flow

1. **Request** → `index.php`
2. **Route Parsing** → Extract path from `REQUEST_URI`
3. **API Routes** → Handle before app loading (availability API, webhooks)
4. **App Loading** → Load `config/app.php` (security, session, env)
5. **Authentication Check** → Dashboard routes require session
6. **Route Mapping** → Map clean URLs to view files
7. **View Rendering** → Include layout + page content
8. **Response** → HTML or JSON

### Key Architectural Decisions

- **Session-based Authentication**: PHP sessions with secure configuration
- **Role-based Access Control**: Granular permissions per module
- **Configuration-driven**: Environment variables in `.env`
- **Migration-based Schema**: SQL migrations for database changes
- **Helper Functions**: Reusable logic in `config/` directory
- **No ORM**: Direct PDO queries with prepared statements
- **AJAX Navigation**: Dashboard uses fetch-based SPA-like navigation

## Folder Structure

```
facilities-reservation-system1/
├── ai/                          # Python ML models and AI integration
│   ├── api/                     # AI API endpoints
│   ├── scripts/                 # Training/utility scripts
│   ├── src/                     # ML model source code
│   └── requirements.txt         # Python dependencies
├── config/                      # Application configuration and helpers
│   ├── app.php                  # Core app helpers (URL, paths, env)
│   ├── database.php            # Database connection (gitignored)
│   ├── security.php            # Security headers, CSRF, rate limiting
│   ├── permissions.php          # RBAC system
│   ├── lookups.php             # Configurable lookup values
│   ├── [module]_helpers.php    # Module-specific helpers
│   └── [module].php            # Module configuration
├── database/                    # Database schema and migrations
│   ├── schema.sql              # Base schema
│   ├── migration_*.sql         # Incremental migrations
│   └── performance_indexes.sql # Performance optimization
├── docs/                       # Project documentation
├── public/                     # Publicly accessible assets
│   ├── css/                    # Stylesheets
│   ├── js/                     # JavaScript files
│   ├── img/                    # Images
│   └── uploads/                # User uploads (profile pics, docs)
├── resources/views/
│   ├── components/             # Reusable UI components
│   ├── layouts/                # Page layouts
│   ├── pages/
│   │   ├── auth/              # Authentication pages
│   │   ├── dashboard/         # Dashboard pages
│   │   └── public/            # Public pages
│   └── pages/dashboard/includes/ # Dashboard sub-views
├── routes/                     # Route definitions (minimal usage)
├── scripts/                    # Cron jobs and maintenance scripts
├── services/                   # External service integrations
├── storage/                    # Application storage (logs, cache)
├── tests/                      # PHPUnit tests
├── vendor/                     # Composer dependencies
├── .env                        # Environment configuration (gitignored)
├── .env.example               # Environment template
├── composer.json              # PHP dependencies
├── index.php                  # Front controller
├── run_migrations.php         # Migration runner
└── .htaccess                  # Apache configuration
```

## Request Lifecycle

### Public Page Request (e.g., `/facilities`)

1. `index.php` receives request
2. Path extracted: `facilities`
3. Route matched to `resources/views/pages/public/facilities.php`
4. `config/app.php` loaded (security, session, env)
5. View rendered with `guest_layout.php`
6. HTML response sent

### Dashboard Page Request (e.g., `/dashboard/book-facility`)

1. `index.php` receives request
2. Path extracted: `dashboard/book-facility`
3. Authentication check via `$_SESSION['user_authenticated']`
4. If unauthenticated → redirect to `/login`
5. Route mapped to `resources/views/pages/dashboard/book_facility.php`
6. `config/app.php` loaded
7. View rendered with `dashboard_layout.php`
8. HTML response sent

### AJAX Dashboard Navigation

1. User clicks sidebar link
2. `dashboard-navigation.js` intercepts click
3. Fetch request with `X-Requested-With: FRS-Dashboard-Nav`
4. Server returns only `<section class="dashboard-content">` HTML
5. Client replaces content with fade animation
6. History updated with `pushState`

### API Request (e.g., `/api/public/availability`)

1. `index.php` detects API route pattern
2. Loads API handler directly (no app.php)
3. Returns JSON response
4. No session required for public APIs

## Authentication Flow

### Registration Flow

1. User accesses `/register`
2. Submits form with email, password, name
3. **Rate limiting**: 5 registrations per IP per hour
4. **Password validation**: Min 8 chars, uppercase, lowercase, number
5. **Account created** with status `pending`
6. **Email verification code** sent (6-digit, 15-min expiry)
7. User redirected to `/verify-email`
8. User enters code → account status changes to `active`
9. **Unverified retention**: Accounts deleted after 24 hours if not verified

### Login Flow

1. User accesses `/login`
2. Submits email and password
3. **Rate limiting**: 5 failed attempts per email per 15 minutes
4. **Account lock**: 5 failed attempts → 30-minute lock
5. **Password verification** via `password_verify()`
6. **Email verification check**: If not verified → redirect to verify flow
7. **Second factor check** (for Admin/Staff):
   - Email OTP enabled → Send 6-digit code (1-min expiry)
   - TOTP enabled → Require authenticator code
   - Neither enabled → Redirect to 2FA setup (required for Admin/Staff)
8. **Session creation**: `frs_complete_authenticated_login()`
9. **Redirect**: To dashboard or `post_login_redirect` target

### Session Management

- **Session timeout**: 5 minutes (configurable via `SESSION_TIMEOUT`)
- **Session regeneration**: Every 5 minutes
- **Secure cookies**: HttpOnly, Secure (HTTPS), SameSite=Lax
- **Session storage**: PHP default (file-based)
- **Session keys**:
  - `user_authenticated`: Boolean flag
  - `user_id`: User ID
  - `user_name`: User display name
  - `user_email`: User email
  - `role`: User role (Admin/Staff/Resident)
  - `last_activity`: Timestamp for timeout

### Two-Factor Authentication (2FA)

- **Email OTP**: 6-digit code, 1-minute expiry, sent via email/SMS
- **TOTP**: Google Authenticator compatible (RobThree/TwoFactorAuth)
- **Required for**: Admin and Staff roles
- **Optional for**: Residents
- **Setup flow**: `/login-setup-2fa` after password authentication

## Authorization / RBAC

### Role-Based Permission System

**Roles**: Admin, Staff, Resident

**Permission Keys** (modules):
- `users` - User management
- `facilities` - Facility management
- `reservations` - Reservation management
- `reports` - Reports and analytics
- `settings` - System settings
- `announcements` - Announcements
- `blackout_dates` - Blackout date management
- `audit_trail` - Audit log access
- `communications` - Contact management
- `maintenance` - Maintenance integration
- `infrastructure` - Infrastructure projects
- `utilities` - Utilities integration
- `ai_tools` - AI tools access
- `documents` - Document management

**Actions per module**: `create`, `read`, `update`, `delete`

### Permission Checks

```php
// Check if role has permission
frs_has_permission('Admin', 'reservations', 'create') // true

// Helper functions
frs_can_create($role, 'reservations')
frs_can_read($role, 'reservations')
frs_can_update($role, 'reservations')
frs_can_delete($role, 'reservations')
```

### Default Permissions

**Admin**: Full access to all modules (all CRUD operations)

**Staff**:
- `users`: create, read, update (no delete)
- `facilities`: create, read, update (no delete)
- `reservations`: create, read, update (no delete)
- `reports`: read only
- `settings`: none
- `announcements`: create, read, update (no delete)
- `blackout_dates`: create, read, update (no delete)
- `audit_trail`: read only
- `communications`: create, read, update (no delete)
- `maintenance`: read, update
- `infrastructure`: read only
- `utilities`: read only
- `ai_tools`: read only
- `documents`: none

**Resident**:
- `users`: none
- `facilities`: read only
- `reservations`: create, read, update (no delete)
- `reports`: none
- `settings`: none
- `announcements`: read only
- `blackout_dates`: read only
- `audit_trail`: none
- `communications`: none
- `maintenance`: none
- `infrastructure`: none
- `utilities`: none
- `ai_tools`: read only
- `documents`: none

### Permission Storage

- **Database table**: `role_permissions`
- **Fallback**: Hardcoded defaults in `config/permissions.php`
- **Caching**: In-memory cache during request lifetime

## Main Modules

 ### 1. Authentication & Authorization
- **Files**: `resources/views/pages/auth/*.php`, `config/security.php`
- **Features**: Login, register, email verification, password reset, 2FA, rate limiting
- **Helpers**: `frs_complete_authenticated_login()`, authentication check via `$_SESSION['user_authenticated']`

### 2. Facility Management
- **Files**: `resources/views/pages/dashboard/facility_management.php`, `config/upload_helper.php`
- **Features**: CRUD facilities, images, operating hours, capacity, amenities, auto-approval rules
- **Status**: available, maintenance, offline (configurable via lookups)

### 3. Reservation Management
- **Files**: `resources/views/pages/dashboard/book_facility.php`, `config/reservation_helpers.php`
- **Features**: Create bookings, view reservations, approve/deny, reschedule, cancel, attendance tracking
- **Status**: pending_payment, pending, approved, denied, cancelled, on_hold, postponed
- **Auto-approval**: Configurable rules based on duration, attendees, commercial use

### 4. AI-Powered Tools
- **Files**: `resources/views/pages/dashboard/ai_*.php`, `config/ai_helpers.php`, `config/ai_ml_integration.php`
- **Features**: 
  - Smart scheduling (AI recommendations)
  - Conflict detection (ML-based)
  - Purpose analysis (unclear purpose detection)
  - Chatbot (Gemini API with rule-based fallback)
  - Facility recommendations

### 5. User Management
- **Files**: `resources/views/pages/dashboard/user_management.php`, `config/user_admin.php`
- **Features**: CRUD users, role assignment, verification, deactivation, document verification

### 6. Reports & Analytics
- **Files**: `resources/views/pages/dashboard/reports.php`, `config/analytics_chart_filters.php`
- **Features**: Reservation statistics, facility utilization, user activity, AI summaries

### 7. Occupancy Monitoring
- **Files**: `resources/views/pages/dashboard/occupancy_monitor.php`, `config/occupancy_monitoring.php`
- **Features**: Real-time occupancy tracking, live API, historical data

### 8. Communications
- **Files**: `resources/views/pages/dashboard/contact*.php`, `config/mail_helper.php`, `config/sms_helper.php`
- **Features**: Contact inquiries, announcements, email notifications, SMS notifications

### 9. Maintenance Integration
- **Files**: `resources/views/pages/dashboard/maintenance_integration.php`, `config/maintenance_helper.php`
- **Features**: CIMM integration, maintenance sync, blackout dates

### 10. Document Management
- **Files**: `resources/views/pages/dashboard/document_management.php`, `config/secure_documents.php`
- **Features**: Secure document storage, archival, user documents, reservation documents

### 11. Audit Trail
- **Files**: `resources/views/pages/dashboard/audit_trail.php`, `config/audit.php`
- **Features**: Action logging, module tracking, IP/user agent logging

### 12. System Settings
- **Files**: `resources/views/pages/dashboard/system_settings.php`
- **Features**: Permission management, lookup configuration, system configuration

## External Libraries

### PHP Libraries (Composer)

**PHPMailer 7.0+**
- Purpose: Email sending
- Configuration: `config/mail.php`, `config/mail_helper.php`
- Usage: `sendEmail($to, $name, $subject, $body)`

**RobThree/TwoFactorAuth 3.0+**
- Purpose: TOTP (Google Authenticator) implementation
- Configuration: `config/security.php`
- Usage: `frs_user_totp_active()`, TOTP verification

### JavaScript Libraries (CDN)

**TailwindCSS**
- Purpose: Utility-first CSS framework
- Usage: Via CDN in layouts
- Configuration: `tailwind.config.js`

**Chart.js 4.4.0**
- Purpose: Data visualization
- Usage: Dashboard charts, reports
- Configuration: `public/js/dashboard-charts.js`

**Leaflet 1.9.4**
- Purpose: Interactive maps
- Usage: Facility location display
- Configuration: `config/geocoding.php`

**GSAP 3.12.5**
- Purpose: Animations
- Usage: Page transitions, UI animations
- Configuration: `public/js/frs-animations.js`

**Bootstrap Icons 1.5.0**
- Purpose: Iconography
- Usage: Throughout UI

### Python Libraries (AI)

**Gemini API**
- Purpose: AI chatbot, text analysis
- Configuration: `GEMINI_API_KEY` in `.env`
- Usage: `config/gemini_chatbot.php`, `config/ai_helpers.php`

**Custom ML Models**
- Purpose: Facility recommendations, conflict detection
- Location: `ai/src/`
- Training: `ai/scripts/train_models.py`

## Coding Conventions

### PHP

**Naming Conventions**
- Functions: `snake_case` (e.g., `frs_has_permission`)
- Variables: `snake_case` (e.g., `$user_id`)
- Classes: `PascalCase` (rarely used)
- Constants: `UPPER_SNAKE_CASE` (e.g., `CSRF_TOKEN_NAME`)
- Files: `snake_case.php` (e.g., `book_facility.php`)

**Function Prefixes**
- `frs_`: Core application functions (namespace-like prefix)
- Module-specific: No prefix (e.g., `sendEmail` from mail_helper)

**Database**
- Table names: `snake_case` (e.g., `reservation_history`)
- Column names: `snake_case` (e.g., `created_at`)
- Foreign keys: `fk_table_column` (e.g., `fk_res_user`)
- Indexes: `idx_table_column` (e.g., `idx_notif_user`)

**Security**
- Always use prepared statements with PDO
- Never concatenate user input into SQL
- Use `htmlspecialchars()` for output escaping
- Use `sanitizeInput()` for input sanitization
- CSRF tokens required for all forms
- Rate limiting for sensitive operations

### JavaScript

**Naming Conventions**
- Functions: `camelCase` (e.g., `initDashboardNav`)
- Variables: `camelCase` (e.g., `dashboardNavProgress`)
- Constants: `UPPER_SNAKE_CASE` (e.g., `MAIN_SEL`)
- Files: `kebab-case.js` (e.g., `dashboard-navigation.js`)

**Code Style**
- Use strict mode: `'use strict'`
- IIFE pattern for module encapsulation
- Event delegation for dynamic elements
- Async/await for asynchronous operations

### CSS

**Naming Conventions**
- Classes: `kebab-case` (e.g., `dashboard-content`)
- BEM-like: `block__element--modifier` (e.g., `sidebar-link--active`)
- Files: `kebab-case.css` (e.g., `dashboard-pages.css`)

**TailwindCSS**
- Utility classes preferred
- Custom classes in component CSS
- Dark mode via `data-theme` attribute

## Important Global Variables

### PHP Session Variables

```php
$_SESSION['user_authenticated']  // Boolean: Is user logged in?
$_SESSION['user_id']             // Integer: Current user ID
$_SESSION['user_name']           // String: User display name
$_SESSION['user_email']          // String: User email
$_SESSION['role']                // String: User role (Admin/Staff/Resident)
$_SESSION['last_activity']       // Timestamp: Last activity time
$_SESSION['profile_picture']     // String: Profile picture path
$_SESSION['pending_otp_user_id'] // Integer: Pending 2FA user ID
$_SESSION['post_login_redirect'] // String: Redirect target after login
```

### PHP Constants (config/security.php)

```php
CSRF_TOKEN_NAME                 // 'csrf_token'
CSRF_TOKEN_EXPIRY               // 3600 (1 hour)
SESSION_TIMEOUT                 // 300 (5 minutes)
PASSWORD_MIN_LENGTH             // 8
PASSWORD_REQUIRE_UPPERCASE      // true
PASSWORD_REQUIRE_LOWERCASE      // true
PASSWORD_REQUIRE_NUMBER         // true
PASSWORD_REQUIRE_SPECIAL        // false
```

### JavaScript Global Variables

```javascript
window.APP_BASE_PATH            // Base path for URLs
window.CHATBOT_USER_ID          // Current user ID for chatbot
window.SESSION_TIMEOUT_REMAINING // Seconds until session timeout
window.SESSION_TIMEOUT_WARNING_SECONDS // Warning threshold (60)
window.CSRF_TOKEN               // Current CSRF token
window.CSRF_TOKEN_NAME          // CSRF token field name
```

### Environment Variables (.env)

```bash
DB_HOST, DB_NAME, DB_USER, DB_PASS  # Database connection
MAIL_*                              # Email configuration
GEMINI_API_KEY                      # AI chatbot
SMS_ENABLED, SMS_DRIVER, IPROG_*    # SMS configuration
PAYMENTS_ENABLED, PAYMONGO_*        # Payment gateway
CAPTCHA_ENABLED, TURNSTILE_*        # Anti-spam
APP_URL                             # Application base URL
```

## Session Management

### Session Configuration

- **Handler**: PHP default (file-based)
- **Name**: PHPSESSID (default)
- **Timeout**: 5 minutes of inactivity
- **Regeneration**: Every 5 minutes
- **Cookie settings**:
  - HttpOnly: true
  - Secure: true (HTTPS only)
  - SameSite: Lax (allows payment redirects)
  - Lifetime: Session

### Session Lifecycle

1. **Start**: `session_start()` called in `config/app.php` (with `secureSession()` for security configuration)
2. **Authentication**: `frs_complete_authenticated_login()` sets session vars
3. **Activity tracking**: `last_activity` updated on each request
4. **Timeout check**: Session destroyed if inactive > 5 minutes
5. **Regeneration**: Session ID regenerated every 5 minutes
6. **Logout**: Session destroyed in `resources/views/pages/auth/logout.php`

### Session Security

- **CSRF protection**: Token generated per session, 1-hour expiry
- **Session fixation**: Regenerate ID on login
- **Secure cookies**: HttpOnly, Secure, SameSite=Lax
- **Timeout**: Short timeout (5 minutes) with keep-alive
- **Binding**: IP address not bound (allows mobile network changes)

## File Upload Handling

### Upload Configuration

- **Max file size**: 5MB default (configurable)
- **Allowed types**: JPEG, PNG, GIF, WebP, PDF
- **Storage location**: `public/uploads/`
- **Secure storage**: `public/uploads/secure/` for sensitive documents

### Upload Helpers

**`config/upload_helper.php`**
- `validateFileUpload($file, $allowedTypes, $maxSize)`
- `generateSecureFileName($originalName)`
- `moveUploadedFile($file, $destination)`

**`config/secure_documents.php`**
- `saveDocumentToSecureStorage($file, $userId, $documentType)`
- `getSecureDocumentPath($documentId)`
- `deleteSecureDocument($documentId)`

### Document Types

**User Documents** (`user_documents` table)
- `birth_certificate`: Proof of residency
- `valid_id`: Government-issued ID
- `brgy_id`: Barangay ID
- `other`: Other documents

**Reservation Documents** (`reservation_documents` table)
- `event_permit`: Event permit
- `barangay_resolution`: Barangay resolution
- `letter_request`: Letter request
- `other`: Other supporting documents

### Security Measures

- MIME type validation (not just extension)
- File size limits
- Secure filename generation (random + original)
- Path traversal prevention
- Access control via database records
- Archival system for old documents

## Notification System

### Notification Types

**Database Notifications** (`notifications` table)
- `booking`: Reservation-related notifications
- `system`: System-wide announcements
- `reminder`: Booking reminders

### Notification Delivery

**Email Notifications**
- Configuration: `config/mail.php`, `config/mail_helper.php`
- Templates: `config/email_templates.php`
- Rate limiting: Global and per-recipient limits
- Types: Booking confirmations, reminders, password reset, 2FA codes

**SMS Notifications**
- Configuration: `config/sms.php`, `config/sms_helper.php`
- Drivers: IPROG, email-to-SMS gateway, log (demo)
- Rate limiting: Per-recipient limits
- Types: Booking confirmations, reminders, 2FA codes

**In-App Notifications**
- Storage: `notifications` table
- Display: Dashboard notification center
- Read/unread tracking
- Link to related resources

### Notification Helpers

**`config/notifications.php`**
- `createNotification($userId, $type, $title, $message, $link)`
- `markNotificationAsRead($notificationId)`
- `getUserNotifications($userId, $limit)`

**`config/mail_helper.php`**
- `sendEmail($to, $name, $subject, $body)`
- `sendBookingConfirmation($userId, $reservationId)`
- `sendBookingReminder($userId, $reservationId)`

**`config/sms_helper.php`**
- `sendSms($mobile, $message)`
- `sendBookingConfirmationSms($mobile, $details)`
- `sendLoginOtpSms($mobile, $otp, $expiryMinutes)`

## Common Workflows

### Booking Workflow (Resident)

1. User logs in
2. Navigates to "Book a Facility"
3. Selects facility, date, time slot, purpose
4. Uploads valid ID (if not verified)
5. Submits booking
6. **AI Analysis**: Purpose clarity check, conflict detection
7. **Auto-approval**: If conditions met → auto-approved
8. **Payment**: If required → redirect to payment
9. **Manual review**: If not auto-approved → pending staff approval
10. **Notification**: Email/SMS sent to user
11. **Status tracking**: User can view in "My Reservations"

### Approval Workflow (Staff/Admin)

1. Staff accesses "Reservation Approvals"
2. Views pending reservations
3. Reviews details, documents, AI conflict analysis
4. Approves or denies reservation
5. **Approval**: Status → approved, notification sent
6. **Denial**: Status → denied, reason required, notification sent
7. **Reschedule**: Staff can reschedule if needed
8. **Audit trail**: All actions logged

### Check-In/Out Workflow

1. User arrives at facility
2. Staff accesses "Time Tracking"
3. Scans QR code or enters reservation ID
4. Records time-in with proof (photo)
5. User uses facility
6. Staff records time-out with proof
7. Attendance data stored in `reservation_attendance`

### Maintenance Sync Workflow

1. CIMM system generates maintenance schedule
2. Cron job runs `scripts/sync_cimm_maintenance.php`
3. Maintenance dates synced to `facility_blackout_dates`
4. Facilities blocked for booking on maintenance dates
5. Users see unavailable dates in booking calendar

## Known Limitations

### Technical Limitations

1. **No ORM**: Direct PDO queries require careful SQL construction
2. **Session-based auth**: No JWT or token-based auth for APIs
3. **File-based sessions**: Not suitable for horizontal scaling
4. **No queue system**: Email/SMS sent synchronously
5. **No caching layer**: Database queried on every request
6. **No API versioning**: API endpoints not versioned
7. **Limited testing**: Minimal PHPUnit test coverage

### Business Logic Limitations

1. **Single LGU scope**: Designed for Barangay Culiat only
2. **Philippines-specific**: Address formats, SMS providers
3. **Payment gateway**: PayMongo only (Philippines-specific)
4. **Timezone**: Hardcoded to Asia/Manila
5. **Language**: English only (no i18n)

### Security Considerations

1. **CSRF**: Tokens expire after 1 hour (may affect long forms)
2. **Rate limiting**: Database-based (can be bypassed with multiple IPs)
3. **File uploads**: Stored in public directory (requires .htaccess protection)
4. **Session timeout**: 5 minutes may be too short for some users
5. **2FA**: Required for Admin/Staff (may cause friction)

### Performance Considerations

1. **No database connection pooling**: New connection per request
2. **No query result caching**: Repeated queries not cached
3. **Synchronous email/SMS**: Can slow down request handling
4. **No CDN for assets**: All assets served from application server
5. **No image optimization**: Images stored at original size

## Glossary of Project-Specific Terms

- **FRS**: Facilities Reservation System (project acronym)
- **LGU**: Local Government Unit (Philippines context)
- **Barangay Culiat**: Specific LGU location in Quezon City
- **CIMM**: Computerized Integrated Maintenance Management (external system)
- **IPROG**: SMS provider (iprogsms.com)
- **PayMongo**: Payment gateway (Philippines)
- **TOTP**: Time-based One-Time Password (Google Authenticator)
- **OTP**: One-Time Password (email/SMS verification)
- **Blackout date**: Date when facility is unavailable for booking
- **Auto-approval**: System automatically approves bookings based on rules
- **Pencil booking**: Temporary reservation hold awaiting payment
- **Walk-in booking**: Staff creates booking on behalf of resident
- **Operational occupancy**: Real-time facility usage tracking
- **Smart Scheduler**: AI-powered scheduling assistant
- **Purpose analysis**: AI analysis of booking purpose clarity
- **Conflict detection**: AI detection of booking conflicts
- **Secure document storage**: Protected document storage system
- **Rate limiting**: Protection against brute force attacks
- **Session timeout**: Automatic logout after inactivity
- **CSRF**: Cross-Site Request Forgery protection
- **RBAC**: Role-Based Access Control
- **Lookup values**: Configurable dropdown options (facility status, etc.)
