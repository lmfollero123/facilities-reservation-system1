<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
$pageTitle = 'Contact | Barangay Culiat Public Facilities Reservation';
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

ob_start();
?>
<section class="contact-section-modern" id="contact">
    <div class="container-modern">
        <!-- Header -->
        <div class="contact-header-modern">
            <h1 class="contact-title">Contact Us</h1>
            <p class="contact-subtitle">Get in touch with Barangay Culiat Facilities Management Office</p>
        </div>

        <!-- Main Content -->
        <div class="contact-content-wrapper">
            <!-- Office Info Card -->
            <div class="info-card-main">
                <div class="info-card-header">
                    <i class="bi bi-building"></i>
                    <h2><?= htmlspecialchars($contactInfo['office_name']); ?></h2>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div class="info-content">
                            <strong>Address</strong>
                            <p><?= htmlspecialchars($contactInfo['address']); ?></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-telephone-fill"></i>
                        </div>
                        <div class="info-content">
                            <strong>Phone</strong>
                            <p><a href="tel:<?= htmlspecialchars($contactInfo['phone']); ?>"><?= htmlspecialchars($contactInfo['phone']); ?></a></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-phone-fill"></i>
                        </div>
                        <div class="info-content">
                            <strong>Mobile</strong>
                            <p><a href="tel:<?= htmlspecialchars($contactInfo['mobile']); ?>"><?= htmlspecialchars($contactInfo['mobile']); ?></a></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-envelope-fill"></i>
                        </div>
                        <div class="info-content">
                            <strong>Email</strong>
                            <p><a href="mailto:<?= htmlspecialchars($contactInfo['email']); ?>"><?= htmlspecialchars($contactInfo['email']); ?></a></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="info-content">
                            <strong>Office Hours</strong>
                            <p><?= $contactInfo['office_hours']; ?></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div class="info-content">
                            <strong>Data Protection Officer</strong>
                            <p><a href="mailto:dpo@barangayculiat.gov.ph">dpo@barangayculiat.gov.ph</a></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Links Card -->
            <div class="quick-links-card">
                <h3><i class="bi bi-link-45deg"></i> Quick Links</h3>
                <div class="quick-links">
                    <a href="<?= $base; ?>/resources/views/pages/public/faq.php" class="quick-link">
                        <i class="bi bi-question-circle"></i>
                        <span>Frequently Asked Questions</span>
                    </a>
                    <a href="<?= $base; ?>/resources/views/pages/public/facilities.php" class="quick-link">
                        <i class="bi bi-building"></i>
                        <span>Browse Facilities</span>
                    </a>
                    <a href="<?= $base; ?>/resources/views/pages/auth/register.php" class="quick-link">
                        <i class="bi bi-person-plus"></i>
                        <span>Create an Account</span>
                    </a>
                    <a href="<?= $base; ?>/resources/views/pages/auth/login.php" class="quick-link">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span>Login to Your Account</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
/* Modern Contact Section */
.contact-section-modern {
    background: url("<?= $base; ?>/public/img/cityhall.jpeg") center/cover no-repeat fixed;
    min-height: 100vh;
    position: relative;
    padding: 6rem 0 4rem;
    display: flex;
    align-items: center;
}

.contact-section-modern::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.3);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    z-index: 0;
}

.container-modern {
    position: relative;
    z-index: 1;
    max-width: 1000px;
    margin: 0 auto;
    padding: 0 2rem;
    width: 100%;
}

/* Header */
.contact-header-modern {
    text-align: center;
    margin-bottom: 3rem;
}

.contact-title {
    font-size: 3rem;
    font-weight: 700;
    color: #ffffff;
    margin: 0 0 1rem;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.contact-subtitle {
    font-size: 1.125rem;
    color: rgba(255, 255, 255, 0.95);
    margin: 0 auto;
    line-height: 1.6;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

/* Content Wrapper */
.contact-content-wrapper {
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

/* Main Info Card */
.info-card-main {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 2.5rem;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.5);
}

.info-card-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    margin-bottom: 2.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.info-card-header i {
    font-size: 2.5rem;
    color: #285ccd;
}

.info-card-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e3a5f;
    margin: 0;
    text-align: center;
}

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 768px) {
    .info-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.info-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem;
    background: #f8f9fa;
    border-radius: 12px;
    transition: all 0.2s ease;
}

.info-item:hover {
    background: #f0f4ff;
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.info-icon {
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #285ccd, #6384d2);
    border-radius: 12px;
    color: #ffffff;
    font-size: 1.5rem;
}

.info-content {
    flex: 1;
}

.info-content strong {
    display: block;
    color: #1e3a5f;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.info-content p {
    margin: 0;
    color: #4a5568;
    font-size: 1rem;
    line-height: 1.5;
}

.info-content a {
    color: #285ccd;
    text-decoration: none;
    transition: color 0.2s ease;
    font-weight: 500;
}

.info-content a:hover {
    color: #1e40af;
    text-decoration: underline;
}

/* Quick Links Card */
.quick-links-card {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 2rem;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.5);
}

.quick-links-card h3 {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e3a5f;
    margin: 0 0 1.5rem;
}

.quick-links-card h3 i {
    color: #285ccd;
    font-size: 1.5rem;
}

.quick-links {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

@media (min-width: 768px) {
    .quick-links {
        grid-template-columns: repeat(2, 1fr);
    }
}

.quick-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    background: #f8f9fa;
    border-radius: 12px;
    color: #1e3a5f;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.quick-link:hover {
    background: linear-gradient(135deg, #285ccd, #6384d2);
    color: #ffffff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 92, 205, 0.3);
    border-color: #285ccd;
}

.quick-link i {
    font-size: 1.5rem;
    flex-shrink: 0;
}

.quick-link span {
    flex: 1;
}

/* Mobile Responsive */
@media (max-width: 991px) {
    .contact-section-modern {
        padding: 4rem 0 3rem;
    }
    
    .container-modern {
        padding: 0 1.5rem;
    }
    
    .contact-title {
        font-size: 2.25rem;
    }
    
    .contact-subtitle {
        font-size: 1rem;
    }
    
    .info-card-main,
    .quick-links-card {
        padding: 2rem;
    }
}

@media (max-width: 576px) {
    .contact-title {
        font-size: 1.875rem;
    }
    
    .info-card-header {
        flex-direction: column;
        text-align: center;
    }
    
    .info-card-header h2 {
        font-size: 1.25rem;
    }
    
    .info-card-main,
    .quick-links-card {
        padding: 1.5rem;
    }
    
    .info-item {
        padding: 1rem;
    }
    
    .info-icon {
        width: 40px;
        height: 40px;
        font-size: 1.25rem;
    }
    
    .quick-links {
        grid-template-columns: 1fr;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
?>
