<?php
/**
 * Webhook Endpoint for Receiving Maintenance Status Updates from LGU Maintenance System (CIMM)
 *
 * Authentication: Bearer token (LGU_WEBHOOK_KEY in .env)
 * Methods: POST
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.',
    ]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/maintenance_helper.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../services/cimm_api.php';

$apiKey = trim((string)(function_exists('env_value') ? env_value('LGU_WEBHOOK_KEY', '') : (getenv('LGU_WEBHOOK_KEY') ?: 'LGU_TO_FACILITIES_KEY_2025')));
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($apiKey === '' || !preg_match('/Bearer\s+(.+)/', $authHeader, $matches) || trim($matches[1]) !== $apiKey) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid or missing API key',
    ]);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    if (!isset($data['facility_name']) || !isset($data['action'])) {
        throw new Exception('Missing required fields: facility_name, action');
    }

    $pdo = db();
    $facilityNameInput = trim((string)$data['facility_name']);
    $maintenanceStatus = (string)($data['maintenance_status'] ?? '');
    $action = (string)$data['action'];
    $facilityIdInput = isset($data['facility_id']) ? (int)$data['facility_id'] : 0;

    $facilitiesStmt = $pdo->query('SELECT id, name, location, status FROM facilities');
    $facilities = $facilitiesStmt ? $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $facility = null;
    if ($facilityIdInput > 0) {
        foreach ($facilities as $row) {
            if ((int)$row['id'] === $facilityIdInput) {
                $facility = $row;
                break;
            }
        }
    }

    if (!$facility) {
        $matchedId = cimmMatchFacilityId($facilityNameInput, $facilities);
        if ($matchedId) {
            foreach ($facilities as $row) {
                if ((int)$row['id'] === $matchedId) {
                    $facility = $row;
                    break;
                }
            }
        }
    }

    if (!$facility) {
        throw new Exception("No facility found matching: {$facilityNameInput}");
    }

    $facilityId = (int)$facility['id'];
    $facilityName = (string)$facility['name'];
    $currentStatus = (string)$facility['status'];
    $cimmManagedMaintenance = frs_cimm_load_managed_maintenance_ids();

    $pdo->beginTransaction();

    $result = [
        'facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'previous_status' => $currentStatus,
        'new_status' => $currentStatus,
        'action_taken' => '',
        'reservations_affected' => [],
    ];

    switch ($action) {
        case 'start_maintenance':
            if ($currentStatus !== 'maintenance') {
                $updateStmt = $pdo->prepare(
                    'UPDATE facilities SET status = "maintenance", updated_at = NOW() WHERE id = :id'
                );
                $updateStmt->execute(['id' => $facilityId]);

                $cimmManagedMaintenance[$facilityId] = true;
                frs_cimm_save_managed_maintenance_ids($cimmManagedMaintenance);

                $result['new_status'] = 'maintenance';
                $result['action_taken'] = 'Facility set to maintenance status';
                $result['reservations_affected'] = handleFacilityMaintenanceStatusChange($facilityId, $facilityName);

                logAudit(
                    'Facility maintenance status changed via CIMM webhook',
                    'LGU Integration',
                    "{$facilityName} (ID {$facilityId}) set to maintenance ({$maintenanceStatus})"
                );
            } else {
                $result['action_taken'] = 'Facility already in maintenance status';
            }
            break;

        case 'end_maintenance':
            if ($currentStatus === 'maintenance') {
                $updateStmt = $pdo->prepare(
                    'UPDATE facilities SET status = "available", updated_at = NOW() WHERE id = :id'
                );
                $updateStmt->execute(['id' => $facilityId]);

                unset($cimmManagedMaintenance[$facilityId]);
                frs_cimm_save_managed_maintenance_ids($cimmManagedMaintenance);

                $result['new_status'] = 'available';
                $result['action_taken'] = 'Facility set to available status';
                $result['notifications_sent'] = handleFacilityAvailableStatusChange($facilityId, $facilityName);

                logAudit(
                    'Facility maintenance status changed via CIMM webhook',
                    'LGU Integration',
                    "{$facilityName} (ID {$facilityId}) set to available"
                );
            } else {
                $result['action_taken'] = 'Facility not in maintenance status';
            }
            break;

        case 'update_status':
            $result['action_taken'] = "Maintenance status updated to: {$maintenanceStatus}";
            $result['maintenance_status'] = $maintenanceStatus;
            break;

        default:
            throw new Exception("Unknown action: {$action}");
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Facility status updated successfully',
        'processed_at' => date('Y-m-d H:i:s T'),
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
