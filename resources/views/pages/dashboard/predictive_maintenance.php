<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'maintenance')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

header('Location: ' . base_path() . '/dashboard/maintenance-integration?tab=insights', true, 302);
exit;
