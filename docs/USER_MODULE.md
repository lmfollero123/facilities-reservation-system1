# User Management Module (Front-End Blueprint)

## Page
- `resources/views/pages/dashboard/user_management.php`
- Sidebar entry: “User Management”
- Route hint: `/dashboard/users`

## Purpose
- Provide Admin and Staff with a centralized view of all system users.
- Support role-based filtering (Admin, Staff, Resident).
- Surface pending account approvals for transparency and control.

## Layout
1. **Accounts Directory (left panel)**
   - Filters:
     - Role: All, Admin, Staff, Resident.
     - Status: All, Active, Pending Approval, Locked.
   - Table columns:
     - Name, Email, Role, Status (using `status-badge` variants), Action (Approve/View).
   - “Approve” buttons on `Pending Approval` users (UI only, no backend logic yet).

2. **Approval Queue Summary (right panel)**
   - Uses `audit-list` style to summarize:
     - Count of pending approvals.
     - Last approval activity.
     - High-level health of staff accounts.

## Integration Points
- **User Data Store:** Replace static `$users` array with live user records and roles.
- **Role Management:** Hook role changes into the authorization layer (Admin, Staff, Resident).
- **Approval Workflow:** Wire “Approve” actions to actual status updates and notification triggers.
- **Audit Trail Module:** Log every approval, lock, and role change for transparency.

## Next Steps
1. Connect filters to backend queries or APIs for dynamic filtering.
2. Add a detail view (or modal) when clicking “View” to show full user profile and history.
3. Extend UI to support invitation flows, password resets, and account locking/unlocking.



