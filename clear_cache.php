<?php
// Clear PHP opcache to force reload of updated files

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache cleared successfully!\n";
} else {
    echo "ℹ️ OPcache is not enabled.\n";
}

// Clear file stat cache
clearstatcache(true);
echo "✅ File stat cache cleared!\n";

echo "\n🔄 Please refresh your browser (Ctrl+Shift+R or Cmd+Shift+R) to see changes.\n";
?>
