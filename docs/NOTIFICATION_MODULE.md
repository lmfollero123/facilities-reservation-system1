# Notification Module (Front-End Blueprint)

## Page & Entry Points
- Notifications Center page: `resources/views/pages/dashboard/notifications.php`
- Header bell icon in `navbar_dashboard.php` with unread dot indicator.
- Route hint: `/dashboard/notifications`

## Purpose
- Provide a unified inbox for reservation-related and system notifications.
- Serve as the visual anchor for future email/SMS/in-app notification logic.

## Layout
1. **Recent Alerts (left)**
   - Table listing:
     - Type (Booking, System, etc.)
     - Title
     - Details
     - Received timestamp
   - Currently powered by static `$notifications` array (to be replaced by live data).

2. **Notification Types Summary (right)**
   - Describes categories:
     - Booking Updates (approvals, denials, changes)
     - Reminders (upcoming reservations, document deadlines)
     - System Notices (maintenance, policy updates)

## Header Bell
- Located in `navbar_dashboard.php`:
  - `notif-bell` button with a small red `notif-dot` indicating unread items.
  - Click navigates to the Notifications Center page.

## Integration Points
- **Backend Notification Service:** Feed real notifications to the center and unread count to the bell.
- **Email/SMS Gateways:** Use the same events that populate this UI to drive outbound messaging.
- **Audit Trail:** Log notification sends and reads for transparency.
- **User Preferences:** Later extend to allow per-user notification settings (channels, frequency).

## Next Steps
1. Replace sample notification data with dynamic queries.
2. Add read/unread state (styling and toggles).
3. Surface per-module links (e.g., “View Reservation” from a booking notification).



