# Facilities Reservation System – Project Structure

## Overview
An AI-driven Public Facilities Reservation System for Local Government Units (LGU) built with PHP, MySQL, CSS, and JavaScript. The system supports role-based access control (Admin, Staff, Resident) and includes modules for facility management, reservations, AI-powered conflict detection, facility recommendations, analytics, notifications, and comprehensive audit trails.

**Key Features:**
- AI-powered conflict detection and facility recommendations
- Real-time booking validation
- Auto-decline expired reservations
- Comprehensive audit logging
- Modern glass-morphism UI design
- Role-based access control
- Notification system
- Analytics and reporting

---

## Directory Structure

```
facilities_reservation_system/
├── config/
│   ├── app.php                    # Application helpers (base_path function)
│   ├── audit.php                  # Audit trail helper functions
│   ├── database.php               # PDO database connection function
│   ├── notifications.php          # Notification helper functions
│   └── ai_helpers.php             # AI conflict detection and facility recommendation functions
├── database/
│   ├── schema.sql                 # Complete MySQL database schema
│   ├── migration_add_notifications.sql
│   ├── migration_alter_facilities_add_public_fields.sql
│   └── migration_add_audit_log.sql
├── docs/
│   ├── AI_IMPLEMENTATIONS.md      # AI feature suggestions and roadmap
│   ├── AI_SCHEDULING_MODULE.md    # AI Predictive Scheduling module documentation
│   ├── AUDIT_TRAIL_MODULE.md      # Audit Trail module documentation
│   ├── AUTH_ROLES_BACKEND.md      # Authentication & Roles backend documentation
│   ├── BOOKING_MODULE.md          # Reservation & Booking module documentation
│   ├── CALENDAR_MODULE.md         # Calendar & Scheduling module documentation
│   ├── FACILITY_MODULE.md         # Facility Management module documentation
│   ├── NOTIFICATION_MODULE.md     # Notification module documentation
│   ├── REPORTS_MODULE.md          # Reports & Analytics module documentation
│   ├── SYSTEM_FLOW.md             # System workflow documentation
│   └── USER_MODULE.md             # User Management module documentation
├── NewTemplate/                   # Design reference template
│   ├── style.css                  # Template design styles
│   ├── cityhall.jpeg              # Landing page background
│   └── *.html                     # Template HTML files
├── public/
│   ├── css/
│   │   └── style.css              # Global styles with glass-morphism design, Poppins font, responsive
│   ├── js/
│   │   └── main.js                # Client-side interactions (mobile menu, sidebar, calendar, notifications, confirmation modals, AI conflict detection)
│   └── img/
│       ├── cityhall.jpeg          # Landing page background image
│       ├── facilities/            # Uploaded facility images
│       ├── amphitheater.jpg       # Default facility images
│       ├── convention-hall.jpg
│       └── sports-complex.jpg
├── resources/
│   └── views/
│       ├── components/
│       │   ├── footer.php                    # Consistent LGU-branded footer for guest pages
│       │   ├── navbar_dashboard.php          # Top header bar for authenticated users (with notifications popover)
│       │   ├── navbar_guest.php              # Navigation bar for public pages (glass-morphism style)
│       │   └── sidebar_dashboard.php         # Fixed left sidebar navigation for authenticated users
│       ├── layouts/
│       │   ├── dashboard_layout.php          # Base layout for authenticated pages (enforces login, includes sidebar/header, sets APP_BASE_PATH)
│       │   └── guest_layout.php               # Base layout for public pages (includes guest navbar/footer, landing-page class detection)
│       └── pages/
│           ├── auth/
│           │   ├── login.php                  # Login form with database authentication (glass-morphism design)
│           │   ├── logout.php                 # Logout handler (destroys session)
│           │   └── register.php               # Registration form (glass-morphism design)
│           ├── dashboard/
│           │   ├── ai_conflict_check.php      # AI conflict detection API endpoint (JSON)
│           │   ├── ai_scheduling.php          # AI Predictive Scheduling insights (data-driven recommendations)
│           │   ├── audit_trail.php            # System action log with filtering (Admin/Staff only)
│           │   ├── book_facility.php          # Facility booking form with AI conflict detection and recommendations
│           │   ├── calendar.php                # Calendar view (month/week/day) for scheduling
│           │   ├── facility_management.php    # Admin/Staff facility CRUD interface with audit log sidebar
│           │   ├── index.php                  # Dashboard overview/homepage with charts and stats
│           │   ├── my_reservations.php        # Resident's reservation history with status timeline
│           │   ├── notifications.php          # Full notifications page with pagination
│           │   ├── notifications_api.php      # Notifications API endpoint (list, mark as read)
│           │   ├── profile.php                # User profile page (editable name, email, password change)
│           │   ├── reports.php                # Reports & Analytics dashboard with charts
│           │   ├── reservation_detail.php     # Staff/Admin detailed reservation view with auto-decline logic
│           │   ├── reservations_manage.php    # Admin/Staff reservation approval/denial interface with auto-decline
│           │   └── user_management.php        # Admin/Staff user account management
│           └── public/
│               ├── contact.php                # Contact form (glass-morphism design with cityhall background)
│               ├── facilities.php             # Public facility listing (dynamic from database)
│               ├── facility_details.php      # Public facility detail page (dynamic data, 14-day availability)
│               ├── home.php                   # Public homepage with hero slideshow (cityhall background)
│               ├── legal.php                  # Legal Notice page
│               ├── privacy.php                # Privacy Policy page
│               └── terms.php                  # Terms and Conditions page
├── routes/
│   └── web.php                    # Route map (placeholder for future router integration)
├── generate_hash.php              # Utility script to generate password hashes
└── STRUCTURE.md                   # This file

```

---

## `/config`

### `app.php`
- **Function**: `base_path()`
- **Purpose**: Dynamically determines the application's base URL path
- **Usage**: Used throughout the system for generating correct URLs regardless of deployment location (subdirectory or root)

### `database.php`
- **Function**: `db()`
- **Returns**: PDO connection to MySQL database
- **Database**: `facilities_reservation`
- **Connection**: Uses PDO with error handling

### `audit.php`
- **Function**: `logAudit($action, $module, $details, $userId)`
- **Purpose**: Logs all system actions for transparency and compliance
- **Integration**: Used across all modules (reservations, facilities, users)

### `notifications.php`
- **Functions**: 
  - `createNotification($userId, $type, $title, $message, $link)`
  - `getUnreadCount($userId)`
- **Purpose**: Manages user notifications (booking updates, system alerts, reminders)

### `ai_helpers.php` ⭐ NEW
- **Functions**:
  - `detectBookingConflict($facilityId, $date, $timeSlot, $excludeReservationId)` - Real-time conflict detection
  - `calculateConflictRisk($facilityId, $date, $timeSlot)` - Risk scoring based on historical patterns
  - `findAlternativeSlots($facilityId, $date)` - Suggests alternative time slots
  - `recommendFacilities($purpose, $expectedAttendance, $requiredAmenities, $limit)` - AI facility recommendations
  - `matchCapacity()`, `matchAmenities()`, `matchPurpose()`, `calculatePopularityScore()` - Recommendation scoring helpers
- **Purpose**: AI-powered features for conflict detection and facility recommendations

---

## `/database`

### `schema.sql`
- **Database**: `facilities_reservation`
- **Tables**:
  - **`users`**: User accounts (id, name, email, password_hash, role, status, timestamps)
  - **`facilities`**: Facility information (id, name, description, base_rate, image_path, location, capacity, amenities, rules, status, timestamps)
  - **`reservations`**: Reservation records (id, user_id, facility_id, reservation_date, time_slot, purpose, status, timestamps)
  - **`reservation_history`**: Status change history (id, reservation_id, status, note, created_by, created_at)
  - **`audit_log`**: System action log (id, user_id, action, module, details, ip_address, user_agent, created_at)
  - **`notifications`**: User notifications (id, user_id, type, title, message, link, is_read, created_at)
- **Foreign Keys**: Enforced relationships between tables
- **Indexes**: Optimized for common queries

---

## `/public`

### CSS (`public/css/style.css`)
- **Design System**: 
  - Poppins font family (Google Fonts)
  - Glass-morphism cards with backdrop blur
  - Gradient backgrounds matching NewTemplate design
  - Color palette: Blues (#6384d2, #285ccd), gradients, white text on colored backgrounds
- **Global LGU Theme**: Color variables, typography, spacing
- **Responsive Design**: Mobile-first approach, media queries
- **Guest Navigation**: Glass-morphism navbar with backdrop blur
- **Dashboard Layout**: Sidebar, header with glass effect, content area
- **Components**: Cards, buttons (gradient style), forms, tables, status badges
- **Animations**: `fadeInUp` for page transitions
- **Calendar Styles**: Month/week/day view grids, event pills
- **Reports & AI**: Grid layouts, KPI cards, charts, AI panels
- **Notification Panel**: Facebook-style popover dropdown
- **Confirmation Modal**: Professional modal overlay
- **Landing Page**: Cityhall.jpeg background with blur overlay

### JavaScript (`public/js/main.js`)
- **Mobile Menu Toggle**: Guest navigation hamburger menu
- **Sidebar Collapse**: Dashboard sidebar toggle
- **Calendar View Switching**: Month/week/day view tabs
- **Notification Panel**: Toggle visibility, AJAX loading, mark as read, close on outside click
- **Confirmation Modal**: Global handler for `.confirm-action` buttons
- **AI Conflict Detection**: Real-time conflict checking on booking form (facility, date, time slot changes)
- **Hero Slideshow**: Automatic rotation for facility images on homepage

### Images (`public/img/`)
- **`cityhall.jpeg`**: Landing page and auth pages background
- **`facilities/`**: Uploaded facility images (created dynamically)
- Default facility placeholder images

---

## `/resources/views`

### Layouts

#### `layouts/guest_layout.php`
- Base template for all public-facing pages
- Includes `navbar_guest.php` and `footer.php`
- No authentication required
- **Landing Page Detection**: Automatically adds `landing-page` class for home.php
- Sets `window.APP_BASE_PATH` for JavaScript

#### `layouts/dashboard_layout.php`
- Base template for all authenticated dashboard pages
- **Session Check**: Redirects unauthenticated users to login
- Includes `sidebar_dashboard.php` and `navbar_dashboard.php`
- Includes global confirmation modal markup and JavaScript
- Sets `window.APP_BASE_PATH` for JavaScript
- Includes Chart.js for analytics

### Components

#### `components/navbar_guest.php`
- Public navigation bar with glass-morphism design
- Logo, links, and mobile hamburger menu
- Links to: Home, Facilities, Contact, Login, Register
- All links use `base_path()` for correct URL generation

#### `components/navbar_dashboard.php`
- Top header bar for authenticated users
- Sidebar toggle button
- Welcome message and username display
- Notification bell with unread indicator (Facebook-style popover)
- Logout link
- All links use `base_path()`

#### `components/sidebar_dashboard.php`
- Fixed left sidebar navigation
- **Role-Based Links**: Dynamically displays links based on user role
- **Sections**:
  - **Main**: Dashboard, Book a Facility, Calendar & Schedule, Reports & Analytics, AI Scheduling
  - **Operations** (Admin/Staff only): Reservation Approvals, Facility Management, User Management
  - **Account**: Audit Trail (Admin/Staff), Profile
- Logo block with gradient badge
- Collapsible on mobile
- All links use `base_path()`

#### `components/footer.php`
- Consistent LGU-branded footer
- Links to Terms, Privacy, Legal, Contact
- Copyright information

### Pages

#### Public Pages (`pages/public/`)

**`home.php`**
- Hero slideshow with dynamic facility images
- Featured facilities from database
- Quick access cards
- "How it works" and "For LGU Offices" sections
- Recently added facilities grid
- Uses `cityhall.jpeg` background with blur overlay

**`facilities.php`**
- Public facility listing (reads from database)
- Dynamic facility cards with images
- "Free of Charge" messaging
- Links to facility details

**`facility_details.php`**
- Detailed facility information (all fields from database)
- Hero image banner
- 14-day availability calendar (real reservation data)
- Emergency override disclaimer
- Usage, amenities, rules sections

**`contact.php`**
- Contact form with glass-morphism design
- Cityhall.jpeg background with blur overlay
- Matches login/register form styling

**`terms.php`**, **`privacy.php`**, **`legal.php`**
- Legal documentation pages

#### Authentication Pages (`pages/auth/`)

**`login.php`**
- Database-backed authentication
- Verifies password with `password_verify()`
- Checks user status (`active` required)
- Sets session variables (`user_authenticated`, `user_id`, `role`, `name`)
- Redirects to dashboard on success
- Glass-morphism design with cityhall background

**`register.php`**
- Registration form
- Saves new users with `password_hash()`
- Sets status to `pending` by default
- Displays success/error messages
- Glass-morphism design with cityhall background

**`logout.php`**
- Destroys session
- Redirects to login page

#### Dashboard Pages (`pages/dashboard/`)

**Resident Access:**

**`index.php`**
- Dashboard overview with real-time stats
- Upcoming reservations
- Pending requests (for Admin/Staff)
- Quick actions
- Charts (monthly trends, status breakdown, top facilities)

**`book_facility.php`** ⭐ ENHANCED
- Facility booking form (loads facilities from DB)
- **AI Conflict Detection**: Real-time checking via JavaScript
- **AI Facility Recommendations**: Shows recommended facilities based on purpose
- Conflict warnings and alternative slot suggestions
- Saves reservations with `pending` status
- Past date validation
- Embeds "My Recent Reservations" card (last 5)
- Availability snapshot (14 days)

**`my_reservations.php`**
- User's reservation history
- Displays status timeline from `reservation_history` table
- Pagination (5 per page)
- Status badges with proper colors

**`profile.php`** ⭐ ENHANCED
- Editable user profile (name, email)
- Password change functionality
- Two-column layout with avatar and role pills
- Success/error message handling

**`notifications.php`**
- Full notifications page with pagination
- Mark as read functionality
- Filter by type

**Admin/Staff Access (in addition to Resident pages):**

**`facility_management.php`** ⭐ ENHANCED
- Facility CRUD interface
- Add/Edit/Delete facilities
- Image upload support
- All facility fields (name, description, location, capacity, amenities, rules, status, image)
- **Audit Log Sidebar**: Shows recent Facility Management activities
- Pagination (5 per page)
- Role-based access control

**`reservations_manage.php`** ⭐ ENHANCED
- Pending reservation approvals
- **Auto-Decline Logic**: Automatically denies expired pending reservations
- Approve/Deny with notes
- Recent activity log
- "View Details" links to detail page
- Records status changes in `reservation_history`
- Creates notifications for requesters

**`reservation_detail.php`** ⭐ ENHANCED
- Comprehensive reservation view
- Shows requester info, facility info
- Full status history timeline
- Action buttons (Approve/Deny) for pending reservations
- **Auto-Decline Logic**: Checks and denies expired reservations on view
- Emergency override disclaimer


**`calendar.php`**
- Calendar view (month/week/day) for scheduling
- Status badges with proper styling

**`reports.php`**
- Reports & Analytics dashboard
- Charts and statistics

**`ai_scheduling.php`** ⭐ ENHANCED
- AI Predictive Scheduling insights
- Data-driven recommendations based on historical patterns
- Shows recommended time slots with conflict scores
- Peak day and time slot insights

**`user_management.php`**
- User account management and approvals
- Role assignment
- Status management

**`audit_trail.php`**
- System action log with filtering
- Filter by module, user, date range
- Pagination
- Admin/Staff only

**`ai_conflict_check.php`** ⭐ NEW
- API endpoint for real-time conflict detection
- Returns JSON response
- Used by JavaScript for live conflict checking

**`notifications_api.php`**
- API endpoint for notifications
- Actions: list, mark as read
- Returns JSON response

---

## Key Features

### Authentication & Authorization
- Database-backed login with password hashing
- Role-based access control (Admin, Staff, Resident)
- Session management
- Account approval workflow (pending → active)
- Password change functionality

### Reservation Management
- Public facility browsing
- Resident booking form with **AI conflict detection**
- **AI facility recommendations**
- Admin/Staff approval workflow
- **Auto-decline expired reservations**
- Status history tracking
- Detailed reservation views
- **Past date validation** (no reverse booking)

### AI Features ⭐ NEW
- **Conflict Detection**: Real-time checking for booking conflicts
  - Historical pattern analysis
  - Risk scoring (0-100%)
  - Alternative slot suggestions
- **Facility Recommendations**: Smart facility suggestions
  - Capacity matching
  - Amenities matching
  - Purpose-based recommendations
  - Popularity scoring

### User Experience
- **Modern Glass-Morphism Design**: Matching NewTemplate design language
- Responsive design (mobile-first)
- Page transition animations
- Professional confirmation modals
- Facebook-style notification popover
- Real-time conflict warnings
- Hero slideshow on homepage
- Cityhall.jpeg background on landing/auth pages

### Design System
- **Poppins font family** (Google Fonts)
- **Glass-morphism cards** with backdrop blur
- **Gradient buttons** with hover effects
- Consistent color palette (LGU blue theme)
- Reusable components
- Professional form styling
- Status badges with color coding
- Card-based layouts
- Modern typography

### System Features
- **Comprehensive Audit Logging**: All actions logged with user, IP, timestamp
- **Notification System**: Real-time notifications for booking events
- **Auto-Decline System**: Expired reservations automatically denied
- **Emergency Override Policy**: Clear disclaimer on booking and facility pages
- **Free Facilities**: All facilities provided free of charge for residents of Barangay Culiat

---

## Adding New Pages

1. **Create the view file** in the appropriate directory:
   - Public pages → `resources/views/pages/public/`
   - Auth pages → `resources/views/pages/auth/`
   - Dashboard pages → `resources/views/pages/dashboard/`

2. **Use the appropriate layout**:
   - Public pages: `include __DIR__ . '/../../layouts/guest_layout.php';`
   - Dashboard pages: `include __DIR__ . '/../../layouts/dashboard_layout.php';`

3. **Include required configs**:
   ```php
   require_once __DIR__ . '/../../../../config/app.php';
   require_once __DIR__ . '/../../../../config/database.php';
   // Add others as needed (audit.php, notifications.php, ai_helpers.php)
   ```

4. **Set page title**: `$pageTitle = 'Page Title | LGU Facilities Reservation';`

5. **Use output buffering**:
   ```php
   ob_start();
   ?>
   <!-- Your HTML/PHP content -->
   <?php
   $content = ob_get_clean();
   include __DIR__ . '/../../layouts/[layout].php';
   ```

6. **Use `base_path()`** for all internal links and redirects

7. **Add navigation link** (if needed):
   - Public pages: Update `navbar_guest.php`
   - Dashboard pages: Update `sidebar_dashboard.php` (respect role-based access)

---

## Deployment Notes

- **Server**: Compatible with Apache (XAMPP) or PHP built-in server
- **PHP Version**: PHP 7.4+ recommended
- **Database**: MySQL 5.7+ or MariaDB 10.2+
- **File Structure**: Maintain directory structure for proper includes
- **Session Storage**: Ensure writable session directory
- **Database**: Import `database/schema.sql` before first use
- **Password Hashing**: Use `generate_hash.php` to create initial admin password
- **Base Path**: System automatically detects deployment location (root or subdirectory)
- **Images**: Ensure `public/img/facilities/` directory is writable for uploads

---

## Development Workflow

1. **Local Development**: Use XAMPP or PHP built-in server
2. **Database Setup**: Import `database/schema.sql` via phpMyAdmin
3. **Create Admin User**: Use `generate_hash.php` to hash password, then INSERT into `users` table
4. **Testing**: Access pages via browser (e.g., `http://localhost/facilities_reservation_system/resources/views/pages/public/home.php`)
5. **Styling**: Edit `public/css/style.css` for design changes
6. **JavaScript**: Edit `public/js/main.js` for client-side behavior
7. **AI Features**: Edit `config/ai_helpers.php` for AI logic enhancements

---

## AI Implementation Status

### ✅ Implemented
- **Conflict Detection**: Real-time checking with risk scoring
- **Facility Recommendations**: Smart matching based on requirements
- **Auto-Decline**: Expired reservations automatically denied

### 📋 Planned (See `docs/AI_IMPLEMENTATIONS.md`)
- Predictive Maintenance Scheduling
- Demand Forecasting & Capacity Planning
- NLP for Purpose Analysis
- Chatbot for Reservation Assistance
- Anomaly Detection for Fraud Prevention
- Image Recognition for Facility Condition Monitoring

---

## Recent Updates

### Visual Design Overhaul
- Applied NewTemplate design language throughout
- Glass-morphism cards and components
- Poppins font family
- Cityhall.jpeg background on landing/auth pages
- Gradient buttons and modern styling
- Improved text contrast and readability

### AI Features
- Real-time conflict detection on booking form
- Facility recommendation engine
- Historical pattern analysis
- Risk scoring system

### System Enhancements
- Auto-decline expired reservations
- Past date validation
- Emergency override policy disclaimers
- Free facilities for Barangay Culiat residents
- Comprehensive audit logging in Facility Management
- Enhanced profile page (editable)

---

## Future Enhancements

- Advanced AI features (see `docs/AI_IMPLEMENTATIONS.md`)
- Email/SMS notification system
- Advanced search and filtering
- Export reports to PDF/Excel
- Multi-language support
- API endpoints for mobile app
- Real-time collaboration features
