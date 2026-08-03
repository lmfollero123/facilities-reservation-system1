<?php
/**
 * Energy Efficiency module — LGU Energy system integration.
 *
 * Tabs: Meter Readings (record + push monthly manual readings), Recommendations
 * (engineer-approved advice pulled from the Energy system), Facility Mapping
 * (link CPRF facilities to Energy-system facilities).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'energy')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/energy_helper.php';

$pdo = db();
$pageTitle = 'Energy Efficiency | LGU Facilities Reservation';
$dashboardContentClass = 'integrations-modern';

$canCreate = frs_can_create($role, 'energy');
$canUpdate = frs_can_update($role, 'energy');
$canDelete = frs_can_delete($role, 'energy');
$syncEnabled = energy_api_enabled();

$message = '';
$messageType = '';
$hasTables = frs_energy_tables_exist($pdo);

$tab = (string)($_GET['tab'] ?? 'readings');
if (!in_array($tab, ['readings', 'recommendations', 'mapping', 'profiles'], true)) {
    $tab = 'readings';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $hasTables) {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } elseif ($_POST['action'] === 'add_reading' && $canCreate) {
        $month = (string)($_POST['reading_month'] ?? ''); // "YYYY-MM" from <input type=month>
        $parts = explode('-', $month);
        try {
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int)$parts[1] < 1 || (int)$parts[1] > 12) {
                throw new InvalidArgumentException('Please choose a valid reading month.');
            }
            $readingId = frs_energy_save_reading($pdo, [
                'facility_id' => (int)($_POST['facility_id'] ?? 0),
                'year' => (int)$parts[0],
                'month' => (int)$parts[1],
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'previous_reading_kwh' => (float)($_POST['previous_reading_kwh'] ?? 0),
                'current_reading_kwh' => (float)($_POST['current_reading_kwh'] ?? 0),
                'rate_per_kwh' => $_POST['rate_per_kwh'] ?? null,
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'recorded_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $push = $syncEnabled
                ? frs_energy_push_reading($pdo, $readingId)
                : ['success' => false, 'error' => 'Sync disabled — reading saved locally as pending.'];
            if ($push['success']) {
                $message = 'Reading saved and pushed to the Energy system. Waiting for Energy Admin approval.';
                $messageType = 'success';
            } else {
                $message = 'Reading saved locally. Push to Energy system pending: ' . (string)$push['error'];
                $messageType = 'success';
            }
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to save reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'update_reading' && $canUpdate) {
        $readingId = (int)($_POST['reading_id'] ?? 0);
        try {
            frs_energy_update_reading($pdo, $readingId, [
                'current_reading_kwh' => $_POST['current_reading_kwh'] ?? null,
                'previous_reading_kwh' => $_POST['previous_reading_kwh'] ?? null,
                'rate_per_kwh' => $_POST['rate_per_kwh'] ?? null,
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ]);
            $push = $syncEnabled
                ? frs_energy_push_reading($pdo, $readingId)
                : ['success' => false, 'error' => 'Sync disabled — reading saved locally as pending.'];
            if ($push['success']) {
                $message = 'Reading corrected and re-pushed to the Energy system. Waiting for Energy Admin approval.';
                $messageType = 'success';
            } else {
                $message = 'Reading corrected. Push pending: ' . (string)$push['error'];
                $messageType = 'success';
            }
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to correct reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'delete_reading' && $canDelete) {
        $readingId = (int)($_POST['reading_id'] ?? 0);
        try {
            frs_energy_delete_reading($pdo, $readingId);
            $message = 'Reading deleted.';
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to delete reading: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'save_mapping' && $canUpdate) {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $pair = trim((string)($_POST['energy_facility'] ?? '')); // "id|name"
        $sep = strpos($pair, '|');
        if ($facilityId <= 0 || $sep === false) {
            $message = 'Please choose an Energy-system facility.';
            $messageType = 'error';
        } else {
            $energyFacilityId = (int)substr($pair, 0, $sep);
            if ($energyFacilityId <= 0) {
                $message = 'Please choose an Energy-system facility.';
                $messageType = 'error';
            } else {
                $energyFacilityName = substr($pair, $sep + 1);
                frs_energy_save_mapping($pdo, $facilityId, $energyFacilityId, $energyFacilityName, (int)($_SESSION['user_id'] ?? 0) ?: null);
                $message = 'Facility mapping saved.';
                $messageType = 'success';
            }
        }
        $tab = 'mapping';
    } elseif ($_POST['action'] === 'update_recommendation' && $canUpdate) {
        $tab = 'recommendations';
        try {
            if (!$syncEnabled) {
                throw new RuntimeException('Sync is disabled. Enable Energy sync before updating progress.');
            }
            $result = frs_energy_push_recommendation_progress(
                $pdo,
                (int)($_POST['recommendation_id'] ?? 0),
                $_POST,
                (int)($_SESSION['user_id'] ?? 0) ?: null
            );
            if (!$result['success']) {
                throw new RuntimeException((string)$result['error']);
            }
            $message = 'Implementation progress saved and synced to the Energy system.';
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (Throwable $e) {
            $message = 'Unable to update recommendation: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$facilities = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$mapping = $hasTables ? frs_energy_get_mapping($pdo) : [];
$syncState = $hasTables ? frs_energy_load_sync_state($pdo) : ['last_pull_at' => null, 'last_push_at' => null, 'last_summary' => null];

$latestReadings = [];
$pendingCount = 0;
$hasSyncedReadings = false;
$approvedRecommendationPeriods = [];
if ($hasTables) {
    $rows = $pdo->query('
        SELECT r.*, f.name AS facility_name, u.name AS recorded_by_name
        FROM energy_meter_readings r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN users u ON u.id = r.recorded_by
        ORDER BY r.year DESC, r.month DESC, r.id DESC
        LIMIT 200
    ')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $fid = (int)$row['facility_id'];
        if (!isset($latestReadings[$fid])) {
            $latestReadings[$fid] = $row;
        }
        if (in_array($row['sync_status'], ['pending', 'failed'], true)) {
            $pendingCount++;
        }
        if ($row['sync_status'] === 'synced') {
            $hasSyncedReadings = true;
        }
    }

    $curYear = (int)date('Y');
    $curMonth = (int)date('n');
    $readFacilityIdsThisMonth = [];
    foreach ($rows as $row) {
        if ((int)$row['year'] === $curYear && (int)$row['month'] === $curMonth) {
            $readFacilityIdsThisMonth[(int)$row['facility_id']] = true;
        }
    }
    $facilitiesMissingThisMonth = array_values(array_filter($facilities, function ($f) use ($readFacilityIdsThisMonth) {
        return $f['status'] !== 'deleted' && !isset($readFacilityIdsThisMonth[(int)$f['id']]);
    }));

    $approvedRows = $pdo->query("
        SELECT facility_id, year, month
        FROM energy_recommendations_cache
        WHERE status = 'approved'
          AND facility_id IS NOT NULL
        GROUP BY facility_id, year, month
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($approvedRows as $approvedRow) {
        $key = (int)$approvedRow['facility_id'] . '-' . (int)$approvedRow['year'] . '-' . (int)$approvedRow['month'];
        $approvedRecommendationPeriods[$key] = true;
    }
}

// Edit-reading target: only valid when it is still a facility's latest reading.
$editReadingId = (int)($_GET['edit_reading'] ?? 0);
$editReading = null;
$editReadingIsOnly = false;
if ($hasTables && $editReadingId > 0 && $canUpdate) {
    foreach ($latestReadings as $r) {
        if ((int)$r['id'] === $editReadingId) {
            $editReading = $r;
            break;
        }
    }
    if ($editReading !== null) {
        $onlyStmt = $pdo->prepare('SELECT COUNT(*) FROM energy_meter_readings WHERE facility_id = :facility_id AND id != :id');
        $onlyStmt->execute(['facility_id' => (int)$editReading['facility_id'], 'id' => $editReadingId]);
        $editReadingIsOnly = (int)$onlyStmt->fetchColumn() === 0;
    }
}

$recommendations = [];
$periodRaw = '';
if ($hasTables && $tab === 'recommendations') {
    $filterFacility = (int)($_GET['facility_id'] ?? 0);
    $filterYear = 0;
    $filterMonth = 0;
    $periodRaw = trim((string)($_GET['period'] ?? ''));
    if ($periodRaw !== '') {
        $parts = explode('-', $periodRaw);
        if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && (int)$parts[1] >= 1 && (int)$parts[1] <= 12) {
            $filterYear = (int)$parts[0];
            $filterMonth = (int)$parts[1];
        }
    }
    $sql = '
        SELECT c.*, f.name AS facility_name
        FROM energy_recommendations_cache c
        INNER JOIN energy_facility_map m
            ON m.facility_id = c.facility_id
           AND m.energy_facility_id = c.energy_facility_id
        INNER JOIN facilities f ON f.id = c.facility_id
        WHERE c.status = \'approved\'
        ' . ($filterFacility > 0 ? 'AND c.facility_id = :fid' : '') . '
        ' . ($filterYear > 0 ? 'AND c.year = :fy AND c.month = :fm' : '') . '
        ORDER BY c.year DESC, c.month DESC, c.id DESC
        LIMIT 100
    ';
    $stmt = $pdo->prepare($sql);
    if ($filterFacility > 0) {
        $stmt->bindValue('fid', $filterFacility, PDO::PARAM_INT);
    }
    if ($filterYear > 0) {
        $stmt->bindValue('fy', $filterYear, PDO::PARAM_INT);
        $stmt->bindValue('fm', $filterMonth, PDO::PARAM_INT);
    }
    $stmt->execute();
    $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$energyFacilities = [];
$energyFacilitiesError = null;
if ($tab === 'mapping' && $canUpdate) {
    $result = fetchEnergyFacilities();
    $energyFacilities = $result['data'];
    $energyFacilitiesError = $result['error'];
}

$profiles = [];
if ($hasTables && $tab === 'profiles') {
    $profiles = $pdo->query('
        SELECT f.id AS facility_id, f.name AS facility_name,
               p.main_meter_name, p.electric_meter_no, p.utility_provider, p.contract_account_no, p.main_energy_source,
               p.backup_power, p.transformer_capacity, p.number_of_meters, p.baseline_kwh,
               p.engineer_approved, p.baseline_locked, p.baseline_source, p.energy_updated_at
        FROM facilities f
        JOIN energy_facility_map m ON m.facility_id = f.id
        LEFT JOIN energy_profile_cache p ON p.facility_id = f.id
        ORDER BY f.name
    ')->fetchAll(PDO::FETCH_ASSOC);
}

$monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$tabUrl = static fn (string $t): string => base_path() . '/dashboard/energy-efficiency?tab=' . $t;

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Energy Efficiency</span>
    </div>
    <?= frs_page_title('Energy Efficiency (LGU Energy)', 'Record monthly electricity meter readings per facility, push them to the LGU Energy system, and review engineer-approved energy-saving recommendations.'); ?>
</div>

<?php if ($message): ?>
    <div class="message <?= htmlspecialchars($messageType); ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!$hasTables): ?>
    <section class="booking-card">
        <h2>Setup required</h2>
        <p style="color:#8b95b5;">Run <code>database/migration_add_energy_integration.sql</code> to create the energy integration tables.</p>
    </section>
<?php else: ?>

<nav class="booking-hub-tabs" aria-label="Energy sections">
    <a class="booking-hub-tab <?= $tab === 'readings' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('readings')); ?>">Meter Readings</a>
    <a class="booking-hub-tab <?= $tab === 'recommendations' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('recommendations')); ?>">Recommendations</a>
    <a class="booking-hub-tab <?= $tab === 'profiles' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('profiles')); ?>">Facility Profiles</a>
    <?php if ($canUpdate): ?>
        <a class="booking-hub-tab <?= $tab === 'mapping' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a>
    <?php endif; ?>
</nav>

<?php if ($tab === 'readings'): ?>
    <div class="booking-wrapper">
        <?php if ($editReading !== null): ?>
        <section class="booking-card">
            <h2>Edit Reading</h2>
            <p style="color:#8b95b5; margin-bottom:1rem;">Only the latest reading for a facility can be corrected. The reading period itself cannot be changed — record a new month instead if the period is wrong.</p>
            <form method="POST" action="<?= htmlspecialchars($tabUrl('readings')); ?>" class="booking-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update_reading">
                <input type="hidden" name="reading_id" value="<?= (int)$editReading['id']; ?>">
                <label>
                    Facility
                    <input type="text" value="<?= htmlspecialchars((string)$editReading['facility_name']); ?>" readonly style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px; background:#f4f6fa;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Month
                    <input type="text" value="<?= htmlspecialchars(($monthNames[(int)$editReading['month']] ?? $editReading['month']) . ' ' . $editReading['year']); ?>" readonly style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px; background:#f4f6fa;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Reading Date
                    <input type="date" name="reading_date" required value="<?= htmlspecialchars((string)$editReading['reading_date']); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Previous Meter Reading (kWh)
                    <input type="number" step="0.01" min="0" name="previous_reading_kwh" id="energy-edit-prev-input" value="<?= htmlspecialchars((string)$editReading['previous_reading_kwh']); ?>" <?= $editReadingIsOnly ? '' : 'readonly'; ?> style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px; <?= $editReadingIsOnly ? '' : 'background:#f4f6fa;'; ?>">
                    <?php if (!$editReadingIsOnly): ?>
                        <small style="color:#8b95b5;">Locked — this facility has earlier readings, so the previous value must stay linked to the prior reading.</small>
                    <?php endif; ?>
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Current Meter Reading (kWh)
                    <input type="number" step="0.01" min="0" name="current_reading_kwh" id="energy-edit-curr-input" required value="<?= htmlspecialchars((string)$editReading['current_reading_kwh']); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Rate per kWh (PHP)
                    <input type="number" step="0.01" min="0.01" name="rate_per_kwh" id="energy-edit-rate-input" required value="<?= htmlspecialchars(number_format((float)($editReading['rate_per_kwh'] ?? 12), 2, '.', '')); ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <small style="color:#8b95b5;">Electricity tariff used to calculate the energy cost.</small>
                </label>
                <p id="energy-edit-consumption-preview" style="margin-top:0.5rem; color:#0066cc; font-weight:600;"></p>
                <label style="margin-top:0.75rem; display:block;">
                    Notes (optional)
                    <textarea name="notes" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"><?= htmlspecialchars((string)($editReading['notes'] ?? '')); ?></textarea>
                </label>
                <div style="margin-top:1rem; display:flex; gap:0.75rem; align-items:center;">
                    <button type="submit" class="btn-primary">Save Correction</button>
                    <a href="<?= htmlspecialchars($tabUrl('readings')); ?>">Cancel</a>
                </div>
            </form>
            <script>
            (function () {
                'use strict';
                var prev = document.getElementById('energy-edit-prev-input');
                var curr = document.getElementById('energy-edit-curr-input');
                var rate = document.getElementById('energy-edit-rate-input');
                var preview = document.getElementById('energy-edit-consumption-preview');
                if (!prev || !curr || !rate || !preview) return;
                function updatePreview() {
                    var p = parseFloat(prev.value), c = parseFloat(curr.value), r = parseFloat(rate.value);
                    preview.textContent = (!isNaN(p) && !isNaN(c) && c >= p)
                        ? 'Consumption: ' + (c - p).toFixed(2) + ' kWh'
                            + (!isNaN(r) && r > 0 ? ' | Estimated cost: PHP ' + ((c - p) * r).toFixed(2) : '')
                        : '';
                }
                prev.addEventListener('input', updatePreview);
                curr.addEventListener('input', updatePreview);
                rate.addEventListener('input', updatePreview);
                updatePreview();
            })();
            </script>
        </section>
        <?php elseif ($canCreate): ?>
        <section class="booking-card">
            <h2>Add Meter Reading</h2>
            <p style="color:#8b95b5; margin-bottom:1rem;">One reading per facility per month. The previous value auto-fills from the facility's last reading.</p>
            <form method="POST" action="<?= htmlspecialchars($tabUrl('readings')); ?>" class="booking-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="add_reading">
                <label>
                    Facility
                    <select name="facility_id" id="energy-facility-select" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                        <option value="">— Select facility —</option>
                        <?php foreach ($facilities as $f): ?>
                            <?php $last = $latestReadings[(int)$f['id']] ?? null; ?>
                            <option value="<?= (int)$f['id']; ?>" data-prev="<?= $last !== null ? htmlspecialchars((string)$last['current_reading_kwh']) : ''; ?>" data-rate="<?= $last !== null ? htmlspecialchars((string)($last['rate_per_kwh'] ?? '12.00')) : '12.00'; ?>">
                                <?= htmlspecialchars($f['name']); ?><?= isset($mapping[(int)$f['id']]) ? '' : ' (unmapped)'; ?>
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
                <label style="margin-top:0.75rem; display:block;">
                    Previous Meter Reading (kWh)
                    <input type="number" step="0.01" min="0" name="previous_reading_kwh" id="energy-prev-input" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <small style="color:#8b95b5;">Auto-filled and locked when the facility already has a reading.</small>
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Current Meter Reading (kWh)
                    <input type="number" step="0.01" min="0" name="current_reading_kwh" id="energy-curr-input" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                </label>
                <label style="margin-top:0.75rem; display:block;">
                    Rate per kWh (PHP)
                    <input type="number" step="0.01" min="0.01" name="rate_per_kwh" id="energy-rate-input" required value="12.00" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <small style="color:#8b95b5;">Enter the applicable electricity tariff for this billing period.</small>
                </label>
                <p id="energy-consumption-preview" style="margin-top:0.5rem; color:#0066cc; font-weight:600;"></p>
                <label style="margin-top:0.75rem; display:block;">
                    Notes (optional)
                    <textarea name="notes" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn-primary" style="margin-top:1rem;">Save Reading</button>
            </form>
            <script>
            (function () {
                'use strict';
                var sel = document.getElementById('energy-facility-select');
                var prev = document.getElementById('energy-prev-input');
                var curr = document.getElementById('energy-curr-input');
                var rate = document.getElementById('energy-rate-input');
                var preview = document.getElementById('energy-consumption-preview');
                if (!sel || !prev || !curr || !rate || !preview) return;
                function updatePreview() {
                    var p = parseFloat(prev.value), c = parseFloat(curr.value), r = parseFloat(rate.value);
                    preview.textContent = (!isNaN(p) && !isNaN(c) && c >= p)
                        ? 'Consumption: ' + (c - p).toFixed(2) + ' kWh'
                            + (!isNaN(r) && r > 0 ? ' | Estimated cost: PHP ' + ((c - p) * r).toFixed(2) : '')
                        : '';
                }
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    var last = opt ? opt.getAttribute('data-prev') : '';
                    var lastRate = opt ? opt.getAttribute('data-rate') : '';
                    if (last) { prev.value = last; prev.readOnly = true; }
                    else { prev.value = ''; prev.readOnly = false; }
                    rate.value = lastRate || '12.00';
                    updatePreview();
                });
                prev.addEventListener('input', updatePreview);
                curr.addEventListener('input', updatePreview);
                rate.addEventListener('input', updatePreview);
            })();
            </script>
        </section>
        <?php endif; ?>

        <section class="booking-card ee-missing-card">
            <h2>Missing This Month</h2>
            <p style="color:#8b95b5; margin:0 0 0.75rem;"><?= htmlspecialchars(date('F Y')); ?> readings not yet recorded.</p>
            <?php if ($facilitiesMissingThisMonth === []): ?>
                <p class="ee-missing-empty"><i class="bi bi-check-circle-fill"></i> All facilities are up to date.</p>
            <?php else: ?>
                <ul class="ee-missing-list">
                    <?php foreach ($facilitiesMissingThisMonth as $f): ?>
                        <li>
                            <button type="button" class="ee-missing-item" data-facility-id="<?= (int)$f['id']; ?>">
                                <?= htmlspecialchars($f['name']); ?>
                                <?php if (!isset($mapping[(int)$f['id']])): ?>
                                    <span class="ee-missing-tag">unmapped</span>
                                <?php endif; ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <section class="booking-card ee-readings-card">
        <h2>Latest Readings per Facility</h2>
        <?php if ($latestReadings === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No readings recorded yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table ee-readings-table">
                        <thead>
                            <tr><th>Facility</th><th>Period</th><th>Consumption</th><th>Rate</th><th>Estimated Cost</th><th>Sync</th><th>Energy Review</th><th>Recorded By</th><?php if ($canUpdate || $canDelete): ?><th>Actions</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($latestReadings as $r): ?>
                                <tr>
                                    <td data-label="Facility"><?= htmlspecialchars((string)$r['facility_name']); ?></td>
                                    <td data-label="Period"><?= htmlspecialchars(($monthNames[(int)$r['month']] ?? $r['month']) . ' ' . $r['year']); ?></td>
                                    <td data-label="Consumption"><?= number_format((float)$r['consumption_kwh'], 2); ?> kWh</td>
                                    <td data-label="Rate">PHP <?= number_format((float)($r['rate_per_kwh'] ?? 12), 2); ?>/kWh</td>
                                    <td data-label="Estimated Cost">PHP <?= number_format((float)$r['consumption_kwh'] * (float)($r['rate_per_kwh'] ?? 12), 2); ?></td>
                                    <td data-label="Sync">
                                        <span class="status-badge <?= $r['sync_status'] === 'synced' ? 'active' : ($r['sync_status'] === 'failed' ? 'offline' : 'maintenance'); ?>"
                                              <?= $r['sync_error'] !== null ? 'title="' . htmlspecialchars((string)$r['sync_error']) . '"' : ''; ?>>
                                            <?= htmlspecialchars(ucfirst((string)$r['sync_status'])); ?>
                                        </span>
                                    </td>
                                    <td data-label="Energy Review">
                                        <?php
                                        $reviewKey = (int)$r['facility_id'] . '-' . (int)$r['year'] . '-' . (int)$r['month'];
                                        $hasApprovedRecommendation = isset($approvedRecommendationPeriods[$reviewKey]);
                                        ?>
                                        <?php if ($r['sync_status'] !== 'synced'): ?>
                                            <span style="color:#8b95b5;">Not submitted yet</span>
                                        <?php elseif ($hasApprovedRecommendation): ?>
                                            <span class="status-badge active">Approved</span>
                                        <?php else: ?>
                                            <span class="status-badge maintenance" style="white-space:nowrap;">Waiting for Energy Admin approval</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Recorded By"><?= htmlspecialchars((string)($r['recorded_by_name'] ?? '—')); ?></td>
                                    <?php if ($canUpdate || $canDelete): ?>
                                    <td data-label="Actions" style="white-space:nowrap;">
                                        <?php if ($canUpdate): ?>
                                            <a href="<?= htmlspecialchars($tabUrl('readings') . '&edit_reading=' . (int)$r['id']); ?>" class="btn-secondary" style="padding:0.3rem 0.7rem; font-size:0.85rem;">Edit</a>
                                        <?php endif; ?>
                                        <?php if ($canDelete && $r['sync_status'] !== 'synced'): ?>
                                            <form method="POST" action="<?= htmlspecialchars($tabUrl('readings')); ?>" style="display:inline;">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_reading">
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
        </section>

<?php elseif ($tab === 'recommendations'): ?>
    <section class="booking-card">
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:center; justify-content:space-between; margin-bottom:1rem;">
            <h2 style="margin:0;">Energy-Saving Recommendations</h2>
            <form method="GET" action="<?= htmlspecialchars(base_path() . '/dashboard/energy-efficiency'); ?>">
                <input type="hidden" name="tab" value="recommendations">
                <input type="month" name="period" value="<?= htmlspecialchars($periodRaw); ?>" onchange="this.form.submit()" style="padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                <select name="facility_id" onchange="this.form.submit()" style="padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                    <option value="0">All facilities</option>
                    <?php foreach ($facilities as $f): ?>
                        <option value="<?= (int)$f['id']; ?>" <?= ((int)($_GET['facility_id'] ?? 0)) === (int)$f['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($f['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <p style="color:#8b95b5;">Engineer-approved advice from the LGU Energy system. Last pulled: <?= htmlspecialchars($syncState['last_pull_at'] ?? 'never'); ?>.</p>
        <?php if ($recommendations === []): ?>
            <?php if ($hasSyncedReadings): ?>
                <div style="max-width:620px; margin:1.25rem auto 0; padding:1.25rem 1.5rem; border:1px solid #f0d9a8; border-radius:12px; background:#fffaf0; text-align:center;">
                    <div style="font-weight:700; color:#9a5b00; margin-bottom:0.35rem;">Waiting for Energy Admin approval</div>
                    <p style="color:#7b6848; margin:0;">Your monthly record was submitted successfully. Approved recommendations will appear here automatically after you run Sync Now.</p>
                </div>
            <?php else: ?>
                <p style="color:#8b95b5; text-align:center; padding:2rem;">No recommendations yet. Submit a monthly meter reading first.</p>
            <?php endif; ?>
        <?php else: ?>
            <?php foreach ($recommendations as $reco): ?>
                <article style="border:1px solid #edf2f7; border-radius:8px; padding:1rem; margin-bottom:0.9rem;">
                    <div style="display:flex; flex-wrap:wrap; gap:0.5rem 1rem; align-items:baseline; justify-content:space-between;">
                        <strong><?= htmlspecialchars((string)($reco['facility_name'] ?? ('Energy facility #' . (int)$reco['energy_facility_id'] . ' (unmapped)'))); ?></strong>
                        <span style="color:#8b95b5;">
                            <?= htmlspecialchars(($monthNames[(int)$reco['month']] ?? $reco['month']) . ' ' . $reco['year']); ?>
                            · <span class="status-badge active"><?= htmlspecialchars(ucfirst((string)$reco['status'])); ?></span>
                        </span>
                    </div>
                    <p style="margin:0.6rem 0 0.3rem;"><?= nl2br(htmlspecialchars((string)$reco['generated_message'])); ?></p>
                    <?php if (!empty($reco['engineer_recommendation'])): ?>
                        <p style="margin:0.3rem 0; color:#0d7a43;"><strong>Engineer:</strong> <?= nl2br(htmlspecialchars((string)$reco['engineer_recommendation'])); ?></p>
                    <?php endif; ?>
                    <small style="color:#8b95b5;">
                        <?php if ($reco['expected_savings_kwh'] !== null): ?>Expected savings: <?= number_format((float)$reco['expected_savings_kwh'], 2); ?> kWh · <?php endif; ?>
                        <?php if (!empty($reco['target_date'])): ?>Target: <?= htmlspecialchars((string)$reco['target_date']); ?> · <?php endif; ?>
                        Fetched: <?= htmlspecialchars((string)$reco['fetched_at']); ?>
                    </small>
                    <?php
                    $implementationStatus = (string)($reco['implementation_status'] ?? 'pending');
                    $implementationLabels = [
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'implemented' => 'Implemented',
                        'verified' => 'Verified by Energy',
                    ];
                    ?>
                    <div style="margin-top:0.9rem; padding-top:0.9rem; border-top:1px solid #edf2f7;">
                        <strong>Implementation: <?= htmlspecialchars($implementationLabels[$implementationStatus] ?? ucfirst($implementationStatus)); ?></strong>
                        <?php if ($reco['actual_savings_kwh'] !== null): ?>
                            <span style="color:#0d7a43;"> &middot; Actual savings: <?= number_format((float)$reco['actual_savings_kwh'], 2); ?> kWh</span>
                        <?php endif; ?>
                        <?php if (!empty($reco['implementation_notes'])): ?>
                            <p style="margin:0.45rem 0; color:#56627a;"><?= nl2br(htmlspecialchars((string)$reco['implementation_notes'])); ?></p>
                        <?php endif; ?>

                        <?php if ($canUpdate && $implementationStatus !== 'verified'): ?>
                            <form method="POST" action="<?= htmlspecialchars($tabUrl('recommendations')); ?>" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:0.65rem; align-items:end; margin-top:0.75rem;">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="update_recommendation">
                                <input type="hidden" name="recommendation_id" value="<?= (int)$reco['id']; ?>">
                                <label>
                                    Progress Status
                                    <select name="implementation_status" required style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                                        <?php foreach (['pending' => 'Pending', 'in_progress' => 'In Progress', 'implemented' => 'Implemented'] as $value => $label): ?>
                                            <option value="<?= $value; ?>" <?= $implementationStatus === $value ? 'selected' : ''; ?>><?= $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    Actual Savings (kWh)
                                    <input type="number" name="actual_savings_kwh" min="0" step="0.01" value="<?= $reco['actual_savings_kwh'] !== null ? htmlspecialchars((string)$reco['actual_savings_kwh']) : ''; ?>" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;">
                                </label>
                                <label>
                                    Implementation Notes
                                    <textarea name="implementation_notes" rows="2" maxlength="5000" style="width:100%; padding:0.5rem; border:1px solid #e0e6ed; border-radius:6px;"><?= htmlspecialchars((string)($reco['implementation_notes'] ?? '')); ?></textarea>
                                </label>
                                <button type="submit" class="btn-primary" <?= $syncEnabled ? '' : 'disabled'; ?>>Save Progress</button>
                            </form>
                            <small style="display:block; margin-top:0.45rem; color:#8b95b5;">Facilities records the action here. Final verification stays with the Energy engineer.</small>
                        <?php elseif ($implementationStatus === 'verified'): ?>
                            <small style="display:block; margin-top:0.45rem; color:#0d7a43;">Final verification was completed in the Energy system.</small>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'mapping' && $canUpdate): ?>
    <section class="booking-card">
        <h2>Facility Mapping</h2>
        <p style="color:#8b95b5;">Link each CPRF facility to its counterpart in the Energy system. Suggested matches are pre-selected — confirm or override, then save per row.</p>
        <?php if ($energyFacilitiesError !== null): ?>
            <p style="color:#b23030; padding:1rem; background:#fdecee; border-radius:8px;"><?= htmlspecialchars($energyFacilitiesError); ?></p>
        <?php elseif ($energyFacilities === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No facilities returned from the Energy system.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>CPRF Facility</th><th>Energy-System Facility</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($facilities as $f): ?>
                            <?php
                            $fid = (int)$f['id'];
                            $current = $mapping[$fid] ?? null;
                            $suggested = $current === null ? frs_energy_suggest_match((string)$f['name'], $energyFacilities) : null;
                            $selectedId = $current['energy_facility_id'] ?? ($suggested['id'] ?? 0);
                            ?>
                            <tr>
                                <td data-label="CPRF Facility"><?= htmlspecialchars($f['name']); ?></td>
                                <td data-label="Energy-System Facility">
                                    <form method="POST" action="<?= htmlspecialchars($tabUrl('mapping')); ?>" style="display:flex; gap:0.5rem; align-items:center;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="save_mapping">
                                        <input type="hidden" name="facility_id" value="<?= $fid; ?>">
                                        <select name="energy_facility" required style="padding:0.4rem; border:1px solid #e0e6ed; border-radius:6px; min-width:220px;">
                                            <option value="">— Select —</option>
                                            <?php foreach ($energyFacilities as $ef): ?>
                                                <?php $efId = (int)($ef['id'] ?? 0); $efName = (string)($ef['name'] ?? ''); ?>
                                                <option value="<?= $efId . '|' . htmlspecialchars($efName); ?>" <?= $efId === $selectedId ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($efName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-primary" style="padding:0.4rem 0.9rem;">Save</button>
                                    </form>
                                </td>
                                <td data-label="Status">
                                    <?php if ($current !== null): ?>
                                        <span class="status-badge active">Mapped</span>
                                    <?php elseif ($suggested !== null): ?>
                                        <span class="status-badge maintenance" title="Name match score: <?= (int)$suggested['score']; ?>">Suggested</span>
                                    <?php else: ?>
                                        <span class="status-badge offline">Unmapped</span>
                                    <?php endif; ?>
                                </td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'profiles'): ?>
    <section class="booking-card">
        <h2>Facility Energy Profiles</h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">
            Utility, meter, and baseline details configured for each facility by the LGU Energy team. This data is read-only here and refreshes automatically whenever a sync runs.
            <?php if ($canUpdate): ?>
                Only facilities mapped to the Energy system are listed below — see <a href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a> to map more.
            <?php else: ?>
                Only facilities mapped to the Energy system are listed below.
            <?php endif; ?>
        </p>
        <?php if ($profiles === []): ?>
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No facilities are mapped to the Energy system yet. Set up mapping first under Facility Mapping.</p>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:1rem;">
                <?php foreach ($profiles as $p): ?>
                    <?php $hasProfile = $p['main_meter_name'] !== null || $p['utility_provider'] !== null || $p['baseline_kwh'] !== null || $p['electric_meter_no'] !== null; ?>
                    <article class="booking-card" style="margin:0;">
                        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:baseline; justify-content:space-between; margin-bottom:0.6rem;">
                            <strong><?= htmlspecialchars((string)$p['facility_name']); ?></strong>
                            <?php if ($hasProfile): ?>
                                <span>
                                    <span class="status-badge <?= $p['engineer_approved'] ? 'approved' : 'pending'; ?>"><?= $p['engineer_approved'] ? 'Approved' : 'Pending Approval'; ?></span>
                                    <?php if ($p['baseline_locked']): ?>
                                        <span class="status-badge admin">Baseline Locked</span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$hasProfile): ?>
                            <p style="color:#8b95b5; margin:0;">No energy profile set yet — the Energy team hasn't configured this facility.</p>
                        <?php else: ?>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.4rem 1rem; font-size:0.9rem;">
                                <div style="grid-column:1 / -1;"><span style="color:#8b95b5;">Main Meter Name</span><br><strong><?= htmlspecialchars((string)($p['main_meter_name'] ?? '—')); ?></strong></div>
                                <div><span style="color:#8b95b5;">Utility Provider</span><br><?= htmlspecialchars((string)($p['utility_provider'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Contract Acct.</span><br><?= htmlspecialchars((string)($p['contract_account_no'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Electric Meter No.</span><br><?= htmlspecialchars((string)($p['electric_meter_no'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Main Energy Source</span><br><?= htmlspecialchars((string)($p['main_energy_source'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Backup Power</span><br><?= htmlspecialchars((string)($p['backup_power'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Transformer Capacity</span><br><?= htmlspecialchars((string)($p['transformer_capacity'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Number of Meters</span><br><?= htmlspecialchars((string)($p['number_of_meters'] ?? '—')); ?></div>
                                <div><span style="color:#8b95b5;">Baseline kWh</span><br><?= $p['baseline_kwh'] !== null ? number_format((float)$p['baseline_kwh'], 2) : '—'; ?></div>
                                <div><span style="color:#8b95b5;">Baseline Source</span><br><?= htmlspecialchars((string)($p['baseline_source'] ?? '—')); ?></div>
                            </div>
                            <p style="color:#8b95b5; font-size:0.8rem; margin:0.75rem 0 0;">Last updated from Energy: <?= htmlspecialchars((string)($p['energy_updated_at'] ?? 'never')); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php endif; // hasTables ?>

<script>
(function () {
    'use strict';
    document.querySelectorAll('.ee-readings-card .table-responsive').forEach(function (el) {
        function sync() {
            el.classList.toggle('is-scrollable', el.scrollWidth > el.clientWidth + 1);
        }
        sync();
        window.addEventListener('resize', sync);
        el.addEventListener('scroll', function () {
            el.classList.toggle('is-scroll-end', el.scrollLeft + el.clientWidth >= el.scrollWidth - 1);
        });
    });

    document.querySelectorAll('.ee-missing-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sel = document.getElementById('energy-facility-select');
            if (!sel) return;
            sel.value = btn.getAttribute('data-facility-id');
            sel.dispatchEvent(new Event('change'));
            sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            sel.focus();
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
