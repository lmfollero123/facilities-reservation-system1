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
    'SELECT id, title, message, type, link, image_path, created_at 
     FROM notifications 
     WHERE user_id IS NULL 
     ORDER BY created_at DESC 
     LIMIT 5'
);
$announcementsStmt->execute();
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Function to categorize announcements for display
function getAnnouncementCategory($title, $message, $type) {
    $titleLower = strtolower($title);
    $messageLower = strtolower($message);
    $combined = $titleLower . ' ' . $messageLower;
    
    // Pattern matching for categories
    $patterns = [
        'urgent' => ['/emergency|urgent|alert|critical|closure|interruption/i'],
        'advisory' => ['/advisory|notice|attention|reminder|please note|schedule|maintenance/i'],
        'event' => ['/event|activity|program|ceremony|celebration|activity|drive/i'],
        'general' => ['/announcement|update|information|news/i'],
    ];
    
    foreach ($patterns as $category => $categoryPatterns) {
        foreach ($categoryPatterns as $pattern) {
            if (preg_match($pattern, $combined)) {
                return [
                    'type' => $category,
                    'color' => $category === 'urgent' ? '#dc2626' : ($category === 'event' ? '#059669' : '#2563eb'),
                    'bgColor' => $category === 'urgent' ? '#fee2e2' : ($category === 'event' ? '#ecfdf5' : '#eff6ff'),
                ];
            }
        }
    }
    
    return [
        'type' => 'general',
        'color' => '#6b7280',
        'bgColor' => '#f3f4f6',
    ];
}

// Default fallback image
$defaultImage = $base . '/public/img/cityhall.jpeg';

ob_start();
?>
<!-- Masthead -->
<header class="masthead" style="background: linear-gradient(to bottom, rgba(92, 77, 66, 0.8) 0%, rgba(92, 77, 66, 0.8) 100%), url('<?= $defaultImage; ?>'); background-position: center; background-repeat: no-repeat; background-size: cover;">
    <div class="container px-4 px-lg-5 h-100">
        <div class="row gx-4 gx-lg-5 h-100 align-items-center justify-content-center text-center">
            <div class="col-lg-10 align-self-end">
                <h1 class="text-white font-weight-bold">Barangay Culiat Public Facilities Reservation System</h1>
                <hr class="divider" />
            </div>
            <div class="col-lg-10 align-self-baseline hero-cta">
                <p class="text-white mb-5">
                    Reserve barangay facilities with clear approvals, OTP-secured logins, and smart recommendations—built for residents and LGU teams.
                </p>
                
                <div class="hero-btn-row">
                    <a class="btn btn-primary" href="<?= $base; ?>/facilities">Browse Facilities</a>
                    <a class="btn btn-light" href="<?= $base; ?>/register">Create Account</a>
                </div>
            </div>
        </div>
    </div>
</header>

<style>
/* Hero button row: center on masthead */
.hero-btn-row {
    display: flex;
    justify-content: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1rem;
}
.hero-btn-row .btn {
    min-width: 160px;
}
</style>

<!-- Announcements -->
<?php if (!empty($announcements)): ?>
<section class="page-section announcements-section" id="announcements">
    <div class="container px-4 px-lg-5">
        <h2 class="text-center mt-0">Announcements & Updates</h2>
        <hr class="divider" />
        <p class="text-center text-muted mb-5">Latest advisories from Barangay Culiat</p>
        
        <!-- Announcement Cards List -->
        <div class="announcements-list">
            <?php foreach ($announcements as $item): 
                $category = getAnnouncementCategory($item['title'] ?? '', $item['message'] ?? '', $item['type'] ?? 'system');
                $dateFormatted = date('M d, Y', strtotime($item['created_at']));
                $messageSummary = htmlspecialchars(mb_strimwidth($item['message'] ?? '', 0, 120, '…'));
                $hasLink = !empty($item['link']);
                $hasImage = !empty($item['image_path']);
            ?>
                <article class="announcement-card-list-item">
                    <!-- Category Tag -->
                    <div class="announcement-tag" style="background-color: <?= $category['bgColor']; ?>; border-left: 4px solid <?= $category['color']; ?>;">
                        <span class="tag-label" style="color: <?= $category['color']; ?>; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px;">
                            <?= ucfirst($category['type']); ?>
                        </span>
                        <span class="tag-date" style="color: #6b7280; font-size: 0.875rem; margin-left: auto;">
                            <?= $dateFormatted; ?>
                        </span>
                    </div>
                    
                    <!-- Feature Image (if available) -->
                    <?php if ($hasImage): ?>
                    <div class="announcement-image-container">
                        <img src="<?= htmlspecialchars($base . $item['image_path']); ?>" alt="<?= htmlspecialchars($item['title'] ?? 'Announcement'); ?>" class="announcement-feature-image">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Content -->
                    <div class="announcement-content">
                        <h3 class="announcement-title"><?= htmlspecialchars($item['title'] ?? 'Announcement'); ?></h3>
                        <p class="announcement-summary"><?= $messageSummary; ?></p>
                        
                        <!-- Read More Link -->
                        <?php if ($hasLink): ?>
                        <a href="<?= htmlspecialchars($base . $item['link']); ?>" class="read-more-link">
                            Read More <i class="bi bi-arrow-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        
        <!-- View All Button -->
        <div class="text-center mt-5">
            <a href="<?= $base; ?>/announcements" class="btn btn-outline btn-lg">
                View All Announcements <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<style>
/* Announcements Section Styling - LGU Best Practices */
.announcements-section {
    background: #ffffff;
    padding: 4rem 1rem;
}

@media (min-width: 481px) {
    .announcements-section {
        padding: 4rem 1.5rem;
    }
}

@media (min-width: 1025px) {
    .announcements-section {
        padding: 5rem 2rem;
    }
}

/* Announcements List Container */
.announcements-list {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-width: 900px;
    margin: 0 auto 2.5rem;
}

/* Individual Announcement Card */
.announcement-card-list-item {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #e5e7eb;
    transition: all 0.2s ease;
}

.announcement-card-list-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

/* Feature Image */
.announcement-image-container {
    width: 100%;
    border-radius: 8px;
    overflow: hidden;
    background: #f3f4f6;
    max-height: 200px;
}

.announcement-feature-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* Category Tag */
.announcement-tag {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.625rem 1rem;
    border-radius: 6px;
    justify-content: space-between;
}

.tag-label {
    white-space: nowrap;
}

.tag-date {
    white-space: nowrap;
}

@media (max-width: 480px) {
    .announcement-tag {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .tag-date {
        align-self: flex-start;
    }
}

/* Content */
.announcement-content {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.announcement-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.announcement-summary {
    font-size: 0.9375rem;
    color: #4b5563;
    margin: 0;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Read More Link */
.read-more-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #2563eb;
    font-weight: 600;
    font-size: 0.9375rem;
    text-decoration: none;
    transition: all 0.2s ease;
    margin-top: 0.5rem;
    width: fit-content;
}

.read-more-link:hover {
    color: #1e40af;
    gap: 0.75rem;
}

.read-more-link i {
    transition: transform 0.2s ease;
}

.read-more-link:hover i {
    transform: translateX(3px);
}

/* Hover effect on card */
.announcement-card-list-item:hover .announcement-title {
    color: #0f172a;
}

/* Accessibility */
.announcement-card-list-item:focus-within {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
    border-radius: 4px;
}

/* Mobile optimization */
@media (max-width: 480px) {
    .announcements-section {
        padding: 2.5rem 1rem;
    }
    
    .announcements-list {
        gap: 1rem;
    }
    
    .announcement-title {
        font-size: 1rem;
    }
    
    .announcement-summary {
        font-size: 0.875rem;
    }
    
    .btn-lg {
        width: 100%;
    }
}
</style>
<?php endif; ?>

<!-- Portfolio (Facilities) -->
<?php if (!empty($featuredFacilities)): ?>
<div id="portfolio">
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php foreach ($featuredFacilities as $facility): 
                $img = !empty($facility['image_path'])
                    ? $base . $facility['image_path']
                    : $defaultImage;
                $detailUrl = $base . '/facility-details?id=' . (int)$facility['id'];
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

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
?>