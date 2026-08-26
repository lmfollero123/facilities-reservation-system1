<?php
/**
 * Maintenance Insights tab panel (included from maintenance_integration.php).
 * Expects: $base, $canSubmit, $predictiveRows, $displayRows, $displayRowsPaged,
 *          $highCount, $mediumCount, $actionableCount, $pendingSent, $filterBand,
 *          $insightsSearch, $insightsPage, $insightsTotalPages, $insightsTotal,
 *          $insightsPerPage, $insightsOffset, $recentRequests
 */
$miTabQs = static function (array $extra = []) use ($filterBand, $insightsSearch): string {
    $params = array_merge(['tab' => 'insights', 'band' => $filterBand, 'iq' => $insightsSearch], $extra);
    return '?' . http_build_query(array_filter($params, static fn($v) => $v !== ''));
};
?>
<div class="pm-panel">
    <div class="pm-intro">
        <p>Usage-based analysis flags facilities that may need preventive work. Submit a <strong>Request for Maintenance</strong> to CIMM — CPRF does not block bookings until CIMM confirms and syncs.
        <button type="button" class="pm-info-link" id="pm-how-btn">How is this calculated?</button></p>
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
                <a class="pm-filter-btn <?= $active; ?>" href="<?= htmlspecialchars($miTabQs(['band' => $key, 'ipage' => 1])); ?>"><?= htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="pm-toolbar-actions">
            <?php if ($canSubmit): ?>
                <button type="button" class="btn-outline pm-export-btn pm-manual-btn" id="pm-manual-open-btn">🚨 Report Issue / Manual Request</button>
            <?php endif; ?>
            <a class="btn-outline pm-export-btn" href="<?= htmlspecialchars($miTabQs(['export' => 'csv'])); ?>">Export CSV</a>
        </div>
    </div>

    <form method="get" class="pm-search-bar" data-frs-partial="mi-insights-grid" data-frs-partial-auto>
        <input type="hidden" name="tab" value="insights">
        <input type="hidden" name="band" value="<?= htmlspecialchars($filterBand); ?>">
        <input type="hidden" name="ipage" value="1">
        <input type="text" name="iq" value="<?= htmlspecialchars($insightsSearch); ?>" placeholder="Search facility name or location...">
        <button type="submit" class="btn-outline" style="padding:0.45rem 0.85rem; font-size:0.85rem;">Search</button>
    </form>

    <div class="pm-layout" id="mi-insights-grid" data-frs-partial-id="mi-insights-grid" data-frs-partial-root>
        <div>
            <?php if (empty($displayRowsPaged)): ?>
                <div class="pm-empty">
                    <?= $insightsTotal === 0 ? 'Not enough reservation data yet to generate maintenance insights.' : 'No facilities match this search/filter.'; ?>
                </div>
            <?php else: ?>
                <div class="pm-grid">
                    <?php foreach ($displayRowsPaged as $row):
                        $imgUrl = frs_facility_display_image_url(
                            !empty($row['image_path']) ? (string)$row['image_path'] : null,
                            (int)($row['facility_id'] ?? 0),
                            (string)($row['facility_name'] ?? '')
                        );
                        $riskScore = (int)($row['risk_score'] ?? 0);
                        $riskColor = (string)($row['risk_color'] ?? '#64748b');
                        $riskBg = (string)($row['risk_bg'] ?? 'rgba(100,116,139,0.15)');
                        $canRequest = $canSubmit && !empty($row['show_request_action']) && !empty($row['recommended_date']) && empty($row['has_pending_request']);
                        $usageP = (int)($row['usage_pressure'] ?? 0);
                        $growthP = (int)($row['growth_pressure'] ?? 0);
                        $statusP = (int)($row['status_pressure'] ?? 0);
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
                                    <div class="pm-risk-breakdown">
                                        Usage <?= $usageP; ?>/60 · Growth <?= $growthP; ?>/25<?php if ($statusP > 0): ?> · Status <?= $statusP; ?>/15<?php endif; ?>
                                    </div>
                                </div>
                                <div class="pm-metrics">
                                    <div class="pm-metric">90-day bookings<strong><?= (int)$row['usage_90d']; ?></strong></div>
                                    <div class="pm-metric">30-day bookings<strong><?= (int)$row['usage_30d']; ?></strong></div>
                                </div>
                                <div class="pm-window">
                                    Suggested window: <strong><?= htmlspecialchars((string)$row['recommended_window_label']); ?></strong>
                                </div>
                                <div class="pm-ai-explain" data-facility-id="<?= (int)$row['facility_id']; ?>">
                                    <button type="button" class="pm-ai-btn" data-ai-explain-btn>✨ Explain this score</button>
                                    <p class="pm-ai-text" hidden></p>
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

                <?php
                $pmLinkParams = array_filter([
                    'tab' => 'insights',
                    'band' => $filterBand !== 'all' ? $filterBand : null,
                    'iq' => $insightsSearch !== '' ? $insightsSearch : null,
                ]);
                $pmPrevQuery = $insightsPage > 1 ? http_build_query($pmLinkParams + ['ipage' => $insightsPage - 1]) : '';
                $pmNextQuery = $insightsPage < $insightsTotalPages ? http_build_query($pmLinkParams + ['ipage' => $insightsPage + 1]) : '';
                ?>
                <div class="pagination-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; margin-top:1rem; padding-top:1rem; border-top:1px solid #e0e6ed;">
                    <span style="color:#6b7280; font-size:0.85rem;">
                        Showing <?= $insightsTotal ? $insightsOffset + 1 : 0 ?>–<?= min($insightsOffset + $insightsPerPage, $insightsTotal); ?> of <?= $insightsTotal; ?>
                    </span>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <?php if ($pmPrevQuery): ?>
                            <a href="?<?= htmlspecialchars($pmPrevQuery); ?>" data-frs-partial="mi-insights-grid" class="btn-outline" style="padding:0.35rem 0.7rem; font-size:0.82rem;">← Prev</a>
                        <?php else: ?>
                            <span class="btn-outline" style="padding:0.35rem 0.7rem; font-size:0.82rem; opacity:0.5; pointer-events:none;">← Prev</span>
                        <?php endif; ?>
                        <span style="font-size:0.85rem; color:#4b5563;">Page <?= $insightsPage; ?> of <?= $insightsTotalPages; ?></span>
                        <?php if ($pmNextQuery): ?>
                            <a href="?<?= htmlspecialchars($pmNextQuery); ?>" data-frs-partial="mi-insights-grid" class="btn-outline" style="padding:0.35rem 0.7rem; font-size:0.82rem;">Next →</a>
                        <?php else: ?>
                            <span class="btn-outline" style="padding:0.35rem 0.7rem; font-size:0.82rem; opacity:0.5; pointer-events:none;">Next →</span>
                        <?php endif; ?>
                    </div>
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
                        $isManualReq = (string)($req['risk_band'] ?? '') === 'Manual';
                    ?>
                        <li class="pm-request-item">
                            <strong><?= htmlspecialchars((string)$req['facility_name']); ?></strong>
                            <?php if ($isManualReq): ?><span class="pm-manual-tag">Manual</span><?php endif; ?>
                            <?= date('M d, Y', strtotime((string)$req['requested_date'])); ?>
                            · <?= htmlspecialchars(ucfirst((string)$req['priority'])); ?> priority
                            <?php if (!empty($req['cimm_reference'])): ?>
                                <br><small class="pm-muted">Ref: <?= htmlspecialchars((string)$req['cimm_reference']); ?></small>
                            <?php endif; ?>
                            <?php if (!empty($req['assigned_staff_name'])): ?>
                                <br><small class="pm-muted">Assigned: <?= htmlspecialchars((string)$req['assigned_staff_name']); ?></small>
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

<div class="pm-modal-backdrop" id="pm-manual-modal" aria-hidden="true">
    <div class="pm-modal" role="dialog" aria-labelledby="pm-manual-modal-title">
        <h3 id="pm-manual-modal-title">Report an issue / request maintenance now</h3>
        <p class="pm-muted" style="margin-top:-0.4rem;">Use this for anything that can't wait for the usage-based queue below — accidents, breakage, safety hazards. This bypasses the risk score entirely.</p>
        <label for="pm-manual-facility">Facility</label>
        <select id="pm-manual-facility">
            <option value="">— Select facility —</option>
            <?php foreach ($predictiveRows as $row): ?>
                <option value="<?= (int)$row['facility_id']; ?>"
                    data-name="<?= htmlspecialchars((string)$row['facility_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-location="<?= htmlspecialchars((string)($row['location'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?= htmlspecialchars((string)$row['facility_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="pm-manual-date">Date needed</label>
        <input type="date" id="pm-manual-date">
        <label for="pm-manual-priority">Urgency</label>
        <select id="pm-manual-priority">
            <option value="high" selected>High — needs attention ASAP</option>
            <option value="medium">Medium — soon</option>
            <option value="low">Low — routine</option>
        </select>
        <label for="pm-manual-notes">Describe the issue (required)</label>
        <textarea id="pm-manual-notes" placeholder="e.g. Broken glass panel near the entrance after last night's storm"></textarea>
        <div class="pm-modal-actions">
            <button type="button" id="pm-manual-cancel">Cancel</button>
            <button type="button" class="primary" id="pm-manual-submit">Send to CIMM</button>
        </div>
    </div>
</div>

<div class="pm-modal-backdrop" id="pm-how-modal" aria-hidden="true">
    <div class="pm-modal" role="dialog" aria-labelledby="pm-how-modal-title">
        <h3 id="pm-how-modal-title">How maintenance pressure is calculated</h3>
        <p>Each facility's score (0–100) adds up three parts, based on its own booking history:</p>
        <ul class="pm-how-list">
            <li><strong>Usage pressure (up to 60 pts)</strong> — more reservations in the last 90 days means more wear, so this rises with the 90-day booking count.</li>
            <li><strong>Growth pressure (up to 25 pts)</strong> — if the last 30 days are busier than the facility's usual 90-day pace, pressure rises faster than usage alone would suggest.</li>
            <li><strong>Status pressure (15 pts)</strong> — added automatically only while the facility is already flagged "under maintenance".</li>
        </ul>
        <p><strong>Low</strong> is under 45, <strong>Medium</strong> is 45–74, <strong>High</strong> is 75+. Tap "✨ Explain this score" on any facility card for a plain-English breakdown of its specific number.</p>
        <div class="pm-modal-actions">
            <button type="button" class="primary" id="pm-how-close">Got it</button>
        </div>
    </div>
</div>

<script>
(function() {
    const basePath = <?= json_encode($base); ?>;
    const csrfName = <?= json_encode(CSRF_TOKEN_NAME); ?>;
    const csrfToken = <?= json_encode(csrf_token()); ?>;

    // ---- How-is-this-calculated modal ----
    const howBtn = document.getElementById('pm-how-btn');
    const howModal = document.getElementById('pm-how-modal');
    if (howBtn && howModal) {
        howBtn.addEventListener('click', function () { howModal.classList.add('open'); });
        document.getElementById('pm-how-close')?.addEventListener('click', function () { howModal.classList.remove('open'); });
        howModal.addEventListener('click', function (e) { if (e.target === howModal) howModal.classList.remove('open'); });
    }

    // ---- AI explain-this-score (on demand, per card) ----
    // Delegated on document: the insights grid is swapped via AJAX partial
    // reload (search/pagination) and #mi-insights-grid's children are
    // replaced wholesale, so a direct per-button binding here would go stale
    // after the first page/search change. Delegation survives that swap
    // because this listener lives on document, not on the replaced nodes.
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-ai-explain-btn]');
        if (!btn) return;
        const wrap = btn.closest('.pm-ai-explain');
        const textEl = wrap.querySelector('.pm-ai-text');
        const facilityId = wrap.dataset.facilityId;
        btn.disabled = true;
        btn.textContent = 'Thinking…';
        try {
            const body = new URLSearchParams();
            body.set(csrfName, csrfToken);
            body.set('facility_id', facilityId);
            const resp = await fetch(basePath + '/dashboard/maintenance-insight-explain-api', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'FRS-Dashboard' },
                body: body
            });
            const data = await resp.json();
            if (data.success) {
                textEl.textContent = data.explanation;
                textEl.hidden = false;
                btn.remove();
            } else {
                btn.disabled = false;
                btn.textContent = '✨ Explain this score';
                alert(data.error || 'Unable to generate explanation.');
            }
        } catch (err) {
            btn.disabled = false;
            btn.textContent = '✨ Explain this score';
            alert('Network error. Please try again.');
        }
    });

    // ---- Algorithmic (risk-driven) request modal ----
    const modal = document.getElementById('pm-request-modal');
    if (modal) {
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
            if (modal.parentNode !== document.body) document.body.appendChild(modal);
            modal.classList.add('open');
        }

        function closeModal() {
            modal.classList.remove('open');
            activePayload = null;
        }

        // Delegated for the same reason as the AI-explain buttons above -
        // these cards get replaced by the search/pagination partial reload.
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-request-btn]');
            if (btn) openModal(btn);
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
    }

    // ---- Manual / emergency request modal (any facility, any time) ----
    const manualOpenBtn = document.getElementById('pm-manual-open-btn');
    const manualModal = document.getElementById('pm-manual-modal');
    if (manualOpenBtn && manualModal) {
        const facilitySel = document.getElementById('pm-manual-facility');
        const dateEl = document.getElementById('pm-manual-date');
        const priorityEl = document.getElementById('pm-manual-priority');
        const notesEl = document.getElementById('pm-manual-notes');

        function openManualModal() {
            facilitySel.value = '';
            priorityEl.value = 'high';
            notesEl.value = '';
            const today = new Date();
            dateEl.value = today.toISOString().slice(0, 10);
            dateEl.min = today.toISOString().slice(0, 10);
            if (manualModal.parentNode !== document.body) document.body.appendChild(manualModal);
            manualModal.classList.add('open');
        }
        function closeManualModal() {
            manualModal.classList.remove('open');
        }

        manualOpenBtn.addEventListener('click', openManualModal);
        document.getElementById('pm-manual-cancel')?.addEventListener('click', closeManualModal);
        manualModal.addEventListener('click', function (e) { if (e.target === manualModal) closeManualModal(); });

        document.getElementById('pm-manual-submit')?.addEventListener('click', async function () {
            const facilityId = facilitySel.value;
            const notes = (notesEl.value || '').trim();
            if (!facilityId) { alert('Please select a facility.'); return; }
            if (!dateEl.value) { alert('Please choose a date.'); return; }
            if (!notes) { alert('Please describe the issue.'); return; }

            const opt = facilitySel.selectedOptions[0];
            const submitBtn = this;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending…';
            const body = new URLSearchParams();
            body.set(csrfName, csrfToken);
            body.set('facility_id', facilityId);
            body.set('facility_name', opt?.dataset.name || '');
            body.set('location', opt?.dataset.location || '');
            body.set('requested_date', dateEl.value);
            body.set('priority', priorityEl.value);
            body.set('notes', notes);
            body.set('request_source', 'manual');
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
    }
})();
</script>
