# Microservices Overview & Communication Patterns

## Microservices Diagram (Textual)
- **Gateway / Frontend**  
  - Serves PHP views, routes user traffic to backend endpoints.
- **Auth & Session Service**  
  - Handles login (email/password), OTP issuance/verification, lockout, rate limits, sessions.
- **User & Profile Service**  
  - Manages user records (name/email/mobile/address/coordinates/profile picture), role/status.
- **Document Service**  
  - Handles resident document uploads/validation/storage (`public/uploads/documents/{userId}`), metadata in DB.
- **Facility Service**  
  - Manages facilities (details, status, citations, lat/long), facility audit entries.
- **Reservation Service**  
  - Manages bookings, conflict checks, history/timeline, auto-decline of expired pending reservations.
- **AI Recommendation Service**  
  - Provides conflict detection and facility recommendations with distance scoring (Haversine), purpose-based ranking.
- **Calendar Service**  
  - Exposes calendar views and reservation event data for Month/Week/Day.
- **Notification Service**  
  - Creates in-app notifications (panel, mark-as-read) for approvals/denials and key events.
- **Export/Reports Service**  
  - Generates CSV and HTML-for-PDF reports from quick actions.
- **Email/OTP Service**  
  - Sends approval emails and OTP emails via SMTP (currently Gmail; target Brevo/domain).
- **Audit & Security Service**  
  - Logs security events, audit trail entries, login attempts, rate limit records.

## Communication Patterns
- **Gateway → Services:** REST/HTTP (PHP controllers) for auth, user, documents, facilities, reservations, calendar, notifications, exports, audit.
- **Auth & Session → Email/OTP:** SMTP to send OTP and approval emails.
- **Reservation → Notification:** Direct DB writes to notification store (polled via HTTP by the UI); no message queue.
- **Reservation → AI Recommendation:** In-process/HTTP call for conflict detection and recommendations (purpose + distance).
- **Reservation → Calendar:** Shared DB view; calendar reads reservation data (HTTP/read-only queries).
- **Facility → AI Recommendation:** Reads facility coordinates/status to compute scores (in-process/HTTP).
- **User/Profile → Document Service:** HTTP/form upload; Document Service writes file + metadata.
- **Audit & Security:** Synchronous DB writes on each critical action; no queue.

## Notes
- Current deployment is monolithic PHP with modular responsibilities; the “services” above are logical boundaries. Actual calls are HTTP within the app; SMTP is used for email/OTP. No message queue is present today. Brevo/domain SMTP is planned to replace Gmail SMTP. AI chatbot is planned and not yet integrated.


