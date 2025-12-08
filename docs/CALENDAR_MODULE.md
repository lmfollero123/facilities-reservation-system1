# Calendar & Scheduling Module (Front-End Blueprint)

## Page
- `resources/views/pages/dashboard/calendar.php`
- Sidebar entry: “Calendar & Schedule”
- Route hint: `/dashboard/calendar`

## Views
1. **Month View (default)**
   - 7-column grid with day names and day cells.
   - Each day can show a pill event with one of three statuses:
     - `available` — facility can be booked.
     - `blocked` — LGU event / maintenance.
     - `request` — pending reservation.
   - Designed to surface high-level availability and busy days at a glance.

2. **Week View**
   - Tabular layout grouped by time slots (rows) and weekdays (columns).
   - Useful for staff to check overlaps and daily load across facilities.

3. **Day View**
   - Simple vertical schedule listing times and activities for a single day.
   - Ideal for on-duty staff to see what is happening “today.”

## UI Components & Hooks
- `.calendar-shell`, `.calendar-header`, `.calendar-controls` — wrapper, title, navigation buttons.
- `.calendar-tabs [data-calendar-view]` — Month/Week/Day switcher wired via `public/js/main.js`.
- `[data-calendar-container]` — panels toggled by the active tab, using `.calendar-view` and `.active`.
- `.pill-event` with variants: `available`, `blocked`, `request`.
- `.calendar-legend` — shows color mapping for quick reference.

## Integration Points
- **Reservations Data:** Replace static day/event arrays with real reservation + maintenance feeds.
- **Conflict Detection:** Highlight conflicting reservations (e.g., extra badge or color) before confirmation.
- **AI Predictive Scheduling:** Overlay predicted high-demand days with an additional visual indicator.
- **Notifications Module:** Trigger reminders and alerts based on calendar events (upcoming bookings, maintenance windows).

## Next Steps
1. Feed real data into each view and time slot from the backend.
2. Connect calendar navigation (Prev/Next/Today) to update the displayed range.
3. Add click handlers on days/slots to open booking details or create new reservations.
4. Optionally integrate a calendar library later while preserving the existing visual language.



