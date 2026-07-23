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

$canCreate = frs_can_create($role, 'energy');
$canUpdate = frs_can_update($role, 'energy');
$syncEnabled = energy_api_enabled();

$message = '';
$messageType = '';
$hasTables = frs_energy_tables_exist($pdo);

$tab = (string)($_GET['tab'] ?? 'readings');
if (!in_array($tab, ['readings', 'recommendations', 'mapping'], true)) {
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
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                throw new InvalidArgumentException('Please choose a valid reading month.');
            }
            $readingId = frs_energy_save_reading($pdo, [
                'facility_id' => (int)($_POST['facility_id'] ?? 0),
                'year' => (int)$parts[0],
                'month' => (int)$parts[1],
                'reading_date' => (string)($_POST['reading_date'] ?? date('Y-m-d')),
                'previous_reading_kwh' => (float)($_POST['previous_reading_kwh'] ?? 0),
                'current_reading_kwh' => (float)($_POST['current_reading_kwh'] ?? 0),
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'recorded_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $push = $syncEnabled
                ? frs_energy_push_reading($pdo, $readingId)
                : ['success' => false, 'error' => 'Sync disabled — reading saved locally as pending.'];
            if ($push['success']) {
                $message = 'Reading saved and pushed to the Energy system.';
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
    } elseif ($_POST['action'] === 'save_mapping' && $canUpdate) {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $pair = trim((string)($_POST['energy_facility'] ?? '')); // "id|name"
        $sep = strpos($pair, '|');
        if ($facilityId <= 0 || $sep === false) {
            $message = 'Please choose an Energy-system facility.';
            $messageType = 'error';
        } else {
            $energyFacilityId = (int)substr($pair, 0, $sep);
            $energyFacilityName = substr($pair, $sep + 1);
            frs_energy_save_mapping($pdo, $facilityId, $energyFacilityId, $energyFacilityName, (int)($_SESSION['user_id'] ?? 0) ?: null);
            $message = 'Facility mapping saved.';
            $messageType = 'success';
        }
        $tab = 'mapping';
    } elseif ($_POST['action'] === 'sync_now' && $canUpdate) {
        if (!$syncEnabled) {
            $message = 'Sync is disabled (ENERGY_SYNC_ENABLED=false).';
            $messageType = 'error';
        } else {
            $summary = frs_energy_run_sync($pdo);
            $message = sprintf(
                'Sync finished: %d reading(s) pushed, %d failed, %d recommendation(s) updated.%s',
                $summary['pushed'],
                $summary['push_failed'],
                $summary['recommendations_upserted'],
                $summary['errors'] !== [] ? ' First issue: ' . $summary['errors'][0] : ''
            );
            $messageType = $summary['errors'] === [] ? 'success' : 'error';
        }
    }
}

$facilities = $pdo->query('SELECT id, name, status FROM facilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$mapping = $hasTables ? frs_energy_get_mapping($pdo) : [];
$syncState = $hasTables ? frs_energy_load_sync_state($pdo) : ['last_pull_at' => null, 'last_push_at' => null, 'last_summary' => null];
$configured = energy_api_base_url() !== '' && energy_api_token() !== '';

$latestReadings = [];
$pendingCount = 0;
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
    }
}

$recommendations = [];
if ($hasTables && $tab === 'recommendations') {
    $filterFacility = (int)($_GET['facility_id'] ?? 0);
    $sql = '
        SELECT c.*, f.name AS facility_name
        FROM energy_recommendations_cache c
        LEFT JOIN facilities f ON f.id = c.facility_id
        ' . ($filterFacility > 0 ? 'WHERE c.facility_id = :fid' : '') . '
        ORDER BY c.year DESC, c.month DESC, c.id DESC
        LIMIT 100
    ';
    $stmt = $pdo->prepare($sql);
    if ($filterFacility > 0) {
        $stmt->bindValue('fid', $filterFacility, PDO::PARAM_INT);
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

<section class="booking-card" style="margin-bottom:1.25rem;">
    <div style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:center; justify-content:space-between;">
        <div>
            <h2 style="margin-bottom:0.25rem;">Connection</h2>
            <p style="color:#8b95b5; margin:0;">
                <?php if (!$configured): ?>
                    Not configured — set <code>ENERGY_API_URL</code> and <code>ENERGY_API_TOKEN</code> in .env.
                <?php elseif (!$syncEnabled): ?>
                    Configured, but sync is disabled (<code>ENERGY_SYNC_ENABLED=false</code>).
                <?php else: ?>
                    Configured.
                    Last push: <?= htmlspecialchars($syncState['last_push_at'] ?? 'never'); ?> ·
                    Last recommendations pull: <?= htmlspecialchars($syncState['last_pull_at'] ?? 'never'); ?> ·
                    Unsynced readings: <?= (int)$pendingCount; ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($canUpdate): ?>
            <form method="POST" action="<?= htmlspecialchars($tabUrl($tab)); ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="sync_now">
                <button type="submit" class="btn-primary" <?= ($configured && $syncEnabled) ? '' : 'disabled'; ?>>Sync Now</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<nav class="booking-hub-tabs" aria-label="Energy sections">
    <a class="booking-hub-tab <?= $tab === 'readings' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('readings')); ?>">Meter Readings</a>
    <a class="booking-hub-tab <?= $tab === 'recommendations' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('recommendations')); ?>">Recommendations</a>
    <?php if ($canUpdate): ?>
        <a class="booking-hub-tab <?= $tab === 'mapping' ? 'is-active' : ''; ?>" href="<?= htmlspecialchars($tabUrl('mapping')); ?>">Facility Mapping</a>
    <?php endif; ?>
</nav>

<?php if ($tab === 'readings'): ?>
    <div class="booking-wrapper">
        <?php if ($canCreate): ?>
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
                            <option value="<?= (int)$f['id']; ?>" data-prev="<?= $last !== null ? htmlspecialchars((string)$last['current_reading_kwh']) : ''; ?>">
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
                var preview = document.getElementById('energy-consumption-preview');
                if (!sel || !prev || !curr || !preview) return;
                function updatePreview() {
                    var p = parseFloat(prev.value), c = parseFloat(curr.value);
                    preview.textContent = (!isNaN(p) && !isNaN(c) && c >= p)
                        ? 'Consumption: ' + (c - p).toFixed(2) + ' kWh'
                        : '';
                }
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    var last = opt ? opt.getAttribute('data-prev') : '';
                    if (last) { prev.value = last; prev.readOnly = true; }
                    else { prev.value = ''; prev.readOnly = false; }
                    updatePreview();
                });
                prev.addEventListener('input', updatePreview);
                curr.addEventListener('input', updatePreview);
            })();
            </script>
        </section>
        <?php endif; ?>

        <section class="booking-card">
            <h2>Latest Readings per Facility</h2>
            <?php if ($latestReadings === []): ?>
                <p style="color:#8b95b5; text-align:center; padding:2rem;">No readings recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Facility</th><th>Period</th><th>Consumption</th><th>Sync</th><th>Recorded By</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($latestReadings as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$r['facility_name']); ?></td>
                                    <td><?= htmlspecialchars(($monthNames[(int)$r['month']] ?? $r['month']) . ' ' . $r['year']); ?></td>
                                    <td><?= number_format((float)$r['consumption_kwh'], 2); ?> kWh</td>
                                    <td>
                                        <span class="status-badge <?= $r['sync_status'] === 'synced' ? 'active' : ($r['sync_status'] === 'failed' ? 'offline' : 'maintenance'); ?>"
                                              <?= $r['sync_error'] !== null ? 'title="' . htmlspecialchars((string)$r['sync_error']) . '"' : ''; ?>>
                                            <?= htmlspecialchars(ucfirst((string)$r['sync_status'])); ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string)($r['recorded_by_name'] ?? '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

<?php elseif ($tab === 'recommendations'): ?>
    <section class="booking-card">
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:center; justify-content:space-between; margin-bottom:1rem;">
            <h2 style="margin:0;">Energy-Saving Recommendations</h2>
            <form method="GET" action="<?= htmlspecialchars(base_path() . '/dashboard/energy-efficiency'); ?>">
                <input type="hidden" name="tab" value="recommendations">
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
            <p style="color:#8b95b5; text-align:center; padding:2rem;">No recommendations cached yet. Use Sync Now after readings have been pushed and reviewed in the Energy system.</p>
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
                                <td><?= htmlspecialchars($f['name']); ?></td>
                                <td>
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
                                <td>
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
<?php endif; ?>

<?php endif; // hasTables ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
