<?php
$pageTitle = frs_t('legal.page_title');
ob_start();
?>
<section class="section">
    <div class="container legal-content">
        <h2><?= frs_te('legal.heading'); ?></h2>
        <p><?= frs_te('legal.p1'); ?></p>
        <p><?= frs_te('legal.p2'); ?></p>
        <p><?= frs_te('legal.p3'); ?></p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


