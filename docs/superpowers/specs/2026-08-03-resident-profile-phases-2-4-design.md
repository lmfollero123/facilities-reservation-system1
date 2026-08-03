# Resident Profile — Phases 2-4 Design

Continuation of `docs/superpowers/specs/2026-08-03-admin-resident-profile-design.md` (Phase 1, shipped). Three small, independent pieces, per the agreed order.

## Phase 2 — Clickable resident names

In `resources/views/pages/dashboard/reservations_manage.php`, wrap the requester name in an `<a>` to `resident_profile.php?user_id={requester_id}` at all three render sites:
- Pending list, line 894 (`$reservation['requester']`, id via `$reservation['requester_id']`).
- Approved list, line 1052 (same fields).
- Detail modal, line 2021 (`$record['requester']`, id via `$record['requester_id']`).

Link only renders when `requester_id` is a positive int (defensive — some legacy/manual reservations may lack a linked user). Admin/staff only see this link; nothing changes for residents viewing their own reservations (this page is already admin/staff-only).

## Phase 3 — Login/logout audit logging

Confirmed gap: `login.php` never calls `logAudit()` on success — only `logSecurityEvent()`. `logout.php` has no logging at all. Fix at the single choke point rather than each call site: all 5 login paths (`login.php`, `login_setup_2fa.php` ×2, `login_otp.php`, `sso_consume.php`) converge on `frs_complete_authenticated_login($user)` in `config/security.php:669`. Add one `logAudit('Login', 'Authentication', ...)` call there, right after `$_SESSION['user_id']` is set (so `logAudit`'s session-based default `$userId` resolves correctly) — covers every login path with one change, DRY.

`logout.php` destroys the session at line 17-18; add `logAudit('Logout', 'Authentication', ...)` immediately before `session_unset()`, while `$_SESSION['user_name']`/`user_id` are still readable.

## Phase 4 — Compact visual refactor of user_management.php

Scope confirmed: **visual/layout only**, no structural split of the 1410-line file. Apply the same compact pattern established in `resident_profile.php` (Phase 1): `booking-card` sections, `status-badge` for status/role/verification pills, denser row spacing. No change to the POST action-handling logic (top half of the file) — restyle the HTML/view half only. Existing action flows (approve/verify/lock/unlock/reset/delete/change_role) keep their current behavior; only markup/CSS changes.

## Out of scope

No new tables, no new permission rules — Phases 2-4 reuse everything already in place from Phase 1 and the existing codebase.
