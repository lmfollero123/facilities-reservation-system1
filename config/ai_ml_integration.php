<?php
/**
 * AI/ML Model Integration Helper Functions
 * 
 * Provides PHP functions to call Python ML models
 * 
 * @package FacilitiesReservation
 */

require_once __DIR__ . '/app.php';

/**
 * Get the path to Python executable
 * Checks virtual environment first, then common locations for Python
 */
function getPythonPath(): string {
    $aiPath = getAIPath();
    
    // Check for virtual environment first (common locations)
    $venvPaths = [
        $aiPath . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',  // Windows
        $aiPath . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python',  // Linux/macOS
        $aiPath . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',  // Windows alternate
        $aiPath . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python',  // Linux/macOS alternate
        $aiPath . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',  // Windows alternate
        $aiPath . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python',  // Linux/macOS alternate
    ];
    
    foreach ($venvPaths as $venvPath) {
        if (file_exists($venvPath)) {
            return $venvPath;
        }
    }
    
    // If no venv found, check system Python
    $possiblePaths = [
        'python',           // If Python is in PATH
        'python3',          // Python 3
        '/usr/bin/python3', // Linux
        'C:\\Python\\python.exe', // Windows
        'C:\\Python3\\python.exe', // Windows
    ];
    
    foreach ($possiblePaths as $path) {
        $output = [];
        $returnVar = 0;
        @exec("$path --version 2>&1", $output, $returnVar);
        if ($returnVar === 0) {
            return $path;
        }
    }
    
    // Default fallback
    return 'python';
}

/**
 * Get the base path to AI directory
 */
function getAIPath(): string {
    // Get absolute path to project root
    $configDir = __DIR__; // config/ directory
    $projectRoot = dirname($configDir); // Go up one level from config/
    return $projectRoot . DIRECTORY_SEPARATOR . 'ai';
}

/**
 * Call Python script and get JSON response
 * 
 * @param string $scriptPath Path to Python script (relative to ai directory)
 * @param array $args Command line arguments
 * @param array|null $inputData JSON input data (if script reads from stdin)
 * @return array Decoded JSON response or error array
 */
function callPythonModel(string $scriptPath, array $args = [], ?array $inputData = null): array {
    $pythonPath = getPythonPath();
    $aiPath = getAIPath();
    $fullScriptPath = $aiPath . '/' . $scriptPath;
    
    // Check if script exists
    if (!file_exists($fullScriptPath)) {
        return ['error' => "Python script not found: $scriptPath"];
    }
    
    // Build command
    $command = escapeshellarg($pythonPath) . ' ' . escapeshellarg($fullScriptPath);
    
    // Add command line arguments
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    
    // Prepare input data if provided
    $inputJson = null;
    if ($inputData !== null) {
        $inputJson = json_encode($inputData);
    }
    
    // Execute command with timeout (5 seconds max for ML operations)
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    
    $process = proc_open($command, $descriptorspec, $pipes);
    
    if (!is_resource($process)) {
        return ['error' => 'Failed to execute Python script'];
    }
    
    // Set stream blocking to non-blocking for timeout
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    
    // Write input data if provided
    if ($inputJson !== null) {
        fwrite($pipes[0], $inputJson);
        fclose($pipes[0]);
    } else {
        fclose($pipes[0]);
    }
    
    // Read output with timeout
    $output = '';
    $error = '';
    $timeout = 5; // 5 seconds timeout
    $startTime = time();
    
    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        
        // Check for timeout
        if (time() - $startTime > $timeout) {
            proc_terminate($process);
            proc_close($process);
            return ['error' => 'Python script execution timeout (>' . $timeout . 's)'];
        }
        
        $numChanged = stream_select($read, $write, $except, 1);
        
        if ($numChanged === false || $numChanged === 0) {
            // Check if process is still running
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            continue;
        }
        
        foreach ($read as $stream) {
            if ($stream === $pipes[1]) {
                $output .= stream_get_contents($pipes[1]);
            } elseif ($stream === $pipes[2]) {
                $error .= stream_get_contents($pipes[2]);
            }
        }
        
        // Check if process finished
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
    }
    
    // Get any remaining output
    $output .= stream_get_contents($pipes[1]);
    $error .= stream_get_contents($pipes[2]);
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $returnValue = proc_close($process);
    
    // Check for errors
    if ($returnValue !== 0) {
        error_log("Python script error: $error");
        return ['error' => "Python script failed with return code $returnValue", 'stderr' => $error];
    }
    
    // Parse JSON output
    $result = json_decode($output, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg() . " Output: $output");
        return ['error' => 'Failed to parse Python script output', 'output' => $output];
    }
    
    return $result ?? ['error' => 'Empty response from Python script'];
}

/**
 * Predict conflict probability using ML model
 * 
 * @param int $facilityId Facility ID
 * @param string $reservationDate Reservation date (Y-m-d format)
 * @param string $timeSlot Time slot string
 * @param int|null $expectedAttendees Expected attendees
 * @param bool $isCommercial Is commercial reservation
 * @param string $capacity Facility capacity
 * @return array Conflict prediction result
 */
function predictConflictML(
    int $facilityId,
    string $reservationDate,
    string $timeSlot,
    ?int $expectedAttendees,
    bool $isCommercial,
    string $capacity = '100'
): array {
    $inputData = [
        'facility_id' => $facilityId,
        'reservation_date' => $reservationDate,
        'time_slot' => $timeSlot,
        'expected_attendees' => $expectedAttendees ?? 50,
        'is_commercial' => $isCommercial,
        'capacity' => $capacity,
    ];
    
    $result = callPythonModel('api/predict_conflict.py', [], $inputData);
    
    // Return default if error
    if (isset($result['error'])) {
        return [
            'conflict_probability' => 0.5,
            'is_conflict' => false,
            'confidence' => 0.0,
            'error' => $result['error'],
            'stderr' => $result['stderr'] ?? null,
        ];
    }
    
    return $result;
}

/**
 * Assess reservation risk using ML model
 * 
 * @param int $facilityId Facility ID
 * @param int $userId User ID
 * @param string $reservationDate Reservation date (Y-m-d format)
 * @param string $timeSlot Time slot string
 * @param int|null $expectedAttendees Expected attendees
 * @param bool $isCommercial Is commercial reservation
 * @param array $facilityData Facility data array
 * @param array $userData User data array
 * @return array Risk assessment result
 */
function assessRiskML(
    int $facilityId,
    int $userId,
    string $reservationDate,
    string $timeSlot,
    ?int $expectedAttendees,
    bool $isCommercial,
    array $facilityData,
    array $userData
): array {
    $result = callPythonModel('api/predict_risk.py', [
        $facilityId,
        $userId,
        $reservationDate,
        $timeSlot,
        $expectedAttendees ?? 50,
        $isCommercial ? '1' : '0',
        $facilityData['auto_approve'] ?? false ? '1' : '0',
        $facilityData['capacity'] ?? '100',
        $facilityData['max_duration_hours'] ?? '8.0',
        $facilityData['capacity_threshold'] ?? '200',
        $userData['is_verified'] ?? true ? '1' : '0',
        $userData['booking_count'] ?? 0,
        $userData['violation_count'] ?? 0,
    ]);
    
    // Return default if error
    if (isset($result['error'])) {
        return [
            'risk_level' => 1,
            'risk_probability' => 0.5,
            'confidence' => 0.0,
            'is_high_risk' => true,
            'error' => $result['error'],
        ];
    }
    
    return $result;
}

/**
 * Classify chatbot user question intent
 * 
 * @param string $question User's question
 * @return array Intent classification result
 */
function classifyChatbotIntent(string $question): array {
    $result = callPythonModel('api/classify_intent.py', [], ['question' => $question]);
    
    // Return default if error
    if (isset($result['error'])) {
        return [
            'intent' => 'unknown',
            'confidence' => 0.0,
            'top_intents' => [],
            'error' => $result['error'],
        ];
    }
    
    return $result;
}

/**
 * Classify reservation purpose into a category
 * 
 * @param string $purpose Purpose text
 * @return array Category classification result
 */
function classifyPurposeCategory(string $purpose): array {
    $result = callPythonModel('api/classify_purpose.py', [], ['purpose' => $purpose]);
    
    // Return default if error
    if (isset($result['error'])) {
        return [
            'category' => 'private',
            'confidence' => 0.0,
            'error' => $result['error'],
        ];
    }
    
    return $result;
}

/**
 * Detect if reservation purpose is unclear or suspicious
 * 
 * @param string $purpose Purpose text
 * @return array Unclear detection result
 */
function detectUnclearPurpose(string $purpose): array {
    $result = callPythonModel('api/detect_unclear_purpose.py', [], ['purpose' => $purpose]);
    
    // Return default if error
    if (isset($result['error'])) {
        return [
            'is_unclear' => true,
            'probability' => 0.5,
            'confidence' => 0.0,
            'error' => $result['error'],
        ];
    }
    
    return $result;
}

/**
 * Get facility recommendations using ML model
 * 
 * @param array $facilities List of facility data arrays
 * @param int $userId User ID
 * @param string $purpose Event purpose
 * @param int $expectedAttendees Expected attendees
 * @param string $timeSlot Time slot string
 * @param string $reservationDate Reservation date (Y-m-d format)
 * @param bool $isCommercial Is commercial reservation
 * @param int $userBookingCount User's total booking count
 * @param int $limit Number of recommendations
 * @return array Recommendations with ML scores
 */
function recommendFacilitiesML(
    array $facilities,
    int $userId,
    string $purpose,
    int $expectedAttendees,
    string $timeSlot,
    string $reservationDate,
    bool $isCommercial = false,
    int $userBookingCount = 0,
    int $limit = 5
): array {
    $inputData = [
        'facilities' => $facilities,
        'user_id' => $userId,
        'purpose' => $purpose,
        'expected_attendees' => $expectedAttendees,
        'time_slot' => $timeSlot,
        'reservation_date' => $reservationDate,
        'is_commercial' => $isCommercial,
        'user_booking_count' => $userBookingCount,
        'limit' => $limit,
    ];
    
    $result = callPythonModel('api/recommend_facilities.py', [], $inputData);
    
    // Return original facilities if error (fallback)
    if (isset($result['error'])) {
        return [
            'recommendations' => array_slice($facilities, 0, $limit),
            'error' => $result['error'],
        ];
    }
    
    return $result;
}

/**
 * Forecast booking demand for a facility on a specific date
 * 
 * @param int $facilityId Facility ID
 * @param string $date Date (Y-m-d format)
 * @param array|null $historicalData Optional historical booking data
 * @return array Demand forecast result
 */
function forecastDemandML(
    int $facilityId,
    string $date,
    ?array $historicalData = null
): array {
    $inputData = [
        'facility_id' => $facilityId,
        'date' => $date,
        'historical_data' => $historicalData,
    ];
    
    $result = callPythonModel('api/forecast_demand.py', [], $inputData);
    
    // Return default if error
    if (isset($result['error'])) {
        return [
            'predicted_count' => 0.0,
            'confidence' => 0.0,
            'error' => $result['error'],
        ];
    }
    
    return $result;
}

/**
 * Check if ML models are available
 * 
 * @return array Status of each model
 */
function checkMLModelsStatus(): array {
    $aiPath = getAIPath();
    $modelsDir = $aiPath . '/models';
    
    $models = [
        'conflict_detection' => 'conflict_detection.pkl',
        'auto_approval_risk' => 'auto_approval_risk_model.pkl',
        'chatbot_intent' => 'chatbot_intent_model.pkl',
        'purpose_category' => 'purpose_category_model.pkl',
        'purpose_unclear' => 'purpose_unclear_model.pkl',
        'facility_recommendation' => 'facility_recommendation_model.pkl',
        'demand_forecasting' => 'demand_forecasting_model.pkl',
    ];
    
    $status = [];
    foreach ($models as $name => $filename) {
        $filepath = $modelsDir . '/' . $filename;
        $status[$name] = [
            'available' => file_exists($filepath),
            'path' => $filepath,
            'size' => file_exists($filepath) ? filesize($filepath) : 0,
        ];
    }
    
    return $status;
}
