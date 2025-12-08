# UML Diagrams (Textual, Full System Coverage)

## Use Case Diagram (Textual)
- **Actors**: Resident, Admin, Staff, System (automations), Email/SMTP, AI Recommender.
- **Resident Use Cases**:
  - Register (with Barangay Culiat address + documents)
  - Login with OTP
  - View/update profile (address/coords/profile picture)
  - Browse public facilities/details (with citations)
  - Book facility (with conflict check, AI recommendations)
  - View reservations (statuses, calendar)
  - Receive notifications (approval/denial)
  - Export reports (CSV/PDF via HTML)
  - View AI recommendations (distance-aware)
  - (Planned) Chat with AI assistant
- **Admin/Staff Use Cases**:
  - Review/approve/deny/lock users; view documents
  - Manage facilities (CRUD, lat/long, citation)
  - View/approve/deny reservations; add notes; see timeline/history
  - Auto-decline (system) of expired pending reservations (overseen)
  - View audit trail, notifications, recent activities
  - Export reports; run quick actions
  - Manage profile (same as resident, with elevated role)
  - (Planned) Configure chatbot knowledge base
- **System/Automations**:
  - OTP generation/verification
  - Conflict detection and AI recommendations
  - Auto-decline expired pending reservations
  - Notifications dispatch
  - Security logging/rate limiting/lockout
- **External**:
  - Email/SMTP (OTP, approval emails)
  - (Planned) AI chatbot provider

## Sequence Diagram (Login with OTP & Approval Email)
1. Resident → Auth: Submit email/password.
2. Auth → Security: Rate/lockout check; verify password.
3. Auth → Email/SMTP: Send OTP (Gmail now; Brevo planned).
4. Auth → Session: Store pending OTP state.
5. Resident → Auth: Submit OTP.
6. Auth → Security: Verify OTP hash/expiry/attempts.
7. Auth → Session: Create authenticated session.
8. Auth → Resident: Dashboard redirect.

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
3. Reservation → AI Recommender: Conflict check + alternative slots + distance scoring.
4. Reservation → DB: Create pending reservation + history entry.
5. Reservation → Notification: Notify staff/resident (pending).
6. Admin/Staff → Reservation: Approve/deny with note.
7. Reservation → Notification: Notify resident; update timeline/history.
8. Calendar → DB: Read reservations for Month/Week/Day views; events clickable to details.

## Activity Diagram (Registration to Approval to Login)
- Start → Resident fills form (name/email/password/mobile/address) → Upload ≥1 document → Validate (fields, Culiat address, doc type/size) → Save user (pending) + docs → Show pending notice → Admin reviews docs → Decision:
  - If approve → status = active → send approval email/notification.
  - If deny/lock → status = locked → send notification.
- Resident logs in → enters OTP → if valid → dashboard; else retry/lockout.
- End.

## Activity Diagram (Booking to Approval)
- Start → Resident selects facility/date/time/purpose → Conflict check + alternatives → AI recommendations shown → Submit booking → Create pending reservation + history → Notify staff/resident (pending) → Admin reviews:
  - Approve → status approved → notify resident → calendar updated.
  - Deny → status denied → notify resident → history updated.
  - Auto-decline (system) for expired pending → notify resident.
- Resident views status / calendar / exports → End.

## Coverage Note
These textual diagrams include all current modules: auth/OTP, registration with documents, user/profile, facilities, reservations, AI recommendations, calendar, notifications, exports, audit/security, and external email/SMTP. Pending integrations (Brevo/domain, AI chatbot provider) are noted as planned external components.

