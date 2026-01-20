<?php
require_once __DIR__ . '/../../../config/database.php';
$base = base_path();

// Fetch contact information from database
$pdo = db();
$contactStmt = $pdo->query(
    'SELECT field_name, field_value FROM contact_info ORDER BY display_order ASC, id ASC'
);
$contactData = [];
while ($row = $contactStmt->fetch(PDO::FETCH_ASSOC)) {
    $contactData[$row['field_name']] = $row['field_value'];
}

// Set defaults if no data exists
$contactInfo = [
    'office_name' => $contactData['office_name'] ?? 'Barangay Culiat Facilities Management Office',
    'address' => $contactData['address'] ?? 'Barangay Culiat, Quezon City, Metro Manila',
    'phone' => $contactData['phone'] ?? '(02) 1234-5678',
    'mobile' => $contactData['mobile'] ?? '0912-345-6789',
    'email' => $contactData['email'] ?? 'facilities@barangayculiat.gov.ph',
    'office_hours' => $contactData['office_hours'] ?? 'Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM<br>Sunday: Closed',
];
?>
<!-- Footer -->
<footer class="bg-light lgu-footer">
    <div class="container px-4 px-lg-5">
        <div class="footer-content">
            <!-- LGU Identity Section (Leftmost/Top) -->
            <div class="footer-section footer-identity">
                <h3 class="footer-heading">Barangay Culiat, Quezon City</h3>
                <p class="footer-office-name"><?= htmlspecialchars($contactInfo['office_name']); ?></p>
                <div class="footer-contact">
                    <p class="footer-address">
                        <i class="bi bi-geo-alt-fill"></i>
                        <?= htmlspecialchars($contactInfo['address']); ?>
                    </p>
                    <p class="footer-phone">
                        <i class="bi bi-telephone-fill"></i>
                        <?= htmlspecialchars($contactInfo['phone']); ?>
                    </p>
                    <p class="footer-mobile">
                        <i class="bi bi-phone-fill"></i>
                        <?= htmlspecialchars($contactInfo['mobile']); ?>
                    </p>
                    <p class="footer-email">
                        <i class="bi bi-envelope-fill"></i>
                        <a href="mailto:<?= htmlspecialchars($contactInfo['email']); ?>"><?= htmlspecialchars($contactInfo['email']); ?></a>
                    </p>
                </div>
            </div>

            <!-- Quick Links Section -->
            <div class="footer-section footer-links">
                <h3 class="footer-heading">Quick Links</h3>
                <ul class="footer-link-list">
                    <li><a href="<?= $base; ?>/">Home</a></li>
                    <li><a href="<?= $base; ?>/facilities">Facilities</a></li>
                    <li><a href="<?= $base; ?>/dashboard/my-reservations">Reservation Status</a></li>
                    <li><a href="<?= $base; ?>/announcements">Announcements</a></li>
                    <li><a href="<?= $base; ?>/contact">Operating Hours</a></li>
                    <li><a href="<?= $base; ?>/faqs">FAQs</a></li>
                    <li><a href="<?= $base; ?>/contact">Contact Us</a></li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="<?= $base; ?>/dashboard/my-reservations">My Reservations</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Legal and Compliance Links -->
            <div class="footer-section footer-legal">
                <h3 class="footer-heading">Legal & Compliance</h3>
                <ul class="footer-link-list">
                    <li><a href="<?= $base; ?>/privacy">Privacy Policy</a></li>
                    <li><a href="<?= $base; ?>/register#terms" id="footerTermsLink">Terms and Conditions</a></li>
                    <li><a href="<?= $base; ?>/privacy">Data Privacy Notice</a></li>
                </ul>
                <p class="footer-legal-note">
                    <small>In compliance with Republic Act No. 10173 (Data Privacy Act of 2012)</small>
                </p>
            </div>


        </div>

        <!-- Copyright Line (Bottom) -->
        <div class="footer-bottom">
            <div class="footer-copyright">
                <p>&copy; <?= date('Y'); ?> Barangay Culiat. All rights reserved.</p>
            </div>
        </div>
    </div>
</footer>

<style>
/* LGU Footer Styling */
.lgu-footer {
    background: #f5f6f8 !important;
    border-top: 1px solid #e5e7eb;
    padding: 5rem 0 2rem !important;
    width: 100%;
}

/* Stronger selector to ensure padding applies */
footer.lgu-footer {
    padding-top: 5rem !important;
    padding-bottom: 2rem !important;
}

.lgu-footer .container {
    max-width: 100% !important;
    padding-left: 2rem;
    padding-right: 2rem;
    margin: 0 auto;
}

.footer-content {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.75rem;
    width: 100%;
    margin: 0 auto 1.5rem;
    align-items: start;
    padding-top: 2rem;
}

@media (min-width: 768px) {
    .lgu-footer .container {
        padding-left: 3rem;
        padding-right: 3rem;
    }
    
    .footer-content {
        grid-template-columns: repeat(2, minmax(200px, 1fr));
        gap: 2rem;
    }
}

@media (min-width: 1024px) {
    .lgu-footer .container {
        padding-left: 4rem;
        padding-right: 4rem;
    }
    
    /* Spread sections across full width on wide screens */
    .footer-content {
        grid-template-columns: 1.8fr 1fr 1fr;
        gap: 3rem;
        justify-content: space-between;
    }
}

@media (min-width: 1400px) {
    .lgu-footer .container {
        padding-left: 5rem;
        padding-right: 5rem;
    }
    
    .footer-content {
        gap: 4rem;
    }
}

.footer-section {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.footer-heading {
    font-size: 1.125rem;
    font-weight: 700;
    color: #1e3a5f;
    margin: 0 0 0.75rem 0;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #285ccd;
}

.footer-office-name {
    font-size: 0.9375rem;
    color: #4a5568;
    margin: 0;
    font-weight: 600;
}

.footer-contact {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.footer-contact p {
    margin: 0;
    font-size: 0.875rem;
    color: #4a5568;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    line-height: 1.5;
}

.footer-contact i {
    color: #285ccd;
    font-size: 1rem;
    margin-top: 0.125rem;
    flex-shrink: 0;
}

.footer-contact a {
    color: #285ccd;
    text-decoration: none;
    transition: color 0.2s ease;
}

.footer-contact a:hover {
    color: #1e40af;
    text-decoration: underline;
}

.footer-link-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.footer-link-list li {
    margin: 0;
}

.footer-link-list a {
    color: #4a5568;
    text-decoration: none;
    font-size: 0.9375rem;
    transition: all 0.2s ease;
    display: inline-block;
}

.footer-link-list a:hover {
    color: #285ccd;
    transform: translateX(4px);
    text-decoration: none;
}

.footer-legal-note {
    margin-top: 0.75rem;
    font-size: 0.8125rem;
    color: #6b7280;
    line-height: 1.5;
}

.system-name {
    font-size: 1rem;
    font-weight: 600;
    color: #1e3a5f;
    margin: 0 0 0.25rem 0;
}

.system-version {
    font-size: 0.875rem;
    color: #6b7280;
    margin: 0 0 0.75rem 0;
}

.system-developer {
    font-size: 0.875rem;
    color: #4a5568;
    margin: 0 0 1rem 0;
    font-style: italic;
}

.footer-support {
    margin-top: 0.5rem;
}

.support-hours {
    font-size: 0.875rem;
    color: #4a5568;
    margin: 0;
    line-height: 1.6;
}

.support-hours strong {
    color: #1e3a5f;
    display: block;
    margin-bottom: 0.25rem;
}

.footer-bottom {
    border-top: 1px solid #e5e7eb;
    padding-top: 1.5rem;
    text-align: center;
}.footer-copyright p {
    margin: 0;
    font-size: 0.9375rem;
    color: #6b7280;
}

/* Mobile Responsive */
@media (max-width: 767px) {
    .lgu-footer {
        padding: 2rem 1rem 1.25rem !important;
    }
    
    .footer-content {
        gap: 2rem;
    }
    
    .footer-heading {
        font-size: 1rem;
    }
    
    .footer-link-list a {
        font-size: 0.875rem;
    }
}
</style>

<script>
// Handle footer terms link click (same as registration page)
document.getElementById('footerTermsLink')?.addEventListener('click', function(e) {
    // If on registration page, let the existing handler manage it
    if (document.getElementById('termsModal')) {
        return; // Let existing handler work
    }
    // Otherwise, navigate to registration page with anchor
    e.preventDefault();
    window.location.href = '<?= $base; ?>/register#terms';
});
</script>