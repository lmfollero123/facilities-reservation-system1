# Integration Requirements
## Facilities Reservation System ↔ Other LGU Systems

**Version:** 1.0  
**System:** Barangay Culiat Public Facilities Reservation System

---

## A. ONE-WAY CONNECTIONS

### 1. Maintenance → Facilities Reservation

**Process Name:** Facility Availability Control

**Description:**
The Maintenance Module informs the Facilities Reservation Module about facilities that are temporarily unavailable due to maintenance activities.

**Data Being Sent:**
- Facility ID
- Facility Name
- Barangay (e.g., Barangay Culiat)
- Maintenance Status (Under Maintenance / Restricted / Closed)
- Maintenance Start Date & Time
- Maintenance End Date & Time
- Maintenance Type (Repair / Cleaning / Inspection / Emergency)

**Data Being Received:**
- None

**Business Rule Impact:**
- Facilities under maintenance are automatically blocked from being reserved.
- Existing pending reservations during the maintenance period are flagged or auto-cancelled.
- Approved reservations during maintenance period are automatically postponed with priority status.
- Users with affected reservations are automatically notified via email and in-app notifications.

---

### 2. Infrastructure Management → Facilities Reservation

**Process Name:** Facility Closure and Creation Control

**Description:**
The Infrastructure Management Module informs the Facilities Reservation Module about facility closures due to construction/renovation projects and new facilities created upon project completion.

**Data Being Sent:**
- Project ID
- Project Name
- Affected Facility IDs (for closures)
- Project Start Date & Time
- Project End Date & Time
- Project Phase (Planning / Construction / Completion)
- Impact Type (Full Closure / Partial Closure / Access Restricted)
- New Facility Information (Name, Type, Location, Capacity, Amenities) - when project completes

**Data Being Received:**
- None

**Business Rule Impact:**
- Affected facilities are automatically blocked during construction/renovation periods.
- Facilities are automatically set to unavailable status during project timeline.
- Users with existing reservations are notified about facility closures.
- New facilities are automatically created and added to the system when projects complete.
- Facility capacity and amenities are automatically updated after expansion/renovation projects.

---

### 3. Utilities Billing → Facilities Reservation

**Process Name:** Utility Outage Facility Blocking

**Description:**
The Utilities Billing Module informs the Facilities Reservation Module about utility outages (power, water) that affect facility availability.

**Data Being Sent:**
- Outage ID
- Outage Type (Power Outage / Water Interruption / All Utilities)
- Affected Facility IDs
- Outage Start Date & Time
- Outage End Date & Time
- Outage Reason
- Outage Status (Scheduled / Ongoing / Resolved)
- Emergency Level (Low / Medium / High)

**Data Being Received:**
- None

**Business Rule Impact:**
- Affected facilities are automatically blocked during utility outages.
- Facilities are set to unavailable status during outage periods.
- Users with reservations during outages are automatically notified.
- Facility availability is automatically restored when outage is resolved.

---

### 4. Facilities Reservation → Maintenance

**Process Name:** Facility Usage Reference

**Description:**
The Facilities Reservation Module provides usage information to assist the Maintenance Module in planning maintenance schedules.

**Data Being Sent:**
- Facility ID
- Total Number of Reservations (last 30/90 days)
- Last Reservation End Date
- Reservation Frequency (High / Medium / Low)
- Peak Usage Dates
- Average Hours Per Reservation
- Capacity Utilization Rate

**Data Being Received:**
- None

**Business Rule Impact:**
- Maintenance is scheduled during low-usage periods.
- High-usage facilities are prioritized for inspection.
- Maintenance windows are optimized to minimize booking disruptions.

---

### 5. Facilities Reservation → Infrastructure Management

**Process Name:** Facility Utilization Analytics

**Description:**
The Facilities Reservation Module provides facility usage statistics and demand patterns to support infrastructure planning decisions.

**Data Being Sent:**
- Facility ID
- Booking Statistics (Total bookings, Average per month)
- Peak Usage Days/Times
- Capacity Utilization Percentage
- Reservation Trends (Most requested types, Seasonal patterns)
- Unmet Demand (Denied/Cancelled requests count)

**Data Being Received:**
- None

**Business Rule Impact:**
- Infrastructure planning decisions are informed by actual usage data.
- New facility construction priorities are based on demand analysis.
- Capacity expansion decisions are supported by utilization statistics.

---

### 6. Facilities Reservation → Utilities Billing

**Process Name:** Facility Usage Data for Billing

**Description:**
The Facilities Reservation Module provides facility usage data to assist the Utilities Billing Module in tracking utility consumption and billing reconciliation.

**Data Being Sent:**
- Facility ID
- Reservation ID
- Booking Date & Time (Start and End)
- Duration (Hours)
- Expected Attendees
- Facility Type
- Purpose Type (Commercial / Non-Commercial)
- Actual Usage (if available: actual attendance, actual end time)

**Data Being Received:**
- None

**Business Rule Impact:**
- Utility consumption can be tracked per reservation.
- Facility usage costs can be included in utility bills (if applicable).
- Energy usage reporting provides insights for facility operations.

---

## B. TWO-WAY CONNECTIONS

### 7. Reservation Completion ↔ Maintenance Scheduling

**Process Name:** Post-Use Maintenance Trigger

**Description:**
After a facility is used, the Reservation Module notifies the Maintenance Module, which may create a maintenance task. The Maintenance Module then responds by updating availability status if maintenance is scheduled.

**Data Sent by Facilities Reservation → Maintenance:**
- Facility ID
- Reservation ID
- Reservation End Date & Time
- Usage Type (Event / Sports / Meeting / Commercial)
- Number of Attendees
- Facility Condition Notes (if any issues reported)

**Data Sent by Maintenance → Facilities Reservation:**
- Maintenance Task ID
- Maintenance Status (Scheduled / Ongoing / Completed)
- Maintenance Start Date & Time
- Maintenance End Date & Time
- Availability Flag (Available / Unavailable)

**Business Rule Impact:**
- Automatic cleaning or inspection tasks are triggered after facility use.
- Facility is temporarily blocked until post-use maintenance is completed.
- Maintenance schedules are dynamically adjusted based on actual usage.

---

### 8. Maintenance Completion ↔ Reservation Availability Update

**Process Name:** Facility Re-Availability Synchronization

**Description:**
Once maintenance is completed, the Maintenance Module updates the Reservation Module to reopen the facility for booking. The Reservation Module confirms the update and provides next booking information.

**Data Sent by Maintenance → Facilities Reservation:**
- Facility ID
- Maintenance Completion Date & Time
- Updated Facility Status (Available)
- Maintenance Notes (Summary of work done)
- Next Recommended Maintenance Date (if scheduled)

**Data Sent by Facilities Reservation → Maintenance:**
- Availability Confirmation Flag
- Next Reserved Date (if any)
- Confirmation Timestamp

**Business Rule Impact:**
- Facility immediately appears as available for new reservations.
- Prevents manual re-activation by administrators (automated process).
- Users with postponed reservations are notified that facility is available again.
- Postponed reservations with priority status are prioritized for re-booking.

---

### 9. Infrastructure Project Completion ↔ Facility Creation

**Process Name:** New Facility Integration

**Description:**
When an infrastructure project is completed, the Infrastructure Management Module sends new facility details. The Facilities Reservation Module creates the facility and confirms availability back to Infrastructure Management.

**Data Sent by Infrastructure Management → Facilities Reservation:**
- Project ID
- Facility Name
- Facility Type
- Location Address
- Location Coordinates (Latitude, Longitude)
- Capacity
- Facility Description
- Amenities List
- Available Date (When facility becomes bookable)
- Facility Images (optional)

**Data Sent by Facilities Reservation → Infrastructure Management:**
- Facility ID (System-generated identifier)
- Creation Confirmation Flag
- Availability Status (Available)
- Creation Timestamp

**Business Rule Impact:**
- New facilities are automatically added to the reservation system.
- Facilities are immediately available for public booking.
- No manual data entry required for new facilities.
- Facility information is synchronized across systems.

---

### 10. Utility Outage Resolution ↔ Facility Availability Restoration

**Process Name:** Outage Resolution Synchronization

**Description:**
When a utility outage is resolved, the Utilities Billing Module notifies the Facilities Reservation Module. The Reservation Module confirms restoration and updates facility availability status.

**Data Sent by Utilities Billing → Facilities Reservation:**
- Outage ID
- Resolution Date & Time
- Affected Facility IDs
- Resolution Status (Resolved)
- Resolution Notes

**Data Sent by Facilities Reservation → Utilities Billing:**
- Availability Restoration Confirmation
- Facilities Restored Count
- Confirmation Timestamp

**Business Rule Impact:**
- Facilities are automatically restored to available status.
- Booking restrictions are automatically removed.
- Affected users are notified that facilities are available again.
- System automatically resumes normal booking operations.

---

## C. INTEGRATION SUMMARY TABLE (FOR DEFENSE SLIDE)

| Connection Type | Direction | Process Name | Key Data Exchanged |
|---|---|---|---|
| **Availability Blocking** | One-Way | Facility Availability Control | Maintenance status, dates, type |
| **Usage Reference** | One-Way | Facility Usage Reference | Reservation frequency, peak usage dates |
| **Facility Closure Control** | One-Way | Facility Closure and Creation Control | Project timeline, affected facilities |
| **Utilization Analytics** | One-Way | Facility Utilization Analytics | Booking statistics, demand patterns |
| **Utility Outage Blocking** | One-Way | Utility Outage Facility Blocking | Outage type, dates, affected facilities |
| **Usage Data for Billing** | One-Way | Facility Usage Data for Billing | Reservation details, duration, attendees |
| **Post-Use Maintenance** | Two-Way | Post-Use Maintenance Trigger | Reservation end ↔ maintenance schedule |
| **Re-Availability Sync** | Two-Way | Facility Re-Availability Synchronization | Maintenance completion ↔ availability |
| **New Facility Integration** | Two-Way | New Facility Integration | Project completion ↔ facility creation |
| **Outage Resolution Sync** | Two-Way | Outage Resolution Synchronization | Outage resolution ↔ availability restoration |

---

## D. INTEGRATION OVERVIEW

### Total Connections: 10
- **One-Way Connections:** 6
- **Two-Way Connections:** 4

### Integrated Systems:
1. **Community Infrastructure Maintenance Management**
   - 3 connections (1 one-way, 2 two-way)
2. **Infrastructure Management**
   - 3 connections (2 one-way, 1 two-way)
3. **Utilities Billing & Management**
   - 4 connections (3 one-way, 1 two-way)

---

**Document End**
