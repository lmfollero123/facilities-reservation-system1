/**
 * Per-chart month/year sync (All Time) on reports analytics.
 */
(function () {
    'use strict';

    function bindAllTimeSync(selectEl) {
        if (!selectEl || !selectEl.form) return;
        const prefix = selectEl.getAttribute('data-chart-prefix');
        if (!prefix) return;
        const form = selectEl.form;
        const monthSel = form.querySelector('[name="' + prefix + '_month"]');
        const yearSel = form.querySelector('[name="' + prefix + '_year"]');
        if (!monthSel || !yearSel) return;

        if (selectEl.classList.contains('chart-filter-month') && selectEl.value === 'all') {
            yearSel.value = 'all';
            form.submit();
        }
        if (selectEl.classList.contains('chart-filter-year') && selectEl.value === 'all') {
            monthSel.value = 'all';
            form.submit();
        }
    }

    document.addEventListener('change', function (e) {
        const el = e.target;
        if (!el.classList || !el.classList.contains('chart-filter-control')) return;
        if (!el.classList.contains('chart-filter-month') && !el.classList.contains('chart-filter-year')) return;
        bindAllTimeSync(el);
    });
})();
