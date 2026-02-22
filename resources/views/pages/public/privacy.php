<?php
$pageTitle = 'Privacy Policy | LGU Facilities Reservation';
ob_start();
?>
<section class="section legal-page-section">
    <div class="container legal-content">
        <h2>Privacy Policy</h2>
        <h3>Data Privacy Policy</h3>

        <h4>1. Data Controller</h4>
        <p>The Barangay Culiat Public Facilities Reservation System is operated by the Barangay Culiat Facilities Management Office, Quezon City. We are committed to protecting your personal data in accordance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong> and its Implementing Rules and Regulations.</p>

        <h4>2. Data Protection Officer</h4>
        <p>For privacy concerns, you may contact our Data Protection Officer:</p>
        <ul>
            <li>Email: dpo@barangayculiat.gov.ph</li>
            <li>Office: Barangay Culiat Facilities Management Office</li>
            <li>Contact: Via the Contact page of this portal</li>
        </ul>

        <h4>3. What Data We Collect</h4>
        <p>We collect only the minimum personal data required to process facility reservations:</p>
        <ul>
            <li><strong>Identity Information</strong>: Name, valid ID (optional)</li>
            <li><strong>Contact Information</strong>: Email address, mobile number</li>
            <li><strong>Address Information</strong>: Street, house number (to verify residency in Barangay Culiat)</li>
            <li><strong>Reservation Details</strong>: Facility, date, time, purpose, number of attendees</li>
        </ul>

        <h4>4. Why We Collect Your Data (Legal Basis)</h4>
        <p>We process your personal data based on:</p>
        <ul>
            <li><strong>Your consent</strong> when you register and accept this policy</li>
            <li><strong>Legitimate government function</strong> to manage public facilities and serve residents</li>
            <li><strong>Legal obligation</strong> to maintain records as required by government regulations</li>
        </ul>

        <h4>5. How We Use Your Data</h4>
        <p>Your information is used solely for:</p>
        <ul>
            <li>Verifying your identity and residency</li>
            <li>Processing and managing facility reservations</li>
            <li>Communicating reservation status and updates</li>
            <li>Coordinating facility usage and scheduling</li>
            <li>Sending official advisories related to your reservations</li>
            <li>Improving service delivery through anonymized analytics</li>
        </ul>

        <h4>6. Data Sharing and Disclosure</h4>
        <p>We do <strong>not sell or share</strong> your personal data with third parties, except:</p>
        <ul>
            <li>When required by law or court order</li>
            <li>When necessary to protect public safety or interest</li>
            <li>With other LGU offices for official coordination (e.g., disaster response)</li>
            <li>With your explicit consent</li>
        </ul>

        <h4>7. Data Retention</h4>
        <p>Personal data is retained for:</p>
        <ul>
            <li><strong>Active accounts</strong>: Duration of account + 3 years after last activity</li>
            <li><strong>Reservation records</strong>: 5 years as required by COA regulations</li>
            <li><strong>Audit logs</strong>: 2 years for security purposes</li>
        </ul>
        <p>After retention periods, data is securely deleted or anonymized.</p>

        <h4>8. Your Rights as a Data Subject</h4>
        <p>Under the Data Privacy Act, you have the right to:</p>
        <ul>
            <li><strong>Access</strong>: Request a copy of your personal data</li>
            <li><strong>Rectify</strong>: Correct inaccurate or incomplete information</li>
            <li><strong>Erase</strong>: Request deletion of your data (subject to legal retention requirements)</li>
            <li><strong>Object</strong>: Object to processing for direct marketing or automated decisions</li>
            <li><strong>Data Portability</strong>: Receive your data in a structured format</li>
            <li><strong>Withdraw Consent</strong>: Withdraw consent at any time (may affect service availability)</li>
        </ul>
        <p>To exercise these rights, contact our Data Protection Officer. You may also use the Profile page&apos;s &quot;Data Export&quot; feature to request a copy of your data; export files are available for 7 days after generation.</p>

        <h4>9. Security Measures</h4>
        <p>We implement robust security safeguards:</p>
        <ul>
            <li><strong>Technical</strong>: Encrypted storage, password hashing, secure connections (HTTPS)</li>
            <li><strong>Organizational</strong>: Role-based access control, staff training, audit logs</li>
            <li><strong>Physical</strong>: Secure server facilities, restricted access to systems</li>
        </ul>

        <h4>10. Automated Decision-Making</h4>
        <p>This system uses AI-powered features for:</p>
        <ul>
            <li>Conflict detection (alerts for double-booking)</li>
            <li>Facility recommendations based on your purpose</li>
        </ul>
        <p>These are <strong>advisory only</strong>. Final decisions on reservation approval are made by authorized LGU staff.</p>

        <h4>11. Data Breach Notification</h4>
        <p>In the unlikely event of a data breach affecting your personal information, we will:</p>
        <ul>
            <li>Notify the National Privacy Commission within 72 hours</li>
            <li>Notify affected individuals without undue delay</li>
            <li>Take immediate steps to contain and remediate the breach</li>
        </ul>

        <h4>12. Cookies and Tracking</h4>
        <p>This system uses:</p>
        <ul>
            <li><strong>Essential cookies</strong>: For authentication and session management (required)</li>
            <li><strong>Analytics</strong>: Anonymized usage data to improve services (optional)</li>
        </ul>
        <p>We do not use third-party tracking or advertising cookies.</p>

        <h4>13. Children's Privacy</h4>
        <p>This system is intended for users 18 years and older. We do not knowingly collect personal data from minors without parental or guardian consent.</p>

        <h4>14. Changes to This Policy</h4>
        <p>We may update this privacy policy to reflect changes in law or practice. Significant changes will be communicated via email or system notification.</p>

        <h4>15. Contact Us</h4>
        <p>For questions, concerns, or to exercise your data subject rights:</p>
        <ul>
            <li><strong>Data Protection Officer</strong>: dpo@barangayculiat.gov.ph</li>
            <li><strong>Office</strong>: Barangay Culiat Facilities Management Office</li>
            <li><strong>National Privacy Commission</strong>: complaints@privacy.gov.ph (for unresolved concerns)</li>
        </ul>

        <p class="legal-content-meta"><strong>Last Updated</strong>: February 1, 2026<br><strong>Effective Date</strong>: February 1, 2026</p>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
