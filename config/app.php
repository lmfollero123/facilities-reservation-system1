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

// Set timezone to Philippines (Asia/Manila - UTC+8)
date_default_timezone_set('Asia/Manila');

if (!function_exists('base_path')) {
    /**
     * Returns the base path of the app relative to the web root.
     *
     * Examples:
     * - At document root (lgu.test, localhost/): returns ''
     * - In subdirectory (localhost/facilities_reservation_system1/): returns '/facilities_reservation_system1'
     * - If URL contains /resources/: extracts base from script path
     */
    function base_path(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $pos = strpos($script, '/resources/');
        if ($pos !== false) {
            $base = substr($script, 0, $pos);
            return rtrim($base, '/');
        }
        // Front-controller (index.php): use script directory for subdirectory support
        $dir = dirname($script);
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            return '';
        }
        return $dir;
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

if (!function_exists('base_url')) {
    /**
     * Returns the full base URL (protocol + domain + base path).
     * Use this for generating absolute URLs in emails or external links.
     *
     * @return string Full base URL (e.g., "http://lgu.test" or "https://example.com/app")
     */
    function base_url(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Use HTTP for localhost/lgu.test, HTTPS detection can be unreliable
        $isLocal = (strpos($host, 'localhost') !== false || 
                   strpos($host, '127.0.0.1') !== false || 
                   strpos($host, 'lgu.test') !== false);
        $protocol = $isLocal ? 'http' : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $base = base_path();
        return $protocol . '://' . $host . $base;
    }
}