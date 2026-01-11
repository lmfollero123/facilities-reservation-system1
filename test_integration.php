<?php
/**
 * Quick test script to verify AI integration
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== AI Integration Test ===\n\n";

// Test 1: Check if integration file exists
echo "1. Checking integration file...\n";
$integrationFile = __DIR__ . '/config/ai_ml_integration.php';
if (file_exists($integrationFile)) {
    echo "   ✓ Integration file exists\n";
    require_once $integrationFile;
} else {
    echo "   ✗ Integration file NOT found at: $integrationFile\n";
    exit(1);
}

// Test 2: Check if functions are available
echo "\n2. Checking functions...\n";
$functions = [
    'predictConflictML',
    'assessRiskML',
    'classifyChatbotIntent',
    'checkMLModelsStatus',
    'callPythonModel',
];

foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "   ✓ $func() available\n";
    } else {
        echo "   ✗ $func() NOT available\n";
    }
}

// Test 3: Check model status
echo "\n3. Checking model status...\n";
if (function_exists('checkMLModelsStatus')) {
    $status = checkMLModelsStatus();
    foreach ($status as $model => $info) {
        $available = $info['available'] ? '✓' : '✗';
        echo "   $available $model: " . ($info['available'] ? 'Available' : 'Not found') . "\n";
    }
} else {
    echo "   ✗ checkMLModelsStatus() not available\n";
}

// Test 4: Test Python path
echo "\n4. Testing Python path...\n";
if (function_exists('getPythonPath')) {
    $pythonPath = getPythonPath();
    echo "   Python path: $pythonPath\n";
    
    // Test if Python works
    $output = [];
    $returnVar = 0;
    @exec("$pythonPath --version 2>&1", $output, $returnVar);
    if ($returnVar === 0) {
        echo "   ✓ Python is accessible: " . implode(' ', $output) . "\n";
    } else {
        echo "   ✗ Python is NOT accessible\n";
    }
} else {
    echo "   ✗ getPythonPath() not available\n";
}

// Test 5: Test AI path
echo "\n5. Testing AI directory path...\n";
if (function_exists('getAIPath')) {
    $aiPath = getAIPath();
    echo "   AI path: $aiPath\n";
    if (is_dir($aiPath)) {
        echo "   ✓ AI directory exists\n";
    } else {
        echo "   ✗ AI directory does NOT exist\n";
    }
} else {
    echo "   ✗ getAIPath() not available\n";
}

echo "\n=== Test Complete ===\n";
