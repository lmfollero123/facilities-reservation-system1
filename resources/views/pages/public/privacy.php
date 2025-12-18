<?php
$pageTitle = 'Privacy Policy | LGU Facilities Reservation';
ob_start();
?>
<section class="section">
    <div class="container legal-content">
        <h2>Privacy Policy</h2>
        <p>The LGU Facilities Reservation System collects only the minimum personal data required to process reservations, namely contact information, organization affiliation, event details, and supporting documents for verification. All records are handled in accordance with the Data Privacy Act of 2012 (RA 10173) and the LGU's Data Governance Manual.</p>
        
        <h3>Data Retention and Archival</h3>
        <p>Personal data is retained only for as long as necessary to fulfill the purpose for which it was collected, in compliance with legal requirements:</p>
        <ul>
            <li><strong>User Identity Documents:</strong> Retained for 7 years after account closure (BIR/NBI requirements, Data Privacy Act)</li>
            <li><strong>Registration Documents:</strong> Retained for 7 years after account approval/denial (Local Government retention policies)</li>
            <li><strong>Reservation Records:</strong> Retained for 5 years after reservation completion (Local Government records retention)</li>
            <li><strong>Audit Logs:</strong> Retained for 7 years minimum (accountability, audit trail requirements)</li>
            <li><strong>Security Logs:</strong> Retained for 3 years (security incident investigation)</li>
        </ul>
        <p>Documents older than 3 years may be archived to secure storage but remain accessible for legal compliance purposes. Archived documents are subject to the same security safeguards as active records.</p>
        
        <h3>Data Usage and Sharing</h3>
        <p>Information gathered through the portal is used solely for verification, scheduling, coordination, and communication of official advisories. Data shall not be shared with third parties unless authorized by law or necessary to protect public interest.</p>
        
        <h3>Your Rights</h3>
        <p>Under the Data Privacy Act, you have the following rights:</p>
        <ul>
            <li><strong>Right to Access:</strong> Request a copy of your personal data through the Profile page's "Data Export" feature</li>
            <li><strong>Right to Rectification:</strong> Update your profile information at any time through your account settings</li>
            <li><strong>Right to Data Portability:</strong> Export your data in a structured, machine-readable format (JSON)</li>
            <li><strong>Right to Erasure:</strong> Request deletion of your data, subject to legal retention requirements</li>
            <li><strong>Right to Object:</strong> Object to processing of your data for specific purposes</li>
        </ul>
        <p>Data export files are available for download for 7 days after generation for security purposes. Export requests are logged for audit trail compliance.</p>
        
        <h3>Security Safeguards</h3>
        <p>Security measures, including role-based access, audit logs, encrypted storage for archived documents, and secure file transfer protocols, are implemented to prevent unauthorized disclosure, access, or modification of personal data.</p>
        
        <h3>Contact</h3>
        <p>For privacy concerns, data access requests, or questions about our data retention policies, you may reach the LGU Data Protection Officer through the Facilities Management Office or via the Contact page of this portal.</p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


