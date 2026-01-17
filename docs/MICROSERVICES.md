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
  - Manages bookings with flexible time slots (start_time - end_time), conflict checks (overlapping time ranges), history/timeline, auto-decline of expired pending reservations, booking limit enforcement (≤3 active/30 days, ≤60-day advance, ≤1/day), reschedule functionality (up to 3 days before, one reschedule per reservation).
- **Auto-Approval Service**  
  - Evaluates reservations against 8 conditions (facility auto_approve flag, blackout dates, duration limits, capacity thresholds, commercial purpose check, time conflicts, user violations, advance booking window), automatically approves eligible reservations.
- **Violation Tracking Service**  
  - Records and manages user violations (no-show, late cancellation, policy violation, damage, other), tracks severity levels, disables auto-approval for users with high/critical violations.
- **AI Recommendation Service**  
  - Provides conflict detection and facility recommendations with distance scoring (Haversine), purpose-based ranking, and holiday/event risk tagging (PH holidays + Barangay Culiat events).
  - **Performance Optimizations (Jan 2025)**: Combined queries (~60% faster conflict detection), timeout protection (5s timeout, 3s quick fallback), client-side debouncing (500ms/1000ms), smart fetching (skips if fields missing), database indexes for query optimization.
- **Calendar Service**  
  - Exposes calendar views and reservation event data for Month/Week/Day.
- **Notification Service**  
  - Creates in-app notifications (panel, mark-as-read) for approvals/denials and key events.
- **Export/Reports Service**  
  - Generates CSV and HTML-for-PDF reports from quick actions.
- **Email/OTP Service**  
  - Sends approval emails and OTP emails via SMTP (currently Gmail; target Brevo/domain).
- **Password Reset Service**  
  - Issues and validates reset tokens, updates passwords, and emails reset links.
- **Audit & Security Service**  
  - Logs security events, audit trail entries, login attempts, rate limit records.
- **Contact Inquiry Service**  
  - Accepts public contact form submissions, stores them, and emails admins/dashboard inbox.

## Communication Patterns
- **Gateway → Services:** REST/HTTP (PHP controllers) for auth, user, documents, facilities, reservations, calendar, notifications, exports, audit.
- **Auth & Session → Email/OTP:** SMTP to send OTP and approval emails.
- **Password Reset → Email/OTP:** SMTP to send reset links with tokens.
- **Reservation → Notification:** Direct DB writes to notification store (polled via HTTP by the UI); no message queue.
- **Reservation → Auto-Approval Service:** In-process call to evaluate 8 conditions and determine approval status.
- **Reservation → Violation Tracking:** Links violations to specific reservations when applicable.
- **Reservation → AI Recommendation:** In-process/HTTP call for conflict detection (overlapping time ranges), recommendations, and holiday/event risk tagging. **Optimized (Jan 2025)**: Combined queries, timeout protection, debounced client-side calls, database indexes.
- **Reservation → Calendar:** Shared DB view; calendar reads reservation data (HTTP/read-only queries).
- **Facility → AI Recommendation:** Reads facility coordinates/status to compute scores (in-process/HTTP).
- **User/Profile → Document Service:** HTTP/form upload; Document Service writes file + metadata.
- **Audit & Security:** Synchronous DB writes on each critical action; no queue.
- **Contact Form → Contact Inquiry Service:** HTTP/AJAX posts; service stores inquiry and triggers admin email.

## Future/Planned Microservices (UI Ready or Design Complete)

### **AI Chatbot Service** (UI Implemented, Model Integration Pending)
- **Status**: Frontend complete, backend integration pending
- **Current Implementation**: 
  - Floating chatbot widget on dashboard pages
  - Mock response system for testing
  - Message handling and UI state management
- **Planned Backend**:
  - AI/ML model API integration (OpenAI, custom LLM, or hosted service)
  - Context retrieval from reservations and facilities
  - FAQ grounding system
  - Safety filtering and allow-listing
- **Communication Pattern**: REST API to AI provider, queries internal DB for context

### **Maintenance Management Integration Service** (Design Complete, Not Implemented)
- **Status**: Planned, high priority
- **Planned Features**:
  - Receive maintenance schedules via API/webhook
  - Auto-update facility status to `maintenance`
  - Block booking dates during maintenance windows
  - Notify affected users
- **Communication Pattern**: Webhook/API from Maintenance Management system

### **Infrastructure Management Integration Service** (Design Complete, Not Implemented)
- **Status**: Planned, medium priority
- **Planned Features**:
  - Receive project timelines via API
  - Auto-block facilities during construction
  - Auto-create facilities when projects complete
  - Update facility capacity for expansions
- **Communication Pattern**: REST API for project data exchange

### **Utilities Billing Integration Service** (Design Complete, Not Implemented)
- **Status**: Planned, medium priority
- **Planned Features**:
  - Receive utility outage alerts via API
  - Block facilities when utilities are unavailable
  - Provide facility usage data for billing
  - Track utility consumption per reservation
- **Communication Pattern**: REST API for utility data exchange

## Notes
- Current deployment is monolithic PHP with modular responsibilities; the "services" above are logical boundaries. Actual calls are HTTP within the app; SMTP is used for email/OTP/reset/inquiry alerts. No message queue is present today. 
- **Future Integrations**: Brevo/domain SMTP is planned to replace Gmail SMTP. AI chatbot UI is implemented and ready for model integration. LGU system integrations (Maintenance Management, Infrastructure Management, Utilities Billing) are designed but not yet implemented.




