<?php
/**
 * Local dev router for PHP's built-in server:
 *
 *     php -S localhost:8080 router.php
 *
 * Mirrors the .htaccess rule — serve real files (CSS/JS/images) directly,
 * route everything else to index.php for clean-URL handling.
 *
 * DEV ONLY. Production (Apache / OpenLiteSpeed) uses .htaccess and ignores this.
 * Safe to delete anytime.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $path;

// Let the built-in server serve existing static assets as-is.
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
