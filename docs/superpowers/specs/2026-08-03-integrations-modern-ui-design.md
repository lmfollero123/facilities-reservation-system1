# Modern UI for Integration Dashboards

## Scope

Redesign the visual layer (web + mobile) of exactly 4 dashboard pages:

- `resources/views/pages/dashboard/utilities_integration.php` (UMAN)
- `resources/views/pages/dashboard/maintenance_integration.php`
- `resources/views/pages/dashboard/infrastructure_projects_integration.php`
- `resources/views/pages/dashboard/energy_efficiency.php`

No other dashboard page's appearance may change. No PHP business logic, POST handlers, form field `name`/`id` attributes, or JS hooks may change — this is presentation-only.

## Problem

All 4 pages share generic classes from `public/css/dashboard-pages.css` (`.booking-card`, `.status-badge`, `.table`/`.table-responsive`, `.btn-primary/outline/secondary`, `.page-header`) — but so do 28 files across the dashboard. The pages read as dated (boxy cards, ad hoc inline `style="..."` overrides, inconsistent spacing), don't collapse well on mobile (tables overflow, tab bars don't scroll), and look inconsistent page-to-page despite the shared class names, because each page adds its own inline-style patches on top.

## Approach

All rendering funnels through one place: `dashboard_layout.php:146`

```php
<section class="dashboard-content dashboard-fade-in">
    <?= $content ?? ''; ?>
</section>
```

Each dashboard page builds `$content` via `ob_start()` earlier in the file and `$content = ob_get_clean();` right before `include .../dashboard_layout.php` at the bottom.

**Changes:**

1. `dashboard_layout.php:146` — append an optional modifier class:
   ```php
   <section class="dashboard-content dashboard-fade-in <?= htmlspecialchars($dashboardContentClass ?? '', ENT_QUOTES, 'UTF-8'); ?>">
   ```
   `$dashboardContentClass` is unset on all other pages, so this is a no-op everywhere except the 4 target pages. Byte-identical output elsewhere.

2. Each of the 4 target pages sets, once near the top (after `$pageTitle` is set, before the page's HTML begins):
   ```php
   $dashboardContentClass = 'integrations-modern';
   ```

3. All new visual rules go into the *existing* `public/css/dashboard-pages.css` (no new stylesheet, no new `<link>` tag), scoped under the `.integrations-modern` ancestor selector, e.g.:
   ```css
   .integrations-modern .booking-card { /* modern card: radius, shadow, spacing */ }
   .integrations-modern .status-badge { /* refreshed badge treatment */ }
   ```
   Because every rule is scoped, it is inert on the other 26 dashboard pages that share the same base class names.

4. Design tokens: reuse existing CSS custom properties already defined in `public/css/style.css` (`--gov-blue`, `--bg-primary/secondary/tertiary`, `--text-primary/secondary`, `--shadow*`, `--border-color`, success/warning/error/info tokens). No new palette. Both light and `[data-theme="dark"]` variants must be covered, following the existing dark-mode override pattern already present in `dashboard-pages.css` (lines ~141-167).

5. Mobile: media queries scoped under `.integrations-modern`, covering:
   - `.table-responsive`/`.table` collapse to stacked card-rows below ~640px (labels shown per cell, no horizontal scroll-hunting)
   - Tab bars (`.mi-tabs`, `.booking-hub-tabs`) become horizontally scrollable with a visible active-state indicator
   - Touch targets (buttons, `.uman-pin-btn`, toggle buttons) sized to at least 44px tap area
   - Card padding/spacing tightens appropriately on narrow viewports without breaking layout

6. Inline-style cleanup: replace the handful of one-off `style="..."` attributes that fight the new design (e.g. the `<select>` elements in `utilities_integration.php` around lines 273/283) with classes styled under `.integrations-modern`. Only touch inline styles that conflict with the redesign — don't do a blanket sweep.

## Non-goals

- No new JS behavior, no new libraries.
- No changes to any other dashboard page's look.
- No changes to underlying data/queries/PHP logic in any of the 4 pages.
- No rename of existing shared classes (`.booking-card` etc.) — they keep their names; only scoped overrides are added.

## Rollout

One page at a time, each its own commit, smallest/simplest first so the pattern is validated cheaply before the larger pages:

1. `utilities_integration.php` (598 lines) — establishes the `.integrations-modern` pattern + shared component CSS
2. `maintenance_integration.php` (986 lines) — reuses pattern, adds calendar-specific mobile polish if needed
3. `infrastructure_projects_integration.php` (276 lines) — simplest, mostly card/table reuse
4. `energy_efficiency.php` (759 lines) — reuses pattern, tabs get the mobile-scroll treatment

After each page: visually spot-check (light + dark, desktop + narrow viewport) and confirm no functional regression (forms still submit, buttons still wired) before moving to the next.

## Testing

No automated visual tests in this codebase. Verification is manual: load each page in browser at desktop and mobile widths, both themes, confirm existing functionality (form submits, AJAX calls, JS toggles) still works untouched.
