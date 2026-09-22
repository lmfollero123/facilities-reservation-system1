<?php
$useTailwind = true;
require_once __DIR__ . '/../../../../config/app.php';
$pageTitle = frs_t('faq.page_title');
$base = base_path();
$pageHeaderIcon = 'bi-patch-question';
$pageHeaderTitle = frs_t('faq.header.title');
$pageHeaderTagline = frs_t('faq.header.tagline');
ob_start();
?>

<?php include __DIR__ . '/../../components/page_header.php'; ?>
<section class="page-section faq-section public-fade-in" id="faq">
    <div class="container px-4 px-lg-5">
        <div class="faq-wrapper">
            <div class="faq-container page-content-animate">
            <!-- Tutorial Video -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-play-circle"></i> <?= frs_te('faq.category.how_to_use'); ?>
                </h3>
                <div class="tutorial-video-placeholder">
                    <i class="bi bi-camera-reels"></i>
                    <p><?= frs_te('faq.tutorial.coming_soon'); ?></p>
                </div>
                <!--
                Once the tutorial video is recorded, replace the placeholder div above with an embed, e.g.:
                <div class="tutorial-video-wrapper">
                    <iframe src="https://www.youtube.com/embed/VIDEO_ID" title="How to Use the System" allowfullscreen></iframe>
                </div>
                -->
            </div>

            <!-- Getting Started -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-rocket-takeoff"></i> <?= frs_te('faq.category.getting_started'); ?>
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq1">
                            <span><?= frs_te('faq.q1.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq1" class="faq-answer">
                            <p><?= frs_te('faq.q1.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq2">
                            <span><?= frs_te('faq.q2.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq2" class="faq-answer">
                            <p><?= frs_te('faq.q2.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq3">
                            <span><?= frs_te('faq.q3.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq3" class="faq-answer">
                            <p><?= frs_te('faq.q3.answer'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking & Reservations -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-calendar-check"></i> <?= frs_te('faq.category.booking'); ?>
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq4">
                            <span><?= frs_te('faq.q4.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq4" class="faq-answer">
                            <p><?= frs_te('faq.q4.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq5">
                            <span><?= frs_te('faq.q5.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq5" class="faq-answer">
                            <p><?= frs_te('faq.q5.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq6">
                            <span><?= frs_te('faq.q6.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq6" class="faq-answer">
                            <p><?= frs_te('faq.q6.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq7">
                            <span><?= frs_te('faq.q7.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq7" class="faq-answer">
                            <p><?= frs_te('faq.q7.answer'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fees & Payments -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-cash-stack"></i> <?= frs_te('faq.category.fees'); ?>
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq8">
                            <span><?= frs_te('faq.q8.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq8" class="faq-answer">
                            <p><?= frs_te('faq.q8.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq9">
                            <span><?= frs_te('faq.q9.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq9" class="faq-answer">
                            <p><?= frs_te('faq.q9.answer'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cancellations & Changes -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-x-circle"></i> <?= frs_te('faq.category.cancellations'); ?>
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq10">
                            <span><?= frs_te('faq.q10.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq10" class="faq-answer">
                            <p><?= frs_te('faq.q10.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq11">
                            <span><?= frs_te('faq.q11.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq11" class="faq-answer">
                            <p><?= frs_te('faq.q11.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq12">
                            <span><?= frs_te('faq.q12.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq12" class="faq-answer">
                            <p><?= frs_te('faq.q12.answer'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Policies & Rules -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-shield-check"></i> <?= frs_te('faq.category.policies'); ?>
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq13">
                            <span><?= frs_te('faq.q13.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq13" class="faq-answer">
                            <p><?= frs_te('faq.q13.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq14">
                            <span><?= frs_te('faq.q14.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq14" class="faq-answer">
                            <p><?= frs_te('faq.q14.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq15">
                            <span><?= frs_te('faq.q15.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq15" class="faq-answer">
                            <p><?= frs_te('faq.q15.answer'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Technical Support -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-headset"></i> <?= frs_te('faq.category.support'); ?>
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq16">
                            <span><?= frs_te('faq.q16.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq16" class="faq-answer">
                            <p><?= frs_te('faq.q16.answer'); ?></p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" role="button" tabindex="0" aria-expanded="false" data-bs-target="#faq17">
                            <span><?= frs_te('faq.q17.question'); ?></span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq17" class="faq-answer">
                            <p><?= frs_te('faq.q17.answer'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            <div class="text-center mt-5">
                <p class="text-muted mb-3"><?= frs_te('faq.still_questions'); ?></p>
                <a href="<?= $base; ?>/contact" class="btn btn-primary">
                    <i class="bi bi-envelope"></i> <?= frs_te('faq.contact_us'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<style>
/* FAQ Section - green gradient background */
.faq-section {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 50%, #f0fdf4 100%);
    min-height: 100vh;
    position: relative;
    padding: 4rem 0;
}

.faq-section::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: transparent;
    z-index: 0;
}

.faq-wrapper {
    position: relative;
    z-index: 1;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 3rem 2rem;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    max-width: 1000px;
    margin: 0 auto;
}

@media (max-width: 767px) {
    .faq-section {
        padding: 2rem 0;
    }
    
    .faq-wrapper {
        padding: 2rem 1.5rem;
        border-radius: 16px;
    }
}

.faq-container {
    max-width: 900px;
    margin: 0 auto;
}

.tutorial-video-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 3rem 1.5rem;
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    background: #f8f9fa;
    color: #6b7280;
    text-align: center;
}

.tutorial-video-placeholder i {
    font-size: 2.5rem;
    color: #285ccd;
}

.tutorial-video-wrapper {
    position: relative;
    width: 100%;
    padding-top: 56.25%;
    border-radius: 12px;
    overflow: hidden;
}

.tutorial-video-wrapper iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: 0;
}

.faq-category {
    margin-bottom: 3rem;
}

.category-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #1e3a5f;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #285ccd;
}

.category-title i {
    font-size: 1.75rem;
    color: #285ccd;
}

.faq-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.faq-card {
    background: #ffffff !important;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: visible;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.faq-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #285ccd;
}

.faq-question {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    cursor: pointer;
    background: #f8f9fa;
    font-weight: 600;
    color: #1e3a5f;
    transition: all 0.2s ease;
    user-select: none;
}

.faq-question:hover {
    background: #f0f4ff;
    color: #285ccd;
}

.faq-question[aria-expanded="true"] {
    background: #eff6ff;
    color: #285ccd;
}

.faq-question[aria-expanded="true"] i {
    transform: rotate(180deg);
}

.faq-question i {
    transition: transform 0.3s ease;
    color: #285ccd;
    font-size: 1.25rem;
}

.faq-answer {
    padding: 0 1.5rem;
    background: #ffffff;
    display: none;
}

.faq-question[aria-expanded="true"] + .faq-answer {
    display: block;
}

.faq-answer p {
    padding: 1.25rem 0;
    margin: 0;
    color: #4a5568;
    line-height: 1.8;
    font-size: 0.95rem;
}

/* Accordion is driven by vanilla JS toggling aria-expanded (see script below); no Bootstrap collapse overrides needed */

/* Mobile Responsive */
@media (max-width: 768px) {
    .category-title {
        font-size: 1.25rem;
    }
    
    .faq-question {
        padding: 1rem;
        font-size: 0.95rem;
    }
    
    .faq-answer {
        padding: 0 1rem;
    }
    
    .faq-answer p {
        padding: 1rem 0;
        font-size: 0.9rem;
    }
}
</style>

<?php
// Accordion click/keyboard handling is wired up centrally in public/js/public-navigation.js
// (initFaqAccordion) so it runs on both a direct page load and an in-site AJAX navigation.
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
?>
