# User Stories (Completed System)

## Registration & Access Control
- As a resident, I can register with my name, email, password, mobile, and Barangay Culiat address so only eligible residents can sign up.
- As a resident, I must upload at least one supporting document (Valid ID primary; other options removed from UI) so my residency can be verified.
- As a resident, I see the Terms & Conditions (Data Privacy Act) modal automatically and must accept before proceeding so I understand data use.
- As a resident, I see a confirmation that my account is pending after registration so I know I need approval before login.
- As an admin/staff, I can view pending users and their uploaded documents so I can verify residency.
- As an admin/staff, I can approve/deny/lock a user and add notes (lock reason captured) so I can control access based on verification.
- As a resident, I receive an approval email/notification when my account is activated so I know I can log in.
- As a resident, I log in with email/password and must enter a one-time passcode sent to my email so only I can complete sign-in.
- As a resident, I can request a password reset link via email and change my password securely using a token so I can regain access.
- As a locked user, I see the lock reason on the login page and receive an email so I know how to resolve it.
- As a resident, I can update my profile (name, contact, address, coordinates, profile picture) so my information stays current.

## Booking & Recommendations
- As a resident, I can book a facility by selecting date, flexible time range (start_time - end_time), purpose, expected attendees, and commercial purpose flag so I can request usage of a venue with precise timing.
- As a resident, I see AI conflict warnings and alternative time slots (including PH holiday/Barangay Culiat event risk) when a booking conflicts so I can adjust my request.
- As a resident, I see AI facility recommendations (including distance) based on my purpose/location so I can pick suitable venues.
- As a resident, I am prevented from overbooking (≤3 active within 30 days, ≤60-day advance, ≤1 per day) so the system avoids abuse.
- As a resident, my reservation is automatically approved if all conditions are met (facility auto-approve enabled, not in blackout, within duration/capacity limits, non-commercial, no conflicts, no violations, within advance window) so I get immediate confirmation.
- As a resident, I can reschedule my own reservations (up to 3 days before the event, one reschedule per reservation) so I can adjust my booking when needed.
- As a resident, I can view my upcoming/pending reservations and their statuses so I can track my requests.
- As an admin/staff, I can view pending reservations, open details, add notes, and approve/deny so I can manage requests.
- As an admin/staff, I can modify/postpone/cancel approved reservations (with reasons, no past dates) so I can handle emergencies and changes.
- As an admin/staff, I can record user violations (no-show, policy violation, damage, etc.) with severity levels so I can track problematic users and affect their auto-approval eligibility.
- As an admin/staff, I see a history/timeline for reservations so I can audit what changed.
- As an admin/staff, I can always override auto-approval decisions so I maintain full control over reservations.
- As an admin/staff, I can auto-decline expired pending reservations so stale requests are cleaned up.
- As a resident, I receive notifications when my reservation is auto-approved, approved, denied, modified, or postponed so I stay informed.

## Facility Management & Public View
- As a resident, I can view public facility listings with images, citations (hover to see full link), and glass-morphism background so I can browse venues.
- As a resident, I can click dates on facility detail calendars to go to login and then the full calendar so I can continue booking.
- As an admin/staff, I can add/edit facilities with location (lat/long) and image citation so details are accurate.
- As an admin/staff, I can see recent facility activity with pagination so I can review changes.

## Calendar, Reports, and Audit
- As any user, I can view the calendar and click events to open reservation details so I can inspect bookings by date.
- As a user, I can export reports (CSV/HTML-to-PDF) from quick actions so I can share data.
- As an admin/staff, I can view audit trails with pagination and filters so I can trace actions.
- As an admin/staff, I can view notifications via the panel and mark them read so I can keep track of events.
- As an admin/staff, I can filter dashboard charts by status/facility/date range and see charts update so analytics stay relevant.
- As an admin/staff, I can view reports with charts (monthly trends, status breakdown, top facilities) so I have quick insights.

## UX, Mobile, and Layout
- As any user, I see role/status badges with clear contrast so roles are visible.
- As any user, I experience mobile-friendly layouts (responsive tables, collapsible sections, non-pushing sidebar) so the app is usable on phones.
- As a user, I can toggle collapsible sections on dashboards/facility management so I reduce scrolling.
- As a user, I see the correct landing background (cityhall) on public pages so the branding is consistent.
- As a visitor, I see hero CTA buttons side-by-side and consistent so the landing page feels polished.
- As any user, I see modern, larger buttons (including confirmations) so actions are clear and easy to tap.

## Security & Reliability
- As a user, I get CSRF protection, rate limiting, and security headers so my interactions are safer.
- As a user uploading files (profile pic/docs/facility images), I benefit from file validation (type/size) so uploads are safe.
- As a security auditor, I see audit logs for key actions (logins, approvals, reservations) so activity is traceable.
- As a security reviewer, I know CSP allows required CDNs (fonts, Chart.js, jsdelivr) without breaking UI assets.

## Testing & Debug
- As a resident (tester), I can see AI recommendation debug info on the test page so I can verify location-based scoring.
- As a user, I can test AI holiday/event risk indicators on booking so I can verify alerts are visible.

## Contact & Support
- As a visitor, I can submit a contact inquiry that stores to the dashboard inbox and emails admins so issues are captured.
- As a user, I can read responses or notifications related to my inquiries.

## Future Features (Planned/UI Ready)

### AI Chatbot (UI Implemented, Model Integration Pending)
- As a resident, I can chat with an AI assistant that answers questions about facilities, bookings, and policies so I get instant help without contacting support.
- As a resident, I can ask the chatbot about facility availability, booking procedures, and system policies so I understand how to use the system effectively.
- As an admin, I can configure the chatbot to ground responses on system documentation and FAQs so users receive accurate, contextual information.
- As a system, the chatbot can access reservation and facility data to provide context-aware responses so users get relevant answers.

### Maintenance Management Integration (Design Complete, Not Implemented)
- As a maintenance manager, I can schedule maintenance in the Maintenance Management system and have it automatically block facility bookings and notify affected users so maintenance is coordinated with reservations.
- As a resident, I am automatically notified when a facility I've booked enters maintenance so I can plan accordingly.
- As the system, I automatically update facility status to "maintenance" when maintenance is scheduled so availability is accurately reflected.

### Infrastructure Management Integration (Design Complete, Not Implemented)
- As a project manager, I can sync construction timelines with the reservation system so facilities are automatically blocked during construction periods.
- As an admin, I can automatically add new facilities to the system when infrastructure projects are completed so new facilities are immediately available for booking.
- As a resident, I am notified about facility closures due to construction projects so I can plan my bookings around construction timelines.

### Utilities Billing Integration (Design Complete, Not Implemented)
- As a billing manager, I can receive facility usage data from the reservation system so I can track utility consumption per reservation.
- As an admin, I can receive utility outage alerts so facilities can be automatically blocked when utilities are unavailable.
- As a resident, I am notified when a facility I've booked is affected by utility outages so I can plan accordingly.
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




