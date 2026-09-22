<?php
$pageTitle = frs_t('privacy.page_title');
ob_start();
?>
<section class="section legal-page-section">
    <div class="container legal-content">
        <h2><?= frs_te('privacy.heading'); ?></h2>
        <h3><?= frs_te('privacy.subheading'); ?></h3>

        <h4><?= frs_te('privacy.s1.title'); ?></h4>
        <p><?= frs_te('privacy.s1.body_prefix'); ?> <strong><?= frs_te('privacy.s1.act'); ?></strong> <?= frs_te('privacy.s1.body_suffix'); ?></p>

        <h4><?= frs_te('privacy.s2.title'); ?></h4>
        <p><?= frs_te('privacy.s2.body'); ?></p>
        <ul>
            <li><?= frs_te('privacy.s2.email_label'); ?> dpo@barangayculiat.gov.ph</li>
            <li><?= frs_te('privacy.s2.office_label'); ?> Barangay Culiat Facilities Management Office</li>
            <li><?= frs_te('privacy.s2.contact_label'); ?> <?= frs_te('privacy.s2.contact_value'); ?></li>
        </ul>

        <h4><?= frs_te('privacy.s3.title'); ?></h4>
        <p><?= frs_te('privacy.s3.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('privacy.s3.identity_label'); ?></strong>: <?= frs_te('privacy.s3.identity_text'); ?></li>
            <li><strong><?= frs_te('privacy.s3.contact_label'); ?></strong>: <?= frs_te('privacy.s3.contact_text'); ?></li>
            <li><strong><?= frs_te('privacy.s3.address_label'); ?></strong>: <?= frs_te('privacy.s3.address_text'); ?></li>
            <li><strong><?= frs_te('privacy.s3.reservation_label'); ?></strong>: <?= frs_te('privacy.s3.reservation_text'); ?></li>
        </ul>

        <h4><?= frs_te('privacy.s4.title'); ?></h4>
        <p><?= frs_te('privacy.s4.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('privacy.s4.consent_label'); ?></strong> <?= frs_te('privacy.s4.consent_text'); ?></li>
            <li><strong><?= frs_te('privacy.s4.function_label'); ?></strong> <?= frs_te('privacy.s4.function_text'); ?></li>
            <li><strong><?= frs_te('privacy.s4.obligation_label'); ?></strong> <?= frs_te('privacy.s4.obligation_text'); ?></li>
        </ul>

        <h4><?= frs_te('privacy.s5.title'); ?></h4>
        <p><?= frs_te('privacy.s5.body'); ?></p>
        <ul>
            <li><?= frs_te('privacy.s5.item1'); ?></li>
            <li><?= frs_te('privacy.s5.item2'); ?></li>
            <li><?= frs_te('privacy.s5.item3'); ?></li>
            <li><?= frs_te('privacy.s5.item4'); ?></li>
            <li><?= frs_te('privacy.s5.item5'); ?></li>
            <li><?= frs_te('privacy.s5.item6'); ?></li>
        </ul>

        <h4><?= frs_te('privacy.s6.title'); ?></h4>
        <p><?= frs_te('privacy.s6.body_prefix'); ?> <strong><?= frs_te('privacy.s6.body_strong'); ?></strong> <?= frs_te('privacy.s6.body_suffix'); ?></p>
        <ul>
            <li><?= frs_te('privacy.s6.item1'); ?></li>
            <li><?= frs_te('privacy.s6.item2'); ?></li>
            <li><?= frs_te('privacy.s6.item3'); ?></li>
            <li><?= frs_te('privacy.s6.item4'); ?></li>
        </ul>

        <h4><?= frs_te('privacy.s7.title'); ?></h4>
        <p><?= frs_te('privacy.s7.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('privacy.s7.active_label'); ?></strong>: <?= frs_te('privacy.s7.active_text'); ?></li>
            <li><strong><?= frs_te('privacy.s7.records_label'); ?></strong>: <?= frs_te('privacy.s7.records_text'); ?></li>
            <li><strong><?= frs_te('privacy.s7.logs_label'); ?></strong>: <?= frs_te('privacy.s7.logs_text'); ?></li>
        </ul>
        <p><?= frs_te('privacy.s7.footer'); ?></p>

        <h4><?= frs_te('privacy.s8.title'); ?></h4>
        <p><?= frs_te('privacy.s8.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('privacy.s8.access_label'); ?></strong>: <?= frs_te('privacy.s8.access_text'); ?></li>
            <li><strong><?= frs_te('privacy.s8.rectify_label'); ?></strong>: <?= frs_te('privacy.s8.rectify_text'); ?></li>
            <li><strong><?= frs_te('privacy.s8.erase_label'); ?></strong>: <?= frs_te('privacy.s8.erase_text'); ?></li>
            <li><strong><?= frs_te('privacy.s8.object_label'); ?></strong>: <?= frs_te('privacy.s8.object_text'); ?></li>
            <li><strong><?= frs_te('privacy.s8.portability_label'); ?></strong>: <?= frs_te('privacy.s8.portability_text'); ?></li>
            <li><strong><?= frs_te('privacy.s8.withdraw_label'); ?></strong>: <?= frs_te('privacy.s8.withdraw_text'); ?></li>
        </ul>
        <p><?= frs_te('privacy.s8.footer'); ?></p>

        <h4><?= frs_te('privacy.s9.title'); ?></h4>
        <p><?= frs_te('privacy.s9.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('privacy.s9.technical_label'); ?></strong>: <?= frs_te('privacy.s9.technical_text'); ?></li>
            <li><strong><?= frs_te('privacy.s9.organizational_label'); ?></strong>: <?= frs_te('privacy.s9.organizational_text'); ?></li>
            <li><strong><?= frs_te('privacy.s9.physical_label'); ?></strong>: <?= frs_te('privacy.s9.physical_text'); ?></li>
        </ul>

        <h4><?= frs_te('privacy.s10.title'); ?></h4>
        <p><?= frs_te('privacy.s10.body'); ?></p>
        <ul>
            <li><?= frs_te('privacy.s10.item1'); ?></li>
            <li><?= frs_te('privacy.s10.item2'); ?></li>
        </ul>
        <p><?= frs_te('privacy.s10.footer_prefix'); ?> <strong><?= frs_te('privacy.s10.footer_strong'); ?></strong><?= frs_te('privacy.s10.footer_suffix'); ?></p>

        <h4><?= frs_te('privacy.s11.title'); ?></h4>
        <p><?= frs_te('privacy.s11.body'); ?></p>
        <ul>
            <li><?= frs_te('privacy.s11.item1'); ?></li>
            <li><?= frs_te('privacy.s11.item2'); ?></li>
            <li><?= frs_te('privacy.s11.item3'); ?></li>
        </ul>

        <h4><?= frs_te('privacy.s12.title'); ?></h4>
        <p><?= frs_te('privacy.s12.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('privacy.s12.essential_label'); ?></strong>: <?= frs_te('privacy.s12.essential_text'); ?></li>
            <li><strong><?= frs_te('privacy.s12.analytics_label'); ?></strong>: <?= frs_te('privacy.s12.analytics_text'); ?></li>
        </ul>
        <p><?= frs_te('privacy.s12.footer'); ?></p>

        <h4><?= frs_te('privacy.s13.title'); ?></h4>
        <p><?= frs_te('privacy.s13.body'); ?></p>

        <h4><?= frs_te('privacy.s14.title'); ?></h4>
        <p><?= frs_te('privacy.s14.body'); ?></p>

        <h4><?= frs_te('privacy.s15.title'); ?></h4>
        <p><?= frs_te('privacy.s15.body'); ?></p>
        <ul>
            <li><strong><?= frs_te('privacy.s15.dpo_label'); ?></strong>: dpo@barangayculiat.gov.ph</li>
            <li><strong><?= frs_te('privacy.s15.office_label'); ?></strong>: Barangay Culiat Facilities Management Office</li>
            <li><strong><?= frs_te('privacy.s15.npc_label'); ?></strong>: complaints@privacy.gov.ph <?= frs_te('privacy.s15.npc_note'); ?></li>
        </ul>

        <p class="legal-content-meta"><strong><?= frs_te('privacy.meta.last_updated_label'); ?></strong>: <?= frs_te('privacy.meta.last_updated_value'); ?><br><strong><?= frs_te('privacy.meta.effective_label'); ?></strong>: <?= frs_te('privacy.meta.effective_value'); ?></p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
