<?php
// Test if index.php path resolution works
echo "Testing index.php logic...<br>";

$homePath = __DIR__ . '/resources/views/pages/public/home.php';
echo "Home path: " . $homePath . "<br>";

if (file_exists($homePath)) {
    echo "✅ home.php file exists!<br>";
    echo "Trying to include it...<br>";
    
    // Check if config files exist
    $configPath = __DIR__ . '/config/app.php';
    if (file_exists($configPath)) {
        echo "✅ config/app.php exists!<br>";
    } else {
        echo "❌ config/app.php NOT found!<br>";
    }
    
    // Try to require config first
    try {
        require_once __DIR__ . '/config/app.php';
        echo "✅ config/app.php loaded successfully!<br>";
    } catch (Exception $e) {
        echo "❌ Error loading config: " . $e->getMessage() . "<br>";
    }
    
} else {
    echo "❌ home.php file NOT found at: " . $homePath . "<br>";
    echo "Current directory: " . __DIR__ . "<br>";
}
