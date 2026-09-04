# React Calendar Island (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the booking calendar grid + navigation in `resources/views/pages/dashboard/book_facility.php` with a React island, fed by a new JSON endpoint, while every other part of the booking flow (conflict-check, AI purpose-hints, modal, submission) keeps working unchanged.

**Architecture:** A new Vite-built React component mounts into a `<div id="bcf-calendar-root">`. It fetches calendar data from a new PHP JSON endpoint that reuses the existing (unmodified) tone/demand/holiday-computing functions. The island talks to the rest of the page through exactly three narrow bridges: a click handler that calls an existing (lightly refactored) vanilla function, and two `window`-exposed functions (`bcfCalendarSetHighlights`, `bcfCalendarGetState`) that existing vanilla AI-hints code calls instead of touching the calendar's DOM directly.

**Tech Stack:** React 18, motion (npm package `motion`, `motion/react` import), Vite 5 + `@vitejs/plugin-react` for the build, plain JSX (no TypeScript). PHP 8 / PHPUnit 10 (already in the project) for the one new pure function.

## Global Constraints

- No server-side build step exists or will be added — the live deploy is `git pull` only. The built bundle (`public/js/dist/booking-calendar.js`) must be committed to git.
- No TypeScript — matches the untyped style of the rest of the codebase.
- The existing `bcf-calendar` HTML-partial mechanism (`data-frs-partial-*` attributes, `public/js/frs-partial-update.js`) is not modified or removed. It simply stops being used for this specific region once the markup changes in Task 7.
- Reuse 100% of existing CSS class names for calendar cells (`my-reservations-calendar-cell`, `status-approved`, `status-pending`, `status-denied`, `status-blackout`, `status-cimm-maintenance`, `today`, `empty`, `bcf-book-cal-cell`, `bcf-ai-suggest-date`, `date-label`, `status-chip`, `holiday-indicator`, `demand-strip demand-*`) — no new CSS for the grid itself.
- Every animation must respect `prefers-reduced-motion: reduce`.
- Restore point exists if anything needs full rollback: git tag `pre-react-calendar-backup-2026-09-04` (both on GitHub and the live server, at commit `adb8824`).

---

### Task 1: Vite + React build scaffolding

**Files:**
- Modify: `package.json` (repo root — already exists with a `build:css` script for Tailwind; add to it, don't replace it)
- Create: `vite.config.js` (repo root)
- Create: `resources/react/booking-calendar/main.jsx`
- Create: `resources/react/booking-calendar/App.jsx`

**Interfaces:**
- Produces: a build pipeline (`npm run build:calendar`) that outputs `public/js/dist/booking-calendar.js`, a single ES module bundle mountable via `<script type="module">`. Later tasks only ever edit `App.jsx`; `main.jsx` never changes again after this task.

- [ ] **Step 1: Add dependencies to `package.json`**

Current file:
```json
{
  "name": "facilities-reservation-system",
  "version": "1.0.0",
  "description": "Barangay Culiat Public Facilities Reservation System",
  "scripts": {
    "build:css": "npx tailwindcss -i ./public/css/tailwind-input.css -o ./public/css/tailwind.css --minify",
    "watch:css": "npx tailwindcss -i ./public/css/tailwind-input.css -o ./public/css/tailwind.css --watch"
  },
  "devDependencies": {
    "tailwindcss": "^3.4.0"
  }
}
```

Replace with:
```json
{
  "name": "facilities-reservation-system",
  "version": "1.0.0",
  "description": "Barangay Culiat Public Facilities Reservation System",
  "scripts": {
    "build:css": "npx tailwindcss -i ./public/css/tailwind-input.css -o ./public/css/tailwind.css --minify",
    "watch:css": "npx tailwindcss -i ./public/css/tailwind-input.css -o ./public/css/tailwind.css --watch",
    "build:calendar": "vite build"
  },
  "dependencies": {
    "react": "^18.3.1",
    "react-dom": "^18.3.1",
    "motion": "^11.15.0"
  },
  "devDependencies": {
    "tailwindcss": "^3.4.0",
    "vite": "^5.4.11",
    "@vitejs/plugin-react": "^4.3.4"
  }
}
```

- [ ] **Step 2: Install dependencies**

Run: `npm install`
Expected: completes without error; `node_modules/react`, `node_modules/motion`, `node_modules/vite` exist.

- [ ] **Step 3: Create `vite.config.js`**

```js
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],
    build: {
        outDir: 'public/js/dist',
        emptyOutDir: false,
        rollupOptions: {
            input: 'resources/react/booking-calendar/main.jsx',
            output: {
                entryFileNames: 'booking-calendar.js',
                assetFileNames: 'booking-calendar.[ext]',
            },
        },
    },
});
```

`emptyOutDir: false` because `public/js/dist/` may host other bundles later — this build must never wipe unrelated files.

- [ ] **Step 4: Create the placeholder `App.jsx`**

```jsx
export default function BookingCalendar() {
    return <div className="bcf-cal-loading">Calendar loading…</div>;
}
```

- [ ] **Step 5: Create `main.jsx`**

```jsx
import { createRoot } from 'react-dom/client';
import BookingCalendar from './App.jsx';

const rootEl = document.getElementById('bcf-calendar-root');
if (rootEl) {
    const facilities = JSON.parse(rootEl.getAttribute('data-facilities') || '[]');
    const initialFacilityId = parseInt(rootEl.getAttribute('data-initial-facility-id') || '0', 10);
    const initialYear = parseInt(rootEl.getAttribute('data-initial-year') || '0', 10);
    const initialMonth = parseInt(rootEl.getAttribute('data-initial-month') || '0', 10);

    createRoot(rootEl).render(
        <BookingCalendar
            facilities={facilities}
            initialFacilityId={initialFacilityId}
            initialYear={initialYear}
            initialMonth={initialMonth}
        />
    );
}
```

- [ ] **Step 6: Build and verify output**

Run: `npm run build:calendar`
Expected: prints a Vite build summary; `public/js/dist/booking-calendar.js` exists and is non-empty.

Verify: `ls -la public/js/dist/booking-calendar.js` (or `Get-Item public/js/dist/booking-calendar.js` on Windows) shows a file size greater than 0 bytes.

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json vite.config.js resources/react/booking-calendar/main.jsx resources/react/booking-calendar/App.jsx public/js/dist/booking-calendar.js
git commit -m "chore: scaffold Vite+React build for booking calendar island"
```

(The mount div doesn't exist in `book_facility.php` yet — that's Task 7 — so this bundle isn't loaded by any page yet. That's expected; this task's deliverable is "the build pipeline works," not "the calendar renders.")

---

### Task 2: Calendar-day JSON endpoint

**Files:**
- Modify: `config/booking_calendar_status.php` (append one new function — file already defines `frs_facility_calendar_matrix()` at line 106, which this reuses unchanged)
- Create: `resources/views/pages/dashboard/book-facility-calendar-data.php`
- Modify: `index.php` (add one line to `$dashboardRouteMap`, around line 214 near `'booking-smart-hints'`)
- Test: `tests/Unit/BcfCalendarDayEntriesTest.php`

**Interfaces:**
- Produces: `frs_bcf_calendar_day_entries(string $todayISO, int $year, int $month, array $toneMatrix, array $demandMatrix, array $holidayMatrix): array` — a pure function (no PDO, no I/O) returning a list of per-day arrays with keys `date`, `day`, `tone`, `status_class`, `chip_label`, `is_today`, `is_pickable`, `holiday_name`, `holiday_type`, `demand_classification`, `demand_score`.
- Produces: `GET /dashboard/book-facility-calendar-data?facility_id=&year=&month=` returning `{"facility_id":int,"year":int,"month":int,"today":"YYYY-MM-DD","days":[...]}` (the `days` array is exactly `frs_bcf_calendar_day_entries()`'s return value, JSON-encoded).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/BcfCalendarDayEntriesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config/booking_calendar_status.php: frs_bcf_calendar_day_entries() — the
 * pure per-day formatter feeding the React calendar island's JSON endpoint.
 * Mirrors the tone/chip/pickability logic that used to live inline in
 * book_facility.php's day-cell render loop.
 */
final class BcfCalendarDayEntriesTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/booking_calendar_status.php';
    }

    public function testPastDayIsMarkedPastAndNotPickableEvenIfToneIsGreen(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-01' => 'green'], [], []);
        $day1 = $entries[0];
        $this->assertSame('2026-09-01', $day1['date']);
        $this->assertSame('past', $day1['tone']);
        $this->assertFalse($day1['is_pickable']);
        $this->assertSame('', $day1['chip_label']);
    }

    public function testOpenDayGetsApprovedStatusAndIsPickable(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-15' => 'green'], [], []);
        $day15 = $entries[14];
        $this->assertSame('2026-09-15', $day15['date']);
        $this->assertSame('green', $day15['tone']);
        $this->assertSame('status-approved', $day15['status_class']);
        $this->assertSame('Open', $day15['chip_label']);
        $this->assertTrue($day15['is_pickable']);
    }

    public function testFullyBookedDayIsStillPickable(): void
    {
        // Faithfully preserves book_facility.php's original behavior: 'red'
        // (fully booked) days remain clickable — the calendar tone is a
        // coarse hint, checkConflict() makes the real-time call.
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-20' => 'red'], [], []);
        $day20 = $entries[19];
        $this->assertSame('status-denied', $day20['status_class']);
        $this->assertSame('Full', $day20['chip_label']);
        $this->assertTrue($day20['is_pickable']);
    }

    public function testBlackoutDayIsNotPickable(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-20' => 'blackout'], [], []);
        $day20 = $entries[19];
        $this->assertSame('status-blackout', $day20['status_class']);
        $this->assertSame('Blackout', $day20['chip_label']);
        $this->assertFalse($day20['is_pickable']);
    }

    public function testHolidayAndDemandDataAttachToTheRightDay(): void
    {
        $holidays = ['2026-09-21' => ['date' => '2026-09-21', 'name' => 'Test Holiday', 'type' => 'regular']];
        $demand = ['2026-09-21' => ['score' => 80, 'classification' => 'Very High']];
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-21' => 'green'], $demand, $holidays);
        $day21 = $entries[20];
        $this->assertSame('Test Holiday', $day21['holiday_name']);
        $this->assertSame('regular', $day21['holiday_type']);
        $this->assertSame('Very High', $day21['demand_classification']);
        $this->assertSame(80, $day21['demand_score']);
    }

    public function testDemandIsIgnoredForPastDaysEvenIfPresentInMatrix(): void
    {
        $demand = ['2026-09-01' => ['score' => 80, 'classification' => 'Very High']];
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-01' => 'green'], $demand, []);
        $this->assertNull($entries[0]['demand_classification']);
        $this->assertNull($entries[0]['demand_score']);
    }

    public function testReturnsOneEntryPerDayInMonth(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, [], [], []);
        $this->assertCount(30, $entries); // September has 30 days
        $entriesFeb = frs_bcf_calendar_day_entries('2026-02-10', 2026, 2, [], [], []);
        $this->assertCount(28, $entriesFeb); // 2026 is not a leap year
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter BcfCalendarDayEntriesTest`
Expected: FAIL with `Error: Call to undefined function Tests\Unit\frs_bcf_calendar_day_entries()`

- [ ] **Step 3: Implement the function**

Append to `config/booking_calendar_status.php` (end of file):

```php
/**
 * Format one month's worth of day entries for the React booking-calendar
 * island's JSON endpoint. Pure — no PDO, no I/O — mirrors the tone/chip/
 * pickability logic that used to live inline in book_facility.php's
 * day-cell render loop (see resources/views/pages/dashboard/
 * book-facility-calendar-data.php for the caller that supplies the matrices).
 *
 * @param array<string,string> $toneMatrix date => tone, from frs_facility_calendar_matrix()
 * @param array<string,array{score:int,classification:string}> $demandMatrix date => demand
 * @param array<string,array{name:string,type:string}> $holidayMatrix date => holiday
 * @return list<array{date:string,day:int,tone:string,status_class:string,
 *   chip_label:string,is_today:bool,is_pickable:bool,holiday_name:?string,
 *   holiday_type:?string,demand_classification:?string,demand_score:?int}>
 */
function frs_bcf_calendar_day_entries(
    string $todayISO,
    int $year,
    int $month,
    array $toneMatrix,
    array $demandMatrix,
    array $holidayMatrix
): array {
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $entries = [];

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $tone = $toneMatrix[$iso] ?? 'green';

        $statusClass = '';
        $chipLabel = '';

        if ($iso < $todayISO) {
            $tone = 'past';
        } elseif ($tone === 'green') {
            $statusClass = 'status-approved';
            $chipLabel = 'Open';
        } elseif ($tone === 'yellow') {
            $statusClass = 'status-pending';
            $chipLabel = 'Busy';
        } elseif ($tone === 'red') {
            $statusClass = 'status-denied';
            $chipLabel = 'Full';
        } elseif ($tone === 'blackout') {
            $statusClass = 'status-blackout';
            $chipLabel = 'Blackout';
        } elseif ($tone === 'cimm_maintenance') {
            $statusClass = 'status-cimm-maintenance';
            $chipLabel = 'Sched. maint.';
        } elseif ($tone === 'maintenance') {
            $statusClass = 'status-blackout';
            $chipLabel = 'Maintenance';
        } elseif ($tone === 'offline') {
            $statusClass = 'status-blackout';
            $chipLabel = 'Offline';
        }

        $isPickable = ($iso >= $todayISO) && in_array($tone, ['green', 'yellow', 'red'], true);

        $holidayName = null;
        $holidayType = null;
        if (isset($holidayMatrix[$iso])) {
            $holidayName = (string)$holidayMatrix[$iso]['name'];
            $holidayType = (string)$holidayMatrix[$iso]['type'];
        }

        $demandClassification = null;
        $demandScore = null;
        if ($iso >= $todayISO && isset($demandMatrix[$iso])) {
            $demandClassification = (string)$demandMatrix[$iso]['classification'];
            $demandScore = (int)$demandMatrix[$iso]['score'];
        }

        $entries[] = [
            'date' => $iso,
            'day' => $day,
            'tone' => $tone,
            'status_class' => $statusClass,
            'chip_label' => $chipLabel,
            'is_today' => $iso === $todayISO,
            'is_pickable' => $isPickable,
            'holiday_name' => $holidayName,
            'holiday_type' => $holidayType,
            'demand_classification' => $demandClassification,
            'demand_score' => $demandScore,
        ];
    }

    return $entries;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter BcfCalendarDayEntriesTest`
Expected: `OK (7 tests, ...)` — all pass.

- [ ] **Step 5: Create the JSON endpoint**

Create `resources/views/pages/dashboard/book-facility-calendar-data.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/booking_calendar_status.php';
require_once __DIR__ . '/../../../../services/PredictionService.php';
require_once __DIR__ . '/../../../../services/HolidayService.php';

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$facilityId = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

if ($facilityId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'facility_id is required']);
    exit;
}
if ($month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid month']);
    exit;
}
if ($year < 2000 || $year > 2100) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
    exit;
}

try {
    $pdo = db();
    $todayISO = date('Y-m-d');

    $toneMatrix = frs_facility_calendar_matrix($pdo, $facilityId, $year, $month);

    // Same 60-day-advance demand forecast averaging book_facility.php did
    // inline before this endpoint existed (see that file's git history for
    // the pre-extraction version of this block).
    $demandMatrix = [];
    $predictionService = new PredictionService($pdo);
    $monthForecast = $predictionService->getFacilityDemandForecast($facilityId, 60);
    foreach ($monthForecast as $dayForecast) {
        $date = $dayForecast['date'];
        $slots = $dayForecast['slots'] ?? [];
        $dataBackedSlots = array_filter($slots, fn($slot) => !empty($slot['has_sufficient_data']));
        if (empty($dataBackedSlots)) {
            continue;
        }
        $totalScore = 0;
        foreach ($dataBackedSlots as $slot) {
            $totalScore += $slot['score'];
        }
        $avgScore = (int)round($totalScore / count($dataBackedSlots));
        $classification = 'Low';
        if ($avgScore >= 76) {
            $classification = 'Very High';
        } elseif ($avgScore >= 51) {
            $classification = 'High';
        } elseif ($avgScore >= 26) {
            $classification = 'Medium';
        }
        $demandMatrix[$date] = ['score' => $avgScore, 'classification' => $classification];
    }

    $holidayMatrix = [];
    $holidayService = new HolidayService();
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, (int)date('t', mktime(0, 0, 0, $month, 1, $year)));
    foreach ($holidayService->getHolidaysInRange($monthStart, $monthEnd) as $holiday) {
        $holidayMatrix[$holiday['date']] = $holiday;
    }

    $entries = frs_bcf_calendar_day_entries($todayISO, $year, $month, $toneMatrix, $demandMatrix, $holidayMatrix);

    echo json_encode([
        'facility_id' => $facilityId,
        'year' => $year,
        'month' => $month,
        'today' => $todayISO,
        'days' => $entries,
    ]);
} catch (Throwable $e) {
    error_log('book-facility-calendar-data API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load calendar data']);
}
```

- [ ] **Step 6: Register the route**

In `index.php`, inside `$dashboardRouteMap` (starts at line 183), add one line next to the other booking-related routes:

```php
        'booking-smart-hints' => 'booking_smart_hints_api.php',
        'book-facility-calendar-data' => 'book-facility-calendar-data.php',
```

- [ ] **Step 7: Syntax-check both PHP files**

Run: `php -l resources/views/pages/dashboard/book-facility-calendar-data.php`
Expected: `No syntax errors detected`

Run: `php -l config/booking_calendar_status.php`
Expected: `No syntax errors detected`

Run: `php -l index.php`
Expected: `No syntax errors detected`

Note: full end-to-end verification of this endpoint (an authenticated browser hitting it and getting real data back) happens naturally in Task 7, once the React UI calls it. That's this task's boundary — the pure function is unit-tested now; the HTTP wiring is syntax-verified now; the live round-trip is proven when the UI that consumes it exists.

- [ ] **Step 8: Commit**

```bash
git add config/booking_calendar_status.php resources/views/pages/dashboard/book-facility-calendar-data.php index.php tests/Unit/BcfCalendarDayEntriesTest.php
git commit -m "feat: add JSON calendar-data endpoint for the React booking calendar island"
```

---

### Task 3: React calendar grid + navigation

**Files:**
- Modify: `resources/react/booking-calendar/App.jsx` (replace the Task 1 placeholder)

**Interfaces:**
- Consumes: `GET /dashboard/book-facility-calendar-data?facility_id=&year=&month=` (Task 2), returning `{facility_id, year, month, today, days: [{date, day, tone, status_class, chip_label, is_today, is_pickable, holiday_name, holiday_type, demand_classification, demand_score}]}`.
- Consumes props from `main.jsx` (Task 1): `facilities` (`[{id, name}]`), `initialFacilityId`, `initialYear`, `initialMonth`.
- Produces: `window.bcfCalendarGetState()` returning `{year, month, facilityId}` — used by Task 5.
- Produces (for Task 4 to call into, not defined yet): none yet — day clicks are inert in this task, wired in Task 4.

- [ ] **Step 1: Replace `App.jsx` with the full grid + navigation component**

```jsx
import { useState, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'motion/react';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

function basePath() {
    return (window.APP_BASE_PATH || '').replace(/\/$/, '');
}

function firstWeekdayOfMonth(year, month) {
    return new Date(year, month - 1, 1).getDay();
}

export default function BookingCalendar({ facilities, initialFacilityId, initialYear, initialMonth }) {
    const [facilityId, setFacilityId] = useState(initialFacilityId || 0);
    const [year, setYear] = useState(initialYear || new Date().getFullYear());
    const [month, setMonth] = useState(initialMonth || new Date().getMonth() + 1);
    const [days, setDays] = useState([]);
    const [loading, setLoading] = useState(false);

    const loadCalendar = useCallback(async (fid, y, m) => {
        if (!fid) {
            setDays([]);
            return;
        }
        setLoading(true);
        try {
            const url = basePath() + '/dashboard/book-facility-calendar-data'
                + '?facility_id=' + encodeURIComponent(fid)
                + '&year=' + encodeURIComponent(y)
                + '&month=' + encodeURIComponent(m);
            const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await res.json();
            setDays(Array.isArray(data.days) ? data.days : []);
        } catch (err) {
            console.error('book-facility-calendar-data fetch failed', err);
            setDays([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadCalendar(facilityId, year, month);
    }, [facilityId, year, month, loadCalendar]);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        params.set('year', String(year));
        params.set('month', String(month));
        if (facilityId) {
            params.set('book_fac', String(facilityId));
        }
        window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }, [facilityId, year, month]);

    useEffect(() => {
        window.bcfCalendarGetState = () => ({ year, month, facilityId });
    }, [year, month, facilityId]);

    const monthLabel = MONTH_NAMES[month - 1] + ' ' + year;
    const leadingBlanks = facilityId ? firstWeekdayOfMonth(year, month) : 0;
    const yearOptions = [0, 1, 2].map((offset) => new Date().getFullYear() + offset);

    return (
        <div className="bcf-cal-toolbar-wrap">
            <div className="bcf-cal-month-heading">{monthLabel}</div>
            <div className="bcf-cal-toolbar-grid">
                <div className="bcf-cal-fac-field">
                    <label className="bcf-cal-fac-field-label" htmlFor="book-fac-cal-select">Facility</label>
                    <div className="bcf-cal-shell">
                        <i className="bi bi-building" aria-hidden="true"></i>
                        <select
                            id="book-fac-cal-select"
                            className="bcf-cal-fac-select"
                            aria-label="Choose facility for calendar"
                            value={facilityId}
                            onChange={(e) => setFacilityId(parseInt(e.target.value, 10))}
                        >
                            <option value="0">Choose a facility…</option>
                            {facilities.map((f) => (
                                <option key={f.id} value={f.id}>{f.name}</option>
                            ))}
                        </select>
                    </div>
                </div>
                <div className="bcf-cal-nav-cluster">
                    <select
                        className="bcf-cal-month-select"
                        aria-label="Select month"
                        value={month}
                        onChange={(e) => setMonth(parseInt(e.target.value, 10))}
                    >
                        {MONTH_NAMES.map((name, idx) => (
                            <option key={name} value={idx + 1}>{name}</option>
                        ))}
                    </select>
                    <select
                        className="bcf-cal-year-select"
                        aria-label="Select year"
                        value={year}
                        onChange={(e) => setYear(parseInt(e.target.value, 10))}
                    >
                        {yearOptions.map((y) => (
                            <option key={y} value={y}>{y}</option>
                        ))}
                    </select>
                    <button
                        type="button"
                        className="btn-outline bcf-cal-nav-btn"
                        onClick={() => {
                            const now = new Date();
                            setYear(now.getFullYear());
                            setMonth(now.getMonth() + 1);
                        }}
                    >
                        Today
                    </button>
                </div>
            </div>

            <div className="my-reservations-calendar" style={{ minHeight: 'auto' }}>
                <div className="my-reservations-calendar-grid">
                    {WEEKDAYS.map((w) => (
                        <div key={w} className="my-reservations-calendar-dayname">{w}</div>
                    ))}
                    {Array.from({ length: leadingBlanks }, (_, i) => (
                        <div key={'blank-' + i} className="my-reservations-calendar-cell empty"></div>
                    ))}
                    <AnimatePresence mode="popLayout">
                        {days.map((entry) => (
                            <CalendarCell key={entry.date} entry={entry} />
                        ))}
                    </AnimatePresence>
                </div>
            </div>
            {loading && <div className="bcf-cal-loading" aria-live="polite">Loading availability…</div>}
        </div>
    );
}

function CalendarCell({ entry }) {
    const cls = [
        'my-reservations-calendar-cell',
        entry.is_today ? 'today' : '',
        !entry.is_pickable ? 'empty' : '',
        entry.status_class || '',
        entry.is_pickable ? 'bcf-book-cal-cell' : '',
    ].filter(Boolean).join(' ');

    const demandClass = entry.demand_classification
        ? 'demand-' + entry.demand_classification.toLowerCase().replace(/\s+/g, '-')
        : '';

    return (
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.18 }}
            className={cls}
            data-cal-date={entry.date}
            {...(entry.is_pickable ? { role: 'button', tabIndex: 0, 'data-bcf-date': entry.date } : {})}
        >
            <div className="date-label">{entry.day}</div>
            {entry.chip_label && (
                <div className="status-chip" title={entry.chip_label}>{entry.chip_label}</div>
            )}
            {entry.holiday_name && (
                <div className="holiday-indicator" title={entry.holiday_name + ' (' + entry.holiday_type + ')'}>
                    <i className="bi bi-calendar-event"></i>
                </div>
            )}
            {entry.demand_classification && (
                <div className={'demand-strip ' + demandClass} title={'Demand: ' + entry.demand_classification}>
                    <span className="demand-score">{entry.demand_classification}</span>
                </div>
            )}
        </motion.div>
    );
}
```

- [ ] **Step 2: Build**

Run: `npm run build:calendar`
Expected: builds without error.

- [ ] **Step 3: Manual verification (temporary test page)**

Create a scratch file `public/_bcf_calendar_test.html` (not committed — deleted in Step 5) to verify the component renders and fetches correctly outside the full dashboard page, since `book_facility.php` isn't wired up yet (that's Task 7):

```html
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Calendar island smoke test</title></head>
<body>
<div id="bcf-calendar-root"
     data-initial-facility-id="1"
     data-initial-year="2026"
     data-initial-month="9"
     data-facilities='[{"id":1,"name":"Test Facility"}]'>
</div>
<script>window.APP_BASE_PATH = '';</script>
<script type="module" src="/js/dist/booking-calendar.js"></script>
</body>
</html>
```

Open this file through the same PHP dev server/vhost used for the rest of the app (so `/dashboard/book-facility-calendar-data` resolves and the request carries a logged-in session cookie), logged in as any dashboard user. Confirm: the facility/month/year selects render, changing them re-fetches and re-renders the grid, and cells show the correct Open/Busy/Full/Blackout labels matching what the live `book_facility.php` page currently shows for facility id 1 in that month.

- [ ] **Step 4: Delete the scratch test file**

```bash
rm public/_bcf_calendar_test.html
```

- [ ] **Step 5: Commit**

```bash
git add resources/react/booking-calendar/App.jsx public/js/dist/booking-calendar.js
git commit -m "feat: render calendar grid and navigation in the React booking calendar island"
```

---

### Task 4: Day-click bridge into the existing booking modal

**Files:**
- Modify: `resources/react/booking-calendar/App.jsx`
- Modify: `resources/views/pages/dashboard/book_facility.php:5010-5036` (the `activateBookingCalDate` function and its two call sites)

**Interfaces:**
- Consumes: `window.bcfCalendarActivateDate(dateIso, facilityId)` — produced by this task's `book_facility.php` change, called by this task's `App.jsx` change.

- [ ] **Step 1: Refactor `activateBookingCalDate` to take plain values instead of a DOM cell**

In `book_facility.php`, find (around line 5007-5037):

```javascript
    const bookPane = document.getElementById('booking-pane-book');
    if (bookPane && !bookPane.dataset.bcfCalDelegated) {
        bookPane.dataset.bcfCalDelegated = '1';
        function activateBookingCalDate(cell) {
            const ds = cell.getAttribute('data-bcf-date');
            if (!ds || !dateInput || !facilitySel) return;
            dateInput.value = ds;
            dateInput.dispatchEvent(new Event('input', { bubbles: true }));
            dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            const calSel = document.getElementById('book-fac-cal-select');
            if (calSel && calSel.value) {
                facilitySel.value = calSel.value;
                facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            openBookingFlowModal();
            debouncedRefillAvail();
            setTimeout(debouncedCheckConflict, 200);
        }
        bookPane.addEventListener('click', function (e) {
            const cell = e.target.closest('.bcf-book-cal-cell');
            if (cell) activateBookingCalDate(cell);
        });
        bookPane.addEventListener('keydown', function (e) {
            const cell = e.target.closest('.bcf-book-cal-cell');
            if (!cell) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activateBookingCalDate(cell);
            }
        });
    }
```

Replace with:

```javascript
    const bookPane = document.getElementById('booking-pane-book');
    if (bookPane && !bookPane.dataset.bcfCalDelegated) {
        bookPane.dataset.bcfCalDelegated = '1';
        function activateBookingCalDate(dateIso, facilityId) {
            if (!dateIso || !dateInput || !facilitySel) return;
            dateInput.value = dateIso;
            dateInput.dispatchEvent(new Event('input', { bubbles: true }));
            dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            if (facilityId) {
                facilitySel.value = String(facilityId);
                facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            openBookingFlowModal();
            debouncedRefillAvail();
            setTimeout(debouncedCheckConflict, 200);
        }
        window.bcfCalendarActivateDate = activateBookingCalDate;
        // Legacy delegated listeners: harmless once the React island (Task 7)
        // replaces the server-rendered .bcf-book-cal-cell markup, since that
        // selector will no longer match anything. Left in place rather than
        // deleted, matching this feature's rollback-safety approach.
        bookPane.addEventListener('click', function (e) {
            const cell = e.target.closest('.bcf-book-cal-cell');
            if (cell) {
                const calSel = document.getElementById('book-fac-cal-select');
                activateBookingCalDate(cell.getAttribute('data-bcf-date'), calSel ? calSel.value : null);
            }
        });
        bookPane.addEventListener('keydown', function (e) {
            const cell = e.target.closest('.bcf-book-cal-cell');
            if (!cell) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const calSel = document.getElementById('book-fac-cal-select');
                activateBookingCalDate(cell.getAttribute('data-bcf-date'), calSel ? calSel.value : null);
            }
        });
    }
```

- [ ] **Step 2: Syntax-check**

Run: `php -l resources/views/pages/dashboard/book_facility.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Wire the click handler into `CalendarCell` in `App.jsx`**

In `resources/react/booking-calendar/App.jsx`, change the `CalendarCell` function to accept the facility id and call the bridge:

Find:
```jsx
function CalendarCell({ entry }) {
```

Replace with:
```jsx
function CalendarCell({ entry, facilityId }) {
    function handleActivate() {
        if (!entry.is_pickable) return;
        if (typeof window.bcfCalendarActivateDate === 'function') {
            window.bcfCalendarActivateDate(entry.date, facilityId);
        }
    }
```

(Keep the rest of the function body — the `cls`/`demandClass` computation and the `return (...)` — unchanged, but add the click/keyboard handlers to the returned `motion.div`.)

Find, inside the same function's `return`:
```jsx
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.18 }}
            className={cls}
            data-cal-date={entry.date}
            {...(entry.is_pickable ? { role: 'button', tabIndex: 0, 'data-bcf-date': entry.date } : {})}
        >
```

Replace with:
```jsx
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.18 }}
            className={cls}
            data-cal-date={entry.date}
            {...(entry.is_pickable ? {
                role: 'button',
                tabIndex: 0,
                'data-bcf-date': entry.date,
                onClick: handleActivate,
                onKeyDown: (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        handleActivate();
                    }
                },
            } : {})}
        >
```

- [ ] **Step 4: Pass `facilityId` down from the grid**

In the same file, find where `CalendarCell` is rendered:
```jsx
                        {days.map((entry) => (
                            <CalendarCell key={entry.date} entry={entry} />
                        ))}
```

Replace with:
```jsx
                        {days.map((entry) => (
                            <CalendarCell key={entry.date} entry={entry} facilityId={facilityId} />
                        ))}
```

- [ ] **Step 5: Build**

Run: `npm run build:calendar`
Expected: builds without error.

- [ ] **Step 6: Manual verification**

Repeat Task 3 Step 3's scratch-page setup (recreate `public/_bcf_calendar_test.html`, same content as before). Click a pickable day. Confirm: no JS console error is thrown (the modal itself won't open in this standalone test page since `#booking-flow-modal` doesn't exist there — that's expected; the deliverable being verified here is that `window.bcfCalendarActivateDate` is called without throwing, not the modal's own behavior, which is proven in Task 7's full-page integration). Delete the scratch file again afterward.

- [ ] **Step 7: Commit**

```bash
git add resources/react/booking-calendar/App.jsx public/js/dist/booking-calendar.js resources/views/pages/dashboard/book_facility.php
git commit -m "feat: bridge calendar day clicks from React into the existing booking modal flow"
```

---

### Task 5: AI purpose-hints bridge

**Files:**
- Modify: `resources/react/booking-calendar/App.jsx`
- Modify: `resources/views/pages/dashboard/book_facility.php` (three call sites: `bcfClearCalendarAiHints` ~line 3397, `bcfApplyCalendarAiHints` ~line 3408, `bcfFetchBookingSmartHints` ~line 3451)

**Interfaces:**
- Consumes: none new.
- Produces: `window.bcfCalendarSetHighlights(isoDates: string[])` — called by the existing (modified) `bcfApplyCalendarAiHints`/`bcfClearCalendarAiHints` instead of them touching `classList` directly.

- [ ] **Step 1: Add highlight state to `App.jsx`**

In `resources/react/booking-calendar/App.jsx`, inside the `BookingCalendar` function, add a new piece of state and expose the bridge function. Find:

```jsx
    useEffect(() => {
        window.bcfCalendarGetState = () => ({ year, month, facilityId });
    }, [year, month, facilityId]);
```

Replace with:

```jsx
    const [highlightedDates, setHighlightedDates] = useState(() => new Set());

    useEffect(() => {
        window.bcfCalendarGetState = () => ({ year, month, facilityId });
    }, [year, month, facilityId]);

    useEffect(() => {
        window.bcfCalendarSetHighlights = (isoDates) => {
            setHighlightedDates(new Set(Array.isArray(isoDates) ? isoDates : []));
        };
        return () => {
            delete window.bcfCalendarSetHighlights;
        };
    }, []);
```

- [ ] **Step 2: Pass highlight state into each cell**

Find:
```jsx
                        {days.map((entry) => (
                            <CalendarCell key={entry.date} entry={entry} facilityId={facilityId} />
                        ))}
```

Replace with:
```jsx
                        {days.map((entry) => (
                            <CalendarCell
                                key={entry.date}
                                entry={entry}
                                facilityId={facilityId}
                                highlighted={highlightedDates.has(entry.date)}
                            />
                        ))}
```

- [ ] **Step 3: Apply the highlight class in `CalendarCell`**

Find:
```jsx
function CalendarCell({ entry, facilityId }) {
    function handleActivate() {
        if (!entry.is_pickable) return;
        if (typeof window.bcfCalendarActivateDate === 'function') {
            window.bcfCalendarActivateDate(entry.date, facilityId);
        }
    }
    const cls = [
        'my-reservations-calendar-cell',
        entry.is_today ? 'today' : '',
        !entry.is_pickable ? 'empty' : '',
        entry.status_class || '',
        entry.is_pickable ? 'bcf-book-cal-cell' : '',
    ].filter(Boolean).join(' ');
```

Replace with:
```jsx
function CalendarCell({ entry, facilityId, highlighted }) {
    function handleActivate() {
        if (!entry.is_pickable) return;
        if (typeof window.bcfCalendarActivateDate === 'function') {
            window.bcfCalendarActivateDate(entry.date, facilityId);
        }
    }
    const cls = [
        'my-reservations-calendar-cell',
        entry.is_today ? 'today' : '',
        !entry.is_pickable ? 'empty' : '',
        entry.status_class || '',
        entry.is_pickable ? 'bcf-book-cal-cell' : '',
        highlighted ? 'bcf-ai-suggest-date' : '',
    ].filter(Boolean).join(' ');
```

- [ ] **Step 4: Build**

Run: `npm run build:calendar`
Expected: builds without error.

- [ ] **Step 5: Update `bcfClearCalendarAiHints` in `book_facility.php`**

Find (around line 3397):
```javascript
    function bcfClearCalendarAiHints() {
        document.querySelectorAll('.bcf-ai-suggest-date').forEach(function (el) {
            el.classList.remove('bcf-ai-suggest-date');
        });
        const bar = document.getElementById('bcf-smart-hints-bar');
        if (bar) {
            bar.classList.remove('is-visible');
            bar.innerHTML = '';
        }
    }
```

Replace with:
```javascript
    function bcfClearCalendarAiHints() {
        if (typeof window.bcfCalendarSetHighlights === 'function') {
            window.bcfCalendarSetHighlights([]);
        } else {
            document.querySelectorAll('.bcf-ai-suggest-date').forEach(function (el) {
                el.classList.remove('bcf-ai-suggest-date');
            });
        }
        const bar = document.getElementById('bcf-smart-hints-bar');
        if (bar) {
            bar.classList.remove('is-visible');
            bar.innerHTML = '';
        }
    }
```

(The `else` branch is a safety fallback for the brief window before the React bundle finishes loading — harmless, matches nothing once the island is live.)

- [ ] **Step 6: Update `bcfApplyCalendarAiHints` in `book_facility.php`**

Find (around line 3408-3449), the block:
```javascript
        const dates = payload && payload.highlight_dates ? payload.highlight_dates : [];
        const dateSet = new Set(dates);
        document.querySelectorAll('[data-cal-date]').forEach(function (cell) {
            const d = cell.getAttribute('data-cal-date');
            if (d && dateSet.has(d)) {
                cell.classList.add('bcf-ai-suggest-date');
            }
        });
```

Replace with:
```javascript
        const dates = payload && payload.highlight_dates ? payload.highlight_dates : [];
        if (typeof window.bcfCalendarSetHighlights === 'function') {
            window.bcfCalendarSetHighlights(dates);
        } else {
            const dateSet = new Set(dates);
            document.querySelectorAll('[data-cal-date]').forEach(function (cell) {
                const d = cell.getAttribute('data-cal-date');
                if (d && dateSet.has(d)) {
                    cell.classList.add('bcf-ai-suggest-date');
                }
            });
        }
```

Also in the same function, find this later line (still inside `bcfApplyCalendarAiHints`, building the "Show this facility on the calendar" link):
```javascript
        if (payload.primary_facility_id) {
            const u = basePath + '/dashboard/book-facility?year=' + encodeURIComponent(String(window._bcfCalYear)) + '&month=' + encodeURIComponent(String(window._bcfCalMonth)) + '&book_fac=' + encodeURIComponent(String(payload.primary_facility_id));
            html += '<div class="bcf-smart-hints-actions"><a class="btn-outline bcf-smart-hints-link" data-frs-partial="bcf-calendar" href="' + u + '">Show this facility on the calendar</a></div>';
        }
```

Replace with:
```javascript
        if (payload.primary_facility_id) {
            const calState = typeof window.bcfCalendarGetState === 'function'
                ? window.bcfCalendarGetState()
                : { year: window._bcfCalYear, month: window._bcfCalMonth };
            const u = basePath + '/dashboard/book-facility?year=' + encodeURIComponent(String(calState.year)) + '&month=' + encodeURIComponent(String(calState.month)) + '&book_fac=' + encodeURIComponent(String(payload.primary_facility_id));
            // Plain full-page link, not a data-frs-partial fragment swap: the
            // bcf-calendar partial region no longer exists once the React
            // island (Task 7) replaces that markup. A full navigation still
            // lands on the right facility/month/year since book_facility.php
            // reads these from $_GET on load and seeds the island's initial
            // state from them.
            html += '<div class="bcf-smart-hints-actions"><a class="btn-outline bcf-smart-hints-link" href="' + u + '">Show this facility on the calendar</a></div>';
        }
```

- [ ] **Step 7: Update `bcfFetchBookingSmartHints` in `book_facility.php`**

Find (around line 3451-3466):
```javascript
        const attEl = document.getElementById('bcf-purpose-attendees-preview');
        let fd = new URLSearchParams();
        fd.append('purpose', purpose);
        fd.append('year', String(window._bcfCalYear));
        fd.append('month', String(window._bcfCalMonth));
```

Replace with:
```javascript
        const attEl = document.getElementById('bcf-purpose-attendees-preview');
        const calState = typeof window.bcfCalendarGetState === 'function'
            ? window.bcfCalendarGetState()
            : { year: window._bcfCalYear, month: window._bcfCalMonth };
        let fd = new URLSearchParams();
        fd.append('purpose', purpose);
        fd.append('year', String(calState.year));
        fd.append('month', String(calState.month));
```

- [ ] **Step 8: Syntax-check**

Run: `php -l resources/views/pages/dashboard/book_facility.php`
Expected: `No syntax errors detected`

- [ ] **Step 9: Manual verification**

Recreate the Task 3 scratch page (`public/_bcf_calendar_test.html`). In the browser console, run:
```javascript
window.bcfCalendarSetHighlights(['2026-09-15', '2026-09-20']);
```
Confirm: the corresponding day cells (if visible in the currently-loaded month) gain the purple `bcf-ai-suggest-date` ring styling. Run `window.bcfCalendarSetHighlights([])` and confirm the ring disappears. Delete the scratch file afterward.

- [ ] **Step 10: Commit**

```bash
git add resources/react/booking-calendar/App.jsx public/js/dist/booking-calendar.js resources/views/pages/dashboard/book_facility.php
git commit -m "feat: bridge AI purpose-hints highlighting into the React booking calendar island"
```

---

### Task 6: Motion polish — reduced motion + AI-ring transition

**Files:**
- Modify: `resources/react/booking-calendar/App.jsx`
- Modify: `resources/views/pages/dashboard/book_facility.php` (one CSS rule, near line 1863)

**Interfaces:** none new — this task only refines existing behavior from Tasks 3 and 5.

Note on scope: the approved design said motion.dev would animate the AI-suggested ring appearing. On implementation, a plain CSS `transition` on the existing `.bcf-ai-suggest-date` rule achieves the identical visual result with far less code, since the ring is a same-node class toggle (not a mount/unmount), which is exactly what CSS transitions are for. motion.dev stays responsible for the one thing it's actually needed for: the day-cell grid's fade-in when `days` changes wholesale on month/facility change (already implemented in Task 3). This task keeps that scope split and adds the reduced-motion guard to the part that does use motion.dev.

- [ ] **Step 1: Add a reduced-motion guard to the grid's fade-in**

In `resources/react/booking-calendar/App.jsx`, add a module-level constant near the top of the file (after the existing imports/constants):

Find:
```jsx
function firstWeekdayOfMonth(year, month) {
    return new Date(year, month - 1, 1).getDay();
}
```

Replace with:
```jsx
function firstWeekdayOfMonth(year, month) {
    return new Date(year, month - 1, 1).getDay();
}

const REDUCE_MOTION = typeof window !== 'undefined'
    && window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
```

- [ ] **Step 2: Use it in `CalendarCell`'s motion props**

Find:
```jsx
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.18 }}
            className={cls}
```

Replace with:
```jsx
        <motion.div
            initial={REDUCE_MOTION ? false : { opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: REDUCE_MOTION ? 0 : 0.18 }}
            className={cls}
```

(`initial={false}` tells motion.dev to skip the enter animation entirely and render directly in the `animate` state — the standard way to disable entrance animations per-component.)

- [ ] **Step 3: Add a CSS transition for the AI-ring class**

In `resources/views/pages/dashboard/book_facility.php`, find (around line 1863):
```css
.my-reservations-calendar-cell.bcf-ai-suggest-date:not(.empty) {
    box-shadow: inset 0 0 0 2px #7c3aed;
    background: linear-gradient(135deg, rgba(124, 58, 237, 0.07), rgba(255, 255, 255, 0));
}
```

Replace with:
```css
.my-reservations-calendar-cell {
    transition: box-shadow 0.25s ease, background 0.25s ease;
}
.my-reservations-calendar-cell.bcf-ai-suggest-date:not(.empty) {
    box-shadow: inset 0 0 0 2px #7c3aed;
    background: linear-gradient(135deg, rgba(124, 58, 237, 0.07), rgba(255, 255, 255, 0));
}
@media (prefers-reduced-motion: reduce) {
    .my-reservations-calendar-cell {
        transition: none;
    }
}
```

- [ ] **Step 4: Build and syntax-check**

Run: `npm run build:calendar`
Expected: builds without error.

Run: `php -l resources/views/pages/dashboard/book_facility.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Manual verification**

Recreate the Task 3 scratch page. In DevTools, enable "Emulate CSS prefers-reduced-motion: reduce" (Chrome: Rendering tab). Reload the page and change the month — confirm cells appear instantly with no fade/slide. Turn emulation off, reload, change the month again — confirm cells fade in smoothly. Run `window.bcfCalendarSetHighlights(['2026-09-15'])` in the console and confirm the purple ring fades in over ~0.25s rather than popping instantly. Delete the scratch file afterward.

- [ ] **Step 6: Commit**

```bash
git add resources/react/booking-calendar/App.jsx public/js/dist/booking-calendar.js resources/views/pages/dashboard/book_facility.php
git commit -m "feat: respect prefers-reduced-motion and smooth the AI-suggested ring transition"
```

---

### Task 7: Wire the island into `book_facility.php`

**Files:**
- Modify: `resources/views/pages/dashboard/book_facility.php` (remove dead PHP variable computation ~line 1091-1168 except `$todayISO`; replace calendar+toolbar markup ~line 2254-2410 with the mount div + script tag)

**Interfaces:** none new — this task only changes what's rendered, using everything built in Tasks 1-6.

- [ ] **Step 1: Remove the now-unused PHP variable computation**

Find (lines 1091-1168 — verify against the current file, since line numbers may have shifted slightly from earlier tasks' edits elsewhere in the file):

```php
$calendarToneMatrix = [];
if ($bookFacilityPick > 0) {
    $calendarToneMatrix = frs_facility_calendar_matrix($pdo, $bookFacilityPick, $bookCalYear, $bookCalMonth);
}

// Get demand forecast for the selected facility and month
$demandForecastMatrix = [];
if ($bookFacilityPick > 0) {
    $predictionService = new PredictionService($pdo);

    // Get forecast for 60 days (booking advance window) instead of just days in month
    $advanceBookingDays = 60;
    $monthForecast = $predictionService->getFacilityDemandForecast($bookFacilityPick, $advanceBookingDays);

    if (!empty($monthForecast)) {
        foreach ($monthForecast as $dayForecast) {
            $date = $dayForecast['date'];
            $slots = $dayForecast['slots'] ?? [];

            // Only average slots that actually have enough historical bookings
            // (PredictionService::predictDemand()) to mean something - a slot
            // below that threshold falls back to a hardcoded placeholder score,
            // and averaging that in made every lightly-booked facility show the
            // same fake "Medium" on nearly every date.
            $dataBackedSlots = array_filter($slots, fn($slot) => !empty($slot['has_sufficient_data']));

            if (!empty($dataBackedSlots)) {
                $totalScore = 0;
                $slotCount = count($dataBackedSlots);

                foreach ($dataBackedSlots as $slot) {
                    $totalScore += $slot['score'];
                }

                $avgScore = $slotCount > 0 ? round($totalScore / $slotCount) : 0;

                // Determine classification
                $classification = 'Low';
                if ($avgScore >= 76) $classification = 'Very High';
                elseif ($avgScore >= 51) $classification = 'High';
                elseif ($avgScore >= 26) $classification = 'Medium';

                $demandForecastMatrix[$date] = [
                    'score' => $avgScore,
                    'classification' => $classification
                ];
            }
        }
    }
}

// Get Philippines holidays for the selected calendar month/year
$holidayMatrix = [];
$holidayData = [];
$holidayService = new HolidayService();

$monthStart = sprintf('%04d-%02d-01', $bookCalYear, $bookCalMonth);
$monthEnd = sprintf('%04d-%02d-%02d', $bookCalYear, $bookCalMonth, date('t', mktime(0, 0, 0, $bookCalMonth, 1, $bookCalYear)));

$holidayList = $holidayService->getHolidaysInRange($monthStart, $monthEnd);

if (!empty($holidayList)) {
    foreach ($holidayList as $holiday) {
        $holidayMatrix[$holiday['date']] = $holiday;
        $holidayData[$holiday['date']] = [
            'name' => $holiday['name'],
            'type' => $holiday['type']
        ];
    }
}
$bookCalFirstDay = sprintf('%04d-%02d-01', $bookCalYear, $bookCalMonth);
$bookCalAnchor = new DateTimeImmutable($bookCalFirstDay);
$bookCalNavPrev = $bookCalAnchor->modify('-1 month');
$bookCalNavNext = $bookCalAnchor->modify('+1 month');
$bookCalMonthLabel = $bookCalAnchor->format('F Y');
$bookCalMonthTs = mktime(0, 0, 0, $bookCalMonth, 1, $bookCalYear);
$bookFirstWeekday = (int)date('w', $bookCalMonthTs);
$bookDaysInMonth = (int)date('t', $bookCalMonthTs);
$todayISO = date('Y-m-d');
```

Replace with (keeping only the one line still read elsewhere in the file, per the earlier grep confirming `$todayISO` is used again at the inline-script constant `BCF_TODAY_ISO`):

```php
$todayISO = date('Y-m-d');
```

- [ ] **Step 2: Replace the calendar block + toolbar markup**

Find (starts around line 2254, ends around line 2410 — the exact block is everything from the `<div data-frs-partial-id="bcf-calendar" ...>` wrapper through its matching closing `</div>` after the day-cell loop, i.e. the toolbar form, the legend, and the `.my-reservations-calendar-grid` loop):

```php
                <div data-frs-partial-id="bcf-calendar" data-frs-partial-root>
                <div class="bcf-cal-toolbar-wrap">
                    <div class="bcf-cal-month-heading"><?= htmlspecialchars($bookCalMonthLabel); ?></div>
                    <form method="get" action="<?= htmlspecialchars(base_path() . '/dashboard/book-facility'); ?>" class="bcf-cal-toolbar-form booking-cal-toolbar" data-frs-partial="bcf-calendar" data-frs-partial-auto>
                        <div class="bcf-cal-toolbar-grid">
                            <div class="bcf-cal-fac-field">
                                <label class="bcf-cal-fac-field-label" for="book-fac-cal-select">Facility</label>
                                <div class="bcf-cal-shell">
                                    <i class="bi bi-building" aria-hidden="true"></i>
                                    <select id="book-fac-cal-select" name="book_fac" class="bcf-cal-fac-select" aria-label="Choose facility for calendar">
                                        <option value="0">Choose a facility…</option>
                                        <?php foreach ($facilities as $f): ?>
                                            <option value="<?= (int)$f['id']; ?>" <?= $bookFacilityPick === (int)$f['id'] ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars((string)$f['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="bcf-cal-nav-cluster">
                                <select name="month" class="bcf-cal-month-select" aria-label="Select month">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m; ?>" <?= $bookCalMonth === $m ? 'selected' : ''; ?>>
                                            <?= date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <select name="year" class="bcf-cal-year-select" aria-label="Select year">
                                    <?php 
                                    $currentYear = (int)date('Y');
                                    for ($y = $currentYear; $y <= $currentYear + 2; $y++): ?>
                                        <option value="<?= $y; ?>" <?= $bookCalYear === $y ? 'selected' : ''; ?>>
                                            <?= $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <a class="btn-outline bcf-cal-nav-btn" data-frs-partial="bcf-calendar" href="<?= htmlspecialchars(base_path() . '/dashboard/book-facility' . $bookCalQuery(array_merge($bookPaneQuery, ['year' => (int)date('Y'), 'month' => (int)date('n')]))); ?>">Today</a>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="my-reservations-calendar" style="min-height:auto;">
                    <div class="my-reservations-calendar-header" style="margin-bottom:0.65rem;">
                        <div class="my-reservations-legend">
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#22c55e;"></span> Open</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#eab308;"></span> Partial bookings</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#ef4444;"></span> Fully booked</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#94a3b8;"></span> Blocked</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="border:2px solid #7c3aed; background:transparent;"></span> AI-suggested day</div>
                            <div class="my-reservations-legend-item my-reservations-legend-demand" style="margin-left: 1rem; border-left: 1px solid #e2e8f0; padding-left: 1rem;">
                                <span style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Demand:</span>
                            </div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#dcfce7;"></span> Low</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fef3c7;"></span> Med</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fed7aa;"></span> High</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fee2e2;"></span> Very High</div>
                        </div>
                    </div>
                    <div class="my-reservations-calendar-grid">
```

... (through the day-cell `<?php endfor; ?>` and its two closing `</div>` tags, then the closing `</div>` of the `data-frs-partial-id="bcf-calendar"` wrapper).

Replace the entire block with:

```php
                <div>
                    <div class="my-reservations-calendar-header" style="margin-bottom:0.65rem;">
                        <div class="my-reservations-legend">
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#22c55e;"></span> Open</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#eab308;"></span> Partial bookings</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#ef4444;"></span> Fully booked</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#94a3b8;"></span> Blocked</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="border:2px solid #7c3aed; background:transparent;"></span> AI-suggested day</div>
                            <div class="my-reservations-legend-item my-reservations-legend-demand" style="margin-left: 1rem; border-left: 1px solid #e2e8f0; padding-left: 1rem;">
                                <span style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Demand:</span>
                            </div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#dcfce7;"></span> Low</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fef3c7;"></span> Med</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fed7aa;"></span> High</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fee2e2;"></span> Very High</div>
                        </div>
                    </div>
                    <div id="bcf-calendar-root"
                         data-initial-facility-id="<?= (int)$bookFacilityPick; ?>"
                         data-initial-year="<?= (int)$bookCalYear; ?>"
                         data-initial-month="<?= (int)$bookCalMonth; ?>"
                         data-facilities="<?= htmlspecialchars(json_encode(array_map(
                             fn($f) => ['id' => (int)$f['id'], 'name' => (string)$f['name']],
                             $facilities
                         )), ENT_QUOTES, 'UTF-8'); ?>"
                    ></div>
                    <script type="module" src="<?= htmlspecialchars($base); ?>/public/js/dist/booking-calendar.js"></script>
                </div>
```

- [ ] **Step 3: Syntax-check**

Run: `php -l resources/views/pages/dashboard/book_facility.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Full manual end-to-end verification**

This is the first point where the whole feature can be tested together as a real user would experience it — do this against your normal local/dev environment, logged in as a resident or staff account with access to `/dashboard/book-facility`:

1. Load `/dashboard/book-facility`. Confirm: the calendar renders via React (open DevTools → Elements, confirm `#bcf-calendar-root` has React-rendered children, not empty).
2. Pick a facility from the dropdown. Confirm: the grid loads and shows Open/Busy/Full/Blackout days matching what that facility's status actually is.
3. Change month and year. Confirm: the grid updates, the URL's `?year=&month=&book_fac=` query params update via `history.replaceState` (visible in the address bar), and reloading the page at that URL shows the same month/facility (proves the initial-state read from `$_GET` still works).
4. Click "Today". Confirm: jumps back to the current month.
5. Click an open (green) day. Confirm: the booking modal opens with that date pre-filled, and the conflict-check message appears (same as before this feature existed).
6. Type a purpose into the "Purpose of reservation" textarea (e.g., "Barangay basketball tournament") and wait ~1 second. Confirm: the AI smart-hints bar appears, and if it recommends specific dates, those cells show the purple ring.
7. Submit a booking end to end (pick date → fill form → submit). Confirm: the reservation is created successfully — this proves Task 4's bridge didn't break the untouched submission flow.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/dashboard/book_facility.php
git commit -m "feat: wire the React calendar island into the booking page"
```

---

## Self-Review Notes

**Spec coverage:** Task 1 covers build setup. Task 2 covers the JSON endpoint (reusing existing tone/demand/holiday functions unchanged, per spec). Task 3 covers grid + navigation rendering with 100% existing CSS class reuse. Task 4 covers the day-click → modal bridge. Task 5 covers the AI-hints bridge (both `bcfCalendarSetHighlights` and `bcfCalendarGetState`, and all three of the spec's identified read/write call sites for `window._bcfCalYear`/`_bcfCalMonth`). Task 6 covers motion.dev usage and reduced-motion, with an explicitly documented, reasoned deviation (CSS transition instead of motion.dev for the same-node ring toggle — visual outcome unchanged from the approved design). Task 7 covers the actual markup swap and full end-to-end verification. Rollback (the git tag) was already created before this plan was written, per the user's explicit request.

**Type/name consistency check:** `frs_bcf_calendar_day_entries` (Task 2) is called with identical parameter order and names in Task 2's own endpoint. `window.bcfCalendarActivateDate` (exposed in Task 4's `book_facility.php` change) is called by that exact name in Task 4's `App.jsx` change. `window.bcfCalendarSetHighlights`/`window.bcfCalendarGetState` (exposed in Tasks 5/3's `App.jsx` changes) are called by those exact names in Task 5's `book_facility.php` changes. The JSON field names returned by the endpoint (`date`, `day`, `tone`, `status_class`, `chip_label`, `is_today`, `is_pickable`, `holiday_name`, `holiday_type`, `demand_classification`, `demand_score`) match exactly what `App.jsx`'s `CalendarCell` reads in Task 3.

**No placeholders found** — every step has complete, real code or an exact command with expected output.
