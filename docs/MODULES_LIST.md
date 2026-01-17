# BARANGAY CULIAT PUBLIC FACILITIES RESERVATION SYSTEM
## MODULES AND SUB MODULES

## 1. User Management and Authentication Module

Purpose: Enable secure user registration, authentication, and account management for residents and LGU staff.

Submodules:
1.1 User Registration and Account Creation
1.2 Email and Password Authentication
1.3 OTP (One-Time Password) Two-Factor Authentication
1.4 Session Management and Security
1.5 Password Reset and Recovery
1.6 User Profile Management
1.7 User Status Management (Approve/Deny/Lock)
1.8 Role-Based Access Control (Resident, Staff, Admin)
1.9 Document Upload and Validation (Valid ID)
1.10 Account Deactivation and Reactivation


## 2. Facility Management Module

Purpose: Manage public facility information, availability, and operational status.

Submodules:
2.1 Facility CRUD Operations (Create, Read, Update, Delete)
2.2 Facility Information Management
2.3 Facility Image Upload and Management
2.4 Facility Status Management (Available, Maintenance, Offline)
2.5 Facility Location and Geocoding
2.6 Public Facility Listing and Display
2.7 Facility Details and Specifications
2.8 Facility Maintenance Scheduling Integration
2.9 Auto-Approval Settings Configuration
2.10 Facility Audit Logging


## 3. Reservation and Booking Module

Purpose: Handle facility reservation requests, approvals, and lifecycle management.

Submodules:
3.1 Reservation Request Submission
3.2 Booking Form and Data Collection
3.3 Reservation Status Management (Pending, Approved, Denied, Cancelled, Postponed, On Hold)
3.4 Reservation Approval and Denial Workflow
3.5 Reservation History and Timeline Tracking
3.6 Booking Limit Enforcement
3.7 Reservation Rescheduling and Modification
3.8 Auto-Decline Expired Reservations
3.9 Reservation Detail View
3.10 Priority Handling for Postponed Reservations
3.11 Maintenance Status Integration (Postponed/Cancelled on Maintenance)


## 4. AI-Assisted Scheduling and Recommendation Module

Purpose: Support intelligent scheduling decisions and provide facility recommendations using automated analysis.

Submodules:
4.1 Real-Time Conflict Detection (Hard/Soft Conflicts) - [Implemented - OPTIMIZED Jan 2025]
   - Combined queries reduce database calls by ~60%
   - Rule-based risk calculation (no ML overhead for faster response)
   - Client-side debouncing (500ms) reduces API calls by ~70%
4.2 Smart Facility Recommendation Engine - [Implemented - OPTIMIZED Jan 2025]
   - 5-second timeout with 3-second quick fallback to rule-based
   - Smart fetching (skips if date/time missing)
   - Client-side debouncing (1000ms)
4.3 Alternative Slot Generation - [Implemented - OPTIMIZED Jan 2025]
   - Only calculated when hard conflicts exist (lazy evaluation)
4.4 Distance-Based Scoring (Haversine Formula) - [Implemented]
4.5 Purpose-Based Keyword Matching - [Implemented]
4.6 Holiday and Event Risk Tagging - [Implemented - OPTIMIZED Jan 2025]
   - Fast rule-based calculation using combined queries
4.7 Historical Risk Scoring - [Implemented - OPTIMIZED Jan 2025]
   - Optimized single aggregate query for historical + pending counts
4.8 Predictive Availability Forecasting - [Partially Implemented]
4.9 AI Chatbot Assistant - [Planned]
4.10 Context-Aware Facility Recommendations - [Implemented]
4.11 Performance Database Indexes - [Implemented Jan 2025]
   - Indexes for conflict detection, historical queries, user booking counts, facility lookups


## 5. Calendar and Visualization Module

Purpose: Provide visual calendar representations of facility availability and reservations.

Submodules:
5.1 Month View Calendar Display
5.2 Week View Calendar Display
5.3 Day View Calendar Display
5.4 Reservation Event Visualization
5.5 Holiday and Event Marking
5.6 Calendar Modal and Snapshot Display
5.7 Day Details Modal
5.8 Maintenance Status Indicators
5.9 Real-Time Availability Updates
5.10 Calendar Export and Integration


## 6. Notification and Communication Module

Purpose: Manage in-app notifications and email communications for users and administrators.

Submodules:
6.1 In-App Notification System
6.2 Notification Panel and Display
6.3 Notification Marking and Filtering
6.4 Email Notification Delivery
6.5 Reservation Status Update Notifications
6.6 Approval and Denial Email Alerts
6.7 Maintenance and Postponement Notifications
6.8 Password Reset Email Notifications
6.9 Contact Inquiry Email Alerts
6.10 Notification Preferences Management
6.11 Public Announcements System - [Implemented]
   - Public announcements archive page with search, filters, and pagination
   - Category-based filtering (Emergency, Events, Health, Deadlines, Advisory, General)
   - Sort options (Newest, Oldest)
   - Responsive grid layout (1/2/3 columns based on screen size)
   - Image support for announcements
   - Link support for external resources
6.12 Announcements Management (Admin/Staff) - [Implemented]
   - Create, view, and delete public announcements
   - Image upload and management
   - Category assignment
   - Link attachment support
   - Audit logging for announcement actions


## 7. Monitoring, Analytics, and Reporting Module

Purpose: Provide operational visibility, performance metrics, and data export capabilities.

Submodules:
7.1 Real-Time Dashboard Visualization
7.2 Reservation Statistics and Trends
7.3 Facility Utilization Analytics
7.4 User Activity Monitoring
7.5 Response Time Tracking
7.6 Report Generation (PDF/Excel)
7.7 Data Export and CSV Download
7.8 Custom Report Filtering
7.9 Chart and Graph Visualization
7.10 Performance Metrics Dashboard


## 8. System Administration and Security Module

Purpose: Control system access, maintain data integrity, ensure security compliance, and manage system operations.

Submodules:
8.1 User Role and Permission Management
8.2 LGU Staff and Team Management
8.3 Audit Trail and Activity Logging
8.4 Security Event Tracking
8.5 Login Attempt Monitoring and Rate Limiting
8.6 Data Backup and Recovery
8.7 Document Archival and Storage Management
8.8 Contact Information Management - [Implemented]
   - Admin/Staff interface for managing public contact information
   - Fields: Office Name, Address, Phone, Mobile, Email, Office Hours
   - Real-time updates to public contact page
   - CSRF protection and audit logging
   - Preview functionality for contact page
8.9 Contact Inquiry Management
8.10 System Maintenance and Updates
8.11 Security and Access Control Policies
8.12 CSRF Protection and Security Headers
8.13 Database Migration Management
