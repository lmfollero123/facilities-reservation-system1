# Thesis Revised Sections (Code-Verified)

> **See also:** `docs/THESIS_CHAPTER_REPLACEMENT_PACK.md` — the full chapter-by-chapter copy-paste pack with school-format numbering, replacement map, Word formatting checklist, and change log.

This file contains a short summary of replacement thesis sections aligned with the current implementation of the Barangay Culiat Facilities Reservation System. These sections are based on source code, schema, and current documentation.

## Chapter 1 - Context and Scope (Revised)

The Barangay Culiat Facilities Reservation System was developed to address delays and inconsistencies in manual facility booking processes. The implementation is a web-based PHP and MySQL application that allows residents, staff, and administrators to manage facility reservations through a centralized platform.

The implemented scope focuses on public facility reservation workflows, including account registration and verification, reservation request submission, conflict checking, approval processing, notifications, and reporting. The system supports role-based access for Resident, Staff, and Admin users and provides operational controls such as booking limits, blackout dates, and violation tracking.

The system includes AI-assisted capabilities that are currently implemented in production code. These include reservation conflict analysis, facility recommendation scoring, holiday/event risk tagging, and an AI chatbot with Gemini integration and fallback responses.

The project also includes external integration components with different levels of completion. CIMM maintenance synchronization is implemented as an outbound integration that updates facility status and blackout dates when API credentials are configured. Infrastructure and utilities integration pages are present as preview modules with sample data and are not yet connected to live external APIs.

Online payments are implemented as an optional module through PayMongo and can be enabled through environment configuration. For capstone use, this module may remain disabled depending on deployment settings and policy decisions. The project does not include a native mobile application; access is provided through a responsive web interface.

## Chapter 2 - System Architecture and Technology Basis (Revised)

### 2.X Architectural Model

The implemented system follows a modular monolithic architecture. It uses a single PHP front controller (`index.php`) with domain modules organized across authentication, reservations, facilities, notifications, AI assistance, and reporting. These domains are logically separated for maintainability but are deployed as one web application sharing a single relational database.

This architecture supports practical maintainability for local government deployment while preserving clear service boundaries in design documentation. However, runtime deployment does not use independent microservice containers, a separate API gateway, or distributed service orchestration.

### 2.X Integration Status

The AI chatbot is implemented in the dashboard and uses a server-side endpoint that connects to Gemini when configured. If the external model is unavailable, the system falls back to internal response logic to preserve usability.

Maintenance integration with CIMM is partially implemented and operational for outbound synchronization. Scheduled scripts pull maintenance schedules and apply updates to facility availability and blackout dates. Inbound integration routes for generic external webhook posting are currently placeholders and return not implemented responses.

Infrastructure projects and utilities integration dashboards are currently presentation modules. They provide administrative visibility of sample records but do not yet perform live API synchronization.

### 2.X Technology Stack

The verified stack is PHP 8.1 or higher, MySQL 8.0 or compatible MariaDB, and Composer-managed dependencies. Optional Python services support machine learning endpoints used for risk and classification workflows. The user interface is delivered through web views and standard frontend assets without a Node.js backend requirement.

## Chapter 3 - Methodology and Implementation Alignment Notes (Revised Insertions)

### 3.X Methodology-to-Implementation Alignment

The development methodology narrative may retain Agile Scrum terminology where it documents team process, sprint planning, backlog management, and iterative delivery. However, technical descriptions in this chapter must use implementation-accurate wording for architecture and integration status.

When discussing architecture in Chapter 3, the system should be described as a modular monolith with logical service partitions. Statements implying independently deployed microservices, service-level fault isolation across separate runtimes, or a dedicated gateway layer should be avoided unless such deployment is verifiable in the current system.

When discussing integrations, the chapter should distinguish implemented modules from preview modules. CIMM outbound synchronization is implemented and environment-dependent. Infrastructure and utilities dashboards are currently mock-data previews. Chatbot capability is implemented and not limited to frontend mock interfaces.

When discussing feature coverage, references to equipment reservation and equipment inventory automation should be removed because current implementation is centered on facilities reservation workflows.

