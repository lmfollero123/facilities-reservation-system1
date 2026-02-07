<?php
require_once __DIR__ . '/../../../config/database.php';
$base = base_path();
?>
<!-- Modern Footer - green gradient to match site theme -->
<footer class="modern-footer" style="background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; color: #ffffff !important;">
    <div class="container">
        <!-- Main Footer Content -->
        <div class="footer-grid">
            <!-- Branding Section -->
            <div class="footer-brand">
                <h3 class="brand-title">Barangay Culiat</h3>
                <p class="brand-subtitle">Public Facilities Reservation System</p>
                <p class="brand-description">
                    Simplifying facility reservations for our community with secure, efficient, and transparent booking services.
                </p>
            </div>
            
            <!-- Quick Links -->
            <div class="footer-section">
                <h4 class="footer-title">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="<?= $base; ?>/">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                        </svg>
                        Home
                    </a></li>
                    <li><a href="<?= $base; ?>/facilities">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd"></path>
                        </svg>
                        Browse Facilities
                    </a></li>
                    <li><a href="<?= $base; ?>/announcements">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M18 3a1 1 0 00-1.196-.98l-10 2A1 1 0 006 5v9.114A4.369 4.369 0 005 14c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V7.82l8-1.6v5.894A4.37 4.37 0 0015 12c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V3z"></path>
                        </svg>
                        Announcements
                    </a></li>
                    <li><a href="<?= $base; ?>/faqs">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path>
                        </svg>
                        FAQs
                    </a></li>
                    <li><a href="<?= $base; ?>/contact">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"></path>
                        </svg>
                        Contact Us
                    </a></li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="<?= $base; ?>/dashboard/my-reservations">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                        </svg>
                        My Reservations
                    </a></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <!-- Legal & Compliance -->
            <div class="footer-section">
                <h4 class="footer-title">Legal & Compliance</h4>
                <ul class="footer-links">
                    <li><a href="<?= $base; ?>/privacy">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                        </svg>
                        Privacy Policy
                    </a></li>
                    <li><a href="<?= $base; ?>/register#terms" id="footerTermsLink">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"></path>
                        </svg>
                        Terms & Conditions
                    </a></li>
                    <li><a href="<?= $base; ?>/privacy">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"></path>
                        </svg>
                        Data Privacy Notice
                    </a></li>
                </ul>
                <div class="compliance-badge">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20" class="me-2">
                        <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <small>Compliant with RA 10173<br>(Data Privacy Act of 2012)</small>
                </div>
            </div>
        </div>
        
        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <p>&copy; <?= date('Y'); ?> Barangay Culiat, Quezon City. All rights reserved.</p>
            <p class="footer-tagline">Serving our community with transparency and efficiency</p>
        </div>
    </div>
</footer>

<style>
/* Modern Footer Styling - green gradient to match site theme */
.modern-footer {
    background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important;
    color: #ffffff !important;
    padding: 4rem 0 2rem !important;
    margin-top: 0 !important;
}

.modern-footer .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 2rem;
}

.footer-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 3rem;
    margin-bottom: 3rem;
}

@media (min-width: 768px) {
    .footer-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 1024px) {
    .footer-grid {
        grid-template-columns: 1.5fr 1fr 1fr;
        gap: 4rem;
    }
}

/* Branding Section */
.footer-brand {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.brand-title {
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0;
    color: #ffffff !important;
    line-height: 1.2;
}

.brand-subtitle {
    font-size: 1.0625rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95) !important;
    margin: 0;
}

.brand-description {
    font-size: 1rem;
    color: rgba(255, 255, 255, 0.9) !important;
    line-height: 1.6;
    margin: 0;
}

/* Footer Sections */
.footer-section {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.footer-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #ffffff !important;
    margin: 0;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid rgba(255, 255, 255, 0.2);
}

.footer-links {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.footer-links li {
    margin: 0;
}

.footer-links a {
    color: rgba(255, 255, 255, 0.95) !important;
    text-decoration: none !important;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    padding: 0.25rem 0;
}

.footer-links a:hover {
    color: #ffffff !important;
    transform: translateX(5px);
}

.footer-links svg {
    flex-shrink: 0;
    opacity: 0.7;
}

/* Compliance Badge */
.compliance-badge {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 1rem;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    border-left: 3px solid rgba(255, 255, 255, 0.3);
    margin-top: 0.5rem;
}

.compliance-badge svg {
    flex-shrink: 0;
    margin-top: 0.125rem;
    color: rgba(255, 255, 255, 0.9) !important;
}

.compliance-badge small {
    font-size: 0.9375rem;
    color: rgba(255, 255, 255, 0.95) !important;
    line-height: 1.5;
}

/* Footer Bottom */
.footer-bottom {
    text-align: center;
    padding-top: 2rem;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
}

.footer-bottom p {
    margin: 0.5rem 0;
    font-size: 1rem;
    color: rgba(255, 255, 255, 0.9) !important;
}

.footer-tagline {
    font-size: 0.9375rem !important;
    color: rgba(255, 255, 255, 0.85) !important;
    font-style: italic;
}

/* Mobile Responsive */
@media (max-width: 767px) {
    .modern-footer {
        padding: 3rem 0 1.5rem !important;
        margin-top: 0 !important;
    }
    
    .modern-footer .container {
        padding: 0 1.5rem;
    }
    
    .footer-grid {
        gap: 2.5rem;
        margin-bottom: 2rem;
    }
    
    .brand-title {
        font-size: 1.5rem;
    }
    
    .footer-title {
        font-size: 1rem;
    }
    
    .footer-links a {
        font-size: 0.875rem;
    }
}
</style>

<script>
// Handle footer terms link click
document.getElementById('footerTermsLink')?.addEventListener('click', function(e) {
    if (document.getElementById('termsModal')) {
        return;
    }
    e.preventDefault();
    window.location.href = '<?= $base; ?>/register#terms';
});
</script>