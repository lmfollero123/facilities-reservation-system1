# React Calendar Island — Phase 1

## Problem

The booking calendar inside `resources/views/pages/dashboard/book_facility.php` (a 5000+ line PHP file) is the single most stateful, most fragile piece of UI in the app: server-rendered HTML fragments swapped via a generic partial-AJAX mechanism, two independent debounced side-channel AJAX calls (conflict-check, AI purpose-hints) that reach into the DOM to toggle classes, and mutable global JS state (`window._bcfCalYear`/`_bcfCalMonth`). This pattern is exactly what React's declarative rendering replaces cleanly. This spec covers introducing React as a scoped **island** for just the calendar — not a rewrite of the page or the app.

## Current system (verified against code — see prior conversation's Explore agent report for full file:line citations)

- **Partial-AJAX mechanism** (`public/js/frs-partial-update.js`): `data-frs-partial-id="bcf-calendar"` marks the swap target (`book_facility.php:2254`); the toolbar form (`data-frs-partial="bcf-calendar" data-frs-partial-auto`, line 2257) and "Today" link (line 2290) trigger a `fetch()` of the *full page*, extract the matching `data-frs-partial-id` fragment's `innerHTML`, and swap it in, with `history.pushState` keeping the URL in sync.
- **Day-cell rendering** (`book_facility.php:2319-2410`): server-computed tone (`frs_facility_calendar_matrix()`), demand level (`PredictionService::getFacilityDemandForecast`), and holiday flag (`HolidayService`) per ISO date, rendered as classes/attributes on each cell.
- **Day-cell click** (`book_facility.php:5007-5037`): delegated listener → `activateBookingCalDate(cell)` → sets hidden date input, opens `#booking-flow-modal`, calls `checkConflict()` after 200ms. No navigation.
- **`checkConflict()`** (`book_facility.php:4342-4498`): debounced POST to `/dashboard/ai-conflict-check`, drives conflict/warning/risk messaging in the modal. Stays entirely as-is.
- **AI purpose-hints** (`book_facility.php:3397-3513`): debounced POST to `/dashboard/booking-smart-hints` off the purpose textarea; response adds `.bcf-ai-suggest-date` class directly to `[data-cal-date]` elements for the purple "AI-suggested" ring.
- **Reservation submission**: full-page POST handled inline in the same file (`book_facility.php:261+`). Untouched by this spec.
- **Global state**: `window._bcfCalYear`/`window._bcfCalMonth`, written from PHP on load and re-synced from the URL after each partial reload; read by the AI-hints POST body and a calendar-refocus link.

## Phase 1 scope

React owns:
1. The calendar grid (day cells, tones, demand, holidays).
2. Facility/month/year navigation (replacing the toolbar form + "Today" link for this region only).
3. The day-click → open-modal handoff (calling existing vanilla functions, not reimplementing them).

React does **not** own: conflict-checking, the AI-hints fetch/debounce logic itself (only consumes its output), reservation submission, or the "My Reservations" tab.

## New backend: one JSON endpoint

New file, own route: `resources/views/pages/dashboard/book-facility-calendar-data.php`.

- Reuses the exact same PHP functions the current fragment calls today: `frs_facility_calendar_matrix()`, the demand-forecast builder, `HolidayService`. No duplicated business logic — this endpoint is a thin JSON serialization of data already computed elsewhere.
- Request: `GET` with `facility_id`, `year`, `month` query params (same auth/session checks as the existing dashboard routes).
- Response: JSON array of per-day objects — `{date: "YYYY-MM-DD", tone: "green|yellow|red|blackout|maintenance", demand: "low|medium|high|very_high"|null, is_holiday: bool, holiday_name: string|null, is_pickable: bool, is_today: bool}`.
- The existing `bcf-calendar` HTML-partial route is left in place, untouched, for Phase 1 — removing it is explicitly deferred to a later phase once the island is proven live. This keeps rollback to a single-file revert of `book_facility.php`'s markup.

## React component contract

Single component, mounted into `<div id="bcf-calendar-root">` (replacing the current server-rendered calendar block in `book_facility.php`, but the toolbar's facility/month/year selects are also replaced — they move inside the React tree so state and rendering stay in one place).

**Reads on mount** (from data attributes on the root div, populated by PHP): initial `facility_id`, `year`, `month` (same values PHP reads from `$_GET` today, so deep links/back-forward still resolve to the same view), and the facility list (id + name) needed to populate the facility dropdown.

**Internal behavior:**
- On facility/month/year change (via its own dropdowns) or on mount, fetches `book-facility-calendar-data.php` and renders the grid from the response.
- Keeps its own `{facilityId, year, month}` state; calls `history.pushState` on change so the URL stays in sync (mirroring what `frs-partial-update.js` does today for this region, but done directly rather than via that shared mechanism, since React now owns this DOM subtree).

**Its outward bridges (the only three ways other code touches it):**
1. **Day click →** calls the existing global `activateBookingCalDate`-equivalent function (the vanilla code already in `book_facility.php` that sets the date input, opens the modal, and schedules `checkConflict()`). React triggers it; does not reimplement it.
2. **`window.bcfCalendarSetHighlights(isoDates)`** — new function the component exposes. `bcfApplyCalendarAiHints()` (existing AI-hints code) calls this instead of touching `classList` directly, so the purple "AI-suggested" ring is applied through React's own state/render instead of two systems fighting over the same DOM nodes.
3. **`window.bcfCalendarGetState()`** — returns `{year, month, facilityId}`. Replaces direct reads of `window._bcfCalYear`/`_bcfCalMonth` elsewhere in the file (the AI-hints POST body, the calendar-refocus link in AI hints output).

No other integration points exist or are needed — conflict-check and submission never read calendar internals directly today (confirmed by the earlier structural investigation), so nothing else needs a bridge.

## Motion

motion.dev (React-native animation library) handles exactly two things inside the island, both restrained:
- Day cells fade/slide in when the month changes (replacing the current instant full-fragment swap).
- The AI-suggested purple ring animates in when `bcfCalendarSetHighlights` adds it, rather than popping instantly.

Nothing else in the island animates. GSAP (used elsewhere in the app — public pages, hero, carousel) is untouched and continues to own everything outside this island; the two libraries never touch the same DOM subtree.

## Build setup

- New source directory: `resources/react/booking-calendar/` (plain JSX, no TypeScript — matches the untyped style of the rest of the codebase).
- New `vite.config.js` at repo root, building to `public/js/dist/booking-calendar.js` (+ associated CSS if any).
- `book_facility.php` adds one `<script type="module" src=".../dist/booking-calendar.js">` tag and the `#bcf-calendar-root` mount div, replacing the current server-rendered calendar markup and toolbar at that spot.
- **No server-side build step.** The live deploy is `git pull` only (confirmed — no Node/build tooling exists on the server). The built bundle (`public/js/dist/booking-calendar.js`) is committed to git like any other static asset; `npm run build` runs locally (or in CI, if added later) before each commit that changes the island's source.

## Rollback safety

- `pre-react-calendar-backup-2026-09-04` git tag (already created, both on GitHub and the live server) reverts everything if needed.
- Because the old HTML-partial route and its PHP rendering logic are left in place (not deleted) in Phase 1, a narrower rollback — reverting just `book_facility.php`'s calendar markup/script-tag changes — restores the exact old behavior without touching the tag or any other file.

## Out of scope (explicitly deferred to a later phase)

- Removing the old `bcf-calendar` HTML-partial route and its now-unused PHP rendering code.
- Bringing conflict-checking or AI-hints fetch/debounce logic itself into React.
- Any change to reservation submission, the "My Reservations" tab, or any other dashboard page.
- TypeScript, testing framework setup, or CI build automation (build stays manual/local for Phase 1).

## Files touched / added

- **New:** `resources/views/pages/dashboard/book-facility-calendar-data.php` (JSON endpoint)
- **New:** `resources/react/booking-calendar/` (React source — entry component, subcomponents as needed)
- **New:** `vite.config.js` (repo root)
- **New:** `package.json` additions (React, ReactDOM, motion, Vite — dependencies only, no existing scripts touched)
- **New:** `public/js/dist/booking-calendar.js` (built output, committed)
- **Modified:** `resources/views/pages/dashboard/book_facility.php` (replace calendar block + toolbar markup with mount div + script tag; existing `activateBookingCalDate`/`checkConflict`/`bcfApplyCalendarAiHints`/AI-hints functions modified only at their DOM-touching edges to call the new bridge functions instead)
