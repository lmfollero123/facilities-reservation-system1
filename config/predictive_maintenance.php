<?php
/**
 * Predictive maintenance insights and CPRF → CIMM request tracking.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/app_settings.php';

/**
 * Ensure local table for outbound maintenance requests exists.
 */
function frs_ensure_cprf_maintenance_requests_table(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === true) {
        return true;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cprf_maintenance_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                facility_id INT UNSIGNED NOT NULL,
                facility_name VARCHAR(255) NOT NULL DEFAULT "",
                requested_date DATE NOT NULL,
                suggested_end_date DATE NULL,
                priority VARCHAR(20) NOT NULL DEFAULT "medium",
                risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                risk_band VARCHAR(20) NOT NULL DEFAULT "Low",
                notes TEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "pending",
                cimm_reference VARCHAR(64) NULL,
                requested_by INT UNSIGNED NULL,
                error_message VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cprf_maint_req_facility (facility_id),
                INDEX idx_cprf_maint_req_status (status),
                INDEX idx_cprf_maint_req_date (requested_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ready = true;
        return true;
    } catch (Throwable $e) {
        error_log('cprf_maintenance_requests table ensure failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Continuous-learning feedback table: honest version, not a retrained ML
 * model. Every time a MANUAL/emergency report lands on a facility - an
 * incident the usage-based score didn't anticipate - that's a real signal
 * the model under-weighted this specific facility. Each occurrence nudges
 * its future score up slightly (capped), so the system adapts to actual
 * maintenance outcomes over time instead of using a fixed formula forever.
 */
function frs_ensure_facility_risk_adjustments_table(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === true) {
        return true;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS facility_risk_adjustments (
                facility_id INT UNSIGNED NOT NULL PRIMARY KEY,
                adjustment_points TINYINT UNSIGNED NOT NULL DEFAULT 0,
                manual_report_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_adjusted_at DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ready = true;
        return true;
    } catch (Throwable $e) {
        error_log('facility_risk_adjustments table ensure failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Records a real-world outcome (a manual report the model didn't predict)
 * and bumps that facility's learned adjustment. Capped at +15 so a single
 * facility's history can't dominate the score the way usage pressure does.
 */
function frs_record_manual_report_outcome(PDO $pdo, int $facilityId): void
{
    if (!frs_ensure_facility_risk_adjustments_table($pdo) || $facilityId <= 0) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO facility_risk_adjustments (facility_id, adjustment_points, manual_report_count, last_adjusted_at)
         VALUES (:facility_id, 5, 1, NOW())
         ON DUPLICATE KEY UPDATE
            manual_report_count = manual_report_count + 1,
            adjustment_points = LEAST(15, adjustment_points + 5),
            last_adjusted_at = NOW()'
    )->execute(['facility_id' => $facilityId]);
}

/**
 * @return array<int, int> facility_id => adjustment_points
 */
function frs_get_facility_risk_adjustments(PDO $pdo): array
{
    if (!frs_ensure_facility_risk_adjustments_table($pdo)) {
        return [];
    }
    $rows = $pdo->query('SELECT facility_id, adjustment_points FROM facility_risk_adjustments')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map('intval', $rows);
}

/**
 * Plain-language summary of a facility's maintenance pressure - panelist
 * feedback was that a raw "35/100" number is hard to act on. This is now
 * the primary thing shown on each card; the numeric breakdown stays
 * available underneath for anyone who wants the specifics.
 */
function frs_describe_maintenance_pressure(
    string $riskBand,
    int $growthPressure,
    int $seasonalPressure,
    int $outcomeAdjustment,
    int $statusPressure
): string {
    $base = match ($riskBand) {
        'High' => 'Heavily used — real wear risk, due for a check',
        'Medium' => 'Moderately used — worth a routine check soon',
        default => 'Lightly used — low wear risk right now',
    };

    $qualifiers = [];
    if ($statusPressure > 0) {
        $qualifiers[] = 'currently flagged under maintenance';
    }
    if ($growthPressure >= 10) {
        $qualifiers[] = 'bookings have picked up recently';
    }
    if ($seasonalPressure >= 5) {
        $qualifiers[] = 'this is typically a busier month';
    } elseif ($seasonalPressure <= -5) {
        $qualifiers[] = 'this is typically a quieter month';
    }
    if ($outcomeAdjustment > 0) {
        $qualifiers[] = "it's had real issues before that usage alone didn't predict";
    }

    return $qualifiers === [] ? "{$base}." : "{$base} — " . implode('; ', $qualifiers) . '.';
}

/**
 * Rule-based facility maintenance risk rows from booking pressure.
 *
 * @return array<int, array<string, mixed>>
 */
function frs_compute_predictive_maintenance_rows(PDO $pdo): array
{
    $rows = [];

    try {
        $facilityUsageStmt = $pdo->query(
            "SELECT
                f.id,
                f.name,
                f.location,
                f.status,
                f.image_path,
                SUM(CASE WHEN r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS usage_90d,
                SUM(CASE WHEN r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS usage_30d
             FROM facilities f
             LEFT JOIN reservations r ON r.facility_id = f.id AND r.status IN ('approved','pending','pending_payment')
             GROUP BY f.id, f.name, f.location, f.status, f.image_path
             ORDER BY f.name ASC"
        );
        $facilityUsage = $facilityUsageStmt ? $facilityUsageStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $dowStmt = $pdo->query(
            "SELECT DAYOFWEEK(reservation_date) AS dow, COUNT(*) AS cnt
             FROM reservations
             WHERE reservation_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               AND status IN ('approved','pending','pending_payment')
             GROUP BY DAYOFWEEK(reservation_date)"
        );
        $dowCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
        if ($dowStmt) {
            foreach ($dowStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dow = (int)($row['dow'] ?? 0);
                if (isset($dowCounts[$dow])) {
                    $dowCounts[$dow] = (int)($row['cnt'] ?? 0);
                }
            }
        }
        asort($dowCounts);
        $leastBusyDow = (int)array_key_first($dowCounts);
        $dowNames = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
        $leastBusyName = $dowNames[$leastBusyDow] ?? 'Sunday';

        // Seasonal trend: is the current calendar month historically busier or
        // quieter than an average month, across all years of booking history?
        // System-wide (not per-facility - most facilities don't have enough
        // individual history yet for a per-facility month-over-month signal).
        $monthlyStmt = $pdo->query(
            "SELECT MONTH(reservation_date) AS m, COUNT(*) AS cnt
             FROM reservations
             WHERE status IN ('approved','pending','pending_payment')
             GROUP BY MONTH(reservation_date)"
        );
        $monthlyTotals = $monthlyStmt ? $monthlyStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        $seasonalIndex = 1.0;
        $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
        $currentMonthName = $monthNames[(int)date('n')] ?? '';
        if (count($monthlyTotals) >= 3) {
            $overallAvg = array_sum($monthlyTotals) / count($monthlyTotals);
            $currentMonthTotal = (int)($monthlyTotals[(int)date('n')] ?? 0);
            if ($overallAvg > 0) {
                $seasonalIndex = $currentMonthTotal / $overallAvg;
            }
        }
        // Small nudge, not a dominant factor - capped at +/-10 points either way.
        $seasonalPressure = (int)round(min(10, max(-10, ($seasonalIndex - 1) * 20)));

        $outcomeAdjustments = frs_get_facility_risk_adjustments($pdo);

        $pendingRequestKeys = [];
        if (frs_ensure_cprf_maintenance_requests_table($pdo)) {
            $pendingStmt = $pdo->query(
                "SELECT facility_id, requested_date
                 FROM cprf_maintenance_requests
                 WHERE status IN ('pending', 'sent', 'acknowledged')"
            );
            if ($pendingStmt) {
                foreach ($pendingStmt->fetchAll(PDO::FETCH_ASSOC) as $pending) {
                    $pendingRequestKeys[(int)$pending['facility_id'] . '|' . (string)$pending['requested_date']] = true;
                }
            }
        }

        foreach ($facilityUsage as $fRow) {
            $facilityId = (int)($fRow['id'] ?? 0);
            $usage90 = (int)($fRow['usage_90d'] ?? 0);
            $usage30 = (int)($fRow['usage_30d'] ?? 0);
            $status = strtolower((string)($fRow['status'] ?? 'available'));

            $usagePressure = min(60, (int)round($usage90 * 1.2));
            $growthPressure = min(25, max(0, ($usage30 - (int)round($usage90 / 3))) * 2);
            $statusPressure = ($status === 'maintenance') ? 15 : 0;
            $outcomeAdjustment = $outcomeAdjustments[$facilityId] ?? 0;
            $riskScore = max(0, min(100, $usagePressure + $growthPressure + $statusPressure + $seasonalPressure + $outcomeAdjustment));
            $recentPace = (int)round($usage90 / 3);

            if ($riskScore >= 75) {
                $riskBand = 'High';
                $riskColor = '#ef4444';
                $riskBg = 'rgba(239,68,68,0.12)';
                $priority = 'high';
            } elseif ($riskScore >= 45) {
                $riskBand = 'Medium';
                $riskColor = '#f59e0b';
                $riskBg = 'rgba(245,158,11,0.14)';
                $priority = 'medium';
            } else {
                $riskBand = 'Low';
                $riskColor = '#22c55e';
                $riskBg = 'rgba(34,197,94,0.12)';
                $priority = 'low';
            }

            $recommendedDate = null;
            for ($i = 1; $i <= 14; $i++) {
                $candidate = new DateTime('+' . $i . ' day');
                $phpDow = (int)$candidate->format('w');
                $mysqlDow = $phpDow === 0 ? 1 : $phpDow + 1;
                if ($mysqlDow === $leastBusyDow) {
                    $recommendedDate = $candidate->format('Y-m-d');
                    break;
                }
            }

            $requestKey = $facilityId . '|' . (string)$recommendedDate;
            $hasPendingRequest = $recommendedDate && isset($pendingRequestKeys[$requestKey]);

            $rows[] = [
                'facility_id' => $facilityId,
                'facility_name' => (string)($fRow['name'] ?? 'Facility'),
                'location' => (string)($fRow['location'] ?? ''),
                'image_path' => (string)($fRow['image_path'] ?? ''),
                'status' => ucfirst($status),
                'usage_90d' => $usage90,
                'usage_30d' => $usage30,
                'risk_score' => $riskScore,
                'risk_band' => $riskBand,
                'risk_color' => $riskColor,
                'risk_bg' => $riskBg,
                'usage_pressure' => $usagePressure,
                'growth_pressure' => $growthPressure,
                'status_pressure' => $statusPressure,
                'seasonal_pressure' => $seasonalPressure,
                'seasonal_index' => round($seasonalIndex, 2),
                'current_month_name' => $currentMonthName,
                'outcome_adjustment' => $outcomeAdjustment,
                'pressure_description' => frs_describe_maintenance_pressure($riskBand, $growthPressure, $seasonalPressure, $outcomeAdjustment, $statusPressure),
                'recent_pace_30d' => $recentPace,
                'priority' => $priority,
                'recommended_date' => $recommendedDate,
                'recommended_window_label' => $recommendedDate
                    ? (date('M d, Y', strtotime($recommendedDate)) . ' (' . $leastBusyName . ')')
                    : ('Next ' . $leastBusyName),
                'least_busy_day' => $leastBusyName,
                'has_pending_request' => $hasPendingRequest,
                'show_request_action' => $riskScore >= 45,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return (int)$b['risk_score'] <=> (int)$a['risk_score'];
        });
    } catch (Throwable $e) {
        error_log('Predictive maintenance compute error: ' . $e->getMessage());
        return [];
    }

    return $rows;
}

/**
 * Opt-in, defaults OFF - staff must explicitly turn this on (Maintenance
 * Insights toolbar). Nothing auto-submits to CIMM unless enabled.
 */
function frs_auto_schedule_enabled(PDO $pdo): bool
{
    return (frs_get_app_settings_map($pdo)['predictive_maintenance_auto_schedule'] ?? '0') === '1';
}

function frs_set_auto_schedule_enabled(PDO $pdo, bool $enabled, ?int $userId = null): void
{
    frs_set_app_setting($pdo, 'predictive_maintenance_auto_schedule', $enabled ? '1' : '0', $userId);
}

/**
 * Auto-submits a maintenance request for every High-risk, actionable
 * facility that doesn't already have one pending - only runs when
 * frs_auto_schedule_enabled() is true. Bounded to High risk (not Medium/Low)
 * so this can't quietly flood CIMM; the existing per-facility/date duplicate
 * guard in frs_submit_maintenance_request() means calling this repeatedly
 * (e.g. once per Insights page load) is safe and won't double-submit.
 *
 * @param array<int, array<string, mixed>> $rows from frs_compute_predictive_maintenance_rows()
 * @return array<int, array{facility_name: string, success: bool, error?: string}>
 */
function frs_auto_schedule_high_risk_requests(PDO $pdo, array $rows): array
{
    $scheduled = [];
    foreach ($rows as $row) {
        if (($row['risk_band'] ?? '') !== 'High'
            || empty($row['show_request_action'])
            || !empty($row['has_pending_request'])
            || empty($row['recommended_date'])
        ) {
            continue;
        }

        $result = frs_submit_maintenance_request($pdo, [
            'facility_id' => $row['facility_id'],
            'facility_name' => $row['facility_name'],
            'location' => $row['location'] ?? '',
            'requested_date' => $row['recommended_date'],
            'priority' => $row['priority'] ?? 'high',
            'risk_score' => $row['risk_score'],
            'risk_band' => $row['risk_band'],
            'request_source' => 'auto',
        ], 0);

        $scheduled[] = [
            'facility_name' => (string)$row['facility_name'],
            'success' => !empty($result['success']),
            'error' => $result['error'] ?? null,
        ];
    }
    return $scheduled;
}

/**
 * Adds columns introduced after the original table was created. Safe to call
 * every time - each ADD COLUMN is wrapped individually so an existing
 * column (already-migrated database) just no-ops instead of failing.
 */
function frs_ensure_maintenance_requests_schema_v2(PDO $pdo): void
{
    $addCol = function (string $definition) use ($pdo): void {
        try {
            $pdo->exec("ALTER TABLE cprf_maintenance_requests ADD COLUMN $definition");
        } catch (Throwable $e) {
            // column already exists: noop
        }
    };
    $addCol("`assigned_staff_id` INT UNSIGNED NULL AFTER `requested_by`");
    $addCol("`assigned_staff_name` VARCHAR(255) NULL AFTER `assigned_staff_id`");
    $addCol("`photo_path` VARCHAR(255) NULL AFTER `notes`");
}

/**
 * Saves one uploaded photo (single-file $_FILES entry, e.g. $_FILES['photo'])
 * for a manual maintenance report. Returns the public-facing URL on success,
 * or null if no file was submitted. Throws on a genuinely invalid upload
 * (wrong type, too large) so the caller can surface that to the user.
 */
function frs_save_maintenance_report_photo(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    require_once __DIR__ . '/upload_helper.php';
    $errors = validateFileUpload($file, ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);
    if (!empty($errors)) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }

    $uploadDir = dirname(__DIR__) . '/public/uploads/maintenance_reports';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $fileName = 'maint-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $fileName;

    [$ok] = saveOptimizedImage($file['tmp_name'], $targetPath, 1600, 82);
    if (!$ok && !move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new InvalidArgumentException('Failed to save the uploaded photo. Please try again.');
    }
    @chmod($targetPath, 0644);

    return base_url() . '/public/uploads/maintenance_reports/' . $fileName;
}

/**
 * Assigns a new maintenance request to whichever Staff user currently has
 * the fewest open (pending/sent/acknowledged) assignments - a greedy
 * least-loaded assignment so work doesn't pile up on one person. Falls back
 * to Admin users if no Staff accounts exist, and returns null (unassigned)
 * if the barangay has no staff/admin accounts at all.
 *
 * @return array{id: int, name: string}|null
 */
function frs_assign_least_loaded_staff(PDO $pdo): ?array
{
    frs_ensure_maintenance_requests_schema_v2($pdo);

    foreach (['Staff', 'Admin'] as $role) {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.name,
                    (SELECT COUNT(*) FROM cprf_maintenance_requests r
                     WHERE r.assigned_staff_id = u.id
                       AND r.status IN ('pending', 'sent', 'acknowledged')) AS open_count
             FROM users u
             WHERE u.role = :role AND u.status = 'active'
             ORDER BY open_count ASC, u.id ASC
             LIMIT 1"
        );
        $stmt->execute(['role' => $role]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
    }
    return null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function frs_fetch_recent_maintenance_requests(PDO $pdo, int $limit = 12): array
{
    if (!frs_ensure_cprf_maintenance_requests_table($pdo)) {
        return [];
    }

    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->query(
            'SELECT r.*, u.name AS requester_name
             FROM cprf_maintenance_requests r
             LEFT JOIN users u ON u.id = r.requested_by
             ORDER BY r.created_at DESC
             LIMIT ' . $limit
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        error_log('Fetch maintenance requests error: ' . $e->getMessage());
        return [];
    }
}

/**
 * In-app + email notification to the staff/admin a maintenance request was
 * just auto-assigned to, so it doesn't just sit invisibly in a list until
 * someone happens to open the Maintenance Insights tab.
 *
 * @param array<string, mixed> $context
 */
function frs_notify_staff_maintenance_assigned(PDO $pdo, int $staffId, array $context): void
{
    $staffStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id LIMIT 1');
    $staffStmt->execute(['id' => $staffId]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        return;
    }

    $facilityName = (string)($context['facility_name'] ?? 'a facility');
    $requestedDate = (string)($context['requested_date'] ?? '');
    $priority = ucfirst((string)($context['priority'] ?? 'medium'));
    $isManual = !empty($context['is_manual']);
    $notes = trim((string)($context['notes'] ?? ''));
    $manageUrl = base_url() . '/dashboard/maintenance-integration?tab=insights';

    $title = $isManual ? 'Maintenance Issue Assigned to You' : 'Maintenance Request Assigned to You';
    $dateLabel = $requestedDate !== '' ? date('M j, Y', strtotime($requestedDate)) : 'soon';
    $message = "{$facilityName} — {$priority} priority, needed {$dateLabel}.";
    if ($notes !== '') {
        $message .= ' ' . mb_substr($notes, 0, 150);
    }

    require_once __DIR__ . '/notifications.php';
    createNotification((int)$staffId, 'system', $title, $message, base_path() . '/dashboard/maintenance-integration?tab=insights');

    if (empty($staff['email'])) {
        return;
    }
    require_once __DIR__ . '/mail_helper.php';
    require_once __DIR__ . '/email_templates.php';
    $htmlBody = getMaintenanceAssignedEmailTemplate(
        (string)$staff['name'],
        $facilityName,
        $dateLabel,
        $priority,
        $notes,
        $manageUrl
    );
    sendEmail((string)$staff['email'], (string)$staff['name'], $title . ' — ' . $facilityName, $htmlBody);
}

/**
 * @param array<string, mixed> $payload
 * @return array{success: bool, request_id?: int, cimm_reference?: ?string, error?: string}
 */
function frs_submit_maintenance_request(PDO $pdo, array $payload, int $userId): array
{
    if (!frs_ensure_cprf_maintenance_requests_table($pdo)) {
        return ['success' => false, 'error' => 'Maintenance request storage is not available.'];
    }
    frs_ensure_maintenance_requests_schema_v2($pdo);

    $facilityId = (int)($payload['facility_id'] ?? 0);
    $requestedDate = trim((string)($payload['requested_date'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));
    $riskScore = (int)($payload['risk_score'] ?? 0);
    $riskBand = trim((string)($payload['risk_band'] ?? 'Medium'));
    $priority = strtolower(trim((string)($payload['priority'] ?? 'medium')));
    $facilityName = trim((string)($payload['facility_name'] ?? ''));
    $location = trim((string)($payload['location'] ?? ''));
    $requestSource = strtolower(trim((string)($payload['request_source'] ?? '')));
    $isManual = $requestSource === 'manual';
    $isAutoScheduled = $requestSource === 'auto';
    $photoUrl = trim((string)($payload['photo_url'] ?? ''));

    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
        return ['success' => false, 'error' => 'Invalid facility or requested date.'];
    }
    if ($isManual && $notes === '') {
        return ['success' => false, 'error' => 'Please describe the issue before submitting a manual request.'];
    }
    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $priority = 'medium';
    }
    if ($isManual) {
        $riskBand = 'Manual';
        $riskScore = 0;
    }
    // Auto-scheduled keeps the real computed risk_score/risk_band (unlike
    // manual, which has none) - that's the whole point of showing it was
    // scheduled automatically because the score crossed the High threshold.

    if ($facilityName === '') {
        $nameStmt = $pdo->prepare('SELECT name, location FROM facilities WHERE id = :id LIMIT 1');
        $nameStmt->execute(['id' => $facilityId]);
        $facility = $nameStmt->fetch(PDO::FETCH_ASSOC);
        if (!$facility) {
            return ['success' => false, 'error' => 'Facility not found.'];
        }
        $facilityName = (string)($facility['name'] ?? 'Facility');
        if ($location === '') {
            $location = (string)($facility['location'] ?? '');
        }
    }

    $dupStmt = $pdo->prepare(
        "SELECT id FROM cprf_maintenance_requests
         WHERE facility_id = :facility_id AND requested_date = :requested_date
           AND status IN ('pending', 'sent', 'acknowledged')
         LIMIT 1"
    );
    $dupStmt->execute(['facility_id' => $facilityId, 'requested_date' => $requestedDate]);
    if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
        return ['success' => false, 'error' => 'A maintenance request for this facility and date is already pending with CIMM.'];
    }

    $assignedStaff = frs_assign_least_loaded_staff($pdo);

    $insert = $pdo->prepare(
        'INSERT INTO cprf_maintenance_requests
            (facility_id, facility_name, requested_date, suggested_end_date, priority, risk_score, risk_band, notes, photo_path, status, requested_by, assigned_staff_id, assigned_staff_name)
         VALUES
            (:facility_id, :facility_name, :requested_date, :suggested_end_date, :priority, :risk_score, :risk_band, :notes, :photo_path, :status, :requested_by, :assigned_staff_id, :assigned_staff_name)'
    );
    $insert->execute([
        'facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'requested_date' => $requestedDate,
        'suggested_end_date' => $requestedDate,
        'priority' => $priority,
        'risk_score' => max(0, min(100, $riskScore)),
        'risk_band' => $riskBand,
        'assigned_staff_id' => $assignedStaff['id'] ?? null,
        'assigned_staff_name' => $assignedStaff['name'] ?? null,
        'notes' => $notes !== '' ? $notes : null,
        'photo_path' => $photoUrl !== '' ? $photoUrl : null,
        'status' => 'pending',
        'requested_by' => $userId > 0 ? $userId : null,
    ]);
    $requestId = (int)$pdo->lastInsertId();

    if ($isManual) {
        frs_record_manual_report_outcome($pdo, $facilityId);
    }

    if (!empty($assignedStaff['id'])) {
        frs_notify_staff_maintenance_assigned($pdo, (int)$assignedStaff['id'], [
            'facility_name' => $facilityName,
            'requested_date' => $requestedDate,
            'priority' => $priority,
            'risk_band' => $riskBand,
            'notes' => $notes,
            'is_manual' => $isManual,
            'request_id' => $requestId,
        ]);
    }

    require_once dirname(__DIR__) . '/services/cimm_api.php';

    $taskNotes = $notes !== ''
        ? $notes
        : ($isManual
            ? 'CPRF manual report — see facility for details.'
            : ($isAutoScheduled
                ? "CPRF auto-scheduled — pressure score {$riskScore}/100 (High risk) crossed the auto-schedule threshold."
                : 'CPRF predictive insight — elevated usage pressure detected.'));
    // CIMM's own schema has no attachment field we can write to (separate
    // system, no access to modify it) - a plain URL in the notes text is
    // the only way to hand their staff a viewable photo without needing
    // any coordination on their end. Still a real, clickable link.
    // CIMM's remote notes column truncates somewhere between 140-160 chars
    // (confirmed by probing their API directly) - stay safely under that,
    // trimming the free-text description first since the link matters more.
    if ($photoUrl !== '') {
        $maxCimmNotesLen = 140;
        $photoLine = "\nPhoto: {$photoUrl}";
        $budget = $maxCimmNotesLen - strlen($photoLine);
        if ($budget > 0 && strlen($taskNotes) > $budget) {
            $taskNotes = rtrim(substr($taskNotes, 0, max(0, $budget - 1))) . '…';
        }
        $taskNotes .= $photoLine;
        if (strlen($taskNotes) > $maxCimmNotesLen) {
            $taskNotes = substr($taskNotes, 0, $maxCimmNotesLen);
        }
    }
    $cimmPayload = [
        'facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'location' => $location,
        'task' => $isManual
            ? 'Manual maintenance report (CPRF request)'
            : ($isAutoScheduled ? 'Auto-scheduled preventive maintenance (CPRF)' : 'Preventive maintenance (CPRF request)'),
        'category' => $isManual
            ? 'Manual / Emergency Report'
            : ($isAutoScheduled ? 'Automatic Scheduling' : 'Preventive / Predictive'),
        'priority' => $priority,
        'starting_date' => $requestedDate,
        'estimated_completion_date' => $requestedDate,
        'status' => 'Request Pending',
        'source' => $isManual ? 'cprf_manual' : ($isAutoScheduled ? 'cprf_auto' : 'cprf_predictive'),
        'risk_score' => $riskScore,
        'risk_band' => $riskBand,
        'notes' => $taskNotes,
        'requested_by' => $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'CPRF Staff',
    ];

    $cimmResult = submitCIMMMaintenanceRequest($cimmPayload);
    $update = $pdo->prepare(
        'UPDATE cprf_maintenance_requests
         SET status = :status, cimm_reference = :cimm_reference, error_message = :error_message, updated_at = NOW()
         WHERE id = :id'
    );

    if (!empty($cimmResult['success'])) {
        $update->execute([
            'status' => 'sent',
            'cimm_reference' => $cimmResult['reference'] ?? null,
            'error_message' => null,
            'id' => $requestId,
        ]);
        // Otherwise the new task only shows up in the Maintenance Schedules
        // tab after someone clicks "Sync Now" (or the cron runs) - confusing
        // right after a submit that just told CIMM about it. Best-effort:
        // a stale cache is a minor inconvenience, not worth failing the
        // submission the user is waiting on.
        try {
            frs_cimm_run_sync($pdo);
        } catch (Throwable $e) {
            error_log('Post-submit CIMM sync error: ' . $e->getMessage());
        }
        return [
            'success' => true,
            'request_id' => $requestId,
            'cimm_reference' => $cimmResult['reference'] ?? null,
        ];
    }

    $errorMsg = (string)($cimmResult['error'] ?? 'Unable to reach CIMM.');
    $update->execute([
        'status' => 'failed',
        'cimm_reference' => null,
        'error_message' => mb_substr($errorMsg, 0, 500),
        'id' => $requestId,
    ]);

    return ['success' => false, 'error' => $errorMsg, 'request_id' => $requestId];
}
