# Integration Diagram (Textual)

## Components & Interactions
- **Browser / Clients**  
  - Access public pages (facilities listing, facility details).  
  - Access authenticated dashboard (booking, approvals, reports).
- **API Gateway / Web Layer (PHP app)**  
  - Single entry point for all HTTP requests.  
  - Routes to internal modules: Auth, Users, Documents, Facilities, Reservations, Calendar, Notifications, Exports, Audit, AI Recommendations.
- **Auth & Session Module**  
  - Handles login/password, OTP send/verify, session management, lockout/rate limit checks.
- **User Module**  
  - CRUD for user profile (name, contact, address, coords, profile picture).
- **Document Module**  
  - Handles document upload, validation, storage (`public/uploads/documents/{userId}`), metadata in DB.
- **Facility Module**  
  - Facility CRUD, status, citations, lat/long.
- **Reservation Module**  
  - Booking, conflict check, history/timeline, auto-decline expired pending.
- **AI Recommendation Module**  
  - Conflict detection and facility recommendations (purpose + distance).
- **Calendar Module**  
  - Provides reservation events for Month/Week/Day views.
- **Notification Module**  
  - Creates in-app notifications; panel fetch/mark-read.
- **Export/Reports Module**  
  - CSV and HTML-for-PDF exports.
- **Audit/Security Module**  
  - Security logs, audit trail, login attempts, rate limits.
- **Email/OTP Service (SMTP)**  
  - Sends OTP and approval emails (currently Gmail SMTP; target Brevo).
- **Database**  
  - Users, sessions, reservations, facilities, docs, notifications, audit, security logs.
- **File Storage**  
  - `public/uploads/documents/{userId}` and images.

## API Gateway (Single Entry)
- **Role:** Fronts all HTTP endpoints; performs routing, CSRF/session checks, and delegates to modules.  
- **External Entry:** `/` public pages, `/auth/*`, `/dashboard/*`, `/api/*` (where applicable).  
- **Security:** CSRF tokens, session validation, role/permission checks, rate limits (via security middleware).  
- **Outbound:** SMTP for email/OTP; reads/writes DB; reads/writes file storage; internal calls to AI Recommendation logic.

## Communication Paths
- Client → API Gateway: HTTPS (forms, AJAX).
- API Gateway → Modules: In-process/HTTP routing (monolithic PHP).
- Modules → DB: SQL reads/writes.
- Modules → File Storage: Document/image saves under `public/uploads`.
- Auth/Notifications/Approvals → Email: SMTP (OTP, approval).
- Reservation/Facility/User → AI Recommendation: In-process call for scoring/distance/conflict.

## Notes
- Current deployment is monolith with logical boundaries; “API gateway” is the web layer/controller tier acting as a single entry point.
- Future: swap SMTP to Brevo + domain; add AI chatbot (new external integration).



