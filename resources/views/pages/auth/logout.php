<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

session_unset();
session_destroy();

header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
exit;




