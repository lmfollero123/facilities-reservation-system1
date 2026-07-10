# Thesis Chapter Replacement Pack (Chapters 1–3)

**Project:** LOCAL GOVERNMENT UNIT 1: AI DRIVEN FACILITIES RESERVATION SYSTEM WITH PREDICTIVE SCHEDULING FEATURES  
**Barangay:** Culiat, Quezon City  
**Source of truth:** Source code, `database/schema.sql`, `index.php`, `docs/MODULES_LIST.md`  
**Use with:** `docs/Capstone-format-for-finals.docx` (format) and `docs/CHAPTER-3-RESEARCH-2.docx` (current draft)

---

## How to use this file

1. Open `CHAPTER-3-RESEARCH-2.docx` in Microsoft Word.
2. Apply the **Word formatting checklist** below to every replaced section.
3. For each section listed in **Replacement map**, select the old paragraph(s) and paste the matching **Replacement text**.
4. Keep your existing Scrum tables, sprint dates, figures, and team names where they are still accurate.
5. Do **not** paste placeholder or corrupted text (e.g., `kskbkslk`, `jndj`, Lorem ipsum blocks) — delete those entirely.

---

## Word formatting checklist

Apply these before final submission:

| Item | School template rule | Fix in current draft |
|------|---------------------|----------------------|
| Section numbers | Space after number: `1.1.`, `2.3.`, `3.1.` | Change `3.1Roles` → `3.1. Roles` |
| Chapter intro | Space after chapter number | Change `Chapter 3illustrates` → `Chapter 3 illustrates` |
| Figure captions | `Figure 2. Microservices Architecture` | Change `Figure no. 2Microservices` → `Figure 2. Microservices Architecture` |
| Body font | Times New Roman, 12 pt | Select All → set font if not already applied |
| Chapter titles | Bold, centered or per template | Match `Capstone-format-for-finals.docx` |
| Line spacing | Double spacing for body (verify in template) | Use Paragraph → Line spacing → Double |
| Bullets | One feature per line | Split merged bullet line in §1.2 |
| Approval sheet | Fix merged words | `PREDICTIVE SCHEDULING` (add space) |

---

## Replacement map (what to change)

| Location | Action | Reason |
|----------|--------|--------|
| §1.1 Background — equipment mentions | **Replace** | No equipment module in code |
| §1.2 Context and Scope — bullets, payments, equipment | **Replace** | PayMongo exists; facilities only |
| §1.3 Problem Statement — equipment items | **Replace** | Facilities-only system |
| §1.4 Objectives — equipment wording | **Replace** | Facilities-only system |
| §1.6 Structure — payments out of scope | **Replace** | Payments are optional, not absent |
| §2.2 EA paragraph — equipment tracking | **Replace** | Not implemented |
| §2.3 Microservices Architecture | **Replace entire section** | System is modular monolith |
| §2.3 Future/Planned list | **Replace** | Chatbot/CIMM status wrong |
| §2.6 Integration paragraph — equipment inventory | **Replace** | Not implemented |
| §3 intro paragraph | **Fix spacing only** | `Chapter 3 illustrates` |
| §3.1 Roles | **Fix heading spacing** | `3.1. Roles and Responsibilities` |
| §3.4 Microservices Architecture | **Rename + replace** | Use §3.4 text below |
| §3.5 DevOps | **Revise opening** | Keep your sprint/CI detail if accurate |
| §3.7 Integration Approach | **Replace** | Overstates live integrations |
| Chapter 2 intro — microservices claim | **Replace sentence** | Use modular monolith wording |
| Chapter 3 summary — microservices claim | **Replace sentence** | Use modular monolith wording |

**Keep unchanged (if your dates/names are verified):**
- §3.1 Roles table (team members)
- §3.2 Sprint Cycles / Gantt content
- §3.3 Scrum Artifacts tables
- Literature review citations (§2.5) unless you need to trim
- Front matter (title, authors, adviser) — fix typos only

---

# CHAPTER 1 — REPLACEMENT TEXT

## 1.1. Background of the Capstone Project

The barangays in the Philippines serve as the smallest administrative units responsible for delivering basic public services and managing shared community resources. Among these responsibilities is the proper scheduling and allocation of public facilities such as basketball courts, multipurpose halls, covered courts, and community spaces. Despite the importance of these services, many barangays continue to rely on manual and informal processes for managing facility reservations.

In Barangay Culiat, Quezon City, residents and staff have historically coordinated facility use through walk-in requests, phone messages, logbooks, and informal communication channels. These methods often result in delayed responses, inconsistent records, overlapping bookings, and limited visibility of facility availability. Residents may need to visit the barangay hall repeatedly to confirm schedules, while staff spend significant time validating requests and resolving conflicts manually.

To address these challenges, the researchers developed the AI-Driven Facilities Reservation System with Predictive Scheduling Features for Barangay Culiat. The system is a web-based PHP and MySQL application that centralizes facility reservation workflows for residents, barangay staff, and administrators. It supports online booking, real-time availability checks, staff approval, AI-assisted conflict detection and recommendations, notifications, attendance tracking, and operational reporting.

The system was implemented as a modular monolithic web application with optional Python machine learning services and optional Google Gemini integration for the dashboard chatbot. Its purpose is to improve transparency, reduce scheduling conflicts, and provide barangay personnel with a single platform for managing public facility reservations.

## 1.2. Context and Scope

This capstone project addresses barangay-level public service management through the digital transformation of **facility** reservation processes. The system is a web-based application accessible to residents, barangay staff, and administrators with role-based permissions.

**Implemented scope includes:**

- Online reservation of barangay **facilities** (not separate equipment inventory)
- Real-time availability display and calendar views
- AI-assisted conflict detection, facility recommendations, and holiday/event risk tagging
- Staff approval, reschedule, cancellation, and violation handling
- In-app, email, and optional SMS notifications
- Facility management, blackout dates, QR check-in, and live occupancy monitoring
- Reports, audit trail, and document management aligned with Data Privacy Act practices
- Optional PayMongo payment checkout (environment-gated; disabled by default in capstone deployment)
- Outbound CIMM maintenance synchronization when API credentials are configured

**Out of scope or preview-only in the current implementation:**

- Native mobile application (responsive web interface only)
- Separate equipment rental or equipment inventory module
- Live Infrastructure Projects and Utilities API integrations (admin preview pages use sample data)
- Inbound `/api/integrations/*` webhook endpoints (return not-implemented responses)
- Full demand-forecasting dashboard UI (Python forecasting script exists; UI coverage is partial)

The system is intended for use within Barangay Culiat and is designed to support current operational needs while remaining extensible for future LGU integrations.

## 1.3. Problem Statement

### 1.3.1. Main Problem

Barangay Culiat experiences inefficiencies in managing and scheduling public facilities due to the absence of a centralized digital reservation system. Manual and informal processes create delays, inconsistent records, and limited transparency for residents and staff.

### 1.3.2. Specific Problems

1. Lack of a centralized system for real-time monitoring of **facility** availability
2. Frequent double bookings and scheduling conflicts for high-demand facilities
3. Delayed communication between residents and barangay personnel regarding reservation status
4. Difficulty tracking facility usage history and reservation records in one platform
5. Inconvenient reservation processes that require physical visits or unstructured messaging
6. Limited use of data for planning peak usage periods and facility demand patterns

## 1.4. Objectives and Goals

### 1.4.1. General Objective

To develop an AI-driven facility reservation and scheduling system that improves the efficiency, accuracy, and accessibility of **public facility** management in Barangay Culiat.

### 1.4.2. Specific Objectives

1. Develop a digital platform for real-time viewing and reservation of barangay facilities
2. Implement AI-assisted conflict detection, recommendations, and scheduling support
3. Provide role-based access for residents, staff, and administrators
4. Enable staff approval workflows, notifications, and reservation lifecycle management
5. Support attendance check-in, occupancy monitoring, and operational reporting
6. Integrate optional external services (CIMM maintenance sync, PayMongo, Gemini) through configuration

### 1.4.3. Goal

Modernize Barangay Culiat’s facility management by introducing a centralized, automated, and intelligent reservation system that increases transparency, convenience, and operational efficiency for both residents and barangay personnel.

### 1.4.4. Users and Beneficiaries

**Users:** Residents of Barangay Culiat, barangay staff, and system administrators  
**Beneficiaries:** The barangay community, barangay personnel, and local government stakeholders who rely on accurate facility scheduling and accountable public service delivery

## 1.5. Significance and Relevance

This system provides a practical model for how a local government unit can digitize facility reservations using a maintainable web architecture. For residents, it reduces uncertainty and improves access to barangay facilities. For staff, it centralizes approvals, records, and monitoring. For the capstone program, it demonstrates verifiable implementation of authentication, booking rules, AI assistance, integrations, and security controls grounded in actual source code rather than theoretical features.

The study is relevant to barangay digital transformation, community service automation, and LGU information system design.

## 1.6. Structure of the Document

Chapter 1 presents the background, scope, problems, objectives, and significance of the project. Chapter 2 discusses related literature and technical concepts including Agile Scrum, enterprise architecture, modular system design, DevOps practices, and integration principles. Chapter 3 explains the methodology, team structure, sprint cycles, scrum artifacts, system architecture, DevOps implementation, and integration approach used during development. Later chapters (Requirements Analysis, Design, Implementation, Testing, and Conclusion) document the engineering phases of the implemented system.

---

# CHAPTER 2 — REPLACEMENT TEXT

## Chapter 2 introduction (replace first paragraph only)

This chapter presents the reviewed theories, technologies, and previous studies relevant to the development of the AI-Driven Facilities Reservation System for Barangay Culiat. It discusses established concepts that guided the system’s design and implementation, including Agile Scrum methodology, enterprise architecture, **modular monolithic application design**, DevOps and CI/CD practices, relevant research studies, and integration of information systems in enterprise environments. These literature sources provide a foundation for understanding how modern information systems can enhance community facility management, optimize resource allocation, streamline communication between residents and staff, and improve service delivery in a local government context.

## 2.2. Enterprise Architecture Concepts (replace EA application paragraph)

For the AI-Driven Facilities Reservation System in Barangay Culiat, enterprise architecture ensures that the system aligns with existing barangay operations. This includes facility scheduling, reservation approval workflows, user account management, notifications, and reporting. Following EA principles supports maintainability and future extension of the system without requiring immediate decomposition into separate deployed services. The diagram below illustrates the enterprise architecture view of the AI-Driven Facilities Reservation System.

## 2.3. System Architecture (rename from "Microservices Architecture")

> **Heading change:** Rename section **2.3 Microservices Architecture** to **2.3 System Architecture (Modular Monolith)**.

The implemented Barangay Culiat Facilities Reservation System uses a **modular monolithic architecture**, not a deployed microservices cluster. The application runs as a single PHP 8.1+ web system with one front controller (`index.php`), shared MySQL database, and logically separated modules for authentication, facilities, reservations, notifications, AI assistance, reporting, and administration.

This design groups related functions into maintainable domains while keeping deployment simple for barangay hosting environments. Modules communicate through in-process PHP includes and shared database operations. Optional external calls are made only where needed—for example SMTP email, SMS gateways, Google Gemini API, PayMongo, and CIMM maintenance API.

The architecture supports practical LGU deployment because it reduces operational overhead while still documenting clear service boundaries for future scaling decisions.

### 2.3.1. Logical Service Modules (Implemented)

The following modules are implemented in the current codebase:

| Logical module | Primary responsibility | Verified routes / files |
|----------------|------------------------|-------------------------|
| Authentication and session | Login, OTP/TOTP, registration, password reset, session timeout (5 minutes) | `config/security.php`, auth pages |
| User and profile | User records, roles, profile, notification preferences | `/dashboard/profile`, user management |
| Facility management | Facility CRUD, images, hours, geocoding, blackouts, QR posters | `/dashboard/facility-management` |
| Reservation management | Booking, approval, reschedule, cancel, limits, timeline | `/dashboard/book-facility`, reservations manage |
| Auto-approval | Eight-condition evaluation for eligible reservations | `config/auto_approval.php` |
| Violation tracking | User violations affecting approval eligibility | `config/violations.php` |
| AI assistance | Conflict check, recommendations, risk tagging, chatbot | `/dashboard/ai-conflict-check`, chatbot API |
| Calendar and reports | Calendar views, CSV/PDF-style exports, dashboard charts | `/dashboard/calendar`, `/dashboard/reports` |
| Notifications | In-app, email, optional SMS | `config/notifications.php` |
| Attendance and occupancy | Check-in/out, facility QR scan, live occupancy | `/dashboard/time-tracking`, occupancy monitor |
| Administration | Audit trail, document management, contact inquiries | `/dashboard/audit-trail` |

### 2.3.2. Communication Pattern

- **Browser → Application:** HTTP requests to `index.php` route map
- **Module → Module:** In-process PHP function calls and shared database access
- **Application → External services:** SMTP, SMS API, Gemini API, PayMongo API, CIMM API (when configured)
- **No message queue:** Notifications and audit events are written synchronously to MySQL

### 2.3.3. Integration Status Summary

| Integration | Status in codebase |
|-------------|-------------------|
| AI Chatbot (Gemini + fallback) | **Implemented** |
| CIMM maintenance (outbound sync) | **Implemented** (requires `CIMM_API_KEY`) |
| PayMongo payments | **Optional** (`PAYMENTS_ENABLED`) |
| Infrastructure projects dashboard | **Preview / mock data** |
| Utilities dashboard | **Preview / mock data** |
| Inbound `/api/integrations/*` webhooks | **Not implemented** (HTTP 501) |

## 2.6. Integration of Information Systems in Enterprise Environments (replace application paragraph)

Integration of information systems in an enterprise environment refers to connecting software components so they exchange data reliably within an organization. In the Barangay Culiat system, integration occurs primarily within the modular monolith: when a resident submits a facility reservation, the system validates booking rules, records the reservation in MySQL, evaluates auto-approval conditions, writes notifications, and updates staff dashboards without manual re-encoding.

External integration is selective and configuration-dependent. CIMM maintenance synchronization can update facility status and blackout dates through scheduled scripts. PayMongo and Gemini are optional external services. Infrastructure and utilities pages are preview modules only and do not yet consume live external APIs. This staged approach allows the barangay to deploy the core reservation system first while preparing interfaces for future LGU services.

---

# CHAPTER 3 — REPLACEMENT TEXT

## Chapter 3 introduction (replace paragraph; fix spacing)

Chapter 3 illustrates the methodology used in developing the AI-Driven Facilities Reservation System with Predictive Scheduling Features for Barangay Culiat. It discusses the Agile Scrum approach, roles and responsibilities, sprint cycles, scrum artifacts, **system architecture (modular monolith)**, DevOps implementation, and integration approach. Technical descriptions in this chapter reflect the actual implemented system verified from source code and deployment configuration.

## 3.1. Roles and Responsibilities

> **Formatting only:** Ensure heading reads `3.1. Roles and Responsibilities` (add space after number).

Keep your existing roles table and narrative. No code-driven changes required unless team assignments changed.

## 3.2. Sprint Cycles

Keep your existing sprint timeline, Gantt chart, and task breakdown. Ensure sprint deliverables use facilities-only wording (remove equipment tasks if present).

## 3.3. Scrum Artifacts

Keep your existing Product Backlog, Sprint Backlog, burndown chart, and mind map content. Verify that completed backlog items match `docs/MODULES_LIST.md`.

Suggested wording fix for Product Backlog intro:

The Product Backlog is a continuously updated list of features and enhancements for the AI-Driven Facilities Reservation System with Predictive Scheduling Features for Barangay Culiat. It includes user account management, facility administration, reservation processing, AI-assisted scheduling, notifications, reporting, document handling, security controls, and optional external integrations. Items are prioritized based on stakeholder value and implementation dependencies.

## 3.4. System Architecture (rename from "Microservices Architecture")

> **Heading change:** Rename **3.4 Microservices Architecture** to **3.4 System Architecture (Modular Monolith)**.

The development team organized the system into logical modules documented in `docs/MICROSERVICES.md` as **logical service boundaries**, not independent deployed microservices. The runtime architecture is a single PHP application with shared database connectivity.

**Implemented architectural characteristics:**

1. Single deployable web application (`index.php` front controller)
2. Shared MySQL schema with 40+ incremental migrations
3. In-process module calls (no API gateway between internal domains)
4. Optional external APIs for email, SMS, Gemini, PayMongo, and CIMM
5. PHPUnit smoke tests and GitHub Actions CI for regression checking

**Figure guidance:** If retaining architecture diagrams, label them as *Logical Module Diagram* and *Communication Pattern Diagram*, not as a distributed microservices deployment diagram unless you have separate servers/containers (the codebase does not).

## 3.5. DevOps Implementation (revise opening paragraph)

DevOps practices were applied to support reliable development and maintainable deployment of the modular monolith. The team used version control (Git/GitHub), Composer dependency management, environment-based configuration (`.env`), database migrations, cron scripts, and GitHub Actions CI to reduce integration errors and support repeatable builds.

**Verified DevOps elements in the codebase:**

| Practice | Implementation |
|----------|----------------|
| Dependency management | `composer install`, `vendor/` |
| Environment config | `.env.example`, `config/*.php` |
| Database migrations | `database/schema.sql`, `database/migration_*.sql` |
| Automated tests | `tests/`, `vendor/bin/phpunit` |
| CI pipeline | `.github/workflows/ci.yml` |
| Scheduled jobs | `scripts/auto_decline_expired.php`, `scripts/sync_cimm_maintenance.php`, etc. |
| Security controls | CSRF, rate limits, session timeout, audit logging |

Retain your existing CI/CD narrative, staging/production notes, and figure references if they match your actual deployment experience.

## 3.7. Integration Approach for Information Systems (full replace)

The Barangay Culiat AI-Driven Facilities Reservation System includes integration capabilities at different maturity levels. The thesis describes them separately to avoid overstating what is live in production.

### 3.7.1. Implemented Integrations

**AI Chatbot.** The dashboard chatbot is implemented through `/dashboard/chatbot-api`. When `GEMINI_API_KEY` is configured, the system uses Google Gemini. If the external service is unavailable, the application falls back to rule-based and ML-assisted responses so users still receive guidance.

**CIMM Maintenance (outbound).** The system can pull maintenance schedules from the CIMM API using `services/cimm_api.php` and `scripts/sync_cimm_maintenance.php`. When configured, this updates facility status and maintenance-related blackout dates. This integration is environment-dependent and requires valid API credentials.

**PayMongo (optional).** Payment checkout and webhook handling are implemented but disabled by default through environment flags. Facilities may be marked free for capstone demonstration.

### 3.7.2. Preview-Only Integrations

**Infrastructure Projects** and **Utilities** dashboards provide staff-facing preview pages with sample data. They demonstrate intended LGU workflow visibility but are not connected to live external APIs in the current codebase.

### 3.7.3. Not Yet Implemented

Inbound integration endpoints under `/api/integrations/*` return HTTP 501 (not implemented). These are placeholders for future webhook-based communication from external LGU systems.

### 3.7.4. Integration Principle Used

The team prioritized a working core reservation system first, then added optional outbound integrations that do not block basic booking operations. This matches the actual dependency structure in the source code and reduces risk during capstone deployment.

## 3.8. Introduction to TOGAF and the Four Architectural Domains

Keep your existing TOGAF discussion if already written. Add this alignment sentence before the domains:

The TOGAF domains were used as an alignment framework for documentation and stakeholder communication. The implemented system maps primarily to the Application and Technology domains (PHP/MySQL web app, optional Python/Gemini services), while Data and Business domains are represented through reservation workflows, role permissions, and barangay facility policies encoded in application rules.

---

# APPENDIX: VERIFIED IMPLEMENTATION FACTS (for defense)

Use these when panel asks "does your system actually do this?"

| Topic | Verified fact |
|-------|---------------|
| PHP version | 8.1+ (`README.md`) |
| Session idle timeout | 5 minutes (`SESSION_TIMEOUT = 300` in `config/security.php`) |
| Roles | Resident, Staff, Admin |
| Booking limits | ≤3 active / 30 days, ≤1/day, ≤60-day advance |
| Reschedule | One per reservation, ≥3 days before event |
| Auto-approval | 8 conditions in `config/auto_approval.php` |
| Architecture | Modular monolith, not deployed microservices |
| Chatbot | Implemented (Gemini + fallback) |
| CIMM | Outbound sync implemented; inbound webhooks not implemented |
| Payments | Optional PayMongo; not absent from codebase |
| Equipment module | **Not implemented** |
| Routes | `index.php` (not Laravel `routes/web.php`) |

---

# CHANGE LOG (for adviser / panel)

## Incorrect statements removed

- Equipment reservation and equipment inventory automation
- Deployed microservices with independent failure isolation
- Gateway routing to separate backend microservice processes
- AI chatbot described as mock/pending only
- CIMM maintenance described as fully planned (not partially live)
- Online payments described as entirely out of scope
- Seamless live integration with infrastructure and utilities modules

## New implementation details added

- Modular monolithic architecture with logical module table
- Integration maturity levels (implemented / preview / not implemented)
- Session timeout 5 minutes
- Optional PayMongo and environment-gated features
- Verified booking, auto-approval, and role rules from code

## Assumptions avoided

- No unverified workload reduction percentages
- No native mobile app claims
- No live utilities/infrastructure API claims
- No separate equipment booking workflow

## Still requires your confirmation

1. Final official title wording on title page
2. Whether PayMongo should be narrated as disabled for capstone policy
3. Whether CIMM API is active in your deployed environment
4. Sprint dates, story points, and figure numbers in your artifacts
5. ~~Whether Chapters 4–5 will be separate files~~ → see `docs/THESIS_CHAPTER_REPLACEMENT_PACK_CH4_CH5.md`

**Continues in:** `docs/THESIS_CHAPTER_REPLACEMENT_PACK_CH4_CH5.md` (Chapters 4–5: Requirements Analysis and Business Process Architecture)

---

*Generated from codebase verification. Update this pack if major features ship or are retired.*
