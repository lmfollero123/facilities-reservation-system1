<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$actorRole = $_SESSION['role'] ?? '';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($actorRole, 'users')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/violations.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/ui_helpers.php';

$pdo = db();
$currentUserId = $_SESSION['user_id'] ?? null;
$viewedUserId = (int)($_GET['user_id'] ?? 0);

if ($viewedUserId <= 0) {
    header('Location: ' . base_path() . '/dashboard/user-management');
    exit;
}

$userStmt = $pdo->prepare('SELECT id, name, email, mobile, address, role, status, lock_reason, is_verified, verified_at, profile_picture, created_at FROM users WHERE id = :id');
$userStmt->execute(['id' => $viewedUserId]);
$viewedUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$viewedUser) {
    header('Location: ' . base_path() . '/dashboard/user-management');
    exit;
}

$isSelfView = ($currentUserId !== null && (int)$currentUserId === $viewedUserId);

if (!$isSelfView) {
    logAudit('Viewed resident profile', 'User Management', $viewedUser['name'] . ' (' . $viewedUser['email'] . ')');
}

$message = '';
$messageType = 'success';
