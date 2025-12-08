<?php
$pageTitle = 'Terms and Conditions | LGU Facilities Reservation';
ob_start();
?>
<section class="section">
    <div class="container legal-content">
        <h2>Terms and Conditions</h2>
        <p>These Terms and Conditions govern the use of the Local Government Unit (LGU) Facilities Reservation System. By accessing the portal, citizens, organizations, and partner agencies agree to observe the policies set by the Municipal Facilities Management Office.</p>
        <p>Reservations are considered tentative until a confirmation notice is issued by the LGU. The LGU reserves the right to reassign, reschedule, or decline requests to ensure continuity of essential public services, disaster response operations, and official functions.</p>
        <p>Users shall provide accurate contact information, submit complete supporting documents, and settle applicable fees within the prescribed period. Non-compliance may result in cancellation without prejudice to future bookings.</p>
        <p>Any unauthorized commercial activity, political gathering without clearance, or activity that jeopardizes public safety is strictly prohibited. Damages to facilities shall be charged to the reserving party and may include administrative sanctions.</p>
        <p>By proceeding, you acknowledge that you have read and understood these terms and agree to comply with all LGU directives related to facility utilization.</p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


