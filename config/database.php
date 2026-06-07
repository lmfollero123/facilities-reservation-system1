<?php
/**
 * Simple PDO database configuration for authentication & roles.
 *
 * Update these values to match your XAMPP/MySQL setup.
 * Recommended DB name: facilities_reservation
 */

if (!function_exists('load_local_env_for_db')) {
    function load_local_env_for_db(string $path): void
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
            if (getenv($key) !== false && getenv($key) !== '') {
                continue;
            }
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

load_local_env_for_db(dirname(__DIR__) . '/.env');
if (!is_file(dirname(__DIR__) . '/.env') && !empty($_SERVER['HOME'])) {
    load_local_env_for_db(rtrim((string) $_SERVER['HOME'], '/\\') . '/private/cprf.env');
}

const DB_HOST = 'localhost';
const DB_NAME = 'facilities_reservation';
const DB_USER = 'root'; // default XAMPP user
const DB_PASS = '';
// /**
//  * Returns a shared PDO instance.
//  */
function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $dbHost = trim((string)(getenv('DB_HOST') !== false ? getenv('DB_HOST') : DB_HOST));
        $dbName = trim((string)(getenv('DB_NAME') !== false ? getenv('DB_NAME') : DB_NAME));
        $dbUser = (string)(getenv('DB_USER') !== false ? getenv('DB_USER') : DB_USER);
        $dbPass = (string)(getenv('DB_PASS') !== false ? getenv('DB_PASS') : DB_PASS);

        $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
        
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
            
            // Set MySQL timezone to Philippines (UTC+8)
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            // Handle authentication method errors
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'authentication method') !== false || 
                strpos($errorMsg, 'auth_gssapi_client') !== false ||
                strpos($errorMsg, 'caching_sha2_password') !== false) {
                
                error_log('MySQL authentication error: ' . $errorMsg);
                throw new RuntimeException(
                    'Database authentication error. ' .
                    'Please run this SQL command in MySQL: ' .
                    "ALTER USER '" . $dbUser . "'@'localhost' IDENTIFIED WITH mysql_native_password BY '" . $dbPass . "'; " .
                    "Then run: FLUSH PRIVILEGES; " .
                    'See docs/DATABASE_AUTH_FIX.md for detailed instructions.'
                );
            } else {
                throw $e;
            }
        }
    }

    return $pdo;
}




