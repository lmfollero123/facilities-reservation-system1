<?php
/**
 * Simple PDO database configuration for authentication & roles.
 *
 * Update these values to match your XAMPP/MySQL setup.
 * Recommended DB name: facilities_reservation
 */

//  const DB_HOST = 'localhost';
//  const DB_NAME = 'cprf_facilities_reservation';
//  const DB_USER = 'cprf_root';        // default XAMPP user
//  const DB_PASS = '#Ej9+LqgMpteCp17';


 const DB_HOST = 'localhost';
 const DB_NAME = 'facilities_reservation';
 const DB_USER = 'root';       // default XAMPP user
 const DB_PASS = '';
/**
 * Returns a shared PDO instance.
 */
function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
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
                    "ALTER USER '" . DB_USER . "'@'localhost' IDENTIFIED WITH mysql_native_password BY '" . DB_PASS . "'; " .
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




