<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
$pageTitle = 'Contact | LGU Facilities Reservation';
$base = base_path();
ob_start();
?>
<section class="section contact-section">
    <div class="container" style="width: 100%; max-width: 600px;">
        <div class="auth-card" style="max-width: 100%;">
            <div class="auth-header">
                <div class="auth-icon">📧</div>
                <h1>Contact Us</h1>
                <p>Reach out to the Facilities Management Office</p>
            </div>
            
            <div id="contactMessage" style="display: none; margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.9rem;"></div>
            
            <form class="auth-form" id="contactForm">
                <?= csrf_field(); ?>
                <label>
                    Full Name
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" name="name" placeholder="Juan Dela Cruz" required minlength="2">
                    </div>
                </label>
                
                <label>
                    Email Address
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                </label>
                
                <label>
                    Organization / Event
                    <div class="input-wrapper">
                        <span class="input-icon">🏛️</span>
                        <input type="text" name="organization" placeholder="Barangay Assembly">
                    </div>
                </label>
                
                <label>
                    Message
                    <textarea name="message" rows="5" placeholder="Provide your inquiry or reservation number" style="width: 100%; padding: 0.9rem 1rem; border: 2px solid rgba(255, 255, 255, 0.3); border-radius: 8px; font-size: 1rem; font-family: inherit; transition: all 0.2s ease; background: rgba(255, 255, 255, 0.2); color: #fff; resize: vertical;" required minlength="10"></textarea>
                </label>
                
                <button class="btn-primary" type="submit" id="submitBtn">Submit Inquiry</button>
            </form>
        </div>
    </div>
</section>

<script>
document.getElementById('contactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = this;
    const submitBtn = document.getElementById('submitBtn');
    const messageDiv = document.getElementById('contactMessage');
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';
    messageDiv.style.display = 'none';
    
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= $base; ?>/resources/views/pages/public/contact_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        messageDiv.style.display = 'block';
        messageDiv.style.background = data.success ? '#e3f8ef' : '#fdecee';
        messageDiv.style.color = data.success ? '#0d7a43' : '#b23030';
        messageDiv.textContent = data.message;
        
        if (data.success) {
            form.reset();
        }
    } catch (error) {
        messageDiv.style.display = 'block';
        messageDiv.style.background = '#fdecee';
        messageDiv.style.color = '#b23030';
        messageDiv.textContent = 'An error occurred. Please try again.';
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Inquiry';
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';


