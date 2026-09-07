/**
 * Dashboard live occupancy — modern single-facility slideshow + View All modal.
 */
(function () {
    'use strict';

    const BUSY_KEYS = new Set([
        'staff_in_use', 'checked_in', 'no_show_risk', 'booked', 'staff_event_ending',
    ]);
    const AVAILABLE_KEYS = new Set(['available', 'staff_vacant']);
    const UNAVAILABLE_KEYS = new Set(['maintenance', 'offline', 'closed', 'staff_closed']);

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function pillClassForState(state) {
        if (AVAILABLE_KEYS.has(state)) return 'is-available';
        if (BUSY_KEYS.has(state)) return state === 'booked' ? 'is-booked' : 'is-busy';
        if (state === 'maintenance' || state === 'no_show_risk') return 'is-warn';
        if (UNAVAILABLE_KEYS.has(state)) return 'is-muted';
        return 'is-available';
    }

    function statusBadgeHtml(fac) {
        const d = fac.aggregate_display || {};
        const state = fac.aggregate_state || 'available';
        const label = d.label || (BUSY_KEYS.has(state) ? 'Occupied' : UNAVAILABLE_KEYS.has(state) ? 'Unavailable' : 'Available');
        const pillClass = pillClassForState(state);
        return (
            '<span class="occ-dash-pill ' + pillClass + '">' +
            escapeHtml(label) + '</span>'
        );
    }

    function activeSlotHint(fac) {
        const reservations = fac.reservations_today || [];
        if (!reservations.length) {
            return '';
        }
        const priority = ['checked_in', 'no_show_risk', 'scheduled', 'upcoming', 'checked_out', 'completed', 'no_show'];
        for (let i = 0; i < priority.length; i++) {
            const key = priority[i];
            const match = reservations.find(function (r) { return r.operational_state === key; });
            if (match) {
                return match.time_slot + (match.state_label ? ' · ' + match.state_label : '');
            }
        }
        return reservations[0].time_slot || '';
    }

    function nextReservationHint(fac) {
        const reservations = fac.reservations_today || [];
        const upcoming = reservations.find(function (r) {
            return r.operational_state === 'upcoming' || r.operational_state === 'scheduled';
        });
        if (upcoming) {
            return upcoming.time_slot || '';
        }
        const active = reservations.find(function (r) {
            return r.operational_state === 'checked_in' || r.operational_state === 'no_show_risk';
        });
        if (active) {
            return active.time_slot || '';
        }
        return reservations[0] ? (reservations[0].time_slot || '') : '';
    }

    function renderListRow(fac, isSelected) {
        const slot = activeSlotHint(fac) || nextReservationHint(fac);
        const img = fac.image_url || '';
        const name = escapeHtml(fac.facility_name || 'Facility');
        const id = escapeHtml(String(fac.facility_id || ''));

        return (
            '<button type="button" class="occ-dash-row' + (isSelected ? ' is-selected' : '') + '" data-occ-dash-row="' + id + '">' +
                '<img src="' + escapeHtml(img) + '" alt="" class="occ-dash-row__img" loading="lazy">' +
                '<div class="occ-dash-row__body">' +
                    '<p class="occ-dash-row__name">' + name + '</p>' +
                    '<p class="occ-dash-row__meta">' + (slot ? escapeHtml(slot) : 'No reservations today') + '</p>' +
                '</div>' +
                statusBadgeHtml(fac) +
            '</button>'
        );
    }

    function renderModalRow(fac) {
        const img = fac.image_url || '';
        const name = escapeHtml(fac.facility_name || 'Facility');
        const slot = activeSlotHint(fac);

        return (
            '<button type="button" class="occ-dash-modal-row" data-occ-dash-pick="' + escapeHtml(String(fac.facility_id || '')) + '">' +
                '<img src="' + escapeHtml(img) + '" alt="" class="occ-dash-modal-row__img" loading="lazy">' +
                '<div>' +
                    '<p class="occ-dash-modal-row__name">' + name + '</p>' +
                    (slot ? '<p class="occ-dash-modal-row__meta">' + escapeHtml(slot) + '</p>' : '') +
                '</div>' +
                statusBadgeHtml(fac) +
            '</button>'
        );
    }

    function filterFacilities(facilities, query, filterKey) {
        const q = (query || '').trim().toLowerCase();
        return facilities.filter(function (fac) {
            const state = fac.aggregate_state || 'available';
            if (filterKey === 'available' && !AVAILABLE_KEYS.has(state)) return false;
            if (filterKey === 'busy' && !BUSY_KEYS.has(state)) return false;
            if (q && !(fac.facility_name || '').toLowerCase().includes(q)) return false;
            return true;
        });
    }

    function initStrip(root) {
        if (!root || root.dataset.occDashInit === '1') return;
        root.dataset.occDashInit = '1';

        let snapshot = {};
        try {
            snapshot = JSON.parse(root.dataset.snapshot || '{}');
        } catch (e) {
            snapshot = {};
        }

        let facilities = snapshot.facilities || [];
        let selectedId = null;
        let modalFilter = 'all';
        let modalQuery = '';

        const listEl = root.querySelector('[data-occ-dash-list]');
        const emptyEl = root.querySelector('[data-occ-dash-empty]');
        const busyEl = root.querySelector('[data-occ-dash-busy]');
        const totalEl = root.querySelector('[data-occ-dash-total]');
        const asofEl = root.querySelector('[data-occ-dash-asof]');

        const modal = document.querySelector('[data-occ-dash-modal]');
        const modalList = modal ? modal.querySelector('[data-occ-dash-modal-list]') : null;
        const modalSearch = modal ? modal.querySelector('[data-occ-dash-modal-search]') : null;
        const modalFilters = modal ? modal.querySelector('[data-occ-dash-modal-filters]') : null;

        const liveUrl = root.dataset.liveUrl || '';
        const REFRESH_MS = 45000;

        function findById(id) {
            return facilities.find(function (f) {
                return String(f.facility_id) === String(id);
            }) || null;
        }

        function updateSummary() {
            const sum = snapshot.summary || {};
            if (busyEl) busyEl.textContent = String(sum.occupied ?? 0);
            if (totalEl) totalEl.textContent = String(sum.total_facilities ?? facilities.length);
            if (asofEl) asofEl.textContent = 'Updated ' + (snapshot.as_of || '');
        }

        function selectFacility(id, source) {
            selectedId = id;
            renderList();
            if (source) {
                document.dispatchEvent(new CustomEvent('frs:facility-status-select', {
                    detail: { id: id, source: source },
                }));
            }
        }

        function renderList() {
            updateSummary();
            const hasFacilities = facilities.length > 0;

            if (emptyEl) emptyEl.hidden = hasFacilities;
            if (listEl) listEl.hidden = !hasFacilities;

            if (!hasFacilities) {
                if (listEl) listEl.innerHTML = '';
                return;
            }

            if (listEl) {
                listEl.innerHTML = facilities.map(function (fac) {
                    return renderListRow(fac, selectedId != null && String(fac.facility_id) === String(selectedId));
                }).join('');
            }
        }

        function renderModalList() {
            if (!modalList) return;
            const filtered = filterFacilities(facilities, modalQuery, modalFilter);
            if (!filtered.length) {
                modalList.innerHTML = '<p class="occ-dash-modal__empty">No facilities match your search.</p>';
                return;
            }
            modalList.innerHTML = filtered.map(renderModalRow).join('');
        }

        function openModal() {
            if (!modal) return;
            if (modal.parentNode !== document.body) document.body.appendChild(modal);
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            renderModalList();
            if (modalSearch) modalSearch.focus();
        }

        function closeModal() {
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        function applySnapshot(next) {
            if (!next || typeof next !== 'object') return;
            snapshot = next;
            facilities = snapshot.facilities || [];
            root.dataset.snapshot = JSON.stringify(next);
            if (selectedId != null && !findById(selectedId)) {
                selectedId = null;
            }
            renderList();
            if (modal && modal.classList.contains('is-open')) {
                renderModalList();
            }
        }

        async function refresh() {
            if (!liveUrl) return;
            try {
                const resp = await fetch(liveUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!resp.ok) return;
                const data = await resp.json();
                if (data.success && data.snapshot) {
                    applySnapshot(data.snapshot);
                }
            } catch (e) {
                /* keep last good snapshot */
            }
        }

        listEl?.addEventListener('click', function (e) {
            const row = e.target.closest('[data-occ-dash-row]');
            if (!row) return;
            selectFacility(row.getAttribute('data-occ-dash-row'), 'strip');
        });

        document.addEventListener('frs:facility-status-select', function (e) {
            if (!e.detail || e.detail.source === 'strip') return;
            if (!findById(e.detail.id)) return;
            selectedId = e.detail.id;
            renderList();
            const row = listEl?.querySelector('[data-occ-dash-row="' + CSS.escape(String(e.detail.id)) + '"]');
            row?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        });

        root.querySelector('[data-occ-dash-open-modal]')?.addEventListener('click', openModal);
        modal?.querySelectorAll('[data-occ-dash-close-modal]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });
        modalSearch?.addEventListener('input', function () {
            modalQuery = modalSearch.value;
            renderModalList();
        });
        modalFilters?.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-occ-dash-filter]');
            if (!btn) return;
            modalFilter = btn.getAttribute('data-occ-dash-filter') || 'all';
            modalFilters.querySelectorAll('.occ-dash-filter').forEach(function (chip) {
                chip.classList.toggle('is-active', chip === btn);
            });
            renderModalList();
        });
        modalList?.addEventListener('click', function (e) {
            const row = e.target.closest('[data-occ-dash-pick]');
            if (!row) return;
            selectFacility(row.getAttribute('data-occ-dash-pick'), 'strip');
            closeModal();
        });

        document.addEventListener('keydown', function (e) {
            if (modal && modal.classList.contains('is-open') && e.key === 'Escape') {
                closeModal();
            }
        });

        renderList();
        if (liveUrl) {
            setInterval(refresh, REFRESH_MS);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-occ-dash-strip]').forEach(initStrip);
    });
})();
