<?php
/**
 * Simple PDO database configuration for authentication & roles.
 *
 * Update these values to match your XAMPP/MySQL setup.
 * Recommended DB name: facilities_reservation
 */

 const DB_HOST = 'localhost';
 const DB_NAME = 'cprf_facilities_reservation';
 const DB_USER = 'cprf_root';        // default XAMPP user
 const DB_PASS = '#Ej9+LqgMpteCp17'; 
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
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}




