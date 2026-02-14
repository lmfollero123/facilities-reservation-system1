# Booking Rules, Limitations, and Anti-Abuse Measures

**Barangay Culiat Public Facilities Reservation System**

This document details the booking rules, limitations, and anti-abuse mechanisms implemented in the system. These controls ensure fair access to barangay facilities, prevent resource hogging, and protect against misuse.

---

## Table of Contents

1. [Booking Rules & Limitations](#1-booking-rules--limitations)
2. [Conflict Detection](#2-conflict-detection)
3. [Auto-Approval Conditions](#3-auto-approval-conditions)
4. [Reschedule Rules](#4-reschedule-rules)
5. [Post-Booking Controls](#5-post-booking-controls)
6. [User Violation Tracking](#6-user-violation-tracking)
7. [Anti-Abuse & Security](#7-anti-abuse--security)
8. [Configuration Reference](#8-configuration-reference)

---

## 1. Booking Rules & Limitations

### 1.1 Active Booking Cap

| Rule | Value | Description |
|------|-------|-------------|
| **Limit** | 3 | Maximum active reservations (pending + approved) per user |
| **Window** | 30 days | Rolling window from today |
| **Counted statuses** | `pending`, `approved` | `postponed` and `on_hold` do not count |

**Purpose:** Prevents users from monopolizing facilities by holding multiple bookings. Ensures fair distribution of availability among residents.

**Enforcement:** Server-side validation in `book_facility.php` before reservation creation. Blocked with error: *"Limit reached: You can have up to 3 active reservations (pending/approved) within the next 30 days."*

---

### 1.2 Advance Booking Window

| Rule | Value | Description |
|------|-------|-------------|
| **Minimum** | Today | No past-date bookings |
| **Maximum** | 60 days | Bookings allowed only up to 60 days in advance |

**Purpose:** Balances planning needs with fairness. Prevents users from booking far in advance and blocking others.

**Enforcement:** Date validation rejects any reservation where `date < today` or `date > today + 60 days`.

---

### 1.3 Per-Day Booking Limit

| Rule | Value | Description |
|------|-------|-------------|
| **Limit** | 1 | Maximum bookings per user per calendar date |
| **Counted statuses** | `pending`, `approved` | Across all facilities |

**Purpose:** Prevents "backup booking" abuse (booking multiple facilities/slots for the same event) and ensures equitable daily access.

**Enforcement:** Server-side check before reservation creation. Blocked with error: *"Limit reached: You can only have 1 booking on this date."*

---

### 1.4 Reservation Duration Limits

| Rule | Value | Description |
|------|-------|-------------|
| **Minimum** | 30 minutes | Reservations must be at least 30 minutes |
| **Maximum** | 12 hours | Reservations cannot exceed 12 hours |
| **Auto-approval** | Facility-defined | Many facilities use 4-hour max for auto-approval |

**Purpose:** Prevents extremely short "placeholder" bookings and long-term monopolization of facilities.

---

### 1.5 Facility Status Checks

| Status | Effect |
|--------|--------|
| **available** | Can be booked |
| **maintenance** | Booking blocked – facility under maintenance |
| **offline** | Booking blocked – facility unavailable |

**Enforcement:** Facility status is checked before allowing any reservation. Users receive a clear error message directing them to select a different facility.

---

### 1.6 ID Verification Requirement

- **Unverified users** must upload a valid government-issued ID when making their first reservation.
- **Verified users** (ID approved by admin) can book without re-uploading.
- **Staff and Admin** are automatically considered verified.
- Users with an existing pending ID upload cannot submit another until verified.

---

## 2. Conflict Detection

### 2.1 Hard Conflicts (Blocking)

- **Definition:** An approved reservation already exists for the same facility, date, and overlapping time slot.
- **Effect:** Booking is **blocked**. User cannot proceed.
- **Overlap logic:** Two time ranges overlap if `start1 < end2 AND start2 < end1`.
- **Alternatives:** System calculates available time slots and suggests alternatives (minimum 30-minute gaps).

### 2.2 Soft Conflicts (Warning Only)

- **Definition:** One or more **pending** reservations exist for the same facility, date, and overlapping time slot.
- **Effect:** User receives a **warning** but can still submit. Admin will approve only one when multiple pending requests exist for the same slot.
- **Rationale:** Allows multiple users to request popular slots; admin decides based on priority, purpose, or order.

### 2.3 Risk Scoring

- System calculates a **risk score** (0–100) based on historical demand and pending count.
- High-demand periods (e.g., holidays, weekends) may show elevated risk.
- Risk score is surfaced in the UI to inform users and staff.

---

## 3. Auto-Approval Conditions

Reservations that meet **all** of the following conditions may be **automatically approved** (when the facility has auto-approval enabled):

| # | Condition | Description |
|---|-----------|-------------|
| 1 | Facility auto-approve enabled | Facility must have `auto_approve = true` |
| 2 | Not in blackout dates | Reservation date must not be in `facility_blackout_dates` |
| 3 | Duration within limit | Reservation duration ≤ facility `max_duration_hours` |
| 4 | Attendees within capacity | `expected_attendees` ≤ facility `capacity_threshold` (if set) |
| 5 | Non-commercial purpose | `is_commercial = false` – commercial bookings always require manual approval |
| 6 | No conflict | No overlapping **approved** reservation for same facility/date/slot |
| 7 | No user violations | User has no high or critical violations in the last 365 days |
| 8 | User verified | User has submitted and been verified with valid ID (or is Staff/Admin) |
| 9 | Within advance window | Date is within allowed advance booking period (60 days) |

**ML Risk Override:** If an ML risk model is enabled and returns high risk with confidence > 70%, auto-approval is overridden and the reservation remains pending for manual review.

---

## 4. Reschedule Rules

### 4.1 Eligibility

- **Statuses allowed:** `pending`, `approved`, `postponed` only.
- **Not allowed:** `denied`, `cancelled`, `on_hold`, or reservations that have already started.

### 4.2 Reschedule Limits

| Rule | Value | Description |
|------|-------|-------------|
| **Reschedule count** | 1 per reservation | Only one reschedule is allowed per reservation |
| **Time window** | Up to 3 days before | Rescheduling allowed only if the event is at least 3 days away |
| **Same-day** | Not allowed | Same-day rescheduling is blocked |
| **Ongoing** | Not allowed | Reservations that have started cannot be rescheduled |

### 4.3 Re-Approval

- **Approved** or **postponed** reservations that are rescheduled return to **pending** status and require re-approval.
- **Pending** reservations remain pending after reschedule.
- Conflict check is performed on the new date/time slot before applying the reschedule.

---

## 5. Post-Booking Controls

### 5.1 Edit Details (Purpose / Attendees)

- Users can edit **purpose** and **expected attendees** on pending, approved, or postponed reservations.
- **Re-approval trigger:** If the new attendee count exceeds the facility `capacity_threshold` and the reservation was approved, it reverts to pending for re-approval.
- Edit is logged in reservation history and audit trail.

### 5.2 Auto-Decline Expired Reservations

- **Triggered:** On load of My Reservations page (and can be run via cron).
- **Logic:** Pending or postponed reservations where the reservation date/time has passed are automatically set to **denied**.
- **Note:** *"Automatically denied: Reservation date/time has passed without approval."*
- Prevents stale pending requests from blocking slots.

### 5.3 Cancellation

- Users can cancel their own reservations (subject to status).
- Cancellations are logged. Late cancellations (< 3 days before) may be recorded as violations.

---

## 6. User Violation Tracking

### 6.1 Violation Types

| Type | Description |
|------|-------------|
| `no_show` | User did not arrive for an approved reservation |
| `late_cancellation` | Cancelled less than 3 days before the event |
| `policy_violation` | Misused facility or violated terms |
| `damage` | Caused damage to the facility |
| `other` | Other infractions |

### 6.2 Severity Levels

| Severity | Effect |
|----------|--------|
| **Low** | Warning only; no automatic restrictions |
| **Medium** | May affect auto-approval or future reviews |
| **High** | Auto-approval **disabled** for the user (365 days) |
| **Critical** | Auto-approval **disabled**; may lead to account restrictions |

### 6.3 No-Show Recording

- Admins can manually record no-shows from the reservation detail page.
- A scheduled job or manual process can auto-record no-shows when an approved reservation date has passed and the user did not attend.
- Each no-show is stored with severity and description for audit.

---

## 7. Anti-Abuse & Security

### 7.1 Login Rate Limiting

| Setting | Value | Description |
|---------|-------|-------------|
| **Max attempts** | 5 | Per email address |
| **Window** | 15 minutes | Rolling window |
| **Storage** | `rate_limits` table | Database-backed |

**Effect:** After 5 failed login attempts within 15 minutes, further attempts for that email are blocked until the window expires.

### 7.2 Account Lockout (Failed Logins)

| Setting | Value | Description |
|---------|-------|-------------|
| **Trigger** | 5 failed attempts | For a given email |
| **Lock duration** | 30 minutes | Automatic unlock after |
| **Storage** | `users.locked_until` | Per-user lock expiry |
| **Notification** | Email sent | User notified of lockout |

**Effect:** User cannot log in until `locked_until` has passed. Admin can also manually lock/unlock accounts.

### 7.3 Registration Rate Limiting

| Setting | Value | Description |
|---------|-------|-------------|
| **Max attempts** | 3 | Per IP address |
| **Window** | 1 hour | Rolling window |

**Effect:** Prevents mass account creation from a single IP (e.g., bot signups).

### 7.4 CSRF Protection

- All state-changing forms require a valid CSRF token.
- Token expiry: 1 hour.
- Invalid or expired tokens cause the request to be rejected.

### 7.5 Session Timeout

- **Idle timeout:** 30 minutes (configurable via `SESSION_TIMEOUT`).
- Expired sessions require re-login.

### 7.6 Password Requirements

- Minimum 8 characters.
- Requires uppercase, lowercase, and number.
- Optional: special character requirement (configurable).

---

## 8. Configuration Reference

### 8.1 Booking Limits (book_facility.php)

```php
$BOOKING_LIMIT_ACTIVE = 3;        // Max active (pending+approved) in window
$BOOKING_LIMIT_WINDOW_DAYS = 30;  // Rolling window for active bookings
$BOOKING_ADVANCE_MAX_DAYS = 60;   // Max days ahead
$BOOKING_PER_DAY = 1;             // Max bookings per user per day
```

### 8.2 Security (config/security.php)

```php
RATE_LIMIT_LOGIN_ATTEMPTS = 5;      // Max login attempts per email
RATE_LIMIT_LOGIN_WINDOW = 900;      // 15 minutes (seconds)
RATE_LIMIT_REGISTER_ATTEMPTS = 3;   // Max registration attempts per IP
RATE_LIMIT_REGISTER_WINDOW = 3600;  // 1 hour (seconds)
SESSION_TIMEOUT = 1800;             // 30 minutes (seconds)
```

### 8.3 Key Files

| Component | File(s) |
|-----------|---------|
| Booking limits & validation | `resources/views/pages/dashboard/book_facility.php` |
| Conflict detection | `config/ai_helpers.php` (`detectBookingConflict`) |
| Auto-approval evaluation | `config/auto_approval.php` |
| Reschedule logic | `resources/views/pages/dashboard/my_reservations.php` |
| Violation recording | `config/violations.php` |
| Auto-decline expired | `config/reservation_helpers.php` |
| Rate limiting & security | `config/security.php` |

---

*Document version: 1.0 | Last updated: January 2025*
