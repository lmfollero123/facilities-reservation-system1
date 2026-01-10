# UML Diagrams (Textual, Full System Coverage)

## Use Case Diagram (Textual)
- **Actors**: Resident, Admin, Staff, System (automations), Email/SMTP, AI Recommender, Contact Inbox.
- **Resident Use Cases**:
  - Register (with Barangay Culiat address + documents)
  - View Terms & Conditions modal (auto-open) and accept checkbox
  - Login with OTP
  - Forgot password (request reset link) and reset via token
  - View/update profile (address/coords/profile picture)
  - Browse public facilities/details (with citations)
  - Book facility (with flexible time slots, conflict check, AI recommendations)
  - See booking risk notes for PH holidays / Barangay Culiat events
  - Book within limits (≤3 active/30 days, ≤60-day advance, ≤1 per day)
  - Receive auto-approval if all conditions are met
  - Reschedule own reservations (up to 3 days before, one reschedule per reservation)
  - View reservations (statuses, calendar)
  - Receive notifications (approval/denial/auto-approval)
  - Export reports (CSV/PDF via HTML)
  - View AI recommendations (distance-aware)
  - (Planned) Chat with AI assistant
- **Admin/Staff Use Cases**:
  - Review/approve/deny/lock users; view documents
  - Provide lock reason (sent to user email + login notice)
  - Manage facilities (CRUD, lat/long, citation)
  - View/approve/deny reservations; add notes; see timeline/history
  - Modify/postpone/cancel approved reservations (with reasons, date validation)
  - Record user violations (no-show, policy violation, damage, etc.) with severity levels
  - Auto-decline (system) of expired pending reservations (overseen)
  - Auto-approval system (system) evaluates and approves eligible reservations
  - View audit trail, notifications, recent activities
  - Export reports; run quick actions
  - View contact inquiries (dashboard inbox) and receive email copies
  - Manage profile (same as resident, with elevated role)
  - (Planned) Configure chatbot knowledge base
- **System/Automations**:
  - OTP generation/verification
  - Conflict detection and AI recommendations
  - Holiday/event tagging for risk scoring (PH holidays + Brgy. Culiat events)
  - Auto-decline expired pending reservations
  - Auto-approval evaluation (8 conditions: facility flag, blackout dates, duration, capacity, commercial purpose, conflicts, violations, advance window)
  - Enforce reservation limits (count/advance/per-day)
  - Track user violations and disable auto-approval for high/critical violations
  - Notifications dispatch
  - Security logging/rate limiting/lockout
- **External**:
  - Email/SMTP (OTP, approval emails)
  - (Planned) AI chatbot provider (UI implemented, model integration pending)
  - (Planned) Urban Planning & Development API
  - (Planned) Community Infrastructure Maintenance Management API
  - (Planned) Infrastructure Project Management API
  - (Planned) Utilities Billing & Management API
  - (Planned) Road and Transportation Infrastructure Monitoring API
  - (Planned) Energy Efficiency Management API
  - Contact Inbox (admin email)

## Sequence Diagram (Login with OTP & Approval Email)
1. Resident → Auth: Submit email/password.
2. Auth → Security: Rate/lockout check; verify password.
3. Auth → Email/SMTP: Send OTP (Gmail now; Brevo planned).
4. Auth → Session: Store pending OTP state.
5. Resident → Auth: Submit OTP.
6. Auth → Security: Verify OTP hash/expiry/attempts.
7. Auth → Session: Create authenticated session.
8. Auth → Resident: Dashboard redirect.

## Sequence Diagram (Forgot Password + Reset)
1. Resident → Auth: Submit email on forgot-password page.
2. Auth → DB: Create reset token + expiry.
3. Auth → Email/SMTP: Send reset link with token.
4. Resident → Auth: Open link, submit new password.
5. Auth → Security: Validate token/expiry, hash password.
6. Auth → DB: Update password, invalidate token.
7. Auth → Session: (Optional) auto-login or redirect to login with success notice.

## Sequence Diagram (User Registration & Approval)
1. Resident → User/Docs: Submit registration + docs (≥1 required).
2. User/Docs → Storage: Save files under `public/uploads/documents/{userId}`; metadata in DB.
3. User/Docs → DB: Create user (pending), doc records.
4. Admin → User/Docs: View pending users + documents.
5. Admin → User: Approve/deny/lock with optional note.
6. User → Notification: Create approval/denial notification.
7. User → Email/SMTP: Send approval email.
8. Resident → Auth: Login with OTP (sequence above).

## Sequence Diagram (Facility Booking & Auto-Approval)
1. Resident → Facility: View facilities (public) / select in booking.
2. Resident → Reservation: Submit booking (facility/date/start_time/end_time/purpose/expected_attendees/commercial_flag).
3. Reservation → AI Recommender: Conflict check (overlapping time ranges) + alternative slots + distance scoring + holiday/event risk tags.
4. Reservation → Auto-Approval Service: Evaluate 8 conditions (facility flag, blackout dates, duration, capacity, commercial purpose, conflicts, violations, advance window).
5. Auto-Approval Service → DB: Check facility settings, blackout dates, user violations, existing reservations.
6. Auto-Approval Service → Reservation: Return approval decision (auto-approved or pending).
7. Reservation → DB: Create reservation (status: approved if auto-approved, pending otherwise) + history entry.
8. Reservation → Notification: Notify resident (auto-approved confirmation or pending review); notify staff if pending.
9. Admin/Staff → Reservation: Can override auto-approval decision; approve/deny/modify/postpone/cancel with note.
10. Reservation → Notification: Notify resident; update timeline/history.
11. Calendar → DB: Read reservations for Month/Week/Day views; events clickable to details.

## Activity Diagram (Registration to Approval to Login)
- Start → Resident fills form (name/email/password/mobile/address) → Terms modal auto-opens → Accept checkbox required → Upload Valid ID (other uploads removed) → Validate (fields, Culiat address, doc type/size) → Save user (pending) + docs → Show pending notice → Admin reviews docs → Decision:
  - If approve → status = active → send approval email/notification.
  - If deny/lock → status = locked → send notification.
- Resident logs in → enters OTP → if valid → dashboard; else retry/lockout.
- End.

## Activity Diagram (Booking to Approval)
- Start → Resident selects facility/date/start_time/end_time/purpose/expected_attendees/commercial_flag → Enforce limits (≤3 active/30 days, ≤60-day advance, ≤1/day) → Conflict check + alternatives (overlapping time ranges, includes holiday/event risk) → AI recommendations shown → Submit booking → Auto-approval evaluation (8 conditions):
  - All conditions met → status approved, auto_approved=true → notify resident → calendar updated.
  - Any condition failed → status pending, auto_approved=false → notify staff/resident (pending) → Admin reviews:
    - Approve → status approved → notify resident → calendar updated.
    - Deny → status denied → notify resident → history updated.
    - Modify/Postpone/Cancel → update reservation with reason → notify resident.
    - Auto-decline (system) for expired pending → notify resident.
- Resident views status / calendar / reschedules / exports → End.

## Coverage Note
These textual diagrams include all current modules: auth/OTP, forgot/reset password, registration with Valid ID-only upload, terms modal, user/profile, facilities, reservations with limits, AI recommendations + holiday/event risk, calendar (clickable to detail), contact inquiries inbox/email, notifications, exports, audit/security, auto-approval system, violation tracking, flexible time slots, resident reschedule, and external email/SMTP.

**Future/Planned Integrations:**
- **AI Chatbot Provider** (UI implemented, model integration pending): Connect chatbot UI to AI/ML model API for contextual responses
- **Community Infrastructure Maintenance Management** (Design complete): Sync facility status with maintenance schedules, block dates during maintenance
- **Infrastructure Management** (Design complete): Sync project timelines, auto-create facilities from completed projects
- **Utilities Billing & Management** (Design complete): Track utility costs, handle outage alerts

Pending external integrations (Brevo/domain SMTP, AI chatbot model, LGU system APIs) are noted as planned external components.




