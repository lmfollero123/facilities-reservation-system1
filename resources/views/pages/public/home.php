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

// Default fallback hero images when a facility has no uploaded image
$heroImages = [
    $base . '/public/img/convention-hall.jpg',
    $base . '/public/img/sports-complex.jpg',
    $base . '/public/img/amphitheater.jpg',
];

ob_start();
?>
<section class="hero hero-with-slider">
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
    <div class="hero-content">
        <h1>Reserve LGU Facilities with Confidence</h1>
        <p>Streamlined scheduling for multi-purpose halls, sports complexes, and community venues. Built for transparency and accessibility.</p>
        <div class="hero-actions">
            <a class="btn btn-primary" href="<?= $base; ?>/resources/views/pages/public/facilities.php">View Facilities</a>
            <a class="btn btn-outline" href="<?= $base; ?>/resources/views/pages/public/contact.php">Contact Us</a>
        </div>
        <p style="margin-top:1rem; color:#e0ecff; font-size:0.9rem;">
            Powered by the LGU Facilities Reservation System for transparent and efficient bookings.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="home-intro">
            <div>
                <h2>A simpler way to reserve public facilities</h2>
                <p>The LGU Facilities Reservation System empowers citizens and local organizations to schedule public spaces online — with clear requirements, faster coordination, and transparent approval tracking.</p>
            </div>
            <div class="home-stats">
                <div class="home-stat">
                    <span class="label">Facilities Managed</span>
                    <span class="value"><?= count($featuredFacilities); ?>+</span>
                </div>
                <div class="home-stat">
                    <span class="label">Reservation Workflow</span>
                    <span class="value">End-to-end</span>
                </div>
                <div class="home-stat">
                    <span class="label">Built For</span>
                    <span class="value">LGU & Residents</span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2>Quick Access</h2>
        <div class="facility-grid">
            <article class="facility-card card-elevated">
                <div class="card-body">
                    <h3>Search Facilities</h3>
                    <p>Browse halls, courts, and open spaces with up-to-date availability and maintenance indicators.</p>
                    <a class="btn btn-primary" href="<?= $base; ?>/resources/views/pages/public/facilities.php">Explore Facilities</a>
                </div>
            </article>
            <article class="facility-card card-elevated">
                <div class="card-body">
                    <h3>Reservation Guide</h3>
                    <p>Understand requirements, supporting documents, and LGU approval timelines.</p>
                    <a class="btn btn-outline" href="<?= $base; ?>/resources/views/pages/public/terms.php">Review Guidelines</a>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="section section-muted">
    <div class="container">
        <div class="home-columns">
            <div>
                <h2>How it works</h2>
                <ol class="home-steps">
                    <li><strong>Browse facilities</strong> and review guidelines and availability.</li>
                    <li><strong>Submit a reservation request</strong> with your preferred schedule and purpose.</li>
                    <li><strong>Track approvals and payments</strong> through the LGU dashboard and notifications.</li>
                </ol>
            </div>
            <div>
                <h2>For LGU Offices</h2>
                <ul class="home-benefits">
                    <li>Centralized requests from barangays, departments, and citizens.</li>
                    <li>Clear audit trail for approvals, changes, and cancellations.</li>
                    <li>Reports and calendar views to manage busy days and peak seasons.</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($featuredFacilities)): ?>
    <section class="section">
        <div class="container">
            <div class="section-header">
                <h2>Recently added facilities</h2>
                <p>New and updated venues that are now available for reservation.</p>
            </div>
            <div class="home-featured-grid">
                <?php foreach (array_slice($featuredFacilities, 0, 3) as $facility): ?>
                    <?php
                    $thumb = !empty($facility['image_path'])
                        ? $base . $facility['image_path']
                        : $heroImages[array_rand($heroImages)];
                    ?>
                    <article class="facility-card home-featured-card">
                        <img src="<?= htmlspecialchars($thumb); ?>" alt="<?= htmlspecialchars($facility['name']); ?>">
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


