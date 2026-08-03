# Admin/Staff Resident Profile Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a new admin/staff-only page, `resident_profile.php`, that shows a resident's details, profile picture, recent reservations, and violations history, and lets admin/staff perform the same reset-password / lock / unlock / delete actions that exist today only on `user_management.php` — with cross-user views and actions logged to `audit_log`.

**Architecture:** One new self-contained page file, following this codebase's established single-file pattern (top: session/role gate + POST action handling; middle: data fetch; bottom: HTML view via output buffering into `dashboard_layout.php`) — same structure as `user_management.php` and `reservations_manage.php`. No new tables, no new PHP classes: reuses `config/audit.php`, `config/violations.php`, `config/permissions.php`, and the exact SQL patterns already used by `user_management.php` for reset/lock/unlock/delete.

**Tech Stack:** PHP 8 + PDO/MySQL, session-based auth, no framework (plain-PHP page router in `index.php`).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-03-admin-resident-profile-design.md`.
- Page is admin+staff view; reset password and lock/unlock available to admin+staff (gated by `frs_can_update($actorRole, 'users')`, matching `user_management.php`); delete is gated by `frs_can_delete($actorRole, 'users')` (Admin-only per current `role_permissions` data — do not hardcode `'Admin'`, reuse the permission function).
- Recent reservations: last 15–20 only, with a "View all" link to `reservations_manage.php?requester_id={id}` — no inline pagination.
- Violations: full list via existing `getUserViolations($userId)` (already reverse-chronological, no separate pagination needed for a resident's typical volume).
- `logAudit('Viewed resident profile', 'User Management', ...)` fires only when the viewer is not viewing their own account (i.e., `$_SESSION['user_id'] !== $userId`).
- This codebase has no Feature/page-level test suite (only `tests/Unit/*` for pure helper functions, `phpunit.xml` only registers `tests/Unit`). Consistent with `user_management.php` and `reservations_manage.php` having no automated tests, this plan uses manual verification steps (documented per task) instead of PHPUnit tests for the page itself.
- CSRF: every POST action must call `frs_csrf_ok()` (from `config/security.php`) exactly like `user_management.php` does, and every form must include `csrf_field()`.

---

### Task 1: Route registration

**Files:**
- Modify: `index.php:195` (`$dashboardRouteMap` array)
- Modify: `routes\web.php:36` (`'dashboard'` array, placeholder route map)

**Interfaces:**
- Produces: clean URL `/dashboard/resident-profile?user_id={id}` resolving to `resources/views/pages/dashboard/resident_profile.php`.

- [ ] **Step 1: Add the route to `index.php`**

In `index.php`, inside the `$dashboardRouteMap` array (the block starting at line 179), add a new line immediately after the `'user-management' => 'user_management.php',` entry (line 195):

```php
        'resident-profile' => 'resident_profile.php',
```

- [ ] **Step 2: Add the route to `routes/web.php`**

In `routes/web.php`, inside the `'dashboard'` array, add a new line immediately after `'/dashboard/users' => 'resources/views/pages/dashboard/user_management.php',` (line 35):

```php
        '/dashboard/resident-profile' => 'resources/views/pages/dashboard/resident_profile.php',
```

- [ ] **Step 3: Verify manually**

Create an empty placeholder file so the route doesn't 404 before Task 2:

```bash
echo '<?php echo "placeholder";' > "resources/views/pages/dashboard/resident_profile.php"
```

Start the local PHP server if not already running, then visit `http://localhost:8000/dashboard/resident-profile?user_id=1` while logged in as any user. Expect to see the text `placeholder` render (proves the route resolves). Then remove the placeholder content — Task 2 replaces it.

- [ ] **Step 4: Commit**

```bash
git add index.php routes/web.php resources/views/pages/dashboard/resident_profile.php
git commit -m "feat: register resident-profile route"
```

---

### Task 2: Page skeleton — role gate, user lookup, view-audit log

**Files:**
- Modify: `resources/views/pages/dashboard/resident_profile.php` (replace placeholder from Task 1)

**Interfaces:**
- Consumes: `frs_can_read(string $role, string $permissionKey): bool`, `frs_can_update(...)`, `frs_can_delete(...)` from `config/permissions.php`; `logAudit(string $action, string $module, ?string $details, ?int $userId): void` from `config/audit.php`; `db(): PDO` from `config/database.php`.
- Produces: `$actorRole` (string), `$viewedUserId` (int), `$viewedUser` (array|false — row from `users` table, or `false` if not found), `$isSelfView` (bool). Later tasks read these.

- [ ] **Step 1: Write the skeleton**

```php
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$actorRole = $_SESSION['role'] ?? '';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($actorRole, 'users')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/violations.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/ui_helpers.php';

$pdo = db();
$currentUserId = $_SESSION['user_id'] ?? null;
$viewedUserId = (int)($_GET['user_id'] ?? 0);

if ($viewedUserId <= 0) {
    header('Location: ' . base_path() . '/dashboard/user-management');
    exit;
}

$userStmt = $pdo->prepare('SELECT id, name, email, mobile, address, role, status, lock_reason, is_verified, verified_at, profile_picture, created_at FROM users WHERE id = :id');
$userStmt->execute(['id' => $viewedUserId]);
$viewedUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$viewedUser) {
    header('Location: ' . base_path() . '/dashboard/user-management');
    exit;
}

$isSelfView = ($currentUserId !== null && (int)$currentUserId === $viewedUserId);

if (!$isSelfView) {
    logAudit('Viewed resident profile', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
}

$message = '';
$messageType = 'success';
```

- [ ] **Step 2: Verify manually**

Log in as a Resident and visit `/dashboard/resident-profile?user_id=2`. Expect redirect to `/dashboard` (permission denied — Residents lack `frs_can_read($actorRole, 'users')`).

Log in as Admin or Staff, visit `/dashboard/resident-profile?user_id=999999` (nonexistent id). Expect redirect to `/dashboard/user-management`.

Log in as Admin, visit `/dashboard/resident-profile?user_id={a-real-resident-id}`. Expect no redirect (blank page for now — no HTML yet). Check `audit_trail.php` (or query `audit_log` table directly) and confirm a new row: `action = 'Viewed resident profile'`, `module = 'User Management'`.

Then visit `/dashboard/resident-profile?user_id={your-own-admin-id}` (your own account). Confirm no new audit row is added for this self-view.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/dashboard/resident_profile.php
git commit -m "feat: add role gate and view-audit logging to resident profile page"
```

---

### Task 3: Reset password / lock / unlock / delete action handlers

**Files:**
- Modify: `resources/views/pages/dashboard/resident_profile.php`

**Interfaces:**
- Consumes: `$pdo`, `$actorRole`, `$currentUserId`, `$viewedUserId`, `$viewedUser` from Task 2. `getAccountLockedEmailTemplate()`, `sendEmail()` from `config/mail_helper.php`/`config/email_templates.php` (already required in Task 2).
- Produces: `$message` (string), `$messageType` ('success'|'error') set after handling a POST. Re-fetches `$viewedUser` after any action so the view (Task 4+) always shows fresh data.

- [ ] **Step 1: Add the POST handler block**

Insert immediately after the `$messageType = 'success';` line from Task 2:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!frs_csrf_ok()) {
        $message = 'Security check failed. Please try again.';
        $messageType = 'error';
    } elseif ($viewedUserId === (int)$currentUserId && in_array($_POST['action'], ['lock', 'delete'], true)) {
        $message = 'You cannot perform this action on your own account.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'];
        $permissionError = false;
        switch ($action) {
            case 'delete':
                if (!frs_can_delete($actorRole, 'users')) {
                    $permissionError = true;
                }
                break;
            case 'lock':
            case 'unlock':
                if (!frs_can_update($actorRole, 'users')) {
                    $permissionError = true;
                }
                break;
        }

        if ($permissionError) {
            $message = 'You do not have permission to perform this action.';
            $messageType = 'error';
        } else {
            switch ($action) {
                case 'lock':
                    $lockReason = trim($_POST['lock_reason'] ?? '');
                    $stmt = $pdo->prepare('UPDATE users SET status = "locked", lock_reason = :lock_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $viewedUserId, 'lock_reason' => $lockReason !== '' ? $lockReason : null]);
                    logAudit('Locked user account', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
                    $message = 'User account locked successfully.';
                    if (!empty($viewedUser['email'])) {
                        try {
                            $body = getAccountLockedEmailTemplate($viewedUser['name'], $lockReason);
                            sendEmail($viewedUser['email'], $viewedUser['name'], 'Account Locked', $body);
                        } catch (Exception $e) {
                            // ignore email failures here
                        }
                    }
                    break;

                case 'unlock':
                    $stmt = $pdo->prepare('UPDATE users SET status = "active", lock_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['id' => $viewedUserId]);
                    logAudit('Unlocked user account', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
                    $message = 'User account unlocked successfully.';
                    break;

                case 'reset_password':
                    $newPassword = bin2hex(random_bytes(6));
                    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 1, failed_login_attempts = 0, locked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->execute(['password_hash' => $newPasswordHash, 'id' => $viewedUserId]);
                    logAudit('Reset user password', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
                    $message = 'Password reset successfully. New credentials have been sent to the user\'s email.';
                    if (!empty($viewedUser['email'])) {
                        try {
                            $body = "<p>Hi " . htmlspecialchars($viewedUser['name']) . ",</p>"
                                  . "<p>Your password has been reset by an administrator. Here are your new login credentials:</p>"
                                  . "<div style='background: #f5f7fa; padding: 1rem; border-radius: 8px; margin: 1rem 0;'>"
                                  . "<p style='margin: 0.5rem 0;'><strong>Email:</strong> " . htmlspecialchars($viewedUser['email']) . "</p>"
                                  . "<p style='margin: 0.5rem 0;'><strong>New Password:</strong> <code style='background: #fff; padding: 0.25rem 0.5rem; border-radius: 4px; font-family: monospace;'>" . htmlspecialchars($newPassword) . "</code></p>"
                                  . "</div>"
                                  . "<p><strong>Important:</strong> For security reasons, please change your password immediately after logging in.</p>";
                            sendEmail($viewedUser['email'], $viewedUser['name'], 'Your Password Has Been Reset', $body);
                        } catch (Exception $e) {
                            error_log('Failed to send password reset email: ' . $e->getMessage());
                            $message .= ' However, the email could not be sent. Please provide the new password manually.';
                        }
                    }
                    break;

                case 'delete':
                    $deleteReason = trim($_POST['delete_reason'] ?? '');
                    if (strlen($deleteReason) < 10) {
                        $message = 'Please provide a deletion reason (at least 10 characters).';
                        $messageType = 'error';
                        break;
                    }
                    if (($viewedUser['role'] ?? '') === 'Admin') {
                        $adminCountStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Admin"');
                        if ((int)$adminCountStmt->fetchColumn() <= 1) {
                            $message = 'Cannot delete the only remaining administrator account.';
                            $messageType = 'error';
                            break;
                        }
                    }
                    logAudit('Deleted user account', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ') — Reason: ' . $deleteReason);
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $stmt->execute(['id' => $viewedUserId]);
                    header('Location: ' . base_path() . '/dashboard/user-management');
                    exit;

                default:
                    $message = 'Unknown action.';
                    $messageType = 'error';
            }
        }
    }

    if ($messageType === 'success' && $action !== 'delete') {
        $userStmt->execute(['id' => $viewedUserId]);
        $viewedUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
}
```

- [ ] **Step 2: Verify manually**

As Staff (assuming `role_permissions` grants Staff `can_update` but not `can_delete` on `users`, matching `user_management.php`'s current behavior — confirm this assumption against the live `role_permissions` table before testing):
- Submit the reset-password form (once added in Task 4) → expect success message, `audit_log` row `Reset user password`, and (if mail is configured) an email sent.
- Submit lock with a reason → expect `status` becomes `locked` in the `users` table, `audit_log` row `Locked user account`.
- Submit unlock → `status` back to `active`, `audit_log` row `Unlocked user account`.
- Attempt delete → expect `'You do not have permission to perform this action.'` (Staff lacks `can_delete`).

As Admin:
- Attempt delete with a reason under 10 characters → expect the length-validation error, no row deleted.
- Attempt delete with a valid reason on a non-Admin resident → expect redirect to `/dashboard/user-management`, row removed from `users`, `audit_log` row `Deleted user account`.
- Attempt to delete the only remaining Admin account → expect `'Cannot delete the only remaining administrator account.'`.
- Attempt lock/delete on your own account (`user_id` = your own id) → expect `'You cannot perform this action on your own account.'`.

Since there's no form yet (Task 4 adds it), drive these with `curl` for now, e.g.:

```bash
curl -i -b "PHPSESSID=<your-session-cookie>" -X POST "http://localhost:8000/dashboard/resident-profile?user_id=<id>" \
  --data-urlencode "action=lock" \
  --data-urlencode "lock_reason=Testing lock flow" \
  --data-urlencode "csrf_token=<token-from-page-source>"
```

(Grab `<your-session-cookie>` and `<token-from-page-source>` from an authenticated browser session's dev tools.)

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/dashboard/resident_profile.php
git commit -m "feat: add reset-password/lock/unlock/delete handlers to resident profile page"
```

---

### Task 4: View — header, actions bar, recent reservations, violations history

**Files:**
- Modify: `resources/views/pages/dashboard/resident_profile.php`

**Interfaces:**
- Consumes: `$viewedUser`, `$viewedUserId`, `$actorRole`, `$currentUserId`, `$message`, `$messageType` from Tasks 2–3. `getUserViolations(int $userId): array` from `config/violations.php`. `frs_page_title(string $title, ?string $tip): string` from `config/ui_helpers.php`. `frs_can_update()`, `frs_can_delete()` from `config/permissions.php`.
- Produces: rendered HTML page (terminal — no later task consumes this).

- [ ] **Step 1: Fetch recent reservations and violations, right before the HTML section**

```php
$reservationsStmt = $pdo->prepare(
    'SELECT r.id, r.reservation_date, r.time_slot, r.status, f.name AS facility_name
     FROM reservations r
     LEFT JOIN facilities f ON r.facility_id = f.id
     WHERE r.user_id = :user_id
     ORDER BY r.reservation_date DESC, r.id DESC
     LIMIT 20'
);
$reservationsStmt->execute(['user_id' => $viewedUserId]);
$recentReservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);

$violations = getUserViolations($viewedUserId);

$canUpdateUsers = frs_can_update($actorRole, 'users');
$canDeleteUsers = frs_can_delete($actorRole, 'users');
$base = base_path();
$profilePicUrl = !empty($viewedUser['profile_picture']) ? $base . $viewedUser['profile_picture'] : null;
$initials = '';
foreach (explode(' ', (string)$viewedUser['name']) as $part) {
    if ($part !== '') { $initials .= strtoupper($part[0]); }
    if (strlen($initials) >= 2) { break; }
}

ob_start();
```

- [ ] **Step 2: Write the HTML view**

```php
?>
<div class="dashboard-content">
    <?= frs_page_title('Resident Profile', 'View account details, reservation history, and violations.'); ?>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success'; ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <section class="booking-card">
        <div style="display:flex; gap:1.25rem; align-items:center; flex-wrap:wrap;">
            <div style="width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:1.5rem; <?= $profilePicUrl ? 'background-image:url(' . htmlspecialchars($profilePicUrl) . '); background-size:cover; background-position:center;' : 'background:linear-gradient(135deg, #2563eb, #1d4ed8);'; ?>">
                <?php if (!$profilePicUrl): ?><?= htmlspecialchars($initials ?: '?'); ?><?php endif; ?>
            </div>
            <div style="flex:1; min-width:200px;">
                <h2 style="margin:0 0 0.25rem;"><?= htmlspecialchars($viewedUser['name']); ?></h2>
                <p style="margin:0; color:#6b7280;"><?= htmlspecialchars($viewedUser['email']); ?> · <?= htmlspecialchars($viewedUser['mobile'] ?? '—'); ?></p>
                <p style="margin:0.25rem 0 0; color:#6b7280;"><?= htmlspecialchars($viewedUser['address'] ?? '—'); ?></p>
            </div>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <span class="status-badge <?= $viewedUser['status'] === 'active' ? 'active' : ($viewedUser['status'] === 'locked' ? 'offline' : 'pending'); ?>"><?= htmlspecialchars(ucfirst((string)$viewedUser['status'])); ?></span>
                <span class="status-badge <?= $viewedUser['is_verified'] ? 'active' : 'pending'; ?>"><?= $viewedUser['is_verified'] ? 'Verified' : 'Unverified'; ?></span>
            </div>
        </div>
    </section>

    <section class="booking-card">
        <h3 style="margin-top:0;">Account Actions</h3>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <?php if ($canUpdateUsers): ?>
                <form method="POST" style="display:inline;">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" class="btn-secondary" onclick="return confirm('Reset this user\'s password?')">Reset Password</button>
                </form>
                <?php if ($viewedUser['status'] === 'locked'): ?>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="unlock">
                        <button type="submit" class="btn-secondary">Unlock Account</button>
                    </form>
                <?php elseif ((int)$currentUserId !== $viewedUserId): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Lock this account?')">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="lock">
                        <input type="text" name="lock_reason" placeholder="Reason (optional)" style="padding:0.4rem;">
                        <button type="submit" class="btn-secondary">Lock Account</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canDeleteUsers && (int)$currentUserId !== $viewedUserId): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this account permanently?')">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="text" name="delete_reason" placeholder="Deletion reason (min 10 chars)" required minlength="10" style="padding:0.4rem; min-width:220px;">
                    <button type="submit" class="btn-secondary" style="color:#b23030;">Delete Account</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="booking-card">
        <h3 style="margin-top:0;">Recent Reservations</h3>
        <?php if ($recentReservations === []): ?>
            <p style="color:#8b95b5;">No reservations found.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Facility</th><th>Date</th><th>Time</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($recentReservations as $res): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($res['facility_name'] ?? '—')); ?></td>
                            <td><?= htmlspecialchars((string)$res['reservation_date']); ?></td>
                            <td><?= htmlspecialchars((string)$res['time_slot']); ?></td>
                            <td><span class="status-badge <?= htmlspecialchars((string)$res['status']); ?>"><?= htmlspecialchars(ucfirst((string)$res['status'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><a href="<?= htmlspecialchars($base . '/dashboard/reservations-manage?requester_id=' . $viewedUserId); ?>">View all reservations for this resident →</a></p>
        <?php endif; ?>
    </section>

    <section class="booking-card">
        <h3 style="margin-top:0;">Violations History</h3>
        <?php if ($violations === []): ?>
            <p style="color:#8b95b5;">No violations recorded.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Type</th><th>Severity</th><th>Facility</th><th>Recorded</th><th>Description</th></tr></thead>
                <tbody>
                    <?php foreach ($violations as $v): ?>
                        <tr>
                            <td><?= htmlspecialchars(frs_violation_type_label((string)$v['violation_type'])); ?></td>
                            <td><span class="status-badge <?= in_array($v['severity'], ['high', 'critical'], true) ? 'offline' : 'pending'; ?>"><?= htmlspecialchars(ucfirst((string)$v['severity'])); ?></span></td>
                            <td><?= htmlspecialchars((string)($v['facility_name'] ?? '—')); ?></td>
                            <td><?= htmlspecialchars((string)$v['created_at']); ?></td>
                            <td><?= htmlspecialchars((string)($v['description'] ?? '—')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
```

- [ ] **Step 3: Verify manually**

Visit `/dashboard/resident-profile?user_id={id}` as Admin. Confirm:
- Header shows name/email/mobile/address, correct status/verified badges, profile picture (or initials fallback if none).
- Reset Password / Lock / Unlock buttons appear for Admin and Staff; Delete only for Admin.
- Recent Reservations table shows up to 20 rows, newest first, "View all" link present when non-empty.
- Violations History table shows rows from `user_violations` for that user, or the empty-state message.
- Submitting each action form round-trips correctly (re-verify the Task 3 manual checks now via the actual UI instead of `curl`).

- [ ] **Step 4: Commit**

```bash
git add resources/views/pages/dashboard/resident_profile.php
git commit -m "feat: render resident profile view with actions, reservations, and violations"
```

---

## Plan Self-Review

**Spec coverage:**
- New page, admin+staff access — Task 2. ✓
- Header (pic/details/status/verified) — Task 4. ✓
- Actions bar (reset/lock/unlock admin+staff, delete admin-only) — Tasks 3–4. ✓
- Recent reservations (15–20) + "View all" link — Task 4. ✓
- Violations history — Task 4. ✓
- `view_profile` audit log on cross-user view only — Task 2. ✓
- Existing action audit logs reused, not reimplemented — Task 3 calls the same `logAudit()` messages `user_management.php` uses. ✓
- Role gate denies residents — Task 2. ✓
- Out of scope confirmed: no changes to `reservations_manage.php` (Phase 2), `login.php`/`logout.php` (Phase 3), or `user_management.php` (Phase 4) — none touched by this plan. ✓

**Column check:** confirmed `reservations.user_id` is the correct FK to `users.id` (verified against `reservations_manage.php:76,433,446,497,504` — all join via `JOIN users u ON r.user_id = u.id`). Task 4's query already uses this column; no changes needed.
