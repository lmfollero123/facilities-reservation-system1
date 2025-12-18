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
  - Booking with flexible time slots (start_time - end_time), conflict check (overlapping time ranges), auto-approval evaluation (8 conditions), history/timeline, auto-decline expired pending, booking limits (≤3 active/30 days, ≤60-day advance, ≤1/day), reschedule functionality (residents can reschedule up to 3 days before, one reschedule per reservation).
- **Auto-Approval Module**  
  - Evaluates reservations against 8 conditions (facility auto_approve flag, blackout dates, duration limits, capacity thresholds, commercial purpose check, time conflicts, user violations, advance booking window).
- **Violation Tracking Module**  
  - Records user violations (no-show, late cancellation, policy violation, damage, other), tracks severity levels (low/medium/high/critical), affects auto-approval eligibility for users with high/critical violations.
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
  - Users, sessions, reservations (with auto_approved, reschedule_count, expected_attendees, is_commercial fields), facilities (with auto_approve, capacity_threshold, max_duration_hours), docs, notifications, audit, security logs, user_violations, facility_blackout_dates.
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

## Future/Planned Integrations (UI Ready or Design Complete)

### 🤖 **AI Chatbot Integration** (High Priority - UI Implemented)
- **Status**: UI implemented, AI/ML model integration pending
- **Current State**: 
  - Floating chatbot widget on all dashboard pages
  - Mock responses for testing
  - API endpoint structure ready: `POST /api/ai/chat`
- **Planned Features**:
  - Connect to AI/ML model API (OpenAI, custom LLM, or hosted service)
  - Context-aware responses using reservation and facility data
  - FAQ grounding on system documentation
  - Safety/allow-listing for content filtering
  - Multi-turn conversation support
  - Quick action buttons for common queries
- **Integration Points**:
  - Queries D3 (Reservations) and D4 (Facilities) for contextual responses
  - Can answer questions about booking, availability, policies
  - Can assist with facility selection and recommendations
- **Files Ready**: 
  - `resources/views/layouts/dashboard_layout.php` (floating widget)
  - `resources/views/pages/dashboard/ai_chatbot.php` (standalone page)
  - JavaScript handlers for message processing

### 🏙️ **Urban Planning & Development Integration** (Medium Priority - UI Implemented)
- **Status**: UI implemented, API integration pending
- **Current State**:
  - Dashboard page exists: `urban_planning_integration.php`
  - Mock data for planning recommendations displayed
  - Admin/Staff access control implemented
- **Planned Features**:
  - **Demand Forecasting**: Export reservation trends and facility usage statistics for urban planning analysis
  - **Location Analytics**: Provide facility usage data (peak times, capacity utilization) for planning decisions
  - **New Development Integration**: Automatically add new facilities when developments are approved
  - **Zoning Compliance**: Validate facility usage against zoning regulations (e.g., event types, capacity limits)
  - **Planning Recommendations**: Receive and display planning recommendations from Urban Planning system
  - **Timeline Sync**: Synchronize facility development timelines with planning projects
- **Data Exchange**:
  - **Outbound**: Reservation trends, facility usage statistics, location data, capacity utilization metrics
  - **Inbound**: Zoning changes, new development plans, planning recommendations, project timelines
- **API Endpoints (To Be Implemented)**:
  - `GET /api/integrations/urban-planning/analytics` - Provide usage analytics
  - `POST /api/integrations/urban-planning/new-development` - Receive new development notifications
  - `GET /api/integrations/urban-planning/zoning-changes` - Receive zoning regulation updates
  - `POST /api/integrations/urban-planning/facility-usage` - Export facility usage data

### 🔧 **Community Infrastructure Maintenance Management Integration** (High Priority - Not Implemented)
- **Status**: Planned, priority integration
- **Planned Features**:
  - **Facility Status Sync**: Automatically set facility status to `maintenance` when maintenance is scheduled
  - **Maintenance Calendar Blocking**: Block booking dates when maintenance is scheduled
  - **Maintenance Notifications**: Notify users with pending/approved reservations when maintenance is scheduled
  - **Maintenance History**: Link facility maintenance records to reservation history
  - **Proactive Maintenance**: Suggest maintenance windows based on facility usage patterns
- **API Endpoints (To Be Implemented)**:
  - `POST /api/integrations/maintenance/schedule` - Receive maintenance schedules
  - `POST /api/integrations/maintenance/completion` - Receive maintenance completion notifications
  - `GET /api/integrations/facilities/status` - Provide facility status

### 🏗️ **Infrastructure Project Management Integration** (Medium Priority - Not Implemented)
- **Status**: Planned integration
- **Planned Features**:
  - **Project Timeline Sync**: Block facilities during construction/renovation projects
  - **New Facility Integration**: Automatically add new facilities when projects are completed
  - **Project Notifications**: Alert users about facility closures due to projects
  - **Capacity Updates**: Update facility capacity when expansion projects complete
- **API Endpoints (To Be Implemented)**:
  - `POST /api/integrations/projects/timeline` - Receive project timelines
  - `POST /api/integrations/projects/facility-creation` - Receive new facility data from completed projects

### ⚡ **Utilities Billing & Management Integration** (Low Priority - Not Implemented)
- **Status**: Future enhancement
- **Planned Features**:
  - **Utility Cost Tracking**: Link facility usage to utility consumption
  - **Billing Integration**: Include facility rental fees in utility bills (if applicable)
  - **Utility Outage Alerts**: Block facilities when utilities are unavailable
  - **Energy Usage Reporting**: Track facility energy consumption per reservation
- **API Endpoints (To Be Implemented)**:
  - `POST /api/integrations/utilities/outage` - Receive utility outage alerts
  - `GET /api/integrations/facilities/usage` - Provide facility usage data for billing

### 🚧 **Road and Transportation Infrastructure Monitoring Integration** (Low Priority - Not Implemented)
- **Status**: Future enhancement
- **Planned Features**:
  - **Accessibility Alerts**: Notify users about road closures affecting facility access
  - **Traffic Impact**: Consider traffic patterns in facility recommendations
  - **Parking Availability**: Link to parking management (if applicable)
- **API Endpoints (To Be Implemented)**:
  - `POST /api/integrations/transport/road-closure` - Receive road closure notifications
  - `GET /api/integrations/facilities/locations` - Provide facility location data

### 💡 **Energy Efficiency Management Integration** (Low Priority - Not Implemented)
- **Status**: Future enhancement
- **Planned Features**:
  - **Usage Analytics**: Share facility usage data for energy planning
  - **Efficiency Recommendations**: Receive recommendations for energy-efficient scheduling
  - **Peak Usage Tracking**: Identify peak usage times for energy optimization
- **API Endpoints (To Be Implemented)**:
  - `GET /api/integrations/energy/usage-analytics` - Provide usage statistics
  - `POST /api/integrations/energy/recommendations` - Receive efficiency recommendations

---

## Notes
- Current deployment is monolith with logical boundaries; "API gateway" is the web layer/controller tier acting as a single entry point. Booking path enforces reservation limits before writes.
- **Future Integrations**: 
  - SMTP: swap to Brevo + domain
  - AI Chatbot: UI implemented, awaiting AI/ML model integration
  - Urban Planning: UI implemented with mock data, awaiting API integration
  - Other LGU systems: Design complete, awaiting implementation prioritization




