<?php
/**
 * Root entry point for the Facilities Reservation System
 * Routes requests to appropriate pages
 */

// Get the requested path
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$basePath = str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_NAME']);
$basePath = trim($basePath, '/');

// Remove base path from requested path if present
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = trim($path, '/');

// Route the request
if ($path === 'announcements') {
    require_once __DIR__ . '/resources/views/pages/public/announcements.php';
} elseif ($path === 'faqs' || $path === 'faq') {
    require_once __DIR__ . '/resources/views/pages/public/faq.php';
} elseif ($path === 'contact') {
    require_once __DIR__ . '/resources/views/pages/public/contact.php';
} elseif ($path === 'facilities') {
    require_once __DIR__ . '/resources/views/pages/public/facilities.php';
} elseif ($path === 'facility-details') {
    require_once __DIR__ . '/resources/views/pages/public/facility_details.php';
} elseif ($path === 'login') {
    require_once __DIR__ . '/resources/views/pages/auth/login.php';
} elseif ($path === 'register') {
    require_once __DIR__ . '/resources/views/pages/auth/register.php';
} elseif ($path === 'forgot-password') {
    require_once __DIR__ . '/resources/views/pages/auth/forgot_password.php';
} elseif ($path === 'privacy') {
    require_once __DIR__ . '/resources/views/pages/public/privacy.php';
} elseif ($path === 'dashboard' || strpos($path, 'dashboard/') === 0) {
    // Dashboard routes - check if user is logged in
    session_start();
    if (!isset($_SESSION['user_id'])) {
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $redirectUrl = $baseUrl . base_path() . '/login';
        header('Location: ' . $redirectUrl);
        exit;
    }
    
    // Map clean URLs to dashboard file names
    $dashboardRouteMap = [
        '' => 'index.php',
        'book-facility' => 'book_facility.php',
        'my-reservations' => 'my_reservations.php',
        'ai-scheduling' => 'ai_scheduling.php',
        'reservations-manage' => 'reservations_manage.php',
        'announcements-manage' => 'announcements_manage.php',
        'facility-management' => 'facility_management.php',
        'maintenance-integration' => 'maintenance_integration.php',
        'infrastructure-projects' => 'infrastructure_projects_integration.php',
        'utilities-integration' => 'utilities_integration.php',
        'reports' => 'reports.php',
        'user-management' => 'user_management.php',
        'document-management' => 'document_management.php',
        'contact-info' => 'contact_info_manage.php',
        'audit-trail' => 'audit_trail.php',
        'profile' => 'profile.php',
        'calendar' => 'calendar.php',
        'notifications' => 'notifications.php',
        'reservation-detail' => 'reservation_detail.php',
    ];
    
    // Extract dashboard path
    $dashboardPath = str_replace('dashboard/', '', $path);
    $dashboardPath = str_replace('dashboard', '', $dashboardPath);
    $dashboardPath = trim($dashboardPath, '/');
    
    // Handle query strings (e.g., reservation-detail?id=123)
    if (strpos($dashboardPath, '?') !== false) {
        $parts = explode('?', $dashboardPath);
        $dashboardPath = $parts[0];
    }
    
    // Get the file from route map
    if (isset($dashboardRouteMap[$dashboardPath])) {
        $dashboardFile = $dashboardRouteMap[$dashboardPath];
    } else {
        // Fallback: try to convert kebab-case to snake_case
        $dashboardFile = str_replace('-', '_', $dashboardPath) . '.php';
    }
    
    $fullPath = __DIR__ . '/resources/views/pages/dashboard/' . $dashboardFile;
    
    // If file exists, require it; otherwise default to index
    if (file_exists($fullPath)) {
        require_once $fullPath;
    } else {
        require_once __DIR__ . '/resources/views/pages/dashboard/index.php';
    }
} else {
    // Default to home page
    require_once __DIR__ . '/resources/views/pages/public/home.php';
}
?>
