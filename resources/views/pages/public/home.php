<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
$pageTitle = 'Home | LGU Facilities Reservation';

$pdo = db();
$base = base_path();

// Load a few featured facilities for the hero slideshow
$featuredStmt = $pdo->query(
    'SELECT id, name, description, status, image_path 
     FROM facilities 
     ORDER BY created_at DESC 
     LIMIT 5'
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

// Default fallback hero images when a facility has no uploaded image
$heroImages = [
    $base . '/public/img/convention-hall.jpg',
    $base . '/public/img/sports-complex.jpg',
    $base . '/public/img/amphitheater.jpg',
];

ob_start();
?>
<section class="hero hero-with-slider hero-modern">
    <div class="hero-content">
        <p class="section-kicker">Barangay Culiat</p>
        <h1>Barangay Culiat Public Facilities Reservation System</h1>
        <p class="hero-lead">
            Reserve barangay facilities with clear approvals, OTP-secured logins, and smart recommendations—built for residents and LGU teams.
        </p>
        <div class="hero-actions">
            <a class="btn btn-primary" href="<?= $base; ?>/resources/views/pages/public/facilities.php">Browse facilities</a>
            <a class="btn btn-outline" href="<?= $base; ?>/resources/views/pages/auth/register.php">Create account</a>
        </div>
        <div class="hero-badges">
            <span class="pill">OTP-secured</span>
            <span class="pill">Document-verified</span>
            <span class="pill">AI recommendations</span>
        </div>
    </div>
    <div class="hero-media">
        <div class="hero-slider" id="heroSlider">
            <?php if (!empty($featuredFacilities)): ?>
                <?php foreach ($featuredFacilities as $index => $facility):
                    $img = !empty($facility['image_path'])
                        ? $base . $facility['image_path']
                        : $heroImages[$index % count($heroImages)];
                ?>
                    <div class="hero-slide<?= $index === 0 ? ' active' : ''; ?>">
                        <div class="hero-slide-image" style="background-image: url('<?= $img; ?>');"></div>
                        <div class="hero-slide-overlay">
                            <h2><?= htmlspecialchars($facility['name']); ?></h2>
                            <p><?= htmlspecialchars(mb_strimwidth($facility['description'] ?? 'LGU-owned multi-purpose facility.', 0, 140, '…')); ?></p>
                            <span class="status-pill <?= $facility['status'] === 'available' ? 'status-available' : ($facility['status'] === 'maintenance' ? 'status-booked' : 'status-booked'); ?>">
                                <?= ucfirst($facility['status']); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($heroImages as $index => $img): ?>
                    <div class="hero-slide<?= $index === 0 ? ' active' : ''; ?>">
                        <div class="hero-slide-image" style="background-image: url('<?= $img; ?>');"></div>
                        <div class="hero-slide-overlay">
                            <h2>LGU Community Spaces</h2>
                            <p>Multi-purpose halls, sports complexes, and open parks ready for your next activity.</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="hero-dots" id="heroDots"></div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="home-intro modern">
            <div>
                <p class="section-kicker">Why residents trust us</p>
                <h2>Frictionless reservations, clear approvals</h2>
                <p class="section-subtitle">
                    End-to-end scheduling built for Barangay Culiat residents and LGU offices—secure, transparent, and mobile-friendly.
                </p>
                <div class="home-stats">
                    <div class="home-stat">
                        <span class="label">Facilities Managed</span>
                        <span class="value"><?= count($featuredFacilities); ?>+</span>
                    </div>
                    <div class="home-stat">
                        <span class="label">Approvals &amp; Audit</span>
                        <span class="value">Tracked</span>
                    </div>
                    <div class="home-stat">
                        <span class="label">Security</span>
                        <span class="value">OTP + Docs</span>
                    </div>
                </div>
            </div>
            <div class="feature-grid">
                <article class="feature-card">
                    <h3>OTP-secured login</h3>
                    <p>Multi-step auth with rate limits and account lockout for safer resident access.</p>
                </article>
                <article class="feature-card">
                    <h3>Document verification</h3>
                    <p>Barangay Culiat validation with required IDs and approvals before access.</p>
                </article>
                <article class="feature-card">
                    <h3>Smart recommendations</h3>
                    <p>Location-aware facility suggestions and conflict-aware booking guidance.</p>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-header">
            <div>
                <p class="section-kicker">Start fast</p>
                <h2>Quick access</h2>
                <p class="section-subtitle">Jump straight into booking or learn the guidelines.</p>
            </div>
        </div>
        <div class="facility-grid quick-links">
            <article class="facility-card card-elevated">
                <div class="card-body">
                    <h3>Search facilities</h3>
                    <p>Browse halls, courts, and open spaces with live availability indicators.</p>
                    <a class="btn btn-primary" href<?= "=\"$base/resources/views/pages/public/facilities.php\""; ?>>Explore facilities</a>
                </div>
            </article>
            <article class="facility-card card-elevated">
                <div class="card-body">
                    <h3>Reservation guide</h3>
                    <p>Understand requirements, documents, and approval timelines before you submit.</p>
                    <a class="btn btn-outline" href<?= "=\"$base/resources/views/pages/public/terms.php\""; ?>>Review guidelines</a>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="process-steps">
            <div class="process-header">
                <p class="section-kicker">Simple steps</p>
                <h2>How it works</h2>
                <p class="section-subtitle">Submit, verify, and get approved with full transparency.</p>
            </div>
            <div class="process-grid">
                <div class="process-step">
                    <span class="badge-number">1</span>
                    <h3>Browse &amp; choose</h3>
                    <p>Filter by availability and see facility details, amenities, and citations.</p>
                </div>
                <div class="process-step">
                    <span class="badge-number">2</span>
                    <h3>Request &amp; upload</h3>
                    <p>Submit your schedule with required documents for Barangay Culiat verification.</p>
                </div>
                <div class="process-step">
                    <span class="badge-number">3</span>
                    <h3>OTP-secured updates</h3>
                    <p>Track approvals, receive notifications, and access the calendar on any device.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($featuredFacilities)): ?>
    <section class="section">
        <div class="container">
            <div class="section-header">
                <div>
                    <p class="section-kicker">Fresh arrivals</p>
                    <h2>Recently added facilities</h2>
                    <p class="section-subtitle">New and updated venues now open for reservation.</p>
                </div>
                <a class="btn btn-outline" href="<?= $base; ?>/resources/views/pages/public/facilities.php">See all facilities</a>
            </div>
            <div class="home-featured-grid">
                <?php foreach (array_slice($featuredFacilities, 0, 3) as $facility): ?>
                    <?php
                    $thumb = !empty($facility['image_path'])
                        ? $base . $facility['image_path']
                        : $heroImages[array_rand($heroImages)];
                    ?>
                    <article class="facility-card home-featured-card">
                        <img src="<?= htmlspecialchars($thumb); ?>" alt="<?= htmlspecialchars($facility['name']); ?>" loading="lazy" decoding="async">
                        <div class="card-body">
                            <div class="status-pill <?= $facility['status'] === 'available' ? 'status-available' : 'status-booked'; ?>">
                                <?= ucfirst(htmlspecialchars($facility['status'])); ?>
                            </div>
                            <h3><?= htmlspecialchars($facility['name']); ?></h3>
                            <p><?= htmlspecialchars(mb_strimwidth($facility['description'] ?? 'LGU-owned facility.', 0, 120, '…')); ?></p>
                            <a class="btn btn-outline" href="<?= $base; ?>/resources/views/pages/public/facility_details.php?id=<?= (int)$facility['id']; ?>">
                                View details
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="section section-muted">
    <div class="container">
        <div class="section-header">
            <div>
                <p class="section-kicker">Updates</p>
                <h2>Announcements</h2>
                <p class="section-subtitle">Latest advisories from Barangay Culiat.</p>
            </div>
        </div>
        <?php if (!empty($announcements)): ?>
            <div class="announcement-grid">
                <?php foreach ($announcements as $item): ?>
                    <article class="announcement-card">
                        <p class="announcement-date"><?= date('M d, Y', strtotime($item['created_at'])); ?></p>
                        <h3><?= htmlspecialchars($item['title'] ?? 'Announcement'); ?></h3>
                        <p class="announcement-body"><?= htmlspecialchars(mb_strimwidth($item['message'] ?? '', 0, 180, '…')); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="margin:0; color:#4c5b7c;">No announcements yet. Check back soon.</p>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const slider = document.getElementById('heroSlider');
    const slides = slider ? Array.from(slider.querySelectorAll('.hero-slide')) : [];
    const dotsContainer = document.getElementById('heroDots');
    if (!slides.length || !dotsContainer) return;

    let current = 0;

    function goTo(index) {
        slides[current].classList.remove('active');
        dotsContainer.children[current].classList.remove('active');
        current = index;
        slides[current].classList.add('active');
        dotsContainer.children[current].classList.add('active');
    }

    slides.forEach((_, idx) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'hero-dot' + (idx === 0 ? ' active' : '');
        dot.addEventListener('click', () => goTo(idx));
        dotsContainer.appendChild(dot);
    });

    setInterval(() => {
        const next = (current + 1) % slides.length;
        goTo(next);
    }, 6000);
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


