/**
 * Shared Chart.js setup for dashboard home and reports pages.
 */
(function (window) {
    'use strict';

    const alwaysValueLabelsPlugin = {
        id: 'alwaysValueLabels',
        afterDatasetsDraw(chart, args, pluginOptions) {
            const opts = pluginOptions || {};
            const color = opts.color || '#111827';
            const fontSize = opts.fontSize || 12;
            const weight = opts.fontWeight || '700';
            const formatter = typeof opts.formatter === 'function'
                ? opts.formatter
                : (v) => String(v);

            const ctx = chart.ctx;
            ctx.save();
            ctx.fillStyle = color;
            ctx.font = `${weight} ${fontSize}px Arial`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            chart.data.datasets.forEach((dataset, datasetIndex) => {
                const meta = chart.getDatasetMeta(datasetIndex);
                if (meta.hidden) return;
                meta.data.forEach((element, dataIndex) => {
                    const raw = dataset.data[dataIndex];
                    if (raw === null || raw === undefined || Number(raw) === 0) return;
                    const label = formatter(raw, dataIndex, dataset, chart);
                    const pos = element.tooltipPosition();
                    ctx.fillText(label, pos.x, pos.y);
                });
            });
            ctx.restore();
        }
    };

    function gridColor() {
        return document.documentElement.getAttribute('data-theme') === 'dark'
            ? 'rgba(255, 255, 255, 0.08)'
            : 'rgba(0, 0, 0, 0.05)';
    }

    function initReservationCharts(cfg) {
        if (!window.Chart || !cfg) return;

        const plugins = cfg.showValueLabels ? [alwaysValueLabelsPlugin] : [];
        const valueLabelOpts = cfg.showValueLabels ? {
            alwaysValueLabels: cfg.valueLabelOptions || { color: '#1f2937', fontSize: 11 }
        } : {};

        const monthlyCtx = document.getElementById('monthlyChart');
        if (monthlyCtx) {
            new Chart(monthlyCtx, {
                type: 'line',
                plugins: plugins.slice(),
                data: {
                    labels: cfg.monthlyLabels || [],
                    datasets: [{
                        label: 'Reservations',
                        data: cfg.monthlyData || [],
                        borderColor: '#0047ab',
                        backgroundColor: 'rgba(0, 71, 171, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2,
                        pointBackgroundColor: '#0047ab',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: Object.assign({ legend: { display: false } }, valueLabelOpts),
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 },
                            grid: { color: gridColor() }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const statusOptions = {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, font: { size: 12 } }
                    }
                }
            };
            if (cfg.showValueLabels) {
                statusOptions.plugins.alwaysValueLabels = { color: '#ffffff', fontSize: 12 };
                statusOptions.cutout = '60%';
            }
            new Chart(statusCtx, {
                type: 'doughnut',
                plugins: plugins.slice(),
                data: {
                    labels: cfg.statusLabels || [],
                    datasets: [{
                        data: cfg.statusCounts || [],
                        backgroundColor: cfg.statusColors || [],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: statusOptions
            });
        }

        const facilityCtx = document.getElementById('facilityChart');
        if (facilityCtx) {
            const facilityDataset = cfg.showValueLabels
                ? {
                    label: 'Approved Bookings',
                    data: cfg.facilityCounts || [],
                    backgroundColor: 'rgba(0, 71, 171, 0.85)',
                    borderColor: '#0047ab',
                    borderWidth: 1.5,
                    borderRadius: 6
                }
                : {
                    label: 'Bookings',
                    data: cfg.facilityCounts || [],
                    backgroundColor: '#0047ab',
                    borderRadius: 6,
                    borderSkipped: false
                };

            const facilityOptions = {
                responsive: true,
                maintainAspectRatio: true,
                plugins: Object.assign({ legend: { display: false } }, valueLabelOpts),
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: cfg.showValueLabels ? undefined : { stepSize: 1 },
                        grid: { color: gridColor() }
                    },
                    x: {
                        grid: { display: false },
                        ticks: cfg.rotateFacilityLabels ? { maxRotation: 45, minRotation: 45 } : undefined
                    }
                }
            };

            new Chart(facilityCtx, {
                type: 'bar',
                plugins: plugins.slice(),
                data: {
                    labels: cfg.facilityLabels || [],
                    datasets: [facilityDataset]
                },
                options: facilityOptions
            });
        }
    }

    window.frsInitReservationCharts = initReservationCharts;
})(window);
