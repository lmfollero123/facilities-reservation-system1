<?php
/**
 * Tagalog strings for the public home, facilities directory and facility
 * details pages. Mirrors lang/en/public_home.php key for key.
 */
declare(strict_types=1);

return [
    // ---------------------------------------------------------------
    // Home page (resources/views/pages/public/home.php)
    // ---------------------------------------------------------------
    'home.page_title' => 'Home | Sistema ng Reserbasyon ng Pasilidad ng Barangay Culiat',
    'home.hero_aria' => 'Portal ng reserbasyon ng pasilidad ng Barangay Culiat',
    'home.hero_eyebrow' => 'District 6, Quezon City · Portal ng Reserbasyon ng Pampublikong Pasilidad',
    'home.hero_title' => 'Barangay Culiat',
    'home.hero_lead' => 'Malugod na pagbati! Ang Sistema ng Reserbasyon ng Pasilidad ng Barangay Culiat ay dinisenyo upang mapadali ang pag-book ng mga pampublikong pasilidad — mula sa covered court hanggang sa multi-purpose hall — nang mabilis, ligtas, at maayos. Sa pamamagitan ng aming online portal, maaari kang mag-book, subaybayan ang katayuan ng iyong reservation, at makatanggap ng abiso, lahat sa iisang lugar.',
    'home.hero_cta_browse' => 'Tingnan ang mga Pasilidad',
    'home.hero_cta_register' => 'Gumawa ng Account',

    'home.updates_aria' => 'Mga update sa komunidad',
    'home.updates_title' => 'Mga Update sa Komunidad',
    'home.updates_slide_toggle' => 'Slide',
    'home.updates_hint' => 'I-tap ang card para buksan ang seksyon',
    'home.announcement_fallback_title' => 'Anunsyo',

    'home.how_title' => 'Paano Ito Gumagana',
    'home.how_subtitle' => 'Ilang simpleng hakbang lang at reserved na ang pasilidad mo',
    'home.how_step1_title' => 'Gumawa ng Account',
    'home.how_step1_text' => 'Mag-sign up gamit ang iyong mga detalye at i-verify ang iyong email',
    'home.how_step2_title' => 'Tingnan ang mga Pasilidad',
    'home.how_step2_text' => 'Silipin ang mga available na pasilidad at ang mga tampok nito',
    'home.how_step3_title' => 'I-book ang Iyong Slot',
    'home.how_step3_text' => 'Piliin ang petsa at oras, pagkatapos ay i-submit ang iyong reservation',
    'home.how_step4_title' => 'Hintayin ang Approval',
    'home.how_step4_text' => 'Matatanggap mo ang kumpirmasyon at masisiyahan ka na sa iyong event',

    'home.announcements_title' => 'Mga Anunsyo at Update',
    'home.announcements_subtitle' => 'Pinakabagong abiso mula sa Barangay Culiat',
    'home.announcements_see_all' => 'Tingnan lahat',
    'home.announcements_view_all' => 'Tingnan ang Lahat ng Anunsyo',

    'home.featured_title' => 'Mga Tampok na Pasilidad',
    'home.featured_subtitle' => 'Silipin ang mga available naming pasilidad',
    'home.facility_no_description' => 'Tingnan ang detalye para sa karagdagang impormasyon',
    'home.view_details' => 'Tingnan ang Detalye',
    'home.status_available' => 'Available',
    'home.status_maintenance' => 'Nasa Maintenance',
    'home.status_offline' => 'Hindi Magamit',

    'home.info_title' => 'Mahalagang Impormasyon',
    'home.info_subtitle' => 'Alamin ang mga detalye tungkol sa aming serbisyo',

    'home.hours_title' => 'Oras ng Operasyon',
    'home.hours_weekdays' => 'Lunes - Biyernes:',
    'home.hours_saturday' => 'Sabado:',
    'home.hours_sunday' => 'Linggo at Pista Opisyal:',
    'home.hours_closed' => 'Sarado',

    'home.contact_title' => 'Makipag-ugnayan',
    'home.contact_phone' => 'Telepono:',
    'home.contact_mobile' => 'Mobile:',
    'home.contact_email' => 'Email:',
    'home.contact_address' => 'Address:',

    'home.facilities_list_title' => 'Mga Pasilidad na Available',

    'home.requirements_title' => 'Mga Kinakailangan',
    'home.req_valid_id' => 'Valid ID (Residente ng Barangay Culiat)',
    'home.req_verified_email' => 'Verified na Email Address',
    'home.req_complete_form' => 'Kumpleto ang Form ng Reservation',
    'home.req_approval' => 'Approval ng Barangay Official',

    'home.notice_label' => 'Paalala:',
    'home.notice_text' => 'Ang mga reservation ay dapat gawin nang hindi bababa sa 3 araw bago ang petsa ng event. Ang approval ay aabutin ng 1-2 business days. Para sa emergency reservations, mangyaring makipag-ugnayan sa aming opisina.',

    // ---------------------------------------------------------------
    // Facilities directory (resources/views/pages/public/facilities.php)
    // ---------------------------------------------------------------
    'facilities.page_title' => 'Mga Pasilidad | Reserbasyon ng Pasilidad ng LGU',
    'facilities.header_title' => 'Direktoryo ng mga Pasilidad',
    'facilities.header_tagline' => 'Tingnan at i-reserba ang mga pasilidad ng barangay. Isang click lang ang mga espasyo ng inyong komunidad.',
    'facilities.default_description' => 'Pasilidad ng LGU.',
    'facilities.empty_state' => 'Wala pa pong nakapaskil na pasilidad. Pakibalikan na lang po mamaya o makipag-ugnayan sa LGU Facilities Office.',
    'facilities.view_details' => 'Tingnan ang Detalye',
    'facilities.status_available' => 'Available',
    'facilities.status_maintenance' => 'Nasa Maintenance',
    'facilities.status_offline' => 'Hindi Magamit',

    // ---------------------------------------------------------------
    // Facility details (resources/views/pages/public/facility_details.php)
    // ---------------------------------------------------------------
    'facilitydetail.page_title' => ':name | Reserbasyon ng Pasilidad ng LGU',
    'facilitydetail.status_available' => 'Available',
    'facilitydetail.status_maintenance' => 'Nasa Maintenance',
    'facilitydetail.status_offline' => 'Hindi Magamit',
    'facilitydetail.location_label' => 'Lokasyon:',
    'facilitydetail.capacity_label' => 'Kapasidad:',
    'facilitydetail.default_description' => 'Pasilidad ng LGU na pwedeng i-reserba.',
    'facilitydetail.usage_title' => 'Paggamit',
    'facilitydetail.usage_free' => 'Libre',
    'facilitydetail.usage_note' => 'Libre po ang paggamit ng pasilidad na ito para sa publiko, bigay ng LGU/Barangay.',
    'facilitydetail.equipment_title' => 'Mga Kagamitan at Utilities',
    'facilitydetail.amenities_title' => 'Mga Amenity',
    'facilitydetail.rules_title' => 'Mga Patakaran at Regulasyon',
    'facilitydetail.availability_title' => 'Availability (Susunod na 14 na Araw)',
    'facilitydetail.availability_note' => 'Para makita ang buong availability at makapag-submit ng reservation request, mag-log in po at gamitin ang booking module.',
    'facilitydetail.notice_title' => 'Mahalagang Paalala',
    'facilitydetail.notice_policy_label' => 'Patakaran sa Emergency Override:',
    'facilitydetail.notice_text' => 'Sakaling may emergency (halimbawa: evacuation center, disaster response, o agarang pangangailangan ng LGU/Barangay), may karapatan ang LGU na i-override o kanselahin ang mga nakatakdang reservation. Agad na aabisuhan ang mga apektadong residente. Libre ang lahat ng pasilidad para sa paggamit ng publiko.',
    'facilitydetail.map_title' => 'Mapa ng Lokasyon ng Pasilidad',
];
