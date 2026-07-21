<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'infrastructure')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../services/ipms_api.php';
$pdo = db();
$pageTitle = 'Infrastructure Projects (IPMS) | LGU Facilities Reservation';

$canSync = in_array($role, ['Admin', 'Staff'], true);
$configured = ipms_api_key() !== '';

$state = frs_ipms_load_sync_state();
$lastSyncAt = $state['last_sync_at'] ?? null;
$summary = is_array($state['last_summary'] ?? null) ? $state['last_summary'] : [];
$needsReview = is_array($summary['needs_review'] ?? null) ? $summary['needs_review'] : [];
$upcomingProjects = is_array($summary['upcoming_projects'] ?? null) ? $summary['upcoming_projects'] : [];
$syncErrors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
$barangay = (string)($summary['barangay'] ?? '');

// Facilities currently blocked by an IPMS-synced blackout — this is the ground truth of what's
// actually blocking bookings right now, independent of what the last sync summary happened to say.
$activeIpmsProjects = [];
try {
    $rows = $pdo->query(
        "SELECT b.facility_id, f.name AS facility_name, b.blackout_date, b.reason
         FROM facility_blackout_dates b
         JOIN facilities f ON f.id = b.facility_id
         WHERE b.reason LIKE 'IPMS Sync:%'
         ORDER BY f.name, b.blackout_date"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $key = (string)$row['facility_id'] . '|' . (string)$row['reason'];
        if (!isset($activeIpmsProjects[$key])) {
            $activeIpmsProjects[$key] = [
                'facility_id' => (int)$row['facility_id'],
                'facility_name' => (string)$row['facility_name'],
                'label' => trim(substr((string)$row['reason'], strlen('IPMS Sync:'))),
                'start_date' => (string)$row['blackout_date'],
                'end_date' => (string)$row['blackout_date'],
                'day_count' => 0,
            ];
        }
        $activeIpmsProjects[$key]['end_date'] = (string)$row['blackout_date'];
        $activeIpmsProjects[$key]['day_count']++;
    }
} catch (Throwable $e) {
    // facility_blackout_dates may not exist yet — treat as no active projects.
}
$activeIpmsProjects = array_values($activeIpmsProjects);

$facilityNameById = [];
try {
    foreach ($pdo->query('SELECT id, name FROM facilities')->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $facilityNameById[(int)$f['id']] = (string)$f['name'];
    }
} catch (Throwable $e) {
    // ignore — falls back to "Facility #id" in the template
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Operations</span><span class="sep">/</span><span>Infrastructure Projects</span>
    </div>
    <?= frs_page_title('Infrastructure Projects (IPMS)', 'Read-only pull integration: we poll IPMS for Culiat infrastructure projects and block booking on facilities under active work. We never write anything back to IPMS.'); ?>
</div>

<div class="booking-wrapper">
    <section class="booking-card" style="grid-column: 1 / -1;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem;">
            <div>
                <h2 style="margin-bottom:0.25rem;">Sync status</h2>
                <?php if (!$configured): ?>
                    <p style="color:#8b95b5;">Not configured — set <code>IPMS_API_KEY</code> (or <code>FACILITIES_RESERVATION_API_KEY</code>) and <code>IPMS_API_URL</code> in your environment.</p>
                <?php elseif ($lastSyncAt): ?>
                    <p style="color:#8b95b5;">
                        Last synced <span id="ipms-last-sync"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($lastSyncAt))); ?></span>
                        <?php if ($barangay !== ''): ?> · Barangay: <?= htmlspecialchars($barangay); ?><?php endif; ?>
                    </p>
                <?php else: ?>
                    <p style="color:#8b95b5;">Never synced yet.</p>
                <?php endif; ?>
            </div>
            <?php if ($canSync && $configured): ?>
                <button type="button" id="ipms-sync-btn" class="btn-primary" style="padding:0.6rem 1.1rem;">Sync Now</button>
            <?php endif; ?>
        </div>

        <?php if (!empty($syncErrors)): ?>
            <div style="background:#fff4e5; border-left:4px solid #dc3545; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem;">
                <strong>Last sync reported errors:</strong>
                <ul style="margin:0.4rem 0 0 1.2rem;">
                    <?php foreach ($syncErrors as $err): ?>
                        <li><?= htmlspecialchars((string)$err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div id="ipms-sync-feedback"></div>
    </section>

    <!-- Facilities currently blocked -->
    <section class="booking-card" style="grid-column: 1 / -1;">
        <h2 style="margin-bottom:1rem;">Facilities currently blocked by IPMS projects</h2>
        <?php if (empty($activeIpmsProjects)): ?>
            <p style="color:#8b95b5; text-align:center; padding:1.5rem 0;">No facilities are currently blocked by an IPMS project.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Project</th>
                            <th>Blocked dates</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeIpmsProjects as $p): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['facility_name']); ?></strong></td>
                                <td><?= htmlspecialchars($p['label'] !== '' ? $p['label'] : 'Infrastructure project'); ?></td>
                                <td>
                                    <?= date('M d, Y', strtotime($p['start_date'])); ?>
                                    <?php if ($p['end_date'] !== $p['start_date']): ?>
                                        &ndash; <?= date('M d, Y', strtotime($p['end_date'])); ?>
                                    <?php endif; ?>
                                    <br><small style="color:#8b95b5;"><?= (int)$p['day_count']; ?> day(s)</small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Needs manual review -->
    <?php if (!empty($needsReview)): ?>
    <section class="booking-card" style="grid-column: 1 / -1; border: 1px solid #ffc107;">
        <h2 style="margin-bottom:0.5rem;">
            Needs manual review
            <small style="font-weight:500; color:#8b95b5;">(<?= count($needsReview); ?>)</small>
        </h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">
            IPMS reported these as affecting a Culiat facility, but we couldn't confidently match the project to one of our facility records.
            They are <strong>not</strong> auto-blocking any facility — review manually and add a blackout date if the facility is in fact affected.
        </p>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Reported location</th>
                        <th>Best match confidence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($needsReview as $item): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string)($item['name'] ?? '')); ?></strong><br>
                                <small style="color:#8b95b5;"><?= htmlspecialchars((string)($item['project_code'] ?? '')); ?></small>
                            </td>
                            <td><span class="status-badge maintenance"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($item['status'] ?? '')))); ?></span></td>
                            <td><?= htmlspecialchars((string)($item['location'] ?? '')); ?></td>
                            <td><?= (int)($item['best_score'] ?? 0); ?>/100</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- Upcoming work (heads-up only, never blocks booking) -->
    <section class="booking-card" style="grid-column: 1 / -1;">
        <h2 style="margin-bottom:0.5rem;">Upcoming work <small style="font-weight:500; color:#8b95b5;">(heads-up only — not blocked yet)</small></h2>
        <p style="color:#8b95b5; margin-bottom:1rem;">
            Projects that are approved/bidding/awarded/assigned but haven't started. IPMS's own timelines are estimates, not guarantees, so these dates are informational only and do not block bookings.
        </p>
        <?php if (empty($upcomingProjects)): ?>
            <p style="color:#8b95b5; text-align:center; padding:1rem 0;">No upcoming work reported.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Status</th>
                            <th>Likely facility</th>
                            <th>Expected start</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcomingProjects as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)($p['name'] ?? '')); ?></strong><br>
                                    <small style="color:#8b95b5;"><?= htmlspecialchars((string)($p['project_code'] ?? '')); ?></small>
                                </td>
                                <td><span class="status-badge offline"><?= htmlspecialchars(ucfirst((string)($p['status'] ?? ''))); ?></span></td>
                                <td>
                                    <?php if (!empty($p['matched_facility_id'])): ?>
                                        <?php $fid = (int)$p['matched_facility_id']; ?>
                                        <?= htmlspecialchars($facilityNameById[$fid] ?? ('Facility #' . $fid)); ?>
                                    <?php else: ?>
                                        <em style="color:#8b95b5;">Not matched — <?= htmlspecialchars((string)($p['location'] ?? '')); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($p['start_date']) ? date('M d, Y', strtotime((string)$p['start_date'])) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    var btn = document.getElementById('ipms-sync-btn');
    if (!btn) { return; }
    var feedback = document.getElementById('ipms-sync-feedback');
    btn.addEventListener('click', function () {
        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = 'Syncing…';
        if (feedback) { feedback.innerHTML = ''; }
        fetch('<?= htmlspecialchars(base_path()); ?>/public/api/sync-ipms-projects.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'FRS-IPMS-Sync' }
        })
        .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
        .then(function (result) {
            if (result.ok && result.data && result.data.success) {
                window.location.reload();
                return;
            }
            var msg = (result.data && (result.data.error || result.data.message)) || 'Sync failed.';
            if (feedback) {
                feedback.innerHTML = '<div style="background:#fff4e5;border-left:4px solid #dc3545;padding:0.75rem 1rem;border-radius:6px;">' + msg.replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}) + '</div>';
            }
            btn.disabled = false;
            btn.textContent = original;
        })
        .catch(function () {
            if (feedback) {
                feedback.innerHTML = '<div style="background:#fff4e5;border-left:4px solid #dc3545;padding:0.75rem 1rem;border-radius:6px;">Sync request failed. Please try again.</div>';
            }
            btn.disabled = false;
            btn.textContent = original;
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
