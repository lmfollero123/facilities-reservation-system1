# UMAN Integration Page Redesign — Design Spec

**Date:** 2026-08-11
**File in scope:** `resources/views/pages/dashboard/utilities_integration.php` (template/markup only)

## Goal

Replace the current inline-style, ad-hoc-CSS UI on the UMAN Integration page (both "Equipment & Requests" and "Utility Readings" tabs) with a modern Tailwind-based UI, consistent with the existing Tailwind pattern already established in `resources/views/pages/dashboard/blackout_dates.php` (the only other dashboard page using Tailwind utility classes today).

**Explicitly out of scope:** no PHP logic changes, no new database queries, no new POST actions, no change to existing JS behavior (pin-asset flow, consumption preview), no tab-switching behavior change (stays server-rendered `<a href>` links with `?tab=` query param, full page reload — this is required because add/edit/delete-reading POST handlers already redirect via `$umanTabUrl('readings')` and rely on a real page load to show the flash message).

## Visual language

Match `blackout_dates.php`'s established pattern:
- Cards: `rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden`, header strip `border-b border-slate-100 bg-slate-50/50 px-4 sm:px-6 py-4`.
- Palette: slate for neutral text/borders, emerald for primary actions (existing `.btn-primary` class), amber for warning/CIMM-style badges, red for error/delete, sky/blue for informational.
- Icons: Bootstrap Icons (`bi-*`), already loaded dashboard-wide.
- Alert/message boxes: `rounded-xl border px-4 py-3 text-sm flex items-start gap-3` with `bi-check-circle` / `bi-exclamation-circle` / `bi-info-circle`, colored per type (emerald/amber/red), replacing the current inline `style="background:...;color:...;border:..."` blocks.
- Tables: wrapped in the existing `.table-responsive` + `.table` classes stay (don't invent a new table system), but header cells get `text-slate-500 text-xs font-semibold uppercase tracking-wide`, rows get `hover:bg-slate-50`, status/urgency values become pill badges: `inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium` with per-status color (emerald=success/operational/synced, amber=pending/priority, red=emergency/failed, slate=routine/default).

## Page-wide changes

1. **Tab bar** (`booking-hub-tabs`): restyle as a segmented control — `inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1`, active tab `bg-white shadow-sm text-slate-900 font-semibold rounded-lg`, inactive `text-slate-500 hover:text-slate-700`. Same two `<a>` links, same `$umanTabUrl()` hrefs.
2. **Message banner** (`$message`/`$messageType`): replace the inline-hex-color block with the Tailwind alert pattern above.
3. **Connection-status notices** (API key missing / request-only mode): replace inline-style blocks with the same Tailwind alert pattern, amber for "not configured", sky/blue for "request-only mode" — same conditions (`!$apiKeyConfigured`, `!$catalogLive`), same copy.
4. **Stat-card row**, one per tab, computed entirely from existing PHP variables (no new queries):
   - **Equipment tab** (shown above the request form): 3 cards — "UMAN Connection" (Live/Request-only/Offline, from `$integrationStatus['sync_status']`), "Assets in Catalog" (`$integrationStatus['asset_count']`), "Pending Requests" (`$integrationStatus['pending_requests']`).
   - **Readings tab** (shown above the add/edit form): 3 cards — "Readings This Month" (count of `$utilityLatestReadings` entries where `year`/`month` match current, over total facilities — new small inline PHP computation, no query), "Facilities Covered" (`count($utilityLatestReadings)` of `count($utilityFacilities)`), "Last Sync Issue" (count where `sync_status === 'failed'`, or "All synced" if zero).
   - Card style: `rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3` with a colored icon badge (`h-10 w-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center`) + label/value stack.

## Equipment tab

1. **Request form card**: header "Request Asset from UMAN" + helper text (unchanged copy). Fields reorganized into clear grid rows, all always visible (no collapsing):
   - Row 1 (2-col grid, `sm:grid-cols-2 gap-4`): Facility, Asset/Equipment Type.
   - Row 2 (3-col grid): Quantity, Urgency, Date Needed.
   - Pinned-asset banner: restyle as a sky-colored alert card (`rounded-xl border border-sky-200 bg-sky-50`), same fields/behavior (exact-match checkbox, Clear button), same element IDs (JS reads them by ID — must not change `id="..."` attributes).
   - Row 3 (2-col grid): Booking Reference, Responsible Office.
   - Row 4 (full width): Event/Purpose.
   - Row 5 (full width): Notes.
   - Live-catalog/fallback notice: small inline badge-style note (emerald if live, slate if fallback), same conditional copy.
   - Submit button: keep `.btn-primary`, same disabled/title logic when API key missing.
2. **Facility Equipment Summary aside**: restyle the list as `divide-y divide-slate-100` rows with facility name + count pill (emerald if >0, slate if 0). Sync button restyled as an outline button with a refresh icon (`bi-arrow-repeat`).
3. **Asset catalog table**: keep clickable-row behavior and all `data-*` attributes (JS depends on them) — only restyle classes/colors. Tip line above table becomes a small info alert. Status becomes a pill badge. "Use this" button restyled as a small emerald outline pill button.
4. **Asset Requests table**: restyle as a Tailwind table per the shared table styling above. Urgency becomes a pill badge (red=Emergency, amber=Priority, slate=Routine) replacing the inline `style="color:..."` span. Status becomes a pill badge.

## Readings tab

1. **Description banner**: restyle as a plain informational paragraph (no box needed) or a light slate info card — keep copy unchanged.
2. **Add/Edit Reading form card**: header restyled, same fields/order/IDs (JS reads `utility-facility-select`, `utility-prev-kwh`, etc. by ID — must not change). Electricity and Water sections become bordered sub-cards (`rounded-xl border border-slate-200 p-4`) with an icon+label header (⚡ amber accent, 💧 sky accent) replacing the current `<fieldset><legend>`. Consumption preview (`#utility-consumption-preview`) becomes a highlighted result box (`rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-emerald-800 font-semibold text-sm`) instead of a bare colored paragraph.
3. **Readings history table**: Tailwind table styling per shared pattern. Sync status becomes a pill badge (emerald=synced, red=failed, amber=pending), same `title` tooltip for sync errors. Edit/Delete actions become small icon buttons (`bi-pencil`, `bi-trash`) with the same hrefs/confirm dialog.

## Implementation notes

- All changes confined to the HTML/markup between `ob_start()` and `ob_get_clean()` in `utilities_integration.php` — no changes above that line (PHP data-prep logic untouched).
- Every existing `id="..."` attribute referenced by the two `<script>` blocks (pin-asset flow, consumption preview) must be preserved exactly — only `class`/inline-`style` attributes change.
- Every existing `name="..."` form field attribute must be preserved exactly (POST handlers read `$_POST['...']` by these names).
- No new files, no new CSS. Tailwind scans `.php` files directly (`tailwind.config.js` → `content: ['./resources/views/**/*.php', ...]`), so any utility class used here (including less-common ones like `divide-y`) will be picked up automatically — but the compiled `public/css/tailwind.css` must be rebuilt after editing (`npm run build:css`) for the new classes to actually appear; this is the final step of implementation, not optional.
