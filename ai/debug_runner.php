<?php
// Debug runner for AI models

require_once __DIR__ . '/../config/ai_ml_integration.php';


$log = "";
$log .= "Testing AI Model Integration...\n";
$log .= "Python Path: " . getPythonPath() . "\n";
$log .= "AI Path: " . getAIPath() . "\n";

$inputData = [
    'facilities' => [['id' => 1, 'name' => 'Court', 'amenities' => 'basketball', 'capacity' => '100']],
    'user_id' => 1,
    'purpose' => 'basketball',
    'expected_attendees' => 10,
    'reservation_date' => '2023-10-27'
];

$log .= "\nCalling predict_conflict.py...\n";
$result = callPythonModel('api/recommend_facilities.py', [], $inputData);

$log .= "\nResult:\n";
$log .= print_r($result, true);

if (isset($result['error'])) {
    $log .= "\nFATAL ERROR DETECTED: " . $result['error'] . "\n";
    if (isset($result['stderr'])) {
        $log .= "STDERR:\n" . $result['stderr'] . "\n";
    }
    if (isset($result['output'])) {
        $log .= "RAW OUTPUT:\n" . $result['output'] . "\n";
    }
} else {
    $log .= "\nSUCCESS!\n";
}

file_put_contents('debug_log_utf8.txt', $log);
echo "Done.";
