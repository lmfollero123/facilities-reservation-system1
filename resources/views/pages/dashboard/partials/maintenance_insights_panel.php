<?php
/**
 * Maintenance Insights tab panel (included from maintenance_integration.php).
 * Expects: $base, $canSubmit, $displayRows, $highCount, $mediumCount, $actionableCount,
 *          $pendingSent, $filterBand, $recentRequests
 */
$miTabQs = static function (array $extra = []) use ($filterBand): string {
    $params = array_merge(['tab' => 'insights', 'band' => $filterBand], $extra);
    return '?' . http_build_query($params);
};
?>
<div class="pm-panel">
    <div class="pm-intro">
        <p>Usage-based analysis flags facilities that may need preventive work. Submit a <strong>Request for Maintenance</strong> to CIMM — CPRF does not block bookings until CIMM confirms and syncs.</p>
    </div>

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
                <a class="pm-filter-btn <?= $active; ?>" href="<?= htmlspecialchars($miTabQs(['band' => $key])); ?>"><?= htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="pm-toolbar-actions">
            <a class="btn-outline pm-export-btn" href="<?= htmlspecialchars($miTabQs(['export' => 'csv'])); ?>">Export CSV</a>
        </div>
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
                        <article class="pm-card">
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
                                </div>
                                <div class="pm-card-actions">
                                    <?php if (!$canSubmit): ?>
                                        <span class="pm-muted">View only</span>
                                    <?php elseif (!empty($row['has_pending_request'])): ?>
                                        <button type="button" class="pm-btn-request is-sent" disabled>Request pending with CIMM</button>
                                    <?php elseif ($canRequest): ?>
                                        <button type="button" class="pm-btn-request" data-request-btn
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
                <p class="pm-muted">No maintenance requests submitted yet.</p>
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
                                <br><small class="pm-muted">Ref: <?= htmlspecialchars((string)$req['cimm_reference']); ?></small>
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
    if (!modal) return;
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
            + ' on ' + activePayload.window + '. CIMM will review and schedule.';
        notesEl.value = '';
        modal.classList.add('open');
    }

    function closeModal() {
        modal.classList.remove('open');
        activePayload = null;
    }

    document.querySelectorAll('[data-request-btn]').forEach(function(btn) {
        btn.addEventListener('click', function() { openModal(btn); });
    });
    document.getElementById('pm-modal-cancel')?.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });

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
                window.location.href = basePath + '/dashboard/maintenance-integration?tab=insights';
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
