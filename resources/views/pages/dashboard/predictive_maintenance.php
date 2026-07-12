<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/predictive_maintenance.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'maintenance')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pdo = db();
$base = base_path();
$pageTitle = 'Maintenance Insights | LGU Facilities Reservation';
$canSubmit = frs_can_create($role, 'maintenance') || frs_can_update($role, 'maintenance');

$predictiveRows = frs_compute_predictive_maintenance_rows($pdo);
$recentRequests = frs_fetch_recent_maintenance_requests($pdo, 10);

$highCount = count(array_filter($predictiveRows, static fn($r) => ($r['risk_band'] ?? '') === 'High'));
$mediumCount = count(array_filter($predictiveRows, static fn($r) => ($r['risk_band'] ?? '') === 'Medium'));
$actionableCount = count(array_filter($predictiveRows, static fn($r) => !empty($r['show_request_action'])));
$pendingSent = count(array_filter($recentRequests, static fn($r) => in_array($r['status'] ?? '', ['pending', 'sent', 'acknowledged'], true)));

$filterBand = strtolower(trim((string)($_GET['band'] ?? 'all')));
if (!in_array($filterBand, ['all', 'high', 'medium', 'low'], true)) {
    $filterBand = 'all';
}

$displayRows = $predictiveRows;
if ($filterBand !== 'all') {
    $displayRows = array_values(array_filter(
        $predictiveRows,
        static fn($r) => strtolower((string)($r['risk_band'] ?? '')) === $filterBand
    ));
}

ob_start();
?>
<style>
.pm-page { max-width: 1320px; margin: 0 auto; }
.pm-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 45%, #0c4a6e 100%);
    border-radius: 20px;
    padding: 1.75rem 2rem;
    color: #fff;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.pm-hero::after {
    content: '';
    position: absolute;
    top: -40%;
    right: -8%;
    width: 280px;
    height: 280px;
    background: radial-gradient(circle, rgba(56,189,248,0.35) 0%, transparent 70%);
    pointer-events: none;
}
.pm-hero h1 { margin: 0 0 0.35rem; font-size: 1.65rem; font-weight: 800; letter-spacing: -0.02em; }
.pm-hero p { margin: 0; color: rgba(255,255,255,0.82); max-width: 52rem; line-height: 1.55; font-size: 0.95rem; }
.pm-hero-links { margin-top: 1rem; display: flex; gap: 0.65rem; flex-wrap: wrap; }
.pm-hero-links a {
    color: #e0f2fe;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.25);
    background: rgba(255,255,255,0.08);
}
.pm-hero-links a:hover { background: rgba(255,255,255,0.16); }

.pm-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.85rem;
    margin-bottom: 1.25rem;
}
.pm-stat {
    background: #fff;
    border: 1px solid #e8ecf4;
    border-radius: 14px;
    padding: 1rem 1.1rem;
    box-shadow: 0 1px 2px rgba(15,23,42,0.04);
}
.pm-stat-label { font-size: 0.78rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.pm-stat-value { font-size: 1.65rem; font-weight: 800; margin-top: 0.2rem; color: #0f172a; }
.pm-stat-value.danger { color: #dc2626; }
.pm-stat-value.warn { color: #d97706; }
.pm-stat-value.ok { color: #16a34a; }

.pm-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.pm-filters { display: flex; gap: 0.45rem; flex-wrap: wrap; }
.pm-filter-btn {
    border: 1px solid #dbe2ef;
    background: #fff;
    color: #475569;
    padding: 0.4rem 0.85rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}
.pm-filter-btn.active, .pm-filter-btn:hover {
    background: #0ea5e9;
    border-color: #0ea5e9;
    color: #fff;
}

.pm-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 1.25rem;
    align-items: start;
}
@media (max-width: 1100px) {
    .pm-layout { grid-template-columns: 1fr; }
}

.pm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}
.pm-card {
    background: #fff;
    border: 1px solid #e8ecf4;
    border-radius: 16px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 8px rgba(15,23,42,0.04);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.pm-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(15,23,42,0.08);
}
.pm-card-media {
    height: 120px;
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    background-size: cover;
    background-position: center;
    position: relative;
}
.pm-card-media-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #94a3b8;
}
.pm-risk-pill {
    position: absolute;
    top: 0.65rem;
    right: 0.65rem;
    padding: 0.25rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    backdrop-filter: blur(6px);
}
.pm-card-body { padding: 1rem 1.1rem 1.1rem; flex: 1; display: flex; flex-direction: column; }
.pm-card-title { margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; }
.pm-card-meta { margin: 0.25rem 0 0.75rem; font-size: 0.8rem; color: #64748b; }

.pm-risk-bar-wrap { margin-bottom: 0.85rem; }
.pm-risk-bar-label { display: flex; justify-content: space-between; font-size: 0.75rem; color: #64748b; margin-bottom: 0.3rem; font-weight: 600; }
.pm-risk-bar {
    height: 8px;
    border-radius: 999px;
    background: #f1f5f9;
    overflow: hidden;
}
.pm-risk-bar > span {
    display: block;
    height: 100%;
    border-radius: 999px;
    transition: width 0.4s ease;
}

.pm-metrics {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
    margin-bottom: 0.85rem;
}
.pm-metric {
    background: #f8fafc;
    border-radius: 10px;
    padding: 0.5rem 0.6rem;
    font-size: 0.78rem;
    color: #64748b;
}
.pm-metric strong { display: block; color: #0f172a; font-size: 0.95rem; margin-top: 0.1rem; }

.pm-window {
    font-size: 0.82rem;
    color: #334155;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 10px;
    padding: 0.55rem 0.65rem;
    margin-bottom: 0.85rem;
}
.pm-window strong { color: #0369a1; }

.pm-card-actions { margin-top: auto; display: flex; gap: 0.5rem; flex-wrap: wrap; }
.pm-btn-request {
    flex: 1;
    min-width: 140px;
    border: none;
    border-radius: 10px;
    padding: 0.55rem 0.85rem;
    font-weight: 800;
    font-size: 0.82rem;
    cursor: pointer;
    background: linear-gradient(135deg, #0284c7, #0369a1);
    color: #fff;
}
.pm-btn-request:hover { filter: brightness(1.05); }
.pm-btn-request:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    filter: none;
}
.pm-btn-request.is-sent {
    background: #e2e8f0;
    color: #475569;
}

.pm-side-panel {
    background: #fff;
    border: 1px solid #e8ecf4;
    border-radius: 16px;
    padding: 1rem 1.1rem;
    position: sticky;
    top: 1rem;
}
.pm-side-panel h3 { margin: 0 0 0.75rem; font-size: 1rem; color: #0f172a; }
.pm-request-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.65rem; }
.pm-request-item {
    border: 1px solid #eef2f7;
    border-radius: 12px;
    padding: 0.65rem 0.75rem;
    font-size: 0.8rem;
}
.pm-request-item strong { display: block; color: #0f172a; margin-bottom: 0.15rem; }
.pm-status {
    display: inline-block;
    margin-top: 0.35rem;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
}
.pm-status.sent { background: #dbeafe; color: #1d4ed8; }
.pm-status.pending { background: #fef3c7; color: #b45309; }
.pm-status.failed { background: #fee2e2; color: #b91c1c; }
.pm-status.acknowledged { background: #dcfce7; color: #166534; }

.pm-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
    background: #fff;
    border: 1px dashed #dbe2ef;
    border-radius: 16px;
}

.pm-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.55);
    z-index: 1200;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.pm-modal-backdrop.open { display: flex; }
.pm-modal {
    background: #fff;
    border-radius: 16px;
    width: min(480px, 100%);
    padding: 1.25rem 1.35rem;
    box-shadow: 0 20px 50px rgba(0,0,0,0.2);
}
.pm-modal h3 { margin: 0 0 0.5rem; }
.pm-modal p { margin: 0 0 1rem; color: #64748b; font-size: 0.9rem; line-height: 1.5; }
.pm-modal label { display: block; font-size: 0.82rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem; }
.pm-modal textarea {
    width: 100%;
    min-height: 90px;
    border: 1px solid #dbe2ef;
    border-radius: 10px;
    padding: 0.6rem 0.7rem;
    font-family: inherit;
    resize: vertical;
}
.pm-modal-actions { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; }
.pm-modal-actions button {
    border-radius: 10px;
    padding: 0.5rem 0.9rem;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid #dbe2ef;
    background: #fff;
}
.pm-modal-actions .primary {
    background: #0284c7;
    border-color: #0284c7;
    color: #fff;
}

html[data-theme="dark"] .pm-stat,
html[data-theme="dark"] .pm-card,
html[data-theme="dark"] .pm-side-panel,
html[data-theme="dark"] .pm-modal,
html[data-theme="dark"] .pm-empty {
    background: #1e293b;
    border-color: #334155;
    color: #e2e8f0;
}
html[data-theme="dark"] .pm-card-title,
html[data-theme="dark"] .pm-stat-value,
html[data-theme="dark"] .pm-side-panel h3 { color: #f1f5f9; }
html[data-theme="dark"] .pm-metric { background: #0f172a; }
html[data-theme="dark"] .pm-filter-btn { background: #1e293b; border-color: #475569; color: #cbd5e1; }
</style>

<div class="pm-page">
    <div class="page-header" style="margin-bottom: 1rem;">
        <div class="breadcrumb">
            <span>Operations</span><span class="sep">/</span><span>Maintenance Insights</span>
        </div>
    </div>

    <section class="pm-hero">
        <h1>Maintenance Insights</h1>
        <p>
            AI-assisted usage analysis flags facilities that may need preventive work.
            CPRF does not schedule maintenance directly — submit a <strong>Request for Maintenance</strong> to CIMM for review and scheduling.
        </p>
        <div class="pm-hero-links">
            <a href="<?= htmlspecialchars($base); ?>/dashboard/maintenance-integration">View CIMM sync &amp; schedules →</a>
            <a href="<?= htmlspecialchars($base); ?>/dashboard/blackout-dates">Manage blackout dates →</a>
        </div>
    </section>

    <div class="pm-stats">
        <div class="pm-stat">
            <div class="pm-stat-label">High risk</div>
            <div class="pm-stat-value danger"><?= (int)$highCount; ?></div>
        </div>
        <div class="pm-stat">
            <div class="pm-stat-label">Medium risk</div>
            <div class="pm-stat-value warn"><?= (int)$mediumCount; ?></div>
        </div>
        <div class="pm-stat">
            <div class="pm-stat-label">Actionable</div>
            <div class="pm-stat-value"><?= (int)$actionableCount; ?></div>
        </div>
        <div class="pm-stat">
            <div class="pm-stat-label">Pending with CIMM</div>
            <div class="pm-stat-value ok"><?= (int)$pendingSent; ?></div>
        </div>
    </div>

    <div class="pm-toolbar">
        <div class="pm-filters">
            <?php
            $bands = ['all' => 'All facilities', 'high' => 'High risk', 'medium' => 'Medium risk', 'low' => 'Low risk'];
            foreach ($bands as $key => $label):
                $active = $filterBand === $key ? 'active' : '';
            ?>
                <a class="pm-filter-btn <?= $active; ?>" href="?band=<?= urlencode($key); ?>"><?= htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>
        <small style="color:#64748b;">Based on booking volume (90/30 days) and facility status</small>
    </div>

    <div class="pm-layout">
        <div>
            <?php if (empty($displayRows)): ?>
                <div class="pm-empty">Not enough reservation data yet to generate maintenance insights.</div>
            <?php else: ?>
                <div class="pm-grid">
                    <?php foreach ($displayRows as $row):
                        $imgUrl = frs_facility_display_image_url(
                            !empty($row['image_path']) ? (string)$row['image_path'] : null,
                            (int)($row['facility_id'] ?? 0)
                        );
                        $riskScore = (int)($row['risk_score'] ?? 0);
                        $riskColor = (string)($row['risk_color'] ?? '#64748b');
                        $riskBg = (string)($row['risk_bg'] ?? 'rgba(100,116,139,0.15)');
                        $canRequest = $canSubmit && !empty($row['show_request_action']) && !empty($row['recommended_date']) && empty($row['has_pending_request']);
                    ?>
                        <article class="pm-card" data-facility-id="<?= (int)$row['facility_id']; ?>">
                            <div class="pm-card-media" style="background-image:url('<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>');">
                                <span class="pm-risk-pill" style="background:<?= htmlspecialchars($riskBg); ?>;color:<?= htmlspecialchars($riskColor); ?>;">
                                    <?= htmlspecialchars((string)$row['risk_band']); ?> · <?= $riskScore; ?>
                                </span>
                            </div>
                            <div class="pm-card-body">
                                <h3 class="pm-card-title"><?= htmlspecialchars((string)$row['facility_name']); ?></h3>
                                <p class="pm-card-meta">
                                    <?= htmlspecialchars((string)$row['status']); ?>
                                    <?php if (!empty($row['location'])): ?>
                                        · <?= htmlspecialchars((string)$row['location']); ?>
                                    <?php endif; ?>
                                </p>

                                <div class="pm-risk-bar-wrap">
                                    <div class="pm-risk-bar-label">
                                        <span>Maintenance pressure</span>
                                        <span><?= $riskScore; ?>/100</span>
                                    </div>
                                    <div class="pm-risk-bar">
                                        <span style="width:<?= $riskScore; ?>%;background:<?= htmlspecialchars($riskColor); ?>;"></span>
                                    </div>
                                </div>

                                <div class="pm-metrics">
                                    <div class="pm-metric">90-day bookings<strong><?= (int)$row['usage_90d']; ?></strong></div>
                                    <div class="pm-metric">30-day bookings<strong><?= (int)$row['usage_30d']; ?></strong></div>
                                </div>

                                <div class="pm-window">
                                    Suggested window: <strong><?= htmlspecialchars((string)$row['recommended_window_label']); ?></strong>
                                    <br><small style="color:#64748b;">Low-demand <?= htmlspecialchars((string)$row['least_busy_day']); ?> — proposed to CIMM, not auto-blocked in CPRF</small>
                                </div>

                                <div class="pm-card-actions">
                                    <?php if (!$canSubmit): ?>
                                        <span style="font-size:0.8rem;color:#94a3b8;">View only</span>
                                    <?php elseif (!empty($row['has_pending_request'])): ?>
                                        <button type="button" class="pm-btn-request is-sent" disabled>Request pending with CIMM</button>
                                    <?php elseif ($canRequest): ?>
                                        <button type="button" class="pm-btn-request"
                                            data-request-btn
                                            data-facility-id="<?= (int)$row['facility_id']; ?>"
                                            data-facility-name="<?= htmlspecialchars((string)$row['facility_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-location="<?= htmlspecialchars((string)($row['location'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-date="<?= htmlspecialchars((string)$row['recommended_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-priority="<?= htmlspecialchars((string)$row['priority'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-risk-score="<?= $riskScore; ?>"
                                            data-risk-band="<?= htmlspecialchars((string)$row['risk_band'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-window="<?= htmlspecialchars((string)$row['recommended_window_label'], ENT_QUOTES, 'UTF-8'); ?>">
                                            Request Maintenance
                                        </button>
                                    <?php elseif ((int)$row['risk_score'] < 45): ?>
                                        <button type="button" class="pm-btn-request is-sent" disabled>Low priority — monitor</button>
                                    <?php else: ?>
                                        <button type="button" class="pm-btn-request is-sent" disabled>No date available</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <aside class="pm-side-panel">
            <h3>Recent requests to CIMM</h3>
            <?php if (empty($recentRequests)): ?>
                <p style="color:#94a3b8;font-size:0.85rem;margin:0;">No maintenance requests submitted yet.</p>
            <?php else: ?>
                <ul class="pm-request-list">
                    <?php foreach ($recentRequests as $req):
                        $st = strtolower((string)($req['status'] ?? 'pending'));
                    ?>
                        <li class="pm-request-item">
                            <strong><?= htmlspecialchars((string)$req['facility_name']); ?></strong>
                            <?= date('M d, Y', strtotime((string)$req['requested_date'])); ?>
                            · <?= htmlspecialchars(ucfirst((string)$req['priority'])); ?> priority
                            <?php if (!empty($req['cimm_reference'])): ?>
                                <br><small style="color:#64748b;">Ref: <?= htmlspecialchars((string)$req['cimm_reference']); ?></small>
                            <?php endif; ?>
                            <span class="pm-status <?= htmlspecialchars($st); ?>"><?= htmlspecialchars($st); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </aside>
    </div>
</div>

<div class="pm-modal-backdrop" id="pm-request-modal" aria-hidden="true">
    <div class="pm-modal" role="dialog" aria-labelledby="pm-modal-title">
        <h3 id="pm-modal-title">Request maintenance from CIMM</h3>
        <p id="pm-modal-desc"></p>
        <label for="pm-request-notes">Notes for CIMM engineers (optional)</label>
        <textarea id="pm-request-notes" placeholder="Describe observed issues, usage patterns, or urgency…"></textarea>
        <div class="pm-modal-actions">
            <button type="button" id="pm-modal-cancel">Cancel</button>
            <button type="button" class="primary" id="pm-modal-submit">Send to CIMM</button>
        </div>
    </div>
</div>

<script>
(function() {
    const basePath = <?= json_encode($base); ?>;
    const csrfName = <?= json_encode(CSRF_TOKEN_NAME); ?>;
    const csrfToken = <?= json_encode(csrf_token()); ?>;
    const modal = document.getElementById('pm-request-modal');
    const modalDesc = document.getElementById('pm-modal-desc');
    const notesEl = document.getElementById('pm-request-notes');
    let activePayload = null;

    function openModal(btn) {
        activePayload = {
            facility_id: btn.dataset.facilityId,
            facility_name: btn.dataset.facilityName,
            location: btn.dataset.location || '',
            requested_date: btn.dataset.date,
            priority: btn.dataset.priority || 'medium',
            risk_score: btn.dataset.riskScore || '0',
            risk_band: btn.dataset.riskBand || 'Medium',
            window: btn.dataset.window || ''
        };
        modalDesc.textContent = 'Submit a maintenance request for ' + activePayload.facility_name
            + ' on ' + activePayload.window + '. CIMM will review and schedule — CPRF will not block bookings until CIMM confirms.';
        notesEl.value = '';
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        activePayload = null;
    }

    document.querySelectorAll('[data-request-btn]').forEach(function(btn) {
        btn.addEventListener('click', function() { openModal(btn); });
    });

    document.getElementById('pm-modal-cancel')?.addEventListener('click', closeModal);
    modal?.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });

    document.getElementById('pm-modal-submit')?.addEventListener('click', async function() {
        if (!activePayload) return;
        const submitBtn = this;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending…';

        const body = new URLSearchParams();
        body.set(csrfName, csrfToken);
        Object.keys(activePayload).forEach(function(k) {
            if (k !== 'window') body.set(k, activePayload[k]);
        });
        const notes = (notesEl.value || '').trim();
        if (notes) body.set('notes', notes);

        try {
            const resp = await fetch(basePath + '/dashboard/cimm-maintenance-request-api', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'FRS-Dashboard' },
                body: body
            });
            const data = await resp.json();
            if (data.success) {
                alert(data.message || 'Request sent to CIMM.');
                window.location.reload();
                return;
            }
            alert(data.error || 'Unable to submit request.');
        } catch (err) {
            alert('Network error. Please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send to CIMM';
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../../layouts/dashboard_layout.php';
