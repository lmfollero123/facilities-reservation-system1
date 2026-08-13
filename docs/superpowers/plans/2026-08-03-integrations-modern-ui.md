# Modern UI for Integration Dashboards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the 4 integration dashboard pages (UMAN utilities, maintenance, infrastructure projects, energy efficiency) a modern, consistent, mobile-safe visual layer without touching any other dashboard page or any PHP business logic.

**Architecture:** A single optional CSS modifier class (`integrations-modern`) is appended to the existing `<section class="dashboard-content">` wrapper in `dashboard_layout.php`, driven by a `$dashboardContentClass` PHP variable each target page sets. All new CSS lives in the existing `public/css/dashboard-pages.css`, scoped under `.integrations-modern` so it's inert on the other 26 dashboard pages that reuse the same base class names (`.booking-card`, `.table`, `.status-badge`, `.btn-primary`). No new stylesheet, no new class renames of existing markup.

**Tech Stack:** Plain CSS (existing custom-property design tokens from `public/css/style.css`), PHP (no framework), no JS changes.

## Global Constraints

- Presentation-only: no changes to PHP business logic, POST handlers, DB queries, or any `id`/`name` attribute a form/JS/PHP handler depends on.
- No other dashboard page's rendered output may change (verify: `$dashboardContentClass` unset ⇒ byte-identical `<section>` tag).
- Reuse existing shared classes and CSS custom properties (`--gov-blue`, `--bg-*`, `--text-*`, `--shadow*`, `--border-color`, `--border-light`) — no new color palette.
- `.table` inside `.table-responsive` already has a mobile card-stacking mechanism in `style.css` (`@media (max-width: 480px)`, driven by `td::before { content: attr(data-label) }`) — reuse it by adding `data-label` attributes; do not write new mobile-table CSS.
- `.btn-primary` and `.status-badge` are already modern (gradient/shadow/hover on buttons, pill-shaped badges) — do not restyle them.
- Only replace inline `style="..."` attributes that either (a) hardcode colors that won't adapt to `[data-theme="dark"]`, or (b) use a fixed multi-column grid that doesn't collapse on mobile. Leave other inline styles alone (no blanket sweep).

---

### Task 1: Shared plumbing + base component CSS + utilities_integration.php

**Files:**
- Modify: `resources/views/layouts/dashboard_layout.php:146`
- Modify: `public/css/dashboard-pages.css` (append new block at end of file)
- Modify: `resources/views/pages/dashboard/utilities_integration.php:33` (add variable), `:270-315` (form grids/fields), `:404-453` (asset catalog table), `:465-513` (asset requests table)

**Interfaces:**
- Produces: CSS class contract used by all later tasks —
  - `.integrations-modern` — set via `$dashboardContentClass` PHP variable, applied to the `<section class="dashboard-content">` wrapper
  - `.integration-form-row`, `.integration-form-row--2`, `.integration-form-row--3` — responsive grid replacing inline `style="display:grid;grid-template-columns:..."`
  - `.integration-field` — replaces inline `style="width:100%;padding:0.5rem;border-radius:6px;"` on inputs/selects (border/background/color still come from the existing global `input[type=...], select` tag rules in `style.css`, so dark mode keeps working)
  - `data-label="<th text>"` attribute convention on every `<td>` inside `.table-responsive .table > tbody > tr` — the existing global CSS in `style.css` (`@media (max-width: 480px) { .table-responsive .table td::before { content: attr(data-label); } }`) already stacks these into cards on mobile; no new CSS needed for this.

- [ ] **Step 1: Add the modifier-class hook to the shared layout**

Edit `resources/views/layouts/dashboard_layout.php` line 146, from:

```php
    <section class="dashboard-content dashboard-fade-in">
```

to:

```php
    <section class="dashboard-content dashboard-fade-in <?= htmlspecialchars($dashboardContentClass ?? '', ENT_QUOTES, 'UTF-8'); ?>">
```

- [ ] **Step 2: Verify no other page is affected**

Run: `php -l resources/views/layouts/dashboard_layout.php`
Expected: `No syntax errors detected`

Run: `grep -rl "dashboardContentClass" resources/views/pages/dashboard/` (should return nothing yet — confirms no page sets it, so every page renders with a trailing empty string, i.e. unchanged output)

- [ ] **Step 3: Append the scoped base CSS block to `public/css/dashboard-pages.css`**

Append at the end of the file:

```css

/* ============================================================
   Integrations Modern UI
   Scoped to pages that set $dashboardContentClass =
   'integrations-modern' before including dashboard_layout.php.
   Inert everywhere else — see
   docs/superpowers/specs/2026-08-03-integrations-modern-ui-design.md
   ============================================================ */

.integrations-modern .page-header {
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color, #e0e6ed);
    margin-bottom: 1.5rem;
}

.integrations-modern .booking-card {
    border: 1px solid var(--border-light, #f0f2f5);
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.integrations-modern .booking-card:hover {
    box-shadow: 0 12px 28px var(--shadow-hover, rgba(0, 0, 0, 0.12));
}

/* Responsive field grid — replaces ad hoc inline grid-template-columns
   that didn't collapse on mobile. */
.integrations-modern .integration-form-row {
    display: grid;
    gap: 0.75rem;
}

.integrations-modern .integration-form-row--2 {
    grid-template-columns: 1fr 1fr;
}

.integrations-modern .integration-form-row--3 {
    grid-template-columns: 1fr 1fr 1fr;
    margin-top: 0.75rem;
}

@media (max-width: 640px) {
    .integrations-modern .integration-form-row--2,
    .integrations-modern .integration-form-row--3 {
        grid-template-columns: 1fr;
    }
}

/* Field sizing only — border/background/color stay owned by the global
   input/select tag rules in style.css so dark mode keeps working. */
.integrations-modern .integration-field {
    width: 100%;
    padding: 0.5rem;
    border-radius: 6px;
}

.integrations-modern label {
    display: block;
    font-size: 0.9rem;
    color: var(--text-secondary, #5b6888);
}
```

- [ ] **Step 4: Wire the modifier class into utilities_integration.php**

Edit `resources/views/pages/dashboard/utilities_integration.php` line 33, from:

```php
$pageTitle = 'UMAN Integration | LGU Facilities Reservation';
```

to:

```php
$pageTitle = 'UMAN Integration | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';
```

- [ ] **Step 5: Replace the two ad hoc inline grids in the request form with `.integration-form-row`**

Edit `resources/views/pages/dashboard/utilities_integration.php`. Find (around line 270):

```php
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <label>
                    Facility *
                    <select name="facility_id" id="f_facility_id" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
```

Replace with:

```php
            <div class="integration-form-row integration-form-row--2">
                <label>
                    Facility *
                    <select name="facility_id" id="f_facility_id" required class="integration-field">
```

Find (around line 283, still inside the same `<div>`):

```php
                    <select name="asset_type" id="f_asset_type" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
```

Replace with:

```php
                    <select name="asset_type" id="f_asset_type" required class="integration-field">
```

Find (around line 298):

```php
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;margin-top:0.75rem;">
                <label style="display:block;">
                    Quantity
                    <input type="number" name="quantity" id="f_quantity" min="1" max="99" value="1" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="display:block;">
                    Urgency
                    <select name="urgency" id="f_urgency" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <option value="Routine">Routine (3–5 days)</option>
                        <option value="Priority">Priority (1–2 days)</option>
                        <option value="Emergency">Emergency (same day)</option>
                    </select>
                </label>
                <label style="display:block;">
                    Date Needed
                    <input type="date" name="date_needed" id="f_date_needed" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;" min="<?= date('Y-m-d'); ?>">
                </label>
            </div>
```

Replace with:

```php
            <div class="integration-form-row integration-form-row--3">
                <label>
                    Quantity
                    <input type="number" name="quantity" id="f_quantity" min="1" max="99" value="1" class="integration-field">
                </label>
                <label>
                    Urgency
                    <select name="urgency" id="f_urgency" class="integration-field">
                        <option value="Routine">Routine (3–5 days)</option>
                        <option value="Priority">Priority (1–2 days)</option>
                        <option value="Emergency">Emergency (same day)</option>
                    </select>
                </label>
                <label>
                    Date Needed
                    <input type="date" name="date_needed" id="f_date_needed" class="integration-field" min="<?= date('Y-m-d'); ?>">
                </label>
            </div>
```

- [ ] **Step 6: Add `data-label` to the asset-catalog table so it stacks on mobile**

Edit `resources/views/pages/dashboard/utilities_integration.php` around line 436-448. Headers are (in order): `Code`, `Name`, `Type`, `Status`, `Location`, `Responsible Office`, `` (blank action column). From:

```php
                            <td><strong style="font-family:monospace;"><?= htmlspecialchars($code); ?></strong></td>
                            <td><?= htmlspecialchars($name); ?></td>
                            <td><?= htmlspecialchars($type); ?></td>
                            <td><span class="status-badge active"><?= htmlspecialchars($cond); ?></span></td>
                            <td><?= htmlspecialchars($loc); ?></td>
                            <td><?= htmlspecialchars($resp); ?></td>
                            <td>
```

to:

```php
                            <td data-label="Code"><strong style="font-family:monospace;"><?= htmlspecialchars($code); ?></strong></td>
                            <td data-label="Name"><?= htmlspecialchars($name); ?></td>
                            <td data-label="Type"><?= htmlspecialchars($type); ?></td>
                            <td data-label="Status"><span class="status-badge active"><?= htmlspecialchars($cond); ?></span></td>
                            <td data-label="Location"><?= htmlspecialchars($loc); ?></td>
                            <td data-label="Responsible Office"><?= htmlspecialchars($resp); ?></td>
                            <td>
```

(The last `<td>` — the "Use this" button column — has no header text, so it's left without a `data-label`; on mobile it will show the button with no label line, which is correct since it's an inline action, not a data field.)

- [ ] **Step 7: Add `data-label` to the asset-requests table**

Edit the same file around line 502-511. Headers are: `Reference`, `Facility`, `Asset`, `Qty`, `Urgency`, `Need By`, `Status`, `Date`. From:

```php
                        <tr>
                            <td><strong><?= htmlspecialchars($ref); ?></strong></td>
                            <td><?= htmlspecialchars($fac); ?></td>
                            <td><?= $assetLabel; ?></td>
                            <td><?= $qty; ?></td>
                            <td><span style="color:<?= $urgColor; ?>;font-weight:600;"><?= htmlspecialchars($urg); ?></span></td>
                            <td><?= htmlspecialchars($need); ?></td>
                            <td><span class="status-badge maintenance"><?= htmlspecialchars(ucfirst($stat)); ?></span></td>
                            <td><?= htmlspecialchars($when); ?></td>
                        </tr>
```

to:

```php
                        <tr>
                            <td data-label="Reference"><strong><?= htmlspecialchars($ref); ?></strong></td>
                            <td data-label="Facility"><?= htmlspecialchars($fac); ?></td>
                            <td data-label="Asset"><?= $assetLabel; ?></td>
                            <td data-label="Qty"><?= $qty; ?></td>
                            <td data-label="Urgency"><span style="color:<?= $urgColor; ?>;font-weight:600;"><?= htmlspecialchars($urg); ?></span></td>
                            <td data-label="Need By"><?= htmlspecialchars($need); ?></td>
                            <td data-label="Status"><span class="status-badge maintenance"><?= htmlspecialchars(ucfirst($stat)); ?></span></td>
                            <td data-label="Date"><?= htmlspecialchars($when); ?></td>
                        </tr>
```

- [ ] **Step 8: Lint and smoke-check**

Run: `php -l resources/views/pages/dashboard/utilities_integration.php`
Expected: `No syntax errors detected`

Run: `grep -c "{" public/css/dashboard-pages.css; grep -c "}" public/css/dashboard-pages.css`
Expected: both counts equal (balanced braces — confirms the appended CSS block is well-formed; CSS has no PHP-style linter)

- [ ] **Step 9: Manual browser check**

Open `/dashboard/utilities-integration` in a browser, logged in as a role with facility-read access.
- Desktop, light theme: form fields align in the 2-col/3-col grids, cards have a visible hairline border, hovering a card shows a soft lift shadow.
- Desktop, dark theme (toggle via the theme switch): no hardcoded-light-color boxes; borders/backgrounds still readable.
- Narrow viewport (~375px wide, e.g. browser dev tools device mode): the two tables collapse into stacked cards with visible field labels (Code, Name, Type, ... / Reference, Facility, ...) instead of a horizontally-scrolling table; the request form's 2-col and 3-col grids collapse to a single column.
- Submit a test asset request (or cancel one) to confirm the form still posts correctly — field `name`/`id` attributes are unchanged so this should behave exactly as before.

- [ ] **Step 10: Commit**

```bash
git add resources/views/layouts/dashboard_layout.php public/css/dashboard-pages.css resources/views/pages/dashboard/utilities_integration.php
git commit -m "Modernize UMAN utilities integration page UI (web + mobile)"
```

---

### Task 2: maintenance_integration.php

**Files:**
- Modify: `resources/views/pages/dashboard/maintenance_integration.php:23` (add variable), `:219-222` (tab nav), `:297-310` + rows (schedules table), `:503-515` + rows (completed table)
- Modify: `public/css/dashboard-pages.css` (append maintenance-specific block)

**Interfaces:**
- Consumes: `.integrations-modern` scope, `data-label` convention, base card/page-header CSS from Task 1.
- Produces: `.integrations-modern .mi-tabs` / `.mi-tab` / `.mi-tab.active` styling, reusable by nothing else (page-specific), but modeled on the existing `.booking-hub-tab`/`.booking-hub-tab.is-active` pattern in `style.css:11359-11392` for visual consistency across the 4 pages.

- [ ] **Step 1: Wire the modifier class**

Edit `resources/views/pages/dashboard/maintenance_integration.php` line 23, from:

```php
$pageTitle = 'Maintenance Integration | LGU Facilities Reservation';
```

to:

```php
$pageTitle = 'Maintenance Integration | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';
```

- [ ] **Step 2: Style the currently-unstyled tab nav**

`.mi-tabs`/`.mi-tab` have no CSS anywhere in the codebase today (confirmed via `grep -rn "\.mi-tab" public/css/`). Append to `public/css/dashboard-pages.css`:

```css

.integrations-modern .mi-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    border-bottom: 2px solid var(--border-color, #e0e6ed);
    margin-bottom: 1.25rem;
}

.integrations-modern .mi-tab {
    display: inline-block;
    padding: 0.6rem 1.1rem;
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-secondary, #5b6888);
    background: transparent;
    text-decoration: none;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: 8px 8px 0 0;
    transition: background 0.15s ease, color 0.15s ease;
}

.integrations-modern .mi-tab:hover {
    color: var(--primary-color, #0047ab);
    background: var(--bg-tertiary, #f9fafc);
}

.integrations-modern .mi-tab.active {
    background: var(--bg-secondary, #fff);
    border-color: var(--border-color, #e0e6ed);
    border-bottom-color: var(--bg-secondary, #fff);
    color: var(--primary-color, #0047ab);
    margin-bottom: -2px;
}

@media (max-width: 480px) {
    .integrations-modern .mi-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .integrations-modern .mi-tab {
        flex: 0 0 auto;
    }
}
```

- [ ] **Step 3: Add `data-label` to the schedules table**

Find the schedules table body in `resources/views/pages/dashboard/maintenance_integration.php` (table starts line 297, class `table--maintenance-schedules`). Headers in order: `Maintenance ID`, `Facility`, `Type`, `Scheduled Date`, `Duration`, `Priority`, `Status`, `Affected`, `Action`.

For every `<tr>` in that table's `<tbody>`, add `data-label="<header text>"` to each `<td>` matching its column position (1st td → `data-label="Maintenance ID"`, 2nd → `Facility`, 3rd → `Type`, 4th → `Scheduled Date`, 5th → `Duration`, 6th → `Priority`, 7th → `Status`, 8th → `Affected`, 9th → `Action`). Read the current `<tbody>` markup first (`sed -n '297,365p' resources/views/pages/dashboard/maintenance_integration.php`) to see the exact PHP interpolation in each `<td>`, then add the attribute without changing anything else inside each tag.

- [ ] **Step 4: Add `data-label` to the completed-maintenance table**

Same approach for the second table (starts line 503). Headers: `Maintenance ID`, `Facility`, `Type`, `Completed Date`, `Duration`, `Technician`, `Status`, `Action`. Add matching `data-label` attributes to each `<td>` in that table's `<tbody>` rows.

- [ ] **Step 5: Lint and smoke-check**

Run: `php -l resources/views/pages/dashboard/maintenance_integration.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Manual browser check**

Open `/dashboard/maintenance-integration`.
- Tab bar ("Schedules & Calendar" / "Maintenance Insights") now has visible pill/underline styling matching the rest of the app, both themes.
- Narrow viewport: tab bar scrolls horizontally instead of wrapping awkwardly; both tables collapse to labeled stacked cards.
- Confirm existing tab-switch links (`?tab=schedules`, `?tab=insights`) still navigate correctly, and any pagination/filter controls on this page still work.

- [ ] **Step 7: Commit**

```bash
git add public/css/dashboard-pages.css resources/views/pages/dashboard/maintenance_integration.php
git commit -m "Modernize maintenance integration page UI (web + mobile)"
```

---

### Task 3: infrastructure_projects_integration.php

**Files:**
- Modify: `resources/views/pages/dashboard/infrastructure_projects_integration.php:18` (add variable), 3 tables (lines ~122-130, ~162-171, ~199-208 + their `<tbody>` rows)

**Interfaces:**
- Consumes: `.integrations-modern` scope + all CSS from Task 1 (no new CSS expected — this page only uses `.page-header`, `.booking-card`, `.table`/`.table-responsive`, `.status-badge`, `.btn-primary`, all already covered).

- [ ] **Step 1: Wire the modifier class**

Edit `resources/views/pages/dashboard/infrastructure_projects_integration.php` line 18, from:

```php
$pageTitle = 'Infrastructure Projects (IPMS) | LGU Facilities Reservation';
```

to:

```php
$pageTitle = 'Infrastructure Projects (IPMS) | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';
```

- [ ] **Step 2: Add `data-label` to the "Blocked dates" table**

Table at line ~123, headers: `Facility`, `Project`, `Blocked dates`. Add `data-label="Facility"`, `data-label="Project"`, `data-label="Blocked dates"` to the 1st/2nd/3rd `<td>` in each `<tbody>` row respectively.

- [ ] **Step 3: Add `data-label` to the second table**

Table at line ~163, headers: `Project`, `Status`, `Reported location`, `Best match confidence`. Add matching `data-label` attributes to each `<td>` by column position.

- [ ] **Step 4: Add `data-label` to the third table**

Table at line ~200, headers: `Project`, `Status`, `Likely facility`, `Expected start`. Add matching `data-label` attributes to each `<td>` by column position.

- [ ] **Step 5: Lint and smoke-check**

Run: `php -l resources/views/pages/dashboard/infrastructure_projects_integration.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Manual browser check**

Open `/dashboard/infrastructure-projects`. Confirm cards/tables now show the Task 1 hairline-border + hover-lift styling (inherited automatically, no page-specific CSS needed), and all 3 tables stack into labeled cards on a narrow viewport.

- [ ] **Step 7: Commit**

```bash
git add resources/views/pages/dashboard/infrastructure_projects_integration.php
git commit -m "Modernize infrastructure projects integration page UI (web + mobile)"
```

---

### Task 4: energy_efficiency.php

**Files:**
- Modify: `resources/views/pages/dashboard/energy_efficiency.php:27` (add variable), 2 tables (lines ~514, ~656 + their `<tbody>` rows)

**Interfaces:**
- Consumes: `.integrations-modern` scope + all CSS from Tasks 1-2. This page's tab bar already uses `.booking-hub-tabs`/`.booking-hub-tab` (styled in `style.css:11359`, not `.mi-tabs`) so no new tab CSS is needed here.

- [ ] **Step 1: Wire the modifier class**

Edit `resources/views/pages/dashboard/energy_efficiency.php` line 27, from:

```php
$pageTitle = 'Energy Efficiency | LGU Facilities Reservation';
```

to:

```php
$pageTitle = 'Energy Efficiency | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';
```

- [ ] **Step 2: Add `data-label` to the consumption table**

Table at line ~512, header row (line 514): `Facility`, `Period`, `Consumption`, `Rate`, `Estimated Cost`, `Sync`, `Recorded By`, and conditionally `Actions` (only when `$canUpdate || $canDelete`). Add matching `data-label` attributes to each `<td>` in the `<tbody>` rows by column position — including the conditional `Actions` column only on rows where that `<td>` exists.

- [ ] **Step 3: Add `data-label` to the facility-mapping table**

Table at line ~655, header row (line 656): `CPRF Facility`, `Energy-System Facility`, `Status`, and a trailing blank header (action column, no label needed). Add `data-label="CPRF Facility"`, `data-label="Energy-System Facility"`, `data-label="Status"` to the first three `<td>`s in each row; leave the action-column `<td>` without a `data-label`.

- [ ] **Step 4: Lint and smoke-check**

Run: `php -l resources/views/pages/dashboard/energy_efficiency.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual browser check**

Open `/dashboard/energy-efficiency` (or its clean URL per `dashboard_layout.php`'s path map — confirm via `grep energy_efficiency resources/views/layouts/dashboard_layout.php` if unsure of the route).
- Both tables stack into labeled cards on a narrow viewport.
- Tab bar (`.booking-hub-tabs`) still switches sections correctly; forms in each tab still submit.
- Both themes look correct (cards show hairline border + hover lift from Task 1).

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/dashboard/energy_efficiency.php
git commit -m "Modernize energy efficiency integration page UI (web + mobile)"
```

---

### Task 5: Cross-page verification pass

**Files:** none (verification only)

**Interfaces:** none — this task only reads/exercises what Tasks 1-4 produced.

- [ ] **Step 1: Confirm the other 26 dashboard pages are untouched**

Run: `grep -rl "dashboardContentClass" resources/views/pages/dashboard/`
Expected output: exactly the 4 target files —
```
resources/views/pages/dashboard/utilities_integration.php
resources/views/pages/dashboard/maintenance_integration.php
resources/views/pages/dashboard/infrastructure_projects_integration.php
resources/views/pages/dashboard/energy_efficiency.php
```

- [ ] **Step 2: Spot-check one unrelated dashboard page for zero visual change**

Open any other dashboard page (e.g. `/dashboard/book-facility` or `/dashboard`) before and after this work (or diff against a git stash if still uncommitted) and confirm the rendered `<section class="dashboard-content ...">` tag has no trailing `integrations-modern` class and the page looks identical to before this plan.

- [ ] **Step 3: Full lint pass**

Run:
```bash
php -l resources/views/layouts/dashboard_layout.php
php -l resources/views/pages/dashboard/utilities_integration.php
php -l resources/views/pages/dashboard/maintenance_integration.php
php -l resources/views/pages/dashboard/infrastructure_projects_integration.php
php -l resources/views/pages/dashboard/energy_efficiency.php
```
Expected: `No syntax errors detected` for all 5.

- [ ] **Step 4: Final manual pass across all 4 pages**

For each of the 4 pages, in both light and dark theme, at both a desktop width and a ~375px mobile width: confirm cards/tables/forms render with the new consistent look, no table overflows horizontally on mobile, no form grid stays multi-column on mobile, and every existing button/link/form still performs its original action (submit request, cancel, sync, pin asset, tab switch, etc.).

- [ ] **Step 5: Commit (if Step 2's spot-check required any tweak; otherwise skip — nothing to commit)**
