<?php
$pageTitle = frs_t('terms.page_title');
if (!function_exists('frs_resident_booking_limits_policy_bullets')) {
    require_once __DIR__ . '/../../../../config/reservation_helpers.php';
}
$residentBookingLimitsHtml = nl2br(htmlspecialchars(frs_resident_booking_limits_policy_bullets()));
ob_start();
?>
<section class="section legal-page-section">
    <div class="container legal-content">
        <h2><?= frs_te('terms.heading'); ?></h2>
        <p><?= frs_te('terms.intro1'); ?></p>
        <p><?= frs_te('terms.intro2'); ?></p>

        <h3><?= frs_te('terms.reservation.title'); ?></h3>
        <p><?= frs_te('terms.reservation.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('terms.reservation.limits_label'); ?></strong><br><?= $residentBookingLimitsHtml ?></li>
            <li><strong><?= frs_te('terms.reservation.reschedule_label'); ?></strong> <?= frs_te('terms.reservation.reschedule_text'); ?></li>
            <li><strong><?= frs_te('terms.reservation.autoapproval_label'); ?></strong> <?= frs_te('terms.reservation.autoapproval_text'); ?></li>
            <li><strong><?= frs_te('terms.reservation.modifications_label'); ?></strong> <?= frs_te('terms.reservation.modifications_text'); ?></li>
        </ul>

        <h3><?= frs_te('terms.prohibited.title'); ?></h3>
        <p><?= frs_te('terms.prohibited.body'); ?></p>

        <h3><?= frs_te('terms.violations.title'); ?></h3>
        <p><?= frs_te('terms.violations.body'); ?></p>

        <h3><?= frs_te('terms.privacy.title'); ?></h3>
        <p><?= frs_te('terms.privacy.body1'); ?></p>
        <p><?= frs_te('terms.privacy.body2'); ?></p>

        <h3><?= frs_te('terms.acceptance.title'); ?></h3>
        <p><?= frs_te('terms.acceptance.body'); ?></p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


