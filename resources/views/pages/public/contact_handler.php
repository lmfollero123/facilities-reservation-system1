<?php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$name = sanitizeInput($_POST['name'] ?? '', 'string');
$email = sanitizeInput($_POST['email'] ?? '', 'email');
$organization = sanitizeInput($_POST['organization'] ?? '', 'string');
$message = sanitizeInput($_POST['message'] ?? '', 'string');

// Validation
$errors = [];
if (empty($name) || strlen($name) < 2) {
    $errors[] = 'Please enter your full name.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}
if (empty($message) || strlen($message) < 10) {
    $errors[] = 'Please enter a message (at least 10 characters).';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

try {
    $pdo = db();
    
    // Save inquiry to database
    $stmt = $pdo->prepare(
        'INSERT INTO contact_inquiries (name, email, organization, message, status) 
         VALUES (?, ?, ?, ?, "new")'
    );
    $stmt->execute([$name, $email, $organization ?: null, $message]);
    $inquiryId = $pdo->lastInsertId();
    
    // Get admin emails
    $adminStmt = $pdo->query("SELECT email, name FROM users WHERE role IN ('Admin', 'Staff') AND status = 'active'");
    $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Send email notification to admins
    if (!empty($admins)) {
        $subject = "New Contact Inquiry - Barangay Culiat Facilities";
        $htmlBody = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #285ccd;'>New Contact Inquiry Received</h2>
            <p>A new inquiry has been submitted through the contact form:</p>
            <div style='background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>
                <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                " . ($organization ? "<p><strong>Organization:</strong> " . htmlspecialchars($organization) . "</p>" : "") . "
                <p><strong>Message:</strong></p>
                <p style='white-space: pre-wrap;'>" . nl2br(htmlspecialchars($message)) . "</p>
            </div>
            <p><a href='" . base_path() . "/resources/views/pages/dashboard/contact_inquiries.php?id=" . $inquiryId . "' style='background: #285ccd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>View in Dashboard</a></p>
        </body>
        </html>";
        
        foreach ($admins as $admin) {
            sendEmail($admin['email'], $admin['name'], $subject, $htmlBody);
        }
    }
    
    // Send confirmation email to user
    $userSubject = "Thank You for Contacting Barangay Culiat Facilities";
    $userHtmlBody = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <h2 style='color: #285ccd;'>Thank You for Your Inquiry</h2>
        <p>Dear " . htmlspecialchars($name) . ",</p>
        <p>We have received your inquiry and will get back to you as soon as possible.</p>
        <p><strong>Your Message:</strong></p>
        <div style='background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;'>
            <p style='white-space: pre-wrap;'>" . nl2br(htmlspecialchars($message)) . "</p>
        </div>
        <p>Best regards,<br>Barangay Culiat Facilities Management Office</p>
    </body>
    </html>";
    
    sendEmail($email, $name, $userSubject, $userHtmlBody);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Thank you for your inquiry! We will get back to you soon.'
    ]);
    
} catch (Exception $e) {
    error_log('Contact form error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
}


