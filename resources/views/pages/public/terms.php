<?php
$pageTitle = 'Terms and Conditions | LGU Facilities Reservation';
ob_start();
?>
<section class="section">
    <div class="container legal-content">
        <h2>Terms and Conditions</h2>
        <p>These Terms and Conditions govern the use of the Local Government Unit (LGU) Facilities Reservation System. By accessing the portal, citizens, organizations, and partner agencies agree to observe the policies set by the Municipal Facilities Management Office.</p>
        <p>Reservations are considered tentative until a confirmation notice is issued by the LGU. The LGU reserves the right to reassign, reschedule, or decline requests to ensure continuity of essential public services, disaster response operations, and official functions.</p>
        
        <h3>Reservation Policies</h3>
        <p>Users shall provide accurate contact information, submit complete supporting documents (Valid ID required), and settle applicable fees within the prescribed period. Non-compliance may result in cancellation without prejudice to future bookings.</p>
        <ul>
            <li><strong>Booking Limits:</strong> Maximum 3 active reservations per user (within 30 days), 60-day advance booking window, and 1 reservation per user per day</li>
            <li><strong>Rescheduling:</strong> Residents may reschedule their own reservations up to 3 days before the event, limited to one reschedule per reservation</li>
            <li><strong>Auto-Approval:</strong> Some reservations may be automatically approved if all conditions are met; staff can override any decision</li>
            <li><strong>Modifications:</strong> Approved reservations may be modified, postponed, or cancelled by staff with proper notification and reason</li>
        </ul>
        
        <h3>Prohibited Activities</h3>
        <p>Any unauthorized commercial activity, political gathering without clearance, or activity that jeopardizes public safety is strictly prohibited. Damages to facilities shall be charged to the reserving party and may include administrative sanctions.</p>
        
        <h3>User Violations and Penalties</h3>
        <p>Violations such as no-shows, late cancellations, policy violations, or facility damage may be recorded and may affect future auto-approval eligibility. Severe violations may result in account restrictions or suspension.</p>
        
        <h3>Data Retention and Privacy</h3>
        <p>By using this system, you consent to the collection, processing, and retention of your personal data as described in our Privacy Policy. Registration documents and reservation records are retained in accordance with Philippine legal requirements (Data Privacy Act of 2012, BIR regulations, Local Government retention policies).</p>
        <p>You have the right to request access to your data, export your information, and request deletion (subject to legal retention requirements) through your account Profile page.</p>
        
        <h3>Acceptance</h3>
        <p>By proceeding, you acknowledge that you have read and understood these terms, the Privacy Policy, and our data retention policies, and agree to comply with all LGU directives related to facility utilization and data processing.</p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


