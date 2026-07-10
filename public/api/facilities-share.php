<?php
/**
 * API Endpoint for Sharing Facility Data with LGU Maintenance System
 * 
 * This endpoint provides facility data to the LGU Maintenance System
 * for automatic facility detection and maintenance status synchronization.
 * 
 * Authentication: API key required
 * CORS: Configured for LGU Maintenance System domain
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load dependencies
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

// API Key Validation - from ENV
$API_KEY = trim((string)(function_exists('env_value') ? env_value('FACILITIES_API_KEY', '') : (getenv('FACILITIES_API_KEY') ?: 'FACILITIES_SECURE_KEY_2025')));
if (!isset($_GET['key']) || $_GET['key'] !== $API_KEY) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: API key incorrect'
    ]);
    exit;
}

try {
    $pdo = db();
    
    // Get all active facilities with coordinates
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            location,
            description,
            capacity,
            amenities,
            latitude,
            longitude,
            operating_hours,
            status,
            created_at,
            updated_at
        FROM facilities
        WHERE status IN ('available', 'maintenance')
        ORDER BY name ASC
    ");
    
    $stmt->execute();
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $data = [];
    foreach ($facilities as $facility) {
        $data[] = [
            'facility_id' => (int)$facility['id'],
            'name' => $facility['name'],
            'location' => $facility['location'],
            'description' => $facility['description'] ?? '',
            'capacity' => $facility['capacity'] ?? '',
            'amenities' => $facility['amenities'] ?? '',
            'latitude' => $facility['latitude'] ? (float)$facility['latitude'] : null,
            'longitude' => $facility['longitude'] ? (float)$facility['longitude'] : null,
            'operating_hours' => $facility['operating_hours'] ?? '',
            'current_status' => $facility['status'],
            'created_at' => $facility['created_at'],
            'updated_at' => $facility['updated_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($data),
        'generated_at' => date('Y-m-d H:i:s T'),
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
