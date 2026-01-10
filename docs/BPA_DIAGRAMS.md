# Business Process Architecture (BPA) Diagrams
## LGU Facilities Reservation System

---

## LEVEL 1 BPA - Enterprise Business Domains

```mermaid
flowchart LR
    CM[Citizen Management]
    FIM[Facilities & Infrastructure<br/>Management]
    RS[Reservation &<br/>Scheduling]
    CE[Compliance &<br/>Enforcement]
    RAA[Records, Analytics<br/>& Archival]

    %% Citizen to Reservation
    CM -- "Citizen Identity Data<br/>(User ID, Status, Verification Status)" --> RS
    RS -- "Reservation History<br/>(Booking Counts, Status Changes)" --> CM

    %% Facilities to Reservation
    FIM -- "Facility Master Data<br/>(Availability, Capacity, Rules, Status)" --> RS
    RS -- "Facility Usage Metrics<br/>(Booking Frequency, Utilization Rates)" --> FIM

    %% Reservation to Compliance
    RS -- "Reservation Events<br/>(Booking Requests, Status Changes)" --> CE
    CE -- "Violation Records<br/>(Type, Severity, User Impact)" --> RS

    %% Reservation to Records
    RS -- "Reservation Data<br/>(All Bookings, Status History, Events)" --> RAA
    CE -- "Violation Records<br/>(All Infractions, Enforcement Actions)" --> RAA
    CM -- "Citizen Records<br/>(User Profiles, Documents)" --> RAA
    FIM -- "Facility Records<br/>(Master Data, Status Changes)" --> RAA

    %% Records to other domains
    RAA -- "Usage Statistics<br/>(Reports, Trends, Analytics)" --> FIM
    RAA -- "Historical Data<br/>(Archived Records, Audit Trails)" --> CM

    style CM fill:#e1f5ff
    style FIM fill:#fff4e1
    style RS fill:#0000FF,color:#ffffff
    style CE fill:#fce4ec
    style RAA fill:#f3e5f5
```

---

## LEVEL 2 BPA - All System Modules

```mermaid
flowchart LR
    %% Internal Modules
    UM[User Management]
    DM[Document Management]
    AUTH[Authentication &<br/>Security]
    FM[Facilities Management]
    FR[Facilities Reservation]
    AI[AI Scheduling &<br/>Recommendations]
    CAL[Calendar &<br/>Visualization]
    VM[Violations Module]
    NT[Notification Service]
    RP[Reporting &<br/>Analytics]
    AR[Archival & Background<br/>Jobs]
    CI[Contact Inquiry<br/>Management]
    AUD[Audit Trail]
    AA[Auto-Approval<br/>Module]
    DE[Data Export]
    
    %% External Integration Modules (will use dotted lines)
    MM[Maintenance Management External System]
    IM[Infrastructure Management External System]
    UB[Utilities Billing External System]

    %% User & Identity Flow - Bidirectional
    UM <-->|"← Authentication Status: Session Token, Login Status, OTP Result<br/>→ User Profile Data: User ID, Status, Role, Verification Flag"| AUTH

    UM <-->|"← Reservation History Summary: Active Count, Status Distribution<br/>→ User Identity Reference: User ID, Verification Status"| FR

    %% Documents Flow - Bidirectional
    DM <-->|"← Document Access Request: User ID, Document Reference<br/>→ Document Verification Status: Approved/Rejected, Document Type"| UM

    DM -->|"Document Metadata: File Path, Upload Date, Type"| FR

    %% Facilities Flow - Bidirectional
    FM <-->|"← Facility Usage Metrics: Booking Counts, Utilization Rates, Peak Times<br/>→ Facility Master Data: ID, Name, Capacity, Rules, Auto-Approve Flag, Status"| FR

    FM -->|"Facility Data: ID, Name, Capacity, Location"| AI
    FR -->|"Reservation Data: Bookings, Conflicts, Time Slots"| AI
    AI -->|"Facility Recommendations: Ranked Facilities, Distance Scores<br/>Conflict Warnings: Holiday Risks, Event Conflicts"| FR

    %% Calendar Flow - Bidirectional
    FR <-->|"← Availability Calendar Data: Available Dates, Booked Slots<br/>→ Reservation Events: Date, Time, Facility, Status"| CAL

    %% Auto-Approval Flow - Bidirectional
    FR <-->|"← Auto-Approval Decision: Eligible, Conditions Passed, Reason<br/>→ Reservation Request Data: Facility ID, Date, Time, User ID, Commercial Flag"| AA
    FM -->|"Facility Auto-Approve Settings: Auto-Approve Flag, Capacity Threshold, Max Duration"| AA
    VM -->|"User Violation Summary: Violation Count, Severity"| AA

    %% Maintenance Flow (External - Dotted, Bidirectional)
    MM <-.->|"← Reservation Conflicts / Facility Usage Metrics: Facility ID, Requested Dates, Existing Bookings, Booking Counts, Utilization, Peak Times<br/>→ Maintenance Schedules: Facility ID, Status Change, Start/End Dates"| FR

    FM <-.->|"← Facility Status Changes: Status, Maintenance Flags<br/>→ Maintenance Status Updates: Facility Availability, Downtime Periods"| MM

    %% Infrastructure Management Flow (External - Dotted, One-way)
    IM -.->|"Facility Closure Notifications: Facility ID, Project ID, Closure Dates"| FR
    IM -.->|"New Facility Data / Project Phase Updates: Name, Type, Location, Capacity, Amenities, Project Status, Impact Type"| FM

    %% Utilities Billing Flow (External - Dotted, Bidirectional)
    UB <-.->|"← Facility Usage Reference: Usage Hours, Utility Consumption Context<br/>→ Utility Outage Alerts: Outage Type, Facility IDs, Start/End Times"| FR

    %% Violations Flow - Bidirectional
    VM <-->|"← Violation Triggers: Reservation ID, Event Type No-show Damage Policy Breach<br/>→ User Violation Summary: Severity Level, Count, Last Violation Date"| FR

    VM <-->|"← User Status Updates: Account Status, Verification Status<br/>→ Violation Records: User ID, Type, Severity, Description"| UM

    %% Notifications Flow - Bidirectional
    FR <-->|"← Notification Delivery Status: Sent, Delivered, Read Status<br/>→ Booking Status Events: Approved, Denied, Pending, Postponed, Cancelled"| NT
    UM -->|"Account Status Events: Approved, Locked, Verification Status"| NT
    MM -.->|"Maintenance Alerts: Facility Status Change, Availability Updates"| NT
    VM -->|"Violation Notifications: Violation Recorded, Impact Warning"| NT
    CI -->|"Inquiry Submission Events: New Inquiry Received"| NT

    %% Contact Inquiry Flow
    CI -->|"Inquiry Data: Name, Email, Message, Status"| NT
    UM -->|"Admin/Staff User Data: Email, Name"| CI

    %% Reporting Flow - Bidirectional
    FR <-->|"← Usage Reports: Monthly Trends, Approval Rates, Facility Performance<br/>→ Reservation Data: All Bookings, Status Changes, Usage Patterns"| RP
    MM -.->|"Maintenance Logs: Maintenance Records, Downtime Statistics"| RP
    VM -->|"Violation Records: All Infractions, Severity Distribution"| RP
    UM -->|"User Statistics: Registration Data, Verification Rates"| RP
    FM -->|"Facility Statistics: Capacity Utilization, Popular Facilities"| RP
    CI -->|"Inquiry Statistics: Inquiry Counts, Response Times"| RP
    AUD -->|"Audit Log Data: All System Events, User Actions"| RP

    %% Archival Flow - Bidirectional
    AR <-->|"← Archive Status: Archival Completion, Retention Policies<br/>→ Expired Documents: Documents Past Retention Period"| DM
    AR -->|"Archived Record References: Archive Location, Retention Status"| RP
    FR -->|"Expired Reservations: Old Reservations, Completed Events"| AR
    UM -->|"Inactive User Records: Locked/Deleted Accounts"| AR
    AUD -->|"Old Audit Logs: Logs Past Retention Period"| AR

    %% Audit Trail Flow (One-way to Audit)
    FR -->|"Reservation Events: Status Changes, Modifications"| AUD
    UM -->|"User Management Events: Account Changes, Status Updates"| AUD
    FM -->|"Facility Management Events: CRUD Operations, Status Changes"| AUD
    VM -->|"Violation Recording Events: Violation Created, Updated"| AUD
    AUTH -->|"Authentication Events: Login Attempts, OTP Verification"| AUD

    %% Data Export Flow - Bidirectional
    UM <-->|"← Export File: JSON File Path, HTML Report<br/>→ User Data Request: User ID, Export Type"| DE
    FR -->|"Reservation Data Request: User ID, Reservation Filter"| DE
    DM -->|"Document Data Request: User ID, Document References"| DE

    style UM fill:#e1f5ff
    style DM fill:#e1f5ff
    style AUTH fill:#fff4e1
    style FM fill:#fff4e1
    style FR fill:#0000FF,color:#ffffff
    style AI fill:#0000FF,color:#ffffff
    style CAL fill:#0000FF,color:#ffffff
    style AA fill:#0000FF,color:#ffffff
    style MM fill:#FF8000,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
    style IM fill:#530CFF,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
    style UB fill:#FFFF33,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#000000
    style VM fill:#fce4ec
    style NT fill:#f3e5f5
    style RP fill:#f3e5f5
    style AR fill:#f3e5f5
    style CI fill:#f3e5f5
    style AUD fill:#fff4e1
    style DE fill:#f3e5f5
```

### Level 2 BPA Legend

#### Internal System Modules (Solid Borders)

**🔵 User & Identity Management (Light Blue - #e1f5ff)**
- User Management
- Document Management

**🟡 Facilities & Infrastructure (Light Orange - #fff4e1)**
- Facilities Management
- Authentication & Security
- Audit Trail

**🔵 Facilities Reservation Modules (Blue - #0000FF)**
- Facilities Reservation
- AI Scheduling & Recommendations
- Calendar & Visualization
- Auto-Approval Module

**🔴 Compliance & Enforcement (Light Pink - #fce4ec)**
- Violations Module

**🟣 Services & Analytics (Light Purple - #f3e5f5)**
- Notification Service
- Reporting & Analytics
- Archival & Background Jobs
- Contact Inquiry Management
- Data Export

#### External Integration Systems (Dotted Borders)

**🟠 Maintenance Management System (Orange - #FF8000)**
- External system managing facility maintenance schedules
- Provides maintenance status updates and schedules
- Receives facility usage metrics from reservation system

**🟣 Infrastructure Management System (Purple - #530CFF)**
- External system managing construction/renovation projects
- Provides facility closure notifications and new facility data
- Sends project phase updates

**🟨 Utilities Billing System (Yellow - #FFFF33)**
- External system managing utility services (power, water)
- Provides utility outage alerts
- Receives facility usage reference data

#### Connection Types

- **Solid Lines** (`-->` or `<-->`): Internal system connections within Facilities Reservation System
- **Dotted Lines** (`-.->` or `<-.->`): External system integrations (connections to other LGU systems)
- **Bidirectional Arrows** (`<-->`): Two-way data exchange between modules
- **One-way Arrows** (`-->`): One-way data flow from source to destination

---

## LEVEL 3 BPA - Individual Module Diagrams

### 3.1 Facilities Reservation Module

```mermaid
flowchart LR
    FR[Facilities Reservation<br/>Module]
    
    UM[User Management]
    DM[Document Management]
    FM[Facilities Management]
    MM[Maintenance Management<br/>External System]
    IM[Infrastructure Management<br/>External System]
    UB[Utilities Billing<br/>External System]
    VM[Violations Module]
    AA[Auto-Approval Module]
    AI[AI Scheduling &<br/>Recommendations]
    CAL[Calendar &<br/>Visualization]
    NT[Notification Service]
    RP[Reporting &<br/>Analytics]
    AR[Archival & Background<br/>Jobs]
    AUD[Audit Trail]

    %% User Management
    UM -->|"User Identity Reference<br/>(User ID, Verification Status)"| FR
    FR -->|"Reservation History Summary<br/>(Active Count, Status Distribution)"| UM

    %% Document Management
    DM -->|"Document Metadata<br/>(File Path, Upload Date, Type)"| FR

    %% Facilities Management
    FM -->|"Facility Master Data<br/>(ID, Name, Capacity, Rules, Auto-Approve Flag, Status)"| FR
    FR -->|"Facility Usage Metrics<br/>(Booking Counts, Utilization Rates, Peak Times)"| FM

    %% Maintenance Management (External - Dotted)
    MM -.->|"Maintenance Schedules<br/>(Facility ID, Status Change, Start/End Dates)"| FR
    FR -.->|"Reservation Conflicts<br/>(Facility ID, Requested Dates, Existing Bookings)"| FR
    FR -.->|"Facility Usage Metrics<br/>(Booking Counts, Utilization, Peak Times)"| MM
    FR -.->|"Reservation Status Updates<br/>(Status: Cancelled/Postponed, Priority Flags)"| MM

    %% Infrastructure Management (External - Dotted)
    IM -.->|"Facility Closure Notifications<br/>(Facility ID, Project ID, Closure Dates)"| FR

    %% Utilities Billing (External - Dotted)
    UB -.->|"Utility Outage Alerts<br/>(Outage Type, Facility IDs, Start/End Times)"| FR

    %% Violations Module
    VM -->|"User Violation Summary<br/>(Severity Level, Count, Last Violation Date)"| FR
    FR -->|"Violation Triggers<br/>(Reservation ID, Event Type: No-show, Damage, Policy Breach)"| VM

    %% Auto-Approval Module
    FR -->|"Reservation Request Data<br/>(Facility ID, Date, Time, User ID, Commercial Flag)"| AA
    AA -->|"Auto-Approval Decision<br/>(Eligible, Conditions Passed, Reason)"| FR

    %% AI Module
    FR -->|"Reservation Data<br/>(Bookings, Conflicts, Time Slots)"| AI
    AI -->|"Facility Recommendations<br/>(Ranked Facilities, Distance Scores)"| FR
    AI -->|"Conflict Warnings<br/>(Holiday Risks, Event Conflicts)"| FR

    %% Calendar Module
    FR -->|"Reservation Events<br/>(Date, Time, Facility, Status)"| CAL
    CAL -->|"Availability Calendar Data<br/>(Available Dates, Booked Slots)"| FR

    %% Notification Service
    FR -->|"Booking Status Events<br/>(Approved, Denied, Pending, Postponed, Cancelled)"| NT
    NT -->|"Notification Delivery Status<br/>(Sent, Delivered, Read Status)"| FR

    %% Reporting & Analytics
    FR -->|"Reservation Data<br/>(All Bookings, Status Changes, Usage Patterns)"| RP
    RP -->|"Usage Reports<br/>(Monthly Trends, Approval Rates, Facility Performance)"| FR

    %% Archival
    FR -->|"Expired Reservations<br/>(Old Reservations, Completed Events)"| AR

    %% Audit Trail
    FR -->|"Reservation Events<br/>(Status Changes, Modifications)"| AUD

    style FR fill:#0000FF,color:#ffffff
    style MM fill:#FF8000,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
    style IM fill:#530CFF,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
    style UB fill:#FFFF33,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#000000
```

---

### 3.2 Notification Service Module

```mermaid
flowchart LR
    NT[Notification Service<br/>Module]
    
    FR[Facilities Reservation]
    UM[User Management]
    MM[Maintenance Management<br/>External System]
    VM[Violations Module]
    CI[Contact Inquiry<br/>Management]
    AUTH[Authentication &<br/>Security]
    AUD[Audit Trail]

    %% Facilities Reservation
    FR -->|"Booking Status Events<br/>(Approved, Denied, Pending, Postponed, Cancelled)"| NT
    FR -->|"Affected User Notifications<br/>(Postponed Reservations, Cancelled Reservations)"| NT
    NT -->|"Notification Delivery Status<br/>(Sent, Delivered, Read Status)"| FR

    %% User Management
    UM -->|"Account Status Events<br/>(Approved, Locked, Verification Status)"| NT

    %% Maintenance Management (External - Dotted)
    MM -.->|"Maintenance Alerts<br/>(Facility Status Change, Availability Updates)"| NT

    %% Violations Module
    VM -->|"Violation Impact Events<br/>(Violation Recorded, Auto-Approval Blocked)"| NT

    %% Contact Inquiry Management
    CI -->|"Inquiry Submission Events<br/>(New Inquiry Received)"| NT

    %% Authentication & Security
    AUTH -->|"OTP Delivery Requests<br/>(Email, OTP Code)"| NT
    AUTH -->|"Password Reset Requests<br/>(Email, Reset Link)"| NT

    %% Audit Trail
    NT -->|"Notification Events<br/>(Notification Sent, Delivery Status)"| AUD

    style NT fill:#f3e5f5
    style MM fill:#FF8000,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
```

---

### 3.3 User Management Module

```mermaid
flowchart LR
    UM[User Management<br/>Module]
    
    DM[Document Management]
    AUTH[Authentication &<br/>Security]
    FR[Facilities Reservation]
    VM[Violations Module]
    NT[Notification Service]
    RP[Reporting &<br/>Analytics]
    AR[Archival & Background<br/>Jobs]
    CI[Contact Inquiry<br/>Management]
    DE[Data Export]
    AUD[Audit Trail]

    %% Document Management
    DM -->|"Document Verification Status<br/>(Approved/Rejected, Document Type)"| UM
    UM -->|"Document Access Request<br/>(User ID, Document Reference)"| DM

    %% Authentication & Security
    UM -->|"User Profile Data<br/>(User ID, Status, Role, Verification Flag)"| AUTH
    AUTH -->|"Authentication Status<br/>(Session Token, Login Status, OTP Result)"| UM

    %% Facilities Reservation
    UM -->|"User Identity Reference<br/>(User ID, Verification Status)"| FR
    FR -->|"Reservation History Summary<br/>(Active Count, Status Distribution)"| UM

    %% Violations Module
    VM -->|"Violation Records<br/>(User ID, Type, Severity, Description)"| UM
    UM -->|"User Status Updates<br/>(Account Status, Verification Status)"| VM

    %% Notification Service
    UM -->|"Account Status Events<br/>(Approved, Locked, Verification Status)"| NT

    %% Reporting & Analytics
    UM -->|"User Statistics<br/>(Registration Data, Verification Rates)"| RP

    %% Archival
    UM -->|"Inactive User Records<br/>(Locked/Deleted Accounts)"| AR

    %% Contact Inquiry Management
    UM -->|"Admin/Staff User Data<br/>(Email, Name)"| CI

    %% Data Export
    UM -->|"User Data Request<br/>(User ID, Export Type)"| DE
    DE -->|"Export File<br/>(JSON File Path, HTML Report)"| UM

    %% Audit Trail
    UM -->|"User Management Events<br/>(Account Changes, Status Updates)"| AUD

    style UM fill:#e1f5ff
```

---

### 3.4 Facilities Management Module

```mermaid
flowchart LR
    FM[Facilities Management<br/>Module]
    
    FR[Facilities Reservation]
    MM[Maintenance Management<br/>External System]
    IM[Infrastructure Management<br/>External System]
    AI[AI Scheduling &<br/>Recommendations]
    RP[Reporting &<br/>Analytics]
    AUD[Audit Trail]

    %% Facilities Reservation
    FM -->|"Facility Master Data<br/>(ID, Name, Capacity, Rules, Auto-Approve Flag, Status)"| FR
    FR -->|"Facility Usage Metrics<br/>(Booking Counts, Utilization Rates, Peak Times)"| FM

    %% Maintenance Management (External - Dotted)
    MM -.->|"Maintenance Status Updates<br/>(Facility Availability, Downtime Periods)"| FM
    FM -.->|"Facility Status Changes<br/>(Status, Maintenance Flags)"| MM

    %% Infrastructure Management (External - Dotted)
    IM -.->|"New Facility Data<br/>(Name, Type, Location, Capacity, Amenities)"| FM
    IM -.->|"Project Phase Updates<br/>(Project Status, Impact Type)"| FM

    %% AI Module
    FM -->|"Facility Data<br/>(ID, Name, Capacity, Location)"| AI

    %% Reporting & Analytics
    FM -->|"Facility Statistics<br/>(Capacity Utilization, Popular Facilities)"| RP

    %% Audit Trail
    FM -->|"Facility Management Events<br/>(CRUD Operations, Status Changes)"| AUD

    style FM fill:#fff4e1
    style MM fill:#FF8000,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
    style IM fill:#530CFF,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
```

---

### 3.5 Violations Module

```mermaid
flowchart LR
    VM[Violations Module<br/>Module]
    
    FR[Facilities Reservation]
    AA[Auto-Approval Module]
    UM[User Management]
    NT[Notification Service]
    RP[Reporting &<br/>Analytics]
    AUD[Audit Trail]

    %% Facilities Reservation
    VM -->|"User Violation Summary<br/>(Severity Level, Count, Last Violation Date)"| FR
    FR -->|"Violation Triggers<br/>(Reservation ID, Event Type: No-show, Damage, Policy Breach)"| VM

    %% Auto-Approval Module
    VM -->|"User Violation Summary<br/>(Violation Count, Severity)"| AA

    %% User Management
    VM -->|"Violation Records<br/>(User ID, Type, Severity, Description)"| UM
    UM -->|"User Status Updates<br/>(Account Status, Verification Status)"| VM

    %% Notification Service
    VM -->|"Violation Impact Events<br/>(Violation Recorded, Auto-Approval Blocked)"| NT

    %% Reporting & Analytics
    VM -->|"Violation Records<br/>(All Infractions, Severity Distribution)"| RP

    %% Audit Trail
    VM -->|"Violation Recording Events<br/>(Violation Created, Updated)"| AUD

    style VM fill:#fce4ec
```

---

### 3.6 Auto-Approval Module

```mermaid
flowchart LR
    AA[Auto-Approval Module<br/>Module]
    
    FR[Facilities Reservation]
    FM[Facilities Management]
    VM[Violations Module]
    AUD[Audit Trail]

    %% Facilities Reservation
    FR -->|"Reservation Request Data<br/>(Facility ID, Date, Time, User ID, Commercial Flag)"| AA
    AA -->|"Auto-Approval Decision<br/>(Eligible, Conditions Passed, Reason)"| FR

    %% Facilities Management
    FM -->|"Facility Auto-Approve Settings<br/>(Auto-Approve Flag, Capacity Threshold, Max Duration)"| AA

    %% Violations Module
    VM -->|"User Violation Summary<br/>(Violation Count, Severity)"| AA

    %% Audit Trail
    AA -->|"Auto-Approval Evaluation Events<br/>(Evaluation Results, Conditions Checked)"| AUD

    style AA fill:#0000FF,color:#ffffff
```

---

### 3.7 AI Scheduling & Recommendations Module

```mermaid
flowchart LR
    AI[AI Scheduling &<br/>Recommendations Module]
    
    FR[Facilities Reservation]
    FM[Facilities Management]
    CAL[Calendar &<br/>Visualization]
    AUD[Audit Trail]

    %% Facilities Reservation
    FR -->|"Reservation Data<br/>(Bookings, Conflicts, Time Slots)"| AI
    AI -->|"Facility Recommendations<br/>(Ranked Facilities, Distance Scores)"| FR
    AI -->|"Conflict Warnings<br/>(Holiday Risks, Event Conflicts)"| FR

    %% Facilities Management
    FM -->|"Facility Data<br/>(ID, Name, Capacity, Location)"| AI

    %% Calendar Module
    CAL -->|"Calendar Event Data<br/>(Reservations, Availability)"| AI

    %% Audit Trail
    AI -->|"AI Recommendation Events<br/>(Recommendations Generated, Conflicts Detected)"| AUD

    style AI fill:#0000FF,color:#ffffff
```

---

### 3.8 Calendar & Visualization Module

```mermaid
flowchart LR
    CAL[Calendar &<br/>Visualization Module]
    
    FR[Facilities Reservation]
    FM[Facilities Management]
    AI[AI Scheduling &<br/>Recommendations]
    AUD[Audit Trail]

    %% Facilities Reservation
    FR -->|"Reservation Events<br/>(Date, Time, Facility, Status)"| CAL
    CAL -->|"Availability Calendar Data<br/>(Available Dates, Booked Slots)"| FR

    %% Facilities Management
    FM -->|"Facility Status<br/>(Available, Maintenance, Offline)"| CAL

    %% AI Module
    CAL -->|"Calendar Event Data<br/>(Reservations, Availability)"| AI

    %% Audit Trail
    CAL -->|"Calendar View Events<br/>(Calendar Accessed, Filters Applied)"| AUD

    style CAL fill:#0000FF,color:#ffffff
```

---

### 3.9 Authentication & Security Module

```mermaid
flowchart LR
    AUTH[Authentication &<br/>Security Module]
    
    UM[User Management]
    NT[Notification Service]
    AUD[Audit Trail]

    %% User Management
    UM -->|"User Profile Data<br/>(User ID, Status, Role, Verification Flag)"| AUTH
    AUTH -->|"Authentication Status<br/>(Session Token, Login Status, OTP Result)"| UM

    %% Notification Service
    AUTH -->|"OTP Delivery Requests<br/>(Email, OTP Code)"| NT
    AUTH -->|"Password Reset Requests<br/>(Email, Reset Link)"| NT

    %% Audit Trail
    AUTH -->|"Authentication Events<br/>(Login Attempts, OTP Verification)"| AUD

    style AUTH fill:#fff4e1
```

---

### 3.10 Document Management Module

```mermaid
flowchart LR
    DM[Document Management<br/>Module]
    
    UM[User Management]
    FR[Facilities Reservation]
    AR[Archival & Background<br/>Jobs]
    DE[Data Export]
    AUD[Audit Trail]

    %% User Management
    DM -->|"Document Verification Status<br/>(Approved/Rejected, Document Type)"| UM
    UM -->|"Document Access Request<br/>(User ID, Document Reference)"| DM

    %% Facilities Reservation
    DM -->|"Document Metadata<br/>(File Path, Upload Date, Type)"| FR

    %% Archival
    DM -->|"Expired Documents<br/>(Documents Past Retention Period)"| AR
    AR -->|"Archive Status<br/>(Archival Completion, Retention Policies)"| DM

    %% Data Export
    DM -->|"Document Data Request<br/>(User ID, Document References)"| DE

    %% Audit Trail
    DM -->|"Document Management Events<br/>(Upload, Delete, Verification)"| AUD

    style DM fill:#e1f5ff
```

---

### 3.11 Reporting & Analytics Module

```mermaid
flowchart LR
    RP[Reporting &<br/>Analytics Module]
    
    FR[Facilities Reservation]
    MM[Maintenance Management<br/>External System]
    VM[Violations Module]
    UM[User Management]
    FM[Facilities Management]
    CI[Contact Inquiry<br/>Management]
    AR[Archival & Background<br/>Jobs]
    AUD[Audit Trail]

    %% Facilities Reservation
    FR -->|"Reservation Data<br/>(All Bookings, Status Changes, Usage Patterns)"| RP
    RP -->|"Usage Reports<br/>(Monthly Trends, Approval Rates, Facility Performance)"| FR

    %% Maintenance Management (External - Dotted)
    MM -.->|"Maintenance Logs<br/>(Maintenance Records, Downtime Statistics)"| RP

    %% Violations Module
    VM -->|"Violation Records<br/>(All Infractions, Severity Distribution)"| RP

    %% User Management
    UM -->|"User Statistics<br/>(Registration Data, Verification Rates)"| RP

    %% Facilities Management
    FM -->|"Facility Statistics<br/>(Capacity Utilization, Popular Facilities)"| RP

    %% Contact Inquiry Management
    CI -->|"Inquiry Statistics<br/>(Inquiry Counts, Response Times)"| RP

    %% Archival
    AR -->|"Archived Record References<br/>(Archive Location, Retention Status)"| RP

    %% Audit Trail
    AUD -->|"Audit Log Data<br/>(All System Events, User Actions)"| RP

    style RP fill:#f3e5f5
    style MM fill:#FF8000,stroke:#000000,stroke-width:2px,stroke-dasharray: 5 5,color:#ffffff
```

---

### 3.12 Archival & Background Jobs Module

```mermaid
flowchart LR
    AR[Archival & Background<br/>Jobs Module]
    
    FR[Facilities Reservation]
    DM[Document Management]
    UM[User Management]
    RP[Reporting &<br/>Analytics]
    AUD[Audit Trail]

    %% Facilities Reservation
    FR -->|"Expired Reservations<br/>(Old Reservations, Completed Events)"| AR

    %% Document Management
    DM -->|"Expired Documents<br/>(Documents Past Retention Period)"| AR
    AR -->|"Archive Status<br/>(Archival Completion, Retention Policies)"| DM

    %% User Management
    UM -->|"Inactive User Records<br/>(Locked/Deleted Accounts)"| AR

    %% Reporting & Analytics
    AR -->|"Archived Record References<br/>(Archive Location, Retention Status)"| RP

    %% Audit Trail
    AUD -->|"Old Audit Logs<br/>(Logs Past Retention Period)"| AR

    style AR fill:#f3e5f5
```

---

### 3.13 Contact Inquiry Management Module

```mermaid
flowchart LR
    CI[Contact Inquiry<br/>Management Module]
    
    UM[User Management]
    NT[Notification Service]
    RP[Reporting &<br/>Analytics]
    AUD[Audit Trail]

    %% User Management
    UM -->|"Admin/Staff User Data<br/>(Email, Name)"| CI

    %% Notification Service
    CI -->|"Inquiry Submission Events<br/>(New Inquiry Received)"| NT

    %% Reporting & Analytics
    CI -->|"Inquiry Statistics<br/>(Inquiry Counts, Response Times)"| RP

    %% Audit Trail
    CI -->|"Contact Inquiry Events<br/>(Inquiry Created, Status Updated)"| AUD

    style CI fill:#f3e5f5
```

---

### 3.14 Audit Trail Module

```mermaid
flowchart LR
    AUD[Audit Trail<br/>Module]
    
    FR[Facilities Reservation]
    UM[User Management]
    FM[Facilities Management]
    VM[Violations Module]
    AUTH[Authentication &<br/>Security]
    DM[Document Management]
    NT[Notification Service]
    AA[Auto-Approval Module]
    AI[AI Scheduling &<br/>Recommendations]
    CAL[Calendar &<br/>Visualization]
    CI[Contact Inquiry<br/>Management]
    RP[Reporting &<br/>Analytics]
    AR[Archival & Background<br/>Jobs]

    %% All Modules Send Audit Events
    FR -->|"Reservation Events<br/>(Status Changes, Modifications)"| AUD
    UM -->|"User Management Events<br/>(Account Changes, Status Updates)"| AUD
    FM -->|"Facility Management Events<br/>(CRUD Operations, Status Changes)"| AUD
    VM -->|"Violation Recording Events<br/>(Violation Created, Updated)"| AUD
    AUTH -->|"Authentication Events<br/>(Login Attempts, OTP Verification)"| AUD
    DM -->|"Document Management Events<br/>(Upload, Delete, Verification)"| AUD
    NT -->|"Notification Events<br/>(Notification Sent, Delivery Status)"| AUD
    AA -->|"Auto-Approval Evaluation Events<br/>(Evaluation Results, Conditions Checked)"| AUD
    AI -->|"AI Recommendation Events<br/>(Recommendations Generated, Conflicts Detected)"| AUD
    CAL -->|"Calendar View Events<br/>(Calendar Accessed, Filters Applied)"| AUD
    CI -->|"Contact Inquiry Events<br/>(Inquiry Created, Status Updated)"| AUD

    %% Reporting & Analytics
    AUD -->|"Audit Log Data<br/>(All System Events, User Actions)"| RP

    %% Archival
    AUD -->|"Old Audit Logs<br/>(Logs Past Retention Period)"| AR

    style AUD fill:#fff4e1
```

---

### 3.15 Data Export Module

```mermaid
flowchart LR
    DE[Data Export<br/>Module]
    
    UM[User Management]
    FR[Facilities Reservation]
    DM[Document Management]

    %% User Management
    UM -->|"User Data Request<br/>(User ID, Export Type)"| DE
    DE -->|"Export File<br/>(JSON File Path, HTML Report)"| UM

    %% Facilities Reservation
    FR -->|"Reservation Data Request<br/>(User ID, Reservation Filter)"| DE

    %% Document Management
    DM -->|"Document Data Request<br/>(User ID, Document References)"| DE

    style DE fill:#f3e5f5
```

---

## Notes

### Level 1 BPA
- **Purpose**: Enterprise-level view of major business domains
- **Focus**: High-level data exchanges between organizational domains
- **Data Labels**: Represent business information flows (citizen identity, facility data, reservation outcomes, violations, usage statistics)

### Level 2 BPA
- **Purpose**: Complete system architecture showing all modules (internal + external integrations)
- **Focus**: Technical data exchanges between all system components
- **Data Labels**: Specific data payloads (User ID, Facility Status, Violation Summary, etc.)
- **Dotted Lines**: External integration modules (Maintenance Management, Infrastructure Management, Utilities Billing from other systems)
- **Includes**: All 15 internal modules + 3 external integration modules

### Level 3 BPA
- **Purpose**: Individual module diagrams showing what connects to each module
- **Focus**: Module-centric view of data exchanges for each system component
- **Structure**: One diagram per module (15 total diagrams)
- **Dotted Lines**: External integration modules shown with dotted connections
- **Excludes**: Modules not directly connected (only shows relevant connections)

---

## General Legend

### Connection Types
- **Solid Lines** (`-->` or `<-->`): Internal system connections (within Facilities Reservation System)
- **Dotted Lines** (`-.->` or `<-.->`): External system integrations (connections to other LGU systems)
- **Bidirectional Arrows** (`<-->`): Two-way data exchange between modules
- **One-way Arrows** (`-->`): One-way data flow from source to destination

### Color Coding (Level 2 Diagram)

**🔵 User & Identity Management (Light Blue - #e1f5ff)**
- User Management
- Document Management

**🟡 Facilities & Infrastructure (Light Orange - #fff4e1)**
- Facilities Management
- Authentication & Security
- Audit Trail

**🔵 Facilities Reservation Modules (Blue - #0000FF)**
- Facilities Reservation
- AI Scheduling & Recommendations
- Calendar & Visualization
- Auto-Approval Module

**🔴 Compliance & Enforcement (Light Pink - #fce4ec)**
- Violations Module

**🟣 Services & Analytics (Light Purple - #f3e5f5)**
- Notification Service
- Reporting & Analytics
- Archival & Background Jobs
- Contact Inquiry Management
- Data Export

**External Integration Systems (Dotted Borders)**
- **MM**: Maintenance Management System (Orange - #FF8000, External)
- **IM**: Infrastructure Management System (Purple - #530CFF, External)
- **UB**: Utilities Billing System (Yellow - #FFFF33, External)

### External System Descriptions

**Maintenance Management System (MM) - Orange (#FF8000)**
- Manages facility maintenance schedules and downtime
- Provides maintenance status updates to block facilities during maintenance periods
- Receives facility usage metrics for maintenance planning

**Infrastructure Management System (IM) - Purple (#530CFF)**
- Manages construction/renovation projects
- Provides facility closure notifications during projects
- Sends new facility data when projects complete

**Utilities Billing System (UB) - Yellow (#FFFF33)**
- Manages utility services (power, water)
- Provides utility outage alerts that affect facility availability
- Receives facility usage reference data for billing purposes
