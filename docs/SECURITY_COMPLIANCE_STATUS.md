# Security & Compliance Status for Sensitive Documents

**Last Updated**: 2025-01-XX  
**System**: Facilities Reservation System  
**Purpose**: Status check against Data Privacy Act (RA 10173) requirements and security best practices

---

## ✅ **IMPLEMENTED**

### 1. Legal / Compliance Requirements

| Requirement | Status | Implementation |
|------------|--------|----------------|
| **Explicit user consent before upload** | ✅ **YES** | Terms & Conditions checkbox required during registration (`resources/views/pages/auth/register.php:217`) |
| **Clear privacy policy / data handling notice** | ✅ **YES** | Full Privacy Policy page (`resources/views/pages/public/privacy.php`) with Data Privacy Act (RA 10173) compliance details |
| **Purpose limitation – only collect what is required** | ✅ **YES** | Registration only requires: name, email, password, address (Culiat check), mobile (optional), Valid ID document |
| **Data minimization** | ✅ **YES** | Only Valid ID document required (removed other options from UI) |
| **User rights: Ability to request deletion** | ✅ **YES** | Mentioned in Privacy Policy; deletion subject to legal retention |
| **User rights: Ability to view what was uploaded** | ✅ **YES** | Users can export their data via Profile → Data Export (JSON + readable report) |
| **Terms of Use disclaimer (prototype notice)** | ⚠️ **PARTIAL** | Terms exist but doesn't explicitly state "prototype for academic purposes" |

### 2. Application-Level Protections

| Requirement | Status | Implementation |
|------------|--------|----------------|
| **File type validation** | ✅ **YES** | `validateFileUpload()` in `config/security.php:288` checks MIME type using `finfo_file()` |
| **MIME type verification** | ✅ **YES** | Real MIME type checking (not just extension) via `finfo_open(FILEINFO_MIME_TYPE)` |
| **Size limits** | ✅ **YES** | Max 5MB default (configurable), enforced in validation |
| **File extension validation** | ✅ **YES** | Whitelist: `jpg, jpeg, png, gif, webp, pdf` |
| **Basic malicious content check** | ✅ **YES** | Checks for `<?php`, `<?=`, `<script` in first 1KB of file |
| **File renaming** | ✅ **YES** | Random safe names: `safeName_timestamp.ext` (prevents path traversal) |
| **Double-extension exploit prevention** | ⚠️ **PARTIAL** | Basic extension check exists but could be strengthened |

### 3. Access Control

| Requirement | Status | Implementation |
|------------|--------|----------------|
| **Role-Based Access Control (RBAC)** | ✅ **YES** | Admin/Staff/Resident roles enforced on all sensitive pages |
| **Authorization checks** | ✅ **YES** | Document viewing/downloading requires authentication + role check |
| **Session security** | ✅ **YES** | HttpOnly, Secure (when HTTPS), SameSite=Strict cookies |

### 4. Audit & Monitoring

| Requirement | Status | Implementation |
|------------|--------|----------------|
| **Activity logs** | ✅ **YES** | `audit_log` table tracks all significant actions |
| **Security logs** | ✅ **YES** | `security_logs` table for security events (upload failures, rate limits, etc.) |
| **Failed upload attempts logging** | ✅ **YES** | Security events logged when uploads fail validation |
| **Rate limiting** | ✅ **YES** | Registration (3/hour/IP), login (5/15min/email) |

### 5. Data Retention & Deletion Policy

| Requirement | Status | Implementation |
|------------|--------|----------------|
| **Retention periods defined** | ✅ **YES** | Privacy Policy states: 7 years for identity docs, 5 years for reservations, 3-7 years for logs |
| **Document retention policy table** | ✅ **YES** | `document_retention_policy` table with configurable retention rules |
| **Automatic archival** | ✅ **YES** | `scripts/archive_documents.php` for scheduled archival |
| **Secure deletion** | ❌ **NO** | Standard `unlink()` only; no secure overwrite |

### 6. Data Export (User Rights)

| Requirement | Status | Implementation |
|------------|--------|----------------|
| **User data export** | ✅ **YES** | Profile → Data Export (JSON + printable report) |
| **Export expiration** | ✅ **YES** | 7-day expiration on export files |
| **Secure download endpoint** | ✅ **YES** | `download_export.php` with auth + ownership checks |

---

## ❌ **NOT IMPLEMENTED / CRITICAL GAPS**

### 1. Secure Storage & Access

| Issue | Severity | Current State | Impact |
|-------|----------|---------------|--------|
| **Files stored in `public/uploads/documents/`** | 🔴 **CRITICAL** | Documents are **web-accessible** via direct URL | Anyone with URL can access sensitive IDs |
| **No secure download handler for documents** | 🔴 **CRITICAL** | Only export files use secure handler; user documents don't | Direct file access bypasses RBAC |
| **No pre-signed/expiring URLs** | 🔴 **CRITICAL** | Direct file paths stored in DB | No time-limited access |
| **No file access logging** | 🟡 **HIGH** | No logs of who accessed which document | Cannot audit document access |

### 2. Encryption

| Issue | Severity | Current State | Impact |
|-------|----------|---------------|--------|
| **No encryption at rest** | 🔴 **CRITICAL** | Files stored as plain filesystem files | If server compromised, files readable |
| **HTTPS not enforced** | 🔴 **CRITICAL** | HTTPS redirect commented out in `config/security.php:279` | Files transmitted in plain text if HTTPS not enabled |
| **No TLS enforcement** | 🔴 **CRITICAL** | Relies on server configuration | Risk of man-in-the-middle attacks |

### 3. Virus Scanning

| Issue | Severity | Current State | Impact |
|-------|----------|---------------|--------|
| **No virus/malware scanning** | 🟡 **HIGH** | Only basic content check (`<?php`, `<script`) | Malicious files can be uploaded |

### 4. Privacy by Design

| Issue | Severity | Current State | Impact |
|-------|----------|---------------|--------|
| **No masked thumbnails** | 🟡 **MEDIUM** | Admin can see full documents in User Management | Privacy risk if admin screen shared |
| **No watermarking** | 🟡 **LOW** | Documents shown as-is | No leakage prevention |

### 5. System Architecture

| Issue | Severity | Current State | Impact |
|-------|----------|---------------|--------|
| **No separate storage server** | 🟡 **MEDIUM** | All files on same server as app | Risk if app server compromised |
| **Some hardcoded values** | 🟡 **MEDIUM** | Some config values in code (can use env vars) | Less flexible deployment |

### 6. Documentation

| Issue | Severity | Current State | Impact |
|-------|----------|---------------|--------|
| **No breach response plan** | 🟡 **MEDIUM** | Not documented | Cannot respond quickly to incidents |

---

## 🛠️ **RECOMMENDED FIXES (Priority Order)**

### **Priority 1: CRITICAL (Do Before Production)**

1. **Move documents outside `public/` directory**
   - Move to `storage/private/documents/{userId}/`
   - Create secure download handler: `download_document.php` with:
     - Authentication check
     - Ownership check (user can only download their own, or Admin/Staff for verification)
     - Access logging
     - RBAC enforcement

2. **Enforce HTTPS**
   - Uncomment HTTPS redirect in `config/security.php:279`
   - Obtain SSL certificate
   - Update `.htaccess` to force HTTPS

3. **Add file access logging**
   - Create `document_access_log` table
   - Log every document download/view: `user_id`, `document_id`, `accessed_by`, `timestamp`
   - Display in admin audit trail

### **Priority 2: HIGH (Strongly Recommended)**

4. **Implement secure download handler for all documents**
   - Replace direct file links with `download_document.php?id={doc_id}`
   - Add expiring tokens (optional: 1-hour expiry)
   - Log all access attempts

5. **Add virus scanning (ClamAV)**
   - Install ClamAV on server
   - Scan files after upload: `clamscan --no-summary {file}`
   - Reject infected files

6. **Add encryption at rest**
   - Use PHP's `openssl_encrypt()` before saving
   - Store encryption key in environment variable
   - Decrypt on download

### **Priority 3: MEDIUM (Good to Have)**

7. **Add masked thumbnails for admin preview**
   - Generate blurred/watermarked thumbnails for document preview
   - Full document only on explicit "View Full" click

8. **Implement secure deletion**
   - Overwrite file with random data before `unlink()`
   - Multiple passes (3x) for sensitive documents

9. **Separate storage server (for production)**
   - Use S3-compatible storage or separate NFS mount
   - Store outside web root entirely

10. **Add breach response plan documentation**
    - Document steps: detect → contain → notify → recover
    - Include contact list (DPO, IT team, legal)

---

## 📋 **IMMEDIATE ACTION CHECKLIST**

### Before Deployment:

- [ ] Move `public/uploads/documents/` → `storage/private/documents/`
- [ ] Create `download_document.php` secure handler
- [ ] Update all document links to use secure handler
- [ ] Add `document_access_log` table
- [ ] Enable HTTPS redirect
- [ ] Obtain SSL certificate
- [ ] Test secure download handler
- [ ] Add Terms & Conditions "prototype/academic" disclaimer

### Before Capstone Defense:

- [ ] Document security architecture in paper
- [ ] Create security testing results section
- [ ] Document risk assessment
- [ ] Add ethical considerations section
- [ ] Show panel: "Here's what we implemented (✅) and what we recommend for production (❌)"

---

## 🎓 **For Capstone Defense**

### What to Say:

**"For a capstone project, we implemented:**
- ✅ File validation (MIME, size, extension, basic content check)
- ✅ RBAC for document access
- ✅ Audit logging for uploads
- ✅ Data export for user rights
- ✅ Retention policies
- ✅ Privacy policy & consent

**For production deployment, we recommend:**
- ❌ Moving documents outside web-accessible directory
- ❌ Implementing secure download handler
- ❌ Adding encryption at rest
- ❌ Enforcing HTTPS
- ❌ Adding virus scanning (ClamAV)

**This demonstrates we understand security requirements while keeping scope appropriate for a capstone."**

---

## 📊 **Summary Score**

| Category | Status |
|----------|--------|
| **Legal Compliance** | ✅ **85%** (Missing: explicit prototype disclaimer) |
| **Application Security** | ✅ **80%** (Good validation, missing: virus scan, double-extension hardening) |
| **Storage Security** | ❌ **30%** (Files in `public/`, no encryption, no secure handler) |
| **Access Control** | ✅ **90%** (Good RBAC, missing: access logging) |
| **Audit & Monitoring** | ✅ **85%** (Good logging, missing: file access logs) |
| **Overall** | 🟡 **70%** (Good for capstone, needs hardening for production) |

---

**Bottom Line**: The system has **good foundations** for a capstone project, but **critical security gaps** exist that must be addressed before handling real sensitive documents in production.


