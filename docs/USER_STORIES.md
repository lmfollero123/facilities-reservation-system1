# User Stories (Completed System)

## Registration & Access Control
- As a resident, I can register with my name, email, password, mobile, and Barangay Culiat address so only eligible residents can sign up.
- As a resident, I must upload at least one supporting document (Birth Cert, Valid ID, Barangay ID, Resident ID) so my residency can be verified.
- As a resident, I see a confirmation that my account is pending after registration so I know I need approval before login.
- As an admin/staff, I can view pending users and their uploaded documents so I can verify residency.
- As an admin/staff, I can approve/deny/lock a user and add notes so I can control access based on verification.
- As a resident, I receive an approval email/notification when my account is activated so I know I can log in.
- As a resident, I log in with email/password and must enter a one-time passcode sent to my email so only I can complete sign-in.
- As a resident, I can update my profile (name, contact, address, coordinates, profile picture) so my information stays current.

## Booking & Recommendations
- As a resident, I can book a facility by selecting date, time slot, and purpose so I can request usage of a venue.
- As a resident, I see AI conflict warnings and alternative time slots when a booking conflicts so I can adjust my request.
- As a resident, I see AI facility recommendations (including distance) based on my purpose/location so I can pick suitable venues.
- As a resident, I can view my upcoming/pending reservations and their statuses so I can track my requests.
- As an admin/staff, I can view pending reservations, open details, add notes, and approve/deny so I can manage requests.
- As an admin/staff, I see a history/timeline for reservations so I can audit what changed.
- As an admin/staff, I can auto-decline expired pending reservations so stale requests are cleaned up.
- As a resident, I receive notifications when my reservation is approved/denied so I stay informed.

## Facility Management & Public View
- As a resident, I can view public facility listings with images, citations (hover to see full link), and glass-morphism background so I can browse venues.
- As an admin/staff, I can add/edit facilities with location (lat/long) and image citation so details are accurate.
- As an admin/staff, I can see recent facility activity with pagination so I can review changes.

## Calendar, Reports, and Audit
- As any user, I can view the calendar and click events to open reservation details so I can inspect bookings by date.
- As a user, I can export reports (CSV/HTML-to-PDF) from quick actions so I can share data.
- As an admin/staff, I can view audit trails with pagination and filters so I can trace actions.
- As an admin/staff, I can view notifications via the panel and mark them read so I can keep track of events.

## UX, Mobile, and Layout
- As any user, I see role/status badges with clear contrast so roles are visible.
- As any user, I experience mobile-friendly layouts (responsive tables, collapsible sections, non-pushing sidebar) so the app is usable on phones.
- As a user, I can toggle collapsible sections on dashboards/facility management so I reduce scrolling.
- As a user, I see the correct landing background (cityhall) on public pages so the branding is consistent.

## Security & Reliability
- As a user, I get CSRF protection, rate limiting, and security headers so my interactions are safer.
- As a user uploading files (profile pic/docs/facility images), I benefit from file validation (type/size) so uploads are safe.
- As a security auditor, I see audit logs for key actions (logins, approvals, reservations) so activity is traceable.

## Testing & Debug
- As a resident (tester), I can see AI recommendation debug info on the test page so I can verify location-based scoring.


