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

if (!function_exists('frs_private_env_candidates')) {
    /**
     * Possible locations for ~/private/cprf.env (cPanel and local dev).
     *
     * @return list<string>
     */
    function frs_private_env_candidates(): array
    {
        $candidates = [];
        $projectRoot = dirname(__DIR__);
        $accountHome = dirname($projectRoot);
        if ($accountHome !== '' && $accountHome !== '/' && $accountHome !== '\\' && $accountHome !== '.') {
            $candidates[] = $accountHome . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'cprf.env';
        }
        foreach (['HOME', 'USERPROFILE'] as $key) {
            $fromServer = $_SERVER[$key] ?? '';
            if (is_string($fromServer) && $fromServer !== '') {
                $candidates[] = rtrim($fromServer, '/\\') . '/private/cprf.env';
            }
            $fromEnv = getenv($key);
            if (is_string($fromEnv) && $fromEnv !== '') {
                $candidates[] = rtrim($fromEnv, '/\\') . '/private/cprf.env';
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}

// Single env bootstrap — project .env first, then private/cprf.env overrides (production secrets).
if (!defined('CPRF_ENV_BOOTSTRAPPED')) {
    define('CPRF_ENV_BOOTSTRAPPED', true);
    $explicitEnv = trim((string)env_value('CPRF_ENV_PATH', '', false));
    if ($explicitEnv !== '' && is_file($explicitEnv)) {
        load_env_file($explicitEnv, true);
    } else {
        $projectEnv = dirname(__DIR__) . '/.env';
        if (is_file($projectEnv)) {
            load_env_file($projectEnv, false);
        }
        foreach (frs_private_env_candidates() as $privateEnv) {
            if (is_file($privateEnv)) {
                load_env_file($privateEnv, true);
                break;
            }
        }
    }
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
        $appEnv = strtolower(trim((string)env_value('APP_ENV', 'production')));
        $isLocalEnv = in_array($appEnv, ['local', 'development', 'dev'], true);

        // Optional explicit local override (e.g. ngrok tunnel for mobile/email testing)
        if ($isLocalEnv) {
            $localUrl = trim((string)env_value('APP_URL_LOCAL', ''));
            if ($localUrl !== '') {
                return rtrim($localUrl, '/');
            }
        }

        $configured = trim((string)env_value('APP_URL', ''));
        if ($configured !== '' && !$isLocalEnv) {
            return rtrim($configured, '/');
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $isLocal = (strpos($host, 'localhost') !== false ||
                   strpos($host, '127.0.0.1') !== false ||
                   strpos($host, 'lgu.test') !== false);

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $protocol = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $protocol = 'https';
        } elseif ($isLocal) {
            $protocol = 'http';
        } else {
            // Production default: HTTPS (required for PayMongo redirect URL validation).
            $protocol = 'https';
        }

        $base = base_path();
        return $protocol . '://' . $host . $base;
    }
}