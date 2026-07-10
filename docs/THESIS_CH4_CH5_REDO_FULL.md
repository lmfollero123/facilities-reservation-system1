# Thesis Replacement Pack — Chapter 4 (Sections 4–12) + Chapter 5 (Conclusion)

**Project:** LOCAL GOVERNMENT UNIT 1: AI DRIVEN FACILITIES RESERVATION SYSTEM WITH PREDICTIVE SCHEDULING FEATURES  
**Barangay:** Culiat, Quezon City  
**Source of truth:** Source code + database (`database/schema.sql`, migrations) + routes (`index.php`) + module catalog (`docs/MODULES_LIST.md`)  
**Use with:** `docs/Capstone-format-for-finals.docx` and your working draft `docs/CHAPTER-3-RESEARCH-2.docx`

**Important:** This file follows your format where **Chapter 4** contains sections **4.1–4.4 and 5–12**, and **Chapter 5** is **Conclusion (13.1–13.4)**.

---

## Manual Word formatting (use the template styles)

Apply the exact **font / spacing / margins / heading styles** from `Capstone-format-for-finals.docx`. This pack only provides the *content* (copy‑paste).

- **Headings**: follow the template’s “CHAPTER” heading style and numbered subsection style.
- **Body**: apply the template body style (Times New Roman 12, double spacing if that’s what your template enforces).
- **Captions**: standardize to `Figure X. …` and `Table X. …` consistently.

---

# CHAPTER 4 — REQUIREMENTS ANALYSIS

Chapter 4 presents the requirements analysis and architecture-related documentation required by the capstone format. It identifies stakeholders, explains requirement gathering, provides user stories and use cases, and documents the functional requirements for integration. It further presents business process architecture, application architecture, data architecture, technology architecture, development process, implementation, testing and quality assurance, and results/evaluation sections as required by the template—while ensuring that all technical claims reflect the implemented system.

## 4.1. Stakeholder Identification

Stakeholders are individuals or groups who influence or are influenced by the Facilities Reservation System of Barangay Culiat.

### 4.1.1. Primary stakeholders

**Resident Users** are barangay constituents who register, verify their accounts, browse facilities, submit reservation requests, and manage their bookings (including reschedule/cancel within policy). They require transparency in availability, timely status updates, and fair access policies.

**Barangay Staff** process reservation approvals/denials, manage facilities and blackout dates, monitor attendance/check-in, handle resident inquiries, and generate operational reports. They require efficient queue management and consistent enforcement of policies.

**System Administrators** configure system-wide settings, manage accounts, roles and permissions, audit trails, security policies, documents, and integration credentials. They require accountability features (audit logs) and administrative controls.

### 4.1.2. Secondary stakeholders

**LGU leadership / barangay officials** rely on reports and summarized operational data for resource planning and policy decisions.

**CIMM (Community Infrastructure Maintenance Management)** is an external system that can synchronize maintenance schedules/status with the Facilities Reservation System so facilities can be placed under maintenance and booking can be restricted accordingly.

**PayMongo** (payments provider) is used when online payments are enabled for the capstone demo.

**Google Gemini API** supports the dashboard AI chatbot and selected AI-assisted summaries when configured.

### 4.1.3. Stakeholder summary table

**Table 4.1. Stakeholders and Primary System Touchpoints**

| Stakeholder | Role in the process | Main touchpoints |
|------------|----------------------|------------------|
| Resident | Request bookings, track status, manage own profile | Public portal, Book Facility, My Reservations, Notifications |
| Staff | Approve/deny, manage facilities, check-in, reports | Reservations Manage, Facility Management, Check-in, Reports |
| Admin | Governance and configuration | User Management, System Settings, Audit Trail, Documents |
| CIMM | Maintenance status source | Maintenance Integration sync + webhook updates |
| PayMongo | Payment processing | Pay Now + return + webhook (when enabled) |

---

## 4.2. Requirements Gathering Techniques

Requirements were gathered through a combination of stakeholder communication and iterative development using Agile Scrum (as described in Chapter 3). The following techniques were applied:

1. **Process observation and review** of the manual facility reservation workflow (walk-in requests, logbooks, informal messaging) to identify inefficiencies, delays, and conflict points.
2. **Backlog-driven refinement** during Scrum planning and review cycles to translate needs into user stories with acceptance criteria and prioritize delivery.
3. **Prototyping and sprint demonstrations** to validate requirements through working software increments.
4. **Code-verified validation** where implemented rules (e.g., booking limits, conflict checks, facility status restrictions) were verified against the actual application behavior.

---

## 4.3. User Stories and Use Cases

### 4.3.1. User stories (implemented)

**Table 4.2. Sample Implemented User Stories**

| ID | Role | User story |
|----|------|------------|
| US-01 | Resident | As a resident, I want to register and verify my account so I can access online facility booking. |
| US-02 | Resident | As a resident, I want to browse facilities and check availability so I can plan my reservation. |
| US-03 | Resident | As a resident, I want the system to prevent double booking so conflicts are avoided. |
| US-04 | Resident | As a resident, I want to receive notifications on approval/denial/reschedule so I stay informed. |
| US-05 | Staff | As staff, I want to approve or deny reservations so facility usage follows barangay policy. |
| US-06 | Staff | As staff, I want to set blackout dates and facility maintenance status so unavailable periods are blocked. |
| US-07 | Admin | As an admin, I want audit logs and role-based permissions so actions are traceable and controlled. |
| US-08 | Resident/Staff | As users, we want online payments (demo) so payment-gated reservations can be confirmed. |
| US-09 | Staff/Admin | As staff/admin, we want CIMM-linked maintenance updates so facility status reflects real maintenance. |

### 4.3.2. Use cases (summary)

**Table 4.3. Core Use Cases**

| Use case | Primary actor | Brief description |
|----------|---------------|------------------|
| UC-01 Register account | Resident | Create account with required profile info and verification steps. |
| UC-02 Browse facilities | Resident | View facility list and details; check availability and rules. |
| UC-03 Create reservation | Resident / Staff (walk-in) | Submit request with date/time, purpose, attendees; system validates policy and conflicts. |
| UC-04 Approve/Deny reservation | Staff/Admin | Review pending requests; decide approval or denial with notes and notifications. |
| UC-05 Reschedule reservation | Resident | Reschedule within allowed window and limits; revalidate conflicts. |
| UC-06 Maintain facility availability | Staff/Admin/System | Apply blackout dates; set maintenance status manually or via CIMM integration. |
| UC-07 Pay for reservation (demo) | Resident | Redirect to PayMongo checkout; confirm via webhook/return flow. |

---

## 4.4. Functional Requirements for Integration

Integration requirements describe data exchange with external systems. The application is implemented as a **single PHP web application** (modular monolith), not separately deployed microservices.

### 4.4.1. CIMM maintenance integration

- The system shall **receive maintenance schedules** from CIMM and map them to local facilities.
- The system shall **set facility status to maintenance** when a matched schedule indicates active maintenance.
- The system shall **block bookings** for facilities that are under maintenance and enforce blackout dates during maintenance windows.
- The system shall **synchronize updates** via scheduled sync and/or dashboard-triggered sync; status updates may also be received via webhook from CIMM.

### 4.4.2. Online payments (PayMongo) — capstone demo enabled

- The system shall redirect residents to PayMongo for payment checkout when payment gating is enabled.
- The system shall process payment confirmation through the configured webhook/return handling.
- The system shall store payment records linked to reservations in the database when the payments module is active.

### 4.4.3. AI services (Gemini chatbot)

- The system shall provide a dashboard chatbot feature.
- When a Gemini API key is configured, the system shall call Gemini to generate responses; otherwise, it shall return safe fallback responses.
- The system shall enforce rate limiting for AI endpoints.

---

# 5 — BUSINESS PROCESS ARCHITECTURE

This section documents the business processes supported by the system and how they improve the current manual workflow.

## 5.1. Identification of Business Processes

The implemented system supports the following major business processes:

| Process ID | Process name | Description |
|------------|--------------|-------------|
| BP-01 | Account onboarding | Registration, verification, login security |
| BP-02 | Facility management | CRUD, status management, operating hours, blackouts |
| BP-03 | Reservation request | Booking submission with validation and conflict prevention |
| BP-04 | Approval workflow | Staff review, approve/deny, timeline/history |
| BP-05 | Payment processing (demo) | PayMongo checkout + confirmation |
| BP-06 | Maintenance alignment | CIMM sync + local maintenance/blackout enforcement |
| BP-07 | Attendance/check-in | Check-in/out workflows and occupancy monitoring |
| BP-08 | Reporting and audit | Reports export and audit trail accountability |

## 5.2. Business Process Diagrams

Create BPMN/flowcharts in Word or draw.io using the flows below.

**Figure 5. Business Process — Reservation Request and Approval (To‑Be)**

1. Resident selects facility and schedule.
2. System validates booking rules (limits, advance window, blackouts, facility status).
3. System checks schedule conflicts.
4. If eligible, auto-approval may approve immediately; otherwise request remains pending.
5. Staff reviews and approves/denies; notifications are sent.
6. If payment-gated (demo), the reservation proceeds through PayMongo as configured.

## 5.3. Alignment of Integrated System with Business Processes

The system aligns with barangay operations by:

- Providing a centralized digital queue for reservations and approvals.
- Enforcing consistent rule-based validation (booking limits, conflict checks, maintenance blocking).
- Providing traceability through reservation history and audit logs.
- Supporting integration points (CIMM maintenance updates and PayMongo payments for demo).

## 5.4. Business Process Improvements

Compared with manual logbooks and walk-in coordination, the system improves:

- **Speed**: residents can submit requests online and view availability without repeated visits.
- **Accuracy**: conflict detection reduces double booking.
- **Accountability**: audit logs and history records provide traceability.
- **Operational continuity**: maintenance periods can automatically restrict booking through CIMM sync.

---

# 6 — APPLICATION ARCHITECTURE

## 6.1. Components of Application Architecture

The system is implemented as a modular monolithic PHP web application with the following logical components:

1. **Presentation layer**: public pages and authenticated dashboard pages rendered via PHP views, with JavaScript for interactive features.
2. **Routing/front controller**: a single entry point that maps URLs to page handlers.
3. **Business logic modules**: booking rules, auto-approval evaluation, conflict checks, recommendations, notifications, maintenance sync.
4. **Integration services**: CIMM maintenance sync, PayMongo payments (demo), Gemini chatbot, email and SMS services.
5. **Data layer**: MySQL database with normalized tables for users, facilities, reservations, history, notifications, audit logs, violations, and payment records (when enabled).

## 6.2. Application Architecture Diagrams

**Figure 6. Application Architecture (High Level)**

Describe the architecture diagram with:

- Browser (Resident/Staff/Admin)  
- PHP Application (public + dashboard modules)  
- Database (MySQL)  
- External services (CIMM, PayMongo, Gemini, SMTP/SMS)

## 6.3. Integration of Software Modules

Key module integrations include:

- Booking module ↔ facilities module (status + blackouts + operating hours)
- Booking module ↔ approval module (pending/approved/denied lifecycle + history)
- Maintenance module ↔ facilities module (maintenance/offline/available enforcement)
- Payment module ↔ reservations module (pending payment/confirmation)
- Notification module ↔ reservations/facilities module (status updates)

## 6.4. Communication and Interaction Patterns

- **Client to server**: HTTPS requests (forms + AJAX endpoints in dashboard).
- **Server to DB**: PDO queries with prepared statements.
- **Server to external APIs**: outbound HTTPS for CIMM/Gemini/PayMongo (as configured).
- **Webhooks**: inbound webhook calls for payment confirmation and maintenance status updates.

---

# 7 — DATA ARCHITECTURE

## 7.1. Data Sources and Types

Primary data sources include:

- User account data (registration, verification, roles, status)
- Facility data (name, location, capacity, amenities, rules, operating hours, status)
- Reservation data (date/time, purpose, attendees, status, reschedule count, history)
- Operational data (audit logs, notifications, violations, attendance/check-in)
- Integration data (CIMM schedules; PayMongo payment events; chatbot interactions)

## 7.2. Data Flow Diagrams

**Figure 7. Data Flow Diagram (Context / Level 0)**

Show flows among:

- Resident/Staff/Admin ↔ Facilities Reservation System
- System ↔ Database
- System ↔ CIMM (maintenance schedules/status)
- System ↔ PayMongo (payments)
- System ↔ Gemini (chatbot)

## 7.3. Data Storage and Management

Data is stored in a relational database with a schema designed to:

- maintain referential integrity for reservations tied to users and facilities,
- keep a reservation history for status timeline,
- store notifications and audit logs for accountability,
- support blackout dates and violations for policy enforcement.

## 7.4. Data Synchronization Across Systems

Synchronization mechanisms include:

- **CIMM sync** to keep facility status and maintenance blackout dates aligned with maintenance schedules.
- **PayMongo payment sync** to keep reservation payment state consistent through webhook confirmation.

---

# 8 — TECHNOLOGY ARCHITECTURE

## 8.1 Technology Stack and Infrastructure

The system is deployed as a web application using:

- **PHP** backend with PDO
- **MySQL** database
- **HTML/CSS/JavaScript** frontend with a responsive UI
- **SMTP email** and optional SMS integration (as configured)

## 8.2 Software Technologies

Supporting technologies include:

- CSRF protection and secure session handling
- password hashing (bcrypt)
- rate limiting for security-sensitive and AI endpoints
- optional integrations: PayMongo, CIMM, Gemini

## 8.3. Scalability and Performance Considerations

Performance considerations include:

- database indexing for frequently queried fields (reservations, notifications, audit logs),
- minimizing expensive external calls through rate limiting and fallbacks,
- using cached/static assets and a responsive UI for usability across devices.

---

# 9 — DEVELOPMENT PROCESS

## 9.1. Agile Scrum Roles and Responsibilities

The project followed Agile Scrum with defined roles such as Product Owner, Scrum Master, and Development Team members as documented in Chapter 3.

## 9.2. Sprint Planning and Backlog Management

The team maintained a Product Backlog and Sprint Backlog to track features, prioritize critical requirements, and ensure continuous delivery of working increments.

## 9.3. Sprint Execution and Deliverables

Each sprint delivered functional increments such as authentication, booking rules, approval workflow, notifications, AI assistance, integrations, and reporting features.

## 9.4. Challenges Faced in the Development Process

Common challenges included aligning technical implementation with real barangay policies, ensuring secure handling of user data, and integrating external services (CIMM and PayMongo) reliably under deployment constraints.

---

# 10 — IMPLEMENTATION

## 10.1. Technical Implementation Details

Implementation highlights include:

- centralized routing through a front controller and mapped dashboard routes,
- strict booking validations (limits, advance window, conflict detection, facility maintenance/offline blocking),
- role-based permissions for staff/admin actions,
- reservation history and audit trail for accountability,
- dashboard AI assistant features with safe fallback behavior.

## 10.2. Tools and Technologies Used

- PHP, MySQL, HTML/CSS/JavaScript
- Git for version control
- Hosting server tools for deployment and environment configuration

## 10.3. Code Integration and Interoperability

Interoperability is achieved through:

- defined API endpoints for public availability and integration flows,
- internal service modules for CIMM sync, payments, email, and AI.

## 10.4. Integration Testing and Debugging

Integration testing includes verifying:

- CIMM maintenance updates correctly reflect in facility status and booking restrictions,
- PayMongo payment flow completes and updates reservation status as expected,
- chatbot endpoints behave correctly with and without external API availability.

---

# 11 — TESTING AND QUALITY ASSURANCE

## 11.1. Testing Strategies and Methodologies

Testing focused on functional correctness of user flows (resident booking, staff approval, admin controls) and security checks (session validity, CSRF, role restrictions).

## 11.2. Test Cases and Test Data

Test cases should cover:

- booking limit enforcement,
- conflict detection (overlap logic),
- reschedule policy enforcement,
- facility blackout dates and maintenance blocking,
- payment-gated reservations (demo),
- CIMM-driven facility status updates.

## 11.3. Test Results and Bug Reports

Document key bugs found during testing (if applicable) and the corrective actions taken, especially for integration issues (CIMM/webhook, payment webhooks).

## 11.4. Quality Assurance Measures

Quality assurance measures include:

- validation of inputs and server-side enforcement of policies,
- audit logging for administrative actions,
- secure document handling and access controls,
- rate limiting on sensitive endpoints.

---

# 12 — RESULTS AND EVALUATION

## 12.1. Project Outcomes and Deliverables

The project delivered a web-based Facilities Reservation System with:

- resident booking and self-service management,
- staff/admin workflows for facilities and reservations,
- maintenance alignment through CIMM integration,
- optional online payments (enabled for capstone demo),
- reporting, audit trail, and AI-assisted features.

## 12.2. Alignment with Project Objectives

The implemented features align with the stated objectives by reducing manual scheduling conflicts, improving visibility of availability, and strengthening accountability through logs and histories.

## 12.3. Stakeholder and User Feedback

Add your collected feedback here (panel/staff/resident testers). Do not invent numeric ratings—paste your real evaluation results and comments.

## 12.4. Lessons Learned

Lessons learned include the importance of:

- validating requirements against actual operational constraints,
- implementing strong security defaults for LGU systems,
- designing integrations with reliable fallbacks and clear monitoring,
- maintaining documentation accuracy by referencing the actual codebase.

---

# CHAPTER 5 — CONCLUSION

## 13.1. Key Takeaways and Summary

The Facilities Reservation System for Barangay Culiat provides a centralized platform for managing facility reservations with conflict prevention, policy enforcement, staff approval workflows, notifications, and operational reporting. The system integrates maintenance synchronization with CIMM and supports online payments through PayMongo for capstone demonstration, improving transparency and reducing manual effort in scheduling.

## 13.2. Project Achievements and Contributions

Key achievements include:

- a complete online reservation lifecycle for public facilities,
- enforceable booking policies to prevent abuse and conflicts,
- administrative governance through role permissions and audit trail,
- integration-ready architecture with CIMM maintenance sync and PayMongo payments,
- AI-assisted support features such as recommendations and a dashboard chatbot.

## 13.3. Future Work and Enhancements

Future improvements may include:

- expanding external integrations beyond maintenance and payments (subject to LGU APIs),
- enhancing reporting dashboards and analytics,
- refining facility matching and status standardization with CIMM,
- improving user experience and accessibility based on stakeholder feedback.

## 13.4. Closing Remarks

This capstone demonstrates how a properly designed web-based reservation platform can support barangay operations by improving scheduling efficiency, transparency, and accountability. The project is positioned for further enhancement based on continuous stakeholder feedback and evolving LGU operational requirements.

