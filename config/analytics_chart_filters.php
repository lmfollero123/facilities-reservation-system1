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
        $monthKey = $prefix . '_month';
        $yearKey = $prefix . '_year';

        $facilityRaw = $_GET[$facKey] ?? ($defaultFacility ? (string)$defaultFacility : 'all');
        $facility = ($facilityRaw !== '' && $facilityRaw !== 'all') ? (int)$facilityRaw : null;

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

        $clause = '';
        $params = [];
        $start = null;
        $end = null;

        if ($year !== null && $month !== null) {
            $start = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
            $end = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
            $label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
            $clause = 'WHERE reservation_date >= :start AND reservation_date <= :end';
            $params = ['start' => $start, 'end' => $end];
        } else {
            $label = 'All Time';
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
        $month = $period['month'];
        $year = $period['year'];
        $facility = $period['facility'];

        ob_start();
        ?>
        <form method="get" class="chart-filter-bar" id="filter-<?= htmlspecialchars($chartId, ENT_QUOTES, 'UTF-8'); ?>">
            <?= frs_chart_hidden_preserve(array_merge($skipPrefixes, [$prefix])); ?>
            <div class="chart-filter-fields">
                <label class="chart-filter-item">
                    <span>Facility</span>
                    <select name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_facility" class="booking-form-control chart-filter-control">
                        <option value="all"<?= $facility === null ? ' selected' : ''; ?>>All Facilities</option>
                        <?php foreach ($facilities as $fac): ?>
                            <option value="<?= (int)$fac['id']; ?>"<?= $facility === (int)$fac['id'] ? ' selected' : ''; ?>>
                                <?= htmlspecialchars($fac['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="chart-filter-item">
                    <span>Month</span>
                    <select name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_month" class="booking-form-control chart-filter-control chart-filter-month" data-chart-prefix="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>">
                        <option value="all"<?= $month === null ? ' selected' : ''; ?>>All Time</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m; ?>"<?= $month === $m ? ' selected' : ''; ?>><?= date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label class="chart-filter-item">
                    <span>Year</span>
                    <select name="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_year" class="booking-form-control chart-filter-control chart-filter-year" data-chart-prefix="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>">
                        <option value="all"<?= $year === null ? ' selected' : ''; ?>>All Years</option>
                        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 2; $y--): ?>
                            <option value="<?= $y; ?>"<?= $year === $y ? ' selected' : ''; ?>><?= $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <button type="submit" class="btn-primary chart-filter-apply">Apply</button>
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
        <form method="get" class="chart-filter-bar" id="filter-occ">
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
                <button type="submit" class="btn-primary chart-filter-apply">Apply</button>
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
