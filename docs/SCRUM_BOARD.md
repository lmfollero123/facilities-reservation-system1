# Scrum Board (Whole System Snapshot: Modules/Submodules/Microservices)

## Columns
- **To Do** – Not started or pending integration
- **In Progress** – Currently being worked
- **Done** – Functionally complete

## To Do (Remaining gaps)
- Domain + Brevo SMTP: configure with SPF/DKIM/DMARC and switch app SMTP from Gmail to Brevo.
- AI Chatbot: select provider, embed chat UI, safety/allow-listing, and ground on FAQs/system docs.
- Final production-like email smoke tests after Brevo cutover (OTP + approval/reset/lock/inquiry).

## In Progress
- (empty)

## Done (by module/submodule)
**Authentication & Security**
- Login with email/password + email OTP (Gmail SMTP working now).
- Forgot password flow with reset token/email; lock reason shown on login and emailed.
- Account lockout, rate limiting, CSRF protection, session hardening, security headers/CSP (fonts/Chart.js/jsdelivr).
- Password policy enforcement; login attempts logging.

**Registration & Approval**
- Resident-only registration (Barangay Culiat address required).
- Valid ID primary required upload; validation (type/size); Terms & Conditions modal auto-opens and must be accepted.
- User documents stored under `public/uploads/documents/{userId}` and visible to Admin/Staff.
- Admin/Staff approval/deny/lock with optional notes and lock reason captured.
- Approval email sent on activation; lock email and login notice; notifications created.

**User Profile**
- Profile edit (name, contact, address, coordinates).
- Profile picture upload with validation.

**Facility Management**
- Add/edit facilities with lat/long, status, image citation; citation hover display fixed.
- Facility activity pagination; collapsible sections; responsive layout.

**Public Site**
- Public facilities listing with glass-morphism cityhall background.
- Facility details with image citation hover, proper layout; facility calendar dates redirect to login → dashboard calendar.
- Guest navbar and landing visuals; hero CTA buttons side-by-side/equal width; higher-contrast cards/text; favicon + CSP fixes.

**Booking & Recommendations**
- Book facility with flexible time slots (start_time - end_time), date/purpose/expected attendees/commercial flag; conflict detection + alternatives (overlapping time range logic); reservation controls (≤3 active/30 days, ≤60-day advance, ≤1/day).
- Auto-approval system: 8-condition evaluation (facility flag, blackout dates, duration, capacity, commercial purpose, conflicts, violations, advance window); automatically approves eligible reservations.
- AI facility recommendations including distance scoring (Haversine) and holiday/event risk tagging; warnings persist (no flicker).
- My Reservations view with statuses and reschedule functionality (up to 3 days before, one reschedule per reservation, requires re-approval if originally approved).

**Reservation Approvals**
- Pending requests list with actions, notes, and timeline/history view.
- Auto-decline expired pending reservations.
- Staff can modify/postpone/cancel approved reservations (with reasons, date validation, no past dates).
- Requester role badge visible; status badges styled.
- Violation recording: Admin/Staff can record user violations (no-show, policy violation, damage, etc.) with severity levels that affect auto-approval eligibility.

**Calendar**
- Month/Week/Day views; events clickable to reservation detail; calendar modal with event pills/holiday colors.
- Table responsiveness on mobile.

**Reports & Exports**
- Quick export (CSV, HTML-for-PDF) from sidebar; reports page charts.

**Notifications & Activity**
- Notifications panel lazy-load + mark-as-read.
- Recent activities pagination (dashboard, facility mgmt).
- Audit trail pagination and filtering.

**Mobile/UX**
- Responsive tables via `.table-responsive`.
- Collapsible sections with persisted state.
- Mobile sidebar overlay with backdrop/close (no content push).
- Status/role badges with contrast.
- Modernized buttons/confirmations across public + dashboard.

**Migrations & Data**
- Security tables (rate_limits, security_logs, login_attempts).
- User documents table and enum.
- OTP columns on users.
- Profile picture column.
- Location fields on users/facilities.
- Lock reason column on users; contact_inquiries table.
- Auto-approval fields: facilities.auto_approve, capacity_threshold, max_duration_hours; reservations.auto_approved, expected_attendees, is_commercial, reschedule_count.
- Violation tracking: user_violations table (violation_type, severity, description, reservation_id, created_by).
- Blackout dates: facility_blackout_dates table (facility_id, blackout_date, reason).

**Documentation**
- FLOWCHART, DFD, WFD updated to latest flows (OTP, docs, approval email, booking limits, holidays/events, contact inquiries, forgot/reset).
- SECURITY docs, BACKLOG, USER_STORIES, SPRINT plan, SCRUM_BOARD.

