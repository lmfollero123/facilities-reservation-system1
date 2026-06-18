/**
 * Shared Chart.js setup for dashboard home and reports pages.
 */
(function (window) {
    'use strict';

    const alwaysValueLabelsPlugin = {
        id: 'alwaysValueLabels',
        afterDatasetsDraw(chart, args, pluginOptions) {
            const opts = pluginOptions || {};
            const defaultColor = opts.color || '#111827';
            const fontSize = opts.fontSize || 12;
            const weight = opts.fontWeight || '700';
            const formatter = typeof opts.formatter === 'function'
                ? opts.formatter
                : (v) => String(v);

            const ctx = chart.ctx;
            const chartType = chart.config.type;

            ctx.save();
            ctx.font = `${weight} ${fontSize}px Arial, sans-serif`;

            chart.data.datasets.forEach((dataset, datasetIndex) => {
                const meta = chart.getDatasetMeta(datasetIndex);
                if (meta.hidden) return;
                meta.data.forEach((element, dataIndex) => {
                    const raw = dataset.data[dataIndex];
                    if (raw === null || raw === undefined || Number(raw) === 0) return;
                    const label = formatter(raw, dataIndex, dataset, chart);

                    let x;
                    let y;
                    let textAlign = 'center';
                    let textBaseline = 'middle';
                    let fillStyle = defaultColor;

                    if (chartType === 'bar') {
                        x = element.x;
                        y = element.y - 8;
                        textBaseline = 'bottom';
                        fillStyle = opts.barColor || '#1e293b';
                        ctx.shadowColor = 'rgba(255, 255, 255, 0.9)';
                        ctx.shadowBlur = 4;
                    } else if (chartType === 'doughnut' || chartType === 'pie') {
                        const pos = element.tooltipPosition();
                        x = pos.x;
                        y = pos.y;
                        const bg = Array.isArray(dataset.backgroundColor)
                            ? dataset.backgroundColor[dataIndex]
                            : dataset.backgroundColor;
                        fillStyle = contrastLabelColor(bg, opts);
                        ctx.shadowColor = 'rgba(0, 0, 0, 0.35)';
                        ctx.shadowBlur = 3;
                    } else {
                        const pos = element.tooltipPosition();
                        x = pos.x;
                        y = pos.y - 10;
                        textBaseline = 'bottom';
                        fillStyle = opts.lineColor || defaultColor;
                    }

                    ctx.fillStyle = fillStyle;
                    ctx.textAlign = textAlign;
                    ctx.textBaseline = textBaseline;
                    ctx.fillText(label, x, y);
                    ctx.shadowBlur = 0;
                });
            });
            ctx.restore();
        }
    };

    function parseColorToRgb(color) {
        if (!color || typeof color !== 'string') return null;
        const hex = color.trim();
        if (hex.startsWith('#')) {
            const h = hex.slice(1);
            const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
            if (full.length !== 6) return null;
            return {
                r: parseInt(full.slice(0, 2), 16),
                g: parseInt(full.slice(2, 4), 16),
                b: parseInt(full.slice(4, 6), 16),
            };
        }
        const rgba = hex.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
        if (rgba) {
            return { r: +rgba[1], g: +rgba[2], b: +rgba[3] };
        }
        return null;
    }

    function contrastLabelColor(background, opts) {
        const rgb = parseColorToRgb(background);
        if (!rgb) return opts.color || '#ffffff';
        const luminance = (0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b) / 255;
        return luminance > 0.62 ? (opts.darkColor || '#1e293b') : (opts.lightColor || '#ffffff');
    }

    function gridColor() {
        return document.documentElement.getAttribute('data-theme') === 'dark'
            ? 'rgba(255, 255, 255, 0.08)'
            : 'rgba(0, 0, 0, 0.05)';
    }

    function initReservationCharts(cfg) {
        if (!window.Chart || !cfg) return;

        const plugins = cfg.showValueLabels ? [alwaysValueLabelsPlugin] : [];
        const valueLabelOpts = cfg.showValueLabels ? {
            alwaysValueLabels: Object.assign({
                color: '#1e293b',
                barColor: '#1e293b',
                fontSize: 12,
                fontWeight: '700',
            }, cfg.valueLabelOptions || {}),
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
                statusOptions.plugins.alwaysValueLabels = { fontSize: 13, fontWeight: '700' };
                statusOptions.plugins.legend = {
                    position: 'bottom',
                    labels: {
                        padding: 12,
                        font: { size: 12, weight: '600' },
                        generateLabels(chart) {
                            const dataset = chart.data.datasets[0];
                            return chart.data.labels.map((label, i) => {
                                const value = dataset.data[i];
                                const fill = Array.isArray(dataset.backgroundColor)
                                    ? dataset.backgroundColor[i]
                                    : dataset.backgroundColor;
                                return {
                                    text: `${label} (${value})`,
                                    fillStyle: fill,
                                    strokeStyle: '#fff',
                                    lineWidth: 2,
                                    hidden: isNaN(value) || chart.getDatasetMeta(0).data[i].hidden,
                                    index: i,
                                };
                            });
                        },
                    },
                };
                statusOptions.cutout = '55%';
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
