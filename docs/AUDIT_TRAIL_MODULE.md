# Audit Trail Module (Front-End Blueprint)

## Page
- `resources/views/pages/dashboard/audit_trail.php`
- Sidebar entry: “Audit Trail”
- Route hint: `/dashboard/audit`

## Purpose
- Provide LGU officials with a transparent view of system activities across modules.
- Support compliance and governance requirements by exposing who did what and when.

## Layout
1. **Activity Log (left)**
   - Table columns:
     - Date & Time
     - User
     - Action
     - Module
     - Details (reservation ID, facility name, OR number, etc.)
   - Backed by a static `$entries` array for now.

2. **Scope of Tracking (right)**
   - List of the types of events expected to be logged:
     - Reservation lifecycle changes.
     - Facility updates.
     - Payment recordings and verifications.
     - User account approvals and role changes.
     - Notification sends and system advisories.

## Integration Points
- **Event Logging Service:** Backend should append entries to a persistent audit store.
- **Reports & Analytics:** Use audit data to support governance reports when needed.
- **User Management & Permissions:** Ensure only authorized roles can view detailed audit logs.

## Next Steps
1. Replace static entries with real audit records from the backend.
2. Add filtering by date range, module, and user.
3. Consider export functionality for formal reporting (PDF/CSV).



