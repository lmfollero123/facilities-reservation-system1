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

    function themeColor(varName, fallback) {
        const value = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
        return value || fallback;
    }

    function withAlpha(hex, alpha) {
        const rgb = parseColorToRgb(hex);
        if (!rgb) return hex;
        return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
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
            const trendColor = themeColor('--primary-color', '#0047ab');
            new Chart(monthlyCtx, {
                type: 'line',
                plugins: plugins.slice(),
                data: {
                    labels: cfg.monthlyLabels || [],
                    datasets: [{
                        label: 'Reservations',
                        data: cfg.monthlyData || [],
                        borderColor: trendColor,
                        backgroundColor: withAlpha(trendColor, 0.12),
                        tension: 0.35,
                        fill: true,
                        borderWidth: 2.5,
                        pointBackgroundColor: trendColor,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: Object.assign({
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: themeColor('--bg-secondary', '#ffffff'),
                            titleColor: themeColor('--text-primary', '#1e293b'),
                            bodyColor: themeColor('--text-secondary', '#475569'),
                            borderColor: themeColor('--border-color', '#e2e8f0'),
                            borderWidth: 1,
                            padding: 10,
                            cornerRadius: 8,
                            displayColors: false,
                        },
                    }, valueLabelOpts),
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
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12 },
                            color: themeColor('--text-secondary', '#475569'),
                        }
                    },
                    tooltip: {
                        backgroundColor: themeColor('--bg-secondary', '#ffffff'),
                        titleColor: themeColor('--text-primary', '#1e293b'),
                        bodyColor: themeColor('--text-secondary', '#475569'),
                        borderColor: themeColor('--border-color', '#e2e8f0'),
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                    },
                }
            };
            if (cfg.showValueLabels) {
                statusOptions.plugins.alwaysValueLabels = { fontSize: 13, fontWeight: '700' };
                statusOptions.plugins.legend = {
                    position: 'bottom',
                    labels: {
                        padding: 12,
                        font: { size: 12, weight: '600' },
                        color: themeColor('--text-secondary', '#475569'),
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
            const facilityColor = themeColor('--primary-color', '#0047ab');
            const facilityDataset = cfg.showValueLabels
                ? {
                    label: 'Approved Bookings',
                    data: cfg.facilityCounts || [],
                    backgroundColor: withAlpha(facilityColor, 0.85),
                    borderColor: facilityColor,
                    borderWidth: 1.5,
                    borderRadius: 6
                }
                : {
                    label: 'Bookings',
                    data: cfg.facilityCounts || [],
                    backgroundColor: facilityColor,
                    borderRadius: 6,
                    borderSkipped: false
                };

            const facilityOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: Object.assign({
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: themeColor('--bg-secondary', '#ffffff'),
                        titleColor: themeColor('--text-primary', '#1e293b'),
                        bodyColor: themeColor('--text-secondary', '#475569'),
                        borderColor: themeColor('--border-color', '#e2e8f0'),
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                    },
                }, valueLabelOpts),
                scales: {
                    y: {
                        beginAtZero: true,
                        // Headroom so the tallest bar's value label (drawn above the
                        // bar by alwaysValueLabelsPlugin) doesn't get clipped by the
                        // chart area's top edge or collide with a neighboring bar's
                        // label on narrow/mobile widths.
                        grace: cfg.showValueLabels ? '10%' : undefined,
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

    function initUtilizationChart(cfg) {
        if (!window.Chart || !cfg) return;
        const canvas = document.getElementById(cfg.canvasId || 'utilizationChart');
        if (!canvas) return;

        const baseColor = themeColor('--primary-color', '#0047ab');
        const highlightColor = themeColor('--warning-text', '#f59e0b');
        const labels = cfg.facilityLabels || [];
        const data = cfg.facilityCounts || [];
        const selectedId = cfg.selectedFacilityId || null;
        const facilityIds = cfg.facilityIds || [];

        const backgroundColor = labels.map(function (_, i) {
            const isSelected = selectedId && facilityIds[i] === selectedId;
            return isSelected ? withAlpha(highlightColor, 0.9) : withAlpha(baseColor, 0.75);
        });
        const borderColor = labels.map(function (_, i) {
            const isSelected = selectedId && facilityIds[i] === selectedId;
            return isSelected ? highlightColor : baseColor;
        });

        new Chart(canvas, {
            type: 'bar',
            plugins: [alwaysValueLabelsPlugin],
            data: {
                labels: labels,
                datasets: [{
                    label: 'Approved Bookings',
                    data: data,
                    backgroundColor: backgroundColor,
                    borderColor: borderColor,
                    borderWidth: 1.5,
                    borderRadius: 6,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    alwaysValueLabels: { fontSize: 12, fontWeight: '700', color: themeColor('--text-primary', '#1e293b') },
                    tooltip: {
                        backgroundColor: themeColor('--bg-secondary', '#ffffff'),
                        titleColor: themeColor('--text-primary', '#1e293b'),
                        bodyColor: themeColor('--text-secondary', '#475569'),
                        borderColor: themeColor('--border-color', '#e2e8f0'),
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grace: '10%',
                        ticks: { color: themeColor('--text-secondary', '#475569') },
                        grid: { color: gridColor() },
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: themeColor('--text-secondary', '#475569') },
                    }
                }
            }
        });
    }

    // ---- Shared "click a pin to filter" facility map (Reports + Dashboard) ----
    const frsFacilityMapConfigs = {};
    const frsFacilityMapInstances = {};
    const frsFacilityMapUserMarkers = {};

    function frsEscapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function frsFacilityMapNavigate(config, facilityId) {
        const url = new URL(window.location.href);
        (config.prefixes || []).forEach(function (prefix) {
            if (facilityId === null) {
                url.searchParams.delete(prefix + '_facility');
            } else {
                url.searchParams.set(prefix + '_facility', String(facilityId));
            }
        });
        if (window.frsPartialLoad) {
            window.frsPartialLoad(url.toString(), config.partialId);
        } else {
            window.location.href = url.toString();
        }
    }

    function initFrsFacilityFilterMap(mapId) {
        if (typeof L === 'undefined') return;
        const configEl = document.getElementById(mapId + '-config');
        const container = document.getElementById(mapId);
        if (!configEl || !container) return;

        let config;
        try {
            config = JSON.parse(configEl.textContent || '{}');
        } catch (err) {
            console.error('initFrsFacilityFilterMap: bad config', err);
            return;
        }
        frsFacilityMapConfigs[mapId] = config;

        // Barangay Culiat, Quezon City fallback center (used when no facility
        // has coordinates yet, so the card still renders instead of erroring).
        const defaultCenter = [14.6710, 121.0550];
        const map = L.map(mapId).setView(defaultCenter, 15);
        frsFacilityMapInstances[mapId] = map;
        delete frsFacilityMapUserMarkers[mapId]; // stale reference from before this re-init

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(map);

        const points = config.points || [];
        points.forEach(function (p) {
            const marker = L.marker([p.lat, p.lng]).addTo(map);
            marker.bindPopup('<strong>' + frsEscapeHtml(p.name) + '</strong>');
            marker.on('click', function () {
                frsFacilityMapNavigate(config, p.id);
            });
        });

        if (points.length > 0) {
            const bounds = L.latLngBounds(points.map(function (p) { return [p.lat, p.lng]; }));
            map.fitBounds(bounds, { padding: [24, 24], maxZoom: 16 });
        }

        // Leaflet mis-sizes its tiles when initialized inside a container
        // that was still settling layout (e.g. right after an AJAX partial
        // swap) - nudge it once the surrounding layout has painted.
        setTimeout(function () { map.invalidateSize(); }, 150);
    }

    function frsFacilityMapShowMyLocation(mapId, btn) {
        const map = frsFacilityMapInstances[mapId];
        if (!map) return;
        if (!navigator.geolocation) {
            alert('Your browser does not support location.');
            return;
        }
        const originalLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Locating…';
        navigator.geolocation.getCurrentPosition(
            function (position) {
                btn.disabled = false;
                btn.textContent = originalLabel;
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const accuracy = position.coords.accuracy || 0;

                const existing = frsFacilityMapUserMarkers[mapId];
                if (existing) {
                    existing.marker.remove();
                    existing.circle.remove();
                }
                const marker = L.circleMarker([lat, lng], {
                    radius: 8,
                    color: '#ffffff',
                    weight: 2,
                    fillColor: '#2563eb',
                    fillOpacity: 1,
                }).addTo(map).bindPopup('You are here');
                const circle = L.circle([lat, lng], {
                    radius: accuracy,
                    color: '#2563eb',
                    weight: 1,
                    fillColor: '#2563eb',
                    fillOpacity: 0.1,
                }).addTo(map);
                frsFacilityMapUserMarkers[mapId] = { marker: marker, circle: circle };

                map.setView([lat, lng], Math.max(map.getZoom(), 16));
                marker.openPopup();
            },
            function (err) {
                btn.disabled = false;
                btn.textContent = originalLabel;
                const messages = {
                    1: 'Location access was denied. Enable it in your browser settings to use this.',
                    2: 'Your location is unavailable right now.',
                    3: 'Getting your location timed out. Please try again.',
                };
                alert(messages[err.code] || 'Unable to get your location.');
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    }

    document.addEventListener('click', function (e) {
        const locateBtn = e.target.closest('[data-facility-map-locate]');
        if (locateBtn) {
            frsFacilityMapShowMyLocation(locateBtn.getAttribute('data-facility-map-locate'), locateBtn);
            return;
        }
        const resetBtn = e.target.closest('[data-facility-map-reset]');
        if (!resetBtn) return;
        const mapId = resetBtn.getAttribute('data-facility-map-reset');
        const config = frsFacilityMapConfigs[mapId];
        if (!config) return;
        frsFacilityMapNavigate(config, null);
    });

    window.frsInitReservationCharts = initReservationCharts;
    window.initUtilizationChart = initUtilizationChart;
    window.initFrsFacilityFilterMap = initFrsFacilityFilterMap;
})(window);
