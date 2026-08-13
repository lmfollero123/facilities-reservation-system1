# Admin/Staff Resident Profile Page — Design

## Context

This is Phase 1 of a larger effort (agreed order):

1. **This spec** — new admin/staff-viewable resident profile page.
2. Clickable resident names in `reservations_manage.php` linking to it.
3. Audit-log gap: login/logout events currently uncaptured.
4. Compact-layout refactor of `user_management.php`, using this new page's layout as the template.

Today, only residents can view their own profile (`resources/views/pages/dashboard/profile.php`). Admins/staff need to view a *resident's* profile while approving reservations or handling account issues — including reservation history, violations history, account details/info, profile picture, and the same account actions (reset password / lock / unlock / delete) currently confined to `user_management.php`.

## Existing building blocks (reused, not duplicated)

- `config/audit.php` → `logAudit($action, $module, $details, $userId)`, writes to `audit_log` table.
- `config/violations.php` → `getUserViolations($userId)`, `frs_violation_type_label()`, `frs_user_has_high_severity_violations()`.
- `config/user_admin.php` → account action handlers (reset password, lock/unlock, delete) already used by `user_management.php`.
- Reservation query pattern in `resources/views/pages/dashboard/reservations_manage.php` (selects `u.id AS requester_id`, `u.name AS requester`).
- Shared CSS classes already in use across dashboard pages: `booking-card`, `status-badge`.

## New page

`resources/views/pages/dashboard/resident_profile.php?user_id={id}`

Access: admin and staff roles only (same role-gate pattern as `user_management.php`). Residents cannot open this page for another user; self-service profile stays on `profile.php`.

### Layout (compact — sets the pattern Phase 4 will apply to `user_management.php`)

1. **Header**: profile picture, name, email, mobile, address, account status (active/locked), verification status.
2. **Actions bar**:
   - Reset Password, Lock/Unlock — visible to admin and staff.
   - Delete Account — visible to admin only.
   - All actions call the existing handlers in `config/user_admin.php` (no new business logic), and each already calls `logAudit()` on the action itself.
3. **Recent Reservations**: last 15–20, with status badges. "View all" link → `reservations_manage.php?requester_id={id}` (filtered).
4. **Violations History**: via `getUserViolations()`, with severity badges from `frs_violation_type_label()`.

### Audit logging (new)

- On page load, if the viewer is admin/staff viewing *someone else's* profile: `logAudit('view_profile', 'user_management', "Viewed resident #{id}")`.
- Self-view (`profile.php`) does not get this log entry — only cross-user views are audited.
- Existing action logs (reset/lock/delete) are already covered by `config/user_admin.php`'s existing `logAudit()` calls — verify during implementation that each action path still logs correctly when triggered from this new page (not just from `user_management.php`).

## Explicitly out of scope for this phase

- Clickable names in `reservations_manage.php` (Phase 2 — separate spec).
- Login/logout audit logging (Phase 3 — separate spec). Confirmed gap: `resources/views/pages/auth/login.php` logs only failure/security events via `logSecurityEvent()`, never a successful-login audit entry; `resources/views/pages/auth/logout.php` has no logging at all.
- `user_management.php` compact refactor (Phase 4 — separate spec, will reuse this page's layout/CSS pattern).
- Any change to the resident-facing compliance/data-export flow already present in `profile.php` (export history, account deletion messaging) — confirmed adequate for now, not touched by this phase.

## Testing

- Role gate: resident hitting `resident_profile.php?user_id=X` directly gets denied (403 or redirect), same as other admin-only dashboard pages.
- Reset/lock/delete from this page produce identical `audit_log` rows as when triggered from `user_management.php`.
- `view_profile` audit entry appears only for cross-user views, never for self-view.
- Reservation and violation lists correctly scope to the viewed `user_id`, not the logged-in admin's own data.
