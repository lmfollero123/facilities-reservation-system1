<?php
$pageTitle = 'Contact | LGU Facilities Reservation';
ob_start();
?>
<section class="section contact-section" style="min-height: calc(100vh - 200px); display: flex; align-items: center;">
    <div class="container" style="width: 100%; max-width: 600px;">
        <div class="auth-card" style="max-width: 100%;">
            <div class="auth-header">
                <div class="auth-icon">📧</div>
                <h1>Contact Us</h1>
                <p>Reach out to the Facilities Management Office</p>
            </div>
            
            <form class="auth-form">
                <label>
                    Full Name
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" placeholder="Juan Dela Cruz" required>
                    </div>
                </label>
                
                <label>
                    Email Address
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" placeholder="you@example.com" required>
                    </div>
                </label>
                
                <label>
                    Organization / Event
                    <div class="input-wrapper">
                        <span class="input-icon">🏛️</span>
                        <input type="text" placeholder="Barangay Assembly">
                    </div>
                </label>
                
                <label>
                    Message
                    <textarea rows="5" placeholder="Provide your inquiry or reservation number" style="width: 100%; padding: 0.9rem 1rem; border: 2px solid rgba(255, 255, 255, 0.3); border-radius: 8px; font-size: 1rem; font-family: inherit; transition: all 0.2s ease; background: rgba(255, 255, 255, 0.2); color: #fff; resize: vertical;" required></textarea>
                </label>
                
                <button class="btn-primary" type="button">Submit Inquiry</button>
            </form>
        </div>
    </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


