# Facilities Reservation System - Work Flow Diagram (WFD)

## Document Information
- **System Name**: Public Facilities Reservation System
- **Document Type**: Work Flow Diagram (WFD)
- **Version**: 1.0
- **Date**: 2024
- **Author**: System Documentation

---

## Table of Contents
1. [Workflow Overview](#1-workflow-overview)
2. [User Registration & Approval Workflow](#2-user-registration--approval-workflow)
3. [Reservation Booking Workflow](#3-reservation-booking-workflow)
4. [Reservation Approval Workflow](#4-reservation-approval-workflow)
5. [Facility Management Workflow](#5-facility-management-workflow)
6. [User Management Workflow](#6-user-management-workflow)
7. [Profile Management Workflow](#7-profile-management-workflow)
8. [Auto-Decline Workflow](#8-auto-decline-workflow)
9. [AI Features Workflow](#9-ai-features-workflow)
10. [Workflow Actors & Roles](#10-workflow-actors--roles)

---

## 1. Workflow Overview

### System Actors
- **Resident**: End users who register and book facilities
- **Admin**: System administrators with full access
- **Staff**: LGU/Barangay staff who manage reservations and users
- **System**: Automated processes and validations

### Main Workflows
1. User Registration → Approval → Login
2. Facility Booking → Review → Approval/Denial
3. Facility Management (Add/Edit/Update Status)
4. User Management (Approve/Deny/Lock)
5. Profile Updates
6. Auto-Decline of Expired Reservations
7. AI-Powered Conflict Detection & Recommendations

---

## 2. User Registration & Approval Workflow

```
┌─────────────┐
│   RESIDENT  │
└──────┬──────┘
       │
       │ 1. Access Registration Page
       │
       ▼
┌─────────────────────────────┐
│ Fill Registration Form:     │
│ - Name                      │
│ - Email                     │
│ - Password                  │
│ - Confirm Password          │
│ - Mobile (Optional)          │
└──────┬──────────────────────┘
       │
       │ 2. Upload Documents:
       │    - Birth Certificate
       │    - Valid ID
       │    - Barangay ID
       │    - Other Documents
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Validates Input:            │
│ - Email format              │
│ - Password match            │
│ - Required fields           │
│ - Document file types       │
└──────┬──────────────────────┘
       │
       │ 3. Hash Password
       │
       ▼
┌─────────────────────────────┐
│   DATABASE                  │
│ INSERT INTO users:          │
│ - role: 'Resident'          │
│ - status: 'pending'         │
│ INSERT INTO user_documents  │
└──────┬──────────────────────┘
       │
       │ 4. Account Created
       │    Status: PENDING
       │
       ▼
┌─────────────────────────────┐
│   RESIDENT                  │
│ Receives Success Message    │
│ Redirected to Login Page    │
│ Cannot Login Yet            │
│ (Status: pending)           │
└──────┬──────────────────────┘
       │
       │ 5. Wait for Approval
       │
       ▼
┌─────────────────────────────┐
│   ADMIN/STAFF               │
│ Access User Management      │
│ Views Pending Users         │
│ Reviews Documents           │
└──────┬──────────────────────┘
       │
       │ 6. Decision Point
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│   APPROVE    │      │     DENY     │
│   USER       │      │     USER     │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ 7a. UPDATE users    │ 7b. UPDATE users
       │     status='active' │     status='locked'
       │                     │
       ▼                     ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Creates Notification        │
│ Logs Audit Event            │
└──────┬──────────────────────┘
       │
       │ 8. User Can Now Login
       │
       ▼
┌─────────────────────────────┐
│   RESIDENT                  │
│ Receives Notification       │
│ Can Now Login Successfully  │
└─────────────────────────────┘
```

### Workflow Steps:
1. **Resident** fills registration form and uploads required documents
2. **System** validates all inputs and file types
3. **System** hashes password and saves user with status 'pending'
4. **System** saves uploaded documents to database
5. **Resident** receives confirmation but cannot login yet
6. **Admin/Staff** reviews pending users and documents
7. **Admin/Staff** makes decision:
   - **Approve**: User status → 'active' (can login)
   - **Deny/Lock**: User status → 'locked' (cannot login)
8. **System** creates notification and logs audit event
9. **Resident** receives notification and can login if approved

---

## 3. Reservation Booking Workflow

```
┌─────────────┐
│   RESIDENT  │
│ (Active)    │
└──────┬──────┘
       │
       │ 1. Login to Dashboard
       │
       ▼
┌─────────────────────────────┐
│ Access Book Facility Page   │
│ Views Available Facilities  │
└──────┬──────────────────────┘
       │
       │ 2. Select Facility
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ AI Conflict Detection       │
│ (Real-time)                 │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│   CONFLICT   │      │  NO CONFLICT │
│   DETECTED   │      │              │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ Shows Warning       │
       │ & Alternatives     │
       │                     │
       └──────────┬──────────┘
                  │
                  ▼
┌─────────────────────────────┐
│   RESIDENT                  │
│ Fills Booking Form:         │
│ - Facility (selected)       │
│ - Date (not in past)        │
│ - Time Slot                 │
│ - Purpose                   │
│ - Expected Attendance       │
└──────┬──────────────────────┘
       │
       │ 3. AI Facility Recommendations
       │    (Based on Purpose)
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Shows Recommended           │
│ Facilities                 │
└──────┬──────────────────────┘
       │
       │ 4. Submit Reservation
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Validates:                 │
│ - Date not in past         │
│ - All fields required      │
│ - Facility available       │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│  VALIDATION  │      │  VALIDATION  │
│   FAILED     │      │   PASSED     │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ Show Error          │
       │                     │
       │                     ▼
       │         ┌─────────────────────────────┐
       │         │   DATABASE                  │
       │         │ INSERT INTO reservations:   │
       │         │ - user_id                   │
       │         │ - facility_id               │
       │         │ - reservation_date          │
       │         │ - time_slot                 │
       │         │ - purpose                   │
       │         │ - status: 'pending'         │
       │         └──────┬──────────────────────┘
       │                │
       │                │ 5. Log Audit Event
       │                │
       │                ▼
       │         ┌─────────────────────────────┐
       │         │   RESIDENT                  │
       │         │ Receives Success Message    │
       │         │ Reservation Status: PENDING │
       │         │ "My Reservations" Updated    │
       │         └──────┬──────────────────────┘
       │                │
       │                │ 6. Wait for Admin Review
       │                │
       └────────────────┘
                        │
                        ▼
              ┌─────────────────────────────┐
              │   ADMIN/STAFF                │
              │ Views Pending Reservations   │
              │ (See Reservation Approval    │
              │  Workflow)                   │
              └─────────────────────────────┘
```

### Workflow Steps:
1. **Resident** logs in and accesses booking page
2. **System** performs real-time conflict detection
3. **System** shows AI-powered facility recommendations based on purpose
4. **Resident** fills form and submits
5. **System** validates all inputs (date not in past, required fields)
6. **System** creates reservation with status 'pending'
7. **System** logs audit event and updates "My Reservations"
8. **Resident** receives confirmation and waits for approval
9. **Admin/Staff** reviews and approves/denies (see Reservation Approval Workflow)

---

## 4. Reservation Approval Workflow

```
┌─────────────┐
│ ADMIN/STAFF │
└──────┬──────┘
       │
       │ 1. Access Reservation Approvals
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Auto-Decline Expired        │
│ Pending Reservations        │
│ (Past reservation time)     │
└──────┬──────────────────────┘
       │
       │ 2. Query Pending Reservations
       │
       ▼
┌─────────────────────────────┐
│   ADMIN/STAFF                │
│ Views Pending List:          │
│ - Requester Name             │
│ - Facility Name              │
│ - Date & Time Slot           │
│ - Purpose                    │
│ - Action Buttons             │
└──────┬──────────────────────┘
       │
       │ 3. Review Request
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│  VIEW DETAILS │      │  TAKE ACTION  │
│  (Full Info)  │      │               │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ Shows:             │
       │ - Full Details     │
       │ - History Timeline │
       │ - Requester Info   │
       │                     │
       │                     ├───────────────────────┐
       │                     │                       │
       │                     ▼                       ▼
       │            ┌──────────────┐      ┌──────────────┐
       │            │   APPROVE    │      │     DENY     │
       │            │              │      │              │
       │            └──────┬───────┘      └──────┬───────┘
       │                   │                     │
       │                   │ 4. Enter Optional Note
       │                   │
       │                   ▼
       │         ┌─────────────────────────────┐
       │         │   DATABASE                  │
       │         │ UPDATE reservations:        │
       │         │ - status = 'approved'/'denied'│
       │         │ - updated_at = NOW()        │
       │         │                             │
       │         │ INSERT INTO                 │
       │         │ reservation_history:        │
       │         │ - reservation_id            │
       │         │ - status                    │
       │         │ - note                      │
       │         │ - created_by                │
       │         └──────┬──────────────────────┘
       │                │
       │                │ 5. Log Audit Event
       │                │
       │                ▼
       │         ┌─────────────────────────────┐
       │         │   SYSTEM                    │
       │         │ Creates Notification        │
       │         │ for Requester               │
       │         └──────┬──────────────────────┘
       │                │
       │                │ 6. Update Status
       │                │
       └────────────────┘
                        │
                        ▼
              ┌─────────────────────────────┐
              │   RESIDENT                  │
              │ Receives Notification:      │
              │ - Reservation Approved      │
              │   OR                        │
              │ - Reservation Denied        │
              │ Views Updated Status        │
              │ in "My Reservations"        │
              └─────────────────────────────┘
```

### Workflow Steps:
1. **Admin/Staff** accesses reservation approvals page
2. **System** automatically declines expired pending reservations
3. **Admin/Staff** views list of pending reservations
4. **Admin/Staff** reviews details and makes decision:
   - **Approve**: Reservation status → 'approved'
   - **Deny**: Reservation status → 'denied'
5. **System** updates reservation status and creates history entry
6. **System** logs audit event and creates notification
7. **Resident** receives notification and sees updated status

### Status Transitions:
- `pending` → `approved` (Admin/Staff approves)
- `pending` → `denied` (Admin/Staff denies OR auto-decline)
- `approved` → `cancelled` (User or Admin cancels)
- `denied` → `cancelled` (User cancels)

---

## 5. Facility Management Workflow

```
┌─────────────┐
│ ADMIN/STAFF │
└──────┬──────┘
       │
       │ 1. Access Facility Management
       │
       ▼
┌─────────────────────────────┐
│   ADMIN/STAFF                │
│ Views All Facilities        │
│ - Name                      │
│ - Status                    │
│ - Location                  │
│ - Capacity                  │
│ - Actions (Edit/View)       │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│   ADD NEW    │      │  EDIT EXISTING│
│   FACILITY   │      │   FACILITY    │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │                     │ 2. Click "Edit Details"
       │                     │    Form Populated
       │                     │
       │ 1. Fill Form:       │
       │    - Name           │
       │    - Description    │
       │    - Image          │
       │    - Location       │
       │    - Capacity       │
       │    - Amenities      │
       │    - Rules          │
       │    - Citation       │
       │    - Status         │
       │                     │ 3. Modify Fields
       │                     │
       └──────────┬──────────┘
                  │
                  ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Validates:                 │
│ - Required fields          │
│ - Image size/type          │
│ - File upload              │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│  VALIDATION  │      │  VALIDATION  │
│   FAILED     │      │   PASSED     │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ Show Error          │
       │                     │
       │                     ▼
       │         ┌─────────────────────────────┐
       │         │   SYSTEM                    │
       │         │ Upload Image (if provided)   │
       │         │ Save to public/uploads/     │
       │         └──────┬──────────────────────┘
       │                │
       │                ▼
       │         ┌─────────────────────────────┐
       │         │   DATABASE                  │
       │         │ INSERT INTO facilities      │
       │         │ OR                          │
       │         │ UPDATE facilities           │
       │         │ SET name, description, etc.  │
       │         └──────┬──────────────────────┘
       │                │
       │                │ 4. Log Audit Event
       │                │
       │                ▼
       │         ┌─────────────────────────────┐
       │         │   ADMIN/STAFF               │
       │         │ Receives Success Message   │
       │         │ Facility List Refreshed    │
       │         └─────────────────────────────┘
       │
       └─────────────────────┘
```

### Workflow Steps:
1. **Admin/Staff** accesses facility management page
2. **Admin/Staff** chooses to add new or edit existing facility
3. **Admin/Staff** fills/modifies facility information
4. **System** validates inputs and file uploads
5. **System** uploads image (if provided) and saves to database
6. **System** logs audit event
7. **Admin/Staff** receives confirmation and sees updated list

### Facility Status Management:
- `available` → `maintenance` (Set to maintenance)
- `available` → `offline` (Set to offline)
- `maintenance` → `available` (Restore availability)
- `offline` → `available` (Restore availability)

---

## 6. User Management Workflow

```
┌─────────────┐
│ ADMIN/STAFF │
└──────┬──────┘
       │
       │ 1. Access User Management
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Query All Users             │
│ ORDER BY created_at DESC    │
└──────┬──────────────────────┘
       │
       ▼
┌─────────────────────────────┐
│   ADMIN/STAFF                │
│ Views User List:             │
│ - Name                      │
│ - Email                     │
│ - Role                      │
│ - Status                    │
│ - Actions                   │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│  PENDING     │      │  ACTIVE/LOCKED│
│  USERS       │      │   USERS       │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ 2. View Documents   │
       │    (Button)         │
       │                     │
       ▼                     │
┌─────────────────────────────┐
│   SYSTEM                    │
│ Display Uploaded Documents:│
│ - Birth Certificate         │
│ - Valid ID                  │
│ - Barangay ID               │
│ - Other Documents           │
└──────┬──────────────────────┘
       │
       │ 3. Review Documents
       │
       ▼
┌─────────────────────────────┐
│   ADMIN/STAFF                │
│ Makes Decision:             │
│ - Approve User              │
│ - Deny/Lock User            │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│   APPROVE    │      │  DENY/LOCK   │
│              │      │              │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ 4. UPDATE users     │
       │    status='active'  │
       │                     │
       │                     │ 4. UPDATE users
       │                     │    status='locked'
       │                     │
       ▼                     ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Logs Audit Event            │
│ Creates Notification        │
│ (if approved)               │
└──────┬──────────────────────┘
       │
       │ 5. User Status Updated
       │
       ▼
┌─────────────────────────────┐
│   USER                      │
│ Receives Notification       │
│ (if approved)               │
│ Can Now Login               │
│ (if status='active')        │
└─────────────────────────────┘
```

### Workflow Steps:
1. **Admin/Staff** accesses user management page
2. **System** displays all users with their status
3. **Admin/Staff** reviews pending users and their documents
4. **Admin/Staff** makes decision:
   - **Approve**: User status → 'active' (can login)
   - **Deny/Lock**: User status → 'locked' (cannot login)
5. **System** updates user status, logs audit event, and creates notification
6. **User** receives notification and can login if approved

### User Status Transitions:
- `pending` → `active` (Admin/Staff approves)
- `pending` → `locked` (Admin/Staff denies)
- `active` → `locked` (Admin/Staff locks account)

---

## 7. Profile Management Workflow

```
┌─────────────┐
│   USER      │
│ (Any Role)  │
└──────┬──────┘
       │
       │ 1. Access Profile Page
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Loads Current User Data     │
│ - Name                      │
│ - Email                     │
│ - Mobile                    │
│ - Profile Picture           │
│ - Role                      │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼                       ▼
┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│  UPDATE      │      │  CHANGE     │      │  UPLOAD      │
│  PROFILE     │      │  PASSWORD   │      │  PROFILE     │
│  INFO        │      │              │      │  PICTURE     │
└──────┬───────┘      └──────┬──────┘      └──────┬───────┘
       │                     │                     │
       │ Modify:             │ Enter:              │ Click Camera
       │ - Name              │ - Current Password │ Icon or Select
       │ - Email             │ - New Password     │ File
       │ - Mobile            │ - Confirm Password │
       │                     │                     │
       └──────────┬──────────┴──────────┬──────────┘
                  │                     │
                  ▼                     ▼
         ┌─────────────────────────────┐
         │   SYSTEM                    │
         │ Validates Inputs:           │
         │ - Email format              │
         │ - Password match            │
         │ - Current password correct  │
         │ - Image size/type           │
         └──────┬──────────────────────┘
                │
                ├───────────────────────┐
                │                       │
                ▼                       ▼
        ┌──────────────┐      ┌──────────────┐
        │  VALIDATION  │      │  VALIDATION  │
        │   FAILED     │      │   PASSED     │
        └──────┬───────┘      └──────┬───────┘
               │                     │
               │ Show Error          │
               │                     │
               │                     ▼
               │         ┌─────────────────────────────┐
               │         │   SYSTEM                    │
               │         │ - Hash new password         │
               │         │ - Upload new image          │
               │         │ - Delete old image          │
               │         └──────┬──────────────────────┘
               │                │
               │                ▼
               │         ┌─────────────────────────────┐
               │         │   DATABASE                  │
               │         │ UPDATE users:               │
               │         │ - name                      │
               │         │ - email                     │
               │         │ - mobile                    │
               │         │ - profile_picture           │
               │         │ - password_hash             │
               │         └──────┬──────────────────────┘
               │                │
               │                │ 2. Log Audit Event
               │                │
               │                ▼
               │         ┌─────────────────────────────┐
               │         │   USER                      │
               │         │ Receives Success Message    │
               │         │ Profile Updated             │
               │         │ Avatar Refreshed            │
               │         └─────────────────────────────┘
               │
               └─────────────────────┘
```

### Workflow Steps:
1. **User** accesses profile page
2. **System** loads current user data
3. **User** chooses to update:
   - Profile information (name, email, mobile)
   - Password
   - Profile picture
4. **System** validates all inputs
5. **System** processes updates (hashes password, uploads image)
6. **System** updates database and logs audit event
7. **User** receives confirmation and sees updated profile

---

## 8. Auto-Decline Workflow

```
┌─────────────┐
│   SYSTEM    │
│ (Automated) │
└──────┬──────┘
       │
       │ 1. Trigger Event:
       │    - Page Load (Reservation Approvals)
       │    - Scheduled Task (if implemented)
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Query All Pending           │
│ Reservations                │
│ WHERE status = 'pending'     │
└──────┬──────────────────────┘
       │
       │ 2. Get Current Date & Time
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Loop Through Each           │
│ Pending Reservation         │
└──────┬──────────────────────┘
       │
       │ 3. Check Each Reservation:
       │    - Reservation Date < Today?
       │    - Date = Today AND Time Slot Passed?
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│   EXPIRED    │      │  NOT EXPIRED │
│              │      │              │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │                     │ Skip
       │                     │
       │ 4. UPDATE           │
       │    reservations     │
       │    status='denied'  │
       │                     │
       ▼                     │
┌─────────────────────────────┐
│   DATABASE                  │
│ INSERT INTO                 │
│ reservation_history:        │
│ - status: 'denied'          │
│ - note: 'Auto-declined:     │
│   Past reservation time'    │
└──────┬──────────────────────┘
       │
       │ 5. Log Audit Event
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ Creates Notification        │
│ for User                    │
│ "Reservation Auto-Denied"   │
└──────┬──────────────────────┘
       │
       │ 6. Continue to Next
       │    Reservation
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ All Expired Reservations    │
│ Processed                   │
│ Removed from Pending List   │
└─────────────────────────────┘
```

### Workflow Steps:
1. **System** triggers auto-decline process (on page load or schedule)
2. **System** queries all pending reservations
3. **System** checks each reservation against current date/time
4. **System** identifies expired reservations (past date or time slot)
5. **System** updates expired reservations to 'denied' status
6. **System** creates history entry with auto-decline note
7. **System** logs audit event and creates notification
8. **User** receives notification about auto-denial

### Expiration Criteria:
- Reservation date is in the past
- Reservation date is today AND:
  - Morning slot selected AND current hour >= 12
  - Afternoon slot selected AND current hour >= 17
  - Evening slot selected AND current hour >= 21

---

## 9. AI Features Workflow

### 9.1 AI Conflict Detection Workflow

```
┌─────────────┐
│   RESIDENT  │
└──────┬──────┘
       │
       │ 1. Selects Facility, Date, Time Slot
       │
       ▼
┌─────────────────────────────┐
│   JAVASCRIPT                │
│ Event Listener (onchange)   │
│ Triggers AJAX Request       │
└──────┬──────────────────────┘
       │
       │ 2. Send to Backend:
       │    - facility_id
       │    - date
       │    - time_slot
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ detectBookingConflict()     │
│ - Query existing bookings   │
│ - Calculate risk score      │
│ - Find alternative slots    │
└──────┬──────────────────────┘
       │
       ├───────────────────────┐
       │                       │
       ▼                       ▼
┌──────────────┐      ┌──────────────┐
│   CONFLICT   │      │  NO CONFLICT │
│   DETECTED   │      │              │
└──────┬───────┘      └──────┬───────┘
       │                     │
       │ Returns:            │ Returns:
       │ - hasConflict: true │ - hasConflict: false
       │ - riskScore: 0-100  │ - riskScore: 0-100
       │ - alternatives: []  │ - alternatives: []
       │                     │
       ▼                     ▼
┌─────────────────────────────┐
│   JAVASCRIPT                │
│ Updates UI:                 │
│ - Shows Warning (if conflict)│
│ - Displays Alternatives     │
│ - Shows Risk Score          │
└─────────────────────────────┘
```

### 9.2 AI Facility Recommendation Workflow

```
┌─────────────┐
│   RESIDENT  │
└──────┬──────┘
       │
       │ 1. Types in Purpose Field
       │
       ▼
┌─────────────────────────────┐
│   JAVASCRIPT                │
│ Debounce Timer (500ms)      │
│ Checks Text Length > 3      │
└──────┬──────────────────────┘
       │
       │ 2. If sufficient text:
       │    Send AJAX Request
       │    - purpose
       │    - expected_attendance
       │    - amenities
       │
       ▼
┌─────────────────────────────┐
│   SYSTEM                    │
│ recommendFacilities()       │
│ - Query all facilities      │
│ - Match purpose keywords     │
│ - Match capacity            │
│ - Match amenities           │
│ - Calculate popularity       │
│ - Calculate match score     │
└──────┬──────────────────────┘
       │
       │ 3. Sort by Score
       │    Return Top 5
       │
       ▼
┌─────────────────────────────┐
│   JAVASCRIPT                │
│ Displays Recommendations:   │
│ - Facility Name             │
│ - Match Score                │
│ - Match Reason               │
│ - Click to Select           │
└──────┬──────────────────────┘
       │
       │ 4. User Can Click
       │    to Auto-Fill
       │
       ▼
┌─────────────────────────────┐
│   RESIDENT                  │
│ Facility Field Auto-Filled  │
│ Can Proceed with Booking    │
└─────────────────────────────┘
```

### AI Workflow Steps:
1. **Resident** interacts with booking form
2. **System** performs real-time analysis (conflict detection or recommendations)
3. **System** returns results via AJAX
4. **JavaScript** updates UI dynamically
5. **Resident** sees warnings/recommendations and can take action

---

## 10. Workflow Actors & Roles

### Actor Definitions

| Actor | Description | Permissions |
|-------|-------------|-------------|
| **Resident** | End users who register and book facilities | - Register account<br>- Book facilities<br>- View own reservations<br>- Update profile<br>- Cannot login until approved |
| **Admin** | System administrators | - All Staff permissions<br>- Full system access<br>- User management<br>- Facility management<br>- Reservation approvals<br>- Audit trail access |
| **Staff** | LGU/Barangay staff | - View pending reservations<br>- Approve/deny reservations<br>- Manage facilities<br>- Manage users<br>- View audit logs |
| **System** | Automated processes | - Input validation<br>- Password hashing<br>- Auto-decline expired reservations<br>- AI conflict detection<br>- AI recommendations<br>- Audit logging<br>- Notification creation |

### Role-Based Access Matrix

| Feature | Resident | Staff | Admin |
|---------|----------|-------|-------|
| Register Account | ✓ | ✓ | ✓ |
| Login (if active) | ✓ | ✓ | ✓ |
| Book Facility | ✓ | - | - |
| View Own Reservations | ✓ | - | - |
| Approve/Deny Reservations | - | ✓ | ✓ |
| Manage Facilities | - | ✓ | ✓ |
| Manage Users | - | ✓ | ✓ |
| View Audit Trail | - | ✓ | ✓ |
| View All Reservations | - | ✓ | ✓ |
| Update Profile | ✓ | ✓ | ✓ |

---

## Workflow Summary

### Key Workflow Characteristics:

1. **Approval-Based System**: Most critical actions require Admin/Staff approval
   - User registration → Admin approval → Active account
   - Reservation booking → Admin approval → Confirmed reservation

2. **Status-Driven Workflows**: All entities have status fields that control workflow progression
   - Users: `pending` → `active` / `locked`
   - Reservations: `pending` → `approved` / `denied` / `cancelled`
   - Facilities: `available` → `maintenance` / `offline`

3. **Audit Trail**: All significant actions are logged for accountability
   - User approvals/denials
   - Reservation approvals/denials
   - Facility changes
   - Status changes

4. **Notification System**: Users are notified of important status changes
   - Account approval
   - Reservation approval/denial
   - Auto-decline notifications

5. **AI-Enhanced Booking**: Real-time assistance during booking
   - Conflict detection
   - Facility recommendations
   - Risk scoring

6. **Automated Processes**: System handles routine tasks automatically
   - Auto-decline expired reservations
   - Input validation
   - Password hashing

---

## End of Document


