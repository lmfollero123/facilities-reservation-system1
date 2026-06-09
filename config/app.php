<?php
/**
 * Application-wide helpers (URLs, paths, etc).
 */

// Load security configuration
require_once __DIR__ . '/security.php';

// UI helpers (field tips, headings) — used by dashboard/public views before layouts load
require_once __DIR__ . '/ui_helpers.php';

// Set security headers
setSecurityHeaders();

// Secure session
secureSession();

// Set timezone to Philippines (Asia/Manila - UTC+8)
date_default_timezone_set('Asia/Manila');

if (!function_exists('load_env_file')) {
    /**
     * Minimal .env loader (KEY=VALUE), non-destructive by default.
     */
    function load_env_file(string $path, bool $override = false): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eqPos));
            $val = trim(substr($line, $eqPos + 1));
            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }
            if ($val !== '' && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }
            $alreadySet = getenv($key);
            if (!$override && $alreadySet !== false && $alreadySet !== '') {
                continue;
            }
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

if (!function_exists('env_value')) {
    /**
     * Read environment value with fallback and optional trim.
     */
    function env_value(string $key, $default = null, bool $trim = true)
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            $value = $_ENV[$key];
        } elseif (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            $value = $_SERVER[$key];
        } else {
            $value = getenv($key);
            if ($value === false) {
                return $default;
            }
        }
        if (!is_string($value)) {
            return $value;
        }
        return $trim ? trim($value) : $value;
    }
}

// Single env bootstrap — all entry points should require app.php (not ad-hoc .env loaders).
if (!defined('CPRF_ENV_BOOTSTRAPPED')) {
    define('CPRF_ENV_BOOTSTRAPPED', true);
    $envPath = env_value('CPRF_ENV_PATH', '', false);
    if ($envPath === '' || !is_file($envPath)) {
        $envPath = dirname(__DIR__) . '/.env';
    }
    if (!is_file($envPath) && !empty($_SERVER['HOME'])) {
        $privateEnv = rtrim((string) $_SERVER['HOME'], '/\\') . '/private/cprf.env';
        if (is_file($privateEnv)) {
            $envPath = $privateEnv;
        }
    }
    load_env_file($envPath, true);
}

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