<?php
/**
 * Quick PHP Error Checker for Localhost
 * Place this in your project root and access via browser: http://localhost/your-project/check_php_errors.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>PHP Error Configuration Check</h1>";
echo "<h2>Error Reporting</h2>";
echo "<p>Error Reporting Level: " . error_reporting() . "</p>";
echo "<p>Display Errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>Log Errors: " . (ini_get('log_errors') ? 'ON' : 'OFF') . "</p>";

echo "<h2>Error Log Location</h2>";
$errorLog = ini_get('error_log');
if ($errorLog) {
    echo "<p>Error Log File: <code>$errorLog</code></p>";
    if (file_exists($errorLog)) {
        echo "<p>File exists: YES</p>";
        echo "<p>File size: " . filesize($errorLog) . " bytes</p>";
        echo "<p>Last modified: " . date('Y-m-d H:i:s', filemtime($errorLog)) . "</p>";
        
        echo "<h3>Last 50 lines of error log:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 1rem; overflow: auto; max-height: 500px;'>";
        $lines = file($errorLog);
        $lastLines = array_slice($lines, -50);
        echo htmlspecialchars(implode('', $lastLines));
        echo "</pre>";
    } else {
        echo "<p>File exists: NO</p>";
    }
} else {
    echo "<p>Error log location not set. Default locations:</p>";
    echo "<ul>";
    echo "<li>Windows (XAMPP): C:\\xampp\\php\\logs\\php_error_log</li>";
    echo "<li>Windows (WAMP): C:\\wamp\\logs\\php_error.log</li>";
    echo "<li>Linux: /var/log/php_errors.log or /var/log/apache2/error.log</li>";
    echo "</ul>";
}

echo "<h2>Test Error Logging</h2>";
error_log("Test error message from check_php_errors.php at " . date('Y-m-d H:i:s'));
echo "<p>✓ Test error logged. Check the error log file above.</p>";

echo "<h2>Recent PHP Errors (if any)</h2>";
$commonLogs = [
    __DIR__ . '/logs/php_errors.log',
    __DIR__ . '/error_log',
    ini_get('error_log'),
];
foreach ($commonLogs as $log) {
    if ($log && file_exists($log)) {
        echo "<h3>Found: $log</h3>";
        $lines = file($log);
        $recent = array_slice($lines, -20);
        echo "<pre style='background: #f5f5f5; padding: 1rem; overflow: auto; max-height: 300px;'>";
        echo htmlspecialchars(implode('', $recent));
        echo "</pre>";
    }
}
