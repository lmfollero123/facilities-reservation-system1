# System Diagrams

**Barangay Culiat Public Facilities Reservation System**

This document contains visual diagrams for the system architecture, data flow, use cases, CI/CD pipeline, and infrastructure.

---

## Table of Contents

1. [Data Flow Diagram (DFD)](#1-data-flow-diagram-dfd)
2. [Use Case Diagrams (Modular)](#2-use-case-diagrams-modular)
   - [2.1 Resident Module](#21-resident-module)
   - [2.2 Staff Operations Module](#22-staff-operations-module)
   - [2.3 Admin Management Module](#23-admin-management-module)
3. [CI/CD Pipeline](#3-cicd-pipeline)
4. [Infrastructure Diagram](#4-infrastructure-diagram)

---

## 1. Data Flow Diagram (DFD)

### Context Diagram (Level 0)

```mermaid
graph TB
    Resident[👤 Resident]
    AdminStaff[👨‍💼 Admin/Staff]
    System[🏢 Facilities Reservation System]
    
    Resident -->|Registration Data<br/>Login Credentials<br/>Booking Request| System
    System -->|Confirmation Messages<br/>Session Data<br/>Reservation Status| Resident
    
    AdminStaff -->|Approval Decisions<br/>Management Commands<br/>Reports & Queries| System
    System -->|Approval Notifications<br/>Facility Data<br/>Audit Logs & Reports| AdminStaff
```

### Level 1 DFD - System Overview

```mermaid
graph TB
    subgraph External["External Entities"]
        Resident[👤 Resident]
        AdminStaff[👨‍💼 Admin/Staff]
    end
    
    subgraph Processes["Processes"]
        P1[1.0 User Registration]
        P2[2.0 Authenticate User]
        P3[3.0 Book Facility]
        P4[4.0 Approve Reservations]
        P5[5.0 Manage Facilities]
        P6[6.0 Manage Users]
        P7[7.0 Auto-Decline Expired]
        P8[8.0 Generate Notifications]
        P9[9.0 AI Conflict Detection]
        P10[10.0 AI Chatbot]
        P11[11.0 Record Violations]
    end
    
    subgraph DataStores["Data Stores"]
        D1[(D1: Users)]
        D2[(D2: Sessions)]
        D3[(D3: Reservations)]
        D4[(D4: Facilities)]
        D5[(D5: Notifications)]
        D6[(D6: User Documents)]
        D7[(D7: Audit Log)]
        D8[(D8: User Violations)]
        D9[(D9: Blackout Dates)]
        D10[(D10: Reservation History)]
    end
    
    Resident -->|Registration Data| P1
    P1 -->|User Record| D1
    P1 -->|Document Records| D6
    
    Resident -->|Login Credentials| P2
    P2 -->|Session Data| D2
    P2 -.->|Query| D1
    
    Resident -->|Booking Request| P3
    P3 -->|Conflict Check| P9
    P9 -.->|Query| D3
    P9 -.->|Query| D4
    P3 -->|Reservation Record| D3
    P3 -->|History Entry| D10
    P3 -->|Audit Record| D7
    
    AdminStaff -->|Approval Decision| P4
    P4 -.->|Query| D3
    P4 -->|Status Update| D3
    P4 -->|History Entry| D10
    P4 -->|Audit Record| D7
    
    AdminStaff -->|Facility Data| P5
    P5 -->|Facility Record| D4
    P5 -->|Audit Record| D7
    
    AdminStaff -->|User Status Update| P6
    P6 -.->|Query| D1
    P6 -.->|Query| D6
    P6 -->|Status Update| D1
    P6 -->|Audit Record| D7
    
    P7 -.->|Query| D3
    P7 -->|Status Update| D3
    P7 -->|History Entry| D10
    
    P3 -->|Notification Request| P8
    P4 -->|Notification Request| P8
    P8 -->|Notification| D5
    D5 -->|Notification| Resident
    D5 -->|Notification| AdminStaff
    
    Resident -->|User Query| P10
    P10 -.->|Query| D3
    P10 -.->|Query| D4
    P10 -->|AI Response| Resident
    
    AdminStaff -->|Violation Data| P11
    P11 -->|Violation Record| D8
    P11 -->|Audit Record| D7
```

---

## 2. Use Case Diagrams (Modular)

### Actor Responsibility Boundaries

| Actor | Responsibility | Primary Scope |
|-------|----------------|---------------|
| **Resident** | End users who register and book barangay facilities. Must be approved before login. Cannot access other users' data or administrative functions. | Self-service: booking, profile, own reservations, notifications, reports |
| **Staff** | LGU/Barangay staff for day-to-day operations. **Inherits Resident capabilities** plus operational privileges. Cannot manage user accounts or view full audit trail. | Operations: facilities, reservations, approvals, violations, inquiries, communications |
| **Admin** | System administrators. **Inherits Staff + Resident capabilities** plus full administrative/security privileges. | Administration: user management, audit trail, security, full system oversight |

---

### 2.1 Resident Module

| Use Case | Description |
|----------|-------------|
| Register Account | Create account with address, documents; pending until approved |
| Login with OTP | Authenticate with email/password + OTP verification |
| Reset Password | Request reset link via email; set new password with token |
| Manage Profile | View/update profile (address, photo, contact info) |
| Browse Facilities | View public facility catalog with details and availability |
| Book Facility | Submit reservation with date, time, purpose; includes conflict check |
| Reschedule | Change date/time of own reservation (within limits) |
| Cancel Reservation | Cancel own reservation |
| View Notifications | View approval/denial/reminder notifications |
| Chat with AI Assistant | Get facility recommendations and booking help via chatbot |
| Export Reports | Export own reservation history (CSV/PDF) |

```mermaid
%% Resident Use Case Diagram (UML-style)
graph LR
    %% Actors (Resident as base, Staff/Admin inherit)
    Resident((Resident))
    Staff((Staff))
    Admin((Admin))
    AI((AI Service))

    %% Simplified inheritance-style links to avoid spaghetti
    %% Interpretation: Staff can do everything Resident can; Admin can do everything Staff can
    Staff -.-> Resident
    Admin -.-> Staff

    %% System boundary as module box
    subgraph SystemBoundary["Facilities Reservation System – Resident Module"]
        UC1([Register Account])
        UC2([Login with OTP])
        UC3([Reset Password])
        UC4([Manage Profile])
        UC5([Browse Facilities])
        UC6([Book Facility])
        UC7([Reschedule Reservation])
        UC8([Cancel Reservation])
        UC9([View Notifications])
        UC10([Chat with AI Assistant])
        UC11([Export Reports])
    end

    %% Primary actor links
    %% Only draw explicit lines from Resident to keep diagram readable
    Resident --> UC1
    Resident --> UC2
    Resident --> UC3
    Resident --> UC4
    Resident --> UC5
    Resident --> UC6
    Resident --> UC7
    Resident --> UC8
    Resident --> UC9
    Resident --> UC10
    Resident --> UC11

    %% Secondary actor link (optional, only where needed)
    AI -.-> UC10

    %% Use case relationships (simplified UML)
    UC2 -. «include» .- UC2a([Verify OTP])
    UC3 -. «include» .- UC3a([Request Reset Link])
    UC3 -. «extend» .- UC3b([Set New Password])
    UC6 -. «include» .- UC6a([Check Availability])
    UC6 -. «include» .- UC6b([Conflict Detection])

    %% Styling to look closer to UML
    classDef actor fill:#ffffff,stroke:#333,stroke-width:1px;
    classDef usecase fill:#fff9c4,stroke:#666,stroke-width:1px,rx:30,ry:18;

    class Resident,Staff,Admin,AI actor;
    class UC1,UC2,UC3,UC4,UC5,UC6,UC7,UC8,UC9,UC10,UC11,UC2a,UC3a,UC3b,UC6a,UC6b usecase;
```

---

### 2.2 Staff Operations Module

| Use Case | Description |
|----------|-------------|
| Manage Facilities | Add, edit, deactivate facilities; set availability and rules |
| Review Reservations | View pending approvals; approve or deny |
| Modify Reservations | Modify/postpone/cancel approved reservations with reason |
| Record Violations | Record no-show, late cancellation, damage with severity |
| View Inquiries | View contact form submissions; respond via email |
| Export Reports | Export facility usage, reservations, operational reports |
| Manage Profile | View/update own profile (same as Resident) |

```mermaid
%% Staff Operations Use Case Diagram (UML-style)
graph LR
    %% Actors (Staff as base, Admin inherits)
    Staff((Staff))
    Admin((Admin))

    %% Simplified inheritance-style link
    %% Interpretation: Admin can do everything Staff can
    Admin -.-> Staff

    %% System boundary as module box
    subgraph StaffBoundary["Facilities Reservation System – Staff Operations Module"]
        UC1([Manage Facilities])
        UC2([Review Reservations])
        UC3([Modify Reservations])
        UC4([Record Violations])
        UC5([View Inquiries])
        UC6([Export Reports])
        UC7([Manage Profile])
    end

    %% Primary actor links
    %% Only draw explicit lines from Staff to keep diagram readable
    Staff --> UC1
    Staff --> UC2
    Staff --> UC3
    Staff --> UC4
    Staff --> UC5
    Staff --> UC6
    Staff --> UC7

    %% Use case relationships
    UC2 -. «include» .- UC2a([Approve or Deny])
    UC3 -. «include» .- UC3a([Add Modification Reason])
    UC4 -. «include» .- UC4a([Set Severity Level])

    %% Styling
    classDef actor fill:#ffffff,stroke:#333,stroke-width:1px;
    classDef usecase fill:#fff9c4,stroke:#666,stroke-width:1px,rx:30,ry:18;

    class Staff,Admin actor;
    class UC1,UC2,UC3,UC4,UC5,UC6,UC7,UC2a,UC3a,UC4a usecase;
```

---

### 2.3 Admin Management Module

| Use Case | Description |
|----------|-------------|
| Approve/Deny Users | Review pending registrations; approve or deny with reason |
| Lock/Unlock Accounts | Lock user accounts; provide lock reason; unlock when resolved |
| Full Reservation Oversight | View all reservations; approve/deny/modify; override auto-approval |
| View Audit Trail | View system audit trail; filter by user, action, date |
| Violation Oversight | View all violations; manage severity; impact on auto-approval |
| System Reports | Export system-wide reports; compliance and analytics |
| Manage Profile | View/update own profile (same as Resident) |

```mermaid
%% Admin Management Use Case Diagram (UML-style)
graph LR
    %% Actor (primary)
    Admin((Admin))

    %% System boundary as module box
    subgraph AdminBoundary["Facilities Reservation System – Admin Management Module"]
        UC1([Approve/Deny Users])
        UC2([Lock/Unlock Accounts])
        UC3([Full Reservation Oversight])
        UC4([View Audit Trail])
        UC5([Violation Oversight])
        UC6([System Reports])
        UC7([Manage Profile])
    end

    %% Primary actor links (Admin-only capabilities, no labels for cleanliness)
    Admin --> UC1
    Admin --> UC2
    Admin --> UC3
    Admin --> UC4
    Admin --> UC5
    Admin --> UC6
    Admin --> UC7

    %% Use case relationships
    UC1 -. «include» .- UC1a([View User Documents])
    UC2 -. «include» .- UC2a([Provide Lock Reason])
    UC3 -. «include» .- UC3a([Approve/Deny/Modify])
    UC3 -. «include» .- UC3b([Override Auto-Approval])

    %% Styling
    classDef actor fill:#ffffff,stroke:#333,stroke-width:1px;
    classDef usecase fill:#fff9c4,stroke:#666,stroke-width:1px,rx:30,ry:18;

    class Admin actor;
    class UC1,UC2,UC3,UC4,UC5,UC6,UC7,UC1a,UC2a,UC3a,UC3b usecase;
```

---

### 2.4 Use Case Relationships Summary

| Relationship | Example |
|--------------|---------|
| **«include»** | Login always includes Verify OTP; Book Facility always includes Check Availability and Conflict Detection |
| **«extend»** | Reset Password extends Login (optional path when user forgot password) |
| **Secondary actor** | AI Service supports Chat with AI Assistant; Email supports Reset Password and notifications |

---

## 3. CI/CD Pipeline

### Current Deployment Flow (cPanel)

```mermaid
graph LR
    subgraph Local["Local Development"]
        Dev[Developer Workspace]
        GitLocal[Git Repository<br/>Local]
    end
    
    subgraph Remote["Remote Repository"]
        GitHub[GitHub Repository<br/>main branch]
    end
    
    subgraph Server["Production Server"]
        cPanel[cPanel Hosting<br/>IndevFinite]
        App[Application<br/>public_html]
        DB[(MySQL Database)]
    end
    
    Dev -->|git add<br/>git commit| GitLocal
    GitLocal -->|git push origin main| GitHub
    
    GitHub -->|git pull origin main| cPanel
    cPanel -->|Deploy Files| App
    App -.->|Connects| DB
    
    style Dev fill:#e1f5ff
    style GitHub fill:#f0f0f0
    style cPanel fill:#fff4e6
    style App fill:#e8f5e9
    style DB fill:#fce4ec
```

### Detailed CI/CD Pipeline Steps

```mermaid
graph TD
    Start([Developer Makes Changes]) --> Commit[Commit to Git]
    Commit --> Push[Push to GitHub]
    Push --> GitHubRepo[GitHub Repository]
    
    GitHubRepo --> SSH[SSH into cPanel Server]
    SSH --> Backup[Backup Config Files<br/>database.php<br/>gemini_config.php]
    Backup --> Pull[git pull origin main]
    
    Pull --> Restore{Restore Configs?}
    Restore -->|Yes| RestoreFiles[Restore gitignored files<br/>from backup]
    Restore -->|No| CheckFiles[Check if files exist]
    RestoreFiles --> CheckFiles
    
    CheckFiles --> CreateMissing{Missing Files?}
    CreateMissing -->|Yes| CreateFiles[Create missing configs<br/>gemini_config.php from example]
    CreateMissing -->|No| Verify[Verify File Permissions]
    CreateFiles --> Verify
    
    Verify --> Migrations{Run Migrations?}
    Migrations -->|Yes| RunMigrations[Run SQL Migrations<br/>via phpMyAdmin]
    Migrations -->|No| Test[Test Application]
    RunMigrations --> Test
    
    Test --> HealthCheck[Health Check<br/>Homepage loads<br/>Login works<br/>Dashboard accessible]
    HealthCheck --> Success{All Tests Pass?}
    Success -->|Yes| DeploySuccess([Deployment Successful])
    Success -->|No| Rollback[Rollback Changes]
    Rollback --> DeployFailed([Deployment Failed])
    
    style Start fill:#e1f5ff
    style DeploySuccess fill:#c8e6c9
    style DeployFailed fill:#ffcdd2
```

---

## 4. Infrastructure Diagram

### Current Infrastructure (cPanel Hosting)

```mermaid
graph TB
    subgraph Internet["Internet"]
        Users[👥 Users<br/>Residents, Admin, Staff]
    end
    
    subgraph Hosting["IndevFinite cPanel Hosting"]
        subgraph WebServer["Web Server Layer"]
            Apache[Apache Web Server<br/>PHP 8.x<br/>mod_rewrite enabled]
            SSL[SSL Certificate<br/>HTTPS]
        end
        
        subgraph Application["Application Layer"]
            AppRoot[Application Root<br/>public_html/facilities_reservation_system]
            PHPFiles[PHP Files<br/>index.php<br/>config/<br/>resources/views/]
            StaticAssets[Static Assets<br/>public/css/<br/>public/js/<br/>public/img/]
            Uploads[Uploads Directory<br/>public/uploads/<br/>gitignored]
        end
        
        subgraph Database["Database Layer"]
            MySQL[(MySQL Database<br/>facilities_reservation)]
            Tables[Tables:<br/>users<br/>reservations<br/>facilities<br/>notifications<br/>audit_log<br/>user_violations<br/>rate_limits<br/>security_logs]
        end
        
        subgraph Storage["File Storage"]
            ConfigFiles[Config Files<br/>config/database.php<br/>config/gemini_config.php<br/>gitignored]
            LogFiles[Log Files<br/>logs/<br/>gitignored]
            UserUploads[User Uploads<br/>public/uploads/<br/>public/img/facilities/<br/>public/img/announcements/]
        end
        
        subgraph ExternalServices["External Services"]
            SMTP[SMTP Server<br/>Gmail/Brevo<br/>Email Delivery]
            GeminiAPI[Gemini API<br/>Google AI Studio<br/>Chatbot]
        end
    end
    
    Users -->|HTTPS| SSL
    SSL --> Apache
    Apache --> AppRoot
    AppRoot --> PHPFiles
    AppRoot --> StaticAssets
    AppRoot --> Uploads
    
    PHPFiles -.->|PDO Connection| MySQL
    MySQL --> Tables
    
    PHPFiles -.->|Read/Write| ConfigFiles
    PHPFiles -.->|Write Logs| LogFiles
    PHPFiles -.->|Store Uploads| UserUploads
    
    PHPFiles -.->|Send Emails| SMTP
    PHPFiles -.->|API Calls| GeminiAPI
    
    style Users fill:#e3f2fd
    style Apache fill:#fff3e0
    style MySQL fill:#f3e5f5
    style SMTP fill:#e8f5e9
    style GeminiAPI fill:#e1f5ff
```

### Infrastructure Components

```mermaid
graph LR
    subgraph Frontend["Frontend"]
        HTML[HTML Pages]
        CSS[CSS Stylesheets<br/>Tailwind + Custom]
        JS[JavaScript<br/>Vanilla JS]
    end
    
    subgraph Backend["Backend"]
        PHP[PHP 8.x<br/>Server-Side Logic]
        Routing[URL Routing<br/>index.php]
        Sessions[Session Management]
        Security[Security Layer<br/>CSRF, Rate Limiting]
    end
    
    subgraph DataLayer["Data Layer"]
        MySQL[(MySQL Database)]
        PDO[PDO Connection<br/>Prepared Statements]
        Migrations[Database Migrations<br/>SQL Files]
    end
    
    subgraph Services["External Services"]
        Email[Email Service<br/>SMTP]
        AI[AI Service<br/>Gemini API]
    end
    
    HTML --> PHP
    CSS --> HTML
    JS --> HTML
    JS -.->|AJAX| PHP
    
    PHP --> Routing
    PHP --> Sessions
    PHP --> Security
    PHP --> PDO
    PDO --> MySQL
    Migrations --> MySQL
    
    PHP -.->|Send Emails| Email
    PHP -.->|API Calls| AI
    
    style Frontend fill:#e3f2fd
    style Backend fill:#fff3e0
    style DataLayer fill:#f3e5f5
    style Services fill:#e8f5e9
```

### Deployment Infrastructure

```mermaid
graph TB
    subgraph Dev["Development Environment"]
        LocalDev[Local Machine<br/>XAMPP/WAMP<br/>PHP 8.x<br/>MySQL]
        GitLocal[Git Local Repo]
    end
    
    subgraph VersionControl["Version Control"]
        GitHub[GitHub Repository<br/>main branch]
    end
    
    subgraph Production["Production Environment"]
        cPanel[cPanel Hosting<br/>IndevFinite]
        WebServer[Apache + PHP 8.x]
        MySQLProd[(MySQL Database<br/>Production)]
        FileSystem[File System<br/>public_html/]
    end
    
    subgraph External["External Services"]
        SMTPProd[SMTP Server<br/>Production]
        GeminiProd[Gemini API<br/>Production Key]
    end
    
    LocalDev -->|git push| GitLocal
    GitLocal -->|git push origin main| GitHub
    
    GitHub -->|git pull| cPanel
    cPanel -->|Deploy| WebServer
    WebServer -->|Read/Write| FileSystem
    WebServer -.->|Connect| MySQLProd
    WebServer -.->|Send Emails| SMTPProd
    WebServer -.->|API Calls| GeminiProd
    
    style LocalDev fill:#e1f5ff
    style GitHub fill:#f0f0f0
    style cPanel fill:#fff4e6
    style WebServer fill:#e8f5e9
    style MySQLProd fill:#f3e5f5
```

---

## Diagram Notes

### DFD Notes
- **Processes** are numbered (1.0, 2.0, etc.) and represent major system functions
- **Data Stores** (D1-D10) represent database tables and persistent storage
- **Data Flows** show movement of data between processes, stores, and external entities
- **External Entities** are users and systems outside the application boundary

### Use Case Notes
- **Resident**: Self-service only—booking, profile, own reservations, notifications, reports. AI Assistant is a secondary actor.
- **Staff**: Operations scope—facilities, reservations, approvals, violations, inquiries, reports. No user management or audit trail.
- **Admin**: Full oversight—all Staff capabilities plus user approval/denial, lock/unlock, audit trail, violation oversight, system reports.
- **No System actor**: OTP, notifications, auto-approval, conflict detection are internal behaviors, not actors.

### CI/CD Pipeline Notes
- **Current Setup**: Manual Git-based deployment via cPanel SSH
- **No Automated CI/CD**: Deployment is manual (git pull on server)
- **Config Management**: Git-ignored files (database.php, gemini_config.php) must be manually managed
- **Migrations**: Run manually via phpMyAdmin or command line
- **Rollback**: Manual (git reset or restore from backup)

### Infrastructure Notes
- **Hosting**: IndevFinite cPanel shared hosting
- **Web Server**: Apache with PHP 8.x
- **Database**: MySQL (managed via cPanel phpMyAdmin)
- **File Storage**: Standard file system (public_html directory)
- **External APIs**: Gemini AI (Google AI Studio), SMTP (Gmail/Brevo)
- **No Containerization**: Traditional LAMP stack deployment
- **No Load Balancing**: Single server deployment
- **No CDN**: Static assets served directly from server

---

*Document version: 1.0 | Last updated: January 2025*
