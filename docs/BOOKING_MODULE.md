# Reservation & Booking Module (Front-End Blueprint)

## Page Location
- `resources/views/pages/dashboard/book_facility.php`
- Linked via sidebar entry “Book a Facility” and route `/dashboard/book`.

## Layout Sections
1. **Reservation Details Panel**
   - Facility dropdown with sample LGU venues.
   - Date selector, time-slot dropdown, purpose text area, supporting document field.
   - “Submit Booking Request” button (no backend hook yet).
2. **Availability Snapshot**
   - Mini calendar grid showing available, blocked, and pending statuses.
   - “View Full Calendar” button reserved for the Scheduling module.
   - Legend clarifies color coding.
3. **Approval Flow Sidebar**
   - Timeline outlining Request → Validation → Approval/Denial.
   - Notification cards describing email/SMS hooks and conflict detection trigger points.

## UI Behaviors
- Uses `.booking-wrapper` grid for two-column layout, collapsing to one column below 960px.
- Calendar cells (`.schedule-cell`) support status classes: `available`, `unavailable`, `requested`.
- Timeline items highlight the sequential steps a booking passes through.

## Integration Touchpoints
- **Facility Management:** populate facility dropdown with real data + capacity/rate.
- **Reservation Workflow:** wire the submit button to create reservation requests and update `My Reservations`.
- **Calendar & Scheduling:** replace snapshot with interactive full calendar, feed conflict detection.
- **Notifications Module:** trigger alerts based on timeline milestones.
- **AI Predictive Scheduling:** leverage booking history to auto-suggest time slots before submission.

## Adding Logic Later
1. Replace placeholder arrays (`$facilities`, `$calendar`, `$timeline`) with dynamic data.
2. Convert buttons to forms pointing at actual controllers/endpoints.
3. Inject status badges or alerts when a slot is blocked/conflicting in real time.
4. Embed calendar library (FullCalendar, etc.) once backend APIs exist.



