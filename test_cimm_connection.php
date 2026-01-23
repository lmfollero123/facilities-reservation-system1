<?php
/**
 * Test CIMM API Connection
 * Run this file to test if CIMM API is accessible
 * Usage: php test_cimm_connection.php
 */

require_once __DIR__ . '/services/cimm_api.php';

echo "=== CIMM API Connection Test ===\n\n";

echo "Testing connection to: https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php\n";
echo "API Key: CIMM_SECURE_KEY_2025\n\n";

$result = fetchCIMMMaintenanceSchedules();

if (!empty($result['error'])) {
    echo "❌ CONNECTION FAILED\n";
    echo "Error: " . $result['error'] . "\n\n";
    
    echo "Possible Issues:\n";
    echo "1. CIMM API endpoint not created yet\n";
    echo "2. API endpoint URL is incorrect\n";
    echo "3. API key is incorrect\n";
    echo "4. CORS (Cross-Origin) restrictions\n";
    echo "5. SSL certificate issues\n";
    echo "6. Network/firewall blocking the connection\n\n";
    
    echo "Solution:\n";
    echo "1. Share docs/CIMM_API_INTEGRATION.md with CIMM team\n";
    echo "2. Ask them to create /api/maintenance-schedules.php on their server\n";
    echo "3. Verify the API key matches: CIMM_SECURE_KEY_2025\n";
    echo "4. Test the API directly: https://cimm.infragovservices.com/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025\n";
} else {
    $schedules = $result['data'] ?? [];
    $count = count($schedules);
    
    echo "✅ CONNECTION SUCCESSFUL\n";
    echo "Schedules found: " . $count . "\n\n";
    
    if ($count > 0) {
        echo "Sample schedule:\n";
        $sample = $schedules[0];
        echo "  - ID: " . ($sample['sched_id'] ?? 'N/A') . "\n";
        echo "  - Task: " . ($sample['task'] ?? 'N/A') . "\n";
        echo "  - Location: " . ($sample['location'] ?? 'N/A') . "\n";
        echo "  - Status: " . ($sample['status'] ?? 'N/A') . "\n";
        echo "  - Start Date: " . ($sample['starting_date'] ?? 'N/A') . "\n";
    } else {
        echo "⚠️  No maintenance schedules found in CIMM database.\n";
        echo "This is normal if CIMM doesn't have any scheduled maintenance yet.\n";
    }
}

echo "\n=== Test Complete ===\n";
