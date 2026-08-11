<?php
$useTailwind = true; // Enable Tailwind CSS for home page
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/announcement_categories.php';
$pageTitle = 'Home | Barangay Culiat Public Facilities Reservation System';

$pdo = db();
$base = base_path();

// Load featured facilities for portfolio gallery
$featuredStmt = $pdo->query(
    "SELECT id, name, description, status, image_path
     FROM facilities
     WHERE status != 'deleted'
     ORDER BY created_at DESC
     LIMIT 6"
);
$featuredFacilities = $featuredStmt->fetchAll(PDO::FETCH_ASSOC);

// Public announcements (system-wide notifications with NULL user_id)
$announcementsStmt = $pdo->prepare(
    'SELECT id, title, message, type, link, image_path, created_at 
     FROM notifications 
     WHERE user_id IS NULL 
     ORDER BY created_at DESC 
     LIMIT 5'
);
$announcementsStmt->execute();
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Default fallback image
$defaultImage = $base . '/public/img/cityhall.jpeg';

$heroImage = $base . '/public/img/756975060_2502390913534018_3600429775912430720_n.jpg';

ob_start();
?>

<!-- Hero Section - static photo, serif title, Tagalog copy -->
<section class="reservation-hero" aria-label="Barangay Culiat facilities reservation portal">
    <div class="reservation-hero-bg" style="background-image:url('<?= htmlspecialchars($heroImage); ?>');"></div>
    <div class="reservation-hero-gradient"></div>

    <div class="reservation-hero-content">
        <p class="reservation-hero-eyebrow">District 6, Quezon City &middot; Public Facilities Reservation Portal</p>
        <h1 class="reservation-hero-title">Barangay Culiat</h1>
        <div class="reservation-hero-rule"></div>
        <p class="reservation-hero-lead">
            Malugod na pagbati! Ang Sistema ng Reserbasyon ng Pasilidad ng Barangay Culiat ay dinisenyo upang mapadali ang pag-book ng mga pampublikong pasilidad &mdash; mula sa covered court hanggang sa multi-purpose hall &mdash; nang mabilis, ligtas, at maayos. Sa pamamagitan ng aming online portal, maaari kang mag-book, subaybayan ang katayuan ng iyong reservation, at makatanggap ng abiso, lahat sa iisang lugar.
        </p>
        <div class="reservation-hero-ctas">
            <a href="<?= $base; ?>/facilities" class="reservation-hero-cta reservation-hero-cta-solid">Browse Facilities</a>
            <a href="<?= $base; ?>/register" class="reservation-hero-cta reservation-hero-cta-outline">Create Account</a>
        </div>
    </div>

    <?php if (!empty($announcements)): ?>
    <aside class="hc-widget" aria-label="Community updates">
        <div class="hc-widget-head">
            <span class="hc-widget-title">Community Updates</span>
            <button type="button" class="hc-widget-toggle" id="hcAutoToggle" aria-pressed="true">&#9679; Slide</button>
        </div>
        <div class="hc-widget-body">
            <?php foreach ($announcements as $i => $item):
                $hcCategory = getAnnouncementCategory($item['title'] ?? '', $item['message'] ?? '', $item['type'] ?? 'system');
                $hcDate = date('M j, Y', strtotime($item['created_at']));
                $hcFallback = frs_announcement_fallback_image($item['title'] ?? '', $item['message'] ?? '');
            ?>
                <a href="<?= htmlspecialchars($base . '/announcements'); ?>" class="hc-slide<?= $i === 0 ? ' is-active' : ''; ?>">
                    <?php if (!empty($item['image_path'])): ?>
                        <div class="hc-slide-img" style="background-image:url('<?= htmlspecialchars($base . $item['image_path']); ?>');"></div>
                    <?php elseif ($hcFallback !== null): ?>
                        <div class="hc-slide-img" style="background-image:url('<?= htmlspecialchars($base . $hcFallback); ?>');"></div>
                    <?php else: ?>
                        <div class="hc-slide-img" style="background:<?= htmlspecialchars($hcCategory['bgColor']); ?>;"></div>
                    <?php endif; ?>
                    <span class="hc-slide-tag" style="color:<?= htmlspecialchars($hcCategory['color']); ?>;"><?= htmlspecialchars(ucfirst($hcCategory['type'])); ?></span>
                    <strong class="hc-slide-title"><?= htmlspecialchars((string)($item['title'] ?? 'Announcement')); ?></strong>
                    <span class="hc-slide-date"><?= htmlspecialchars($hcDate); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="hc-widget-dots">
            <?php foreach ($announcements as $i => $item): ?>
                <span class="hc-dot<?= $i === 0 ? ' is-active' : ''; ?>"></span>
            <?php endforeach; ?>
        </div>
        <p class="hc-widget-hint">Tap card to open section</p>
    </aside>
    <?php endif; ?>
</section>

<!-- How It Works Section -->
<section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8" style="background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-16 home-animate">
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">How It Works</h2>
            <div class="h-1 w-16 bg-emerald-600 rounded-full mx-auto mt-4 mb-4"></div>
            <p class="text-gray-600 text-lg">Reserve your facility in just a few simple steps</p>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <div class="home-how-card home-animate home-animate-delay-1 text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="home-step-icon relative inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-emerald-600 to-emerald-400 text-white shadow-lg mb-5 mx-auto">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    <span class="absolute -top-1 -right-1 w-8 h-8 bg-emerald-800 text-white text-sm font-bold rounded-full flex items-center justify-center">1</span>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Create Account</h3>
                <p class="text-gray-500 text-sm">Sign up with your details and verify your email</p>
            </div>
            
            <div class="home-how-card home-animate home-animate-delay-2 text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="home-step-icon relative inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-emerald-600 to-emerald-400 text-white shadow-lg mb-5 mx-auto">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <span class="absolute -top-1 -right-1 w-8 h-8 bg-emerald-800 text-white text-sm font-bold rounded-full flex items-center justify-center">2</span>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Browse Facilities</h3>
                <p class="text-gray-500 text-sm">Explore available facilities and their features</p>
            </div>
            
            <div class="home-how-card home-animate home-animate-delay-3 text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="home-step-icon relative inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-emerald-600 to-emerald-400 text-white shadow-lg mb-5 mx-auto">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span class="absolute -top-1 -right-1 w-8 h-8 bg-emerald-800 text-white text-sm font-bold rounded-full flex items-center justify-center">3</span>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Book Your Slot</h3>
                <p class="text-gray-500 text-sm">Select date, time, and submit your reservation</p>
            </div>
            
            <div class="home-how-card home-animate home-animate-delay-4 text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="home-step-icon relative inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-emerald-600 to-emerald-400 text-white shadow-lg mb-5 mx-auto">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="absolute -top-1 -right-1 w-8 h-8 bg-emerald-800 text-white text-sm font-bold rounded-full flex items-center justify-center">4</span>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Get Approved</h3>
                <p class="text-gray-500 text-sm">Receive confirmation and enjoy your event</p>
            </div>
        </div>
    </div>
</section>

<!-- Announcements Section -->
<?php if (!empty($announcements)): ?>
<section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8" style="background: linear-gradient(180deg, #ecfdf5 0%, #ffffff 100%);">
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-10 home-animate">
            <div>
                <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">Announcements & Updates</h2>
                <p class="text-gray-600 text-lg mt-2">Latest advisories from Barangay Culiat</p>
            </div>
            <a href="<?= $base; ?>/announcements" class="inline-flex items-center whitespace-nowrap text-emerald-700 font-semibold hover:text-emerald-800">
                See all
                <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>

        <?php
            $featuredAnnouncement = $announcements[0];
            $restAnnouncements = array_slice($announcements, 1, 3);
            $featCategory = getAnnouncementCategory($featuredAnnouncement['title'] ?? '', $featuredAnnouncement['message'] ?? '', $featuredAnnouncement['type'] ?? 'system');
            $featDate = date('M j, Y', strtotime($featuredAnnouncement['created_at']));
            $featFallback = frs_announcement_fallback_image($featuredAnnouncement['title'] ?? '', $featuredAnnouncement['message'] ?? '');
            $featImg = !empty($featuredAnnouncement['image_path'])
                ? $base . $featuredAnnouncement['image_path']
                : ($featFallback !== null ? $base . $featFallback : $defaultImage);
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <a href="<?= htmlspecialchars($base . '/announcements'); ?>" class="lg:col-span-2 relative rounded-2xl overflow-hidden group home-animate block" style="min-height:360px;">
                <div class="absolute inset-0 bg-cover bg-center transition-transform duration-500 group-hover:scale-105" style="background-image:url('<?= htmlspecialchars($featImg); ?>');"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent"></div>
                <div class="absolute bottom-0 left-0 p-6 sm:p-8 text-white">
                    <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wide mb-3 text-white" style="background:<?= htmlspecialchars($featCategory['color']); ?>;"><?= htmlspecialchars($featCategory['type']); ?></span>
                    <h3 class="text-2xl sm:text-3xl font-bold mb-2"><?= htmlspecialchars($featuredAnnouncement['title'] ?? 'Announcement'); ?></h3>
                    <p class="text-white/80 text-sm"><?= htmlspecialchars($featDate); ?></p>
                </div>
            </a>

            <div class="flex flex-col gap-4">
                <?php foreach ($restAnnouncements as $index => $item):
                    $rCategory = getAnnouncementCategory($item['title'] ?? '', $item['message'] ?? '', $item['type'] ?? 'system');
                    $rDate = date('M j, Y', strtotime($item['created_at']));
                    $rFallback = frs_announcement_fallback_image($item['title'] ?? '', $item['message'] ?? '');
                    $rImg = !empty($item['image_path'])
                        ? $base . $item['image_path']
                        : ($rFallback !== null ? $base . $rFallback : $defaultImage);
                ?>
                    <a href="<?= htmlspecialchars($base . '/announcements'); ?>" class="home-announcement-card home-animate home-animate-delay-<?= min($index + 1, 5); ?> flex gap-3 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden p-3">
                        <div class="w-20 h-20 flex-shrink-0 rounded-lg bg-cover bg-center" style="background-image:url('<?= htmlspecialchars($rImg); ?>');"></div>
                        <div class="flex-1 min-w-0">
                            <span class="text-xs font-bold uppercase tracking-wide" style="color:<?= htmlspecialchars($rCategory['color']); ?>;"><?= htmlspecialchars($rCategory['type']); ?></span>
                            <h4 class="text-sm font-bold text-gray-900 line-clamp-2 mt-0.5"><?= htmlspecialchars($item['title'] ?? 'Announcement'); ?></h4>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($rDate); ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="text-center mt-12 home-animate">
            <a href="<?= $base; ?>/announcements" class="inline-flex items-center px-6 py-3 bg-emerald-600 text-white font-semibold rounded-lg shadow-lg hover:bg-emerald-700 hover:shadow-xl transition-all duration-200 hover:-translate-y-0.5">
                View All Announcements
                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Featured Facilities Section -->
<?php if (!empty($featuredFacilities)): ?>
<section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8" style="background: linear-gradient(180deg, #ffffff 0%, #dcfce7 50%, #f0fdf4 100%);">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-16 home-animate">
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">Featured Facilities</h2>
            <div class="h-1 w-16 bg-emerald-600 rounded-full mx-auto mt-4 mb-4"></div>
            <p class="text-gray-600 text-lg">Explore our available facilities</p>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach ($featuredFacilities as $index => $facility):
                $img = !empty($facility['image_path'])
                    ? $base . $facility['image_path']
                    : $base . frs_facility_placeholder_image($facility['name'], $facility['description'] ?? null, $index);
                $detailUrl = $base . '/facility-details?id=' . (int)$facility['id'];
            ?>
                <a href="<?= htmlspecialchars($detailUrl); ?>" class="home-facility-card home-animate home-animate-delay-<?= min($index + 1, 5); ?> block bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden group">
                    <div class="relative h-56 overflow-hidden">
                        <img src="<?= htmlspecialchars($img); ?>" alt="<?= htmlspecialchars($facility['name']); ?>" loading="lazy" class="home-facility-img w-full h-full object-cover">
                        <span class="absolute top-4 right-4 px-3 py-1.5 bg-white/95 backdrop-blur-sm text-emerald-700 font-semibold text-sm rounded-full shadow-sm">
                            <?= ucfirst(htmlspecialchars($facility['status'])); ?>
                        </span>
                        <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    </div>
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-emerald-600 transition-colors"><?= htmlspecialchars($facility['name']); ?></h3>
                        <p class="text-gray-600 text-sm mb-4 line-clamp-2">
                            <?= htmlspecialchars($facility['description'] ?? 'View details for more information'); ?>
                        </p>
                        <span class="inline-flex items-center text-emerald-700 font-semibold text-sm">
                            View Details
                            <svg class="w-5 h-5 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                            </svg>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Important Information Section (Tagalog) -->
<section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8" style="background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-16 home-animate">
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">Mahalagang Impormasyon</h2>
            <div class="h-1 w-16 bg-emerald-600 rounded-full mx-auto mt-4 mb-4"></div>
            <p class="text-gray-600 text-lg">Alamin ang mga detalye tungkol sa aming serbisyo</p>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="space-y-6">
                <div class="home-info-box home-animate-left home-animate-delay-1 bg-white rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="flex items-center gap-3 px-6 py-4 bg-gradient-to-r from-emerald-50 to-green-50 border-b-2 border-emerald-600">
                        <svg class="w-6 h-6 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h3 class="text-lg font-bold text-emerald-900">Oras ng Operasyon</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex justify-between items-start py-3 border-b border-gray-100">
                            <span class="font-semibold text-gray-600">Lunes - Biyernes:</span>
                            <span class="text-gray-900 text-right">8:00 AM - 5:00 PM</span>
                        </div>
                        <div class="flex justify-between items-start py-3 border-b border-gray-100">
                            <span class="font-semibold text-gray-600">Sabado:</span>
                            <span class="text-gray-900 text-right">8:00 AM - 12:00 PM</span>
                        </div>
                        <div class="flex justify-between items-start py-3">
                            <span class="font-semibold text-gray-600">Linggo at Pista Opisyal:</span>
                            <span class="text-gray-900 text-right">Sarado</span>
                        </div>
                    </div>
                </div>
                
                <div class="home-info-box home-animate-left home-animate-delay-2 bg-white rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="flex items-center gap-3 px-6 py-4 bg-gradient-to-r from-emerald-50 to-green-50 border-b-2 border-emerald-600">
                        <svg class="w-6 h-6 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                        </svg>
                        <h3 class="text-lg font-bold text-emerald-900">Makipag-ugnayan</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start py-3 border-b border-gray-100 gap-1">
                            <span class="font-semibold text-gray-600">Telepono:</span>
                            <span class="text-gray-900">(02) 1234-5678</span>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start py-3 border-b border-gray-100 gap-1">
                            <span class="font-semibold text-gray-600">Mobile:</span>
                            <span class="text-gray-900">0912-345-6789</span>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start py-3 border-b border-gray-100 gap-1">
                            <span class="font-semibold text-gray-600">Email:</span>
                            <span class="text-gray-900 break-all">facilities@barangayculiat.gov.ph</span>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start py-3 gap-1">
                            <span class="font-semibold text-gray-600">Address:</span>
                            <span class="text-gray-900">Barangay Culiat, Quezon City</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="space-y-6">
                <div class="home-info-box home-animate-right home-animate-delay-1 bg-white rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="flex items-center gap-3 px-6 py-4 bg-gradient-to-r from-emerald-50 to-green-50 border-b-2 border-emerald-600">
                        <svg class="w-6 h-6 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        <h3 class="text-lg font-bold text-emerald-900">Mga Pasilidad na Available</h3>
                    </div>
                    <div class="p-6">
                        <ul class="space-y-3">
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Covered Court
                            </li>
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Multi-Purpose Hall
                            </li>
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Conference Room
                            </li>
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Function Hall
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div class="home-info-box home-animate-right home-animate-delay-2 bg-white rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="flex items-center gap-3 px-6 py-4 bg-gradient-to-r from-emerald-50 to-green-50 border-b-2 border-emerald-600">
                        <svg class="w-6 h-6 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="text-lg font-bold text-emerald-900">Mga Kinakailangan</h3>
                    </div>
                    <div class="p-6">
                        <ul class="space-y-3">
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Valid ID (Residente ng Barangay Culiat)
                            </li>
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Verified Email Address
                            </li>
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Kumpleto ang Form ng Reservation
                            </li>
                            <li class="flex items-center gap-3 py-2 text-gray-900 font-medium">
                                <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Approval ng Barangay Official
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-10 home-animate">
            <div class="flex flex-col sm:flex-row items-start gap-4 p-6 rounded-2xl border-l-4 border-emerald-600 bg-emerald-50">
                <svg class="w-6 h-6 text-emerald-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <strong class="block text-gray-900 mb-2">Paalala:</strong>
                    <p class="text-gray-700 text-sm sm:text-base mb-0">Ang mga reservation ay dapat gawin nang hindi bababa sa 3 araw bago ang petsa ng event. Ang approval ay aabutin ng 1-2 business days. Para sa emergency reservations, mangyaring makipag-ugnayan sa aming opisina.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    'use strict';
    var hcSlides = document.querySelectorAll('.hc-slide');
    var hcDots = document.querySelectorAll('.hc-dot');
    var toggle = document.getElementById('hcAutoToggle');
    if (hcSlides.length > 0) {
        var hcIndex = 0;
        var autoOn = true;
        var timer = null;
        var hcShow = function (i) {
            hcSlides.forEach(function (s, idx) { s.classList.toggle('is-active', idx === i); });
            hcDots.forEach(function (d, idx) { d.classList.toggle('is-active', idx === i); });
            hcIndex = i;
        };
        var stopAuto = function () { if (timer) { clearInterval(timer); timer = null; } };
        var startAuto = function () {
            stopAuto();
            if (hcSlides.length > 1) {
                timer = setInterval(function () { hcShow((hcIndex + 1) % hcSlides.length); }, 5000);
            }
        };
        if (toggle) {
            toggle.addEventListener('click', function () {
                autoOn = !autoOn;
                toggle.setAttribute('aria-pressed', autoOn ? 'true' : 'false');
                if (autoOn) { startAuto(); } else { stopAuto(); }
            });
        }
        startAuto();
    }
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
?>
