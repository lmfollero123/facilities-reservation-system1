# Resident Profile Phases 2-4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task (small, low-risk, no subagents needed). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire up the resident-profile page built in Phase 1: clickable requester names, close the login/logout audit gap, and visually densify `user_management.php` to match the new page's compact feel.

**Architecture:** Three independent, small edits to existing files — no new files, no new tables, no logic changes to Phase 3/4 targets beyond the specific lines touched.

**Tech Stack:** PHP 8 + PDO/MySQL, inline CSS (this codebase keeps page-specific CSS in `<style>` blocks inside the page file itself, e.g. `user_management.php:1160-1262`).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-03-resident-profile-phases-2-4-design.md`.
- Phase 4 is visual/layout only — no changes to `user_management.php`'s POST action-handling logic (lines 1-460ish).
- No test suite covers page-level rendering in this codebase (confirmed in Phase 1) — verification here is manual/visual plus `php -l` syntax checks and the existing `phpunit` suite (must stay green, no regressions expected since none of these touch tested helper functions).

---

### Task 1: Clickable resident names in reservations_manage.php

**Files:**
- Modify: `resources/views/pages/dashboard/reservations_manage.php:894,1052,2021`

- [ ] **Step 1: Pending list (line 894)**

```php
                                    <span class="ra-cell-primary"><?= htmlspecialchars((string)$reservation['requester']); ?></span>
```
becomes:
```php
                                    <span class="ra-cell-primary">
                                        <?php if (!empty($reservation['requester_id'])): ?>
                                            <a href="<?= htmlspecialchars(base_path() . '/dashboard/resident-profile?user_id=' . (int)$reservation['requester_id']); ?>"><?= htmlspecialchars((string)$reservation['requester']); ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars((string)$reservation['requester']); ?>
                                        <?php endif; ?>
                                    </span>
```

- [ ] **Step 2: Approved list (line 1052)** — same replacement, applied to the `$reservation['requester']` / `$reservation['requester_id']` pair at that line.

- [ ] **Step 3: Detail modal (line 2021)**

```php
                            <p style="margin:0 0 0.75rem; color: #4a5568;"><strong>Requester:</strong> <?= htmlspecialchars($record['requester']); ?></p>
```
becomes:
```php
                            <p style="margin:0 0 0.75rem; color: #4a5568;"><strong>Requester:</strong>
                                <?php if (!empty($record['requester_id'])): ?>
                                    <a href="<?= htmlspecialchars(base_path() . '/dashboard/resident-profile?user_id=' . (int)$record['requester_id']); ?>"><?= htmlspecialchars($record['requester']); ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($record['requester']); ?>
                                <?php endif; ?>
                            </p>
```

- [ ] **Step 4: Verify**

```bash
php -l resources/views/pages/dashboard/reservations_manage.php
```
Expected: `No syntax errors detected`. Then confirm `$reservation['requester_id']` and `$record['requester_id']` are actually populated by their respective SELECT queries (already confirmed in Phase 1 exploration: `u.id AS requester_id` selected at lines 73 and 442) — no query changes needed.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/dashboard/reservations_manage.php
git commit -m "feat: link resident names to their profile page in reservations manage"
```

---

### Task 2: Login/logout audit logging

**Files:**
- Modify: `config/security.php:669-699` (`frs_complete_authenticated_login`)
- Modify: `resources/views/pages/auth/logout.php`

**Interfaces:**
- Consumes: `logAudit(string $action, string $module, ?string $details, ?int $userId): void` from `config/audit.php`.

- [ ] **Step 1: Add require + log call inside `frs_complete_authenticated_login`**

In `config/security.php`, immediately after the `$_SESSION['last_activity'] = time();` line (line 679):

```php
    require_once __DIR__ . '/audit.php';
    logAudit('Login', 'Authentication', ($user['name'] ?? 'Unknown') . ' (' . ($user['email'] ?? 'unknown') . ')');
```

This fires for all 5 login paths that call this function (`login.php`, `login_setup_2fa.php` ×2, `login_otp.php`, `sso_consume.php`) with one change.

- [ ] **Step 2: Add logout logging**

In `resources/views/pages/auth/logout.php`, the POST handler currently reads:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!frs_csrf_ok()) {
        header('Location: ' . base_path() . '/login?error=csrf');
        exit;
    }

    // If this session originated from a Main LGU SSO launch, send the admin
    // back to the SSO hub instead of this system's own login page.
    $returnToMainLgu = !empty($_SESSION['sso_from_mainlgu']);

    session_unset();
    session_destroy();
```

Change to:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!frs_csrf_ok()) {
        header('Location: ' . base_path() . '/login?error=csrf');
        exit;
    }

    // If this session originated from a Main LGU SSO launch, send the admin
    // back to the SSO hub instead of this system's own login page.
    $returnToMainLgu = !empty($_SESSION['sso_from_mainlgu']);

    require_once __DIR__ . '/../../../../config/audit.php';
    $logoutUserName = $_SESSION['user_name'] ?? 'Unknown';
    $logoutUserEmail = $_SESSION['user_email'] ?? 'unknown';
    logAudit('Logout', 'Authentication', $logoutUserName . ' (' . $logoutUserEmail . ')');

    session_unset();
    session_destroy();
```

- [ ] **Step 3: Verify**

```bash
php -l config/security.php
php -l resources/views/pages/auth/logout.php
```
Expected: `No syntax errors detected` for both. Manual check (no local server in this environment — flag for your pass): log in, confirm a new `audit_log` row `action = 'Login', module = 'Authentication'`; log out, confirm `action = 'Logout', module = 'Authentication'`.

- [ ] **Step 4: Commit**

```bash
git add config/security.php resources/views/pages/auth/logout.php
git commit -m "feat: log successful login/logout to audit_log"
```

---

### Task 3: Compact visual pass on user_management.php

**Files:**
- Modify: `resources/views/pages/dashboard/user_management.php:1191-1234` (inline `<style>` block only — no PHP/markup changes)

- [ ] **Step 1: Reduce card padding and avatar size**

```css
.um-user-card { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(260px, 0.8fr); gap: 1rem; padding: 1rem 1.1rem; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
.um-user-main { display: flex; gap: 0.85rem; min-width: 0; }
.um-avatar { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #6384d2, #285ccd); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
```
becomes:
```css
.um-user-card { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(260px, 0.8fr); gap: 0.75rem; padding: 0.65rem 0.85rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
.um-user-main { display: flex; gap: 0.65rem; min-width: 0; }
.um-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #6384d2, #285ccd); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; font-size: 0.85rem; }
```

- [ ] **Step 2: Tighten vertical rhythm in the info column**

```css
.um-user-title h3 { margin: 0; font-size: 1.05rem; color: #0f172a; }
.um-email { margin: 0.15rem 0 0.5rem; color: #64748b; font-size: 0.9rem; word-break: break-word; }
.um-badges { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.45rem; }
```
becomes:
```css
.um-user-title h3 { margin: 0; font-size: 0.95rem; color: #0f172a; }
.um-email { margin: 0.1rem 0 0.35rem; color: #64748b; font-size: 0.82rem; word-break: break-word; }
.um-badges { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-bottom: 0.3rem; }
```

- [ ] **Step 3: Tighten the list gap and violations block spacing**

```css
.um-user-list { display: flex; flex-direction: column; gap: 0.85rem; }
```
becomes:
```css
.um-user-list { display: flex; flex-direction: column; gap: 0.55rem; }
```

```css
.um-violations { margin-top: 0.65rem; padding-top: 0.65rem; border-top: 1px dashed #e2e8f0; }
```
becomes:
```css
.um-violations { margin-top: 0.45rem; padding-top: 0.45rem; border-top: 1px dashed #e2e8f0; }
```

- [ ] **Step 4: Tighten the action-side column padding**

```css
.um-user-side { display: flex; flex-direction: column; gap: 0.65rem; border-left: 1px solid #eef2f7; padding-left: 1rem; }
```
becomes:
```css
.um-user-side { display: flex; flex-direction: column; gap: 0.5rem; border-left: 1px solid #eef2f7; padding-left: 0.85rem; }
```

- [ ] **Step 5: Verify**

```bash
php -l resources/views/pages/dashboard/user_management.php
```
Expected: `No syntax errors detected`. Manual visual check (your pass, no local server here): open `/dashboard/user-management`, confirm rows are visibly denser than before, no overlapping text/badges, mobile breakpoint (`max-width: 1100px`/`720px` media queries, unchanged) still stacks correctly.

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/dashboard/user_management.php
git commit -m "style: densify user management account cards"
```

---

## Plan Self-Review

**Spec coverage:**
- Phase 2 — all 3 name-render sites linked, defensive on missing `requester_id`. ✓
- Phase 3 — single choke-point fix covering all 5 login paths, logout logged before session destroy. ✓
- Phase 4 — visual-only, no action-handling logic touched, matches confirmed scope decision. ✓
- Nothing added beyond spec (no new tables/permissions, per spec's "Out of scope"). ✓

**Type/name consistency:** `requester_id` used consistently (matches Phase 1 exploration's confirmed column alias); `logAudit()` signature matches `config/audit.php:17` exactly in both Task 2 call sites.
