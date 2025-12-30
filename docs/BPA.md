# Business Process Architecture (BPA) – Level 1 & Level 2

## Level 1 – End‑to‑End LGU Facilities Reservation Process

**Scope**: From resident awareness and registration up to booking, approval, facility use, and post‑event archiving/analytics.

1. **Citizen Onboarding**
   - Resident discovers the LGU Facilities Reservation portal.
   - Resident registers with Barangay Culiat address and uploads at least one supporting document (Valid ID, etc.).
   - System validates data, stores user + document metadata, and queues user for approval.
   - Admin/Staff reviews documents and approves/locks account; system sends email/notification.
2. **Authentication & Account Access**
   - Resident logs in with email/password.
   - System enforces OTP on login, rate limiting, and lockout for suspicious activity.
   - Successful login creates a secure session and shows the resident dashboard.
3. **Facility Discovery & Planning**
   - Resident browses public facility listings and details (capacity, amenities, rules, citations).
   - Resident checks availability via calendar and AI conflict/schedule hints (holiday/event risk).
   - (Planned) Resident can consult AI Chatbot for “which facility fits my event?” and policy questions.
4. **Reservation Request**
   - Resident opens **Book a Facility** and selects facility, date, **start/end time**, purpose, expected attendees, and commercial flag.
   - System enforces booking limits (≤3 active/30 days, ≤60‑day advance, ≤1 per day) and validates time ranges/duration.
   - System performs conflict detection and shows AI recommendations/alternative slots if needed.
5. **Auto‑Approval & Staff Review**
   - System evaluates **8 auto‑approval conditions** (facility flag, blackout dates, duration, capacity, commercial flag, conflicts, user violations, advance window).
   - If all pass → reservation is **auto‑approved** (status `approved`, `auto_approved=true`); otherwise → status `pending` for staff review.
   - Admin/Staff can always override: approve/deny/modify/postpone/cancel with reasons, and record violations.
6. **Resident Self‑Service**
   - Resident views reservations (list + calendar), including statuses and history.
   - Resident can **reschedule** their own reservations (one time only, up to 3 days before event, not into the past); approved bookings go back to `pending`.
   - Notifications (in‑app + email) keep residents informed of all changes.
7. **Facility Use & Policy Enforcement**
   - On event date, resident uses the facility under LGU rules.
   - Staff can record **user violations** (no‑show, late cancellation, policy breach, damage) with severity; high/critical violations affect future auto‑approval.
8. **Post‑Event Analytics & Archival**
   - System updates reservation history and audit logs.
   - Admin/Staff review reports (usage, approvals vs denials, violations, blackout impact).
   - Background jobs auto‑decline expired pending reservations, archive old documents/records based on retention policies, and optimize the database.
   - Residents can export their own data (JSON + readable report that can be printed/saved as PDF).

---

## Level 2 – Decomposed Core Processes

### 1. Citizen Onboarding (Registration & Approval)

**Sub‑Processes**
- 1.1 **Capture Registration Data**
  - Inputs: name, email, password, address, contact details.
  - Rules: Barangay Culiat address required; email unique; password policy enforced.
- 1.2 **Upload Supporting Documents**
  - Inputs: at least one document (Valid ID recommended).
  - System stores files under user‑scoped directory with metadata in `user_documents`.
- 1.3 **System Validation**
  - Validate required fields, document type/size, email format.
  - Create `users` row with `status=pending` and link `user_documents`.
- 1.4 **Administrative Review**
  - Admin/Staff sees a review queue with user details + documents.
  - Outcomes: `approved`, `locked` (with reason), or `request more info`.
- 1.5 **Communication & Audit**
  - System sends approval/lock emails and creates notifications.
  - Audit entry records who approved/locked and when.

### 2. Booking & Auto‑Approval

**Sub‑Processes**
- 2.1 **Collect Booking Details**
  - Resident selects facility, date, `start_time`, `end_time`, purpose, expected attendees, commercial flag, optional attachments.
- 2.2 **Validate Limits & Time Window**
  - Enforce booking limits (count, advance days, per‑day).
  - Validate that `end_time > start_time` and duration ≤ facility `max_duration_hours`.
- 2.3 **Conflict Detection & AI Assist**
  - Check overlapping reservations (start/end times).
  - Highlight PH holidays/Barangay events for risk; show AI‑suggested alternative slots/facilities.
- 2.4 **Auto‑Approval Evaluation**
  - Check: facility `auto_approve`, absence of blackout, duration, capacity threshold, non‑commercial, no conflicts, no disqualifying violations, within advance window.
  - Write reservation with `status=approved` + `auto_approved=true` or `status=pending` + `auto_approved=false`.
  - Insert reservation history entry and audit log.
- 2.5 **Staff Override & Maintenance Actions**
  - Admin/Staff list pending/approved reservations.
  - Actions: approve, deny, modify date/time, postpone, cancel (all with reasons and history).
  - System sends notifications/emails on every change.

### 3. Resident Self‑Service & Violations

**Sub‑Processes**
- 3.1 **View & Reschedule**
  - Resident sees a list/calendar of own reservations with status and history.
  - Reschedule allowed once per reservation, only into the future and at least 3 days before event; collisions and limits re‑validated.
- 3.2 **Violation Recording**
  - Staff record violations tied to reservations with type + severity + description.
  - High/critical violations inform future auto‑approval decisions.

### 4. Data Export, Archival & Background Jobs

**Sub‑Processes**
- 4.1 **Resident Data Export**
  - Resident requests data export (full/profile/reservations/documents‑only).
  - System generates JSON export in `data_exports` and exposes a readable report view for print/PDF.
- 4.2 **Document Archival**
  - Nightly job finds documents past retention thresholds and moves them to archive storage.
  - `user_documents` updated with archival flags/paths; audit logs record actions.
- 4.3 **Old Data Cleanup & DB Optimization**
  - Scheduled jobs: auto‑decline expired pending reservations, cleanup expired tokens/rate limits/logs, and run DB optimize.

### 5. External & Planned Integrations

- **AI Chatbot Provider** – Conversational UI already implemented; future integration will supply real AI answers grounded in system data and FAQs.
- **Urban Planning, Maintenance, Projects, Utilities, Roads/Transpo, Energy Efficiency** – Planned APIs to:
  - Share facility usage metrics and trends.
  - Receive maintenance/project schedules and auto‑block facilities.
  - Track energy/utility impacts and surface insights to LGU planners.




