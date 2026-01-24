<?php
/**
 * Routing Test Script
 * Use this to debug routing issues
 * Access: http://lgu.test/test_routing.php
 */

echo "<h1>Routing Debug Information</h1>";
echo "<pre>";

echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'NOT SET') . "\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'NOT SET') . "\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'NOT SET') . "\n";
echo "\n";

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
echo "Parsed Path: '$path'\n";

$basePath = str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_NAME'] ?? '');
$basePath = trim($basePath, '/');
echo "Base Path: '$basePath'\n";

if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = trim($path, '/');
echo "Final Path: '$path'\n";

echo "\n";
echo "Current File Location: " . __FILE__ . "\n";
echo "Project Root (index.php location): " . dirname(__FILE__) . "\n";
echo "index.php exists: " . (file_exists(dirname(__FILE__) . '/index.php') ? 'YES' : 'NO') . "\n";
echo ".htaccess exists: " . (file_exists(dirname(__FILE__) . '/.htaccess') ? 'YES' : 'NO') . "\n";

echo "\n";
echo "Testing route: dashboard/book-facility\n";
if ($path === 'dashboard' || strpos($path, 'dashboard/') === 0) {
    echo "✓ Matches dashboard route\n";
    $dashboardPath = str_replace('dashboard/', '', $path);
    $dashboardPath = str_replace('dashboard', '', $dashboardPath);
    $dashboardPath = trim($dashboardPath, '/');
    echo "Dashboard Path: '$dashboardPath'\n";
    
    if ($dashboardPath === 'book-facility') {
        echo "✓ Matches book-facility route\n";
        $targetFile = __DIR__ . '/resources/views/pages/dashboard/book_facility.php';
        echo "Target File: $targetFile\n";
        echo "Target File Exists: " . (file_exists($targetFile) ? 'YES' : 'NO') . "\n";
    }
}

echo "</pre>";
