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
  - Book facility (with conflict check, AI recommendations)
  - See booking risk notes for PH holidays / Barangay Culiat events
  - Book within limits (≤3 active/30 days, ≤60-day advance, ≤1 per day)
  - View reservations (statuses, calendar)
  - Receive notifications (approval/denial)
  - Export reports (CSV/PDF via HTML)
  - View AI recommendations (distance-aware)
  - (Planned) Chat with AI assistant
- **Admin/Staff Use Cases**:
  - Review/approve/deny/lock users; view documents
  - Provide lock reason (sent to user email + login notice)
  - Manage facilities (CRUD, lat/long, citation)
  - View/approve/deny reservations; add notes; see timeline/history
  - Auto-decline (system) of expired pending reservations (overseen)
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
  - Enforce reservation limits (count/advance/per-day)
  - Notifications dispatch
  - Security logging/rate limiting/lockout
- **External**:
  - Email/SMTP (OTP, approval emails)
  - (Planned) AI chatbot provider
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

## Sequence Diagram (Facility Booking & AI Recommendation)
1. Resident → Facility: View facilities (public) / select in booking.
2. Resident → Reservation: Submit booking (facility/date/time/purpose).
3. Reservation → AI Recommender: Conflict check + alternative slots + distance scoring + holiday/event risk tags.
4. Reservation → DB: Create pending reservation + history entry.
5. Reservation → Notification: Notify staff/resident (pending).
6. Admin/Staff → Reservation: Approve/deny with note.
7. Reservation → Notification: Notify resident; update timeline/history.
8. Calendar → DB: Read reservations for Month/Week/Day views; events clickable to details.

## Activity Diagram (Registration to Approval to Login)
- Start → Resident fills form (name/email/password/mobile/address) → Terms modal auto-opens → Accept checkbox required → Upload Valid ID (other uploads removed) → Validate (fields, Culiat address, doc type/size) → Save user (pending) + docs → Show pending notice → Admin reviews docs → Decision:
  - If approve → status = active → send approval email/notification.
  - If deny/lock → status = locked → send notification.
- Resident logs in → enters OTP → if valid → dashboard; else retry/lockout.
- End.

## Activity Diagram (Booking to Approval)
- Start → Resident selects facility/date/time/purpose → Enforce limits (≤3 active/30 days, ≤60-day advance, ≤1/day) → Conflict check + alternatives (includes holiday/event risk) → AI recommendations shown → Submit booking → Create pending reservation + history → Notify staff/resident (pending) → Admin reviews:
  - Approve → status approved → notify resident → calendar updated.
  - Deny → status denied → notify resident → history updated.
  - Auto-decline (system) for expired pending → notify resident.
- Resident views status / calendar / exports → End.

## Coverage Note
These textual diagrams include all current modules: auth/OTP, forgot/reset password, registration with Valid ID-only upload, terms modal, user/profile, facilities, reservations with limits, AI recommendations + holiday/event risk, calendar (clickable to detail), contact inquiries inbox/email, notifications, exports, audit/security, and external email/SMTP. Pending integrations (Brevo/domain, AI chatbot provider) are noted as planned external components.




