# Secure Document Storage Implementation

**Date**: 2025-01-XX  
**Status**: ✅ **COMPLETE**

This document describes the implementation of Priority 1 security fixes for document storage and access.

---

## ✅ **What Was Implemented**

### 1. Secure Document Storage

- **Moved documents outside `public/` directory**
  - New location: `storage/private/documents/{userId}/`
  - Files are no longer web-accessible via direct URL
  - Restrictive file permissions (0600 = owner read/write only)
  - Directory permissions (0700 = owner only)

### 2. Secure Download Handler

- **Created `download_document.php`** with:
  - ✅ Authentication check (must be logged in)
  - ✅ Ownership check (users can only access their own documents)
  - ✅ RBAC enforcement (Admin/Staff can access any document)
  - ✅ Access logging (every access logged to `document_access_log` table)
  - ✅ Security headers
  - ✅ Proper MIME type detection
  - ✅ Support for view/download/thumbnail access types

### 3. Database Changes

- **Created `document_access_log` table**:
  - Tracks: `document_id`, `user_id`, `accessed_by`, `access_type`, `ip_address`, `user_agent`, `accessed_at`
  - Full audit trail of who accessed which document and when

### 4. Code Updates

- **Updated `register.php`**: Now uses `saveDocumentToSecureStorage()` function
- **Updated `user_management.php`**: Document links now use secure download handler
- **Updated `document_archival.php`**: Handles both old and new storage paths
- **Created `config/secure_documents.php`**: Helper functions for secure document management

### 5. HTTPS Configuration

- **Updated `config/security.php`**: Added clearer comments about HTTPS enforcement
- **Updated `.htaccess`**: Added clearer comments about HTTPS redirect
- **Note**: HTTPS redirect is still commented out (requires SSL certificate)

### 6. Migration Script

- **Created `scripts/migrate_documents_to_secure_storage.php`**:
  - Moves existing documents from `public/uploads/documents/` to `storage/private/documents/`
  - Updates database records with new paths
  - Supports `--dry-run` mode for testing
  - Handles filename conflicts

---

## 📋 **Deployment Steps**

### Step 1: Run Database Migration

```sql
-- Import the migration file
SOURCE database/migration_secure_document_storage.sql;
```

This creates the `document_access_log` table.

### Step 2: Migrate Existing Documents

```bash
# Test first (dry run)
php scripts/migrate_documents_to_secure_storage.php --dry-run

# Perform actual migration
php scripts/migrate_documents_to_secure_storage.php
```

This will:
- Move all documents from `public/uploads/documents/` to `storage/private/documents/`
- Update database records with new paths
- Preserve file permissions

### Step 3: Verify Storage Directories

Ensure these directories exist and have correct permissions:
- `storage/private/documents/` (0700)
- `storage/archive/documents/` (0700)

### Step 4: Test Secure Downloads

1. Log in as a user
2. Go to User Management (Admin/Staff) or Profile (Resident)
3. Click on a document link
4. Verify:
   - Document downloads through secure handler
   - Access is logged in `document_access_log` table
   - Direct URL access to old location fails (if files were moved)

### Step 5: Enable HTTPS (Production Only)

**After obtaining SSL certificate:**

1. Uncomment HTTPS redirect in `config/security.php` (line ~279)
2. Uncomment HTTPS redirect in `.htaccess` (line ~9-11)
3. Test HTTPS enforcement

---

## 🔒 **Security Improvements**

### Before:
- ❌ Documents stored in `public/uploads/documents/` (web-accessible)
- ❌ Direct URL access: `https://site.com/public/uploads/documents/123/file.pdf`
- ❌ No access logging
- ❌ No ownership verification

### After:
- ✅ Documents stored in `storage/private/documents/` (not web-accessible)
- ✅ Secure access: `https://site.com/resources/views/pages/dashboard/download_document.php?id=123`
- ✅ Full access logging with IP, user agent, timestamp
- ✅ RBAC and ownership checks enforced
- ✅ Files have restrictive permissions (0600)

---

## 📊 **Access Logging**

Every document access is logged with:
- Document ID
- Document owner (user_id)
- Who accessed it (accessed_by)
- Access type (view/download/thumbnail)
- IP address
- User agent
- Timestamp

**Query access logs:**
```sql
SELECT 
    dal.*,
    u1.name as owner_name,
    u2.name as accessed_by_name,
    ud.file_name
FROM document_access_log dal
JOIN users u1 ON dal.user_id = u1.id
JOIN users u2 ON dal.accessed_by = u2.id
JOIN user_documents ud ON dal.document_id = ud.id
ORDER BY dal.accessed_at DESC
LIMIT 50;
```

---

## 🛠️ **Helper Functions**

### `config/secure_documents.php`

- `getSecureDocumentStoragePath()` - Get secure storage directory
- `getUserDocumentStoragePath($userId)` - Get user's document directory
- `getSecureDocumentUrl($documentId, $accessType)` - Generate secure download URL
- `logDocumentAccess($documentId, $accessedBy, $accessType)` - Log access
- `canUserAccessDocument($documentId, $userId, $role)` - Check access permissions
- `getDocumentFilePath($documentId)` - Get absolute file path (handles old/new paths)
- `saveDocumentToSecureStorage($file, $userId, $documentType)` - Save uploaded document

---

## ⚠️ **Important Notes**

1. **Old Documents**: The system handles both old (`public/uploads`) and new (`storage/private`) paths. Run the migration script to move all documents.

2. **HTTPS**: HTTPS enforcement is commented out. Uncomment after obtaining SSL certificate.

3. **File Permissions**: 
   - Files: `0600` (owner read/write only)
   - Directories: `0700` (owner only)
   - These are set automatically by the helper functions

4. **Backward Compatibility**: The `getDocumentFilePath()` function handles both old and new paths, so existing documents continue to work until migration.

---

## 🧪 **Testing Checklist**

- [ ] New document uploads go to `storage/private/documents/`
- [ ] Document links use secure download handler
- [ ] Users can only access their own documents
- [ ] Admin/Staff can access any document
- [ ] Access attempts are logged
- [ ] Direct URL access to old location fails (after migration)
- [ ] Direct URL access to new location fails (not web-accessible)
- [ ] Migration script successfully moves existing documents
- [ ] Database records updated with new paths

---

## 📝 **Next Steps (Priority 2)**

1. **Virus Scanning**: Add ClamAV scanning for uploaded files
2. **Encryption at Rest**: Encrypt files before storing
3. **Masked Thumbnails**: Generate blurred/watermarked previews for admin
4. **Secure Deletion**: Overwrite files before deletion
5. **Pre-signed URLs**: Add expiring tokens for temporary access

---

**Status**: ✅ Priority 1 implementation complete. System is now significantly more secure for handling sensitive documents.




