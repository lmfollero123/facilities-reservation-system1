# UMAN Integration Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle `resources/views/pages/dashboard/utilities_integration.php` (both the "Equipment & Requests" and "Utility Readings" tabs) with a modern Tailwind UI matching the existing pattern in `resources/views/pages/dashboard/blackout_dates.php`, with light UX cleanup (stat cards, tidied form grids, pill badges) — no backend/JS logic changes.

**Architecture:** Single-file template rewrite. All changes live between the existing `ob_start();` (line 346) and `ob_get_clean();` (line 965) in `utilities_integration.php`. No new files, no new PHP functions, no new queries — every stat card and badge is computed from variables already produced above that line.

**Tech Stack:** PHP (plain, no framework), Tailwind CSS (utility classes, JIT-scanned from `.php` files per `tailwind.config.js`), Bootstrap Icons (`bi-*`, already loaded dashboard-wide), existing vanilla JS (unchanged).

## Global Constraints

- No PHP logic changes above line 346 (data-prep) or in the POST handler block (lines 67–222) — spec is markup-only.
- Every `id="..."` attribute read by the two existing `<script>` blocks (pin-asset flow, consumption preview) must be preserved exactly, character-for-character.
- Every `name="..."` form field attribute must be preserved exactly (POST handlers read `$_POST['...']` by these names).
- Tab switching stays server-rendered `<a href>` links with `?tab=` query param (no client-side JS tab toggle) — required because add/edit/delete-reading POST handlers redirect via `$umanTabUrl('readings')` and rely on a real page load to show the flash message.
- Visual language: `rounded-2xl border border-slate-200 bg-white shadow-sm` cards, slate neutral palette, emerald primary actions, `bi-*` icons — matching `blackout_dates.php`.
- After all markup edits are done, run `npm run build:css` once (final step) to compile any newly-used Tailwind utility classes into `public/css/tailwind.css`.
- No automated view/template tests exist in this codebase (PHPUnit only covers `config/*_helper.php` business logic, not views) — verification per task is `php -l` syntax check + a manual browser checklist, matching this codebase's actual practice.

---

### Task 1: Page-wide shared elements (header, tabs, banners, stat card helper)

**Files:**
- Modify: `resources/views/pages/dashboard/utilities_integration.php:345-382` (breadcrumb/title, tab nav, message banner, connection-status notices — the part shared by both tabs, rendered before the `if ($tab === 'equipment')` branch)

**Interfaces:**
- Produces: the restyled tab-bar markup and alert-box pattern that Tasks 2 and 3 will reuse verbatim for their own in-tab banners/notices.
- Consumes: existing PHP variables only — `$tab`, `$umanTabUrl`, `$message`, `$messageType`, `$apiKeyConfigured`, `$catalogLive`, `$apiError`, `$integrationStatus` (already computed at lines 300-308).

- [ ] **Step 1: Restyle the tab bar**

Replace (line 355-358):

```php
<nav class="booking-hub-tabs" aria-label="UMAN sections">
    <a class="booking-hub-tab <?= $tab === 'equipment' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($umanTabUrl('equipment')); ?>">Equipment &amp; Requests</a>
    <a class="booking-hub-tab <?= $tab === 'readings' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($umanTabUrl('readings')); ?>">Utility Readings</a>
</nav>
```

with:

```php
<nav class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 mb-6 gap-1" aria-label="UMAN sections">
    <a class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $tab === 'equipment' ? 'bg-white shadow-sm text-slate-900 font-semibold' : 'text-slate-500 hover:text-slate-700'; ?>" href="<?= htmlspecialchars($umanTabUrl('equipment')); ?>">Equipment &amp; Requests</a>
    <a class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $tab === 'readings' ? 'bg-white shadow-sm text-slate-900 font-semibold' : 'text-slate-500 hover:text-slate-700'; ?>" href="<?= htmlspecialchars($umanTabUrl('readings')); ?>">Utility Readings</a>
</nav>
```

- [ ] **Step 2: Restyle the flash message banner**

Replace (lines 360-368):

```php
<?php if ($message):
    $msgBg = $messageType === 'success' ? '#ecfdf5' : ($messageType === 'warning' ? '#fffbeb' : '#fef2f2');
    $msgFg = $messageType === 'success' ? '#047857' : ($messageType === 'warning' ? '#92400e' : '#b91c1c');
    $msgBd = $messageType === 'success' ? '#a7f3d0' : ($messageType === 'warning' ? '#fde68a' : '#fecaca');
?>
    <div class="message <?= htmlspecialchars($messageType); ?>" style="padding:0.85rem 1rem;border-radius:10px;margin-bottom:1.25rem;background:<?= $msgBg; ?>;color:<?= $msgFg; ?>;border:1px solid <?= $msgBd; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
```

with:

```php
<?php if ($message):
    $msgClasses = $messageType === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : ($messageType === 'warning'
            ? 'border-amber-200 bg-amber-50 text-amber-800'
            : 'border-red-200 bg-red-50 text-red-800');
    $msgIcon = $messageType === 'success' ? 'bi-check-circle' : ($messageType === 'warning' ? 'bi-exclamation-triangle' : 'bi-exclamation-circle');
?>
    <div class="mb-5 rounded-xl border px-4 py-3 text-sm flex items-start gap-3 <?= $msgClasses; ?>" role="alert">
        <i class="bi <?= $msgIcon; ?> text-lg flex-shrink-0 mt-0.5"></i>
        <span><?= htmlspecialchars($message); ?></span>
    </div>
<?php endif; ?>
```

- [ ] **Step 3: Restyle the connection-status notices**

Replace (lines 370-382):

```php
<?php if (!$apiKeyConfigured): ?>
    <div style="padding:0.85rem 1rem;border-radius:10px;margin-bottom:1.25rem;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;">
        <strong style="display:block;margin-bottom:0.25rem;">UMAN API key not configured</strong>
        Set <code>UMAN_API_KEY</code> in your <code>.env</code> file to submit asset requests.
    </div>
<?php elseif (!$catalogLive): ?>
    <div style="padding:0.85rem 1rem;border-radius:10px;margin-bottom:1.25rem;background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;">
        <strong style="display:block;margin-bottom:0.25rem;">Request-only mode</strong>
        Asset catalog and request sync couldn't load from UMAN
        <?php if (!empty($apiError)): ?>: <em><?= htmlspecialchars($apiError); ?></em><?php endif; ?>.
        You can still submit requests — they will be queued and synced automatically when UMAN is reachable.
    </div>
<?php endif; ?>
```

with:

```php
<?php if (!$apiKeyConfigured): ?>
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 flex items-start gap-3" role="alert">
        <i class="bi bi-exclamation-triangle text-lg flex-shrink-0 mt-0.5"></i>
        <div>
            <strong class="block mb-0.5">UMAN API key not configured</strong>
            Set <code class="rounded bg-amber-100/80 px-1.5 py-0.5 text-xs">UMAN_API_KEY</code> in your <code class="rounded bg-amber-100/80 px-1.5 py-0.5 text-xs">.env</code> file to submit asset requests.
        </div>
    </div>
<?php elseif (!$catalogLive): ?>
    <div class="mb-5 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 flex items-start gap-3" role="alert">
        <i class="bi bi-info-circle text-lg flex-shrink-0 mt-0.5"></i>
        <div>
            <strong class="block mb-0.5">Request-only mode</strong>
            Asset catalog and request sync couldn't load from UMAN
            <?php if (!empty($apiError)): ?>: <em><?= htmlspecialchars($apiError); ?></em><?php endif; ?>.
            You can still submit requests — they will be queued and synced automatically when UMAN is reachable.
        </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 4: Verify PHP syntax**

Run: `php -l resources/views/pages/dashboard/utilities_integration.php`
Expected: `No syntax errors detected in resources/views/pages/dashboard/utilities_integration.php`

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/dashboard/utilities_integration.php
git commit -m "refactor: restyle UMAN page tab bar and banners with Tailwind"
```

---

### Task 2: Equipment & Requests tab

**Files:**
- Modify: `resources/views/pages/dashboard/utilities_integration.php:384-581` (request form, facility equipment summary aside, asset catalog table)
- Modify: `resources/views/pages/dashboard/utilities_integration.php:823-884` (asset requests table — sits after the readings-tab block but is itself gated by `if ($tab === 'equipment')`, unchanged in this repo's current structure)

**Interfaces:**
- Consumes: `$facilities`, `$equipmentTypes`, `$typesConnected`, `$typesApiError`, `$apiKeyConfigured`, `$assignedCounts`, `$umanAssets`, `$catalogLive`, `$apiError`, `$localRequests`, `$remoteRequests`, `$integrationStatus` (all already computed above line 346 — no new variables needed).
- Produces: new stat-card row markup (3 cards) placed immediately after Task 1's connection-status notices and before the request-form/aside grid.

- [ ] **Step 1: Add the Equipment tab stat-card row**

Insert immediately after the `<?php if ($tab === 'equipment'): ?>` opening (line 384), before the existing `<div class="booking-wrapper" id="request-form-wrapper">`:

```php
<?php
$umanStatLabel = match ($integrationStatus['sync_status']) {
    'live' => 'Live',
    'request_only' => 'Request-only',
    default => 'Offline',
};
$umanStatColor = match ($integrationStatus['sync_status']) {
    'live' => 'bg-emerald-50 text-emerald-600',
    'request_only' => 'bg-sky-50 text-sky-600',
    default => 'bg-red-50 text-red-600',
};
?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full <?= $umanStatColor; ?> flex items-center justify-center flex-shrink-0">
            <i class="bi bi-plug text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">UMAN Connection</p>
            <p class="text-lg font-bold text-slate-900"><?= htmlspecialchars($umanStatLabel); ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-box-seam text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Assets in Catalog</p>
            <p class="text-lg font-bold text-slate-900"><?= (int)$integrationStatus['asset_count']; ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-hourglass-split text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Pending Requests</p>
            <p class="text-lg font-bold text-slate-900"><?= (int)$integrationStatus['pending_requests']; ?></p>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Restyle the request-form card wrapper and header**

Replace (lines 385-388):

```php
<div class="booking-wrapper" id="request-form-wrapper">
    <section class="booking-card">
        <h2>Request Asset from UMAN</h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">Submit an equipment/utility asset request to the Utilities Management system. <em style="color:#059669;">Tip: click any asset row in the catalog below to prefill this form with a specific unit.</em></p>
```

with:

```php
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6" id="request-form-wrapper">
    <section class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6">
        <h2 class="text-base font-semibold text-slate-900 mb-1">Request Asset from UMAN</h2>
        <p class="text-sm text-slate-500 mb-4">Submit an equipment/utility asset request to the Utilities Management system. <em class="text-emerald-600">Tip: click any asset row in the catalog below to prefill this form with a specific unit.</em></p>
```

- [ ] **Step 3: Restyle the facility/asset-type row (Row 1)**

Replace (lines 395-421):

```php
            <div class="integration-form-row integration-form-row--2">
                <label>
                    Facility *
                    <select name="facility_id" id="f_facility_id" required class="integration-field">
                        <option value="">— Select facility —</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= (int)$f['id']; ?>"><?= htmlspecialchars($f['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Asset / Equipment Type *
                    <select name="asset_type" id="f_asset_type" required class="integration-field">
                        <option value="">— Select type —</option>
                        <?php foreach ($equipmentTypes as $t):
                            $countStr = $typesConnected && $t['asset_count'] > 0
                                ? " ({$t['operational_count']}/{$t['asset_count']} oper.)"
                                : '';
                            $title = $t['description'] !== '' ? ' title="' . htmlspecialchars($t['description']) . '"' : '';
                            $dataId = $t['id'] > 0 ? " data-id=\"{$t['id']}\"" : '';
                        ?>
                            <option value="<?= htmlspecialchars($t['name']); ?>"<?= $dataId . $title; ?>><?= htmlspecialchars($t['name'] . $countStr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
```

with:

```php
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block text-sm font-medium text-slate-700">
                    Facility *
                    <select name="facility_id" id="f_facility_id" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <option value="">— Select facility —</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= (int)$f['id']; ?>"><?= htmlspecialchars($f['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block text-sm font-medium text-slate-700">
                    Asset / Equipment Type *
                    <select name="asset_type" id="f_asset_type" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <option value="">— Select type —</option>
                        <?php foreach ($equipmentTypes as $t):
                            $countStr = $typesConnected && $t['asset_count'] > 0
                                ? " ({$t['operational_count']}/{$t['asset_count']} oper.)"
                                : '';
                            $title = $t['description'] !== '' ? ' title="' . htmlspecialchars($t['description']) . '"' : '';
                            $dataId = $t['id'] > 0 ? " data-id=\"{$t['id']}\"" : '';
                        ?>
                            <option value="<?= htmlspecialchars($t['name']); ?>"<?= $dataId . $title; ?>><?= htmlspecialchars($t['name'] . $countStr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
```

- [ ] **Step 4: Restyle the qty/urgency/date row (Row 2) and pinned-asset banner**

Replace (lines 423-457):

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

            <div id="pinned-asset-banner" style="margin-top:0.75rem;padding:0.6rem 0.85rem;border-radius:8px;background:#ecfeff;border:1px solid #a5f3fc;color:#0e7490;font-size:0.9rem;display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                    <div>
                        <strong style="color:#155e75;">Pinned specific asset:</strong>
                        <span id="pinned-asset-name">—</span>
                        <span id="pinned-asset-code" style="margin-left:0.4rem;padding:0.1rem 0.4rem;border-radius:4px;background:#cffafe;color:#0e7490;font-family:monospace;font-size:0.8rem;"></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <label style="display:inline-flex;align-items:center;gap:0.25rem;font-size:0.85rem;color:#155e75;white-space:nowrap;">
                            <input type="checkbox" name="exact_match" id="f_exact_match" style="width:auto;margin:0;">
                            Exact unit only
                        </label>
                        <button type="button" id="btn-clear-pin" style="padding:0.2rem 0.5rem;border-radius:4px;border:1px solid #a5f3fc;background:#fff;color:#0e7490;font-size:0.8rem;cursor:pointer;">Clear</button>
                    </div>
                </div>
            </div>
```

with:

```php
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                <label class="block text-sm font-medium text-slate-700">
                    Quantity
                    <input type="number" name="quantity" id="f_quantity" min="1" max="99" value="1" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <label class="block text-sm font-medium text-slate-700">
                    Urgency
                    <select name="urgency" id="f_urgency" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <option value="Routine">Routine (3–5 days)</option>
                        <option value="Priority">Priority (1–2 days)</option>
                        <option value="Emergency">Emergency (same day)</option>
                    </select>
                </label>
                <label class="block text-sm font-medium text-slate-700">
                    Date Needed
                    <input type="date" name="date_needed" id="f_date_needed" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none" min="<?= date('Y-m-d'); ?>">
                </label>
            </div>

            <div id="pinned-asset-banner" class="mt-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" style="display:none;">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <strong class="text-sky-800">Pinned specific asset:</strong>
                        <span id="pinned-asset-name">—</span>
                        <span id="pinned-asset-code" class="ml-1.5 rounded bg-sky-100 px-1.5 py-0.5 font-mono text-xs text-sky-800"></span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-1.5 text-xs text-sky-800 whitespace-nowrap">
                            <input type="checkbox" name="exact_match" id="f_exact_match" class="rounded border-sky-300">
                            Exact unit only
                        </label>
                        <button type="button" id="btn-clear-pin" class="rounded-md border border-sky-300 bg-white px-2.5 py-1 text-xs text-sky-800 hover:bg-sky-100">Clear</button>
                    </div>
                </div>
            </div>
```

- [ ] **Step 5: Restyle the booking-ref/office/purpose/notes rows and submit**

Replace (lines 459-489):

```php
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.75rem;">
                <label style="display:block;">
                    Booking Reference (optional)
                    <input type="text" name="booking_ref" id="f_booking_ref" placeholder="e.g., RES-2026-0812-007" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="display:block;">
                    Responsible Office (optional)
                    <input type="text" name="responsible_office" id="f_responsible_office" placeholder="e.g., Barangay Engineering Office" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
            </div>

            <label style="margin-top:0.75rem; display:block;">
                Event / Purpose (optional)
                <input type="text" name="event_purpose" id="f_event_purpose" placeholder="e.g., Graduation ceremony, Barangay assembly" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
            </label>

            <label style="margin-top:0.75rem; display:block;">
                Notes (optional)
                <textarea name="notes" id="f_notes" rows="2" placeholder="e.g., For convention hall events, portable unit preferred" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"></textarea>
            </label>

            <div style="margin-top:0.75rem;padding:0.55rem 0.8rem;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:0.82rem;">
                <?= $typesConnected
                    ? '<strong>Live catalog:</strong> asset types and operational counts pulled directly from UMAN.'
                    : '<strong>Fallback list:</strong> using a static 9-type list — UMAN asset-types endpoint was unreachable' . (!empty($typesApiError) ? ' (' . htmlspecialchars($typesApiError) . ')' : '') . '.';
                ?>
            </div>

            <button type="submit" class="btn-primary" style="margin-top:1rem;" <?= $apiKeyConfigured ? '' : 'disabled title="Configure UMAN_API_KEY in .env first"'; ?>>
                <?= $apiKeyConfigured ? 'Submit Request to UMAN' : 'UMAN API key not configured'; ?>
            </button>
```

with:

```php
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                <label class="block text-sm font-medium text-slate-700">
                    Booking Reference (optional)
                    <input type="text" name="booking_ref" id="f_booking_ref" placeholder="e.g., RES-2026-0812-007" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <label class="block text-sm font-medium text-slate-700">
                    Responsible Office (optional)
                    <input type="text" name="responsible_office" id="f_responsible_office" placeholder="e.g., Barangay Engineering Office" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
            </div>

            <label class="block text-sm font-medium text-slate-700 mt-4">
                Event / Purpose (optional)
                <input type="text" name="event_purpose" id="f_event_purpose" placeholder="e.g., Graduation ceremony, Barangay assembly" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
            </label>

            <label class="block text-sm font-medium text-slate-700 mt-4">
                Notes (optional)
                <textarea name="notes" id="f_notes" rows="2" placeholder="e.g., For convention hall events, portable unit preferred" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none"></textarea>
            </label>

            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                <?= $typesConnected
                    ? '<strong>Live catalog:</strong> asset types and operational counts pulled directly from UMAN.'
                    : '<strong>Fallback list:</strong> using a static 9-type list — UMAN asset-types endpoint was unreachable' . (!empty($typesApiError) ? ' (' . htmlspecialchars($typesApiError) . ')' : '') . '.';
                ?>
            </div>

            <button type="submit" class="btn-primary mt-4" <?= $apiKeyConfigured ? '' : 'disabled title="Configure UMAN_API_KEY in .env first"'; ?>>
                <?= $apiKeyConfigured ? 'Submit Request to UMAN' : 'UMAN API key not configured'; ?>
            </button>
```

- [ ] **Step 6: Restyle the facility-equipment-summary aside and sync button**

Replace (lines 493-517):

```php
    <aside class="booking-card">
        <h2>Facility Equipment Summary</h2>
        <?php if (empty($facilities)): ?>
            <p style="color:#8b95b5;">No facilities registered.</p>
        <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0;">
                <?php foreach ($facilities as $f): ?>
                    <?php $cnt = $assignedCounts[(int)$f['id']] ?? 0; ?>
                    <li style="padding:0.75rem 0; border-bottom:1px solid #edf2f7; display:flex; justify-content:space-between; gap:0.5rem;">
                        <span><?= htmlspecialchars($f['name']); ?></span>
                        <span style="font-weight:600; color:<?= $cnt > 0 ? '#0066cc' : '#8b95b5'; ?>;"><?= $cnt; ?> assigned</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" style="margin-top:1.25rem;padding-top:1rem;border-top:1px dashed #e0e6ed;">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="sync_requests">
            <button type="submit" class="btn-outline" style="width:100%;padding:0.5rem;border-radius:6px;border:1px solid #cbd5e1;background:#fff;color:#475569;cursor:pointer;">
                ⟳ Sync Request Status from UMAN
            </button>
        </form>
    </aside>
</div>
```

with:

```php
    <aside class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6 h-fit">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Facility Equipment Summary</h2>
        <?php if (empty($facilities)): ?>
            <p class="text-sm text-slate-500">No facilities registered.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($facilities as $f): ?>
                    <?php $cnt = $assignedCounts[(int)$f['id']] ?? 0; ?>
                    <li class="py-2.5 flex items-center justify-between gap-2">
                        <span class="text-sm text-slate-700"><?= htmlspecialchars($f['name']); ?></span>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $cnt > 0 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-500'; ?>"><?= $cnt; ?> assigned</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" class="mt-5 pt-4 border-t border-dashed border-slate-200">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="sync_requests">
            <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                <i class="bi bi-arrow-repeat"></i> Sync Request Status from UMAN
            </button>
        </form>
    </aside>
</div>
```

- [ ] **Step 7: Restyle the asset-catalog table card**

Replace (lines 519-580):

```php
<section class="booking-card" style="margin-top:1.5rem;">
    <h2>UMAN Asset Catalog <?= $catalogLive ? '' : '<small style="font-weight:500;color:#8b95b5;">(catalog offline — requests still work)</small>'; ?></h2>
    <?php if (empty($umanAssets)): ?>
        <p style="color:#8b95b5; text-align:center; padding:2rem;">
            <?= $apiError ? htmlspecialchars($apiError) : 'No assets returned from UMAN.'; ?>
        </p>
    <?php else: ?>
        <div style="margin-bottom:0.6rem;font-size:0.85rem;color:#64748b;">
            💡 <strong>Tip:</strong> click any row to prefill the request form with that specific asset.
        </div>
        <div class="table-responsive">
            <table class="table" id="asset-catalog-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Responsible Office</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($umanAssets as $asset):
                        $code = (string)($asset['asset_code'] ?? '');
                        $name = (string)($asset['name'] ?? '');
                        $type = (string)($asset['asset_type'] ?? '');
                        $cond = (string)($asset['condition_status'] ?? '');
                        $loc  = (string)($asset['location'] ?? '');
                        $resp = (string)($asset['responsible_office'] ?? '');
                        $typeId = (int)($asset['asset_type_id'] ?? 0);
                        $rowClickable = $cond === 'Operational';
                    ?>
                        <tr class="uman-catalog-row"
                            data-code="<?= htmlspecialchars($code); ?>"
                            data-name="<?= htmlspecialchars($name); ?>"
                            data-type="<?= htmlspecialchars($type); ?>"
                            data-type-id="<?= $typeId; ?>"
                            data-resp="<?= htmlspecialchars($resp); ?>"
                            style="<?= $rowClickable ? 'cursor:pointer;' : 'opacity:0.75;' ?>"
                            title="<?= $rowClickable ? 'Click to request this specific asset' : 'Asset not operational — click to view only' ?>">
                            <td data-label="Code"><strong style="font-family:monospace;"><?= htmlspecialchars($code); ?></strong></td>
                            <td data-label="Name"><?= htmlspecialchars($name); ?></td>
                            <td data-label="Type"><?= htmlspecialchars($type); ?></td>
                            <td data-label="Status"><span class="status-badge active"><?= htmlspecialchars($cond); ?></span></td>
                            <td data-label="Location"><?= htmlspecialchars($loc); ?></td>
                            <td data-label="Responsible Office"><?= htmlspecialchars($resp); ?></td>
                            <td>
                                <?php if ($rowClickable): ?>
                                    <button type="button" class="uman-pin-btn"
                                        style="padding:0.25rem 0.6rem;border-radius:6px;border:1px solid #059669;background:#d1fae5;color:#059669;font-size:0.78rem;cursor:pointer;white-space:nowrap;"
                                        aria-label="Request this asset">Use this</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
```

with:

```php
<section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6 mt-6">
    <h2 class="text-base font-semibold text-slate-900 mb-3">UMAN Asset Catalog <?= $catalogLive ? '' : '<span class="text-xs font-normal text-slate-400">(catalog offline — requests still work)</span>'; ?></h2>
    <?php if (empty($umanAssets)): ?>
        <p class="text-sm text-slate-500 text-center py-8">
            <?= $apiError ? htmlspecialchars($apiError) : 'No assets returned from UMAN.'; ?>
        </p>
    <?php else: ?>
        <div class="mb-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-500 flex items-center gap-2">
            <i class="bi bi-lightbulb text-slate-400"></i>
            <span><strong class="text-slate-600">Tip:</strong> click any row to prefill the request form with that specific asset.</span>
        </div>
        <div class="table-responsive">
            <table class="table" id="asset-catalog-table">
                <thead>
                    <tr class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Responsible Office</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($umanAssets as $asset):
                        $code = (string)($asset['asset_code'] ?? '');
                        $name = (string)($asset['name'] ?? '');
                        $type = (string)($asset['asset_type'] ?? '');
                        $cond = (string)($asset['condition_status'] ?? '');
                        $loc  = (string)($asset['location'] ?? '');
                        $resp = (string)($asset['responsible_office'] ?? '');
                        $typeId = (int)($asset['asset_type_id'] ?? 0);
                        $rowClickable = $cond === 'Operational';
                    ?>
                        <tr class="uman-catalog-row hover:bg-slate-50 <?= $rowClickable ? 'cursor-pointer' : 'opacity-75'; ?>"
                            data-code="<?= htmlspecialchars($code); ?>"
                            data-name="<?= htmlspecialchars($name); ?>"
                            data-type="<?= htmlspecialchars($type); ?>"
                            data-type-id="<?= $typeId; ?>"
                            data-resp="<?= htmlspecialchars($resp); ?>"
                            title="<?= $rowClickable ? 'Click to request this specific asset' : 'Asset not operational — click to view only' ?>">
                            <td data-label="Code"><strong class="font-mono text-slate-700"><?= htmlspecialchars($code); ?></strong></td>
                            <td data-label="Name"><?= htmlspecialchars($name); ?></td>
                            <td data-label="Type"><?= htmlspecialchars($type); ?></td>
                            <td data-label="Status"><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-emerald-50 text-emerald-700"><?= htmlspecialchars($cond); ?></span></td>
                            <td data-label="Location"><?= htmlspecialchars($loc); ?></td>
                            <td data-label="Responsible Office"><?= htmlspecialchars($resp); ?></td>
                            <td>
                                <?php if ($rowClickable): ?>
                                    <button type="button" class="uman-pin-btn inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 whitespace-nowrap"
                                        aria-label="Request this asset">Use this</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
```

- [ ] **Step 8: Restyle the asset-requests table card**

Replace (lines 823-884):

```php
<?php if ($tab === 'equipment'): ?>
<section class="booking-card" style="margin-top:1.5rem;">
    <h2>Asset Requests</h2>
    <?php
    $displayRequests = $localRequests !== [] ? $localRequests : $remoteRequests;
    ?>
    <?php if (empty($displayRequests)): ?>
        <p style="color:#8b95b5; text-align:center; padding:2rem;">No asset requests yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Facility</th>
                        <th>Asset</th>
                        <th>Qty</th>
                        <th>Urgency</th>
                        <th>Need By</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayRequests as $req):
                        $ref  = (string)($req['uman_request_ref'] ?? $req['request_ref'] ?? '—');
                        $fac  = (string)($req['facility_name'] ?? '');
                        $type = (string)($req['asset_type'] ?? '');
                        $code = (string)($req['requested_asset_code'] ?? '');
                        $qty  = (int)($req['quantity'] ?? 1);
                        $urg  = (string)($req['urgency'] ?? 'Routine');
                        $need = !empty($req['date_needed']) ? date('M d, Y', strtotime((string)$req['date_needed'])) : '—';
                        $stat = (string)($req['status'] ?? 'pending');
                        $when = date('M d, Y', strtotime((string)($req['created_at'] ?? 'now')));

                        $assetLabel = $type;
                        if ($code !== '') {
                            $assetLabel .= ' <span style="font-size:0.75rem;padding:0.08rem 0.3rem;border-radius:3px;background:#f1f5f9;color:#475569;font-family:monospace;">' . htmlspecialchars($code) . '</span>';
                        }

                        $urgColor = match($urg) {
                            'Emergency' => '#dc2626',
                            'Priority'  => '#d97706',
                            default     => '#64748b',
                        };
                    ?>
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
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
```

with:

```php
<?php if ($tab === 'equipment'): ?>
<section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6 mt-6">
    <h2 class="text-base font-semibold text-slate-900 mb-3">Asset Requests</h2>
    <?php
    $displayRequests = $localRequests !== [] ? $localRequests : $remoteRequests;
    ?>
    <?php if (empty($displayRequests)): ?>
        <p class="text-sm text-slate-500 text-center py-8">No asset requests yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th>Reference</th>
                        <th>Facility</th>
                        <th>Asset</th>
                        <th>Qty</th>
                        <th>Urgency</th>
                        <th>Need By</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayRequests as $req):
                        $ref  = (string)($req['uman_request_ref'] ?? $req['request_ref'] ?? '—');
                        $fac  = (string)($req['facility_name'] ?? '');
                        $type = (string)($req['asset_type'] ?? '');
                        $code = (string)($req['requested_asset_code'] ?? '');
                        $qty  = (int)($req['quantity'] ?? 1);
                        $urg  = (string)($req['urgency'] ?? 'Routine');
                        $need = !empty($req['date_needed']) ? date('M d, Y', strtotime((string)$req['date_needed'])) : '—';
                        $stat = (string)($req['status'] ?? 'pending');
                        $when = date('M d, Y', strtotime((string)($req['created_at'] ?? 'now')));

                        $assetLabel = $type;
                        if ($code !== '') {
                            $assetLabel .= ' <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-600">' . htmlspecialchars($code) . '</span>';
                        }

                        $urgBadgeClass = match($urg) {
                            'Emergency' => 'bg-red-50 text-red-700',
                            'Priority'  => 'bg-amber-50 text-amber-700',
                            default     => 'bg-slate-100 text-slate-600',
                        };
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td data-label="Reference"><strong class="text-slate-700"><?= htmlspecialchars($ref); ?></strong></td>
                            <td data-label="Facility"><?= htmlspecialchars($fac); ?></td>
                            <td data-label="Asset"><?= $assetLabel; ?></td>
                            <td data-label="Qty"><?= $qty; ?></td>
                            <td data-label="Urgency"><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $urgBadgeClass; ?>"><?= htmlspecialchars($urg); ?></span></td>
                            <td data-label="Need By"><?= htmlspecialchars($need); ?></td>
                            <td data-label="Status"><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-50 text-amber-700"><?= htmlspecialchars(ucfirst($stat)); ?></span></td>
                            <td data-label="Date"><?= htmlspecialchars($when); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
```

(Note: the closing `<?php endif; ?>` for `$tab === 'equipment'` and the `<script>` block that follows at lines 886-961 are untouched — Step 8 only replaces the `<section>...</section>` markup, not the surrounding conditional or script.)

- [ ] **Step 9: Verify PHP syntax**

Run: `php -l resources/views/pages/dashboard/utilities_integration.php`
Expected: `No syntax errors detected in resources/views/pages/dashboard/utilities_integration.php`

- [ ] **Step 10: Manual verification checklist**

Load `{APP_URL}/dashboard/utilities-integration?tab=equipment` as a Staff/Admin user and confirm:
- Stat-card row shows connection/asset-count/pending-request values.
- Clicking a catalog row still populates the pinned-asset banner and prefills the form (existing JS, unchanged IDs).
- "Clear" button on the pinned-asset banner still clears the pin.
- Submitting the request form still works (existing POST handler, unchanged `name` attributes).
- "Sync Request Status from UMAN" button still works.

- [ ] **Step 11: Commit**

```bash
git add resources/views/pages/dashboard/utilities_integration.php
git commit -m "refactor: restyle UMAN Equipment & Requests tab with Tailwind"
```

---

### Task 3: Utility Readings tab

**Files:**
- Modify: `resources/views/pages/dashboard/utilities_integration.php:583-819`

**Interfaces:**
- Consumes: `$hasReadingTables`, `$utilityEditReading`, `$utilityEditReadingIsOnly`, `$canCreateReadings`, `$canUpdateReadings`, `$canDeleteReadings`, `$utilityFacilities`, `$utilityLatestReadings`, `$utilityMonthNames`, `$umanTabUrl` (all already computed above line 346).
- Produces: new stat-card row markup (3 cards), computed via a small inline PHP block added in Step 1 — no new file-level variables consumed by any other task.

- [ ] **Step 1: Add the Readings tab stat-card row**

Insert immediately after the `<?php if ($tab === 'readings'): ?>` opening (line 583), before the existing `<section class="booking-card" style="margin-top:1.5rem;">`:

```php
<?php if ($hasReadingTables):
    $curYear = (int)date('Y');
    $curMonth = (int)date('n');
    $readingsThisMonth = 0;
    $syncFailedCount = 0;
    foreach ($utilityLatestReadings as $r) {
        if ((int)$r['year'] === $curYear && (int)$r['month'] === $curMonth) {
            $readingsThisMonth++;
        }
        if (($r['sync_status'] ?? '') === 'failed') {
            $syncFailedCount++;
        }
    }
    $totalFacilities = count($utilityFacilities);
    $coveredFacilities = count($utilityLatestReadings);
?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-calendar-check text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Readings This Month</p>
            <p class="text-lg font-bold text-slate-900"><?= $readingsThisMonth; ?> / <?= $totalFacilities; ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center flex-shrink-0">
            <i class="bi bi-buildings text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Facilities Covered</p>
            <p class="text-lg font-bold text-slate-900"><?= $coveredFacilities; ?> / <?= $totalFacilities; ?></p>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
        <div class="h-10 w-10 rounded-full <?= $syncFailedCount > 0 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600'; ?> flex items-center justify-center flex-shrink-0">
            <i class="bi <?= $syncFailedCount > 0 ? 'bi-exclamation-circle' : 'bi-check-circle'; ?> text-lg"></i>
        </div>
        <div>
            <p class="text-xs text-slate-500">Sync Status</p>
            <p class="text-lg font-bold text-slate-900"><?= $syncFailedCount > 0 ? "{$syncFailedCount} failed" : 'All synced'; ?></p>
        </div>
    </div>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Restyle the section header and description**

Replace (lines 584-589):

```php
<section class="booking-card" style="margin-top:1.5rem;">
    <h2>💧⚡ Utility Readings (Electric &amp; Water)</h2>
    <p style="color:#8b95b5; margin-bottom:1rem;">
        Monthly readings sent to UMAN for consumption monitoring — UMAN forwards them to the LGU Energy system.
        One reading per facility per month.
    </p>
```

with:

```php
<section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6">
    <h2 class="text-base font-semibold text-slate-900 mb-1">💧⚡ Utility Readings (Electric &amp; Water)</h2>
    <p class="text-sm text-slate-500 mb-4">
        Monthly readings sent to UMAN for consumption monitoring — UMAN forwards them to the LGU Energy system.
        One reading per facility per month.
    </p>
```

- [ ] **Step 3: Restyle the "run migration" notice**

Replace (line 592):

```php
        <p style="color:#8b95b5;">Run <code>database/migration_add_energy_integration.sql</code> and <code>database/migration_add_water_readings.sql</code> to enable utility readings.</p>
```

with:

```php
        <p class="text-sm text-slate-500">Run <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">database/migration_add_energy_integration.sql</code> and <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">database/migration_add_water_readings.sql</code> to enable utility readings.</p>
```

- [ ] **Step 4: Restyle the Edit Reading form card wrapper, fields, and fieldsets**

Replace (lines 595-649):

```php
    <?php if ($utilityEditReading !== null): ?>
        <div class="booking-form" style="margin-bottom:1.5rem; padding:1rem; border:1px solid #e0e6ed; border-radius:8px;">
            <h3 style="margin-top:0;">Edit Reading</h3>
            <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>" class="booking-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update_utility_reading">
                <input type="hidden" name="reading_id" value="<?= (int)$utilityEditReading['id']; ?>">
                <label>
                    Facility
                    <input type="text" value="<?= htmlspecialchars((string)$utilityEditReading['facility_name']); ?>" readonly style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px; background:#f4f6fa;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars((string)$utilityEditReading['reading_date']); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <fieldset style="margin-top:1rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>⚡ Electricity</legend>
                    <label>
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" value="<?= htmlspecialchars((string)$utilityEditReading['previous_reading_kwh']); ?>" <?= $utilityEditReadingIsOnly ? '' : 'readonly'; ?> style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" required value="<?= htmlspecialchars((string)$utilityEditReading['current_reading_kwh']); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" required value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_kwh'] ?? 14.83), 2, '.', '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                </fieldset>
                <fieldset style="margin-top:0.75rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>💧 Water</legend>
                    <label>
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['previous_reading_water'] ?? '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['current_reading_water'] ?? '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_water'] ?? 68.02), 2, '.', '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                </fieldset>
                <label style="margin-top:0.75rem; display:block;">
                    Notes (optional)
                    <textarea name="notes" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"><?= htmlspecialchars((string)($utilityEditReading['notes'] ?? '')); ?></textarea>
                </label>
                <div style="margin-top:1rem; display:flex; gap:0.75rem; align-items:center;">
                    <button type="submit" class="btn-primary">Save Correction</button>
                    <a href="<?= htmlspecialchars($umanTabUrl('readings')); ?>">Cancel</a>
                </div>
            </form>
        </div>
```

with:

```php
    <?php if ($utilityEditReading !== null): ?>
        <div class="mb-6 rounded-xl border border-slate-200 p-4 sm:p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Edit Reading</h3>
            <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update_utility_reading">
                <input type="hidden" name="reading_id" value="<?= (int)$utilityEditReading['id']; ?>">
                <label class="block text-sm font-medium text-slate-700">
                    Facility
                    <input type="text" value="<?= htmlspecialchars((string)$utilityEditReading['facility_name']); ?>" readonly class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                </label>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars((string)$utilityEditReading['reading_date']); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-amber-800 mb-2"><i class="bi bi-lightning-charge-fill"></i> Electricity</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" value="<?= htmlspecialchars((string)$utilityEditReading['previous_reading_kwh']); ?>" <?= $utilityEditReadingIsOnly ? '' : 'readonly'; ?> class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" required value="<?= htmlspecialchars((string)$utilityEditReading['current_reading_kwh']); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" required value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_kwh'] ?? 14.83), 2, '.', '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                </div>
                <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-sky-800 mb-2"><i class="bi bi-droplet-fill"></i> Water</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['previous_reading_water'] ?? '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" value="<?= htmlspecialchars((string)($utilityEditReading['current_reading_water'] ?? '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" value="<?= htmlspecialchars(number_format((float)($utilityEditReading['rate_per_water'] ?? 68.02), 2, '.', '')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                </div>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Notes (optional)
                    <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none"><?= htmlspecialchars((string)($utilityEditReading['notes'] ?? '')); ?></textarea>
                </label>
                <div class="mt-4 flex items-center gap-3">
                    <button type="submit" class="btn-primary">Save Correction</button>
                    <a href="<?= htmlspecialchars($umanTabUrl('readings')); ?>" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
                </div>
            </form>
        </div>
```

- [ ] **Step 5: Restyle the Add Reading form card wrapper, fields, and fieldsets**

Replace (lines 650-720):

```php
    <?php elseif ($canCreateReadings): ?>
        <div class="booking-form" style="margin-bottom:1.5rem; padding:1rem; border:1px solid #e0e6ed; border-radius:8px;">
            <h3 style="margin-top:0;">Add Reading</h3>
            <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>" class="booking-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="add_utility_reading">
                <label>
                    Facility
                    <select name="facility_id" id="utility-facility-select" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <option value="">— Select facility —</option>
                        <?php foreach ($utilityFacilities as $f): ?>
                            <?php $last = $utilityLatestReadings[(int)$f['id']] ?? null; ?>
                            <option value="<?= (int)$f['id']; ?>"
                                data-prev-kwh="<?= $last !== null ? htmlspecialchars((string)$last['current_reading_kwh']) : ''; ?>"
                                data-rate-kwh="<?= $last !== null ? htmlspecialchars((string)($last['rate_per_kwh'] ?? '14.83')) : '14.83'; ?>"
                                data-prev-water="<?= ($last !== null && $last['current_reading_water'] !== null) ? htmlspecialchars((string)$last['current_reading_water']) : ''; ?>"
                                data-rate-water="<?= ($last !== null && $last['rate_per_water'] !== null) ? htmlspecialchars((string)$last['rate_per_water']) : '68.02'; ?>">
                                <?= htmlspecialchars($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Month
                    <input type="month" name="reading_month" required value="<?= htmlspecialchars(date('Y-m')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars(date('Y-m-d')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <fieldset style="margin-top:1rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>⚡ Electricity</legend>
                    <label>
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" id="utility-prev-kwh" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Auto-filled and locked when the facility already has a reading.</small>
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" id="utility-curr-kwh" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" id="utility-rate-kwh" required value="14.83" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Meralco residential all-in rate, July 2026 — adjust to the current tariff.</small>
                    </label>
                </fieldset>
                <fieldset style="margin-top:0.75rem; border:1px solid #e0e6ed; border-radius:8px; padding:0.75rem;">
                    <legend>💧 Water (optional)</legend>
                    <label>
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" id="utility-prev-water" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Auto-filled and locked when the facility already has a water reading.</small>
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" id="utility-curr-water" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    </label>
                    <label style="margin-top:0.5rem; display:block;">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" id="utility-rate-water" value="68.02" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <small style="color:#8b95b5;">Manila Water East Zone (Quezon City), Q2 2026 tier — adjust to the current tariff.</small>
                    </label>
                </fieldset>
                <p id="utility-consumption-preview" style="margin-top:0.75rem; color:#0066cc; font-weight:600;"></p>
                <label style="margin-top:0.75rem; display:block;">
                    Notes (optional)
                    <textarea name="notes" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn-primary" style="margin-top:1rem;">Save Reading</button>
            </form>
```

with:

```php
    <?php elseif ($canCreateReadings): ?>
        <div class="mb-6 rounded-xl border border-slate-200 p-4 sm:p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Add Reading</h3>
            <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="add_utility_reading">
                <label class="block text-sm font-medium text-slate-700">
                    Facility
                    <select name="facility_id" id="utility-facility-select" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <option value="">— Select facility —</option>
                        <?php foreach ($utilityFacilities as $f): ?>
                            <?php $last = $utilityLatestReadings[(int)$f['id']] ?? null; ?>
                            <option value="<?= (int)$f['id']; ?>"
                                data-prev-kwh="<?= $last !== null ? htmlspecialchars((string)$last['current_reading_kwh']) : ''; ?>"
                                data-rate-kwh="<?= $last !== null ? htmlspecialchars((string)($last['rate_per_kwh'] ?? '14.83')) : '14.83'; ?>"
                                data-prev-water="<?= ($last !== null && $last['current_reading_water'] !== null) ? htmlspecialchars((string)$last['current_reading_water']) : ''; ?>"
                                data-rate-water="<?= ($last !== null && $last['rate_per_water'] !== null) ? htmlspecialchars((string)$last['rate_per_water']) : '68.02'; ?>">
                                <?= htmlspecialchars($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Reading Month
                    <input type="month" name="reading_month" required value="<?= htmlspecialchars(date('Y-m')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars(date('Y-m-d')); ?>" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </label>
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-amber-800 mb-2"><i class="bi bi-lightning-charge-fill"></i> Electricity</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (kWh)
                        <input type="number" step="0.01" min="0" name="previous_reading_kwh" id="utility-prev-kwh" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Auto-filled and locked when the facility already has a reading.</small>
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (kWh)
                        <input type="number" step="0.01" min="0" name="current_reading_kwh" id="utility-curr-kwh" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per kWh (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_kwh" id="utility-rate-kwh" required value="14.83" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Meralco residential all-in rate, July 2026 — adjust to the current tariff.</small>
                    </label>
                </div>
                <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50/40 p-4">
                    <div class="flex items-center gap-2 text-sm font-semibold text-sky-800 mb-2"><i class="bi bi-droplet-fill"></i> Water (optional)</div>
                    <label class="block text-sm font-medium text-slate-700">
                        Previous Reading (m³)
                        <input type="number" step="0.01" min="0" name="previous_reading_water" id="utility-prev-water" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Auto-filled and locked when the facility already has a water reading.</small>
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Current Reading (m³)
                        <input type="number" step="0.01" min="0" name="current_reading_water" id="utility-curr-water" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                    </label>
                    <label class="block text-sm font-medium text-slate-700 mt-2">
                        Rate per m³ (PHP)
                        <input type="number" step="0.01" min="0.01" name="rate_per_water" id="utility-rate-water" value="68.02" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        <small class="text-slate-400">Manila Water East Zone (Quezon City), Q2 2026 tier — adjust to the current tariff.</small>
                    </label>
                </div>
                <p id="utility-consumption-preview" class="mt-3 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-emerald-800 font-semibold text-sm empty:border-0 empty:bg-transparent empty:p-0"></p>
                <label class="block text-sm font-medium text-slate-700 mt-3">
                    Notes (optional)
                    <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none"></textarea>
                </label>
                <button type="submit" class="btn-primary mt-4">Save Reading</button>
            </form>
```

(The `<script>` block that follows immediately after, lines 721-763, reads `#utility-consumption-preview` via `.innerHTML` and is otherwise unaffected by class changes — leave it untouched.)

- [ ] **Step 6: Restyle the readings-history table card**

Replace (lines 767-818):

```php
    <?php if ($utilityLatestReadings === []): ?>
        <p style="color:#8b95b5; text-align:center; padding:2rem;">No utility readings recorded yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Facility</th><th>Period</th><th>Electric</th><th>Water</th><th>Sync</th><th>Recorded By</th>
                        <?php if ($canUpdateReadings || $canDeleteReadings): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($utilityLatestReadings as $r): ?>
                        <tr>
                            <td data-label="Facility"><?= htmlspecialchars((string)$r['facility_name']); ?></td>
                            <td data-label="Period"><?= htmlspecialchars(($utilityMonthNames[(int)$r['month']] ?? $r['month']) . ' ' . $r['year']); ?></td>
                            <td data-label="Electric"><?= number_format((float)$r['consumption_kwh'], 2); ?> kWh · PHP <?= number_format((float)$r['consumption_kwh'] * (float)($r['rate_per_kwh'] ?? 14.83), 2); ?></td>
                            <td data-label="Water">
                                <?php if ($r['current_reading_water'] !== null): ?>
                                    <?= number_format((float)$r['consumption_water'], 2); ?> m³ · PHP <?= number_format((float)$r['consumption_water'] * (float)($r['rate_per_water'] ?? 68.02), 2); ?>
                                <?php else: ?>
                                    <span style="color:#8b95b5;">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Sync">
                                <span class="status-badge <?= $r['sync_status'] === 'synced' ? 'active' : ($r['sync_status'] === 'failed' ? 'offline' : 'maintenance'); ?>"
                                      <?= $r['sync_error'] !== null ? 'title="' . htmlspecialchars((string)$r['sync_error']) . '"' : ''; ?>>
                                    <?= htmlspecialchars(ucfirst((string)$r['sync_status'])); ?>
                                </span>
                            </td>
                            <td data-label="Recorded By"><?= htmlspecialchars((string)($r['recorded_by_name'] ?? '—')); ?></td>
                            <?php if ($canUpdateReadings || $canDeleteReadings): ?>
                            <td data-label="Actions" style="white-space:nowrap;">
                                <?php if ($canUpdateReadings): ?>
                                    <a href="<?= htmlspecialchars($umanTabUrl('readings') . '&edit_reading=' . (int)$r['id']); ?>" class="btn-secondary" style="padding:0.3rem 0.7rem; font-size:0.85rem;">Edit</a>
                                <?php endif; ?>
                                <?php if ($canDeleteReadings && $r['sync_status'] !== 'synced'): ?>
                                    <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>" style="display:inline;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_utility_reading">
                                        <input type="hidden" name="reading_id" value="<?= (int)$r['id']; ?>">
                                        <button type="submit" class="btn-secondary" style="padding:0.3rem 0.7rem; font-size:0.85rem; color:#b23030;" onclick="return confirm('Delete this reading?')">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
```

with:

```php
    <?php if ($utilityLatestReadings === []): ?>
        <p class="text-sm text-slate-500 text-center py-8">No utility readings recorded yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th>Facility</th><th>Period</th><th>Electric</th><th>Water</th><th>Sync</th><th>Recorded By</th>
                        <?php if ($canUpdateReadings || $canDeleteReadings): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($utilityLatestReadings as $r):
                        $syncBadgeClass = match($r['sync_status']) {
                            'synced' => 'bg-emerald-50 text-emerald-700',
                            'failed' => 'bg-red-50 text-red-700',
                            default  => 'bg-amber-50 text-amber-700',
                        };
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td data-label="Facility"><?= htmlspecialchars((string)$r['facility_name']); ?></td>
                            <td data-label="Period"><?= htmlspecialchars(($utilityMonthNames[(int)$r['month']] ?? $r['month']) . ' ' . $r['year']); ?></td>
                            <td data-label="Electric"><?= number_format((float)$r['consumption_kwh'], 2); ?> kWh · PHP <?= number_format((float)$r['consumption_kwh'] * (float)($r['rate_per_kwh'] ?? 14.83), 2); ?></td>
                            <td data-label="Water">
                                <?php if ($r['current_reading_water'] !== null): ?>
                                    <?= number_format((float)$r['consumption_water'], 2); ?> m³ · PHP <?= number_format((float)$r['consumption_water'] * (float)($r['rate_per_water'] ?? 68.02), 2); ?>
                                <?php else: ?>
                                    <span class="text-slate-400">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Sync">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $syncBadgeClass; ?>"
                                      <?= $r['sync_error'] !== null ? 'title="' . htmlspecialchars((string)$r['sync_error']) . '"' : ''; ?>>
                                    <?= htmlspecialchars(ucfirst((string)$r['sync_status'])); ?>
                                </span>
                            </td>
                            <td data-label="Recorded By"><?= htmlspecialchars((string)($r['recorded_by_name'] ?? '—')); ?></td>
                            <?php if ($canUpdateReadings || $canDeleteReadings): ?>
                            <td data-label="Actions" class="whitespace-nowrap">
                                <?php if ($canUpdateReadings): ?>
                                    <a href="<?= htmlspecialchars($umanTabUrl('readings') . '&edit_reading=' . (int)$r['id']); ?>" class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50"><i class="bi bi-pencil"></i> Edit</a>
                                <?php endif; ?>
                                <?php if ($canDeleteReadings && $r['sync_status'] !== 'synced'): ?>
                                    <form method="POST" action="<?= htmlspecialchars($umanTabUrl('readings')); ?>" class="inline">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_utility_reading">
                                        <input type="hidden" name="reading_id" value="<?= (int)$r['id']; ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-md border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50" onclick="return confirm('Delete this reading?')"><i class="bi bi-trash"></i> Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
```

- [ ] **Step 7: Verify PHP syntax**

Run: `php -l resources/views/pages/dashboard/utilities_integration.php`
Expected: `No syntax errors detected in resources/views/pages/dashboard/utilities_integration.php`

- [ ] **Step 8: Manual verification checklist**

Load `{APP_URL}/dashboard/utilities-integration?tab=readings` as a Staff/Admin user and confirm:
- Stat-card row shows readings-this-month, facilities-covered, sync-status values.
- Selecting a facility in "Add Reading" still auto-fills/locks previous-reading fields (existing JS, unchanged IDs).
- Typing into the reading/rate fields still updates the consumption preview live.
- Submitting Add Reading still saves and pushes to UMAN.
- Clicking "Edit" on a history row still opens the edit form pre-filled; "Save Correction" still works.
- "Delete" still prompts a confirm dialog and deletes (for non-synced rows only).

- [ ] **Step 9: Commit**

```bash
git add resources/views/pages/dashboard/utilities_integration.php
git commit -m "refactor: restyle UMAN Utility Readings tab with Tailwind"
```

---

### Task 4: Rebuild compiled Tailwind CSS

**Files:**
- Modify (generated): `public/css/tailwind.css`

**Interfaces:**
- Consumes: the finished `utilities_integration.php` from Tasks 1-3 (Tailwind's JIT content-scanner reads this file directly).
- Produces: nothing consumed by another task — this is the final build step.

- [ ] **Step 1: Rebuild Tailwind CSS**

Run: `npm run build:css`
Expected: exits 0, `public/css/tailwind.css` file modified time updates (verify with `ls -la public/css/tailwind.css` before/after, or just confirm the command prints no errors — the build script is `npx tailwindcss -i ./public/css/tailwind-input.css -o ./public/css/tailwind.css --minify`).

- [ ] **Step 2: Manual visual check**

Reload both tabs (`?tab=equipment` and `?tab=readings`) in a browser and confirm every class added in Tasks 1-3 actually renders styled (no unstyled/plain-black-text fallback, which would indicate a class Tailwind didn't pick up).

- [ ] **Step 3: Commit**

```bash
git add public/css/tailwind.css
git commit -m "build: recompile tailwind.css for UMAN page redesign"
```

---

## Self-Review

**Spec coverage:**
- Visual language (cards, palette, icons, alerts, table/badge styling) → Tasks 1-3, all sections. ✅
- Tab bar restyle → Task 1 Step 1. ✅
- Message banner + connection-status notices restyle → Task 1 Steps 2-3. ✅
- Equipment stat cards → Task 2 Step 1. ✅
- Readings stat cards → Task 3 Step 1. ✅
- Request form grid rows (facility/type, qty/urgency/date, pinned-asset banner, booking-ref/office, purpose, notes) → Task 2 Steps 3-5. ✅
- Facility Equipment Summary aside + sync button → Task 2 Step 6. ✅
- Asset catalog table → Task 2 Step 7. ✅
- Asset Requests table → Task 2 Step 8. ✅
- Add/Edit Reading form with Electricity/Water sub-cards → Task 3 Steps 4-5. ✅
- Consumption preview highlighted box → Task 3 Step 5 (`#utility-consumption-preview` classes). ✅
- Readings history table → Task 3 Step 6. ✅
- Preserve all `id`/`name` attributes for JS/POST compatibility → called out explicitly in every step that touches an ID/name-bearing element, plus Global Constraints. ✅
- Tailwind rebuild → Task 4. ✅
- No test suite exists for views → reflected in "Verify PHP syntax" + "Manual verification checklist" steps in place of automated tests. ✅

**Placeholder scan:** no TBD/TODO; every step shows complete before/after code, not descriptions.

**Type consistency:** no new functions/interfaces introduced across tasks — all tasks consume pre-existing PHP variables by their existing names (`$facilities`, `$equipmentTypes`, `$integrationStatus`, `$utilityLatestReadings`, `$utilityFacilities`, `$umanTabUrl`, etc.), confirmed consistent between Task descriptions and the code blocks.
