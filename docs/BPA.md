# Business Process Architecture (BPA) – Level 1, 2 & 3

## Level 1 – End‑to‑End LGU Facilities Reservation Process

**Scope**: From resident awareness and registration up to booking, approval, facility use, and post‑event archiving/analytics.

**Performance Optimizations (Jan 2025):** Conflict detection and AI recommendations have been optimized for faster response times (~60% faster conflict detection, ~70% fewer API calls, database indexes for query optimization). See `docs/PERFORMANCE_OPTIMIZATIONS.md` for details.

1. **Citizen Onboarding**
   - Resident discovers the LGU Facilities Reservation portal.
   - Resident registers with Barangay Culiat address and uploads at least one supporting document (Valid ID, etc.).
   - System validates data, stores user + document metadata, and queues user for approval.
   - Admin/Staff reviews documents and approves/locks account; system sends email/notification.

2. **Authentication & Account Access**
   - Resident logs in with email/password.
   - System enforces OTP on login, rate limiting, and lockout for suspicious activity.
   - Successful login creates a secure session and shows the resident dashboard.

3. **Facility Discovery & Planning**
   - Resident browses public facility listings and details (capacity, amenities, rules, citations).
   - Resident checks availability via calendar and AI conflict/schedule hints (holiday/event risk).
   - (Planned) Resident can consult AI Chatbot for "which facility fits my event?" and policy questions.

4. **Reservation Request**
   - Resident opens **Book a Facility** and selects facility, date, **start/end time**, purpose, expected attendees, and commercial flag.
   - System enforces booking limits (≤3 active/30 days, ≤60‑day advance, ≤1 per day) and validates time ranges/duration.
   - System performs conflict detection and shows AI recommendations/alternative slots if needed.

5. **Auto‑Approval & Staff Review**
   - System evaluates **8 auto‑approval conditions** (facility flag, blackout dates, duration, capacity, commercial flag, conflicts, user violations, advance window).
   - If all pass → reservation is **auto‑approved** (status `approved`, `auto_approved=true`); otherwise → status `pending` for staff review.
   - Admin/Staff can always override: approve/deny/modify/postpone/cancel with reasons, and record violations.

6. **Resident Self‑Service**
   - Resident views reservations (list + calendar), including statuses and history.
   - Resident can **reschedule** their own reservations (one time only, up to 3 days before event, not into the past); approved bookings go back to `pending`.
   - Notifications (in‑app + email) keep residents informed of all changes.

7. **Facility Use & Policy Enforcement**
   - On event date, resident uses the facility under LGU rules.
   - Staff can record **user violations** (no‑show, late cancellation, policy breach, damage) with severity; high/critical violations affect future auto‑approval.

8. **Post‑Event Analytics & Archival**
   - System updates reservation history and audit logs.
   - Admin/Staff review reports (usage, approvals vs denials, violations, blackout impact).
   - Background jobs auto‑decline expired pending reservations, archive old documents/records based on retention policies, and optimize the database.
   - Residents can export their own data (JSON + readable report that can be printed/saved as PDF).

---

## Level 2 – Decomposed Core Processes

### Process 1: Citizen Onboarding (Registration & Approval)

**Sub‑Processes:**
- 1.1 **Capture Registration Data**
- 1.2 **Upload Supporting Documents**
- 1.3 **System Validation**
- 1.4 **Administrative Review**
- 1.5 **Communication & Audit**

### Process 2: Authentication & Account Access

**Sub‑Processes:**
- 2.1 **Credential Validation**
- 2.2 **OTP Generation & Verification**
- 2.3 **Security Checks (Rate Limiting & Lockout)**
- 2.4 **Session Creation & Dashboard Access**

### Process 3: Facility Discovery & Planning

**Sub‑Processes:**
- 3.1 **Browse Public Facility Listings**
- 3.2 **View Facility Details**
- 3.3 **Check Calendar Availability**
- 3.4 **AI Conflict Detection & Recommendations**

### Process 4: Reservation Request

**Sub‑Processes:**
- 4.1 **Collect Booking Details**
- 4.2 **Validate Limits & Time Window**
- 4.3 **Conflict Detection & AI Assist**
- 4.4 **Submit Reservation Request**

### Process 5: Auto‑Approval & Staff Review

**Sub‑Processes:**
- 5.1 **Auto‑Approval Evaluation**
- 5.2 **Reservation Status Assignment**
- 5.3 **Staff Review & Override**
- 5.4 **Notification Dispatch**

### Process 6: Resident Self‑Service

**Sub‑Processes:**
- 6.1 **View Reservations**
- 6.2 **Reschedule Reservation**
- 6.3 **View Reservation History**

### Process 7: Facility Use & Policy Enforcement

**Sub‑Processes:**
- 7.1 **Facility Access Verification**
- 7.2 **Violation Recording**
- 7.3 **Violation Impact Assessment**

### Process 8: Post‑Event Analytics & Archival

**Sub‑Processes:**
- 8.1 **Reservation History Update**
- 8.2 **Report Generation**
- 8.3 **Data Export**
- 8.4 **Document Archival**
- 8.5 **Background Maintenance Jobs**

### Process 9: Public Announcements Management

**Sub‑Processes:**
- 9.1 **Create Public Announcement** (Admin/Staff)
- 9.2 **View Announcements Archive** (Public)
- 9.3 **Search and Filter Announcements** (Public)
- 9.4 **Delete Announcement** (Admin/Staff)

### Process 10: Contact Information Management

**Sub‑Processes:**
- 10.1 **Update Contact Information** (Admin/Staff)
- 10.2 **View Public Contact Page** (Public)
- 10.3 **Contact Information Audit**

---

## Level 3 – Detailed Activities

### Process 1: Citizen Onboarding (Registration & Approval)

#### 1.1 Capture Registration Data

**Activities:**
- 1.1.1 Resident accesses registration page
- 1.1.2 Resident enters name (first name, last name)
- 1.1.3 Resident enters email address
- 1.1.4 Resident creates password (minimum 8 characters)
- 1.1.5 Resident confirms password
- 1.1.6 Resident enters mobile number (optional)
- 1.1.7 Resident enters complete address (must include "Barangay Culiat")
- 1.1.8 System validates email format
- 1.1.9 System checks email uniqueness
- 1.1.10 System validates password meets policy (length, complexity)
- 1.1.11 System validates password confirmation matches

#### 1.2 Upload Supporting Documents

**Activities:**
- 1.2.1 Resident selects document type (Valid ID, Birth Certificate, Barangay ID, Resident ID)
- 1.2.2 Resident uploads document file (JPG, PNG, PDF, max 5MB)
- 1.2.3 System validates file type
- 1.2.4 System validates file size
- 1.2.5 System generates unique filename
- 1.2.6 System stores file in user-scoped directory (`public/uploads/documents/{userId}/`)
- 1.2.7 System creates document metadata entry in `user_documents` table
- 1.2.8 System verifies at least one document uploaded

#### 1.3 System Validation

**Activities:**
- 1.3.1 System validates all required fields are present
- 1.3.2 System validates email format (regex validation)
- 1.3.3 System checks if email already exists in database
- 1.3.4 System validates password meets security requirements
- 1.3.5 System validates address contains "Barangay Culiat" (case-insensitive)
- 1.3.6 System validates document file type (whitelist: JPG, PNG, PDF)
- 1.3.7 System validates document file size (max 5MB)
- 1.3.8 System creates user record in `users` table with `status='pending'`
- 1.3.9 System links uploaded documents to user via `user_documents` table
- 1.3.10 System generates user ID
- 1.3.11 System hashes password (bcrypt)
- 1.3.12 System sets default role as 'Resident'
- 1.3.13 System sets `created_at` timestamp

#### 1.4 Administrative Review

**Activities:**
- 1.4.1 Admin/Staff logs into dashboard
- 1.4.2 Admin/Staff accesses User Management page
- 1.4.3 System displays pending users queue
- 1.4.4 Admin/Staff views user details (name, email, address, contact)
- 1.4.5 Admin/Staff reviews uploaded documents
- 1.4.6 Admin/Staff verifies document authenticity and completeness
- 1.4.7 Admin/Staff makes decision: Approve, Lock, or Request More Info
- 1.4.8 If Lock: Admin/Staff enters lock reason
- 1.4.9 System updates user status in `users` table
- 1.4.10 If Approved: System sets `status='approved'` and `is_verified=true`
- 1.4.11 If Locked: System sets `status='locked'` and stores `lock_reason`
- 1.4.12 System records reviewer ID and timestamp

#### 1.5 Communication & Audit

**Activities:**
- 1.5.1 System triggers email notification based on decision
- 1.5.2 If Approved: System sends approval email to user
- 1.5.3 If Locked: System sends lock notification email with reason
- 1.5.4 System creates in-app notification for user
- 1.5.5 System creates audit log entry in `audit_log` table
- 1.5.6 Audit log records: action type, user ID, reviewer ID, timestamp, decision
- 1.5.7 System stores notification in `notifications` table

---

### Process 2: Authentication & Account Access

#### 2.1 Credential Validation

**Activities:**
- 2.1.1 Resident accesses login page
- 2.1.2 Resident enters email address
- 2.1.3 Resident enters password
- 2.1.4 Resident clicks "Login" button
- 2.1.5 System validates email format
- 2.1.6 System queries `users` table for email
- 2.1.7 System verifies user exists
- 2.1.8 System verifies user status is 'approved' (not locked/pending)
- 2.1.9 System hashes provided password
- 2.1.10 System compares hashed password with stored hash (bcrypt verify)
- 2.1.11 System verifies password matches

#### 2.2 OTP Generation & Verification

**Activities:**
- 2.2.1 System generates 6-digit OTP code
- 2.2.2 System stores OTP in `login_otp` table with user ID
- 2.2.3 System sets OTP expiration (10 minutes from generation)
- 2.2.4 System sends OTP via email using SMTP
- 2.2.5 System displays OTP input page
- 2.2.6 Resident enters OTP code
- 2.2.7 System queries `login_otp` table for valid OTP
- 2.2.8 System verifies OTP code matches
- 2.2.9 System verifies OTP not expired
- 2.2.10 System marks OTP as used
- 2.2.11 System deletes or invalidates OTP record

#### 2.3 Security Checks (Rate Limiting & Lockout)

**Activities:**
- 2.3.1 System checks login attempt history from `security_logs` table
- 2.3.2 System counts failed login attempts in last 15 minutes
- 2.3.3 If failed attempts ≥ 5: System blocks login attempt
- 2.3.4 System records failed login attempt with IP address
- 2.3.5 System updates rate limit counter
- 2.3.6 If rate limit exceeded: System displays lockout message
- 2.3.7 System checks if account is locked by admin
- 2.3.8 If account locked: System denies access and displays lock reason

#### 2.4 Session Creation & Dashboard Access

**Activities:**
- 2.4.1 System creates PHP session
- 2.4.2 System stores user ID in session variable
- 2.4.3 System stores user role in session variable
- 2.4.4 System stores user authentication flag (`user_authenticated=true`)
- 2.4.5 System records successful login in `security_logs` table
- 2.4.6 System updates user `last_login` timestamp
- 2.4.7 System redirects to dashboard page
- 2.4.8 Dashboard displays user-specific content based on role

---

### Process 3: Facility Discovery & Planning

#### 3.1 Browse Public Facility Listings

**Activities:**
- 3.1.1 Resident accesses public facilities page (no login required)
- 3.1.2 System queries `facilities` table where `is_public=true`
- 3.1.3 System retrieves facility information (name, type, capacity, location, base_rate)
- 3.1.4 System displays facility cards with images
- 3.1.5 Resident can filter facilities by type (Multipurpose Hall, Sports Complex, etc.)
- 3.1.6 Resident can search facilities by name
- 3.1.7 System applies filters to query
- 3.1.8 System displays filtered results

#### 3.2 View Facility Details

**Activities:**
- 3.2.1 Resident clicks on facility card
- 3.2.2 System queries facility details by ID
- 3.2.3 System retrieves full facility information (description, amenities, rules, images, location)
- 3.2.4 System displays facility details page
- 3.2.5 System shows facility images gallery
- 3.2.6 System displays amenities list
- 3.2.7 System displays facility rules and regulations
- 3.2.8 System displays location on map (if geocoded)
- 3.2.9 System shows base rate and capacity information

#### 3.3 Check Calendar Availability

**Activities:**
- 3.3.1 Resident selects facility on booking page (if logged in)
- 3.3.2 System queries `reservations` table for facility
- 3.3.3 System retrieves all approved reservations for facility
- 3.3.4 System checks facility status (available, maintenance, offline)
- 3.3.5 System displays calendar view (month/week/day)
- 3.3.6 System marks dates with existing reservations
- 3.3.7 System highlights unavailable dates (maintenance, blackout dates)
- 3.3.8 System shows available time slots for selected date
- 3.3.9 System displays holiday indicators (Philippine holidays, Barangay events)

#### 3.4 AI Conflict Detection & Recommendations

**Activities:**
- 3.4.1 Resident selects facility and tentative date/time
- 3.4.2 System calls AI helper function for conflict detection (OPTIMIZED: combined queries, ~60% faster)
- 3.4.3 System queries overlapping reservations using optimized single query (start_time/end_time conflicts)
- 3.4.4 System checks if date falls on Philippine holidays
- 3.4.5 System checks if date falls on Barangay events (Fiesta, Founding Day)
- 3.4.6 System calculates risk score for date/time (OPTIMIZED: rule-based only, no ML overhead)
- 3.4.7 System generates alternative facility suggestions based on purpose (with timeout fallback)
- 3.4.8 System calculates distance-based recommendations (Haversine formula)
- 3.4.9 System displays conflict warnings (if any) - debounced 500ms for performance
- 3.4.10 System displays recommended alternative facilities with reasons (debounced 1000ms)
- 3.4.11 System shows alternative time slots for same facility

---

### Process 4: Reservation Request

#### 4.1 Collect Booking Details

**Activities:**
- 4.1.1 Resident logs in and accesses "Book a Facility" page
- 4.1.2 Resident selects facility from dropdown
- 4.1.3 Resident selects reservation date (via date picker)
- 4.1.4 Resident selects start time (HH:MM format)
- 4.1.5 Resident selects end time (HH:MM format)
- 4.1.6 Resident enters purpose (text field, required)
- 4.1.7 Resident enters expected attendees (number, optional)
- 4.1.8 Resident checks commercial flag if applicable (checkbox)
- 4.1.9 Resident selects priority level (LGU/Barangay, Community/Org, Private)
- 4.1.10 Resident enters document reference (optional text field)
- 4.1.11 If unverified user: Resident uploads Valid ID document

#### 4.2 Validate Limits & Time Window

**Activities:**
- 4.2.1 System validates date is not in the past
- 4.2.2 System validates date is within advance booking window (≤60 days)
- 4.2.3 System queries user's active reservations (status: pending or approved)
- 4.2.4 System counts reservations within last 30 days
- 4.2.5 System validates user has ≤3 active reservations (limit check)
- 4.2.6 System checks if user already has reservation on same date
- 4.2.7 System validates user has ≤1 booking per day
- 4.2.8 System validates start_time < end_time
- 4.2.9 System calculates duration in hours
- 4.2.10 System validates duration ≥ 0.5 hours (30 minutes minimum)
- 4.2.11 System validates duration ≤ 12 hours (maximum)
- 4.2.12 System queries facility `max_duration_hours` setting
- 4.2.13 System validates duration ≤ facility max_duration_hours
- 4.2.14 If validation fails: System displays specific error message

#### 4.3 Conflict Detection & AI Assist

**Activities:**
- 4.3.1 System queries `reservations` table for facility on selected date (OPTIMIZED: single combined query)
- 4.3.2 System retrieves all approved and pending reservations in one query (OPTIMIZED: ~60% faster)
- 4.3.3 System parses time ranges (start_time - end_time) from reservations in PHP (faster than SQL)
- 4.3.4 System checks if new reservation time overlaps with existing reservations
- 4.3.5 System checks facility status (if maintenance/offline, block booking)
- 4.3.6 System checks if date is in blackout dates for facility
- 4.3.7 System calls AI recommendation function (with 5-second timeout and 3-second quick fallback)
- 4.3.8 AI function analyzes purpose and suggests similar facilities (falls back to rule-based if ML slow)
- 4.3.9 AI function calculates distance scores for recommendations (Haversine formula)
- 4.3.10 System checks holiday/event conflicts (OPTIMIZED: rule-based risk calculation, no ML overhead)
- 4.3.11 If conflict detected: System displays warning message (debounced 500ms)
- 4.3.12 System displays alternative facilities with rankings (debounced 1000ms, skips if date/time missing)
- 4.3.13 System displays alternative time slots if available (only calculated when hard conflict exists)

#### 4.4 Submit Reservation Request

**Activities:**
- 4.4.1 Resident reviews all entered information
- 4.4.2 Resident accepts terms and conditions (checkbox)
- 4.4.3 Resident clicks "Submit Reservation" button
- 4.4.4 System re-validates all inputs (server-side validation)
- 4.4.5 System checks user verification status
- 4.4.6 If unverified and no ID uploaded: System rejects and shows error
- 4.4.7 If all validations pass: System proceeds to auto-approval evaluation

---

### Process 5: Auto‑Approval & Staff Review

#### 5.1 Auto‑Approval Evaluation

**Activities:**
- 5.1.1 System calls `evaluateAutoApproval()` function
- 5.1.2 System checks Condition 1: Facility `auto_approve` flag = true
- 5.1.3 System checks Condition 2: Date not in facility blackout dates
- 5.1.4 System checks Condition 3: Duration ≤ facility `max_duration_hours`
- 5.1.5 System checks Condition 4: Expected attendees ≤ facility `capacity_threshold`
- 5.1.6 System checks Condition 5: `is_commercial` = false (non-commercial only)
- 5.1.7 System checks Condition 6: No time conflicts with existing approved reservations
- 5.1.8 System queries user violations from `user_violations` table
- 5.1.9 System checks Condition 7: User has no high/critical violations
- 5.1.10 System checks Condition 8: Reservation within advance booking window (≤60 days)
- 5.1.11 System evaluates all 8 conditions
- 5.1.12 If all conditions pass: System sets `auto_approved=true`
- 5.1.13 If any condition fails: System sets `auto_approved=false`
- 5.1.14 System generates evaluation result with reasons for each condition

#### 5.2 Reservation Status Assignment

**Activities:**
- 5.2.1 If auto-approved: System sets `status='approved'`
- 5.2.2 If not auto-approved: System sets `status='pending'`
- 5.2.3 System inserts reservation record into `reservations` table
- 5.2.4 System stores: user_id, facility_id, reservation_date, start_time, end_time, purpose, expected_attendees, is_commercial, status, auto_approved flag
- 5.2.5 System generates reservation ID
- 5.2.6 System sets `created_at` timestamp
- 5.2.7 System creates initial reservation history entry in `reservation_history` table
- 5.2.8 System logs audit event in `audit_log` table
- 5.2.9 System stores reservation details in history: status change, timestamp, system as actor

#### 5.3 Staff Review & Override

**Activities:**
- 5.3.1 Admin/Staff logs into dashboard
- 5.3.2 Admin/Staff accesses "Reservation Approvals" page
- 5.3.3 System queries pending reservations (status='pending')
- 5.3.4 System displays reservation list with filters (date range, facility, status)
- 5.3.5 Admin/Staff views reservation details (user info, facility, date/time, purpose)
- 5.3.6 Admin/Staff reviews auto-approval evaluation results
- 5.3.7 Admin/Staff selects action: Approve, Deny, Modify, Postpone, Cancel
- 5.3.8 If Approve: System updates `status='approved'`, records staff ID
- 5.3.9 If Deny: System updates `status='denied'`, requires denial reason
- 5.3.10 If Modify: System updates reservation details (date/time/facility), sets status to pending
- 5.3.11 If Postpone: System updates `status='postponed'`, sets `postponed_priority=true`
- 5.3.12 If Cancel: System updates `status='cancelled'`
- 5.3.13 System creates reservation history entry for each action
- 5.3.14 System logs audit event with staff ID and action details

#### 5.4 Notification Dispatch

**Activities:**
- 5.4.1 System triggers notification based on reservation status
- 5.4.2 If auto-approved: System creates "Reservation Approved" notification
- 5.4.3 If pending: System creates "Reservation Submitted" notification
- 5.4.4 System creates in-app notification in `notifications` table
- 5.4.5 System sends email notification via SMTP
- 5.4.6 Email includes: reservation details, status, facility information
- 5.4.7 If staff action: System sends notification with staff decision and reason
- 5.4.8 System marks notification as unread for user

---

### Process 6: Resident Self‑Service

#### 6.1 View Reservations

**Activities:**
- 6.1.1 Resident accesses "My Reservations" page
- 6.1.2 System queries user's reservations from `reservations` table
- 6.1.3 System filters by user_id from session
- 6.1.4 System retrieves reservation details with facility information (JOIN)
- 6.1.5 System displays reservations in list view
- 6.1.6 System shows: facility name, date, time, status, purpose
- 6.1.7 System displays status badges (Pending, Approved, Denied, Cancelled, Postponed)
- 6.1.8 Resident can filter by status
- 6.1.9 Resident can view calendar view of reservations
- 6.1.10 Resident clicks on reservation to view details
- 6.1.11 System displays full reservation information
- 6.1.12 System displays reservation history timeline

#### 6.2 Reschedule Reservation

**Activities:**
- 6.2.1 Resident selects reservation from "My Reservations"
- 6.2.2 System checks reservation status (only approved/pending can be rescheduled)
- 6.2.3 System checks if already rescheduled (`reschedule_count >= 1`)
- 6.2.4 System checks if event is at least 3 days in future
- 6.2.5 System checks if reservation is not in the past
- 6.2.6 If valid: System displays reschedule form
- 6.2.7 Resident selects new date
- 6.2.8 Resident selects new start time
- 6.2.9 Resident selects new end time
- 6.2.10 System validates new date/time against booking limits
- 6.2.11 System validates new date/time against conflicts
- 6.2.12 System validates new date is within 3 days before original date
- 6.2.13 Resident submits reschedule request
- 6.2.14 System updates reservation with new date/time
- 6.2.15 System increments `reschedule_count`
- 6.2.16 If originally approved: System sets `status='pending'` for re-approval
- 6.2.17 System creates reservation history entry
- 6.2.18 System sends notification to user and staff

#### 6.3 View Reservation History

**Activities:**
- 6.3.1 Resident views reservation details page
- 6.3.2 System queries `reservation_history` table for reservation ID
- 6.3.3 System retrieves all status changes and modifications
- 6.3.4 System displays timeline of events
- 6.3.5 System shows: status changes, timestamps, actors (system/staff), comments

---

### Process 7: Facility Use & Policy Enforcement

#### 7.1 Facility Access Verification

**Activities:**
- 7.1.1 Resident arrives at facility on reservation date
- 7.1.2 Staff verifies resident identity
- 7.1.3 Staff checks reservation in system (date, time, facility match)
- 7.1.4 Staff verifies reservation status is 'approved'
- 7.1.5 Staff grants facility access
- 7.1.6 Resident uses facility within reserved time slot

#### 7.2 Violation Recording

**Activities:**
- 7.2.1 Staff identifies violation (no-show, late cancellation, policy breach, damage)
- 7.2.2 Staff accesses reservation detail page
- 7.2.3 Staff clicks "Record Violation" button
- 7.2.4 Staff selects violation type from dropdown
- 7.2.5 Staff selects severity level (Low, Medium, High, Critical)
- 7.2.6 Staff enters violation description (text field)
- 7.2.7 Staff submits violation record
- 7.2.8 System creates violation record in `user_violations` table
- 7.2.9 System links violation to reservation ID and user ID
- 7.2.10 System stores violation type, severity, description, timestamp
- 7.2.11 System logs audit event

#### 7.3 Violation Impact Assessment

**Activities:**
- 7.3.1 System queries user's violations from `user_violations` table
- 7.3.2 System counts violations by severity level
- 7.3.3 System identifies high/critical violations
- 7.3.4 If user has high/critical violations: System flags user for manual review
- 7.3.5 System updates violation count in user profile
- 7.3.6 During auto-approval: System checks violations and denies if high/critical
- 7.3.7 System sends notification to user about violation

---

### Process 8: Post‑Event Analytics & Archival

#### 8.1 Reservation History Update

**Activities:**
- 8.1.1 System updates reservation status to 'completed' after event date
- 8.1.2 System creates final history entry
- 8.1.3 System records event completion timestamp
- 8.1.4 System maintains audit trail of all changes

#### 8.2 Report Generation

**Activities:**
- 8.2.1 Admin/Staff accesses "Reports & Analytics" page
- 8.2.2 Admin/Staff selects report type (Usage, Approvals, Violations)
- 8.2.3 Admin/Staff selects date range
- 8.2.4 Admin/Staff selects facility filter (optional)
- 8.2.5 System queries reservation data based on filters
- 8.2.6 System calculates statistics (total bookings, approval rate, denial rate)
- 8.2.7 System generates charts (Monthly trends, Status breakdown, Top facilities)
- 8.2.8 System displays report with visualizations
- 8.2.9 Admin/Staff can export report as PDF
- 8.2.10 Admin/Staff can export data as CSV

#### 8.3 Data Export

**Activities:**
- 8.3.1 Resident accesses Profile page
- 8.3.2 Resident clicks "Export My Data" button
- 8.3.3 Resident selects export type (Full, Profile Only, Reservations Only, Documents Only)
- 8.3.4 Resident submits export request
- 8.3.5 System queries user data based on selection
- 8.3.6 System generates JSON export file
- 8.3.7 System stores export in `data_exports` table
- 8.3.8 System generates readable HTML report
- 8.3.9 System displays export preview
- 8.3.10 Resident can download JSON file
- 8.3.11 Resident can print/save HTML report as PDF

#### 8.4 Document Archival

**Activities:**
- 8.4.1 Scheduled job runs nightly (cron task)
- 8.4.2 System queries `user_documents` table
- 8.4.3 System identifies documents past retention threshold (e.g., 2 years)
- 8.4.4 System checks if documents already archived
- 8.4.5 System moves document files to archive storage (`storage/archive/documents/`)
- 8.4.6 System updates `user_documents` table with archive path
- 8.4.7 System sets archival flag in database
- 8.4.8 System records archival timestamp
- 8.4.9 System logs archival action in audit log

#### 8.5 Background Maintenance Jobs

**Activities:**
- 8.5.1 Scheduled job runs auto-decline script
- 8.5.2 System queries pending reservations older than 7 days
- 8.5.3 System checks if reservation date has passed
- 8.5.4 System updates expired reservations to `status='denied'`
- 8.5.5 System creates history entry with auto-decline reason
- 8.5.6 System sends notification to user
- 8.5.7 System cleans up expired password reset tokens
- 8.5.8 System cleans up old rate limit records
- 8.5.9 System optimizes database tables
- 8.5.10 System archives old audit logs (if configured)

---

### Process 9: Public Announcements Management

#### 9.1 Create Public Announcement (Admin/Staff)

**Activities:**
- 9.1.1 Admin/Staff logs into dashboard
- 9.1.2 Admin/Staff accesses "Announcements Management" page
- 9.1.3 Admin/Staff clicks "Create Announcement" button
- 9.1.4 Admin/Staff enters announcement title (max 200 characters)
- 9.1.5 Admin/Staff enters announcement message (2-4 sentences recommended)
- 9.1.6 Admin/Staff selects category (Emergency, Events, Health, Deadlines, Advisory, General)
- 9.1.7 Admin/Staff optionally uploads image (JPG, PNG, GIF, WebP, max 5MB)
- 9.1.8 Admin/Staff optionally adds external link
- 9.1.9 System validates required fields (title, message)
- 9.1.10 System validates image file type and size if provided
- 9.1.11 System stores image in `public/img/announcements/` directory
- 9.1.12 System creates announcement record in `notifications` table with `user_id=NULL`
- 9.1.13 System sets `type` field based on category
- 9.1.14 System records `created_at` timestamp
- 9.1.15 System creates audit log entry
- 9.1.16 System displays success message

#### 9.2 View Announcements Archive (Public)

**Activities:**
- 9.2.1 Public user accesses `/announcements` page
- 9.2.2 System queries `notifications` table where `user_id IS NULL`
- 9.2.3 System retrieves announcements with pagination (12 per page)
- 9.2.4 System displays announcements in responsive grid (1/2/3 columns)
- 9.2.5 System shows announcement title, message preview, category, date
- 9.2.6 System displays announcement image if available
- 9.2.7 System shows "Read More" link if external link provided
- 9.2.8 System applies color-coded accent bars based on category
- 9.2.9 System displays pagination controls

#### 9.3 Search and Filter Announcements (Public)

**Activities:**
- 9.3.1 Public user enters search query in search box
- 9.3.2 System filters announcements by title or message content (LIKE query)
- 9.3.3 Public user selects sort option (Newest, Oldest)
- 9.3.4 System applies sorting to query results
- 9.3.5 System updates display with filtered/sorted results
- 9.3.6 System maintains pagination for filtered results

#### 9.4 Delete Announcement (Admin/Staff)

**Activities:**
- 9.4.1 Admin/Staff views announcements list in management page
- 9.4.2 Admin/Staff clicks "Delete" button for specific announcement
- 9.4.3 System confirms deletion action
- 9.4.4 System deletes announcement record from `notifications` table
- 9.4.5 System optionally removes associated image file
- 9.4.6 System creates audit log entry
- 9.4.7 System displays success message

---

### Process 10: Contact Information Management

#### 10.1 Update Contact Information (Admin/Staff)

**Activities:**
- 10.1.1 Admin/Staff logs into dashboard
- 10.1.2 Admin/Staff accesses "Contact Information Management" page
- 10.1.3 System displays current contact information from `contact_info` table
- 10.1.4 Admin/Staff updates fields: Office Name, Address, Phone, Mobile, Email, Office Hours
- 10.1.5 Admin/Staff submits form with CSRF token
- 10.1.6 System validates CSRF token
- 10.1.7 System sanitizes input data
- 10.1.8 System uses INSERT ... ON DUPLICATE KEY UPDATE for each field
- 10.1.9 System updates `contact_info` table with new values
- 10.1.10 System sets `updated_at` timestamp
- 10.1.11 System creates audit log entry
- 10.1.12 System displays success message
- 10.1.13 Changes are immediately reflected on public contact page

#### 10.2 View Public Contact Page (Public)

**Activities:**
- 10.2.1 Public user accesses `/contact` page
- 10.2.2 System queries `contact_info` table ordered by `display_order`
- 10.2.3 System retrieves all contact information fields
- 10.2.4 System displays office name, address, phone, mobile, email, office hours
- 10.2.5 System formats office hours with HTML line breaks if provided
- 10.2.6 System displays contact information in organized layout

#### 10.3 Contact Information Audit

**Activities:**
- 10.3.1 System records all contact information updates in `audit_log` table
- 10.3.2 Audit log includes: action type, user ID, fields updated, timestamp
- 10.3.3 Admin/Staff can view audit trail in Audit Trail page
- 10.3.4 System maintains history of contact information changes

---

## Summary

**Level 1:** 10 high-level end-to-end processes covering the complete reservation lifecycle, announcements, and contact management

**Level 2:** 36 sub-processes decomposing Level 1 processes into major functional areas

**Level 3:** 350+ detailed activities breaking down Level 2 sub-processes into specific tasks and system operations

Each level provides increasing detail, from strategic overview (Level 1) to operational activities (Level 3), enabling comprehensive understanding of the business process architecture.

---

## External & Planned Integrations

- **AI Chatbot Provider** – Conversational UI already implemented; future integration will supply real AI answers grounded in system data and FAQs.
- **Maintenance Management, Infrastructure Management, Utilities Billing** – Planned APIs to:
  - Share facility usage metrics and trends.
  - Receive maintenance/project schedules and auto‑block facilities.
  - Track utility consumption and handle outage alerts.
