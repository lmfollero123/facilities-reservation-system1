<?php
/**
 * Predictive maintenance insights and CPRF → CIMM request tracking.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

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
            $riskScore = min(100, $usagePressure + $growthPressure + $statusPressure);

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
 * @param array<string, mixed> $payload
 * @return array{success: bool, request_id?: int, cimm_reference?: ?string, error?: string}
 */
function frs_submit_maintenance_request(PDO $pdo, array $payload, int $userId): array
{
    if (!frs_ensure_cprf_maintenance_requests_table($pdo)) {
        return ['success' => false, 'error' => 'Maintenance request storage is not available.'];
    }

    $facilityId = (int)($payload['facility_id'] ?? 0);
    $requestedDate = trim((string)($payload['requested_date'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));
    $riskScore = (int)($payload['risk_score'] ?? 0);
    $riskBand = trim((string)($payload['risk_band'] ?? 'Medium'));
    $priority = strtolower(trim((string)($payload['priority'] ?? 'medium')));
    $facilityName = trim((string)($payload['facility_name'] ?? ''));
    $location = trim((string)($payload['location'] ?? ''));

    if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
        return ['success' => false, 'error' => 'Invalid facility or requested date.'];
    }
    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $priority = 'medium';
    }

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

    $insert = $pdo->prepare(
        'INSERT INTO cprf_maintenance_requests
            (facility_id, facility_name, requested_date, suggested_end_date, priority, risk_score, risk_band, notes, status, requested_by)
         VALUES
            (:facility_id, :facility_name, :requested_date, :suggested_end_date, :priority, :risk_score, :risk_band, :notes, :status, :requested_by)'
    );
    $insert->execute([
        'facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'requested_date' => $requestedDate,
        'suggested_end_date' => $requestedDate,
        'priority' => $priority,
        'risk_score' => max(0, min(100, $riskScore)),
        'risk_band' => $riskBand,
        'notes' => $notes !== '' ? $notes : null,
        'status' => 'pending',
        'requested_by' => $userId > 0 ? $userId : null,
    ]);
    $requestId = (int)$pdo->lastInsertId();

    require_once dirname(__DIR__) . '/services/cimm_api.php';

    $taskNotes = $notes !== '' ? $notes : 'CPRF predictive insight — elevated usage pressure detected.';
    $cimmPayload = [
        'facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'location' => $location,
        'task' => 'Preventive maintenance (CPRF request)',
        'category' => 'Preventive / Predictive',
        'priority' => $priority,
        'starting_date' => $requestedDate,
        'estimated_completion_date' => $requestedDate,
        'status' => 'Request Pending',
        'source' => 'cprf_predictive',
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
