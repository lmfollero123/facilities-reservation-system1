# Scrum Board (Whole System Snapshot: Modules/Submodules/Microservices)

## Columns
- **To Do** – Not started or pending integration
- **In Progress** – Currently being worked
- **Done** – Functionally complete

## To Do (Remaining gaps)
- Domain + Brevo SMTP: configure with SPF/DKIM/DMARC and switch app SMTP from Gmail to Brevo.
- AI Chatbot: select provider, embed chat UI, safety/allow-listing, and ground on FAQs/system docs.
- Final production-like email smoke tests after Brevo cutover (OTP + approval).

## In Progress
- (empty)

## Done (by module/submodule)
**Authentication & Security**
- Login with email/password + email OTP (Gmail SMTP working now).
- Account lockout, rate limiting, CSRF protection, session hardening, security headers/CSP.
- Password policy enforcement; login attempts logging.

**Registration & Approval**
- Resident-only registration (Barangay Culiat address required).
- Required document upload (≥1 of Birth Cert / Valid ID / Barangay ID / Resident ID); validation (type/size).
- User documents stored under `public/uploads/documents/{userId}` and visible to Admin/Staff.
- Admin/Staff approval/deny/lock with optional notes.
- Approval email sent on activation; notifications created.

**User Profile**
- Profile edit (name, contact, address, coordinates).
- Profile picture upload with validation.

**Facility Management**
- Add/edit facilities with lat/long, status, image citation; citation hover display fixed.
- Facility activity pagination; collapsible sections; responsive layout.

**Public Site**
- Public facilities listing with glass-morphism cityhall background.
- Facility details with image citation hover, proper layout.
- Guest navbar and landing visuals.

**Booking & Recommendations**
- Book facility with date/time/purpose; conflict detection + alternatives.
- AI facility recommendations including distance scoring (Haversine).
- My Reservations view with statuses.

**Reservation Approvals**
- Pending requests list with actions, notes, and timeline/history view.
- Auto-decline expired pending reservations.
- Requester role badge visible; status badges styled.

**Calendar**
- Month/Week/Day views; events clickable to reservation detail.
- Table responsiveness on mobile.

**Reports & Exports**
- Quick export (CSV, HTML-for-PDF) from sidebar.

**Notifications & Activity**
- Notifications panel lazy-load + mark-as-read.
- Recent activities pagination (dashboard, facility mgmt).
- Audit trail pagination and filtering.

**Mobile/UX**
- Responsive tables via `.table-responsive`.
- Collapsible sections with persisted state.
- Mobile sidebar overlay with backdrop/close (no content push).
- Status/role badges with contrast.

**Migrations & Data**
- Security tables (rate_limits, security_logs, login_attempts).
- User documents table and enum.
- OTP columns on users.
- Profile picture column.
- Location fields on users/facilities.

**Documentation**
- FLOWCHART, DFD, WFD updated to latest flows (OTP, docs, approval email).
- SECURITY docs, BACKLOG, USER_STORIES, SPRINT plan, SCRUM_BOARD.

