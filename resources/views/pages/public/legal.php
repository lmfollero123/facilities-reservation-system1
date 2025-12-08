<?php
$pageTitle = 'Legal Notice | LGU Facilities Reservation';
ob_start();
?>
<section class="section">
    <div class="container legal-content">
        <h2>Legal Notice</h2>
        <p>The LGU Facilities Reservation System is an official electronic service of the Local Government Unit. All content, logos, and materials hosted within the portal are protected by Philippine intellectual property and administrative laws.</p>
        <p>Unauthorized alteration, data scraping, or malicious disruption of this system is punishable under the E-Commerce Act, the Cybercrime Prevention Act, and other applicable statutes. The LGU will cooperate with law enforcement agencies to investigate cyber incidents arising from misuse.</p>
        <p>Any dispute arising from facility reservations shall be governed by local ordinances, Civil Code provisions, and applicable national regulations. Venue for legal action shall be the proper courts of the LGU’s jurisdiction.</p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


