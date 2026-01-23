<?php
/**
 * CIMM API Integration Helper
 * Fetches maintenance schedules from the Community Infrastructure Maintenance Management system
 */

/**
 * Fetches maintenance schedules from CIMM API
 * 
 * @return array Array of maintenance schedules, or empty array on error
 */
function fetchCIMMMaintenanceSchedules(): array {
    // API Configuration
    $apiUrl = 'https://cimm.infragovservices.com/api/maintenance-schedules.php';
    $apiKey = 'CIMM_SECURE_KEY_2025'; // TODO: Move to config file for security
    
    $url = $apiUrl . '?key=' . urlencode($apiKey);
    
    // Initialize cURL
    $ch = curl_init($url);
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: CPRF-Facilities-Reservation/1.0'
        ]
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    // Handle errors
    if ($response === false || !empty($curlError)) {
        error_log('CIMM API Error: ' . $curlError);
        return [];
    }
    
    if ($httpCode !== 200) {
        error_log('CIMM API HTTP Error: ' . $httpCode);
        return [];
    }
    
    // Decode JSON response
    $json = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('CIMM API JSON Error: ' . json_last_error_msg());
        return [];
    }
    
    // Return data array or empty array
    return $json['data'] ?? [];
}

/**
 * Maps CIMM data format to CPRF format
 * 
 * @param array $rawSchedules Raw schedules from CIMM API
 * @return array Mapped schedules in CPRF format
 */
function mapCIMMToCPRF(array $rawSchedules): array {
    $mappedSchedules = [];
    
    foreach ($rawSchedules as $row) {
        $start = strtotime($row['starting_date'] ?? '');
        $end = strtotime($row['estimated_completion_date'] ?? '');
        
        // Calculate duration
        $duration = '';
        if ($start && $end) {
            $hours = round(($end - $start) / 3600);
            if ($hours < 24) {
                $duration = $hours . ' hours';
            } else {
                $days = round($hours / 24);
                $duration = $days . ' day' . ($days > 1 ? 's' : '');
            }
        }
        
        // Map status
        $status = strtolower(str_replace(' ', '_', $row['status'] ?? 'scheduled'));
        
        // Map priority
        $priority = strtolower($row['priority'] ?? 'low');
        
        $mappedSchedules[] = [
            'id' => 'CIMM-' . ($row['sched_id'] ?? ''),
            'sched_id' => $row['sched_id'] ?? '',
            'facility_name' => $row['location'] ?? '',
            'maintenance_type' => $row['task'] ?? '',
            'scheduled_start' => $row['starting_date'] ?? '',
            'scheduled_end' => $row['estimated_completion_date'] ?? '',
            'status' => $status,
            'status_label' => $row['status'] ?? 'Scheduled',
            'priority' => $priority,
            'description' => $row['category'] ?? '',
            'category' => $row['category'] ?? 'General Maintenance',
            'assigned_team' => $row['assigned_team'] ?? '',
            'estimated_duration' => $duration,
            'affected_reservations' => 0, // Will be calculated separately
            'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
            // Additional fields for calendar compatibility
            'task' => $row['task'] ?? '',
            'location' => $row['location'] ?? '',
            'schedule_date' => date('Y-m-d', strtotime($row['starting_date'] ?? 'now'))
        ];
    }
    
    return $mappedSchedules;
}
