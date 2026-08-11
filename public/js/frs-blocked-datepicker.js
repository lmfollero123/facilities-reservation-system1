/**
 * Small calendar popover that shades maintenance/blackout days for a facility,
 * so staff picking a new date on Modify/Postpone/Extend can see blocked days
 * instead of guessing and hitting the conflict check after the fact.
 *
 * Native <input type="date"> cannot be styled per-day, so attach() swaps the
 * input to a text field driven entirely by this popover; the input keeps its
 * id/name/min/required so existing form handling and JS listeners (change
 * events, conflict checks) keep working unchanged.
 */
(function () {
    'use strict';

    var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    var blockedCache = {};
    var openState = null; // { input, popover, year, month, getFacilityId }

    function basePath() {
        return (window.APP_BASE_PATH || '').replace(/\/$/, '');
    }

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function isoOf(year, month, day) {
        return year + '-' + pad2(month) + '-' + pad2(day);
    }

    function todayISO() {
        var d = new Date();
        return isoOf(d.getFullYear(), d.getMonth() + 1, d.getDate());
    }

    function fetchBlocked(facilityId, year, month) {
        var key = facilityId + '-' + year + '-' + month;
        if (blockedCache[key]) {
            return blockedCache[key];
        }
        var promise = fetch(basePath() + '/dashboard/facility-blackout-dates-api?facility_id=' + encodeURIComponent(facilityId) + '&year=' + year + '&month=' + month, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(function (resp) {
            var contentType = resp.headers.get('content-type') || '';
            if (!resp.ok || contentType.indexOf('application/json') === -1) {
                return {};
            }
            return resp.json().then(function (data) {
                return (data && data.blocked_dates) || {};
            });
        }).catch(function () {
            return {};
        });
        blockedCache[key] = promise;
        return promise;
    }

    function closePopover() {
        if (!openState) return;
        openState.popover.remove();
        document.removeEventListener('mousedown', onOutsideClick, true);
        document.removeEventListener('keydown', onKeydown, true);
        window.removeEventListener('resize', closePopover);
        window.removeEventListener('scroll', closePopoverOnScroll, true);
        openState = null;
    }

    function onOutsideClick(e) {
        if (openState && !openState.popover.contains(e.target) && e.target !== openState.input) {
            closePopover();
        }
    }

    function onKeydown(e) {
        if (e.key === 'Escape') closePopover();
    }

    function closePopoverOnScroll(e) {
        if (openState && openState.popover.contains(e.target)) return;
        closePopover();
    }

    function renderMonth() {
        if (!openState) return;
        var state = openState;
        var popover = state.popover;
        var year = state.year;
        var month = state.month;
        var min = state.input.getAttribute('data-min-date') || todayISO();
        var selected = state.input.value || '';

        var facilityId = state.getFacilityId();
        var loadPromise = facilityId ? fetchBlocked(facilityId, year, month) : Promise.resolve({});

        popover.innerHTML =
            '<div class="frs-bdp-header">' +
            '<button type="button" class="frs-bdp-nav" data-nav="-1" aria-label="Previous month">&lsaquo;</button>' +
            '<span class="frs-bdp-title">' + MONTH_NAMES[month - 1] + ' ' + year + '</span>' +
            '<button type="button" class="frs-bdp-nav" data-nav="1" aria-label="Next month">&rsaquo;</button>' +
            '</div>' +
            '<div class="frs-bdp-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>' +
            '<div class="frs-bdp-grid frs-bdp-loading">Loading&hellip;</div>' +
            '<div class="frs-bdp-legend"><span class="frs-bdp-chip frs-bdp-chip--blocked"></span> Maintenance / blackout &nbsp; <span class="frs-bdp-chip frs-bdp-chip--selected"></span> Selected</div>';

        popover.querySelectorAll('[data-nav]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var delta = parseInt(btn.getAttribute('data-nav'), 10);
                state.month += delta;
                if (state.month < 1) { state.month = 12; state.year -= 1; }
                if (state.month > 12) { state.month = 1; state.year += 1; }
                renderMonth();
            });
        });

        loadPromise.then(function (blocked) {
            if (!openState || openState !== state) return; // popover was closed/reopened meanwhile
            var grid = popover.querySelector('.frs-bdp-grid');
            grid.classList.remove('frs-bdp-loading');
            grid.innerHTML = '';

            var firstWeekday = new Date(year, month - 1, 1).getDay();
            var daysInMonth = new Date(year, month, 0).getDate();

            for (var i = 0; i < firstWeekday; i++) {
                grid.appendChild(document.createElement('span'));
            }

            for (var day = 1; day <= daysInMonth; day++) {
                var iso = isoOf(year, month, day);
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'frs-bdp-day';
                cell.textContent = String(day);

                var isPast = iso < min;
                var blockedReason = blocked[iso];

                if (iso === selected) {
                    cell.classList.add('frs-bdp-day--selected');
                }
                if (isPast) {
                    cell.classList.add('frs-bdp-day--disabled');
                    cell.disabled = true;
                } else if (blockedReason) {
                    cell.classList.add('frs-bdp-day--blocked');
                    cell.disabled = true;
                    cell.title = blockedReason;
                } else {
                    cell.addEventListener('click', function (pickedIso) {
                        return function () {
                            state.input.value = pickedIso;
                            state.input.dispatchEvent(new Event('input', { bubbles: true }));
                            state.input.dispatchEvent(new Event('change', { bubbles: true }));
                            closePopover();
                        };
                    }(iso));
                }

                grid.appendChild(cell);
            }
        });
    }

    function positionPopover(input, popover) {
        var rect = input.getBoundingClientRect();
        var top = rect.bottom + 4;
        var left = rect.left;
        // Keep on-screen if the input sits near the modal's right/bottom edge.
        var maxLeft = window.innerWidth - 260;
        if (left > maxLeft) left = Math.max(8, maxLeft);
        if (top > window.innerHeight - 280) {
            top = Math.max(8, rect.top - 4 - 300);
        }
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    }

    function openPopover(input, getFacilityId) {
        if (openState && openState.input === input) {
            closePopover();
            return;
        }
        closePopover();

        var base = input.value || todayISO();
        var parts = base.split('-').map(Number);
        var popover = document.createElement('div');
        popover.className = 'frs-bdp-popover';
        document.body.appendChild(popover);

        openState = {
            input: input,
            popover: popover,
            year: parts[0] || new Date().getFullYear(),
            month: parts[1] || (new Date().getMonth() + 1),
            getFacilityId: getFacilityId,
        };

        positionPopover(input, popover);
        renderMonth();

        document.addEventListener('mousedown', onOutsideClick, true);
        document.addEventListener('keydown', onKeydown, true);
        window.addEventListener('resize', closePopover);
        window.addEventListener('scroll', closePopoverOnScroll, true);
    }

    function attach(input, getFacilityId) {
        if (!input || input.dataset.frsBdpAttached === '1') return;
        input.dataset.frsBdpAttached = '1';
        if (input.getAttribute('min')) {
            input.setAttribute('data-min-date', input.getAttribute('min'));
        }
        input.setAttribute('type', 'text');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('placeholder', 'YYYY-MM-DD');
        input.readOnly = true;
        input.addEventListener('click', function () {
            openPopover(input, getFacilityId);
        });
    }

    window.frsBlockedDatePicker = { attach: attach };
})();
