<?php
/**
 * Root entry point for the Facilities Reservation System
 * Routes requests to appropriate pages
 */

// Get the requested path FIRST (before loading app.php to avoid session/header issues)
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = trim(parse_url($requestUri, PHP_URL_PATH), '/');
$basePath = str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_NAME'] ?? '');
$basePath = trim($basePath, '/');

// Remove base path from requested path if present
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = trim($path, '/');

// Route the request - API routes FIRST (before loading app.php)
// Check for API route - handle various path formats
$apiPath = ltrim($path, '/');
$isApiRoute = (
    $apiPath === 'api/public/availability' || 
    strpos($apiPath, 'api/public/availability') === 0 ||
    $path === 'api/public/availability' ||
    strpos($path, 'api/public/availability') === 0 ||
    strpos($path, 'api/public/availability') !== false
);

if ($isApiRoute) {
    // Public API endpoint for facility availability
    // Don't load app.php to avoid session/header issues
    require_once __DIR__ . '/resources/views/pages/public/api/availability.php';
    exit;
}

$isIntegrationsApi = (
    $apiPath === 'api/integrations' ||
    strpos($apiPath, 'api/integrations/') === 0
);
if ($isIntegrationsApi) {
    require_once __DIR__ . '/resources/views/pages/public/api/integrations_not_implemented.php';
    exit;
}

// Load app configuration (includes base_path function) - AFTER API routes
require_once __DIR__ . '/config/app.php';

// Route the request
if ($path === 'announcements') {
    require_once __DIR__ . '/resources/views/pages/public/announcements.php';
} elseif ($path === 'faq') {
    header('Location: ' . base_path() . '/faqs', true, 301);
    exit;
} elseif ($path === 'faqs') {
    require_once __DIR__ . '/resources/views/pages/public/faq.php';
} elseif ($path === 'contact') {
    require_once __DIR__ . '/resources/views/pages/public/contact.php';
} elseif ($path === 'contact-handler') {
    require_once __DIR__ . '/resources/views/pages/public/contact_handler.php';
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
} elseif ($path === 'login-otp') {
    require_once __DIR__ . '/resources/views/pages/auth/login_otp.php';
} elseif ($path === 'login-setup-2fa') {
    require_once __DIR__ . '/resources/views/pages/auth/login_setup_2fa.php';
} elseif ($path === 'verify-email') {
    require_once __DIR__ . '/resources/views/pages/auth/verify_email.php';
} elseif ($path === 'logout') {
    require_once __DIR__ . '/resources/views/pages/auth/logout.php';
} elseif ($path === 'reset-password') {
    require_once __DIR__ . '/resources/views/pages/auth/reset_password.php';
} elseif ($path === 'privacy') {
    require_once __DIR__ . '/resources/views/pages/public/privacy.php';
} elseif ($path === 'terms') {
    require_once __DIR__ . '/resources/views/pages/public/terms.php';
} elseif ($path === 'legal') {
    require_once __DIR__ . '/resources/views/pages/public/legal.php';
} elseif ($path === 'paymongo-webhook') {
    require_once __DIR__ . '/resources/views/pages/public/api/paymongo_webhook.php';
} elseif ($path === 'payment-return') {
    require_once __DIR__ . '/resources/views/pages/public/payment_return.php';
} elseif ($path === 'dashboard' || strpos($path, 'dashboard/') === 0) {
    // Extract dashboard sub-path early (used for auth + routing)
    $dashboardPath = str_replace('dashboard/', '', $path);
    $dashboardPath = str_replace('dashboard', '', $dashboardPath);
    $dashboardPath = trim($dashboardPath, '/');
    if (strpos($dashboardPath, '?') !== false) {
        $parts = explode('?', $dashboardPath);
        $dashboardPath = $parts[0];
    }

    // JSON POST endpoints should not redirect to login HTML (breaks fetch().json())
    $dashboardJsonPostRoutes = [
        'ai-chatbot',
        'chatbot-api',
        'facility-details-api',
        'facility-recommendations',
        'booking-smart-hints',
        'ai-conflict-check',
        'ai-recommendations-api',
        'notifications-api',
        'geocode-api',
        'occupancy-live',
        'session-keepalive',
    ];

    // Dashboard routes - require full authenticated session
    if (!frs_dashboard_is_authenticated()) {
        $isJsonApiPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && in_array($dashboardPath, $dashboardJsonPostRoutes, true);
        if ($isJsonApiPost) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
            echo json_encode([
                'reply' => 'Your session has expired. Please refresh the page and log in again.',
                'error' => 'session_expired',
            ]);
            exit;
        }

        // Use HTTP for localhost/lgu.test, HTTPS detection can be unreliable
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $isLocal = (strpos($host, 'localhost') !== false || 
                   strpos($host, '127.0.0.1') !== false || 
                   strpos($host, 'lgu.test') !== false);
        $protocol = $isLocal ? 'http' : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $redirectUrl = $protocol . '://' . $host . base_path() . '/login';
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
        'time-tracking' => 'time_tracking.php',
        'maintenance-integration' => 'maintenance_integration.php',
        'infrastructure-projects' => 'infrastructure_projects_integration.php',
        'utilities-integration' => 'utilities_integration.php',
        'calendar-export' => 'calendar_export_ics.php',
        'reports' => 'reports.php',
        'user-management' => 'user_management.php',
        'document-management' => 'document_management.php',
        'contact-info' => 'contact_info_manage.php',
        'audit-trail' => 'audit_trail.php',
        'profile' => 'profile.php',
        'calendar' => 'calendar.php',
        'notifications' => 'notifications.php',
        'reservation-detail' => 'reservation_detail.php',
        'session-keepalive' => 'session_keepalive.php',
        'pay-now' => 'pay_now.php',
        'facility-recommendations' => 'facility_recommendations_api.php',
        'booking-smart-hints' => 'booking_smart_hints_api.php',
        'occupancy-monitor' => 'occupancy_monitor.php',
        'occupancy-live' => 'occupancy_live_api.php',
        'check-in' => 'check_in_gate.php',
        'facility-check-in' => 'facility_check_in_gate.php',
        'facility-qr-print' => 'facility_qr_print.php',
        'blackout-dates' => 'blackout_dates.php',
        'ai-conflict-check' => 'ai_conflict_check.php',
        'ai-recommendations-api' => 'ai_recommendations_api.php',
        'notifications-api' => 'notifications_api.php',
        'facility-details-api' => 'facility-details-api.php',
        'geocode-api' => 'geocode_api.php',
        'chatbot-api' => 'chatbot_api.php',
        'ai-chatbot' => 'ai_chatbot.php',
        'export-audit-trail' => 'export_audit_trail.php',
        'download-document' => 'download_document.php',
        'download-reservation-document' => 'download_reservation_document.php',
        'download-export' => 'download_export.php',
        'contact-inquiries' => 'contact_inquiries.php',
        'sms-test' => 'sms_test.php',
    ];
    
    // Extract dashboard path
    // (already computed above for auth checks)
    
    // Handle query strings (e.g., reservation-detail?id=123) — path only, query stays in $_GET
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
