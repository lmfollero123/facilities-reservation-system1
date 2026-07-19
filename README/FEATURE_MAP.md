# Feature Map

This document provides a comprehensive map of all features in the Facilities Reservation System, allowing developers to locate any feature within 30 seconds.

## Authentication & Authorization

### Purpose
User authentication, registration, password reset, and two-factor authentication (2FA).

### Main Pages
- `/login` - Login page
- `/register` - Registration page
- `/verify-email` - Email verification page
- `/forgot-password` - Forgot password page
- `/reset-password` - Password reset page
- `/login-otp` - OTP verification page
- `/login-setup-2fa` - 2FA setup page
- `/logout` - Logout handler

### PHP Files Involved
- `resources/views/pages/auth/login.php`
- `resources/views/pages/auth/register.php`
- `resources/views/pages/auth/verify_email.php`
- `resources/views/pages/auth/forgot_password.php`
- `resources/views/pages/auth/reset_password.php`
- `resources/views/pages/auth/login_otp.php`
- `resources/views/pages/auth/login_setup_2fa.php`
- `resources/views/pages/auth/logout.php`
- `config/security.php` - Security functions, rate limiting, 2FA
- `config/permissions.php` - RBAC system

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation
- `public/js/main.js` - Global event listeners

### Database Tables Used
- `users` - User accounts
- `password_reset_tokens` - Password reset tokens
- `rate_limits` - Rate limiting
- `login_attempts` - Login attempt logging

### User Roles Allowed
- All roles (Admin, Staff, Resident) for login
- Public for registration

### Main Workflows
1. **Registration**: User registers → email verification code sent → user verifies code → account activated
2. **Login**: User enters credentials → password verified → 2FA check (if Admin/Staff) → session created
3. **Password Reset**: User requests reset → token sent via email → user resets password

### Entry Points
- `/login` - Login form
- `/register` - Registration form
- `/forgot-password` - Password reset request

### Exit Points
- `/dashboard` - After successful login
- `/login` - After logout
- `/verify-email` - After registration

### Dependencies
- PHPMailer (email sending)
- RobThree/TwoFactorAuth (TOTP)
- `config/mail_helper.php` - Email functions
- `config/sms_helper.php` - SMS functions

### Future Extension Points
- Social login (Google, Facebook)
- Multi-factor authentication options
- Password strength policies
- Account recovery options

---

## Facility Management

### Purpose
CRUD operations for facilities, including images, operating hours, capacity, amenities, and auto-approval rules.

### Main Pages
- `/dashboard/facility-management` - Facility management page
- `/dashboard/facility-assistant` - Facility selection helper

### PHP Files Involved
- `resources/views/pages/dashboard/facility_management.php`
- `resources/views/pages/dashboard/facility_assistant.php`
- `resources/views/pages/dashboard/facility-details-api.php`
- `config/upload_helper.php` - File upload handling
- `config/lookups.php` - Lookup values for status

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation
- `public/js/frs-toast.js` - Toast notifications

### Database Tables Used
- `facilities` - Facility data
- `facility_blackout_dates` - Blackout dates
- `lookup_values` - Status values

### User Roles Allowed
- Admin: Full CRUD
- Staff: Create, Read, Update (no delete)
- Resident: Read only

### Main Workflows
1. **Create Facility**: Admin/Staff fills form → uploads image → saves to database
2. **Edit Facility**: Admin/Staff modifies facility details → updates database
3. **Delete Facility**: Admin removes facility → cascades to reservations
4. **Set Blackout Dates**: Admin/Staff blocks dates for maintenance

### Entry Points
- `/dashboard/facility-management` - Main management page

### Exit Points
- `/dashboard/facility-management` - After CRUD operations

### Dependencies
- `config/upload_helper.php` - Image upload
- `config/lookups.php` - Status management

### Future Extension Points
- Facility categories
- Facility ratings/reviews
- Facility availability calendar
- Bulk facility import/export

---

## Reservation Management

### Purpose
Create, view, approve, deny, reschedule, and cancel facility reservations.

### Main Pages
- `/dashboard/book-facility` - Booking page and My Reservations
- `/dashboard/reservations-manage` - Reservation approvals (Staff/Admin)
- `/dashboard/my-reservations` - User's reservations
- `/dashboard/calendar` - Calendar view
- `/dashboard/calendar-export-ics` - ICS export

### PHP Files Involved
- `resources/views/pages/dashboard/book_facility.php` - Main booking logic
- `resources/views/pages/dashboard/reservations_manage.php` - Approval management
- `resources/views/pages/dashboard/my_reservations.php` - User reservations
- `resources/views/pages/dashboard/calendar.php` - Calendar view
- `resources/views/pages/dashboard/calendar_export_ics.php` - ICS export
- `resources/views/pages/dashboard/includes/reservations_mine_post_handlers.php` - Post handlers
- `config/reservation_helpers.php` - Reservation business logic
- `config/auto_approval.php` - Auto-approval rules
- `config/ai_helpers.php` - AI conflict detection
- `config/reservation_documents.php` - Document handling
- `config/paymongo_helper.php` - Payment integration

### JavaScript Files Involved
- `public/js/dashboard-navigation.js` - AJAX navigation
- `public/js/frs-form-validation.js` - Form validation
- `public/js/frs-toast.js` - Toast notifications
- `public/js/dashboard-charts.js` - Calendar charts

### Database Tables Used
- `reservations` - Reservation data
- `reservation_history` - Status change history
- `reservation_documents` - Supporting documents
- `reservation_attendance` - Check-in/check-out data
- `facilities` - Facility data
- `users` - User data
- `payments` - Payment data

### User Roles Allowed
- Admin: Full access (approve, deny, reschedule, cancel)
- Staff: Full access (approve, deny, reschedule, cancel)
- Resident: Create, Read, Update own reservations (no delete)

### Main Workflows
1. **Create Booking**: User selects facility/date/time → uploads documents → submits → AI analysis → auto-approve or pending
2. **Approve/Deny**: Staff/Admin reviews pending reservations → approves or denies with reason
3. **Reschedule**: User or staff changes reservation date/time
4. **Cancel**: User cancels reservation (with restrictions)
5. **Payment**: User pays via PayMongo (if required)

### Entry Points
- `/dashboard/book-facility` - Booking form
- `/dashboard/reservations-manage` - Approval queue

### Exit Points
- `/dashboard/book-facility?module=mine` - After booking
- `/dashboard/reservations-manage` - After approval action

### Dependencies
- `config/reservation_helpers.php` - Core logic
- `config/auto_approval.php` - Auto-approval
- `config/ai_helpers.php` - AI features
- `config/paymongo_helper.php` - Payments
- `config/notifications.php` - Notifications
- `config/mail_helper.php` - Email notifications
- `config/sms_helper.php` - SMS notifications

### Future Extension Points
- Recurring reservations
- Waitlist system
- Reservation templates
- Bulk reservation import
- Reservation analytics

---

## AI-Powered Tools

### Purpose
AI-powered scheduling assistance, conflict detection, purpose analysis, and chatbot.

### Main Pages
- `/dashboard/ai-scheduling` - Smart scheduler
- `/dashboard/ai-chatbot` - Chatbot widget (embedded in dashboard)

### PHP Files Involved
- `resources/views/pages/dashboard/ai_scheduling.php` - Smart scheduler UI
- `resources/views/pages/dashboard/ai_chatbot.php` - Chatbot API
- `resources/views/pages/dashboard/ai_recommendations_api.php` - Recommendations API
- `resources/views/pages/dashboard/ai_conflict_check.php` - Conflict detection API
- `resources/views/pages/dashboard/booking_smart_hints_api.php` - Smart hints API
- `resources/views/pages/dashboard/chatbot_api.php` - Legacy chatbot
- `resources/views/pages/dashboard/facility_recommendations_api.php` - Facility recommendations
- `config/ai_helpers.php` - AI helper functions
- `config/ai_ml_integration.php` - ML model integration
- `config/gemini_chatbot.php` - Gemini API client
- `config/chatbot_responses.php` - Rule-based fallbacks

### JavaScript Files Involved
- `public/js/main.js` - Chatbot widget initialization

### Database Tables Used
- `reservations` - For conflict detection
- `facilities` - For recommendations

### User Roles Allowed
- Admin: Full access
- Staff: Read access
- Resident: Read access

### Main Workflows
1. **Smart Scheduling**: User provides event details → AI recommends best slots
2. **Conflict Detection**: User selects slot → AI checks for conflicts
3. **Purpose Analysis**: User enters purpose → AI detects unclear descriptions
4. **Chatbot**: User asks questions → AI provides answers or prefill actions

### Entry Points
- `/dashboard/ai-scheduling` - Smart scheduler
- Chatbot widget in dashboard layout

### Exit Points
- `/dashboard/book-facility` - Prefilled booking form
- Chatbot responses

### Dependencies
- Gemini API (`GEMINI_API_KEY`)
- Python ML models (optional)
- `config/gemini_chatbot.php` - API client

### Future Extension Points
- Natural language booking
- Predictive availability
- AI-powered pricing
- Sentiment analysis on feedback

---

## User Management

### Purpose
CRUD operations for user accounts, role assignment, verification, and deactivation.

### Main Pages
- `/dashboard/user-management` - User management page

### PHP Files Involved
- `resources/views/pages/dashboard/user_management.php`
- `config/user_admin.php` - User management functions
- `config/security.php` - Password handling
- `config/permissions.php` - Permission checks

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation
- `public/js/frs-toast.js` - Toast notifications

### Database Tables Used
- `users` - User accounts
- `user_documents` - User documents
- `role_permissions` - Permission data

### User Roles Allowed
- Admin: Full CRUD
- Staff: Create, Read, Update (no delete)
- Resident: None

### Main Workflows
1. **Create User**: Admin/Staff adds user → sends verification email
2. **Edit User**: Admin/Staff modifies user details
3. **Deactivate User**: Admin deactivates account
4. **Assign Role**: Admin changes user role
5. **Verify User**: Admin verifies user documents

### Entry Points
- `/dashboard/user-management` - Main management page

### Exit Points
- `/dashboard/user-management` - After CRUD operations

### Dependencies
- `config/user_admin.php` - User functions
- `config/mail_helper.php` - Verification emails
- `config/permissions.php` - Permission system

### Future Extension Points
- User groups
- Bulk user import
- User activity tracking
- User analytics

---

## Document Management

### Purpose
Secure document storage for user verification and reservation support.

### Main Pages
- `/dashboard/document-management` - Document management page
- `/dashboard/download-document.php` - Document download
- `/dashboard/download-reservation-document.php` - Reservation document download

### PHP Files Involved
- `resources/views/pages/dashboard/document_management.php`
- `resources/views/pages/dashboard/download_document.php`
- `resources/views/pages/dashboard/download_reservation_document.php`
- `config/secure_documents.php` - Secure storage functions
- `config/document_archival.php` - Archival logic
- `config/upload_helper.php` - Upload handling

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation

### Database Tables Used
- `user_documents` - User documents
- `reservation_documents` - Reservation documents

### User Roles Allowed
- Admin: Full access
- Staff: Read access
- Resident: Upload own documents, view own documents

### Main Workflows
1. **Upload Document**: User uploads ID or supporting document
2. **Verify Document**: Admin reviews and verifies document
3. **Archive Document**: System archives old documents
4. **Download Document**: Authorized users download documents

### Entry Points
- `/dashboard/book-facility` - During booking
- `/dashboard/document-management` - Management page

### Exit Points
- Download file response

### Dependencies
- `config/secure_documents.php` - Storage logic
- `config/upload_helper.php` - Upload validation
- `config/document_archival.php` - Archival

### Future Extension Points
- Document expiration
- Document versioning
- Document OCR
- Bulk document processing

---

## Reports & Analytics

### Purpose
Reservation statistics, facility utilization, user activity, and audit trail reports.

### Main Pages
- `/dashboard/reports` - Reports dashboard
- `/dashboard/occupancy-monitor` - Real-time occupancy
- `/dashboard/download-export.php` - Export downloads
- `/dashboard/export-view.php` - Export view
- `/dashboard/export-pdf.php` - PDF exports
- `/dashboard/audit_trail.php` - Audit log viewer
- `/dashboard/audit_trail_pdf.php` - Audit log PDF
- `/dashboard/export_audit_trail.php` - Audit log export

### PHP Files Involved
- `resources/views/pages/dashboard/reports.php`
- `resources/views/pages/dashboard/occupancy_monitor.php`
- `resources/views/pages/dashboard/download_export.php`
- `resources/views/pages/dashboard/export_view.php`
- `resources/views/pages/dashboard/export_pdf.php`
- `resources/views/pages/dashboard/audit_trail.php`
- `resources/views/pages/dashboard/audit_trail_pdf.php`
- `resources/views/pages/dashboard/export_audit_trail.php`
- `config/analytics_chart_filters.php` - Chart filters
- `config/data_export.php` - Export functions
- `config/audit.php` - Audit logging
- `config/occupancy_monitoring.php` - Occupancy data

### JavaScript Files Involved
- `public/js/dashboard-charts.js` - Chart.js initialization
- `public/js/chart-filters.js` - Filter controls
- `public/js/occupancy-board.js` - Occupancy display
- `public/js/occupancy-dashboard-strip.js` - Compact occupancy

### Database Tables Used
- `reservations` - Reservation data
- `facilities` - Facility data
- `users` - User data
- `audit_log` - Audit trail
- `operational_occupancy` - Occupancy data

### User Roles Allowed
- Admin: Full access
- Staff: Read access
- Resident: None

### Main Workflows
1. **View Reports**: Admin/Staff selects report type → filters data → views charts
2. **Export Data**: Admin/Staff exports to CSV/Excel/PDF
3. **Monitor Occupancy**: Real-time occupancy tracking
4. **View Audit Trail**: Review system actions

### Entry Points
- `/dashboard/reports` - Reports dashboard
- `/dashboard/occupancy-monitor` - Occupancy monitor

### Exit Points
- File downloads (CSV, Excel, PDF)

### Dependencies
- `config/analytics_chart_filters.php` - Data filtering
- `config/data_export.php` - Export generation
- `config/occupancy_monitoring.php` - Occupancy API

### Future Extension Points
- Custom report builder
- Scheduled reports
- Report sharing
- Advanced analytics

---

## Communications

### Purpose
Contact inquiries, announcements, email notifications, and SMS notifications.

### Main Pages
- `/dashboard/announcements-manage` - Announcements management
- `/dashboard/contact` - Contact information
- `/dashboard/contact-inquiries` - Contact inquiries management
- `/dashboard/contact_info_manage.php` - Contact info management
- `/public/contact.php` - Public contact form
- `/public/contact_handler.php` - Contact form handler

### PHP Files Involved
- `resources/views/pages/dashboard/announcements_manage.php`
- `resources/views/pages/dashboard/contact.php`
- `resources/views/pages/dashboard/contact_inquiries.php`
- `resources/views/pages/dashboard/contact_info_manage.php`
- `resources/views/pages/public/contact.php`
- `resources/views/pages/public/contact_handler.php`
- `config/mail_helper.php` - Email functions
- `config/sms_helper.php` - SMS functions
- `config/notifications.php` - In-app notifications
- `config/email_templates.php` - Email templates
- `config/announcement_categories.php` - Announcement categories

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation
- `public/js/frs-toast.js` - Toast notifications

### Database Tables Used
- `contact_inquiries` - Contact form submissions
- `announcements` - System announcements
- `notifications` - In-app notifications

### User Roles Allowed
- Admin: Full access
- Staff: Create, Read, Update (no delete)
- Resident: Read announcements, submit contact form

### Main Workflows
1. **Create Announcement**: Admin/Staff creates announcement → published to dashboard
2. **Handle Inquiry**: Staff reviews contact inquiry → responds
3. **Send Notification**: System sends email/SMS for events
4. **Contact Form**: Public submits inquiry → stored in database

### Entry Points
- `/dashboard/announcements-manage` - Announcements
- `/dashboard/contact-inquiries` - Inquiries
- `/public/contact.php` - Public contact form

### Exit Points
- `/dashboard/announcements-manage` - After CRUD
- `/dashboard/contact-inquiries` - After response

### Dependencies
- `config/mail_helper.php` - Email sending
- `config/sms_helper.php` - SMS sending
- `config/notifications.php` - In-app notifications

### Future Extension Points
- Email campaigns
- SMS campaigns
- Newsletter management
- Contact form customization

---

## Maintenance Integration

### Purpose
CIMM system integration for maintenance scheduling and blackout date sync.

### Main Pages
- `/dashboard/maintenance_integration.php` - Maintenance integration page
- `/dashboard/blackout_dates.php` - Blackout date management

### PHP Files Involved
- `resources/views/pages/dashboard/maintenance_integration.php`
- `resources/views/pages/dashboard/blackout_dates.php`
- `config/maintenance_helper.php` - Maintenance functions
- `config/blackout_dates.php` - Blackout date functions
- `scripts/sync_cimm_maintenance.php` - CIMM sync script

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation
- `public/js/frs-toast.js` - Toast notifications

### Database Tables Used
- `facility_blackout_dates` - Blackout dates
- `facilities` - Facility data

### User Roles Allowed
- Admin: Full access
- Staff: Create, Read, Update (no delete)
- Resident: Read only

### Main Workflows
1. **Sync CIMM**: Cron job fetches maintenance dates → creates blackout dates
2. **Manual Blackout**: Admin/Staff manually adds blackout dates
3. **View Blackouts**: Users see unavailable dates in booking calendar

### Entry Points
- `/dashboard/maintenance_integration.php` - Integration page
- `/dashboard/blackout_dates.php` - Blackout management

### Exit Points
- `/dashboard/maintenance_integration.php` - After sync

### Dependencies
- `config/maintenance_helper.php` - CIMM integration
- `scripts/sync_cimm_maintenance.php` - Cron job

### Future Extension Points
- External maintenance system APIs
- Automated conflict resolution
- Maintenance notifications

---

## Payments

### Purpose
PayMongo payment gateway integration for reservation payments.

### Main Pages
- `/dashboard/pay-now.php` - Payment page
- `/public/payment_return.php` - Payment return handler
- `/public/api/paymongo_webhook.php` - Payment webhook

### PHP Files Involved
- `resources/views/pages/dashboard/pay_now.php`
- `resources/views/pages/public/payment_return.php`
- `resources/views/pages/public/api/paymongo_webhook.php`
- `config/paymongo_helper.php` - PayMongo integration
- `config/payments.php` - Payment configuration
- `config/reservation_helpers.php` - Reservation payment sync

### JavaScript Files Involved
- None (PayMongo handles client-side)

### Database Tables Used
- `payments` - Payment transactions
- `reservations` - Reservation data

### User Roles Allowed
- Admin: Full access
- Staff: Read access
- Resident: Make payments

### Main Workflows
1. **Initiate Payment**: User clicks pay → PayMongo checkout created → redirect to payment
2. **Payment Return**: PayMongo redirects back → payment status synced
3. **Webhook**: PayMongo sends webhook → payment status updated
4. **Sync Payment**: Cron job syncs pending payments

### Entry Points
- `/dashboard/pay-now.php` - Payment initiation
- `/public/api/paymongo_webhook.php` - Webhook endpoint

### Exit Points
- `/dashboard/book-facility?module=mine` - After payment
- PayMongo checkout page

### Dependencies
- PayMongo API (`PAYMONGO_*` environment variables)
- `config/paymongo_helper.php` - Integration logic

### Future Extension Points
- Multiple payment gateways
- Refund processing
- Payment plans
- Payment history

---

## Attendance Tracking

### Purpose
Check-in/check-out tracking for reservations with proof uploads.

### Main Pages
- `/dashboard/time-tracking.php` - Time tracking page
- `/dashboard/check_in_gate.php` - Check-in gate
- `/dashboard/facility_check_in_gate.php` - Facility check-in gate

### PHP Files Involved
- `resources/views/pages/dashboard/time_tracking.php`
- `resources/views/pages/dashboard/check_in_gate.php`
- `resources/views/pages/dashboard/facility_check_in_gate.php`
- `config/attendance.php` - Attendance functions
- `config/upload_helper.php` - Proof upload

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation
- `public/js/frs-toast.js` - Toast notifications

### Database Tables Used
- `reservation_attendance` - Attendance records
- `reservations` - Reservation data

### User Roles Allowed
- Admin: Full access
- Staff: Full access
- Resident: View own attendance

### Main Workflows
1. **Check-in**: Staff scans QR or enters reservation ID → uploads proof → records time-in
2. **Check-out**: Staff uploads proof → records time-out
3. **View Attendance**: Users view their attendance history

### Entry Points
- `/dashboard/time-tracking.php` - Main tracking page
- `/dashboard/check_in_gate.php` - Gate interface

### Exit Points
- `/dashboard/time-tracking.php` - After check-in/out

### Dependencies
- `config/attendance.php` - Attendance logic
- `config/upload_helper.php` - Proof upload

### Future Extension Points
- Self check-in kiosk
- Mobile check-in
- Attendance analytics
- Automated check-out

---

## System Settings

### Purpose
System configuration, permission management, and lookup value management.

### Main Pages
- `/dashboard/system_settings.php` - System settings page

### PHP Files Involved
- `resources/views/pages/dashboard/system_settings.php`
- `config/permissions.php` - Permission management
- `config/lookups.php` - Lookup management

### JavaScript Files Involved
- `public/js/frs-form-validation.js` - Form validation
- `public/js/frs-toast.js` - Toast notifications

### Database Tables Used
- `role_permissions` - Permission data
- `lookup_categories` - Lookup categories
- `lookup_values` - Lookup values

### User Roles Allowed
- Admin: Full access
- Staff: None
- Resident: None

### Main Workflows
1. **Manage Permissions**: Admin assigns permissions per role per module
2. **Manage Lookups**: Admin configures dropdown values (facility status, etc.)
3. **System Configuration**: Admin configures system settings

### Entry Points
- `/dashboard/system_settings.php` - Settings page

### Exit Points
- `/dashboard/system_settings.php` - After changes

### Dependencies
- `config/permissions.php` - Permission logic
- `config/lookups.php` - Lookup logic

### Future Extension Points
- Configuration versioning
- Settings import/export
- Configuration templates

---

## Public Pages

### Purpose
Public-facing pages for facility browsing, announcements, and information.

### Main Pages
- `/` - Home page
- `/facilities` - Facilities listing
- `/facility-details` - Individual facility details
- `/announcements` - Public announcements
- `/faq` - FAQ page
- `/contact` - Contact page
- `/legal` - Legal page
- `/privacy` - Privacy policy
- `/terms` - Terms of service

### PHP Files Involved
- `resources/views/pages/public/home.php`
- `resources/views/pages/public/facilities.php`
- `resources/views/pages/public/facility_details.php`
- `resources/views/pages/public/announcements.php`
- `resources/views/pages/public/faq.php`
- `resources/views/pages/public/contact.php`
- `resources/views/pages/public/legal.php`
- `resources/views/pages/public/privacy.php`
- `resources/views/pages/public/terms.php`

### JavaScript Files Involved
- `public/js/public-navigation.js` - Public navigation
- `public/js/home-animations.js` - Homepage animations

### Database Tables Used
- `facilities` - Facility data
- `announcements` - Public announcements

### User Roles Allowed
- Public (no authentication required)

### Main Workflows
1. **Browse Facilities**: Public users view facility catalog
2. **View Details**: Public users view facility information
3. **Read Announcements**: Public users view system announcements
4. **Contact**: Public users submit contact form

### Entry Points
- `/` - Home page
- `/facilities` - Facilities listing

### Exit Points
- External links, contact form submission

### Dependencies
- `guest_layout.php` - Public layout

### Future Extension Points
- Multi-language support
- Facility search
- Booking preview for public

---

## API Endpoints

### Purpose
JSON API endpoints for AJAX requests and external integrations.

### Endpoints
- `/api/public/availability` - Public availability API
- `/dashboard/ai-chatbot` - Chatbot API
- `/dashboard/session-keepalive` - Session keepalive
- `/dashboard/ai-recommendations-api` - AI recommendations
- `/dashboard/ai-conflict-check` - Conflict detection
- `/dashboard/notifications-api` - Notifications
- `/dashboard/occupancy-live` - Live occupancy
- `/dashboard/geocode-api` - Geocoding
- `/dashboard/booking-smart-hints` - Smart hints
- `/dashboard/calendar-availability` - Calendar availability
- `/paymongo-webhook` - Payment webhook
- `/contact-handler` - Contact form handler

### PHP Files Involved
- `resources/views/pages/public/api/availability.php`
- `resources/views/pages/dashboard/ai_chatbot.php`
- `resources/views/pages/dashboard/session_keepalive.php`
- `resources/views/pages/dashboard/ai_recommendations_api.php`
- `resources/views/pages/dashboard/ai_conflict_check.php`
- `resources/views/pages/dashboard/notifications_api.php`
- `resources/views/pages/dashboard/occupancy_live_api.php`
- `resources/views/pages/dashboard/geocode_api.php`
- `resources/views/pages/dashboard/booking_smart_hints_api.php`
- `resources/views/pages/dashboard/api/availability_api.php`
- `resources/views/pages/public/api/paymongo_webhook.php`
- `resources/views/pages/public/contact_handler.php`

### JavaScript Files Involved
- `public/js/dashboard-navigation.js` - AJAX navigation
- `public/js/main.js` - Global AJAX handlers

### Database Tables Used
- Varies by endpoint

### User Roles Allowed
- Public APIs: No authentication
- Dashboard APIs: Authenticated users

### Main Workflows
- AJAX requests from dashboard
- Webhook callbacks
- Public API calls

### Entry Points
- Various API endpoints

### Exit Points
- JSON responses

### Dependencies
- Module-specific config files

### Future Extension Points
- API versioning
- Rate limiting per endpoint
- API documentation (Swagger)
- API keys for external access

---

## Scheduled Tasks (Cron Jobs)

### Purpose
Background jobs for maintenance, reminders, and data cleanup.

### Scripts
- `scripts/auto_decline_expired.php` - Decline expired reservations
- `scripts/send_booking_reminders.php` - Send booking reminders
- `scripts/process_expired_reservations.php` - Clean up expired reservations
- `scripts/archive_documents.php` - Archive old documents
- `scripts/cleanup_old_data.php` - Clean up old data
- `scripts/sync_cimm_maintenance.php` - Sync CIMM maintenance
- `scripts/process_operational_occupancy.php` - Process occupancy data
- `scripts/attendance_reminders.php` - Send attendance reminders

### PHP Files Involved
- All scripts in `scripts/` directory

### Database Tables Used
- Varies by script

### User Roles Allowed
- System (cron execution)

### Main Workflows
- Automated maintenance tasks
- Scheduled notifications
- Data cleanup

### Entry Points
- Cron job execution

### Exit Points
- Script completion

### Dependencies
- Module-specific config files

### Future Extension Points
- Job queue system
- Job scheduling UI
- Job failure notifications
