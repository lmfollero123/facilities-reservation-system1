/**
 * Live occupancy board: filter, search, paginate (client-side).
 */
(function (global) {
    'use strict';

    const OCCUPIED_KEYS = new Set([
        'staff_in_use', 'checked_in', 'no_show_risk', 'booked', 'staff_event_ending', 'staff_closed',
    ]);
    const AVAILABLE_KEYS = new Set(['available', 'staff_vacant']);

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function matchesFilter(fac, filter) {
        const key = fac.aggregate_state || 'available';
        if (filter === 'occupied') return OCCUPIED_KEYS.has(key);
        if (filter === 'available') return AVAILABLE_KEYS.has(key);
        return true;
    }

    function matchesSearch(fac, q) {
        if (!q) return true;
        return String(fac.facility_name || '').toLowerCase().includes(q);
    }

    function staffFormHtml(fac, csrfHtml, hasLiveTable) {
        if (!hasLiveTable) return '';
        const staff = fac.staff_live || {};
        const st = staff.status || 'auto';
        const note = staff.note || '';
        const fid = fac.facility_id;
        return `
            <form method="POST" class="occ-staff-form booking-form">
                ${csrfHtml}
                <input type="hidden" name="facility_id" value="${fid}">
                <div class="occ-staff-form-row">
                    <label class="occ-staff-field occ-staff-field--status">
                        Staff override
                        <div class="input-wrapper">
                            <i class="bi bi-sliders input-icon"></i>
                            <select name="live_status" class="booking-form-control" required>
                                <option value="auto" ${st === 'auto' ? 'selected' : ''}>Auto (from bookings)</option>
                                <option value="in_use" ${st === 'in_use' ? 'selected' : ''}>In use</option>
                                <option value="vacant" ${st === 'vacant' ? 'selected' : ''}>Vacant</option>
                                <option value="event_ending" ${st === 'event_ending' ? 'selected' : ''}>Ending soon</option>
                                <option value="closed" ${st === 'closed' ? 'selected' : ''}>Closed</option>
                            </select>
                        </div>
                    </label>
                    <div class="occ-staff-form-right">
                        <label class="occ-staff-field occ-staff-field--note">
                            Note <span class="occ-optional">(optional)</span>
                            <div class="input-wrapper">
                                <i class="bi bi-chat-left-text input-icon"></i>
                                <input type="text" name="live_note" class="booking-form-control" maxlength="255"
                                    placeholder="e.g. Event ending in 15 min" value="${escapeHtml(note)}">
                            </div>
                        </label>
                        <button type="submit" class="btn-primary occ-staff-save">Save status</button>
                    </div>
                </div>
            </form>`;
    }

    function reservationsHtml(fac, staffMode) {
        const rows = fac.reservations_today || [];
        if (!rows.length) {
            return '<p class="occ-empty-slot">No approved reservations today.</p>';
        }
        const head = staffMode
            ? '<tr><th>Time</th><th>Requester</th><th>Status</th></tr>'
            : '<tr><th>Time</th><th>Status</th></tr>';
        const body = rows.map((res) => {
            const inAt = res.time_in_at
                ? ` <span class="occ-time-in-hint">In ${escapeHtml(new Date(res.time_in_at.replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }))}</span>`
                : '';
            const req = staffMode && res.requester_name
                ? `<td>${escapeHtml(res.requester_name)}</td>`
                : '';
            return `<tr>
                <td>${escapeHtml(res.time_slot)}</td>
                ${req}
                <td><span class="occ-state-pill" style="background:${escapeHtml(res.state_bg)};color:${escapeHtml(res.state_color)};">${escapeHtml(res.state_label)}</span>${inAt}</td>
            </tr>`;
        }).join('');
        return `<div class="table-responsive"><table class="table occ-res-table"><thead>${head}</thead><tbody>${body}</tbody></table></div>`;
    }

    function facilityCardHtml(fac, staffMode, csrfHtml, hasLiveTable) {
        const d = fac.aggregate_display || {};
        const staffForm = staffMode && hasLiveTable ? staffFormHtml(fac, csrfHtml, hasLiveTable) : '';
        return `
            <article class="occ-facility-card booking-card" data-facility-id="${fac.facility_id}">
                <div class="occ-facility-head">
                    <h3 class="occ-facility-name">${escapeHtml(fac.facility_name)}</h3>
                    <span class="occ-agg-badge" style="background:${escapeHtml(d.bg)};color:${escapeHtml(d.color)};">${escapeHtml(d.label)}</span>
                </div>
                ${reservationsHtml(fac, staffMode)}
                ${staffForm}
            </article>`;
    }

    function initBoard(root) {
        if (!root || root.dataset.occInit === '1') return;
        root.dataset.occInit = '1';

        let snapshot = {};
        try {
            snapshot = JSON.parse(root.dataset.snapshot || '{}');
        } catch (e) {
            snapshot = {};
        }

        const staffMode = root.dataset.staffMode === '1';
        const csrfHtml = root.dataset.csrf || '';
        const perPageDefault = parseInt(root.dataset.perPage || '6', 10) || 6;
        const liveUrl = root.dataset.liveUrl || '';

        const summaryEl = root.querySelector('[data-occ-summary]');
        const listEl = root.querySelector('[data-occ-list]');
        const emptyEl = root.querySelector('[data-occ-empty]');
        const paginationEl = root.querySelector('[data-occ-pagination]');
        const asofEl = root.querySelector('[data-occ-asof]');
        const filterEl = root.querySelector('[data-occ-filter]');
        const searchEl = root.querySelector('[data-occ-search]');
        const perPageEl = root.querySelector('[data-occ-per-page]');
        const countEl = root.querySelector('[data-occ-count]');

        let page = 1;
        let perPage = perPageDefault;

        function updateSummary(s) {
            const sum = s.summary || {};
            if (!summaryEl) return;
            const map = {
                total: sum.total_facilities,
                occupied: sum.occupied,
                available: sum.available,
                checked_in: sum.checked_in,
                no_show_risk: sum.no_show_risk,
                rate: (s.occupancy_rate ?? 0) + '%',
            };
            summaryEl.querySelectorAll('[data-occ-kpi]').forEach((el) => {
                const k = el.dataset.occKpi;
                if (map[k] !== undefined) el.textContent = String(map[k]);
            });
            if (asofEl) asofEl.textContent = 'Updated ' + (s.as_of || '');
        }

        function filteredFacilities() {
            const filter = filterEl ? filterEl.value : 'all';
            const q = searchEl ? searchEl.value.trim().toLowerCase() : '';
            return (snapshot.facilities || []).filter((f) => matchesFilter(f, filter) && matchesSearch(f, q));
        }

        function render() {
            const all = filteredFacilities();
            const total = all.length;
            const pages = Math.max(1, Math.ceil(total / perPage));
            if (page > pages) page = pages;
            const start = (page - 1) * perPage;
            const slice = all.slice(start, start + perPage);

            if (countEl) {
                countEl.textContent = total
                    ? `Showing ${start + 1}–${Math.min(start + perPage, total)} of ${total} facilities`
                    : 'No facilities match your filters';
            }

            if (listEl) {
                listEl.innerHTML = slice
                    .map((f) => facilityCardHtml(f, staffMode, csrfHtml, !!snapshot.has_live_status_table))
                    .join('');
            }

            if (emptyEl) {
                emptyEl.hidden = total > 0;
            }

            if (paginationEl) {
                if (pages <= 1) {
                    paginationEl.innerHTML = '';
                    paginationEl.hidden = true;
                } else {
                    paginationEl.hidden = false;
                    paginationEl.innerHTML = `
                        <div class="pagination" style="margin-top:0;">
                            ${page > 1 ? `<button type="button" class="btn-outline occ-page-btn" data-page="${page - 1}">← Prev</button>` : ''}
                            <span style="padding:0.5rem 1rem;color:#5b6888;">Page ${page} of ${pages}</span>
                            ${page < pages ? `<button type="button" class="btn-outline occ-page-btn" data-page="${page + 1}">Next →</button>` : ''}
                        </div>`;
                    paginationEl.querySelectorAll('.occ-page-btn').forEach((btn) => {
                        btn.addEventListener('click', () => {
                            page = parseInt(btn.dataset.page, 10) || 1;
                            render();
                            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        });
                    });
                }
            }
        }

        const defaultFilter = root.dataset.defaultFilter || 'all';
        if (filterEl && defaultFilter && filterEl.querySelector(`option[value="${defaultFilter}"]`)) {
            filterEl.value = defaultFilter;
        }

        if (filterEl) filterEl.addEventListener('change', () => { page = 1; render(); });
        if (searchEl) {
            let t;
            searchEl.addEventListener('input', () => {
                clearTimeout(t);
                t = setTimeout(() => { page = 1; render(); }, 200);
            });
        }
        if (perPageEl) {
            perPageEl.addEventListener('change', () => {
                perPage = parseInt(perPageEl.value, 10) || perPageDefault;
                page = 1;
                render();
            });
        }

        updateSummary(snapshot);
        render();

        if (liveUrl) {
            setInterval(() => {
                fetch(liveUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then((r) => r.json())
                    .then((data) => {
                        if (!data || !data.success || !data.snapshot) return;
                        snapshot = data.snapshot;
                        root.dataset.snapshot = JSON.stringify(snapshot);
                        updateSummary(snapshot);
                        render();
                    })
                    .catch(() => {});
            }, 20000);
        }
    }

    function boot() {
        document.querySelectorAll('[data-occ-board]').forEach(initBoard);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    global.FrsOccupancyBoard = { initBoard };
})(typeof window !== 'undefined' ? window : globalThis);
