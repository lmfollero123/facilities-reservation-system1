# Facilities Reservation System - Flowchart Documentation

## Document Information
- **System Name**: Public Facilities Reservation System
- **Document Type**: Flowchart
- **Version**: 1.0
- **Date**: 2024
- **Author**: System Documentation

---

## Table of Contents
1. [System Overview Flowchart](#1-system-overview-flowchart)
2. [User Registration Flowchart](#2-user-registration-flowchart)
3. [User Login Flowchart](#3-user-login-flowchart)
4. [Reservation Booking Flowchart](#4-reservation-booking-flowchart)
5. [Reservation Approval Flowchart](#5-reservation-approval-flowchart)
6. [Facility Management Flowchart](#6-facility-management-flowchart)
7. [User Management Flowchart](#7-user-management-flowchart)
8. [Profile Management Flowchart](#8-profile-management-flowchart)
9. [Auto-Decline Process Flowchart](#9-auto-decline-process-flowchart)
10. [AI Conflict Detection Flowchart](#10-ai-conflict-detection-flowchart)
11. [AI Facility Recommendation Flowchart](#11-ai-facility-recommendation-flowchart)

---

## 1. System Overview Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │   System Entry      │
            │   (Home Page)       │
            └──────────┬──────────┘
                       │
        ┌───────────────┼───────────────┐
        │               │               │
        ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  Public   │   │   Login   │   │  Register │
│  Browse   │   │   Page    │   │   Page    │
└─────┬─────┘   └─────┬─────┘   └─────┬─────┘
      │               │               │
      │               │               ▼
      │               │      ┌─────────────────┐
      │               │      │  Fill Form &    │
      │               │      │  Upload Docs    │
      │               │      └────────┬────────┘
      │               │               │
      │               │               ▼
      │               │      ┌─────────────────┐
      │               │      │  Account Status │
      │               │      │  = 'pending'    │
      │               │      └────────┬────────┘
      │               │               │
      │               │               ▼
      │               │      ┌─────────────────┐
      │               │      │  Wait for Admin │
      │               │      │  Approval       │
      │               │      └─────────────────┘
      │               │
      │               ▼
      │      ┌─────────────────┐
      │      │  Enter Email &  │
      │      │  Password       │
      │      └────────┬────────┘
      │               │
      │               ▼
      │      ┌─────────────────┐
      │      │  Validate        │
      │      │  Credentials     │
      │      └────────┬────────┘
      │               │
      │        ┌──────┴──────┐
      │        │             │
      │        ▼             ▼
      │  ┌─────────┐   ┌─────────┐
      │  │ Invalid │   │ Valid   │
      │  │ Show    │   │ Create  │
      │  │ Error   │   │ Session │
      │  └─────────┘   └────┬────┘
      │                     │
      │                     ▼
      │            ┌─────────────────┐
      │            │   Dashboard     │
      │            │   (Role-Based)  │
      │            └────────┬─────────┘
      │                     │
      └─────────────────────┘
                      │
        ┌─────────────┼─────────────┐
        │             │             │
        ▼             ▼             ▼
┌───────────┐  ┌───────────┐  ┌───────────┐
│ Resident  │  │  Staff    │  │  Admin    │
│ Dashboard │  │ Dashboard │  │ Dashboard │
└───────────┘  └───────────┘  └───────────┘
```

---

## 2. User Registration Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  Access Register    │
            │  Page               │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Display           │
            │  Registration Form │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  User Fills:        │
            │  - Name             │
            │  - Email            │
            │  - Password         │
            │  - Confirm Password │
            │  - Mobile (Optional)│
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  User Uploads      │
            │  Documents:        │
            │  - Birth Cert      │
            │  - Valid ID        │
            │  - Brgy ID         │
            │  - Other Docs      │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Click Submit      │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Validate Input    │
            │  - Email format    │
            │  - Password match  │
            │  - Required fields │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │  Validation   │    │  Validation   │
    │  Failed       │    │  Passed       │
    └───────┬───────┘    └───────┬───────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Hash Password      │
            │         │  (password_hash)    │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Save User to DB    │
            │         │  - role: 'Resident' │
            │         │  - status: 'pending'│
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Save Documents     │
            │         │  to user_documents  │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Display Success    │
            │         │  Message           │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Redirect to        │
            │         │  Login Page        │
            │         └──────────┬──────────┘
            │                    │
            └────────────────────┘
                       │
                       ▼
                    END
```

---

## 3. User Login Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  Access Login Page  │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Enter Credentials  │
            │  - Email            │
            │  - Password         │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Click Login Button│
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Query Database     │
            │  SELECT * FROM users│
            │  WHERE email = ?    │
            │  AND status =       │
            │  'active'           │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │  User Not     │    │  User Found   │
    │  Found or     │    │  & Status =   │
    │  Status !=    │    │  'active'     │
    │  'active'     │    └───────┬───────┘
    └───────┬───────┘            │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Verify Password   │
            │         │  (password_verify)  │
            │         └──────────┬──────────┘
            │                    │
            │         ┌──────────┴──────────┐
            │         │                     │
            │         ▼                     ▼
            │  ┌───────────────┐    ┌───────────────┐
            │  │  Password     │    │  Password     │
            │  │  Incorrect    │    │  Correct      │
            │  └───────┬───────┘    └───────┬───────┘
            │          │                    │
            │          │                    ▼
            │          │         ┌─────────────────────┐
            │          │         │  Create Session     │
            │          │         │  - user_id          │
            │          │         │  - user_name        │
            │          │         │  - user_email       │
            │          │         │  - role             │
            │          │         │  - authenticated    │
            │          │         └──────────┬──────────┘
            │          │                    │
            │          │                    ▼
            │          │         ┌─────────────────────┐
            │          │         │  Redirect to        │
            │          │         │  Dashboard          │
            │          │         │  (Role-Based)      │
            │          │         └──────────┬──────────┘
            │          │                    │
            └──────────┴────────────────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Display Error      │
            │  Message            │
            └──────────┬──────────┘
                       │
                       ▼
                    END
```

---

## 4. Reservation Booking Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  Authenticated      │
            │  Resident          │
            │  (Session Check)   │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Access Book       │
            │  Facility Page     │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Load Available    │
            │  Facilities        │
            │  (status='available')│
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  User Enters:      │
            │  - Facility        │
            │  - Date            │
            │  - Time Slot       │
            │  - Purpose         │
            │  - Expected        │
            │    Attendance      │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  AI Conflict       │
            │  Detection         │
            │  (Real-time)       │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │  Conflict     │    │  No Conflict  │
    │  Detected     │    │  Detected     │
    └───────┬───────┘    └───────┬───────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  AI Facility       │
            │         │  Recommendations   │
            │         │  (Based on Purpose)│
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Validate Date      │
            │         │  (Not in Past)      │
            │         └──────────┬──────────┘
            │                    │
            │         ┌──────────┴──────────┐
            │         │                     │
            │         ▼                     ▼
            │  ┌───────────────┐    ┌───────────────┐
            │  │  Date Invalid │    │  Date Valid  │
            │  └───────┬───────┘    └───────┬───────┘
            │          │                    │
            │          │                    ▼
            │          │         ┌─────────────────────┐
            │          │         │  Validate Form      │
            │          │         │  - All fields       │
            │          │         │    required         │
            │          │         └──────────┬──────────┘
            │          │                    │
            │          │         ┌──────────┴──────────┐
            │          │         │                     │
            │          │         ▼                     ▼
            │          │  ┌───────────────┐    ┌───────────────┐
            │          │  │  Validation   │    │  Validation   │
            │          │  │  Failed      │    │  Passed       │
            │          │  └───────┬───────┘    └───────┬───────┘
            │          │          │                    │
            │          │          │                    ▼
            │          │          │         ┌─────────────────────┐
            │          │          │         │  INSERT INTO        │
            │          │          │         │  reservations       │
            │          │          │         │  - user_id         │
            │          │          │         │  - facility_id     │
            │          │          │         │  - reservation_date │
            │          │          │         │  - time_slot       │
            │          │          │         │  - purpose         │
            │          │          │         │  - status: 'pending'│
            │          │          │         └──────────┬──────────┘
            │          │          │                    │
            │          │          │                    ▼
            │          │          │         ┌─────────────────────┐
            │          │          │         │  Log Audit Event    │
            │          │          │         └──────────┬──────────┘
            │          │          │                    │
            │          │          │                    ▼
            │          │          │         ┌─────────────────────┐
            │          │          │         │  Display Success    │
            │          │          │         │  Message           │
            │          │          │         └──────────┬──────────┘
            │          │          │                    │
            │          │          │                    ▼
            │          │          │         ┌─────────────────────┐
            │          │          │         │  Update "My        │
            │          │          │         │  Reservations"      │
            │          │          │         │  Card              │
            │          │          │         └──────────┬──────────┘
            │          │          │                    │
            └──────────┴──────────┴────────────────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Show Warning &     │
            │  Alternative Slots  │
            └──────────┬──────────┘
                       │
                       ▼
                    END
```

---

## 5. Reservation Approval Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  Admin/Staff       │
            │  Login            │
            │  (Role Check)     │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Access Reservation │
            │  Approvals Page     │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Auto-Decline       │
            │  Expired Pending    │
            │  Reservations       │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Query Pending      │
            │  Reservations       │
            │  WHERE status =     │
            │  'pending'           │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Display Pending    │
            │  Reservations List  │
            │  - Requester        │
            │  - Facility         │
            │  - Date & Time      │
            │  - Purpose          │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Admin/Staff        │
            │  Reviews Request    │
            └──────────┬──────────┘
                       │
        ┌───────────────┼───────────────┐
        │               │               │
        ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  View     │   │  Approve  │   │   Deny    │
│  Details  │   │  Button   │   │  Button   │
└─────┬─────┘   └─────┬─────┘   └─────┬─────┘
      │               │               │
      │               │               │
      ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  Show    │   │  Confirm  │   │  Confirm  │
│  Full    │   │  Modal    │   │  Modal    │
│  Details │   │  (Optional│   │  (Optional│
│  &       │   │   Note)   │   │   Note)   │
│  History │   └─────┬─────┘   └─────┬─────┘
└──────────┘         │               │
                     │               │
                     ▼               ▼
            ┌─────────────────────┐
            │  UPDATE             │
            │  reservations      │
            │  SET status =       │
            │  'approved'/'denied'│
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  INSERT INTO        │
            │  reservation_history│
            │  - reservation_id   │
            │  - status           │
            │  - note             │
            │  - created_by       │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Log Audit Event    │
            │  (logAudit)         │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Create Notification│
            │  for Requester      │
            │  (createNotification)│
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Display Success    │
            │  Message           │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Refresh Page      │
            │  (Remove from      │
            │   Pending List)    │
            └──────────┬──────────┘
                       │
                       ▼
                    END
```

---

## 6. Facility Management Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  Admin/Staff       │
            │  Login            │
            │  (Role Check)     │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Access Facility   │
            │  Management Page   │
            └──────────┬──────────┘
                       │
        ┌───────────────┼───────────────┐
        │               │               │
        ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│   View    │   │    Add    │   │    Edit   │
│ Facilities│   │  Facility │   │  Facility │
└─────┬─────┘   └─────┬─────┘   └─────┬─────┘
      │               │               │
      │               │               │
      ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  Display  │   │  Fill Form│   │  Click    │
│  All      │   │  - Name   │   │  "Edit    │
│  Facilities│  │  - Desc   │   │  Details" │
│  Table    │   │  - Image  │   │  Button   │
└───────────┘   │  - Location│   └─────┬─────┘
                │  - Capacity│         │
                │  - Amenities│        │
                │  - Rules   │         ▼
                │  - Citation│  ┌───────────────┐
                └─────┬─────┘  │  Populate Form │
                      │        │  with Facility│
                      │        │  Data         │
                      │        └───────┬───────┘
                      │                │
                      │                ▼
                      │        ┌───────────────┐
                      │        │  Modify Fields│
                      │        └───────┬───────┘
                      │                │
                      └────────────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │  Validate Form      │
                    │  - Required fields  │
                    │  - Image size/type  │
                    └──────────┬──────────┘
                               │
                    ┌──────────┴──────────┐
                    │                     │
                    ▼                     ▼
            ┌───────────────┐    ┌───────────────┐
            │  Validation   │    │  Validation   │
            │  Failed      │    │  Passed       │
            └───────┬───────┘    └───────┬───────┘
                    │                    │
                    │                    ▼
                    │         ┌─────────────────────┐
                    │         │  Upload Image       │
                    │         │  (if provided)      │
                    │         └──────────┬──────────┘
                    │                    │
                    │                    ▼
                    │         ┌─────────────────────┐
                    │         │  INSERT or UPDATE    │
                    │         │  facilities table   │
                    │         └──────────┬──────────┘
                    │                    │
                    │                    ▼
                    │         ┌─────────────────────┐
                    │         │  Log Audit Event    │
                    │         └──────────┬──────────┘
                    │                    │
                    │                    ▼
                    │         ┌─────────────────────┐
                    │         │  Display Success    │
                    │         │  Message           │
                    │         └──────────┬──────────┘
                    │                    │
                    │                    ▼
                    │         ┌─────────────────────┐
                    │         │  Refresh Facility   │
                    │         │  List              │
                    │         └──────────┬──────────┘
                    │                    │
                    └────────────────────┘
                               │
                               ▼
                            END
```

---

## 7. User Management Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  Admin/Staff       │
            │  Login            │
            │  (Role Check)     │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Access User      │
            │  Management Page  │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Query All Users   │
            │  FROM users        │
            │  ORDER BY          │
            │  created_at DESC   │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Display Users     │
            │  Table:            │
            │  - Name            │
            │  - Email           │
            │  - Role            │
            │  - Status          │
            │  - Actions         │
            └──────────┬──────────┘
                       │
        ┌───────────────┼───────────────┐
        │               │               │
        ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  View     │   │  Approve  │   │   Deny/   │
│ Documents │   │  User     │   │   Lock    │
│ (Pending) │   │  (Pending)│   │   User    │
└─────┬─────┘   └─────┬─────┘   └─────┬─────┘
      │               │               │
      │               │               │
      ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  Display  │   │  Confirm  │   │  Confirm  │
│  Uploaded │   │  Modal    │   │  Modal    │
│  Documents│   │           │   │           │
│  List     │   └─────┬─────┘   └─────┬─────┘
└───────────┘         │               │
                      │               │
                      ▼               ▼
            ┌─────────────────────┐
            │  UPDATE users       │
            │  SET status =       │
            │  'active'/'locked'  │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Log Audit Event    │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Create Notification│
            │  (if approved)      │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Display Success    │
            │  Message           │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Refresh User List  │
            └──────────┬──────────┘
                       │
                       ▼
                    END
```

---

## 8. Profile Management Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  Authenticated User │
            │  (Any Role)        │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Access Profile    │
            │  Page             │
            └──────────┬──────────┘
                       │
        ┌───────────────┼───────────────┐
        │               │               │
        ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  Update   │   │  Change   │   │  Upload   │
│  Profile  │   │  Password │   │  Profile  │
│  Info     │   │           │   │  Picture  │
└─────┬─────┘   └─────┬─────┘   └─────┬─────┘
      │               │               │
      │               │               │
      ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  Modify:  │   │  Enter:   │   │  Click    │
│  - Name   │   │  - Current│   │  Camera   │
│  - Email  │   │    Password│  │  Icon on  │
│  - Mobile │   │  - New    │   │  Avatar   │
└─────┬─────┘   │    Password│  └─────┬─────┘
      │         │  - Confirm │         │
      │         └─────┬─────┘         │
      │               │               │
      │               ▼               ▼
      │       ┌───────────────┐ ┌───────────────┐
      │       │  Validate     │ │  Select Image  │
      │       │  - Current   │ │  File          │
      │       │    Password   │ └───────┬───────┘
      │       │  - Match     │         │
      │       │  - Strength  │         ▼
      │       └───────┬───────┘ ┌───────────────┐
      │               │         │  Validate     │
      │               │         │  - File size  │
      │               │         │  - File type  │
      │               │         └───────┬───────┘
      │               │                 │
      └───────────────┼─────────────────┘
                      │
                      ▼
            ┌─────────────────────┐
            │  Validate All        │
            │  Inputs             │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │  Validation   │    │  Validation   │
    │  Failed       │    │  Passed       │
    └───────┬───────┘    └───────┬───────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Process Updates    │
            │         │  - Hash new password│
            │         │  - Upload image     │
            │         │  - Delete old image │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  UPDATE users       │
            │         │  SET name, email,   │
            │         │  mobile,            │
            │         │  profile_picture,  │
            │         │  password_hash      │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Display Success    │
            │         │  Message           │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Refresh Profile    │
            │         │  Display           │
            │         └──────────┬──────────┘
            │                    │
            └────────────────────┘
                       │
                       ▼
                    END
```

---

## 9. Auto-Decline Process Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  System Trigger     │
            │  (Page Load or      │
            │   Scheduled Task)   │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Query All Pending  │
            │  Reservations       │
            │  WHERE status =     │
            │  'pending'          │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Get Current Date   │
            │  & Time            │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Loop Through Each  │
            │  Pending            │
            │  Reservation        │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Check Reservation  │
            │  Date & Time Slot   │
            └──────────┬──────────┘
                       │
        ┌───────────────┼───────────────┐
        │               │               │
        ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐
│  Date <   │   │  Date =   │   │  Date >   │
│  Today    │   │  Today    │   │  Today    │
│  (Past)   │   │           │   │  (Future) │
└─────┬─────┘   └─────┬─────┘   └─────┬─────┘
      │               │               │
      │               │               │
      ▼               ▼               │
┌───────────┐   ┌───────────┐        │
│  Mark as  │   │  Check    │        │
│  Expired  │   │  Time     │        │
└─────┬─────┘   │  Slot     │        │
      │         └─────┬─────┘        │
      │               │              │
      │      ┌────────┴────────┐     │
      │      │                 │     │
      │      ▼                 ▼     │
      │ ┌─────────┐      ┌─────────┐│
      │ │ Time    │      │ Time    ││
      │ │ Slot    │      │ Slot    ││
      │ │ Passed  │      │ Not     ││
      │ │         │      │ Passed  ││
      │ └────┬────┘      └────┬────┘│
      │      │                │     │
      │      │                │     │
      └──────┴────────────────┴─────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  For Each Expired   │
            │  Reservation        │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  UPDATE             │
            │  reservations      │
            │  SET status =      │
            │  'denied'           │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  INSERT INTO        │
            │  reservation_history│
            │  - status: 'denied' │
            │  - note: 'Auto-    │
            │    declined: Past  │
            │    reservation     │
            │    time'           │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Log Audit Event   │
            │  (Auto-decline)    │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Create Notification│
            │  for User          │
            │  (Reservation      │
            │   Auto-denied)     │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Continue to Next   │
            │  Reservation        │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  All Processed?    │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │      No       │    │      Yes      │
    └───────┬───────┘    └───────┬───────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Process Complete   │
            │         └──────────┬──────────┘
            │                   │
            └───────────────────┘
                       │
                       ▼
                    END
```

---

## 10. AI Conflict Detection Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  User Selects      │
            │  Facility, Date,    │
            │  Time Slot         │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  JavaScript        │
            │  Event Listener    │
            │  (onchange)        │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  AJAX Request to   │
            │  ai_conflict_check │
            │  .php             │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Backend Receives  │
            │  - facility_id     │
            │  - date            │
            │  - time_slot       │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Call              │
            │  detectBooking     │
            │  Conflict()        │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Query Existing    │
            │  Bookings          │
            │  WHERE facility_id  │
            │  AND date = ?      │
            │  AND time_slot = ? │
            │  AND status =      │
            │  'approved'        │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │  Conflict     │    │  No Direct    │
    │  Found        │    │  Conflict     │
    └───────┬───────┘    └───────┬───────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Calculate Risk     │
            │         │  Score              │
            │         │  (Historical Data) │
            │         └──────────┬──────────┘
            │                    │
            │         ┌──────────┴──────────┐
            │         │                     │
            │         ▼                     ▼
            │  ┌───────────────┐    ┌───────────────┐
            │  │  High Risk    │    │  Low Risk     │
            │  │  Score        │    │  Score        │
            │  └───────┬───────┘    └───────┬───────┘
            │          │                    │
            │          │                    ▼
            │          │         ┌─────────────────────┐
            │          │         │  Find Alternative   │
            │          │         │  Time Slots        │
            │          │         └──────────┬──────────┘
            │          │                    │
            │          │                    ▼
            │          │         ┌─────────────────────┐
            │          │         │  Return JSON:       │
            │          │         │  - hasConflict:    │
            │          │         │    false           │
            │          │         │  - riskScore:      │
            │          │         │    (0-100)         │
            │          │         │  - alternatives:   │
            │          │         │    []              │
            │          │         └──────────┬──────────┘
            │          │                    │
            │          ▼                    │
            │  ┌─────────────────────┐      │
            │  │  Find Alternative   │      │
            │  │  Time Slots        │      │
            │  └──────────┬──────────┘      │
            │             │                 │
            │             ▼                 │
            │  ┌─────────────────────┐      │
            │  │  Return JSON:       │      │
            │  │  - hasConflict:     │      │
            │  │    true             │      │
            │  │  - riskScore:       │      │
            │  │    (0-100)          │      │
            │  │  - alternatives:    │      │
            │  │    [slot1, slot2...]│      │
            │  └──────────┬──────────┘      │
            │             │                 │
            └─────────────┴─────────────────┘
                           │
                           ▼
            ┌─────────────────────┐
            │  JavaScript         │
            │  Receives Response  │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │  Display      │    │  Display      │
    │  Conflict     │    │  Low Risk     │
    │  Warning      │    │  Message or   │
    │  &            │    │  No Warning   │
    │  Alternatives │    │               │
    └───────┬───────┘    └───────┬───────┘
            │                    │
            └────────────────────┘
                       │
                       ▼
                    END
```

---

## 11. AI Facility Recommendation Flowchart

```
                    START
                      │
                      ▼
            ┌─────────────────────┐
            │  User Types in      │
            │  Purpose Field      │
            │  (Textarea)         │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  JavaScript         │
            │  Debounce Timer     │
            │  (500ms delay)      │
            └──────────┬──────────┘
                       │
                       ▼
            ┌─────────────────────┐
            │  Check if Purpose   │
            │  Text Length > 3   │
            │  Characters         │
            └──────────┬──────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
    ┌───────────────┐    ┌───────────────┐
    │  Text Too     │    │  Text         │
    │  Short        │    │  Sufficient   │
    │  (Skip)       │    │               │
    └───────┬───────┘    └───────┬───────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  AJAX Request to     │
            │         │  ai_recommendations  │
            │         │  _api.php           │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Backend Receives   │
            │         │  - purpose          │
            │         │  - expected_        │
            │         │    attendance       │
            │         │  - amenities        │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Call               │
            │         │  recommend         │
            │         │  Facilities()      │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Query All          │
            │         │  Available          │
            │         │  Facilities        │
            │         │  (status='available')│
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  For Each Facility: │
            │         │  - Match Purpose    │
            │         │    (Keyword)        │
            │         │  - Match Capacity   │
            │         │  - Match Amenities  │
            │         │  - Calculate        │
            │         │    Popularity Score │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Calculate Total    │
            │         │  Match Score        │
            │         │  for Each Facility  │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Sort by Score      │
            │         │  (Descending)       │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Return Top 5       │
            │         │  Facilities        │
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  Return JSON:       │
            │         │  - recommendations: │
            │         │    [{id, name,      │
            │         │      score, reason}]│
            │         └──────────┬──────────┘
            │                    │
            │                    ▼
            │         ┌─────────────────────┐
            │         │  JavaScript         │
            │         │  Receives Response  │
            │         └──────────┬──────────┘
            │                    │
            │         ┌──────────┴──────────┐
            │         │                     │
            │         ▼                     ▼
            │  ┌───────────────┐    ┌───────────────┐
            │  │  Has          │    │  No           │
            │  │  Recommendations│  │  Recommendations│
            │  └───────┬───────┘    └───────┬───────┘
            │          │                    │
            │          │                    ▼
            │          │         ┌─────────────────────┐
            │          │         │  Hide               │
            │          │         │  Recommendations   │
            │          │         │  Section           │
            │          │         └──────────┬──────────┘
            │          │                    │
            │          ▼                    │
            │  ┌─────────────────────┐      │
            │  │  Display            │      │
            │  │  Recommendations    │      │
            │  │  List:              │      │
            │  │  - Facility Name    │      │
            │  │  - Match Score      │      │
            │  │  - Match Reason     │      │
            │  │  - Click to Select  │      │
            │  └──────────┬──────────┘      │
            │             │                 │
            └─────────────┴─────────────────┘
                           │
                           ▼
            ┌─────────────────────┐
            │  User Can Click     │
            │  Recommendation to  │
            │  Auto-Fill Facility │
            │  Field             │
            └──────────┬──────────┘
                       │
                       ▼
                    END
```

---

## Flowchart Symbols Legend

- **Rectangle (Process)**: Represents a process or action
- **Diamond (Decision)**: Represents a decision point with yes/no or multiple paths
- **Parallelogram (Input/Output)**: Represents data input or output
- **Rounded Rectangle (Start/End)**: Represents the start or end of a process
- **Arrow**: Shows the flow direction between steps

---

## Notes

1. **Session Management**: All authenticated flows require valid session variables
2. **Role-Based Access**: Admin/Staff pages require role verification
3. **Database Transactions**: All database operations use prepared statements for security
4. **Error Handling**: All flows include error handling and user feedback
5. **Audit Logging**: Critical actions are logged in the audit_log table
6. **Notifications**: Status changes trigger notifications for affected users
7. **Real-Time Features**: AI conflict detection and recommendations use AJAX for real-time updates
8. **Auto-Decline**: System automatically declines expired pending reservations

---

## End of Document



