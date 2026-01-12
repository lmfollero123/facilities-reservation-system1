<?php
/**
 * Root entry point for the Facilities Reservation System
 * Routes requests to appropriate pages
 */

// Get the requested path
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$basePath = str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_NAME']);
$basePath = trim($basePath, '/');

// Remove base path from requested path if present
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = trim($path, '/');

// Route the request
if ($path === 'announcements') {
    require_once __DIR__ . '/resources/views/pages/public/announcements.php';
} else {
    // Default to home page
    require_once __DIR__ . '/resources/views/pages/public/home.php';
}
?>
