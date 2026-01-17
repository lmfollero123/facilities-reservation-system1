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
<section class="page-section contact-section-page" id="contact">
    <div class="container px-4 px-lg-5">
        <div class="contact-wrapper">
            <div class="text-center mb-5">
                <h2 class="mt-0">Contact Us</h2>
                <hr class="divider" />
                <p class="text-muted mb-0">Get in touch with Barangay Culiat Facilities Management Office</p>
            </div>

            <div class="contact-content">
                <div class="contact-card">
                <div class="contact-header">
                    <i class="bi bi-building"></i>
                    <h3><?= htmlspecialchars($contactInfo['office_name']); ?></h3>
                </div>

                <div class="contact-info-grid">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div class="contact-details">
                            <strong>Address</strong>
                            <p><?= htmlspecialchars($contactInfo['address']); ?></p>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-telephone-fill"></i>
                        </div>
                        <div class="contact-details">
                            <strong>Phone</strong>
                            <p><?= htmlspecialchars($contactInfo['phone']); ?></p>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-phone-fill"></i>
                        </div>
                        <div class="contact-details">
                            <strong>Mobile</strong>
                            <p><?= htmlspecialchars($contactInfo['mobile']); ?></p>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-envelope-fill"></i>
                        </div>
                        <div class="contact-details">
                            <strong>Email</strong>
                            <p><a href="mailto:<?= htmlspecialchars($contactInfo['email']); ?>"><?= htmlspecialchars($contactInfo['email']); ?></a></p>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="contact-details">
                            <strong>Office Hours</strong>
                            <p><?= $contactInfo['office_hours']; ?></p>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
/* Contact Section with Glassmorphism Background */
.contact-section-page {
    background: url("<?= $base; ?>/public/img/cityhall.jpeg") center/cover no-repeat fixed;
    min-height: 100vh;
    position: relative;
    padding: 4rem 0;
}

.contact-section-page::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.2);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    z-index: 0;
}

.contact-wrapper {
    position: relative;
    z-index: 1;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 3rem 2rem;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    max-width: 900px;
    margin: 0 auto;
}

@media (max-width: 767px) {
    .contact-section-page {
        padding: 2rem 0;
    }
    
    .contact-wrapper {
        padding: 2rem 1.5rem;
        border-radius: 16px;
    }
}

.contact-content {
    max-width: 800px;
    margin: 0 auto;
}

.contact-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 2.5rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.contact-header {
    text-align: center;
    margin-bottom: 2.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px solid #285ccd;
}

.contact-header i {
    font-size: 3rem;
    color: #285ccd;
    margin-bottom: 1rem;
}

.contact-header h3 {
    color: #1e3a5f;
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
}

.contact-info-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 768px) {
    .contact-info-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.contact-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem;
    background: #f8f9fa;
    border-radius: 12px;
    transition: all 0.2s ease;
}

.contact-item:hover {
    background: #f0f4ff;
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.contact-icon {
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

.contact-details {
    flex: 1;
}

.contact-details strong {
    display: block;
    color: #1e3a5f;
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.contact-details p {
    margin: 0;
    color: #4a5568;
    font-size: 1rem;
    line-height: 1.6;
}

.contact-details a {
    color: #285ccd;
    text-decoration: none;
    transition: color 0.2s ease;
}

.contact-details a:hover {
    color: #1e40af;
    text-decoration: underline;
}

/* Mobile Responsive */
@media (max-width: 767px) {
    .contact-card {
        padding: 1.5rem;
    }
    
    .contact-header i {
        font-size: 2.5rem;
    }
    
    .contact-header h3 {
        font-size: 1.25rem;
    }
    
    .contact-item {
        padding: 1rem;
    }
    
    .contact-icon {
        width: 40px;
        height: 40px;
        font-size: 1.25rem;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
?>
