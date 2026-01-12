<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
$pageTitle = 'Home | Barangay Culiat Public Facilities Reservation System';

$pdo = db();
$base = base_path();

// Load featured facilities for portfolio gallery
$featuredStmt = $pdo->query(
    'SELECT id, name, description, status, image_path 
     FROM facilities 
     ORDER BY created_at DESC 
     LIMIT 6'
);
$featuredFacilities = $featuredStmt->fetchAll(PDO::FETCH_ASSOC);

// Public announcements (system-wide notifications with NULL user_id)
$announcementsStmt = $pdo->prepare(
    'SELECT id, title, message, type, link, created_at 
     FROM notifications 
     WHERE user_id IS NULL 
     ORDER BY created_at DESC 
     LIMIT 6'
);
$announcementsStmt->execute();
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Map notification types to categories for display
function getAnnouncementCategory($title, $message, $type) {
    $titleLower = strtolower($title);
    $messageLower = strtolower($message);
    $combined = $titleLower . ' ' . $messageLower;
    
    // Determine category based on keywords
    if (preg_match('/\b(emergency|urgent|alert|warning|disaster|evacuation|flood|fire)\b/i', $combined)) {
        return ['type' => 'emergency', 'icon' => 'exclamation-triangle', 'color' => 'danger'];
    } elseif (preg_match('/\b(event|celebration|festival|conference|meeting|gathering)\b/i', $combined)) {
        return ['type' => 'event', 'icon' => 'calendar-event', 'color' => 'primary'];
    } elseif (preg_match('/\b(health|vaccination|vaccine|medical|clinic|hospital|healthcare)\b/i', $combined)) {
        return ['type' => 'health', 'icon' => 'heart-pulse', 'color' => 'success'];
    } elseif (preg_match('/\b(deadline|permit|license|application|due|expire|expiration)\b/i', $combined)) {
        return ['type' => 'deadline', 'icon' => 'clock', 'color' => 'warning'];
    } elseif (preg_match('/\b(advisory|notice|update|information|reminder)\b/i', $combined)) {
        return ['type' => 'advisory', 'icon' => 'info-circle', 'color' => 'info'];
    } else {
        // Default based on notification type
        if ($type === 'system') {
            return ['type' => 'advisory', 'icon' => 'megaphone', 'color' => 'primary'];
        } else {
            return ['type' => 'general', 'icon' => 'bell', 'color' => 'secondary'];
        }
    }
}

// Default fallback image
$defaultImage = $base . '/public/img/cityhall.jpeg';

ob_start();
?>
<!-- Masthead -->
<header class="masthead" style="background: linear-gradient(to bottom, rgba(92, 77, 66, 0.8) 0%, rgba(92, 77, 66, 0.8) 100%), url('<?= $defaultImage; ?>'); background-position: center; background-repeat: no-repeat; background-size: cover;">
    <div class="container px-4 px-lg-5 h-100">
        <div class="row gx-4 gx-lg-5 h-100 align-items-center justify-content-center text-center">
            <div class="col-lg-8 align-self-end">
                <h1 class="text-white font-weight-bold">Barangay Culiat Public Facilities Reservation System</h1>
                <hr class="divider" />
            </div>
            <div class="col-lg-8 align-self-baseline hero-cta">
            <p class="text-white mb-5">
  Reserve barangay facilities with clear approvals, OTP-secured logins, and smart recommendations—built for residents and LGU teams.
</p>
                <div class="hero-btn-row">
                    <a class="btn btn-primary" href="<?= $base; ?>/resources/views/pages/public/facilities.php">Browse Facilities</a>
                    <a class="btn btn-light" href="<?= $base; ?>/resources/views/pages/auth/register.php">Create Account</a>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- About -->
<section class="page-section bg-primary" id="about">
    <div class="container px-4 px-lg-5">
        <div class="row gx-4 gx-lg-5 justify-content-center">
            <div class="col-lg-8 text-center">
                <h2 class="text-white mt-0">About Barangay Culiat</h2>
                <hr class="divider divider-light" />
                <p class="text-white-75 mb-4">The Barangay Culiat Public Facilities Reservation System is dedicated to managing, maintaining, and improving essential public facilities to ensure safe, reliable, and efficient community services. Our goal is to streamline reservation operations, enhance facility accessibility, and provide residents with transparent access to facility bookings and support.</p>
                <a class="btn btn-light btn-xl" href="<?= $base; ?>/resources/views/pages/public/facilities.php">Browse Facilities</a>
            </div>
        </div>
    </div>
</section>

<!-- Services -->
<section class="page-section" id="services">
    <div class="container px-4 px-lg-5">
        <h2 class="text-center mt-0">Our Services & Features</h2>
        <hr class="divider" />
        <div class="row gx-4 gx-lg-5">
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mt-5">
                    <div class="mb-2"><i class="bi-shield-check fs-1 text-primary"></i></div>
                    <h3 class="h4 mb-2">OTP-Secured Login</h3>
                    <p class="text-muted mb-0">Multi-step authentication with rate limits and account lockout for safer resident access.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mt-5">
                    <div class="mb-2"><i class="bi-file-earmark-check fs-1 text-primary"></i></div>
                    <h3 class="h4 mb-2">Document Verification</h3>
                    <p class="text-muted mb-0">Barangay Culiat validation with required IDs and approvals before access.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mt-5">
                    <div class="mb-2"><i class="bi-geo-alt fs-1 text-primary"></i></div>
                    <h3 class="h4 mb-2">Smart Recommendations</h3>
                    <p class="text-muted mb-0">Location-aware facility suggestions and conflict-aware booking guidance.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 text-center">
                <div class="mt-5">
                    <div class="mb-2"><i class="bi-calendar-check fs-1 text-primary"></i></div>
                    <h3 class="h4 mb-2">Easy Reservations</h3>
                    <p class="text-muted mb-0">Streamlined booking process with real-time availability and approval tracking.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Portfolio (Facilities) -->
<?php if (!empty($featuredFacilities)): ?>
<div id="portfolio">
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php foreach ($featuredFacilities as $facility): 
                $img = !empty($facility['image_path'])
                    ? $base . $facility['image_path']
                    : $defaultImage;
                $detailUrl = $base . '/resources/views/pages/public/facility_details.php?id=' . (int)$facility['id'];
            ?>
                <div class="col-lg-4 col-sm-6">
                    <a class="portfolio-box" href="<?= htmlspecialchars($detailUrl); ?>" title="<?= htmlspecialchars($facility['name']); ?>">
                        <img class="img-fluid" src="<?= htmlspecialchars($img); ?>" alt="<?= htmlspecialchars($facility['name']); ?>" loading="lazy" />
                        <div class="portfolio-box-caption">
                            <div class="project-category text-white-50"><?= ucfirst(htmlspecialchars($facility['status'])); ?></div>
                            <div class="project-name"><?= htmlspecialchars($facility['name']); ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Announcements -->
<?php if (!empty($announcements)): ?>
<section class="page-section announcements-modern" id="announcements">
    <div class="container px-4 px-lg-5">
        <div class="announcements-header text-center mb-5">
            <h2 class="mt-0 mb-2">Announcements & Updates</h2>
            <hr class="divider" />
            <p class="text-muted mb-0">Stay informed with the latest news, events, and important information from Barangay Culiat</p>
        </div>
        <div class="announcements-grid">
            <?php foreach ($announcements as $item): 
                $category = getAnnouncementCategory($item['title'] ?? '', $item['message'] ?? '', $item['type'] ?? 'system');
                $iconClass = 'bi-' . $category['icon'];
                $colorClass = $category['color'];
                $dateFormatted = date('M d, Y', strtotime($item['created_at']));
                $timeFormatted = date('g:i A', strtotime($item['created_at']));
                $messagePreview = htmlspecialchars(mb_strimwidth($item['message'] ?? '', 0, 150, '…'));
                $link = !empty($item['link']) ? $base . $item['link'] : null;
            ?>
                <div class="announcement-card announcement-<?= $category['type']; ?>">
                    <div class="announcement-card-header">
                        <div class="announcement-icon announcement-icon-<?= $colorClass; ?>">
                            <i class="bi <?= $iconClass; ?>"></i>
                        </div>
                        <div class="announcement-meta">
                            <span class="announcement-type"><?= ucfirst($category['type']); ?></span>
                            <span class="announcement-date">
                                <i class="bi bi-calendar3"></i> <?= $dateFormatted; ?>
                            </span>
                        </div>
                    </div>
                    <div class="announcement-card-body">
                        <h3 class="announcement-title"><?= htmlspecialchars($item['title'] ?? 'Announcement'); ?></h3>
                        <p class="announcement-message"><?= $messagePreview; ?></p>
                    </div>
                    <?php if ($link): ?>
                    <div class="announcement-card-footer">
                        <a href="<?= htmlspecialchars($link); ?>" class="announcement-link">
                            Read More <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
