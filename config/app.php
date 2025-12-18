<?php
/**
 * Application-wide helpers (URLs, paths, etc).
 */

// Load security configuration
require_once __DIR__ . '/security.php';

// Set security headers
setSecurityHeaders();

// Secure session
secureSession();

if (!function_exists('base_path')) {
    /**
     * Returns the base path of the app relative to the web root.
     *
     * Examples:
     * - If URL is /resources/views/pages/public/home.php   → returns ''
     * - If URL is /facilities-reservation-system/facilities_reservation_system/resources/... → returns '/facilities-reservation-system/facilities_reservation_system'
     */
    function base_path(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $pos = strpos($script, '/resources/');
        if ($pos === false) {
            return '';
        }
        $base = substr($script, 0, $pos);
        return rtrim($base, '/');
    }
}

if (!function_exists('app_root_path')) {
    /**
     * Returns the absolute filesystem root path of the application.
     *
     * Use this for filesystem operations (is_dir, mkdir, move_uploaded_file, etc.).
     * base_path() is URL-relative and should only be used for generating links.
     */
    function app_root_path(): string
    {
        // config/app.php lives in the /config directory at the project root
        return dirname(__DIR__);
    }
}

