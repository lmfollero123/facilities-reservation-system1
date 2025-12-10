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
    'SELECT title, message, created_at 
     FROM notifications 
     WHERE user_id IS NULL 
     ORDER BY created_at DESC 
     LIMIT 4'
);
$announcementsStmt->execute();
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Default fallback image
$defaultImage = $base . '/NewTemplate/assets/img/cityhall.jpeg';

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
            <div class="col-lg-8 align-self-baseline">
                <p class="text-white-75 mb-5">Reserve barangay facilities with clear approvals, OTP-secured logins, and smart recommendations—built for residents and LGU teams.</p>
                <a class="btn btn-primary btn-xl" href="<?= $base; ?>/resources/views/pages/public/facilities.php">Browse Facilities</a>
                <a class="btn btn-light btn-xl" href="<?= $base; ?>/resources/views/pages/auth/register.php" style="margin-left: 1rem;">Create Account</a>
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
<section class="page-section" id="announcements">
    <div class="container px-4 px-lg-5">
        <h2 class="text-center mt-0">Announcements</h2>
        <hr class="divider" />
        <p class="text-center text-muted mb-5">Latest advisories from Barangay Culiat</p>
        <div class="row gx-4 gx-lg-5">
            <?php foreach ($announcements as $item): ?>
                <div class="col-lg-6 col-md-6 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <small class="text-muted d-block mb-2"><?= date('M d, Y', strtotime($item['created_at'])); ?></small>
                            <h4 class="card-title"><?= htmlspecialchars($item['title'] ?? 'Announcement'); ?></h4>
                            <p class="card-text text-muted"><?= htmlspecialchars(mb_strimwidth($item['message'] ?? '', 0, 200, '…')); ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
