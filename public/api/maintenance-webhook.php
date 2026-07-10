<?php
/**
 * Webhook Endpoint for Receiving Maintenance Status Updates from LGU Maintenance System
 * 
 * This endpoint receives maintenance status updates from the LGU Maintenance System
 * and automatically updates facility status accordingly.
 * 
 * Authentication: API key required
 * Methods: POST
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// Load dependencies
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/maintenance_helper.php';
require_once __DIR__ . '/../../config/audit.php';

// API Key Validation - from ENV
$API_KEY = trim((string)(function_exists('env_value') ? env_value('LGU_WEBHOOK_KEY', '') : (getenv('LGU_WEBHOOK_KEY') ?: 'LGU_TO_FACILITIES_KEY_2025')));
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!preg_match('/Bearer\s+(.+)/', $authHeader, $matches) || $matches[1] !== $API_KEY) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid or missing API key'
    ]);
    exit;
}

try {
    // Parse JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }
    
    // Validate required fields
    if (!isset($data['facility_name']) || !isset($data['maintenance_status']) || !isset($data['action'])) {
        throw new Exception('Missing required fields: facility_name, maintenance_status, action');
    }
    
    $pdo = db();
    $facilityName = $data['facility_name'];
    $maintenanceStatus = $data['maintenance_status']; // 'scheduled', 'in_progress', 'completed', 'delayed'
    $action = $data['action']; // 'start_maintenance', 'end_maintenance', 'update_status'
    
    // Find facility by name (fuzzy matching)
    $stmt = $pdo->prepare("
        SELECT id, name, status 
        FROM facilities 
        WHERE LOWER(name) LIKE LOWER(:name_pattern)
        LIMIT 5
    ");
    $stmt->execute(['name_pattern' => '%' . $facilityName . '%']);
    $matchingFacilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($matchingFacilities)) {
        throw new Exception("No facility found matching: {$facilityName}");
    }
    
    // Use the best match (exact match if available, otherwise first fuzzy match)
    $facility = null;
    foreach ($matchingFacilities as $match) {
        if (strtolower($match['name']) === strtolower($facilityName)) {
            $facility = $match;
            break;
        }
    }
    if (!$facility) {
        $facility = $matchingFacilities[0];
    }
    
    $facilityId = (int)$facility['id'];
    $facilityName = $facility['name']; // Use actual facility name from DB
    $currentStatus = $facility['status'];
    
    $pdo->beginTransaction();
    
    $result = [
        'facility_id' => $facilityId,
        'facility_name' => $facilityName,
        'previous_status' => $currentStatus,
        'new_status' => $currentStatus,
        'action_taken' => '',
        'reservations_affected' => []
    ];
    
    // Handle different actions
    switch ($action) {
        case 'start_maintenance':
            // Set facility to maintenance status
            if ($currentStatus !== 'maintenance') {
                $updateStmt = $pdo->prepare("
                    UPDATE facilities 
                    SET status = 'maintenance', updated_at = NOW() 
                    WHERE id = :id
                ");
                $updateStmt->execute(['id' => $facilityId]);
                
                $result['new_status'] = 'maintenance';
                $result['action_taken'] = 'Facility set to maintenance status';
                
                // Handle existing reservations
                $reservationResult = handleFacilityMaintenanceStatusChange($facilityId, $facilityName);
                $result['reservations_affected'] = $reservationResult;
                
                // Log audit
                logAudit(
                    'Facility maintenance status changed via LGU integration',
                    'LGU Integration',
                    "{$facilityName} (ID {$facilityId}) set to maintenance by LGU system"
                );
            } else {
                $result['action_taken'] = 'Facility already in maintenance status';
            }
            break;
            
        case 'end_maintenance':
            // Set facility back to available
            if ($currentStatus === 'maintenance') {
                $updateStmt = $pdo->prepare("
                    UPDATE facilities 
                    SET status = 'available', updated_at = NOW() 
                    WHERE id = :id
                ");
                $updateStmt->execute(['id' => $facilityId]);
                
                $result['new_status'] = 'available';
                $result['action_taken'] = 'Facility set to available status';
                
                // Notify users with postponed reservations
                $notificationResult = handleFacilityAvailableStatusChange($facilityId, $facilityName);
                $result['notifications_sent'] = $notificationResult;
                
                // Log audit
                logAudit(
                    'Facility maintenance status changed via LGU integration',
                    'LGU Integration',
                    "{$facilityName} (ID {$facilityId}) set to available by LGU system"
                );
            } else {
                $result['action_taken'] = 'Facility not in maintenance status';
            }
            break;
            
        case 'update_status':
            // Just update the maintenance status without changing facility status
            // This could be used to show "In Progress", "Delayed", etc. in the UI
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
        'result' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
