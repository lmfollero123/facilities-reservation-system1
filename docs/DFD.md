# Facilities Reservation System - Data Flow Diagram (DFD)

## Document Information
- **System Name**: Public Facilities Reservation System
- **Document Type**: Data Flow Diagram (DFD)
- **Version**: 1.1
- **Date**: 2025
- **Author**: System Documentation

---

## Table of Contents
1. [DFD Symbols & Notation](#1-dfd-symbols--notation)
2. [Context Diagram (Level 0 DFD)](#2-context-diagram-level-0-dfd)
3. [Level 1 DFD - System Overview](#3-level-1-dfd---system-overview)
4. [Level 2 DFD - User Management](#4-level-2-dfd---user-management)
5. [Level 2 DFD - Reservation Management](#5-level-2-dfd---reservation-management)
6. [Level 2 DFD - Facility Management](#6-level-2-dfd---facility-management)
7. [Level 2 DFD - Authentication & Authorization](#7-level-2-dfd---authentication--authorization)
8. [Level 2 DFD - AI Features](#8-level-2-dfd---ai-features)
9. [Data Dictionary](#9-data-dictionary)

---

## 1. DFD Symbols & Notation

### Standard DFD Symbols

| Symbol | Name | Description |
|--------|------|-------------|
| ⭕ | **Process** | A process that transforms data |
| ⬛ | **Data Store** | A repository where data is stored |
| ▭ | **External Entity** | A person, organization, or system outside the scope |
| ➡️ | **Data Flow** | Movement of data between processes, stores, and entities |

### Notation Rules
- **Processes**: Numbered (1.0, 2.0, etc.) and named with verb-noun pairs
- **Data Stores**: Labeled with noun phrases (D1, D2, etc.)
- **External Entities**: Labeled with noun phrases
- **Data Flows**: Labeled with descriptive names showing what data is flowing

---

## 2. Context Diagram (Level 0 DFD)

```
                    ┌─────────────────────┐
                    │                     │
                    │      RESIDENT       │
                    │                     │
                    └──────────┬──────────┘
                               │
                ┌──────────────┼──────────────┐
                │              │              │
                │              │              │
    ┌───────────▼───┐  ┌───────▼──────┐  ┌───▼──────────┐
    │ Registration  │  │ Login        │  │ Booking      │
    │ Data          │  │ Credentials  │  │ Request      │
    └───────────┬───┘  └───────┬──────┘  └───┬──────────┘
                │              │              │
                │              │              │
                └──────────────┼──────────────┘
                               │
                    ┌──────────▼──────────┐
                    │                     │
                    │  FACILITIES         │
                    │  RESERVATION        │
                    │  SYSTEM             │
                    │                     │
                    └──────────┬──────────┘
                               │
                ┌──────────────┼──────────────┐
                │              │              │
                │              │              │
    ┌───────────▼───┐  ┌───────▼──────┐  ┌───▼──────────┐
    │ Confirmation  │  │ Session      │  │ Reservation  │
    │ Message       │  │ Data         │  │ Status       │
    └───────────────┘  └──────────────┘  └──────────────┘
                               │
                               │
                    ┌──────────▼──────────┐
                    │                     │
                    │   ADMIN/STAFF       │
                    │                     │
                    └──────────┬──────────┘
                               │
                ┌──────────────┼──────────────┐
                │              │              │
    ┌───────────▼───┐  ┌───────▼──────┐  ┌───▼──────────┐
    │ Approval      │  │ Management    │  │ Reports &     │
    │ Decisions     │  │ Commands      │  │ Queries       │
    └───────────┬───┘  └───────┬──────┘  └───┬──────────┘
                │              │              │
                │              │              │
                └──────────────┼──────────────┘
                               │
                    ┌──────────▼──────────┐
                    │                     │
                    │  FACILITIES         │
                    │  RESERVATION        │
                    │  SYSTEM             │
                    │                     │
                    └──────────┬──────────┘
                               │
                ┌──────────────┼──────────────┐
                │              │              │
    ┌───────────▼───┐  ┌───────▼──────┐  ┌───▼──────────┐
    │ Approval      │  │ Facility      │  │ Audit Logs   │
    │ Notifications │  │ Data          │  │ & Reports    │
    └───────────────┘  └──────────────┘  └──────────────┘
```

### Context Diagram Description

**External Entities:**
- **Resident**: End users who register, login, and book facilities
- **Admin/Staff**: System administrators and staff who manage the system

**Main Data Flows:**
1. **Resident → System**:
   - Registration Data (name, email, password, documents)
   - Login Credentials (email, password)
   - Booking Request (facility, date, time, purpose)

2. **System → Resident**:
   - Confirmation Messages
   - Session Data (authentication tokens)
   - Reservation Status (approval/denial notifications)

3. **Admin/Staff → System**:
   - Approval Decisions (approve/deny reservations/users)
   - Management Commands (add/edit facilities, manage users)
   - Reports & Queries (view audit logs, generate reports)

4. **System → Admin/Staff**:
   - Approval Notifications
   - Facility Data (lists, details)
   - Audit Logs & Reports

---

## 3. Level 1 DFD - System Overview

```
                    ┌──────────────┐
                    │   RESIDENT   │
                    └──────┬───────┘
                           │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐  ┌───────────────┐  ┌───────────────┐
│ 1.0           │  │ 2.0           │  │ 3.0           │
│ User          │  │ Authenticate   │  │ Book          │
│ Registration  │  │ User          │  │ Facility      │
└───────┬───────┘  └───────┬───────┘  └───────┬───────┘
        │                   │                   │
        │ User Data         │ Session Data     │ Reservation
        │                   │                   │ Request
        ▼                   ▼                   ▼
    ┌───────┐          ┌───────┐          ┌───────┐
    │  D1   │          │  D2   │          │  D3   │
    │ Users │          │Sessions│          │Reservations│
    └───────┘          └───────┘          └───────┘
        │                   │                   │
        │                   │                   │
        └───────────────────┼───────────────────┘
                            │
                            ▼
                    ┌──────────────┐
                    │ ADMIN/STAFF   │
                    └───────┬───────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐  ┌───────────────┐  ┌───────────────┐
│ 4.0           │  │ 5.0           │  │ 6.0           │
│ Approve       │  │ Manage        │  │ Manage        │
│ Reservations  │  │ Facilities    │  │ Users         │
└───────┬───────┘  └───────┬───────┘  └───────┬───────┘
        │                   │                   │
        │ Approval          │ Facility Data    │ User Status
        │ Decision          │                   │ Update
        ▼                   ▼                   ▼
    ┌───────┐          ┌───────┐          ┌───────┐
    │  D3   │          │  D4   │          │  D1   │
    │Reservations│     │Facilities│        │ Users │
    └───────┘          └───────┘          └───────┘
        │                   │                   │
        │                   │                   │
        └───────────────────┼───────────────────┘
                            │
                            ▼
                    ┌──────────────┐
                    │    SYSTEM    │
                    └───────┬───────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐  ┌───────────────┐  ┌───────────────┐
│ 7.0           │  │ 8.0           │  │ 9.0           │
│ Auto-Decline  │  │ Generate      │  │ AI Conflict   │
│ Expired       │  │ Notifications │  │ Detection &  │
│ Reservations  │  │               │  │ Recommendations│
└───────┬───────┘  └───────┬───────┘  └───────┬───────┘
        │                   │                   │
        │ Status Update     │ Notification      │ AI Results
        │                   │ Data              │
        ▼                   ▼                   ▼
    ┌───────┐          ┌───────┐          ┌───────┐
    │  D3   │          │  D5   │          │  D3   │
    │Reservations│     │Notifications│     │Reservations│
    └───────┘          └───────┘          └───────┘
                            │
                            │ Notification
                            │ Data
                            ▼
                    ┌──────────────┐
                    │   RESIDENT   │
                    │ ADMIN/STAFF  │
                    └──────────────┘
```

### Level 1 DFD Processes

| Process | Name | Description |
|--------|------|-------------|
| **1.0** | User Registration | Handles new user registration with Barangay Culiat address validation and required document upload (Birth Cert, Valid ID, Barangay ID, Resident ID) |
| **2.0** | Authenticate User | Validates credentials, sends email OTP, verifies OTP, then creates session |
| **3.0** | Book Facility | Processes facility reservation requests |
| **4.0** | Approve Reservations | Admin/Staff reviews and approves/denies reservations |
| **5.0** | Manage Facilities | Add, edit, and update facility information |
| **6.0** | Manage Users | Approve, deny, or lock user accounts |
| **7.0** | Auto-Decline Expired | Automatically declines expired pending reservations |
| **8.0** | Generate Notifications | Creates notifications for status changes |
| **9.0** | AI Conflict Detection & Recommendations | Real-time conflict checking and facility suggestions |

### Level 1 DFD Data Stores

| Data Store | Name | Description |
|------------|------|-------------|
| **D1** | Users | Stores user account information |
| **D2** | Sessions | Stores active user sessions |
| **D3** | Reservations | Stores reservation requests and status |
| **D4** | Facilities | Stores facility information |
| **D5** | Notifications | Stores user notifications |
| **D6** | User Documents | Stores uploaded resident documents for verification |

### Additional Notes (Level 1)
- OTP email flow: Authenticate User sends OTP to the user’s email, stores OTP hash/expiry in Users, and only then creates a session.
- Registration enforces Barangay Culiat residency (address check) and at least one supporting document uploaded to User Documents.

---

## 4. Level 2 DFD - User Management

```
                    ┌──────────────┐
                    │   RESIDENT   │
                    └──────┬───────┘
                           │
                           │ Registration Data
                           │ (name, email, password, mobile)
                           │ Documents (files)
                           │
                           ▼
                    ┌──────────────┐
                    │ 1.1          │
                    │ Validate     │
                    │ Registration │
                    │ Data         │
                    └──────┬───────┘
                           │
                           │ Validated Data
                           │
                           ▼
                    ┌──────────────┐
                    │ 1.2          │
                    │ Hash         │
                    │ Password     │
                    └──────┬───────┘
                           │
                           │ Hashed Password
                           │
                           ▼
                    ┌──────────────┐
                    │ 1.3          │
                    │ Save User    │
                    │ Data         │
                    └──────┬───────┘
                           │
                           │ User Record
                           │
                           ▼
                    ┌───────┐
                    │  D1   │
                    │ Users │
                    └───┬───┘
                        │
                        │ User Data
                        │
                        ▼
                    ┌──────────────┐
                    │ 1.4          │
                    │ Save         │
                    │ Documents    │
                    └──────┬───────┘
                           │
                           │ Document Records
                           │
                           ▼
                    ┌───────┐
                    │  D6   │
                    │User   │
                    │Documents│
                    └───────┘
                           │
                           │
                    ┌──────▼───────┐
                    │ ADMIN/STAFF   │
                    └──────┬───────┘
                           │
                           │ Query Pending Users
                           │
                           ▼
                    ┌──────────────┐
                    │ 6.1          │
                    │ Retrieve     │
                    │ Pending Users│
                    └──────┬───────┘
                           │
                           │ User List
                           │ Document List
                           │
                           ▼
                    ┌───────┐
                    │  D1   │
                    │ Users │
                    └───┬───┘
                        │
                        │
                    ┌───▼───┐
                    │  D6   │
                    │User   │
                    │Documents│
                    └───┬───┘
                        │
                        │
                        ▼
                    ┌──────────────┐
                    │ ADMIN/STAFF   │
                    │ Reviews &     │
                    │ Makes Decision│
                    └──────┬───────┘
                           │
                           │ Approval Decision
                           │ (approve/deny/lock)
                           │
                           ▼
                    ┌──────────────┐
                    │ 6.2          │
                    │ Update User  │
                    │ Status       │
                    └──────┬───────┘
                           │
                           │ Updated Status
                           │
                           ▼
                    ┌───────┐
                    │  D1   │
                    │ Users │
                    └───┬───┘
                        │
                        │ User Data
                        │
                        ▼
                    ┌──────────────┐
                    │ 6.3          │
                    │ Log Audit    │
                    │ Event        │
                    └──────┬───────┘
                           │
                           │ Audit Record
                           │
                           ▼
                    ┌───────┐
                    │  D7   │
                    │Audit  │
                    │Log    │
                    └───────┘
                           │
                           │
                           ▼
                    ┌──────────────┐
                    │ 8.0          │
                    │ Generate     │
                    │ Notification │
                    └──────┬───────┘
                           │
                           │ Notification
                           │
                           ▼
                    ┌───────┐
                    │  D5   │
                    │Notifications│
                    └───────┘
```

### User Management Data Flows

**Process 1.0 - User Registration:**
- **Input**: Registration Data, Documents
- **Processes**:
  - 1.1: Validate Registration Data
  - 1.2: Hash Password
  - 1.3: Save User Data → D1 (Users)
  - 1.4: Save Documents → D6 (User Documents)
- **Output**: User Record (status: pending)

**Process 6.0 - Manage Users:**
- **Input**: Query Request, Approval Decision
- **Processes**:
  - 6.1: Retrieve Pending Users from D1, D6
  - 6.2: Update User Status in D1
  - 6.3: Log Audit Event → D7
- **Output**: Updated User Status, Notification

---

## 5. Level 2 DFD - Reservation Management

```
                    ┌──────────────┐
                    │   RESIDENT   │
                    └──────┬───────┘
                           │
                           │ Booking Request
                           │ (facility_id, date, time, purpose)
                           │
                           ▼
                    ┌──────────────┐
                    │ 3.1          │
                    │ Validate     │
                    │ Booking Data │
                    └──────┬───────┘
                           │
                           │ Validated Request
                           │
                           ▼
                    ┌──────────────┐
                    │ 9.1          │
                    │ Check        │
                    │ Conflicts    │
                    └──────┬───────┘
                           │
                           │ Query Reservations
                           │
                           ▼
                    ┌───────┐
                    │  D3   │
                    │Reservations│
                    └───┬───┘
                        │
                        │ Existing Bookings
                        │
                        ▼
                    ┌──────────────┐
                    │ 9.1          │
                    │ Calculate    │
                    │ Risk Score   │
                    └──────┬───────┘
                           │
                           │ Conflict Result
                           │
                           ▼
                    ┌──────────────┐
                    │   RESIDENT   │
                    │ (See Warning)│
                    └──────┬───────┘
                           │
                           │ Proceed with Booking
                           │
                           ▼
                    ┌──────────────┐
                    │ 3.2          │
                    │ Create       │
                    │ Reservation  │
                    └──────┬───────┘
                           │
                           │ Reservation Record
                           │
                           ▼
                    ┌───────┐
                    │  D3   │
                    │Reservations│
                    └───┬───┘
                        │
                        │ Reservation Data
                        │
                        ▼
                    ┌──────────────┐
                    │ 3.3          │
                    │ Log Audit    │
                    │ Event        │
                    └──────┬───────┘
                           │
                           │ Audit Record
                           │
                           ▼
                    ┌───────┐
                    │  D7   │
                    │Audit  │
                    │Log    │
                    └───────┘
                           │
                           │
                    ┌──────▼───────┐
                    │ ADMIN/STAFF   │
                    └──────┬───────┘
                           │
                           │ Query Pending Reservations
                           │
                           ▼
                    ┌──────────────┐
                    │ 4.1          │
                    │ Retrieve     │
                    │ Pending      │
                    │ Reservations │
                    └──────┬───────┘
                           │
                           │ Reservation List
                           │
                           ▼
                    ┌───────┐
                    │  D3   │
                    │Reservations│
                    └───┬───┘
                        │
                        │
                    ┌───▼───┐
                    │  D4   │
                    │Facilities│
                    └───┬───┘
                        │
                        │
                    ┌───▼───┐
                    │  D1   │
                    │ Users │
                    └───┬───┘
                        │
                        │
                        ▼
                    ┌──────────────┐
                    │ ADMIN/STAFF   │
                    │ Reviews &     │
                    │ Makes Decision│
                    └──────┬───────┘
                           │
                           │ Approval Decision
                           │ (approve/deny)
                           │ Optional Note
                           │
                           ▼
                    ┌──────────────┐
                    │ 4.2          │
                    │ Update       │
                    │ Reservation  │
                    │ Status       │
                    └──────┬───────┘
                           │
                           │ Updated Status
                           │
                           ▼
                    ┌───────┐
                    │  D3   │
                    │Reservations│
                    └───┬───┘
                        │
                        │ Reservation ID
                        │
                        ▼
                    ┌──────────────┐
                    │ 4.3          │
                    │ Create       │
                    │ History      │
                    │ Entry        │
                    └──────┬───────┘
                           │
                           │ History Record
                           │
                           ▼
                    ┌───────┐
                    │  D8   │
                    │Reservation│
                    │History │
                    └───────┘
                           │
                           │
                           ▼
                    ┌──────────────┐
                    │ 4.4          │
                    │ Log Audit    │
                    │ Event        │
                    └──────┬───────┘
                           │
                           │ Audit Record
                           │
                           ▼
                    ┌───────┐
                    │  D7   │
                    │Audit  │
                    │Log    │
                    └───────┘
                           │
                           │
                           ▼
                    ┌──────────────┐
                    │ 8.0          │
                    │ Generate     │
                    │ Notification │
                    └──────┬───────┘
                           │
                           │ Notification
                           │
                           ▼
                    ┌───────┐
                    │  D5   │
                    │Notifications│
                    └───────┘
                           │
                           │
                    ┌──────▼───────┐
                    │   RESIDENT   │
                    │ (Receives    │
                    │  Notification)│
                    └──────────────┘
```

### Reservation Management Data Flows

**Process 3.0 - Book Facility:**
- **Input**: Booking Request
- **Processes**:
  - 3.1: Validate Booking Data
  - 9.1: Check Conflicts (from D3)
  - 3.2: Create Reservation → D3
  - 3.3: Log Audit Event → D7
- **Output**: Reservation Record (status: pending)

**Process 4.0 - Approve Reservations:**
- **Input**: Query Request, Approval Decision
- **Processes**:
  - 4.1: Retrieve Pending Reservations (from D3, D4, D1)
  - 4.2: Update Reservation Status → D3
  - 4.3: Create History Entry → D8
  - 4.4: Log Audit Event → D7
- **Output**: Updated Status, History Entry, Notification

**Process 7.0 - Auto-Decline Expired:**
- **Input**: System Trigger (time-based)
- **Processes**:
  - 7.1: Query Pending Reservations from D3
  - 7.2: Check Expiration (date/time comparison)
  - 7.3: Update Status to 'denied' → D3
  - 7.4: Create History Entry → D8
  - 7.5: Log Audit Event → D7
  - 7.6: Generate Notification → D5
- **Output**: Updated Status, History Entry, Notification

---

## 6. Level 2 DFD - Facility Management

```
                    ┌──────────────┐
                    │ ADMIN/STAFF   │
                    └──────┬───────┘
                           │
                           │ Facility Data
                           │ (name, description, image, etc.)
                           │
                           ▼
                    ┌──────────────┐
                    │ 5.1          │
                    │ Validate     │
                    │ Facility Data│
                    └──────┬───────┘
                           │
                           │ Validated Data
                           │
                           ▼
                    ┌──────────────┐
                    │ 5.2          │
                    │ Upload Image │
                    │ (if provided)│
                    └──────┬───────┘
                           │
                           │ Image File Path
                           │
                           ▼
                    ┌──────────────┐
                    │ 5.3          │
                    │ Save/Update  │
                    │ Facility     │
                    └──────┬───────┘
                           │
                           │ Facility Record
                           │
                           ▼
                    ┌───────┐
                    │  D4   │
                    │Facilities│
                    └───┬───┘
                        │
                        │ Facility Data
                        │
                        ▼
                    ┌──────────────┐
                    │ 5.4          │
                    │ Log Audit    │
                    │ Event        │
                    └──────┬───────┘
                           │
                           │ Audit Record
                           │
                           ▼
                    ┌───────┐
                    │  D7   │
                    │Audit  │
                    │Log    │
                    └───────┘
                           │
                           │
                    ┌──────▼───────┐
                    │   RESIDENT   │
                    │ ADMIN/STAFF  │
                    └──────┬───────┘
                           │
                           │ Query Facilities
                           │
                           ▼
                    ┌──────────────┐
                    │ 5.5          │
                    │ Retrieve     │
                    │ Facilities   │
                    └──────┬───────┘
                           │
                           │ Facility List
                           │
                           ▼
                    ┌───────┐
                    │  D4   │
                    │Facilities│
                    └───────┘
```

### Facility Management Data Flows

**Process 5.0 - Manage Facilities:**
- **Input**: Facility Data (add/edit)
- **Processes**:
  - 5.1: Validate Facility Data
  - 5.2: Upload Image (if provided)
  - 5.3: Save/Update Facility → D4
  - 5.4: Log Audit Event → D7
  - 5.5: Retrieve Facilities from D4 (for display)
- **Output**: Facility Record, Facility List

---

## 7. Level 2 DFD - Authentication & Authorization

```
                    ┌──────────────┐
                    │   RESIDENT   │
                    │ ADMIN/STAFF  │
                    └──────┬───────┘
                           │
                           │ Login Credentials
                           │ (email, password)
                           │
                           ▼
                    ┌──────────────┐
                    │ 2.1          │
                    │ Validate     │
                    │ Credentials  │
                    └──────┬───────┘
                           │
                           │ Query User
                           │
                           ▼
                    ┌───────┐
                    │  D1   │
                    │ Users │
                    └───┬───┘
                        │
                        │ User Data
                        │
                        ▼
                    ┌──────────────┐
                    │ 2.2          │
                    │ Verify       │
                    │ Password     │
                    └──────┬───────┘
                           │
                    ┌──────┴──────┐
                    │             │
                    ▼             ▼
            ┌───────────┐  ┌───────────┐
            │ Invalid   │  │ Valid     │
            │ Credentials│  │ Credentials│
            └───────────┘  └───────┬───┘
                                   │
                                   │ User Data
                                   │
                                   ▼
                            ┌──────────────┐
                            │ 2.3          │
                            │ Create       │
                            │ Session      │
                            └──────┬───────┘
                                   │
                                   │ Session Data
                                   │
                                   ▼
                            ┌───────┐
                            │  D2   │
                            │Sessions│
                            └───────┘
                                   │
                                   │ Session Token
                                   │
                                   ▼
                            ┌──────────────┐
                            │   RESIDENT     │
                            │ ADMIN/STAFF    │
                            │ (Authenticated)│
                            └───────────────┘
                                   │
                                   │ Access Request
                                   │
                                   ▼
                            ┌──────────────┐
                            │ 2.4          │
                            │ Check        │
                            │ Authorization│
                            └──────┬───────┘
                                   │
                                   │ Query Session
                                   │
                                   ▼
                            ┌───────┐
                            │  D2   │
                            │Sessions│
                            └───┬───┘
                                │
                                │ Session Data
                                │
                                ▼
                            ┌──────────────┐
                            │ 2.5          │
                            │ Check Role   │
                            │ Permissions  │
                            └──────┬───────┘
                                   │
                                   │ Query User Role
                                   │
                                   ▼
                            ┌───────┐
                            │  D1   │
                            │ Users │
                            └───┬───┘
                                │
                                │ Role Data
                                │
                                ▼
                            ┌──────────────┐
                            │ Authorized/  │
                            │ Unauthorized │
                            │ Response     │
                            └──────────────┘
```

### Authentication & Authorization Data Flows

**Process 2.0 - Authenticate User:**
- **Input**: Login Credentials
- **Processes**:
  - 2.1: Validate Credentials (query D1)
  - 2.2: Verify Password
  - 2.3: Create Session → D2
  - 2.4: Check Authorization (query D2)
  - 2.5: Check Role Permissions (query D1)
- **Output**: Session Token, Authorization Status

---

## 8. Level 2 DFD - AI Features

```
                    ┌──────────────┐
                    │   RESIDENT   │
                    └──────┬───────┘
                           │
                           │ Booking Input
                           │ (facility_id, date, time_slot)
                           │
                           ▼
                    ┌──────────────┐
                    │ 9.1          │
                    │ Conflict     │
                    │ Detection    │
                    └──────┬───────┘
                           │
                           │ Query Reservations
                           │
                           ▼
                    ┌───────┐
                    │  D3   │
                    │Reservations│
                    └───┬───┘
                        │
                        │ Existing Bookings
                        │
                        ▼
                    ┌──────────────┐
                    │ 9.1.1        │
                    │ Check Exact  │
                    │ Conflicts    │
                    └──────┬───────┘
                           │
                           │ Conflict Status
                           │
                           ▼
                    ┌──────────────┐
                    │ 9.1.2        │
                    │ Calculate    │
                    │ Risk Score   │
                    └──────┬───────┘
                           │
                           │ Query Historical Data
                           │
                           ▼
                    ┌───────┐
                    │  D3   │
                    │Reservations│
                    └───┬───┘
                        │
                        │ Historical Bookings
                        │
                        ▼
                    ┌──────────────┐
                    │ 9.1.3        │
                    │ Find         │
                    │ Alternative  │
                    │ Slots        │
                    └──────┬───────┘
                           │
                           │ Alternative Slots
                           │
                           ▼
                    ┌──────────────┐
                    │   RESIDENT   │
                    │ (See Warning)│
                    └──────┬───────┘
                           │
                           │ Purpose Text
                           │
                           ▼
                    ┌──────────────┐
                    │ 9.2          │
                    │ Facility     │
                    │ Recommendation│
                    └──────┬───────┘
                           │
                           │ Query Facilities
                           │
                           ▼
                    ┌───────┐
                    │  D4   │
                    │Facilities│
                    └───┬───┘
                        │
                        │ Facility Data
                        │
                        ▼
                    ┌──────────────┐
                    │ 9.2.1        │
                    │ Match        │
                    │ Purpose      │
                    │ Keywords     │
                    └──────┬───────┘
                           │
                           │ Matched Facilities
                           │
                           ▼
                    ┌──────────────┐
                    │ 9.2.2        │
                    │ Match        │
                    │ Capacity     │
                    └──────┬───────┘
                           │
                           │ Capacity Matched
                           │
                           ▼
                    ┌──────────────┐
                    │ 9.2.3        │
                    │ Match        │
                    │ Amenities    │
                    └──────┬───────┘
                           │
                           │ Amenity Matched
                           │
                           ▼
                    ┌──────────────┐
                    │ 9.2.4        │
                    │ Calculate    │
                    │ Popularity   │
                    │ Score        │
                    └──────┬───────┘
                           │
                           │ Query Recent Bookings
                           │
                           ▼
                    ┌───────┐
                    │  D3   │
                    │Reservations│
                    └───┬───┘
                        │
                        │ Booking History
                        │
                        ▼
                    ┌──────────────┐
                    │ 9.2.5        │
                    │ Calculate    │
                    │ Total Match  │
                    │ Score        │
                    └──────┬───────┘
                           │
                           │ Sorted Recommendations
                           │
                           ▼
                    ┌──────────────┐
                    │   RESIDENT   │
                    │ (See Top 5   │
                    │  Recommendations)│
                    └──────────────┘
```

### AI Features Data Flows

**Process 9.1 - Conflict Detection:**
- **Input**: Booking Input (facility_id, date, time_slot)
- **Processes**:
  - 9.1.1: Check Exact Conflicts (query D3)
  - 9.1.2: Calculate Risk Score (query D3 historical)
  - 9.1.3: Find Alternative Slots
- **Output**: Conflict Status, Risk Score, Alternative Slots

**Process 9.2 - Facility Recommendation:**
- **Input**: Purpose Text, Expected Attendance, Amenities
- **Processes**:
  - 9.2.1: Match Purpose Keywords (query D4)
  - 9.2.2: Match Capacity (query D4)
  - 9.2.3: Match Amenities (query D4)
  - 9.2.4: Calculate Popularity Score (query D3)
  - 9.2.5: Calculate Total Match Score
- **Output**: Top 5 Recommended Facilities

---

## 9. Data Dictionary

### Data Stores

| Data Store | Description | Key Attributes |
|------------|-------------|----------------|
| **D1 - Users** | User account information | id, name, email, mobile, password_hash, role, status, profile_picture, created_at, updated_at |
| **D2 - Sessions** | Active user sessions | session_id, user_id, created_at, expires_at |
| **D3 - Reservations** | Facility reservation requests | id, user_id, facility_id, reservation_date, time_slot, purpose, status, created_at, updated_at |
| **D4 - Facilities** | Facility information | id, name, description, image_path, location, capacity, amenities, rules, status, image_citation, created_at, updated_at |
| **D5 - Notifications** | User notifications | id, user_id, type, title, message, link, is_read, created_at |
| **D6 - User Documents** | Uploaded user documents | id, user_id, document_type, file_path, file_name, file_size, uploaded_at |
| **D7 - Audit Log** | System audit trail | id, user_id, action, module, details, ip_address, user_agent, created_at |
| **D8 - Reservation History** | Reservation status history | id, reservation_id, status, note, created_by, created_at |

### Data Flows

| Data Flow | Source | Destination | Description |
|-----------|--------|-------------|-------------|
| Registration Data | Resident | 1.0 User Registration | name, email, password, mobile, documents |
| Login Credentials | Resident/Admin/Staff | 2.0 Authenticate User | email, password |
| Booking Request | Resident | 3.0 Book Facility | facility_id, date, time_slot, purpose |
| Approval Decision | Admin/Staff | 4.0 Approve Reservations | reservation_id, action (approve/deny), note |
| Facility Data | Admin/Staff | 5.0 Manage Facilities | name, description, image, location, capacity, amenities, rules, status |
| User Status Update | Admin/Staff | 6.0 Manage Users | user_id, status (approve/deny/lock) |
| Session Data | 2.0 Authenticate User | D2 Sessions | session_id, user_id, authentication token |
| User Record | 1.0 User Registration | D1 Users | Complete user record with status 'pending' |
| Reservation Record | 3.0 Book Facility | D3 Reservations | Complete reservation record with status 'pending' |
| Notification | 8.0 Generate Notifications | D5 Notifications | Notification for user about status change |
| Audit Record | Various Processes | D7 Audit Log | Action, module, details, user_id |
| Conflict Result | 9.1 Conflict Detection | Resident | hasConflict, riskScore, alternatives |
| Recommendations | 9.2 Facility Recommendation | Resident | Top 5 facilities with match scores |

### External Entities

| Entity | Description | Interactions |
|--------|-------------|--------------|
| **Resident** | End users who register and book facilities | - Register account<br>- Login<br>- Book facilities<br>- View reservations<br>- Update profile |
| **Admin/Staff** | System administrators and staff | - Approve/deny reservations<br>- Manage facilities<br>- Manage users<br>- View reports<br>- Access audit logs |

---

## DFD Summary

### System Boundaries
- **Internal**: All processes, data stores, and data flows within the system
- **External**: Residents, Admin/Staff (external entities)

### Key Data Flows
1. **Registration Flow**: Resident → Registration Data → User Record → Pending Status
2. **Authentication Flow**: User → Credentials → Session → Authorization
3. **Booking Flow**: Resident → Booking Request → Reservation → Pending Status
4. **Approval Flow**: Admin/Staff → Decision → Status Update → Notification
5. **AI Flow**: User Input → Analysis → Recommendations/Warnings

### Data Store Relationships
- **Users (D1)** → **Reservations (D3)** (one-to-many)
- **Facilities (D4)** → **Reservations (D3)** (one-to-many)
- **Reservations (D3)** → **Reservation History (D8)** (one-to-many)
- **Users (D1)** → **User Documents (D6)** (one-to-many)
- **Users (D1)** → **Audit Log (D7)** (one-to-many)
- **Users (D1)** → **Notifications (D5)** (one-to-many)

---

## End of Document


