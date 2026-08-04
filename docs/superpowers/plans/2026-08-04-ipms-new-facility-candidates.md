# IPMS New-Facility Candidates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff turn a finished IPMS construction project into a bookable CPRF facility with one click, without ever silently auto-creating an unreviewed facility record.

**Architecture:** A new `frs_ipms_new_facility_candidates()` filter in `services/ipms_api.php` narrows the existing `needs_review` list down to projects at `completion_inspection` status that haven't been dismissed. The Infrastructure Projects page renders that filtered list with "Add as Facility" (deep-links to Facility Management with prefill query params) and "Not a facility" (dismiss) actions. Facility Management reads those query params to auto-open its existing Add Facility modal pre-filled, and on successful save, pins the IPMS project to the new facility using the pin-store infrastructure already built for the CIMM/IPMS rename-drift fix.

**Tech Stack:** PHP (no framework), PDO/MySQL, vanilla JS. No automated test runner in this codebase — verification per task is `php -l` (syntax) + a standalone `php -r` script exercising the new pure functions, plus a final manual browser walkthrough.

## Global Constraints

- This repo has no PHPUnit/test runner. "Test" steps below are `php -l` + isolated `php -r` verification scripts, not `phpunit`.
- JSON pin/dismiss stores live in `storage/` and follow the existing pattern (`frs_ipms_load_managed_maintenance_ids()` style): `is_file()` guard, `json_decode` with `is_array` check, `JSON_PRETTY_PRINT`.
- CSRF: any new POST form uses the existing `csrf_field()` / `verifyCSRFToken($_POST[CSRF_TOKEN_NAME])` helpers already used throughout `facility_management.php` and the rest of the dashboard.
- `IPMS_MATCH_THRESHOLD` (70) and `ipmsIsBlockingStatus()` / `completion_inspection` are existing constants/functions in `services/ipms_api.php` — do not redefine them.

---

## File Map

| File | Change |
|---|---|
| `services/ipms_api.php` | Add `latitude`/`longitude` to `needs_review` entries; add dismissed-projects persistence; add `frs_ipms_new_facility_candidates()` filter |
| `resources/views/pages/dashboard/infrastructure_projects_integration.php` | Handle dismiss POST; render "New Facility Candidates" section with Add-as-Facility link + dismiss form |
| `resources/views/pages/dashboard/facility_management.php` | Read prefill query params, auto-open+populate Add modal, pin project→facility on successful insert |
| `storage/ipms_dismissed_projects.json` | New — created on first dismiss, not committed (matches `.gitignore` treatment of the other `storage/*.json` state files — verify in Task 1) |

---

### Task 1: IPMS candidate list + dismiss persistence

**Files:**
- Modify: `services/ipms_api.php`

**Interfaces:**
- Consumes: existing `frs_ipms_load_schedule_pins()`/`ipmsProjectStableKey()` (already present).
- Produces: `frs_ipms_load_dismissed_projects(): array<string>`, `frs_ipms_save_dismissed_projects(array $keys): void`, `frs_ipms_new_facility_candidates(array $needsReview): array` — filters to `completion_inspection` + not-dismissed entries, each carrying `project_key`, `name`, `location`, `latitude`, `longitude`, `status`.

- [ ] **Step 1: Confirm `storage/*.json` state files are gitignored**

Run: `grep -n "storage" .gitignore`
Expected: a line matching `storage/*.json` or similar (the existing `cimm_managed_maintenance.json`, `ipms_sync_state.json` etc. already live there without being committed — confirm the new file follows the same rule; if the grep finds nothing, note it and add `storage/ipms_dismissed_projects.json` to `.gitignore` explicitly before continuing).

- [ ] **Step 2: Add latitude/longitude to `needs_review` entries**

In `services/ipms_api.php`, find (inside `syncFacilitiesFromIPMS()`):

```php
        if (!$facilityId) {
            $summary['unmatched_project_count']++;
            $summary['needs_review'][] = [
                'project_id' => $project['project_id'],
                'project_code' => $project['project_code'],
                'name' => $project['name'],
                'location' => $project['location'],
                'status' => $project['status'],
                'best_score' => $match['score'],
            ];
            continue;
        }
```

Replace with:

```php
        if (!$facilityId) {
            $summary['unmatched_project_count']++;
            $summary['needs_review'][] = [
                'project_id' => $project['project_id'],
                'project_code' => $project['project_code'],
                'name' => $project['name'],
                'location' => $project['location'],
                'status' => $project['status'],
                'best_score' => $match['score'],
                'latitude' => $project['latitude'] ?? null,
                'longitude' => $project['longitude'] ?? null,
            ];
            continue;
        }
```

- [ ] **Step 3: Add dismissed-projects persistence and the candidate filter**

Add these functions to `services/ipms_api.php`, right after `ipmsProjectStableKey()`:

```php
/**
 * Path to the list of IPMS projects staff have explicitly marked "not a
 * facility" from the New Facility Candidates section.
 */
function frs_ipms_dismissed_projects_path(): string
{
    $root = function_exists('app_root_path') ? app_root_path() : dirname(__DIR__);
    return $root . '/storage/ipms_dismissed_projects.json';
}

/**
 * @return array<int, string> list of dismissed project stable keys
 */
function frs_ipms_load_dismissed_projects(): array
{
    $path = frs_ipms_dismissed_projects_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $key) {
        $key = trim((string)$key);
        if ($key !== '') {
            $out[] = $key;
        }
    }
    return array_values(array_unique($out));
}

/**
 * @param array<int, string> $keys
 */
function frs_ipms_save_dismissed_projects(array $keys): void
{
    $path = frs_ipms_dismissed_projects_path();
    $clean = array_values(array_unique(array_filter(array_map(
        static fn($k) => trim((string)$k),
        $keys
    ), static fn($k) => $k !== '')));
    file_put_contents($path, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Narrow the `needs_review` list (unmatched projects from the last sync) down
 * to projects that look like a brand-new public facility ready to be added:
 * status `completion_inspection` (the last stage before a project presumably
 * exits the IPMS feed — IPMS has no observed explicit "completed" status) and
 * not already dismissed by staff.
 *
 * @param array<int, array<string, mixed>> $needsReview from $summary['needs_review']
 * @return array<int, array{project_key: string, name: string, location: string,
 *                            latitude: ?float, longitude: ?float, status: string}>
 */
function frs_ipms_new_facility_candidates(array $needsReview): array
{
    $dismissed = array_flip(frs_ipms_load_dismissed_projects());
    $candidates = [];

    foreach ($needsReview as $item) {
        $status = strtolower(trim((string)($item['status'] ?? '')));
        if ($status !== 'completion_inspection') {
            continue;
        }

        $projectKey = ipmsProjectStableKey([
            'project_code' => $item['project_code'] ?? '',
            'project_id' => $item['project_id'] ?? 0,
        ]);
        if ($projectKey === '' || isset($dismissed[$projectKey])) {
            continue;
        }

        $candidates[] = [
            'project_key' => $projectKey,
            'name' => (string)($item['name'] ?? ''),
            'location' => (string)($item['location'] ?? ''),
            'latitude' => isset($item['latitude']) && $item['latitude'] !== null ? (float)$item['latitude'] : null,
            'longitude' => isset($item['longitude']) && $item['longitude'] !== null ? (float)$item['longitude'] : null,
            'status' => $status,
        ];
    }

    return $candidates;
}
```

- [ ] **Step 4: Lint**

Run: `php -l services/ipms_api.php`
Expected: `No syntax errors detected in services/ipms_api.php`

- [ ] **Step 5: Verify with an isolated script**

Run this inline (or save to the scratchpad and run with `php <path>`):

```bash
php -r '
require_once "services/ipms_api.php";

$needsReview = [
    ["project_id"=>10, "project_code"=>"IPMS-A", "name"=>"New Barangay Hall", "location"=>"Culiat", "status"=>"completion_inspection", "best_score"=>40, "latitude"=>14.68, "longitude"=>121.04],
    ["project_id"=>11, "project_code"=>"IPMS-B", "name"=>"Road widening", "location"=>"Culiat", "status"=>"active", "best_score"=>30, "latitude"=>null, "longitude"=>null],
];

$candidates = frs_ipms_new_facility_candidates($needsReview);
echo "candidate count (expect 1): " . count($candidates) . "\n";
echo "candidate name (expect \"New Barangay Hall\"): " . $candidates[0]["name"] . "\n";

frs_ipms_save_dismissed_projects(["IPMS-A"]);
$afterDismiss = frs_ipms_new_facility_candidates($needsReview);
echo "candidate count after dismiss (expect 0): " . count($afterDismiss) . "\n";

// cleanup
@unlink(frs_ipms_dismissed_projects_path());
'
```

Expected output:
```
candidate count (expect 1): 1
candidate name (expect "New Barangay Hall"): New Barangay Hall
candidate count after dismiss (expect 0): 0
```

- [ ] **Step 6: Commit**

```bash
git add services/ipms_api.php
git commit -m "feat: IPMS new-facility candidate filter + dismiss persistence"
```

---

### Task 2: Surface candidates + dismiss action on the Infrastructure Projects page

**Files:**
- Modify: `resources/views/pages/dashboard/infrastructure_projects_integration.php`

**Interfaces:**
- Consumes: `frs_ipms_new_facility_candidates()`, `frs_ipms_load_dismissed_projects()`, `frs_ipms_save_dismissed_projects()` from Task 1.
- Produces: nothing new consumed elsewhere.

- [ ] **Step 1: Handle the dismiss POST**

In `resources/views/pages/dashboard/infrastructure_projects_integration.php`, right after the existing `$canSync = in_array($role, ['Admin', 'Staff'], true);` (around line 21), add:

```php
if ($canSync && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'dismiss_candidate') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        http_response_code(403);
        die('Invalid request.');
    }
    $projectKey = trim((string)($_POST['project_key'] ?? ''));
    if ($projectKey !== '') {
        $dismissed = frs_ipms_load_dismissed_projects();
        $dismissed[] = $projectKey;
        frs_ipms_save_dismissed_projects($dismissed);
    }
    header('Location: ' . base_path() . '/dashboard/infrastructure-projects');
    exit;
}
```

- [ ] **Step 2: Compute the candidate list**

Right after `$syncErrors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];` (around line 29), add:

```php
$newFacilityCandidates = frs_ipms_new_facility_candidates($needsReview);
```

- [ ] **Step 3: Render the "New Facility Candidates" section**

Immediately before the existing `<!-- Needs manual review -->` comment block, add:

```php
<!-- New facility candidates -->
<?php if (!empty($newFacilityCandidates)): ?>
<section class="booking-card" style="grid-column: 1 / -1; border: 1px solid #0d9488;">
    <h2 style="margin-bottom:0.5rem;">
        🏗️ New Facility Candidates
        <small style="font-weight:500; color:#8b95b5;">(<?= count($newFacilityCandidates); ?>)</small>
    </h2>
    <p style="color:#8b95b5; margin-bottom:1rem;">
        These IPMS projects are in final completion inspection and don't match an existing facility —
        likely a brand-new public facility. Review and add it, or mark it as not a facility
        (e.g. road/drainage work) to stop it showing up here.
    </p>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Reported location</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($newFacilityCandidates as $c): ?>
                    <tr>
                        <td data-label="Project"><strong><?= htmlspecialchars($c['name']); ?></strong></td>
                        <td data-label="Reported location"><?= htmlspecialchars($c['location']); ?></td>
                        <td data-label="Action">
                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <?php
                                $prefillParams = [
                                    'prefill_name' => $c['name'],
                                    'prefill_location' => $c['location'],
                                    'prefill_ipms_key' => $c['project_key'],
                                ];
                                if ($c['latitude'] !== null) $prefillParams['prefill_lat'] = $c['latitude'];
                                if ($c['longitude'] !== null) $prefillParams['prefill_lng'] = $c['longitude'];
                                $addUrl = base_path() . '/dashboard/facility-management?' . http_build_query($prefillParams);
                                ?>
                                <a href="<?= htmlspecialchars($addUrl); ?>" class="btn-primary" style="padding:0.4rem 0.75rem; font-size:0.85rem; text-decoration:none;">Add as Facility</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this project as not a facility? It will stop appearing here.');">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="dismiss_candidate">
                                    <input type="hidden" name="project_key" value="<?= htmlspecialchars($c['project_key']); ?>">
                                    <button type="submit" class="btn-outline" style="padding:0.4rem 0.75rem; font-size:0.85rem;">Not a facility</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

```

- [ ] **Step 4: Lint**

Run: `php -l resources/views/pages/dashboard/infrastructure_projects_integration.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual check**

Load `/dashboard/infrastructure-projects` as Admin/Staff. If there's no real `completion_inspection`-status unmatched project in the current IPMS feed, temporarily hardcode one test entry into `$newFacilityCandidates` right after Step 2's line to confirm the section renders, the "Add as Facility" link builds a correct query string, and "Not a facility" dismisses it (remove the hardcoded test entry afterward).

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/dashboard/infrastructure_projects_integration.php
git commit -m "feat: surface IPMS new-facility candidates with add/dismiss actions"
```

---

### Task 3: Prefill Facility Management's Add modal and pin on save

**Files:**
- Modify: `resources/views/pages/dashboard/facility_management.php`

**Interfaces:**
- Consumes: `frs_ipms_load_schedule_pins()` / `frs_ipms_save_schedule_pins()` from `services/ipms_api.php` (already present).
- Produces: nothing new consumed elsewhere.

- [ ] **Step 1: Require the IPMS service**

Near the top of `facility_management.php`, alongside the existing `require_once __DIR__ . '/../../../../services/uman_api.php';` (line 20), add:

```php
require_once __DIR__ . '/../../../../services/ipms_api.php';
```

- [ ] **Step 2: Pin the IPMS project on successful facility insert**

Find (in the INSERT branch):

```php
                if ($newFacilityId > 0 && $hasUmanEquipment) {
                    $selectedEquipment = isset($_POST['equipment_ids']) && is_array($_POST['equipment_ids'])
                        ? $_POST['equipment_ids']
                        : [];
                    frs_save_facility_equipment($pdo, $newFacilityId, $selectedEquipment, $umanAssetsIndexed);
                }
                
                $message = 'Facility added successfully.';
```

Replace with:

```php
                if ($newFacilityId > 0 && $hasUmanEquipment) {
                    $selectedEquipment = isset($_POST['equipment_ids']) && is_array($_POST['equipment_ids'])
                        ? $_POST['equipment_ids']
                        : [];
                    frs_save_facility_equipment($pdo, $newFacilityId, $selectedEquipment, $umanAssetsIndexed);
                }

                $ipmsProjectKey = trim((string)($_POST['ipms_project_key'] ?? ''));
                if ($newFacilityId > 0 && $ipmsProjectKey !== '') {
                    $pins = frs_ipms_load_schedule_pins();
                    $pins[$ipmsProjectKey] = $newFacilityId;
                    frs_ipms_save_schedule_pins($pins);
                }

                $message = 'Facility added successfully.';
```

- [ ] **Step 3: Add the hidden field carrying the project key through the form**

Find:

```php
                <form class="facility-form" method="POST" enctype="multipart/form-data" id="facilityForm">
```

The line right after it is `<input type="hidden" name="facility_id" id="facility_id">` — after that line, add:

```php
                    <input type="hidden" name="ipms_project_key" id="form-ipms-project-key" value="">
```

- [ ] **Step 4: Auto-open and prefill from query params**

Near the end of the file, find where `resetFacilityForm()` is defined (search for `function resetFacilityForm()`). Immediately after that function's closing `}`, add a new function and an auto-run block:

```php
function prefillFromIpmsCandidate() {
    const params = new URLSearchParams(window.location.search);
    const name = params.get('prefill_name');
    const location = params.get('prefill_location');
    const lat = params.get('prefill_lat');
    const lng = params.get('prefill_lng');
    const ipmsKey = params.get('prefill_ipms_key');
    if (!name && !location && !ipmsKey) return;

    openFacilityModal(true);
    if (name) document.getElementById('form-name').value = name;
    if (location) document.getElementById('form-location').value = location;
    if (lat) document.getElementById('form-latitude').value = lat;
    if (lng) document.getElementById('form-longitude').value = lng;
    if (ipmsKey) document.getElementById('form-ipms-project-key').value = ipmsKey;
}

document.addEventListener('DOMContentLoaded', prefillFromIpmsCandidate);
```

(This must be placed inside the existing `<script>` block that already defines `openFacilityModal`/`resetFacilityForm` — do not open a new `<script>` tag.)

- [ ] **Step 5: Lint**

Run: `php -l resources/views/pages/dashboard/facility_management.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Verify the pin-write logic in isolation**

```bash
php -r '
require_once "services/ipms_api.php";
$pinsPath = frs_ipms_schedule_pins_path();
@unlink($pinsPath);

// Simulate what facility_management.php now does on insert.
$ipmsProjectKey = "IPMS-TEST-99";
$newFacilityId = 4242;
$pins = frs_ipms_load_schedule_pins();
$pins[$ipmsProjectKey] = $newFacilityId;
frs_ipms_save_schedule_pins($pins);

$reloaded = frs_ipms_load_schedule_pins();
echo "pinned facility id (expect 4242): " . var_export($reloaded[$ipmsProjectKey] ?? null, true) . "\n";

@unlink($pinsPath);
'
```

Expected: `pinned facility id (expect 4242): 4242`

- [ ] **Step 7: Manual browser walkthrough**

1. Visit `/dashboard/infrastructure-projects` and click "Add as Facility" on a candidate (real or the Task 2 Step 5 hardcoded test entry).
2. Confirm Facility Management opens with the Add modal already open, name/location/lat/lng pre-filled.
3. Fill in the remaining required fields (capacity, rate/free toggle, etc.) and Save.
4. Confirm the facility appears in the active facilities list.
5. Check `storage/ipms_schedule_facility_pins.json` (or wherever `frs_ipms_schedule_pins_path()` points) contains the new `project_key => facility_id` entry.

- [ ] **Step 8: Commit**

```bash
git add resources/views/pages/dashboard/facility_management.php
git commit -m "feat: prefill Add Facility from IPMS candidate + pin on save"
```

---

## Explicitly out of scope for this plan

- Detecting an actual IPMS "completed"/"finished" status — none has been observed; `completion_inspection` is the practical trigger (see spec).
- De-duplicating if staff clicks "Add as Facility" twice for the same project — accepted risk, same discipline required as any manual facility creation today.
- Any change to the CIMM side of the integration — CIMM's maintenance-request flow is unrelated and already complete.
