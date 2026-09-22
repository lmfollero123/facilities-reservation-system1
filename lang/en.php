<?php
/**
 * English strings (default locale). Keys are grouped by page/component
 * prefix (nav.*, footer.*, home.*, ...) so it's obvious where each string
 * renders. Every key here MUST also exist in lang/tl.php.
 */
declare(strict_types=1);

return [
    // Shared navbar (resources/views/components/navbar_guest.php)
    'nav.home' => 'Home',
    'nav.facilities' => 'Facilities',
    'nav.announcements' => 'Announcements',
    'nav.faq' => 'FAQ',
    'nav.contact' => 'Contact',
    'nav.login' => 'Login',
    'nav.register' => 'Register',
    'nav.menu' => 'Menu',
    'nav.close_menu' => 'Close menu',
    'nav.dark_mode' => 'Dark Mode',
    'nav.toggle_dark_mode' => 'Toggle dark mode',

    // Shared footer (resources/views/components/footer.php)
    'footer.brand_title' => 'Barangay Culiat',
    'footer.brand_subtitle' => 'Public Facilities Reservation System',
    'footer.brand_description' => 'Simplifying facility reservations for our community with secure, efficient, and transparent booking services.',
    'footer.quick_links' => 'Quick Links',
    'footer.link_home' => 'Home',
    'footer.link_browse_facilities' => 'Browse Facilities',
    'footer.link_announcements' => 'Announcements',
    'footer.link_faqs' => 'FAQs',
    'footer.link_contact' => 'Contact Us',
    'footer.link_my_reservations' => 'My Reservations',
    'footer.location' => 'Location',
    'footer.location_label' => 'Barangay Culiat, Quezon City, Philippines',
    'footer.legal_compliance' => 'Legal & Compliance',
    'footer.link_privacy' => 'Privacy Policy',
    'footer.link_terms' => 'Terms & Conditions',
    'footer.link_legal' => 'Legal Notice',
    'footer.compliance_badge' => 'Compliant with RA 10173<br>(Data Privacy Act of 2012)',
    'footer.copyright' => '&copy; :year Barangay Culiat, Quezon City. All rights reserved.',
    'footer.tagline' => 'Serving our community with transparency and efficiency',

    // Dashboard top navbar (resources/views/components/navbar_dashboard.php)
    'nav.logout' => 'Logout',
    'nav.logout_confirm' => 'Are you sure you want to log out?',
    'dashnav.toggle_sidebar' => 'Toggle Sidebar',
    'dashnav.search' => 'Search dashboard',
    'dashnav.search_placeholder' => 'Search dashboard (e.g. booking, maintenance)...',
    'dashnav.notifications' => 'Notifications',
    'dashnav.mark_all_read' => 'Mark all read',
    'dashnav.mark_all_read_title' => 'Mark all as read',
    'dashnav.view_all' => 'View All',
    'dashnav.loading' => 'Loading...',
    'dashnav.no_matches' => 'No matches',
    'dashnav.toggle_dark_mode' => 'Toggle Dark Mode',

    // Dashboard sidebar groups (resources/views/components/sidebar_dashboard.php)
    'sidebar.brand' => 'Facilities',
    'sidebar.close' => 'Close sidebar',
    'sidebar.nav_label' => 'Dashboard navigation',
    'sidebar.main' => 'Main',
    'sidebar.booking' => 'Booking',
    'sidebar.reservations_facilities' => 'Reservations & Facilities',
    'sidebar.communications' => 'Communications',
    'sidebar.operations' => 'Operations',
    'sidebar.administration' => 'Administration',
    'sidebar.account' => 'Account',

    // Dashboard sidebar links
    'sidebar.dashboard' => 'Dashboard',
    'sidebar.book_facility' => 'Book a Facility',
    'sidebar.my_reservations' => 'My Reservations',
    'sidebar.check_in_out' => 'Check In/Out',
    'sidebar.smart_scheduler' => 'Smart Scheduler',
    'sidebar.reservation_approvals' => 'Reservation Approvals',
    'sidebar.staff_scheduling' => 'Staff Scheduling',
    'sidebar.facility_management' => 'Facility Management',
    'sidebar.occupancy_waivers' => 'Occupancy & Waivers',
    'sidebar.announcements' => 'Announcements',
    'sidebar.contact_management' => 'Contact Management',
    'sidebar.maintenance_management' => 'Maintenance Management',
    'sidebar.infrastructure_projects' => 'Infrastructure Projects',
    'sidebar.utilities_management' => 'Utilities and Equipments Management',
    'sidebar.energy_efficiency' => 'Energy Savings and Recommendations',
    'sidebar.reports_analytics' => 'Reports & Analytics',
    'sidebar.user_management' => 'User Management',
    'sidebar.system_settings' => 'System Settings',
    'sidebar.document_management' => 'Document Management',
    'sidebar.audit_trail' => 'Audit Trail',
    'sidebar.ai_model_lab' => 'AI Model Lab',
    'sidebar.profile' => 'Profile',

    // Shared status labels
    'status.pending' => 'Pending',
    'status.approved' => 'Approved',
    'status.denied' => 'Denied',
    'status.cancelled' => 'Cancelled',
    'status.completed' => 'Completed',
    'status.rescheduled' => 'Rescheduled',

    // Shared buttons / common UI
    'common.save' => 'Save',
    'common.cancel' => 'Cancel',
    'common.submit' => 'Submit',
    'common.search' => 'Search',
    'common.filter' => 'Filter',
    'common.close' => 'Close',
    'common.back' => 'Back',
    'common.confirm' => 'Confirm',
    'common.delete' => 'Delete',
    'common.edit' => 'Edit',
    'common.view' => 'View',
    'common.loading' => 'Loading...',
    'common.date' => 'Date',
    'common.time' => 'Time',
    'common.status' => 'Status',
    'common.actions' => 'Actions',
    'common.facility' => 'Facility',

    // Announcement category badges (config/announcement_categories.php)
    'category.emergency' => 'Emergency',
    'category.urgent' => 'Urgent',
    'category.event' => 'Event',
    'category.health' => 'Health',
    'category.deadline' => 'Deadline',
    'category.advisory' => 'Advisory',
    'category.general' => 'General',
];
