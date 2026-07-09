# Architecture Documentation

## High-Level Architecture

```mermaid
graph TB
    Client[Client Browser] -->|HTTP Request| Index[index.php Front Controller]
    Index -->|API Routes| API[API Handlers]
    Index -->|Public Routes| Public[Public Pages]
    Index -->|Auth Routes| Auth[Auth Pages]
    Index -->|Dashboard Routes| Dashboard[Dashboard Pages]
    
    API -->|JSON Response| Client
    Public -->|HTML + guest_layout| Client
    Auth -->|HTML + guest_layout| Client
    Dashboard -->|Auth Check| Security[Security Middleware]
    Security -->|Authenticated| Dashboard
    Dashboard -->|HTML + dashboard_layout| Client
    
    Dashboard -->|Load| Config[Config/Helpers]
    Dashboard -->|Query| Database[(MySQL Database)]
    Config --> Database
    Config --> External[External Services]
    
    External -->|Email| Mail[PHPMailer/SMTP]
    External -->|SMS| SMS[IPROG SMS Gateway]
    External -->|Payment| Payment[PayMongo]
    External -->|AI| AI[Gemini API]
    External -->|Maps| Maps[Mapbox/Google Maps]
    
    subgraph "Scheduled Tasks"
        Cron[Cron Jobs]
    end
    Cron --> Database
    Cron --> External
    
    subgraph "AI/ML Module"
        Python[Python Scripts]
    end
    Python --> Database
    Python --> AI
```

## Folder Responsibilities

### Root Directory
- **index.php**: Front controller, routes all requests
- **.htaccess**: Apache configuration, URL rewriting
- **composer.json**: PHP dependencies
- **package.json**: Node.js dependencies (TailwindCSS)
- **run_migrations.php**: Database migration runner
- **.env**: Environment configuration (gitignored)

### config/
**Purpose**: Application configuration and business logic helpers

**Key Files**:
- `app.php`: Core helpers (URL, paths, environment loading)
- `database.php`: Database connection (PDO singleton)
- `security.php`: Security headers, CSRF, rate limiting, session management
- `permissions.php`: RBAC system implementation
- `lookups.php**: Configurable lookup values (facility status, etc.)
- `*_helper.php`: Module-specific business logic
- `*.php`: Module configuration (mail, sms, payments, etc.)

**Responsibilities**:
- Centralized business logic
- Database abstraction layer
- Security enforcement
- Permission checking
- External service integration

### database/
**Purpose**: Database schema and migrations

**Key Files**:
- `schema.sql`: Base database schema
- `migration_*.sql`: Incremental schema changes
- `performance_indexes.sql`: Performance optimization indexes

**Responsibilities**:
- Schema versioning
- Incremental updates
- Performance tuning

### resources/views/
**Purpose**: All view templates

**Subdirectories**:
- `layouts/`: Page layout templates (dashboard_layout.php, guest_layout.php)
- `components/`: Reusable UI components (sidebar, navbar, footer)
- `pages/`: Page-specific views
  - `auth/`: Authentication pages
  - `dashboard/`: Dashboard pages
  - `public/`: Public pages
  - `dashboard/includes/`: Dashboard sub-views

**Responsibilities**:
- HTML rendering
- UI composition
- Reusable component library

### public/
**Purpose**: Publicly accessible assets

**Subdirectories**:
- `css/`: Stylesheets
- `js/`: JavaScript files
- `img/`: Images
- `uploads/`: User-uploaded files

**Responsibilities**:
- Static asset serving
- User file storage
- Client-side code

### scripts/
**Purpose**: Scheduled tasks and maintenance scripts

**Key Files**:
- `auto_decline_expired.php`: Decline stale reservations
- `send_booking_reminders.php`: Send booking reminders
- `process_expired_reservations.php`: Clean up expired reservations
- `archive_documents.php`: Archive old documents
- `sync_cimm_maintenance.php`: Sync maintenance from CIMM

**Responsibilities**:
- Background job execution
- Data maintenance
- External system sync

### ai/
**Purpose**: AI/ML integration

**Subdirectories**:
- `api/`: AI API endpoints
- `scripts/`: Training and utility scripts
- `src/`: ML model source code

**Responsibilities**:
- ML model training
- AI feature implementation
- Python-PHP integration

### services/
**Purpose**: External service integrations

**Note**: This directory exists but external service integrations are primarily in config/

**Responsibilities**:
- Third-party API clients
- Service abstraction layer

### storage/
**Purpose**: Application storage

**Responsibilities**:
- Log files
- Cache storage
- Temporary files

### tests/
**Purpose**: Unit tests

**Responsibilities**:
- PHPUnit test cases
- Test fixtures

## Data Flow

### Booking Creation Flow

```mermaid
sequenceDiagram
    participant U as User
    participant I as index.php
    participant B as book_facility.php
    participant C as Config Helpers
    participant D as Database
    participant A as AI Services
    participant P as Payment Gateway
    participant N as Notification Service

    U->>I: POST /dashboard/book-facility
    I->>I: Route to book_facility.php
    I->>B: Include view
    B->>C: Load helpers (security, permissions, etc.)
    B->>C: Validate CSRF token
    B->>C: Check rate limits
    B->>C: Validate input
    B->>C: Check user verification
    B->>A: AI purpose analysis
    B->>A: AI conflict detection
    B->>C: Evaluate auto-approval rules
    B->>D: Begin transaction
    B->>D: Lock facility for booking
    B->>D: Recheck conflicts
    B->>D: Insert reservation
    B->>D: Insert reservation history
    B->>D: Log audit event
    B->>D: Commit transaction
    alt Payment Required
        B->>P: Create payment link
        B->>D: Update reservation status to pending_payment
        B->>N: Send payment notification
    else Auto-Approved
        B->>D: Update reservation status to approved
        B->>N: Send approval notification
    else Manual Review
        B->>D: Update reservation status to pending
        B->>N: Send submission notification
    end
    B->>I: Return success/error
    I->>U: HTML response with flash message
```

### Authentication Flow

```mermaid
sequenceDiagram
    participant U as User
    participant I as index.php
    participant L as login.php
    participant S as security.php
    participant D as Database
    participant M as Mail/SMS Service
    participant T as TOTP Service

    U->>I: POST /login
    I->>L: Route to login.php
    L->>S: Load security helpers
    L->>S: Verify CSRF token
    L->>S: Check rate limits
    L->>S: Validate captcha (if enabled)
    L->>D: Query user by email
    alt User not found
        L->>S: Record failed attempt
        L->>U: Return generic error
    else User found
        L->>S: Verify password
        alt Password invalid
            L->>S: Increment failed attempts
            alt 5+ failed attempts
                L->>D: Lock account for 30 min
                L->>M: Send lock notification
            end
            L->>U: Return error
        else Password valid
            alt Account locked
                L->>U: Return lock message
            else Account deactivated
                L->>U: Return deactivated message
            else Email not verified
                L->>S: Begin email verification flow
                L->>M: Send verification code
                L->>U: Redirect to /verify-email
            else 2FA required (Admin/Staff)
                alt Email OTP enabled
                    L->>S: Generate OTP code
                    L->>M: Send OTP via email/SMS
                else TOTP enabled
                    L->>T: Validate TOTP setup
                else No 2FA configured
                    L->>U: Redirect to /login-setup-2fa
                end
                L->>U: Redirect to /login-otp
            else No 2FA required
                L->>S: Complete authenticated login
                L->>D: Update last login
                L->>U: Redirect to dashboard
            end
        end
    end
```

### Dashboard AJAX Navigation Flow

```mermaid
sequenceDiagram
    participant U as User
    participant J as dashboard-navigation.js
    participant I as index.php
    participant V as View File
    participant C as Config

    U->>J: Click sidebar link
    J->>J: Intercept click event
    J->>J: Check if dashboard URL
    J->>J: Prevent default navigation
    J->>J: Animate out current content
    J->>I: Fetch with X-Requested-With: FRS-Dashboard-Nav
    I->>I: Route to view file
    I->>V: Include view
    I->>C: Load config helpers
    V->>I: Return HTML
    I->>I: Extract dashboard-content section
    I->>J: Return partial HTML
    J->>J: Replace content
    J->>J: Animate in new content
    J->>J: Update browser history
    J->>J: Update active nav state
    J->>J: Reinitialize components
```

## Page Navigation Flow

```mermaid
graph TD
    Start[User Request] --> Index[index.php]
    Index --> CheckPath{Path Type?}
    
    CheckPath -->|API Route| APIHandler[Load API Handler]
    CheckPath -->|Public Route| PublicHandler[Load Public View]
    CheckPath -->|Auth Route| AuthHandler[Load Auth View]
    CheckPath -->|Dashboard Route| DashboardCheck{Auth Check}
    
    APIHandler --> APIResponse[Return JSON]
    
    PublicHandler --> LoadGuestLayout[Load guest_layout.php]
    LoadGuestLayout --> RenderPublic[Render Public Page]
    RenderPublic --> PublicResponse[Return HTML]
    
    AuthHandler --> LoadGuestLayout
    LoadGuestLayout --> RenderAuth[Render Auth Page]
    RenderAuth --> AuthResponse[Return HTML]
    
    DashboardCheck -->|Not Authenticated| RedirectToLogin[Redirect to /login]
    DashboardCheck -->|Authenticated| LoadDashboardLayout[Load dashboard_layout.php]
    LoadDashboardLayout --> RenderDashboard[Render Dashboard Page]
    RenderDashboard --> DashboardResponse[Return HTML]
    
    APIResponse --> End[Response Sent]
    PublicResponse --> End
    AuthResponse --> End
    DashboardResponse --> End
    RedirectToLogin --> End
```

## PHP Include Hierarchy

### Dashboard Page Include Chain

```mermaid
graph TD
    A[index.php] --> B[config/app.php]
    B --> C[config/security.php]
    B --> D[config/ui_helpers.php]
    B --> E[config/database.php]
    
    A --> F[resources/views/pages/dashboard/*.php]
    F --> E
    F --> G[config/permissions.php]
    F --> H[config/lookups.php]
    F --> I[config/module_helpers.php]
    
    A --> J[resources/views/layouts/dashboard_layout.php]
    J --> B
    J --> K[resources/views/components/sidebar_dashboard.php]
    J --> L[resources/views/components/navbar_dashboard.php]
    
    K --> B
    K --> E
    K --> G
    
    L --> B
```

### Public Page Include Chain

```mermaid
graph TD
    A[index.php] --> B[config/app.php]
    B --> C[config/security.php]
    B --> D[config/ui_helpers.php]
    
    A --> E[resources/views/pages/public/*.php]
    E --> B
    
    A --> F[resources/views/layouts/guest_layout.php]
    F --> B
    F --> G[resources/views/components/navbar_guest.php]
    F --> H[resources/views/components/footer.php]
    
    G --> B
    H --> B
```

## Shared Components

### Layout Components

**dashboard_layout.php**
- Sidebar navigation (role-based)
- Top navbar with user info
- Dashboard content area
- AI chatbot widget
- Session timeout modal
- Confirmation modal
- Toast notification stack
- Global JavaScript variables

**guest_layout.php**
- Public navigation
- Footer
- Content area
- No authentication required

### UI Components

**sidebar_dashboard.php**
- Role-based menu rendering
- Collapsible sections
- Active state highlighting
- User profile display
- Permission-aware links

**navbar_dashboard.php**
- User information
- Notification bell
- Logout button
- Mobile menu toggle

**footer.php**
- Copyright information
- Quick links
- Contact information

**occupancy_board.php**
- Real-time occupancy display
- Facility status indicators
- Live updates via AJAX

**occupancy_dashboard_strip.php**
- Compact occupancy display
- Quick status overview

## AJAX Endpoints

### Dashboard AJAX Endpoints

**POST /dashboard/ai-chatbot**
- Purpose: AI chatbot interaction
- Authentication: Required
- Returns: JSON with reply and optional actions
- File: `resources/views/pages/dashboard/ai_chatbot.php`

**POST /dashboard/session-keepalive**
- Purpose: Keep session alive
- Authentication: Required
- Returns: JSON with success and remaining seconds
- File: `resources/views/pages/dashboard/session_keepalive.php`

**POST /dashboard/ai-recommendations-api**
- Purpose: Get AI facility recommendations
- Authentication: Required
- Returns: JSON with recommended facilities
- File: `resources/views/pages/dashboard/ai_recommendations_api.php`

**POST /dashboard/ai-conflict-check**
- Purpose: Check booking conflicts using AI
- Authentication: Required
- Returns: JSON with conflict analysis
- File: `resources/views/pages/dashboard/ai_conflict_check.php`

**POST /dashboard/notifications-api**
- Purpose: Get user notifications
- Authentication: Required
- Returns: JSON with notifications
- File: `resources/views/pages/dashboard/notifications_api.php`

**POST /dashboard/occupancy-live**
- Purpose: Get live occupancy data
- Authentication: Required
- Returns: JSON with occupancy stats
- File: `resources/views/pages/dashboard/occupancy_live_api.php`

**POST /dashboard/geocode-api**
- Purpose: Geocode address to coordinates
- Authentication: Required
- Returns: JSON with coordinates
- File: `resources/views/pages/dashboard/geocode_api.php`

### Public AJAX Endpoints

**GET /api/public/availability**
- Purpose: Get facility availability for public calendar
- Authentication: Not required
- Returns: JSON with available slots
- File: `resources/views/pages/public/api/availability.php`

**POST /paymongo-webhook**
- Purpose: PayMongo payment webhook
- Authentication: Not required (signature verification)
- Returns: JSON acknowledgment
- File: `resources/views/pages/public/api/paymongo_webhook.php`

**POST /contact-handler**
- Purpose: Handle contact form submissions
- Authentication: Not required
- Returns: JSON with success/error
- File: `resources/views/pages/public/contact_handler.php`

## Controller/Model Relationships

### Traditional MVC vs FRS Architecture

The FRS system does not follow traditional MVC pattern. Instead, it uses:

**Controllers**: View files (`resources/views/pages/*.php`) handle both presentation and logic
**Models**: Config helpers (`config/*.php`) contain business logic and data access
**Views**: Same as controllers (monolithic view files)

### Data Access Pattern

```mermaid
graph LR
    View[View File] -->|Include| Helper[Config Helper]
    Helper -->|PDO Query| Database[(Database)]
    Database -->|Result Set| Helper
    Helper -->|Data Array| View
    View -->|Render| HTML[HTML Output]
```

### Key Helper-View Relationships

**Reservation Operations**
- View: `resources/views/pages/dashboard/book_facility.php`
- Helpers: `config/reservation_helpers.php`, `config/auto_approval.php`, `config/ai_helpers.php`
- Tables: `reservations`, `reservation_history`, `facilities`, `users`

**User Management**
- View: `resources/views/pages/dashboard/user_management.php`
- Helpers: `config/user_admin.php`, `config/security.php`
- Tables: `users`, `user_documents`, `role_permissions`

**Facility Management**
- View: `resources/views/pages/dashboard/facility_management.php`
- Helpers: `config/upload_helper.php`, `config/lookups.php`
- Tables: `facilities`, `facility_blackout_dates`

**Reports**
- View: `resources/views/pages/dashboard/reports.php`
- Helpers: `config/analytics_chart_filters.php`, `config/ai_ml_integration.php`
- Tables: `reservations`, `facilities`, `users`, `audit_log`

## Dependency Graph

### PHP File Dependencies

```mermaid
graph TD
    A[index.php] --> B[config/app.php]
    B --> C[config/security.php]
    B --> D[config/database.php]
    B --> E[config/ui_helpers.php]
    
    F[Dashboard Views] --> B
    F --> D
    F --> G[config/permissions.php]
    F --> H[config/lookups.php]
    
    I[Auth Views] --> B
    I --> C
    I --> J[config/mail_helper.php]
    I --> K[config/captcha.php]
    
    L[Public Views] --> B
    
    M[Helpers] --> D
    M --> N[External Services]
    
    N --> O[PHPMailer]
    N --> P[TwoFactorAuth]
    
    Q[Scripts] --> D
    Q --> M
    Q --> N
```

### JavaScript File Dependencies

```mermaid
graph TD
    A[main.js] --> B[frs-form-validation.js]
    A --> C[frs-animations.js]
    A --> D[frs-toast.js]
    A --> E[frs-field-tips.js]
    
    F[dashboard-layout] --> G[dashboard-navigation.js]
    F --> H[dashboard-charts.js]
    F --> I[chart-filters.js]
    
    J[public-layout] --> K[public-navigation.js]
    J --> L[home-animations.js]
    
    M[occupancy-monitor] --> N[occupancy-board.js]
    M --> O[occupancy-dashboard-strip.js]
```

## Reusable Components

### PHP Helpers

**Security Functions** (`config/security.php`)
- `generateCSRFToken()`: Generate CSRF token
- `verifyCSRFToken()`: Verify CSRF token
- `csrf_token()`: Get CSRF token for forms
- `csrf_field()`: Output CSRF hidden input
- `checkRateLimit()`: Check and record rate limit
- `validatePassword()`: Validate password strength
- `secureSession()`: Configure secure session
- `getClientIP()`: Get client IP address
- `sanitizeInput()`: Sanitize user input

**Database Functions** (`config/database.php`)
- `db()`: Get PDO instance (singleton)
- Connection configuration from environment variables

**Permission Functions** (`config/permissions.php`)
- `frs_has_permission()`: Check role permission
- `frs_can_create()`: Check create permission
- `frs_can_read()`: Check read permission
- `frs_can_update()`: Check update permission
- `frs_can_delete()`: Check delete permission
- `frs_get_role_permissions()`: Get all permissions for role

**Lookup Functions** (`config/lookups.php`)
- `frs_lookup_values()`: Get lookup values for category
- `frs_lookup_label()`: Get label for a value
- `frs_lookup_metadata()`: Get metadata for a value
- `frs_lookup_add_value()`: Add new lookup value
- `frs_lookup_update_value()`: Update lookup value
- `frs_lookup_delete_value()`: Delete lookup value

**URL/Path Functions** (`config/app.php`)
- `base_path()`: Get base path relative to web root
- `base_url()`: Get full base URL
- `app_root_path()`: Get absolute filesystem path
- `env_value()`: Get environment variable

**Notification Functions** (`config/notifications.php`)
- `createNotification()`: Create database notification
- `markNotificationAsRead()`: Mark notification as read
- `getUserNotifications()`: Get user notifications

**Email Functions** (`config/mail_helper.php`)
- `sendEmail()`: Send email via PHPMailer
- `sendBookingConfirmation()`: Send booking confirmation email
- `sendBookingReminder()`: Send booking reminder email

**SMS Functions** (`config/sms_helper.php`)
- `sendSms()`: Send SMS via configured provider
- `sendBookingConfirmationSms()`: Send booking confirmation SMS
- `sendLoginOtpSms()`: Send login OTP SMS

### JavaScript Modules

**Form Validation** (`frs-form-validation.js`)
- `frsFocusFirstInvalid()`: Focus first invalid field
- `frsFocusBySelector()`: Focus field by selector
- `frsFocusByFieldKey()`: Focus field by key

**Animations** (`frs-animations.js`)
- `frsAnim.pageOut()`: Page exit animation
- `frsAnim.pageIn()`: Page enter animation
- `frsAnim.staggerCards()`: Stagger card animations

**Toast Notifications** (`frs-toast.js`)
- `frsShowToast()`: Show toast notification
- `frsHideToast()`: Hide toast notification

**Dashboard Navigation** (`dashboard-navigation.js`)
- SPA-like navigation for dashboard
- Progress indicator
- History management
- Active state highlighting

**Field Tips** (`frs-field-tips.js`)
- Contextual field help
- Tooltips
- Field descriptions

### CSS Components

**Layout Classes**
- `.dashboard`: Dashboard page wrapper
- `.dashboard-content`: Main content area
- `.sidebar`: Sidebar navigation
- `.dashboard-main`: Main area (content + navbar)

**Utility Classes**
- `.frs-field-error-highlight`: Error highlight animation
- `.dashboard-fade-in`: Fade in animation
- `.dashboard-fade-out`: Fade out animation

**Component Classes**
- `.frs-toast-stack`: Toast notification container
- `.modal-confirm`: Confirmation modal
- `.chatbot-panel`: AI chatbot panel
- `.session-timeout-overlay`: Session timeout modal
