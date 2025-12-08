<?php
$pageTitle = 'Privacy Policy | LGU Facilities Reservation';
ob_start();
?>
<section class="section">
    <div class="container legal-content">
        <h2>Privacy Policy</h2>
        <p>The LGU Facilities Reservation System collects only the minimum personal data required to process reservations, namely contact information, organization affiliation, and event details. All records are handled in accordance with the Data Privacy Act of 2012 and the LGU’s Data Governance Manual.</p>
        <p>Information gathered through the portal is used solely for verification, scheduling, coordination, and communication of official advisories. Data shall not be shared with third parties unless authorized by law or necessary to protect public interest.</p>
        <p>Users have the right to request access to their submitted data, rectify inaccuracies, and withdraw consent subject to existing retention policies. Security safeguards, including role-based access, audit logs, and encrypted storage, are implemented to prevent unauthorized disclosure.</p>
        <p>For privacy concerns, you may reach the LGU Data Protection Officer through the Facilities Management Office or via the Contact page of this portal.</p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


