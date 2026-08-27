<?php
/**
 * Per-chart / per-widget filter helpers for dashboard & reports analytics.
 */

if (!function_exists('frs_chart_filter_prefixes')) {
    /** @return list<string> */
    function frs_chart_filter_prefixes(): array
    {
        return [
            'trend', 'status', 'topfac', 'forecast', 'kpi', 'util', 'outcomes', 'occ',
            'cm', 'cs', 'cf',
        ];
    }
}

if (!function_exists('frs_chart_filter_is_param')) {
    function frs_chart_filter_is_param(string $key): bool
    {
        foreach (frs_chart_filter_prefixes() as $prefix) {
            if (str_starts_with($key, $prefix . '_')) {
                return true;
            }
        }
        return in_array($key, ['status', 'facility_id', 'start_date', 'end_date', 'facility', 'month', 'year'], true);
    }
}

if (!function_exists('frs_chart_hidden_preserve')) {
    /**
     * Hidden inputs for GET params to keep when submitting another chart's filter form.
     *
     * @param list<string> $skipPrefixes Prefixes to omit (e.g. ['trend'] when trend form submits)
     */
    function frs_chart_hidden_preserve(array $skipPrefixes = [], array $extraSkip = []): string
    {
        $skip = array_merge($extraSkip, [
            'print', 'export', 'ai_summary', 'live_occupancy',
        ]);
        $html = '';
        foreach ($_GET as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $matched = false;
            foreach ($skipPrefixes as $prefix) {
                if (str_starts_with($key, $prefix . '_')) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            // data-frs-preserve marks these as no-JS fallback state: the AJAX
            // partial layer rebuilds cross-widget state from the live URL
            // instead (these values are a snapshot from render time).
            $html .= '<input type="hidden" data-frs-preserve="1" name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" value="'
                . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
        }
        return $html;
    }
}

if (!function_exists('frs_parse_reports_period')) {
    /**
     * @return array{
     *   facility: int|null,
     *   year: int|null,
     *   month: int|null,
     *   start: string|null,
     *   end: string|null,
     *   label: string,
     *   clause: string,
     *   params: array<string, mixed>
     * }
     */
    function frs_parse_reports_period(string $prefix, ?int $defaultYear, ?int $defaultMonth, ?int $defaultFacility): array
    {
        $facKey = $prefix . '_facility';
        $startKey = $prefix . '_start';
        $endKey = $prefix . '_end';
        $monthKey = $prefix . '_month';
        $yearKey = $prefix . '_year';

        $facilityRaw = $_GET[$facKey] ?? ($defaultFacility ? (string)$defaultFacility : 'all');
        $facility = ($facilityRaw !== '' && $facilityRaw !== 'all') ? (int)$facilityRaw : null;

        $isValidDate = static function (string $value): bool {
            if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return false;
            }
            return strtotime($value) !== false;
        };

        $startRaw = isset($_GET[$startKey]) ? trim((string)$_GET[$startKey]) : '';
        $endRaw = isset($_GET[$endKey]) ? trim((string)$_GET[$endKey]) : '';

        $year = null;
        $month = null;
        $start = null;
        $end = null;
        $label = 'All Time';

        if (isset($_GET[$startKey]) || isset($_GET[$endKey])) {
            // The Date From/To form was explicitly submitted (possibly with one
            // or both fields cleared for "All Time") - it wins outright over
            // any month/year params, and does NOT fall back to the default
            // current-month view the way a bare fresh page load does below.
            if ($isValidDate($startRaw) && $isValidDate($endRaw)) {
                $start = $startRaw;
                $end = $endRaw;
                if (strtotime($start) > strtotime($end)) {
                    [$start, $end] = [$end, $start];
                }
                $startLabel = date('M j, Y', strtotime($start));
                $endLabel = date('M j, Y', strtotime($end));
                $label = $startLabel === $endLabel ? $startLabel : "{$startLabel} – {$endLabel}";
            }
        } elseif (isset($_GET[$monthKey]) || isset($_GET[$yearKey])) {
            // Back-compat for old bookmarked/shared URLs still using month+year.
            $monthRaw = $_GET[$monthKey] ?? ($defaultMonth === null ? 'all' : (string)$defaultMonth);
            $yearRaw = $_GET[$yearKey] ?? ($defaultYear === null ? 'all' : (string)$defaultYear);
            $year = ($yearRaw === 'all' || $yearRaw === '') ? null : (int)$yearRaw;
            $month = ($monthRaw === 'all' || $monthRaw === '') ? null : (int)$monthRaw;
            if ($monthRaw === 'all' && $yearRaw !== 'all' && $yearRaw !== '') {
                $month = null;
            }
            if ($yearRaw === 'all' && $monthRaw !== 'all' && $monthRaw !== '') {
                $year = null;
            }
            if ($year !== null && $month !== null) {
                $start = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
                $end = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
                $label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
            }
        } elseif ($defaultYear !== null && $defaultMonth !== null) {
            // Fresh page load, no filter params yet: default to the current month.
            $year = $defaultYear;
            $month = $defaultMonth;
            $start = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
            $end = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
            $label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
        }

        $clause = '';
        $params = [];
        if ($start !== null && $end !== null) {
            $clause = 'WHERE reservation_date >= :start AND reservation_date <= :end';
            $params = ['start' => $start, 'end' => $end];
        }

        if ($facility) {
            if ($clause) {
                $clause .= ' AND facility_id = :facility_id';
            } else {
                $clause = 'WHERE facility_id = :facility_id';
            }
            $params['facility_id'] = $facility;
        }

        return [
            'facility' => $facility,
            'year' => $year,
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'label' => $label,
            'clause' => $clause,
            'params' => $params,
        ];
    }
}

if (!function_exists('frs_reports_bucket_series')) {
    /**
     * Adaptively bucket a resolved report period into a label/count series for
     * trend-style charts, instead of assuming the period is a whole month.
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    function frs_reports_bucket_series(PDO $pdo, ?string $start, ?string $end, ?int $facility, int $targetPoints = 6): array
    {
        if ($start === null || $end === null) {
            // All Time: fall back to a trailing 12-month view so there's still
            // a meaningful trend/forecast basis.
            $end = date('Y-m-d');
            $start = date('Y-m-01', strtotime('-11 months'));
        }

        $startTs = strtotime($start);
        $endTs = strtotime($end);
        $days = max(1, (int)round(($endTs - $startTs) / 86400) + 1);

        if ($days <= 31) {
            $unit = 'day';
            $stepDays = 1;
        } elseif ($days <= 180) {
            $unit = 'week';
            $stepDays = 7;
        } else {
            $unit = 'month';
            $stepDays = 30;
        }

        $labels = [];
        $data = [];

        if ($unit === 'month') {
            $cursor = strtotime(date('Y-m-01', $startTs));
            $endMonth = strtotime(date('Y-m-01', $endTs));
            while ($cursor <= $endMonth) {
                $bucketStart = date('Y-m-01', $cursor);
                $bucketEnd = date('Y-m-t', $cursor);
                $labels[] = date('M Y', $cursor);
                $data[] = frs_reports_count_in_range($pdo, max($bucketStart, $start), min($bucketEnd, $end), $facility);
                $cursor = strtotime('+1 month', $cursor);
            }
        } else {
            $cursor = $startTs;
            while ($cursor <= $endTs) {
                $bucketEndTs = min($endTs, strtotime("+" . ($stepDays - 1) . " days", $cursor));
                $bucketStart = date('Y-m-d', $cursor);
                $bucketEnd = date('Y-m-d', $bucketEndTs);
                $labels[] = $unit === 'day'
                    ? date('M j', $cursor)
                    : (date('M j', $cursor) . '–' . date('j', $bucketEndTs));
                $data[] = frs_reports_count_in_range($pdo, $bucketStart, $bucketEnd, $facility);
                $cursor = strtotime("+{$stepDays} days", $cursor);
            }
        }

        // Guard against a pathologically fine-grained range producing an
        // unreadable number of points (shouldn't happen given the thresholds
        // above, but keeps the chart sane if called with unusual inputs).
        if (count($labels) > 60) {
            $labels = array_slice($labels, -60);
            $data = array_slice($data, -60);
        }

        return ['labels' => $labels, 'data' => $data, 'unit' => $unit, 'end' => $end];
    }
}

if (!function_exists('frs_reports_forecast_labels')) {
    /**
     * Labels for the N periods after a bucketed series, in the same unit
     * (day/week/month) so a short custom range doesn't get "3 months ahead"
     * forecast labels for a 3-point daily series.
     *
     * @return list<string>
     */
    function frs_reports_forecast_labels(string $lastBucketEnd, string $unit, int $periods = 3): array
    {
        $labels = [];
        $cursor = strtotime($lastBucketEnd);
        for ($i = 1; $i <= $periods; $i++) {
            if ($unit === 'day') {
                $cursor = strtotime('+1 day', $cursor);
                $labels[] = date('M j', $cursor);
            } elseif ($unit === 'week') {
                $cursor = strtotime('+7 days', $cursor);
                $labels[] = date('M j', $cursor);
            } else {
                $cursor = strtotime('+1 month', $cursor);
                $labels[] = date('M Y', $cursor);
            }
        }
        return $labels;
    }
}

if (!function_exists('frs_reports_count_in_range')) {
    function frs_reports_count_in_range(PDO $pdo, string $start, string $end, ?int $facility): int
    {
        $sql = 'SELECT COUNT(*) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end';
        $params = ['start' => $start, 'end' => $end];
        if ($facility) {
            $sql .= ' AND facility_id = :facility_id';
            $params['facility_id'] = $facility;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('frs_reports_period_filter_form')) {
    /**
     * @param list<array{id:string,label:string}> $facilities
     */
    function frs_reports_period_filter_form(
        string $chartId,
        string $prefix,
        array $facilities,
        array $period,
        array $skipPrefixes = []
    ): string {
        $facility = $period['facility'];
        $start = $period['start'];
        $end = $period['end'];
        $safePrefix = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');

        $presets = [
            'today' => 'Today',
            '7d' => 'Last 7 Days',
            'month' => 'This Month',
            '30d' => 'Last 30 Days',
            'year' => 'This Year',
            'all' => 'All Time',
        ];

        ob_start();
        ?>
        <form method="get" class="chart-filter-bar" id="filter-<?= htmlspecialchars($chartId, ENT_QUOTES, 'UTF-8'); ?>" data-frs-partial="reports-content" data-frs-partial-auto>
            <?= frs_chart_hidden_preserve(array_merge($skipPrefixes, [$prefix])); ?>
            <div class="chart-filter-fields">
                <label class="chart-filter-item">
                    <span>Facility</span>
                    <select name="<?= $safePrefix; ?>_facility" class="booking-form-control chart-filter-control">
                        <option value="all"<?= $facility === null ? ' selected' : ''; ?>>All Facilities</option>
                        <?php foreach ($facilities as $fac): ?>
                            <option value="<?= (int)$fac['id']; ?>"<?= $facility === (int)$fac['id'] ? ' selected' : ''; ?>>
                                <?= htmlspecialchars($fac['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="chart-filter-item">
                    <span>Date From</span>
                    <input type="date" name="<?= $safePrefix; ?>_start" class="booking-form-control chart-filter-control" value="<?= htmlspecialchars((string)$start, ENT_QUOTES, 'UTF-8'); ?>" data-chart-range-start="<?= $safePrefix; ?>">
                </label>
                <label class="chart-filter-item">
                    <span>Date To</span>
                    <input type="date" name="<?= $safePrefix; ?>_end" class="booking-form-control chart-filter-control" value="<?= htmlspecialchars((string)$end, ENT_QUOTES, 'UTF-8'); ?>" data-chart-range-end="<?= $safePrefix; ?>">
                </label>
                <noscript><button type="submit" class="btn-primary chart-filter-apply">Apply</button></noscript>
            </div>
            <div class="chart-filter-presets" data-chart-presets="<?= $safePrefix; ?>">
                <?php foreach ($presets as $key => $presetLabel): ?>
                    <button type="button" class="chart-filter-preset" data-preset="<?= $key; ?>"><?= htmlspecialchars($presetLabel); ?></button>
                <?php endforeach; ?>
            </div>
            <small class="chart-filter-active">Showing: <?= htmlspecialchars($period['label']); ?></small>
        </form>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('frs_parse_dashboard_chart_filter')) {
    /**
     * @return array{
     *   status: string,
     *   facility: int,
     *   start: string,
     *   end: string,
     *   months: int
     * }
     */
    function frs_parse_dashboard_chart_filter(string $prefix): array
    {
        // Use lookup values for allowed statuses
        $allowedStatuses = [];
        if (frs_lookups_table_ready(db())) {
            foreach (frs_lookup_values(db(), 'reservation_status') as $status) {
                $allowedStatuses[] = $status['slug'];
            }
        } else {
            // Fallback to hardcoded statuses
            $allowedStatuses = ['approved', 'pending', 'denied', 'cancelled'];
        }

        $status = '';
        $statusKey = $prefix . '_status';
        if (isset($_GET[$statusKey]) && in_array(strtolower((string)$_GET[$statusKey]), $allowedStatuses, true)) {
            $status = strtolower((string)$_GET[$statusKey]);
        }

        $facility = 0;
        $facilityKey = $prefix . '_facility';
        if (isset($_GET[$facilityKey]) && ctype_digit((string)$_GET[$facilityKey])) {
            $facility = (int)$_GET[$facilityKey];
        }

        $start = '';
        $startKey = $prefix . '_start';
        if (!empty($_GET[$startKey]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET[$startKey])) {
            $start = (string)$_GET[$startKey];
        }

        $end = '';
        $endKey = $prefix . '_end';
        if (!empty($_GET[$endKey]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET[$endKey])) {
            $end = (string)$_GET[$endKey];
        }

        $months = 6;
        $monthsKey = $prefix . '_months';
        if (isset($_GET[$monthsKey]) && in_array((int)$_GET[$monthsKey], [6, 12], true)) {
            $months = (int)$_GET[$monthsKey];
        }

        $limit = 5;
        $limitKey = $prefix . '_limit';
        if (isset($_GET[$limitKey]) && in_array((int)$_GET[$limitKey], [5, 10, 15], true)) {
            $limit = (int)$_GET[$limitKey];
        }

        return [
            'status' => $status,
            'facility' => $facility,
            'start' => $start,
            'end' => $end,
            'months' => $months,
            'limit' => $limit,
        ];
    }
}

if (!function_exists('frs_dashboard_chart_filter_label')) {
    function frs_dashboard_chart_filter_label(array $f): string
    {
        $parts = [];
        if ($f['status'] !== '') {
            $parts[] = ucfirst($f['status']);
        }
        if ($f['facility'] > 0) {
            $parts[] = 'Facility #' . $f['facility'];
        }
        if ($f['start'] !== '' || $f['end'] !== '') {
            $parts[] = trim(($f['start'] ?: '…') . ' – ' . ($f['end'] ?: '…'));
        }
        if (isset($f['months'])) {
            $parts[] = 'Last ' . $f['months'] . ' months';
        }
        return $parts ? implode(' · ', $parts) : 'All data';
    }
}

if (!function_exists('frs_dashboard_chart_filter_form')) {
    /**
     * @param list<array{id:int|string,name:string}> $facilities
     */
    function frs_dashboard_chart_filter_form(
        string $chartId,
        string $prefix,
        array $facilities,
        array $filter,
        bool $showMonths = false,
        bool $showLimit = false,
        array $skipPrefixes = [],
        ?string $partialId = null
    ): string {
        ob_start();
        $partialAttr = $partialId !== null && $partialId !== ''
            ? ' data-frs-partial="' . htmlspecialchars($partialId, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        ?>
        <form method="get" class="chart-filter-bar" id="filter-<?= htmlspecialchars($chartId, ENT_QUOTES, 'UTF-8'); ?>"<?= $partialAttr; ?>>
            <?= frs_chart_hidden_preserve(array_merge($skipPrefixes, [$prefix])); ?>
            <div class="chart-filter-fields">
                <label class="chart-filter-item">
                    <span>Status</span>
                    <select name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_status" class="booking-form-control chart-filter-control">
                        <option value="">All</option>
                        <?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'denied' => 'Denied', 'cancelled' => 'Cancelled'] as $key => $label): ?>
                            <option value="<?= $key; ?>"<?= $filter['status'] === $key ? ' selected' : ''; ?>><?= $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="chart-filter-item">
                    <span>Facility</span>
                    <select name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_facility" class="booking-form-control chart-filter-control">
                        <option value="0">All</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= (int)$facility['id']; ?>"<?= $filter['facility'] === (int)$facility['id'] ? ' selected' : ''; ?>>
                                <?= htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="chart-filter-item">
                    <span>From</span>
                    <input type="date" name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_start" class="booking-form-control chart-filter-control" value="<?= htmlspecialchars($filter['start']); ?>">
                </label>
                <label class="chart-filter-item">
                    <span>To</span>
                    <input type="date" name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_end" class="booking-form-control chart-filter-control" value="<?= htmlspecialchars($filter['end']); ?>">
                </label>
                <?php if ($showMonths): ?>
                <label class="chart-filter-item">
                    <span>Range</span>
                    <select name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_months" class="booking-form-control chart-filter-control">
                        <option value="6"<?= (int)$filter['months'] === 6 ? ' selected' : ''; ?>>Last 6 months</option>
                        <option value="12"<?= (int)$filter['months'] === 12 ? ' selected' : ''; ?>>Last 12 months</option>
                    </select>
                </label>
                <?php endif; ?>
                <?php if ($showLimit): ?>
                <label class="chart-filter-item">
                    <span>Top</span>
                    <select name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_limit" class="booking-form-control chart-filter-control">
                        <?php foreach ([5, 10, 15] as $n): ?>
                            <option value="<?= $n; ?>"<?= (int)($filter['limit'] ?? 5) === $n ? ' selected' : ''; ?>><?= $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <button type="submit" class="btn-primary chart-filter-apply">Apply</button>
            </div>
            <small class="chart-filter-active">Showing: <?= htmlspecialchars(frs_dashboard_chart_filter_label($filter)); ?></small>
        </form>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('frs_reports_occ_filter_form')) {
    /**
     * @param list<array{id:int|string,name:string}> $facilities
     */
    function frs_reports_occ_filter_form(array $facilities, ?int $facilityId, array $skipPrefixes = []): string
    {
        ob_start();
        ?>
        <form method="get" class="chart-filter-bar" id="filter-occ" data-frs-partial="reports-content" data-frs-partial-auto>
            <?= frs_chart_hidden_preserve(array_merge($skipPrefixes, ['occ'])); ?>
            <div class="chart-filter-fields">
                <label class="chart-filter-item">
                    <span>Facility</span>
                    <select name="occ_facility" class="booking-form-control chart-filter-control">
                        <option value="all"<?= $facilityId === null ? ' selected' : ''; ?>>All Facilities</option>
                        <?php foreach ($facilities as $fac): ?>
                            <option value="<?= (int)$fac['id']; ?>"<?= $facilityId === (int)$fac['id'] ? ' selected' : ''; ?>>
                                <?= htmlspecialchars($fac['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button type="submit" class="btn-primary chart-filter-apply">Apply</button></noscript>
            </div>
            <small class="chart-filter-active">
                Showing: <?= $facilityId ? 'Selected facility' : 'All facilities'; ?> (live snapshot)
            </small>
        </form>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('frs_dashboard_apply_chart_sql_filters')) {
    /**
     * @param array<string, mixed> $params
     * @param list<string> $conditions
     */
    function frs_dashboard_apply_chart_sql_filters(
        array $filter,
        array &$conditions,
        array &$params,
        string $statusParam = 'chart_status',
        string $facilityParam = 'chart_facility',
        string $startParam = 'chart_start',
        string $endParam = 'chart_end',
        string $dateColumn = 'reservation_date'
    ): void {
        if ($filter['status'] !== '') {
            $conditions[] = "LOWER(status) = :{$statusParam}";
            $params[$statusParam] = $filter['status'];
        }
        if ($filter['facility'] > 0) {
            $conditions[] = "facility_id = :{$facilityParam}";
            $params[$facilityParam] = $filter['facility'];
        }
        if ($filter['start'] !== '') {
            $conditions[] = "{$dateColumn} >= :{$startParam}";
            $params[$startParam] = $filter['start'];
        }
        if ($filter['end'] !== '') {
            $conditions[] = "{$dateColumn} <= :{$endParam}";
            $params[$endParam] = $filter['end'];
        }
    }
}

if (!function_exists('frs_reports_export_href')) {
    /**
     * Build export URL preserving Overview KPIs (kpi_*) filter query params.
     */
    function frs_reports_export_href(string $type, string $prefix = 'kpi'): string
    {
        $query = ['export' => $type];
        foreach ($_GET as $key => $value) {
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            if (str_starts_with($key, $prefix . '_')) {
                $query[$key] = (string)$value;
            }
        }
        return '?' . http_build_query($query);
    }
}

if (!function_exists('frs_facility_filter_map')) {
    /**
     * Shared "click a pin to filter" facility map, reused by the Reports page
     * (7 chart prefixes) and the main Dashboard (3 chart prefixes) - one map
     * implementation, driven entirely by data-frs-partial (no new endpoint).
     *
     * @param list<array{id:int|string,name:string,lat:float|string|null,lng:float|string|null}> $facilities
     * @param list<string> $prefixes GET-param prefixes to set on click, e.g. ['trend','status','topfac']
     */
    function frs_facility_filter_map(string $mapId, array $facilities, array $prefixes, string $partialId): string
    {
        $points = [];
        foreach ($facilities as $fac) {
            if ($fac['lat'] === null || $fac['lng'] === null || $fac['lat'] === '' || $fac['lng'] === '') {
                continue;
            }
            $points[] = [
                'id' => (int)$fac['id'],
                'name' => (string)$fac['name'],
                'lat' => (float)$fac['lat'],
                'lng' => (float)$fac['lng'],
            ];
        }

        $config = [
            'mapId' => $mapId,
            'points' => $points,
            'prefixes' => array_values($prefixes),
            'partialId' => $partialId,
        ];

        ob_start();
        ?>
        <div class="facility-map-card">
            <div class="facility-map-card__head">
                <span class="facility-map-card__label">Click a facility pin to filter <?= count($prefixes) > 1 ? 'every chart' : 'the chart'; ?> below</span>
                <button type="button" class="chart-filter-preset facility-map-card__reset" data-facility-map-reset="<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8'); ?>">All Facilities</button>
            </div>
            <div id="<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8'); ?>" class="facility-map-canvas"></div>
        </div>
        <script type="application/json" id="<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8'); ?>-config"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
        <script>
        if (window.initFrsFacilityFilterMap) {
            window.initFrsFacilityFilterMap('<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8'); ?>');
        }
        </script>
        <?php
        return (string)ob_get_clean();
    }
}
