<?php
// config/db.php

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";          // default XAMPP password
$DB_NAME = "lgu_portal";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>