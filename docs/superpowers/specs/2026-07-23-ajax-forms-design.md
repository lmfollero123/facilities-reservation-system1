# AJAX Form Submission Layer — Design

**Date:** 2026-07-23
**Status:** Approved (pending user spec review)
**Scope:** CPRF repo only. One shared client mechanism + one shared server helper + phase-1 conversion of the booking and approvals flows.

## 1. Goal

Dashboard POST flows submit without full page reloads: the form's region re-renders in place, success shows as a toast, validation errors show inline. The mechanism is generic and **opt-in per form** (`data-frs-ajax`), rolled out page-by-page. Unconverted forms keep today's behavior exactly; JS-disabled browsers fall back to normal submission (progressive enhancement).

## 2. Architectural decision

**Extend the existing `public/js/frs-partial-update.js`** — the app's proven AJAX substrate (GET region swaps via `[data-frs-partial-id]`, `DOMParser` extraction, inline-`<script>` re-execution via clone-and-replace, `frs:partial-loaded` event + `window.frsOnPartialLoaded` callback, pushState/popstate handling). It gains POST form support.

Rejected: a whole-`.dashboard-content` swapper (re-introduces the full soft-swap approach `dashboard-navigation.js`'s authors deliberately rejected — style/handler re-binding problems) and per-action JSON endpoints (bespoke JS for ~60 pages; massive scope for no phase-1 benefit).

## 3. Client — additions to `frs-partial-update.js`

### 3.1 Interception

- Document-level `submit` listener (bubble phase — required so page-level validation listeners' `preventDefault()` runs first and is visible to the intercept, consistent with the file's existing delegation style).
- Handles only `form[data-frs-ajax]` whose method is POST (attribute or default). GET forms keep the existing `form[data-frs-partial]` path untouched.
- The form must be inside a `[data-frs-partial-id]` region — the swap target is the **closest** such ancestor — OR name its region explicitly via `data-frs-ajax-target="<region-id>"` (required for forms inside modals that the partial layer re-mounts onto `<body>`, outside any region). If neither resolves: `console.warn` once and let the browser submit normally (never break a form).
- Optional `data-frs-ajax-close="<selector>"`: after a successful submit (toast type `success`), hide the matched element (`style.display='none'`) and restore `document.body.style.overflow` — declarative modal closing.
- If `window.fetch`/`FormData`/`DOMParser` are unavailable, do nothing (native submit).

### 3.2 Submission

- `fetch(form.action || location.href, { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { 'Accept': 'text/html', 'X-FRS-Partial': targetId, 'X-Requested-With': 'FRSAjaxForm' } })`.
- `FormData` carries file inputs (multipart) and the CSRF hidden field with zero extra handling. If a submit button carries a `name`, append its name/value to the FormData (native submit includes the clicked button; `FormData(form)` does not).
- **Double-submit guard:** on submit, set all the form's submit buttons `disabled` + `aria-busy="true"` and remember their labels; re-enable + restore in a `finally`. A second submit while in flight is ignored.

### 3.3 Response handling

- The server responds with its normal HTML (fetch transparently follows PRG redirects; the final response body is the rendered page).
- Parse with `DOMParser`; extract `[data-frs-partial-id="<targetId>"]`; swap via the existing swap routine so **inline-script re-execution and `frs:partial-loaded`/`frsOnPartialLoaded` dispatch behave identically to GET swaps**.
- **Toast:** if the final response carries an `X-FRS-Toast` header (value: URL-encoded JSON `{"message": "...", "type": "success"|"error"}`), call `window.frsShowToast(message, type)` after the swap. Absence of the header means no toast (inline messages inside the swapped region cover errors).
- **History:** POST results do NOT `pushState` (re-rendering the same URL's region; avoids resubmit-on-back semantics).
- If the fragment is missing from the response (unexpected layout, auth redirect to login, fatal error page): fall back to a full navigation to the final `response.url` — never leave the user stuck.
- Network failure / non-OK without parseable fragment: `frsShowToast('Connection problem — your changes were not saved. Please try again.', 'error')`, re-enable the form, leave user input intact.

### 3.4 Focus & scroll

- After an error swap (toast header with `type=error`, or no toast header), call `window.frsFocusFirstInvalid()` if present (existing helper in `frs-form-validation.js`).
- After swap, if the region's top is above the viewport, scroll it into view (`scrollIntoView({block:'nearest'})`).

## 4. Server — new `config/flash_helper.php`

One small helper standardizing the three existing flash patterns (inline `$message`/`$messageType`, ad-hoc session keys like `booking_flash`, toast data-attributes):

- `frs_flash_success(string $msg): void` / `frs_flash_error(string $msg): void` — store `['message' => $msg, 'type' => ...]` in `$_SESSION['frs_flash']` (single slot, last-write-wins).
- `frs_flash_take(): ?array` — read + clear the slot.
- AJAX detection is `X-Requested-With: FRSAjaxForm` only — GET partial loads (`X-FRS-Partial`) never read the toast header, so they must not consume the flash.
- Emission is two-phase, since HTTP headers must be sent before any HTML output:
  - `frs_flash_emit_header(): void` — called from the layout's PHP prologue, before its first byte of output. If the request is an AJAX form submit and headers can still be sent and a flash exists: send header `X-FRS-Toast: <rawurlencode(json)>` and consume the flash. Otherwise consume nothing.
  - `frs_flash_emit(): void` — called by the dashboard layout at the toast-stack render point, for full page loads. If a flash still exists (the header phase didn't consume it), render it through the existing DOM toast channel (`data-frs-toast-message`/`data-frs-toast-type` on the `#frsToastStack` element) so full-page loads keep working with zero page code.
- Coexistence: pages not yet migrated keep their current patterns untouched. Migration = replace the page's ad-hoc flash write with `frs_flash_success()`/`frs_flash_error()`.
- The layout's toast stack rendering stays behind `frs_flash_emit()` (backward compatible: it still honors any page that sets the old data-attributes directly); `frs_flash_emit_header()` runs earlier, before the layout emits any output.

**Error convention:** validation errors that must appear inline next to the form stay exactly as they are today (inline `$message`/`$messageType` render inside the region — the swap shows them). `frs_flash_error()` is for errors that suit a toast (e.g., rate-limited, generic failure). Success messages always go through `frs_flash_success()` on converted forms.

## 5. Phase-1 conversions

### 5.1 `book_facility.php` — My Reservations actions only (scope amended during planning)

- The cancel/reschedule/edit action forms in the My Reservations pane (modals the partial layer mounts to `<body>`) are marked `data-frs-ajax` with `data-frs-ajax-target="mine-calendar"` and `data-frs-ajax-close` pointing at their modal.
- `$_SESSION['booking_flash']` writes become `frs_flash_success()`/`frs_flash_error()` (benefits full-load toasts immediately).
- **The main booking submit form is deferred to phase 2** (user decision 2026-07-23): its pane depends on large inline-script blocks that live outside any swappable region; converting it safely first requires relocating those scripts into a region — a refactor with real regression risk on the app's most important form. It is PRG today and reloads on success anyway.

### 5.2 `reservations_manage.php` (approvals)

- Approve / deny / hold / postpone action forms marked `data-frs-ajax`; the reservations table/list wrapped in a `data-frs-partial-id` region so the row list refreshes in place after each action.
- Success messages via `frs_flash_success()`; denial-reason validation errors stay inline in the region.

### 5.3 Explicitly out of phase 1

Energy Efficiency module, facility/user management, announcements, profile, system settings — all convert later by the same recipe (mark forms + region + flash helper). No auth, routing, or session changes anywhere.

## 6. Error handling summary

| Scenario | Behavior |
|---|---|
| Validation error | Region re-renders with existing inline red message; focus first invalid field |
| Success | Region re-renders with fresh data; green toast |
| Session expired mid-submit | Fragment missing from login-page response → full navigation to `response.url` (login) |
| Network down / server 500 without fragment | Error toast; form re-enabled; user input preserved |
| Double click | Second submit ignored (buttons disabled while in flight) |
| JS disabled / old browser / form outside a region | Native full-page submit (today's behavior) |

## 7. Testing

- **Unit (PHPUnit, `tests/Unit/FlashHelperTest.php`):** flash set/take round-trip, take clears slot, emit header encoding (assert via a testable "build header value" function — pure `frs_flash_build_toast_header(array): string`).
- **Manual UAT per converted page:** submit success (toast + region refresh, no reload), validation error (inline message + focus), upload booking document, double-click submit (single reservation created), cancel/reschedule from My Reservations, approve/deny from approvals with list refreshing, session-expiry fallback to login, and one full-page (non-AJAX) load confirming toasts still render via the DOM channel.
- **Regression:** existing GET partial links (calendar, tabs) unchanged; unconverted forms across the dashboard behave exactly as before.

## 8. Out of scope

Public-site forms, auth pages, JSON APIs, converting the remaining dashboard pages (later phases), optimistic UI, and any change to `dashboard-navigation.js`.
