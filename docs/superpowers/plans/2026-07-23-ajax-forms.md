# AJAX Form Submission Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Opt-in AJAX POST submission for dashboard forms — region re-render in place, success toasts, inline errors — with phase-1 conversion of the approvals page and the My Reservations modal actions.

**Architecture:** Extend the existing `public/js/frs-partial-update.js` (the app's proven GET region-swapper) with `form[data-frs-ajax]` POST interception via fetch+FormData, reusing its extraction/swap/inline-script-re-execution/`frs:partial-loaded` machinery. A new `config/flash_helper.php` standardizes flash messages: `X-FRS-Toast` response header for AJAX submits, existing `data-frs-toast-message` DOM channel for full loads. Spec: `docs/superpowers/specs/2026-07-23-ajax-forms-design.md`.

**Tech Stack:** Vanilla JS (ES2017+, IIFE style matching the file), plain PHP 8.1, PHPUnit for the helper.

## Global Constraints

- Attribute names, exact: `data-frs-ajax` (opt-in), `data-frs-ajax-target="<region-id>"` (explicit region override), `data-frs-ajax-close="<selector>"` (hide element on success).
- Request headers on AJAX form POST, exact: `Accept: text/html`, `X-FRS-Partial: <targetId>`, `X-Requested-With: FRSAjaxForm`.
- Response toast header, exact: `X-FRS-Toast` = `rawurlencode(json_encode(['message' => ..., 'type' => 'success'|'error']))`.
- Session flash key: `$_SESSION['frs_flash']` = `['message' => string, 'type' => 'success'|'error']` (single slot).
- Toast API: `window.frsShowToast(message, type)`; types `'success'`/`'error'` only.
- Network-failure toast copy, verbatim: `Connection problem — your changes were not saved. Please try again.`
- POST swaps never `pushState`. Unconverted forms and JS-off browsers must behave exactly as today (progressive enhancement; on any resolution failure, fall through to native submit).
- CPRF repo root, commits on `main`. All new PHP output escaped with `htmlspecialchars()`; prepared statements for any SQL (none expected in this plan).

---

### Task 1: Flash helper (TDD) + layout emission point

**Files:**
- Create: `config/flash_helper.php`
- Modify: `resources/views/layouts/dashboard_layout.php:133-136` (toast stack block)
- Modify: `tests/bootstrap.php` (add require)
- Test: `tests/Unit/FlashHelperTest.php`

**Interfaces:**
- Consumes: `$_SESSION`, `headers_sent()`, existing toast stack markup `#frsToastStack` with `.frs-toast-preset` divs (see `dashboard_layout.php:133-135` for the `$loginToast` example this mirrors).
- Produces (Tasks 3–4 call these): `frs_flash_success(string $msg): void`, `frs_flash_error(string $msg): void`, `frs_flash_take(): ?array`, `frs_flash_build_toast_header(array $flash): string` (pure — unit tested), `frs_flash_is_ajax_form_request(): bool`, `frs_flash_emit(): void` (returns nothing; either sends the header or echoes a preset div).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/FlashHelperTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FlashHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        unset($_SERVER['HTTP_X_FRS_PARTIAL'], $_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    public function test_flash_set_and_take_round_trip(): void
    {
        frs_flash_success('Saved!');
        $this->assertSame(['message' => 'Saved!', 'type' => 'success'], frs_flash_take());
    }

    public function test_take_clears_the_slot(): void
    {
        frs_flash_error('Nope');
        frs_flash_take();
        $this->assertNull(frs_flash_take());
    }

    public function test_last_write_wins(): void
    {
        frs_flash_success('first');
        frs_flash_error('second');
        $this->assertSame(['message' => 'second', 'type' => 'error'], frs_flash_take());
    }

    public function test_build_toast_header_encodes_json(): void
    {
        $value = frs_flash_build_toast_header(['message' => 'Réservation saved — 100%', 'type' => 'success']);
        $decoded = json_decode(rawurldecode($value), true);
        $this->assertSame('Réservation saved — 100%', $decoded['message']);
        $this->assertSame('success', $decoded['type']);
    }

    public function test_ajax_form_request_detection(): void
    {
        $this->assertFalse(frs_flash_is_ajax_form_request());
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'FRSAjaxForm';
        $this->assertTrue(frs_flash_is_ajax_form_request());
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $_SERVER['HTTP_X_FRS_PARTIAL'] = 'some-region';
        $this->assertTrue(frs_flash_is_ajax_form_request());
    }
}
```

- [ ] **Step 2: Wire bootstrap and verify failure**

In `tests/bootstrap.php`, append:

```php
require_once $root . '/config/flash_helper.php';
```

Run: `vendor/bin/phpunit tests/Unit/FlashHelperTest.php`
Expected: FAIL — file not found / undefined function.

- [ ] **Step 3: Create `config/flash_helper.php`**

```php
<?php
/**
 * Session-backed flash messages with two delivery channels:
 *  - AJAX form submits (X-Requested-With: FRSAjaxForm / X-FRS-Partial header):
 *    an X-FRS-Toast response header the client turns into frsShowToast().
 *  - Full page loads: a .frs-toast-preset div in #frsToastStack that
 *    frs-toast.js already promotes to a toast on DOMContentLoaded.
 *
 * Standardizes the older per-page patterns (inline $message vars, ad-hoc
 * session keys like booking_flash). Pages migrate by calling
 * frs_flash_success()/frs_flash_error() instead of their local pattern.
 */

declare(strict_types=1);

function frs_flash_set(string $message, string $type): void
{
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
        session_start();
    }
    $_SESSION['frs_flash'] = ['message' => $message, 'type' => $type === 'error' ? 'error' : 'success'];
}

function frs_flash_success(string $message): void
{
    frs_flash_set($message, 'success');
}

function frs_flash_error(string $message): void
{
    frs_flash_set($message, 'error');
}

/** Read and clear the slot. */
function frs_flash_take(): ?array
{
    $flash = $_SESSION['frs_flash'] ?? null;
    unset($_SESSION['frs_flash']);
    return is_array($flash) && isset($flash['message'], $flash['type']) ? $flash : null;
}

/** Pure: header value for X-FRS-Toast. */
function frs_flash_build_toast_header(array $flash): string
{
    return rawurlencode((string)json_encode([
        'message' => (string)($flash['message'] ?? ''),
        'type' => ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success',
    ]));
}

/** True when the request came from the AJAX form layer (or a partial GET). */
function frs_flash_is_ajax_form_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'FRSAjaxForm'
        || ($_SERVER['HTTP_X_FRS_PARTIAL'] ?? '') !== '';
}

/**
 * Deliver a pending flash. Call from the layout at the toast-stack render
 * point: on AJAX requests it sends the X-FRS-Toast header (output is still
 * buffered there, so headers_sent() is normally false); on full loads it
 * echoes a preset div inside #frsToastStack.
 */
function frs_flash_emit(): void
{
    $flash = frs_flash_take();
    if ($flash === null) {
        return;
    }
    if (frs_flash_is_ajax_form_request() && !headers_sent()) {
        header('X-FRS-Toast: ' . frs_flash_build_toast_header($flash));
        return;
    }
    echo '<div class="frs-toast-preset" data-frs-toast-message="'
        . htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8')
        . '" data-frs-toast-type="'
        . htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8')
        . '" hidden></div>';
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/Unit/FlashHelperTest.php`
Expected: PASS (5 tests). Then full suite: `vendor/bin/phpunit` — all green (18 total).

- [ ] **Step 5: Emit from the layout**

In `resources/views/layouts/dashboard_layout.php`, the toast stack block (line ~133) currently reads:

```php
<div class="frs-toast-stack" id="frsToastStack" aria-live="polite">
    <?php if (...$loginToast...): ?>
        <div class="frs-toast-preset" data-frs-toast-message="..." ...></div>
    <?php endif; ?>
```

Add, inside the `#frsToastStack` div, after the existing `$loginToast` block (keep that block untouched):

```php
    <?php
    require_once dirname(__DIR__, 3) . '/config/flash_helper.php';
    frs_flash_emit();
    ?>
```

(`__DIR__` for the layout is `<root>/resources/views/layouts`, so three levels up is the repo root — `dirname(__DIR__, 3) . '/config/flash_helper.php'`.)

- [ ] **Step 6: Lint + commit**

Run: `php -l config/flash_helper.php && php -l resources/views/layouts/dashboard_layout.php`
Expected: clean.

```bash
git add config/flash_helper.php resources/views/layouts/dashboard_layout.php tests/bootstrap.php tests/Unit/FlashHelperTest.php
git commit -m "Add standardized flash helper with AJAX header and DOM toast channels"
```

---

### Task 2: POST support in `frs-partial-update.js`

**Files:**
- Modify: `public/js/frs-partial-update.js` (~270 lines; additions only — do not alter existing GET behavior)

**Interfaces:**
- Consumes: existing internals `resolveTarget(partialId)`, `extractPartial(html, partialId)`, `executeInlineScripts(target)`, `dispatchLoaded(partialId, target)`, `setLoading(target, on)` (all defined in this file); `window.frsShowToast(message, type)`; optional `window.frsFocusFirstInvalid()`.
- Produces: submit interception for `form[data-frs-ajax]` per the Global Constraints; used by Tasks 3–4 markup.

- [ ] **Step 1: Add the POST module**

Insert the following block into `public/js/frs-partial-update.js` immediately after the existing GET `submit` listener (the one ending `loadPartial(buildUrlFromForm(form), partialId);` around line 244), keeping the file's IIFE style:

```javascript
    // ---- AJAX POST forms (opt-in via data-frs-ajax) --------------------
    // Progressive enhancement: on ANY resolution failure we return without
    // preventDefault so the browser performs a normal full-page submit.

    function ajaxFormTargetId(form) {
        const explicit = (form.getAttribute('data-frs-ajax-target') || '').trim();
        if (explicit) return explicit;
        const region = form.closest('[data-frs-partial-id]');
        return region ? region.getAttribute('data-frs-partial-id') : '';
    }

    function setSubmitting(form, on) {
        form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])').forEach(function (btn) {
            btn.disabled = on;
            btn.setAttribute('aria-busy', on ? 'true' : 'false');
        });
    }

    function readToastHeader(resp) {
        const raw = resp.headers.get('X-FRS-Toast');
        if (!raw) return null;
        try {
            const parsed = JSON.parse(decodeURIComponent(raw));
            if (parsed && typeof parsed.message === 'string') {
                return { message: parsed.message, type: parsed.type === 'error' ? 'error' : 'success' };
            }
        } catch (err) {
            console.error('frsAjaxForm toast header', err);
        }
        return null;
    }

    function closeOnSuccess(form) {
        const selector = (form.getAttribute('data-frs-ajax-close') || '').trim();
        if (!selector) return;
        document.querySelectorAll(selector).forEach(function (el) {
            el.style.display = 'none';
        });
        document.body.style.overflow = '';
    }

    async function submitAjaxForm(form, submitter) {
        const targetId = ajaxFormTargetId(form);
        const target = resolveTarget(targetId);
        if (!target) return false; // caller falls back to native submit

        const body = new FormData(form);
        if (submitter && submitter.name) {
            body.append(submitter.name, submitter.value || '');
        }

        form.setAttribute('data-frs-ajax-busy', '1');
        setSubmitting(form, true);
        setLoading(target, true);

        try {
            const resp = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                body: body,
                headers: {
                    Accept: 'text/html',
                    'X-FRS-Partial': targetId,
                    'X-Requested-With': 'FRSAjaxForm',
                },
            });
            const html = await resp.text();
            const fragment = extractPartial(html, targetId);
            if (fragment === null) {
                // Login page, fatal error page, unexpected layout: never strand the user.
                window.location.href = resp.url || window.location.href;
                return true;
            }
            const toast = readToastHeader(resp);
            target.innerHTML = fragment;
            executeInlineScripts(target);
            dispatchLoaded(targetId, target);
            if (toast) {
                if (typeof window.frsShowToast === 'function') {
                    window.frsShowToast(toast.message, toast.type);
                }
                if (toast.type === 'success') {
                    closeOnSuccess(form);
                }
            }
            if (!toast || toast.type === 'error') {
                if (typeof window.frsFocusFirstInvalid === 'function') {
                    window.frsFocusFirstInvalid();
                }
            }
            if (target.getBoundingClientRect().top < 0) {
                target.scrollIntoView({ block: 'nearest' });
            }
            return true;
        } catch (err) {
            console.error('frsAjaxForm', err);
            if (typeof window.frsShowToast === 'function') {
                window.frsShowToast('Connection problem — your changes were not saved. Please try again.', 'error');
            }
            return true; // handled (do not native-resubmit a possibly-applied action)
        } finally {
            form.removeAttribute('data-frs-ajax-busy');
            setSubmitting(form, false);
            setLoading(target, false);
        }
    }

    document.addEventListener('submit', function (e) {
        const form = e.target.closest('form[data-frs-ajax]');
        if (!form) return;
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return;
        if (!window.fetch || !window.FormData || !window.DOMParser) return; // native submit
        if (form.hasAttribute('data-frs-ajax-busy')) {
            e.preventDefault(); // double-submit guard
            return;
        }
        const targetId = ajaxFormTargetId(form);
        if (!targetId || !resolveTarget(targetId)) {
            console.warn('frsAjaxForm: no swap region for form; submitting normally', form);
            return; // native submit
        }
        e.preventDefault();
        submitAjaxForm(form, e.submitter || null);
    });
```

- [ ] **Step 2: Syntax check**

Run: `node --check public/js/frs-partial-update.js` (if node is unavailable on this machine, paste the file into the browser console via `new Function(fileContents)` on any dashboard page, or rely on Step 3's live check).
Expected: no syntax errors.

- [ ] **Step 3: Live smoke of unchanged GET behavior**

With the app served locally, load `/dashboard/reservations-manage` and click a pagination/search partial link — region still swaps, no console errors (proves the additions didn't disturb the GET path).

- [ ] **Step 4: Commit**

```bash
git add public/js/frs-partial-update.js
git commit -m "Add opt-in AJAX POST form submission to the partial-update layer"
```

---

### Task 3: Convert reservation approvals (`reservations_manage.php`)

**Files:**
- Modify: `resources/views/pages/dashboard/reservations_manage.php` (message div ~663-667; region open ~685; POST modal forms at ~1066 `#reviewDecisionForm`, ~1091 `#modifyForm`, ~1157 `#postponeForm`, ~1208 `#cancelForm`, ~1243 `#staffRescheduleForm`; POST handling block ends ~line 351)

**Interfaces:**
- Consumes: Task 1 `frs_flash_success(string): void`; Task 2 attributes `data-frs-ajax`, `data-frs-ajax-target`, `data-frs-ajax-close`.
- Produces: converted approvals flow (later phases copy this recipe).

- [ ] **Step 1: Route successes through the flash helper**

Near the top of the file (after the other `require_once` lines), add:

```php
require_once __DIR__ . '/../../../../config/flash_helper.php';
```

Immediately after the POST-handling block completes (after the `catch` that sets `$message = $e->getMessage(); $messageType = 'error';` around line 350-352, at the point where `$message` is final), add:

```php
if ($message !== '' && $messageType === 'success') {
    frs_flash_success($message);
    $message = '';
}
```

Result: successes become toasts (both AJAX and full loads); `$message` now only ever renders errors inline.

- [ ] **Step 2: Move the inline message div inside the swap region**

Cut the message block at ~663-667:

```php
<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="...">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
```

and paste it as the FIRST child inside `<div data-frs-partial-id="ra-approvals-main" data-frs-partial-root>` (region opens at ~685), so error messages appear in the re-rendered region after an AJAX submit.

- [ ] **Step 3: Mark the five POST forms**

For each form, add three attributes. The `data-frs-ajax-close` selector is the form's enclosing modal container — find it with: the form's ancestor `div` whose `id` ends in `Modal` (e.g. `#reviewDecisionForm` sits inside `#reviewDecisionModal`; verify each of the other four the same way with a quick look at the surrounding markup — `grep -n 'Modal"' resources/views/pages/dashboard/reservations_manage.php`).

```html
<form method="POST" id="reviewDecisionForm"
      data-frs-ajax
      data-frs-ajax-target="ra-approvals-main"
      data-frs-ajax-close="#reviewDecisionModal"
      action="...unchanged...">
```

Repeat for `#modifyForm`, `#postponeForm`, `#cancelForm`, `#staffRescheduleForm` with their own modal ids.

- [ ] **Step 4: Modal-close residue check**

These modals are opened by page JS that may set `document.body.style.overflow='hidden'` and `aria-hidden` attributes. Verify (read the page's modal open/close JS) that hiding the container via `display:none` + restoring body overflow (what `data-frs-ajax-close` does) matches what the page's own close button does; if the close routine does more (e.g. clears `aria-hidden` or form fields), add a small inline handler on `frs:partial-loaded` OR point `data-frs-ajax-close` at the same element the page's close routine hides. Keep whatever the page's own close path does authoritative.

- [ ] **Step 5: Lint + live UAT**

Run: `php -l resources/views/pages/dashboard/reservations_manage.php` — clean.
Live (Admin login): approve a pending reservation from the review modal → modal closes, list refreshes in place (no reload), green toast; deny without a reason (if the page validates it) → inline red message inside the region; double-click approve → single action recorded; disable JS (browser devtools) → approve still works via full reload.

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/dashboard/reservations_manage.php
git commit -m "Convert reservation approval actions to AJAX submission"
```

---

### Task 4: Convert My Reservations actions + migrate `booking_flash`

**Files:**
- Modify: `resources/views/pages/dashboard/book_facility.php` (flash read ~65-67; flash writes ~712 and ~916)
- Modify: `resources/views/pages/dashboard/includes/reservations_hub_mine_tab.php` and/or the modal markup files containing the cancel/reschedule/edit-details POST forms (locate: `grep -rn "cancelReservationModal\|rescheduleModal\|editDetailsModal" resources/views/pages/dashboard/includes/`)
- Possibly: `resources/views/pages/dashboard/includes/reservations_mine_post_handlers.php` (success message routing)

**Interfaces:**
- Consumes: Task 1 flash functions; Task 2 attributes. The mine pane's swap region id is `mine-calendar` (existing; see `frs-partial-update.js:87`).
- Produces: AJAX cancel/reschedule/edit actions in My Reservations.

- [ ] **Step 1: Migrate `booking_flash` to the shared helper**

In `book_facility.php` add near the top requires: `require_once __DIR__ . '/../../../../config/flash_helper.php';`

Replace the two writes (~712 and ~916):

```php
$_SESSION['booking_flash'] = ['msg' => $success, 'type' => 'success'];
```
with:
```php
frs_flash_success($success);
```

Replace the read block (~65-67):
```php
if (!empty($_SESSION['booking_flash']) && is_array($_SESSION['booking_flash'])) {
    $success = (string)($_SESSION['booking_flash']['msg'] ?? '');
    unset($_SESSION['booking_flash']);
}
```
with nothing (delete it — `$success` keeps its `''` initialization at line 62; the toast now comes from `frs_flash_emit()` in the layout). Search the whole repo for any other `booking_flash` references (`grep -rn booking_flash`) and remove/adapt stragglers.

- [ ] **Step 2: Investigate the mine action handlers' result flow**

Read `resources/views/pages/dashboard/includes/reservations_mine_post_handlers.php` top-to-bottom once. Decision rules:
- If a handler ends in a redirect (PRG): route its success text through `frs_flash_success()` before the redirect — the toast then arrives on the follow-up GET (works for both AJAX and full submissions).
- If a handler renders inline (sets a variable displayed by the mine pane): confirm the variable renders INSIDE the `mine-calendar` region; if it renders outside, move that message markup inside the region (same maneuver as Task 3 Step 2); route successes through `frs_flash_success()` + clear the inline var (errors stay inline).

- [ ] **Step 3: Mark the mine action forms**

For each POST form in the cancel / reschedule / edit-details modals:

```html
<form method="POST"
      data-frs-ajax
      data-frs-ajax-target="mine-calendar"
      data-frs-ajax-close="#cancelReservationModal"
      ...>
```

(`data-frs-ajax-close` = that form's own modal id; the day-list modal `#dayReservationsModal` is re-mounted by `mountMineDayModal()` on every `mine-calendar` swap — `frs-partial-update.js:138-141` — so the refreshed list appears without extra work. If after testing the old day modal remains open showing stale rows, extend `data-frs-ajax-close` to also hide it: `data-frs-ajax-close="#cancelReservationModal, #dayReservationsModal"` — the attribute takes any `querySelectorAll` selector.)

- [ ] **Step 4: Lint + live UAT**

`php -l` on every touched file — clean. Live (resident login with an approved upcoming reservation): cancel from My Reservations → modal closes, calendar/list refreshes in place, green toast; reschedule to a conflicting slot (validation error) → inline error visible inside the pane; JS disabled → cancel still works full-page; book a facility (main form, unconverted) → normal reload, success TOAST now appears via the migrated flash (was inline green box before).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/dashboard/book_facility.php resources/views/pages/dashboard/includes/
git commit -m "Convert My Reservations actions to AJAX; migrate booking_flash to shared helper"
```

---

### Task 5: Regression pass

**Files:** none — verification only.

- [ ] **Step 1: Unit suite**

`vendor/bin/phpunit` — all green (18 tests).

- [ ] **Step 2: Regression checklist (live app)**

1. GET partial links (approvals pagination/search, booking calendar month nav) still swap without reloads.
2. An unconverted form (e.g. Energy Efficiency add-reading, System Settings) still full-page submits and shows its message exactly as before.
3. Login toast (`$loginToast` preset) still appears after login.
4. Session-expiry path: log out in a second tab, then submit an approval via AJAX → browser lands on the login page (fragment-missing fallback).
5. Back/forward buttons after several AJAX submits: no resubmit prompts, page state sane (POST never pushed history).
6. `php -l` clean on all touched PHP; no console errors on the two converted pages.

- [ ] **Step 3: Update ledger/commit any fixes**

Any defect found loops back to its task; when clean, this phase is done (later pages convert by the Task 3 recipe).
