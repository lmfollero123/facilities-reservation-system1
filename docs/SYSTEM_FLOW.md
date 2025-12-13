# Facilities Reservation System - Complete System Flow

## Overview
This document describes the complete flow of the Public Facilities Reservation System, from user registration to reservation approval and management.

---

## 1. User Registration & Authentication Flow

### 1.1 New User Registration
```
Public User → Register Page → Fill Form → Submit
    ↓
System validates input
    ↓
Password hashed with password_hash()
    ↓
User saved to `users` table with:
    - role: 'Resident' (default)
    - status: 'pending' (requires Admin/Staff approval)
    ↓
Success message displayed
    ↓
User redirected to Login page
```

**Files Involved:**
- `resources/views/pages/auth/register.php`
- `database/schema.sql` (users table)

**Database Action:**
- `INSERT INTO users (name, email, password_hash, role, status) VALUES (...)`

---

### 1.2 User Login
```
User → Login Page → Enter Credentials → Submit
    ↓
System queries `users` table:
    - WHERE email = ? AND status = 'active'
    ↓
Password verified with password_verify()
    ↓
If valid:
    - Session variables set:
        * $_SESSION['user_authenticated'] = true
        * $_SESSION['user_id'] = user.id
        * $_SESSION['user_name'] = user.name
        * $_SESSION['user_email'] = user.email
        * $_SESSION['role'] = user.role
    ↓
Redirect to Dashboard
```

**Files Involved:**
- `resources/views/pages/auth/login.php`
- `config/database.php`

**Database Action:**
- `SELECT * FROM users WHERE email = ? AND status = 'active'`

**Access Control:**
- Only users with `status = 'active'` can log in
- `pending` users must wait for Admin/Staff approval

---

### 1.3 User Logout
```
User clicks Logout → logout.php
    ↓
Session destroyed (session_destroy())
    ↓
All session variables unset
    ↓
Redirect to Login page
```

**Files Involved:**
- `resources/views/pages/auth/logout.php`

---

## 2. Reservation Booking Flow (Resident)

### 2.1 Submit Reservation Request
```
Authenticated Resident → Book Facility Page → Fill Form → Submit
    ↓
Form validation:
    - Facility selected
    - Date selected
    - Time slot selected
    - Purpose provided
    - User ID from session
    ↓
INSERT INTO reservations:
    - user_id: $_SESSION['user_id']
    - facility_id: selected facility
    - reservation_date: selected date
    - time_slot: selected slot
    - purpose: provided text
    - status: 'pending' (default)
    ↓
Success message displayed
    ↓
"My Recent Reservations" card updated (last 5)
```

**Files Involved:**
- `resources/views/pages/dashboard/book_facility.php`
- `database/schema.sql` (reservations table)

**Database Action:**
- `INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, status) VALUES (...)`

**Status:** `pending` (awaiting Admin/Staff review)

---

### 2.2 View My Reservations
```
Authenticated Resident → My Reservations Page
    ↓
Query reservations WHERE user_id = $_SESSION['user_id']
    ↓
For each reservation:
    - Join with facilities table (get facility name)
    - Query reservation_history for status timeline
    ↓
Display:
    - Facility name
    - Reservation date & time slot
    - Current status badge
    - Status history timeline (if exists)
    ↓
Pagination (5 per page)
```

**Files Involved:**
- `resources/views/pages/dashboard/my_reservations.php`

**Database Actions:**
- `SELECT r.*, f.name FROM reservations r JOIN facilities f ... WHERE r.user_id = ?`
- `SELECT * FROM reservation_history WHERE reservation_id = ? ORDER BY created_at DESC`

---

## 3. Reservation Approval Flow (Admin/Staff)

### 3.1 View Pending Reservations
```
Admin/Staff → Reservation Approvals Page
    ↓
Role check: Must be 'Admin' or 'Staff'
    ↓
Query reservations WHERE status = 'pending'
    - Join with facilities (get facility name)
    - Join with users (get requester name)
    ↓
Display in "Pending Requests" table:
    - Requester name
    - Facility name
    - Schedule (date + time slot)
    - Purpose
    - Action buttons (Approve/Deny) with remarks field
    - "View Details" link
```

**Files Involved:**
- `resources/views/pages/dashboard/reservations_manage.php`

**Database Action:**
- `SELECT r.*, f.name AS facility, u.name AS requester FROM reservations r JOIN facilities f ... JOIN users u ... WHERE r.status = 'pending'`

---

### 3.2 Approve Reservation
```
Admin/Staff → Click "Approve" → Confirmation Modal → Confirm
    ↓
POST request with:
    - reservation_id
    - action: 'approved'
    - note: optional remarks
    ↓
UPDATE reservations:
    - SET status = 'approved'
    - SET updated_at = CURRENT_TIMESTAMP
    ↓
INSERT INTO reservation_history:
    - reservation_id
    - status: 'approved'
    - note: staff remarks (if provided)
    - created_by: $_SESSION['user_id']
    ↓
Success message displayed
    ↓
Page refreshed (reservation removed from pending list)
```

**Files Involved:**
- `resources/views/pages/dashboard/reservations_manage.php`
- `resources/views/pages/dashboard/reservation_detail.php`

**Database Actions:**
- `UPDATE reservations SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?`
- `INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (...)`

**Status Change:** `pending` → `approved`

---

### 3.3 Deny Reservation
```
Admin/Staff → Click "Deny" → Confirmation Modal → Confirm
    ↓
POST request with:
    - reservation_id
    - action: 'denied'
    - note: optional remarks (recommended)
    ↓
UPDATE reservations:
    - SET status = 'denied'
    - SET updated_at = CURRENT_TIMESTAMP
    ↓
INSERT INTO reservation_history:
    - reservation_id
    - status: 'denied'
    - note: denial reason (if provided)
    - created_by: $_SESSION['user_id']
    ↓
Success message displayed
    ↓
Page refreshed (reservation removed from pending list)
```

**Files Involved:**
- `resources/views/pages/dashboard/reservations_manage.php`
- `resources/views/pages/dashboard/reservation_detail.php`

**Database Actions:**
- `UPDATE reservations SET status = 'denied', updated_at = CURRENT_TIMESTAMP WHERE id = ?`
- `INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (...)`

**Status Change:** `pending` → `denied`

---

### 3.4 View Reservation Details
```
Admin/Staff → Click "View Details" → Reservation Detail Page
    ↓
Query reservation with full details:
    - Join with users (requester info)
    - Join with facilities (facility info)
    ↓
Query reservation_history for complete timeline
    ↓
Display comprehensive view:
    - Reservation Information (ID, status, date, time, purpose)
    - Requester Information (name, email, role)
    - Facility Information (name, description, rate, status)
    - Action buttons (if status = 'pending')
    - Status History Timeline (all status changes with notes)
    ↓
If pending: Show Approve/Deny form with remarks field
```

**Files Involved:**
- `resources/views/pages/dashboard/reservation_detail.php`

**Database Actions:**
- `SELECT r.*, u.*, f.* FROM reservations r JOIN users u ... JOIN facilities f ... WHERE r.id = ?`
- `SELECT rh.*, u.name AS created_by_name FROM reservation_history rh LEFT JOIN users u ... WHERE rh.reservation_id = ? ORDER BY rh.created_at DESC`

---

### 3.5 View Recent Activity
```
Admin/Staff → Reservation Approvals Page → Scroll to "Recent Activity"
    ↓
Query all reservations (ordered by updated_at DESC)
    - Join with facilities (get facility name)
    - Join with users (get requester name)
    ↓
For each reservation:
    - Query reservation_history for timeline
    ↓
Display cards with:
    - Facility name
    - Schedule
    - Current status badge
    - Requester name
    - Status timeline
    - "View Details" link
    ↓
Pagination (5 per page)
```

**Files Involved:**
- `resources/views/pages/dashboard/reservations_manage.php`

**Database Actions:**
- `SELECT r.*, f.name AS facility, u.name AS requester FROM reservations r JOIN facilities f ... JOIN users u ... ORDER BY r.updated_at DESC LIMIT ? OFFSET ?`
- `SELECT * FROM reservation_history WHERE reservation_id = ? ORDER BY created_at DESC`

---

## 4. Facility Management Flow (Admin/Staff)

### 4.1 View Facilities
```
Admin/Staff → Facility Management Page
    ↓
Role check: Must be 'Admin' or 'Staff'
    ↓
Query all facilities from `facilities` table
    ↓
Display in table:
    - Facility name
    - Description
    - Base rate
    - Status badge
    - Actions (Edit Details button)
    ↓
Pagination (5 per page)
```

**Files Involved:**
- `resources/views/pages/dashboard/facility_management.php`

**Database Action:**
- `SELECT * FROM facilities ORDER BY name LIMIT ? OFFSET ?`

---

### 4.2 Add New Facility
```
Admin/Staff → Facility Management → Fill "Add Facility" Form → Submit
    ↓
Form validation:
    - Name required
    - Description optional
    - Base rate optional
    - Status defaults to 'available'
    ↓
INSERT INTO facilities:
    - name
    - description
    - base_rate
    - status: 'available' (default)
    ↓
Success message displayed
    ↓
Facility list refreshed
```

**Files Involved:**
- `resources/views/pages/dashboard/facility_management.php`

**Database Action:**
- `INSERT INTO facilities (name, description, base_rate, status) VALUES (...)`

---

### 4.3 Edit Facility
```
Admin/Staff → Click "Edit Details" → Confirmation Modal → Confirm
    ↓
JavaScript populates form with facility data:
    - Facility name
    - Description
    - Base rate
    - Status
    ↓
User modifies fields → Submit
    ↓
UPDATE facilities:
    - SET name = ?
    - SET description = ?
    - SET base_rate = ?
    - SET status = ?
    - SET updated_at = CURRENT_TIMESTAMP
    - WHERE id = ?
    ↓
Success message displayed
    ↓
Facility list refreshed
```

**Files Involved:**
- `resources/views/pages/dashboard/facility_management.php`

**Database Action:**
- `UPDATE facilities SET name = ?, description = ?, base_rate = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`

---

## 5. User Management Flow (Admin/Staff)

### 5.1 View Users
```
Admin/Staff → User Management Page
    ↓
Role check: Must be 'Admin' or 'Staff'
    ↓
Query all users from `users` table
    ↓
Display in table:
    - Name
    - Email
    - Role badge
    - Status badge
    - Actions (Approve/Deny/Lock buttons)
    ↓
Pagination (5 per page)
```

**Files Involved:**
- `resources/views/pages/dashboard/user_management.php`

**Database Action:**
- `SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?`

---

### 5.2 Approve User Account
```
Admin/Staff → User Management → Click "Approve" → Confirmation Modal → Confirm
    ↓
UPDATE users:
    - SET status = 'active'
    - SET updated_at = CURRENT_TIMESTAMP
    - WHERE id = ?
    ↓
User can now log in
```

**Files Involved:**
- `resources/views/pages/dashboard/user_management.php`

**Database Action:**
- `UPDATE users SET status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = ?`

**Status Change:** `pending` → `active`

---

## 6. Status History Timeline Flow

### 6.1 Status Change Recording
```
Any status change (Approve/Deny/Cancel) triggers:
    ↓
1. UPDATE reservations table (set new status)
    ↓
2. INSERT INTO reservation_history:
    - reservation_id: ID of reservation
    - status: new status ('pending', 'approved', 'denied', 'cancelled')
    - note: optional remarks from staff
    - created_by: $_SESSION['user_id'] (who made the change)
    - created_at: CURRENT_TIMESTAMP
```

**Files Involved:**
- `resources/views/pages/dashboard/reservations_manage.php`
- `resources/views/pages/dashboard/reservation_detail.php`

**Database Actions:**
- `UPDATE reservations SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`
- `INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (...)`

---

### 6.2 Displaying Timeline
```
When viewing reservation (Resident or Staff):
    ↓
Query reservation_history WHERE reservation_id = ?
    - ORDER BY created_at DESC (newest first)
    - LEFT JOIN users to get staff name (created_by_name)
    ↓
Display timeline:
    - Status badge
    - Staff note (if provided)
    - Timestamp
    - Staff name (if available)
```

**Files Involved:**
- `resources/views/pages/dashboard/my_reservations.php`
- `resources/views/pages/dashboard/reservation_detail.php`
- `resources/views/pages/dashboard/reservations_manage.php`

**Database Action:**
- `SELECT rh.*, u.name AS created_by_name FROM reservation_history rh LEFT JOIN users u ON rh.created_by = u.id WHERE rh.reservation_id = ? ORDER BY rh.created_at DESC`

---

## 7. Access Control Flow

### 7.1 Page Access Protection
```
User navigates to any dashboard page
    ↓
Page checks session:
    - $_SESSION['user_authenticated'] === true?
    ↓
If NO:
    - Redirect to login page
    ↓
If YES:
    - Check role (if page requires Admin/Staff)
    - If role check fails: Redirect to dashboard index
    - If role check passes: Display page
```

**Files Involved:**
- `resources/views/layouts/dashboard_layout.php` (session check)
- Individual pages (role checks)

**Protected Pages:**
- **All Dashboard Pages**: Require authentication
- **Admin/Staff Only**: 
  - Facility Management
  - Reservation Approvals
  - User Management
  - Audit Trail
  - Reports
  - AI Scheduling

---

## 8. Database Schema Relationships

```
users (1) ──< (many) reservations
    │
    └──< (many) reservation_history (created_by)

facilities (1) ──< (many) reservations

reservations (1) ──< (many) reservation_history
```

**Foreign Keys:**
- `reservations.user_id` → `users.id`
- `reservations.facility_id` → `facilities.id`
- `reservation_history.reservation_id` → `reservations.id` (ON DELETE CASCADE)
- `reservation_history.created_by` → `users.id`

---

## 9. Status Flow Diagram

### Reservation Status Flow
```
[pending] ──(Approve)──> [approved]
    │
    └──(Deny)──> [denied]

[approved] ──(Cancel)──> [cancelled]
[denied] ──(Cancel)──> [cancelled]
```

### User Status Flow
```
[pending] ──(Admin Approve)──> [active]
    │
    └──(Admin Lock)──> [locked]

[active] ──(Admin Lock)──> [locked]
```

### Facility Status Flow
```
[available] ──(Set Maintenance)──> [maintenance]
    │
    └──(Set Offline)──> [offline]

[maintenance] ──(Set Available)──> [available]
[offline] ──(Set Available)──> [available]
```

---

## 10. Notification Flow (Planned)

### 10.1 Email/SMS Notifications
```
Status Change Event → Notification System
    ↓
Query user email/phone from users table
    ↓
Send notification:
    - Reservation approved → "Your reservation for [Facility] on [Date] has been approved."
    - Reservation denied → "Your reservation for [Facility] on [Date] has been denied. Reason: [Note]"
    - New pending request → "New reservation request from [Requester] for [Facility]"
```

**Current Status:** UI ready, backend integration pending

**Files Involved:**
- `resources/views/components/navbar_dashboard.php` (notification bell)
- `resources/views/pages/dashboard/notifications.php` (full page)

---

---

## 12. Error Handling Flow

### 12.1 Database Errors
```
Database query fails
    ↓
Try-Catch block catches exception
    ↓
Display user-friendly error message
    ↓
Log error (if logging enabled)
    ↓
User can retry or contact support
```

**Error Messages:**
- "Unable to submit reservation. Please try again later."
- "Unable to load facilities right now."
- "Unable to update reservation. Please try again."

---

## 13. Session Management Flow

### 13.1 Session Lifecycle
```
User logs in → Session created
    ↓
Session variables set:
    - user_authenticated
    - user_id
    - user_name
    - user_email
    - role
    ↓
Session persists across page requests
    ↓
User logs out → Session destroyed
    ↓
All session data cleared
```

**Session Configuration:**
- Started with `session_start()` in each page
- Checked in `dashboard_layout.php` for authentication
- Destroyed in `logout.php`

---

## 14. Complete User Journey Example

### Resident Journey
```
1. Register → Account created (status: pending)
2. Wait for Admin approval
3. Admin approves → Status: active
4. Login → Access dashboard
5. Browse facilities (public page)
6. Book facility → Reservation created (status: pending)
7. View "My Reservations" → See pending status
8. Admin reviews → Approves with note
9. Status history updated → Timeline shows approval
10. View "My Reservations" → See approved status + timeline
```

### Admin/Staff Journey
```
1. Login → Access dashboard
2. View "Reservation Approvals" → See pending requests
3. Click "View Details" → See full reservation info
4. Review requester, facility, purpose
5. Approve with note → Status updated, history recorded
6. View "Recent Activity" → See all reservations
7. Manage facilities → Add/Edit facilities
8. Manage users → Approve pending registrations
```

---

## 15. Data Flow Summary

### Input → Processing → Output

**Reservation Booking:**
- **Input**: Form data (facility, date, time, purpose)
- **Processing**: Validation → Database INSERT
- **Output**: Success message, reservation record, updated "My Reservations"

**Reservation Approval:**
- **Input**: Reservation ID, action (approve/deny), optional note
- **Processing**: UPDATE reservation + INSERT history
- **Output**: Status change, history entry, success message

**Facility Management:**
- **Input**: Facility data (name, description, rate, status)
- **Processing**: INSERT or UPDATE facilities table
- **Output**: Updated facility list, success message

---

## Notes

1. **Password Security**: All passwords are hashed using `password_hash()` with `PASSWORD_DEFAULT` algorithm
2. **SQL Injection Prevention**: All queries use prepared statements with parameter binding
3. **XSS Prevention**: All user input is escaped with `htmlspecialchars()` before display
4. **Role-Based Access**: Pages check both authentication and role before rendering
5. **Status History**: Every status change is recorded with timestamp and staff notes
6. **Pagination**: Lists exceeding 5 items are paginated for performance
7. **Confirmation Modals**: Destructive actions (approve/deny/edit) require confirmation
8. **Responsive Design**: All pages work on mobile, tablet, and desktop

---

## Migration Notes

If you encounter the error: `Table 'facilities_reservation.reservation_history' doesn't exist`

**Solution:**
1. Run the migration file: `database/migration_add_reservation_history.sql`
2. Or run the full schema: `database/schema.sql` (includes all tables)

The `reservation_history` table is required for:
- Status timeline display
- Tracking who approved/denied reservations
- Storing staff notes with status changes

