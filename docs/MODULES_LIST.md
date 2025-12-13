# Complete Modules/Submodules/Microservices List

## System Architecture Overview
The Facilities Reservation System is organized into logical microservices (currently implemented as a monolithic PHP application with modular boundaries). This document lists all modules, submodules, and microservices.

---

## 1. Core Microservices (Logical Services)

### 1.1 Gateway / Frontend Service
- **Purpose**: Serves PHP views, routes user traffic to backend endpoints
- **Components**:
  - Public pages routing
  - Authenticated dashboard routing
  - Layout management (guest_layout.php, dashboard_layout.php)

### 1.2 Auth & Session Service
- **Purpose**: Authentication, authorization, and session management
- **Submodules**:
  - Login Module (email/password)
  - OTP Module (2-step verification)
  - Session Management
  - Rate Limiting & Lockout
  - Password Reset Module
- **Files**:
  - `resources/views/pages/auth/login.php`
  - `resources/views/pages/auth/login_otp.php`
  - `resources/views/pages/auth/register.php`
  - `resources/views/pages/auth/forgot_password.php`
  - `resources/views/pages/auth/reset_password.php`
  - `resources/views/pages/auth/logout.php`
  - `config/security.php`

### 1.3 User & Profile Service
- **Purpose**: User account and profile management
- **Submodules**:
  - User Registration
  - Profile Management
  - User Status Management (approve/deny/lock)
  - Role Management
- **Files**:
  - `resources/views/pages/dashboard/profile.php`
  - `resources/views/pages/dashboard/user_management.php`

### 1.4 Document Service
- **Purpose**: Document upload, validation, and storage
- **Submodules**:
  - Document Upload (Valid ID)
  - Document Validation
  - Document Storage (`public/uploads/documents/{userId}`)
  - Document Metadata Management
- **Files**:
  - `config/upload_helper.php`
  - Registration form (document upload)

### 1.5 Facility Service
- **Purpose**: Facility management and information
- **Submodules**:
  - Facility CRUD (Create, Read, Update, Delete)
  - Facility Status Management
  - Facility Image Management
  - Facility Audit Logging
  - Public Facility Listing
  - Facility Details Display
- **Files**:
  - `resources/views/pages/dashboard/facility_management.php`
  - `resources/views/pages/public/facilities.php`
  - `resources/views/pages/public/facility_details.php`

### 1.6 Reservation Service
- **Purpose**: Booking management and reservation lifecycle
- **Submodules**:
  - Booking Creation
  - Reservation Status Management (pending/approved/denied/cancelled)
  - Reservation History/Timeline
  - Auto-Decline Expired Reservations
  - Booking Limit Enforcement:
    - Max 3 active reservations per user (within 30 days)
    - 60-day advance booking window
    - 1 booking per user per day
  - Reservation Detail View
  - My Reservations (user's booking history)
- **Files**:
  - `resources/views/pages/dashboard/book_facility.php`
  - `resources/views/pages/dashboard/my_reservations.php`
  - `resources/views/pages/dashboard/reservations_manage.php`
  - `resources/views/pages/dashboard/reservation_detail.php`

### 1.7 AI Recommendation Service
- **Purpose**: AI-powered conflict detection and facility recommendations
- **Submodules**:
  - Conflict Detection Module
  - Facility Recommendation Engine
  - Distance Scoring (Haversine formula)
  - Purpose-Based Ranking
  - Holiday/Event Risk Tagging:
    - Philippine Holidays
    - Barangay Culiat Events (Fiesta, Founding Day)
  - AI Scheduling Insights
- **Files**:
  - `config/ai_helpers.php`
  - `resources/views/pages/dashboard/ai_scheduling.php`
  - `resources/views/pages/dashboard/ai_conflict_check.php`
  - `resources/views/pages/dashboard/ai_recommendations_api.php`

### 1.8 Calendar Service
- **Purpose**: Calendar views and reservation event data
- **Submodules**:
  - Month View
  - Week View
  - Day View
  - Event Display (reservations, holidays, events)
  - Calendar Modal (30-day snapshot)
  - Day Details Modal
- **Files**:
  - `resources/views/pages/dashboard/calendar.php`
  - Calendar components in `book_facility.php`

### 1.9 Notification Service
- **Purpose**: In-app notifications management
- **Submodules**:
  - Notification Creation
  - Notification Panel (sidebar)
  - Mark as Read
  - Notification Filtering
  - Full Notifications Page
- **Files**:
  - `config/notifications.php`
  - `resources/views/pages/dashboard/notifications.php`
  - `resources/views/pages/dashboard/notifications_api.php`

### 1.10 Export/Reports Service
- **Purpose**: Data export and reporting
- **Submodules**:
  - CSV Export
  - HTML-for-PDF Reports
  - Analytics Dashboard
  - Reports & Analytics Page
- **Files**:
  - `resources/views/pages/dashboard/reports.php`
  - `resources/views/pages/dashboard/index.php` (charts)

### 1.11 Email/OTP Service
- **Purpose**: Email notifications and OTP delivery
- **Submodules**:
  - OTP Email Sending
  - Approval/Denial Email Notifications
  - Password Reset Email
  - Account Lock Email Notifications
  - Contact Inquiry Email Alerts
- **Files**:
  - `config/mail.php`
  - `config/mail_helper.php`

### 1.12 Password Reset Service
- **Purpose**: Password reset token management
- **Submodules**:
  - Reset Token Generation
  - Token Validation
  - Password Update
  - Reset Link Email
- **Files**:
  - `resources/views/pages/auth/forgot_password.php`
  - `resources/views/pages/auth/reset_password.php`

### 1.13 Audit & Security Service
- **Purpose**: Security logging and audit trails
- **Submodules**:
  - Security Event Logging
  - Audit Trail Entries
  - Login Attempt Tracking
  - Rate Limit Records
  - Audit Trail View (Admin/Staff)
- **Files**:
  - `config/audit.php`
  - `config/security.php`
  - `resources/views/pages/dashboard/audit_trail.php`

### 1.14 Contact Inquiry Service
- **Purpose**: Public contact form and inquiry management
- **Submodules**:
  - Contact Form Submission
  - Inquiry Storage
  - Admin Email Alerts
  - Contact Inquiries Dashboard (Admin/Staff)
- **Files**:
  - `resources/views/pages/public/contact.php`
  - `resources/views/pages/public/contact_handler.php`
  - `resources/views/pages/dashboard/contact_inquiries.php`

### 1.15 Geocoding Service
- **Purpose**: Address geocoding and coordinate management
- **Submodules**:
  - Address to Coordinates Conversion
  - Coordinate Storage
- **Files**:
  - `config/geocoding.php`

### 1.16 AI Chatbot Assistant Service
- **Purpose**: Conversational AI assistant for user queries and support
- **Submodules**:
  - Chat Interface (UI)
  - Message Processing
  - Context Retrieval (Facilities, Reservations)
  - AI/ML Model Integration (API endpoint ready)
  - Mock Response Generation (fallback)
- **Files**:
  - `resources/views/pages/dashboard/ai_chatbot.php`
- **Integration**:
  - API Endpoint: `POST /api/ai/chat` (to be implemented)
  - Queries: Facilities (D4), Reservations (D3) for context
  - Currently uses mock responses; ready for AI/ML model integration

---

## 2. Frontend Modules (Pages & Views)

### 2.1 Public Pages
- **Home Page** (`home.php`)
- **Facilities Listing** (`facilities.php`)
- **Facility Details** (`facility_details.php`)
- **Contact Page** (`contact.php`)
- **Legal Pages**:
  - Terms & Conditions (`terms.php`)
  - Privacy Policy (`privacy.php`)
  - Legal (`legal.php`)

### 2.2 Authentication Pages
- **Login** (`login.php`)
- **Login OTP** (`login_otp.php`)
- **Registration** (`register.php`)
- **Forgot Password** (`forgot_password.php`)
- **Reset Password** (`reset_password.php`)
- **Logout** (`logout.php`)

### 2.3 Dashboard Pages (Resident Access)
- **Dashboard Overview** (`index.php`)
  - Real-time statistics
  - Upcoming reservations
  - Charts (Monthly Trends, Status Breakdown, Top Facilities)
  - Filter bar
- **Book a Facility** (`book_facility.php`)
  - Booking form
  - AI conflict detection
  - Calendar snapshot
  - Recent reservations
- **My Reservations** (`my_reservations.php`)
- **AI Scheduling** (`ai_scheduling.php`)
- **AI Assistant** (`ai_chatbot.php`)
- **Profile** (`profile.php`)
- **Notifications** (`notifications.php`)
- **Calendar** (`calendar.php`)

### 2.4 Dashboard Pages (Admin/Staff Access)
- **Reservation Approvals** (`reservations_manage.php`)
- **Facility Management** (`facility_management.php`)
- **Reports & Analytics** (`reports.php`)
- **User Management** (`user_management.php`)
- **Contact Inquiries** (`contact_inquiries.php`)
- **Audit Trail** (`audit_trail.php`)
- **Reservation Detail** (`reservation_detail.php`)

### 2.5 API Endpoints
- **Notifications API** (`notifications_api.php`)
- **AI Recommendations API** (`ai_recommendations_api.php`)
- **AI Conflict Check** (`ai_conflict_check.php`)
- **Contact Handler** (`contact_handler.php`)

---

## 3. Backend Modules (Config & Helpers)

### 3.1 Configuration Modules
- **App Configuration** (`config/app.php`)
  - Base path function
  - Application constants
- **Database Configuration** (`config/database.php`)
  - PDO connection
  - Database helpers
- **Security Configuration** (`config/security.php`)
  - Security headers
  - Content Security Policy (CSP)
  - Rate limiting configuration

### 3.2 Helper Modules
- **AI Helpers** (`config/ai_helpers.php`)
  - Conflict detection functions
  - Facility recommendation functions
  - Risk calculation
  - Holiday/event tagging
- **Audit Helpers** (`config/audit.php`)
  - Audit logging functions
  - Activity tracking
- **Notification Helpers** (`config/notifications.php`)
  - Notification creation
  - Notification retrieval
- **Upload Helpers** (`config/upload_helper.php`)
  - File upload validation
  - File storage management
- **Mail Helpers** (`config/mail.php`, `config/mail_helper.php`)
  - Email sending functions
  - SMTP configuration
- **Geocoding Helpers** (`config/geocoding.php`)
  - Address geocoding functions

---

## 4. Supporting Services & Infrastructure

### 4.1 Layout Components
- **Guest Layout** (`resources/views/layouts/guest_layout.php`)
- **Dashboard Layout** (`resources/views/layouts/dashboard_layout.php`)
- **Sidebar Component** (`resources/views/components/sidebar_dashboard.php`)
- **Navbar Component** (`resources/views/components/navbar_dashboard.php`)

### 4.2 Frontend Assets
- **CSS Styles** (`public/css/style.css`)
- **JavaScript** (`public/js/main.js`)
- **Bootstrap Integration**
- **Chart.js Integration** (for analytics)

### 4.3 Database Modules
- **Schema** (`database/schema.sql`)
- **Migrations**:
  - `migration_add_notifications.sql`
  - `migration_alter_facilities_add_public_fields.sql`
  - `migration_add_audit_log.sql`
  - `migration_add_lock_reason_to_users.sql`

### 4.4 Storage Services
- **Document Storage** (`public/uploads/documents/{userId}/`)
- **Image Storage** (`public/uploads/images/`)
- **Profile Picture Storage**

---

## 5. Module Dependencies & Communication

### 5.1 Service Communication Patterns
- **Gateway → Services**: REST/HTTP (PHP controllers)
- **Auth → Email/OTP**: SMTP for OTP and approval emails
- **Password Reset → Email/OTP**: SMTP for reset links
- **Reservation → Notification**: Direct DB writes
- **Reservation → AI Recommendation**: In-process/HTTP calls
- **Reservation → Calendar**: Shared DB view (read-only queries)
- **Facility → AI Recommendation**: Reads facility data for scoring
- **User → Document Service**: HTTP/form upload
- **Contact Form → Contact Inquiry**: HTTP/AJAX posts

### 5.2 Data Flow
- **User Registration**: Frontend → Auth Service → User Service → Document Service
- **Booking Flow**: Frontend → Reservation Service → AI Recommendation Service → Notification Service
- **Approval Flow**: Admin → Reservation Service → Notification Service → Email Service
- **Contact Inquiry**: Public Form → Contact Inquiry Service → Email Service

---

## 6. Module Summary by Category

### Core Business Logic (7 modules)
1. Auth & Session Service
2. User & Profile Service
3. Facility Service
4. Reservation Service
5. AI Recommendation Service
6. Calendar Service
7. Contact Inquiry Service

### Supporting Services (8 modules)
8. Document Service
9. Notification Service
10. Email/OTP Service
11. Password Reset Service
12. Export/Reports Service
13. Audit & Security Service
14. Geocoding Service
15. AI Chatbot Assistant Service
16. Gateway/Frontend Service

### Total: 16 Core Microservices + Supporting Infrastructure

---

## Notes
- Current deployment is monolithic PHP with modular responsibilities
- Services are logical boundaries; actual calls are HTTP within the app
- SMTP is used for email/OTP/reset/inquiry alerts
- No message queue is present today
- Brevo/domain SMTP is planned to replace Gmail SMTP
- AI Chatbot Assistant: UI implemented, ready for AI/ML model integration via API endpoint (`POST /api/ai/chat`)



