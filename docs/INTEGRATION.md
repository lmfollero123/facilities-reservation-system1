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
  - Booking, conflict check, history/timeline, auto-decline expired pending, booking limits (≤3 active/30 days, ≤60-day advance, ≤1/day).
- **AI Recommendation Module**  
  - Conflict detection and facility recommendations (purpose + distance) with holiday/event risk tagging (PH holidays + Brgy. Culiat).
- **Calendar Module**  
  - Provides reservation events for Month/Week/Day views.
- **Notification Module**  
  - Creates in-app notifications; panel fetch/mark-read.
- **Password Reset Module**  
  - Issues reset tokens, validates expiry, updates passwords.
- **Contact Inquiry Module**  
  - Accepts public inquiries, stores them, emails admins/inbox.
- **Export/Reports Module**  
  - CSV and HTML-for-PDF exports.
- **Audit/Security Module**  
  - Security logs, audit trail, login attempts, rate limits.
- **Email/OTP Service (SMTP)**  
  - Sends OTP, approval/lock notices, reset links, and contact inquiry alerts (currently Gmail SMTP; target Brevo).
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
- Auth/Notifications/Approvals/Password Reset/Contact → Email: SMTP (OTP, approval/lock, reset, inquiry alert).
- Reservation/Facility/User → AI Recommendation: In-process call for scoring/distance/conflict + holiday/event risk tagging.

## Notes
- Current deployment is monolith with logical boundaries; “API gateway” is the web layer/controller tier acting as a single entry point. Booking path enforces reservation limits before writes.
- Future: swap SMTP to Brevo + domain; add AI chatbot (new external integration).




