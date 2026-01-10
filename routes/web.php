<?php
/**
 * Placeholder route map for the Facilities Reservation System.
 * Replace this file with your framework/router of choice.
 */

return [
    'public' => [
        '/' => 'resources/views/pages/public/home.php',
        '/facilities' => 'resources/views/pages/public/facilities.php',
        '/facility/{id}' => 'resources/views/pages/public/facility_details.php',
        '/terms' => 'resources/views/pages/public/terms.php',
        '/privacy' => 'resources/views/pages/public/privacy.php',
        '/legal' => 'resources/views/pages/public/legal.php',
        '/contact' => 'resources/views/pages/public/contact.php',
        '/login' => 'resources/views/pages/auth/login.php',
        '/register' => 'resources/views/pages/auth/register.php',
    ],
    'dashboard' => [
        '/dashboard' => 'resources/views/pages/dashboard/index.php',
        '/dashboard/book' => 'resources/views/pages/dashboard/book_facility.php',
        '/dashboard/reservations' => 'resources/views/pages/dashboard/my_reservations.php',
        '/dashboard/reservations/manage' => 'resources/views/pages/dashboard/reservations_manage.php',
        '/dashboard/reservations/detail' => 'resources/views/pages/dashboard/reservation_detail.php',
        '/dashboard/facilities' => 'resources/views/pages/dashboard/facility_management.php',
        '/dashboard/maintenance' => 'resources/views/pages/dashboard/maintenance_integration.php',
        '/dashboard/infrastructure-projects' => 'resources/views/pages/dashboard/infrastructure_projects_integration.php',
        '/dashboard/utilities' => 'resources/views/pages/dashboard/utilities_integration.php',
        '/dashboard/calendar' => 'resources/views/pages/dashboard/calendar.php',
        '/dashboard/reports' => 'resources/views/pages/dashboard/reports.php',
        '/dashboard/ai' => 'resources/views/pages/dashboard/ai_scheduling.php',
        '/dashboard/ai-chatbot' => 'resources/views/pages/dashboard/ai_chatbot.php',
        '/dashboard/notifications' => 'resources/views/pages/dashboard/notifications.php',
        '/dashboard/users' => 'resources/views/pages/dashboard/user_management.php',
        '/dashboard/audit' => 'resources/views/pages/dashboard/audit_trail.php',
        '/dashboard/profile' => 'resources/views/pages/dashboard/profile.php',
    ],
];

