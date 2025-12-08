# Facility Management Module (Front-End Blueprint)

## Page
- `resources/views/pages/dashboard/facility_management.php`
- Accessible through sidebar link “Facility Management” or `/dashboard/facilities`.

## Sections
1. **Facility Listing**
   - Displays each venue with capacity, rate, health status badge (Active / Maintenance / Offline).
   - Feature tags highlight amenities (AV Ready, Locker Rooms, etc.).
   - Availability toggle placeholder shows how admins/staff can quickly mark slots (UI only).
   - “Edit Details” button reserved for future modal/inline editing.
2. **Facility Form**
   - Form fields to add or update a facility (name, capacity, rate, description, status).
   - Currently mock-only; hook to backend later.
3. **Audit Log**
   - Lists recent actions (rate changes, maintenance flags, new uploads) to align with the Audit Trail module later.

## Styling Hooks
- `.facility-admin` grid splits listing + admin tools, collapses on mobile.
- `.status-badge` classes: `active`, `maintenance`, `offline`.
- `.facility-tag`, `.availability-toggle`, `.audit-list` ready for dynamic data binding.

## Integration Points
- **Facility Data Store:** Replace static `$facilities` array with DB or API response.
- **Maintenance Scheduling:** Tie status changes to scheduling calendar and notification triggers.
- **Reservation Module:** Share facility metadata with booking forms to ensure consistent display.
- **Audit Trail Module:** Feed actual actions into a persistent log for transparency.
- **Image/Asset Management:** Extend the form to upload and manage facility media under `/public/img`.

## Next Steps
1. Connect “Save Facility” to controller/endpoint for create/update operations.
2. Implement modal for “Edit Details,” reusing the same form with populated data.
3. Sync availability toggle with realtime status (e.g., disable booking when marked maintenance).
4. Pipe audit entries into centralized audit service once backend is available.



