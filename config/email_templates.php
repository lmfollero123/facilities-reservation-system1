<?php
/**
 * Email Template Helper Functions
 * Provides beautifully formatted email templates for the LGU Facilities Reservation System
 */

/**
 * Generate email header with logo and styling
 */
function getEmailHeader($title = 'LGU Facilities Reservation System') {
    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($title) . '</title>
    </head>
    <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f5f7fa; line-height: 1.6;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f7fa; padding: 20px 0;">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);">
                        <!-- Header -->
                        <tr>
                            <td style="background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%); padding: 30px 40px; text-align: center;">
                                <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">LGU Facilities Reservation</h1>
                                <p style="margin: 5px 0 0; color: rgba(255, 255, 255, 0.9); font-size: 14px;">Barangay Culiat, Quezon City</p>
                            </td>
                        </tr>
                        <!-- Content -->
                        <tr>
                            <td style="padding: 40px;">
    ';
}

/**
 * Generate email footer
 */
function getEmailFooter() {
    $currentYear = date('Y');
    return '
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style="background-color: #f8f9fa; padding: 30px 40px; text-align: center; border-top: 1px solid #e0e6ed;">
                                <p style="margin: 0 0 10px; color: #6b7280; font-size: 14px;">
                                    <strong>LGU Facilities Reservation System</strong><br>
                                    Barangay Culiat, Quezon City, Metro Manila
                                </p>
                                <p style="margin: 0; color: #9ca3af; font-size: 12px;">
                                    © ' . $currentYear . ' Barangay Culiat. All rights reserved.
                                </p>
                                <p style="margin: 10px 0 0; color: #9ca3af; font-size: 12px;">
                                    This is an automated message. Please do not reply to this email.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ';
}

/**
 * Create a styled button for emails
 */
function getEmailButton($text, $url, $color = '#6384d2') {
    return '
    <table cellpadding="0" cellspacing="0" style="margin: 20px 0;">
        <tr>
            <td style="border-radius: 8px; background-color: ' . $color . ';">
                <a href="' . htmlspecialchars($url) . '" target="_blank" style="display: inline-block; padding: 14px 32px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px;">
                    ' . htmlspecialchars($text) . '
                </a>
            </td>
        </tr>
    </table>
    ';
}

/**
 * Create an info box for emails
 */
function getEmailInfoBox($content, $bgColor = '#f0f8ff', $borderColor = '#6384d2') {
    return '
    <div style="background: ' . $bgColor . '; border-left: 4px solid ' . $borderColor . '; border-radius: 8px; padding: 16px 20px; margin: 20px 0;">
        ' . $content . '
    </div>
    ';
}

/**
 * OTP Email Template
 */
function getOTPEmailTemplate($userName, $otpCode, $expiryMinutes = 10) {
    $header = getEmailHeader('Login Verification Code');
    $footer = getEmailFooter();
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">Login Verification Code</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            You requested a one-time password (OTP) to log in to your account. Use the code below to complete your login:
        </p>
        
        ' . getEmailInfoBox('
            <p style="margin: 0; text-align: center;">
                <span style="font-size: 14px; color: #6b7280; display: block; margin-bottom: 8px;">Your OTP Code</span>
                <span style="font-size: 36px; font-weight: 700; color: #285ccd; letter-spacing: 8px; font-family: \'Courier New\', monospace;">' . htmlspecialchars($otpCode) . '</span>
            </p>
        ', '#e3f2fd', '#2196f3') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            <strong>⏱️ This code will expire in ' . $expiryMinutes . ' minutes.</strong>
        </p>
        <p style="margin: 10px 0 0; color: #6b7280; font-size: 14px;">
            If you didn\'t request this code, please ignore this email or contact support if you\'re concerned about your account security.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Account Approved Email Template
 */
function getAccountApprovedEmailTemplate($userName) {
    $header = getEmailHeader('Account Approved');
    $footer = getEmailFooter();
    $loginUrl = base_url() . '/login';
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">🎉 Your Account Has Been Approved!</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Great news! Your account has been approved by our administrators. You can now sign in and start booking facilities.
        </p>
        
        ' . getEmailInfoBox('
            <p style="margin: 0; color: #0d7a43;">
                <strong>✓ Account Status:</strong> Active<br>
                <strong>✓ Access Level:</strong> Full Access
            </p>
        ', '#e3f8ef', '#0d7a43') . '
        
        ' . getEmailButton('Sign In Now', $loginUrl, '#6384d2') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            You can now browse available facilities, submit booking requests, and manage your reservations through your dashboard.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Account Verified Email Template
 */
function getAccountVerifiedEmailTemplate($userName) {
    $header = getEmailHeader('Account Verified');
    $footer = getEmailFooter();
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">✅ Your Account Has Been Verified!</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Your account has been verified by an administrator. You can now enjoy enhanced features and benefits!
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 10px; color: #1e3a5f; font-size: 16px;">🌟 New Benefits Unlocked:</h3>
            <ul style="margin: 0; padding-left: 20px; color: #4a5568;">
                <li style="margin-bottom: 8px;"><strong>Auto-Approval:</strong> Eligible reservations will be automatically approved</li>
                <li style="margin-bottom: 8px;"><strong>Priority Processing:</strong> Faster review for your booking requests</li>
                <li style="margin-bottom: 8px;"><strong>Extended Booking:</strong> Book facilities further in advance</li>
            </ul>
        ', '#f0f8ff', '#2196f3') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            Thank you for being a verified member of our community!
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Account Locked Email Template
 */
function getAccountLockedEmailTemplate($userName, $reason = '') {
    $header = getEmailHeader('Account Locked');
    $footer = getEmailFooter();
    
    $reasonHtml = '';
    if (!empty($reason)) {
        $reasonHtml = getEmailInfoBox('
            <p style="margin: 0; color: #856404;">
                <strong>Reason:</strong> ' . htmlspecialchars($reason) . '
            </p>
        ', '#fff4e5', '#ffc107');
    }
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">⚠️ Your Account Has Been Locked</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Your account has been temporarily locked by an administrator. You will be unable to sign in until it is reviewed and unlocked.
        </p>
        
        ' . $reasonHtml . '
        
        ' . getEmailInfoBox('
            <p style="margin: 0; color: #b23030;">
                <strong>⚠️ Action Required:</strong><br>
                If you believe this is a mistake or need access restored, please contact the administrator team or reply to this email.
            </p>
        ', '#fdecee', '#dc3545') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            For assistance, please contact the Barangay Culiat LGU office during business hours.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Reservation Approved Email Template
 */
function getReservationApprovedEmailTemplate($userName, $facilityName, $date, $timeSlot, $note = '') {
    $header = getEmailHeader('Reservation Approved');
    $footer = getEmailFooter();
    $dashboardUrl = base_url() . '/dashboard/my-reservations';
    
    $noteHtml = '';
    if (!empty($note)) {
        $noteHtml = '
        <p style="margin: 15px 0 0; color: #6b7280; font-size: 14px;">
            <strong>Note from Admin:</strong> ' . htmlspecialchars($note) . '
        </p>
        ';
    }
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">✅ Reservation Approved!</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Great news! Your reservation request has been approved.
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #1e3a5f; font-size: 16px;">📅 Reservation Details</h3>
            <table cellpadding="0" cellspacing="0" style="width: 100%;">
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px; width: 120px;"><strong>Facility:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($facilityName) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Date:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars(date('F j, Y', strtotime($date))) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Time:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($timeSlot) . '</td>
                </tr>
            </table>
            ' . $noteHtml . '
        ', '#e3f8ef', '#0d7a43') . '
        
        ' . getEmailButton('View My Reservations', $dashboardUrl, '#0d7a43') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            Please arrive on time and follow all facility rules and regulations. If you need to cancel or reschedule, please do so at least 3 days in advance.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Reservation Postponed Email Template
 */
function getReservationPostponedEmailTemplate($userName, $facilityName, $oldDate, $oldTimeSlot, $newDate, $newTimeSlot, $reason) {
    $header = getEmailHeader('Reservation Postponed');
    $footer = getEmailFooter();
    $dashboardUrl = base_url() . '/dashboard/my-reservations';
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">📅 Reservation Postponed</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Your approved reservation for <strong>' . htmlspecialchars($facilityName) . '</strong> has been postponed to a new date and time.
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #856404; font-size: 16px;">Original Schedule</h3>
            <p style="margin: 0; color: #856404; text-decoration: line-through;">
                ' . htmlspecialchars(date('F j, Y', strtotime($oldDate))) . ' • ' . htmlspecialchars($oldTimeSlot) . '
            </p>
        ', '#fff4e5', '#ffc107') . '
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #1976d2; font-size: 16px;">New Schedule</h3>
            <p style="margin: 0 0 10px; color: #1976d2; font-size: 16px; font-weight: 600;">
                ' . htmlspecialchars(date('F j, Y', strtotime($newDate))) . ' • ' . htmlspecialchars($newTimeSlot) . '
            </p>
            <p style="margin: 0; color: #6b7280; font-size: 14px;">
                <strong>Reason:</strong> ' . htmlspecialchars($reason) . '
            </p>
        ', '#e3f2fd', '#2196f3') . '
        
        ' . getEmailInfoBox('
            <p style="margin: 0; color: #856404;">
                <strong>⚠️ Note:</strong> The new date requires re-approval. You will be notified once it is reviewed.
            </p>
        ', '#fff4e5', '#ffc107') . '
        
        ' . getEmailButton('View My Reservations', $dashboardUrl, '#2196f3') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            We apologize for any inconvenience. Thank you for your understanding.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Account Locked (Failed Login Attempts) Email Template
 */
function getAccountLockedFailedLoginEmailTemplate($userName, $lockDurationMinutes = 30) {
    $header = getEmailHeader('Account Temporarily Locked');
    $footer = getEmailFooter();
    $resetPasswordUrl = base_url() . '/forgot-password';
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">🔒 Account Temporarily Locked</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Your account has been temporarily locked due to multiple failed login attempts.
        </p>
        
        ' . getEmailInfoBox('
            <p style="margin: 0; color: #856404;">
                <strong>🕐 Lock Duration:</strong> ' . $lockDurationMinutes . ' minutes<br>
                <strong>🔐 Reason:</strong> Security protection after 5 failed login attempts
            </p>
        ', '#fff4e5', '#ffc107') . '
        
        ' . getEmailInfoBox('
            <p style="margin: 0; color: #1976d2;">
                <strong>ℹ️ What to do:</strong><br>
                • Wait ' . $lockDurationMinutes . ' minutes for automatic unlock<br>
                • If this wasn\'t you, reset your password immediately<br>
                • Contact support if you need immediate assistance
            </p>
        ', '#e3f2fd', '#2196f3') . '
        
        ' . getEmailButton('Reset Password', $resetPasswordUrl, '#dc3545') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            If you didn\'t attempt to log in, your account may be at risk. Please reset your password and contact support immediately.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Password Reset Email Template
 */
function getPasswordResetEmailTemplate($userName, $resetUrl) {
    $header = getEmailHeader('Password Reset Request');
    $footer = getEmailFooter();
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">🔑 Password Reset Request</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            You have requested to reset your password for your Barangay Culiat Facilities Reservation account.
        </p>
        
        ' . getEmailInfoBox('
            <p style="margin: 0; color: #1976d2;">
                <strong>⏱️ This link will expire in 1 hour</strong><br>
                Click the button below to create a new password
            </p>
        ', '#e3f2fd', '#2196f3') . '
        
        ' . getEmailButton('Reset Password', $resetUrl, '#6384d2') . '
        
        ' . getEmailInfoBox('
            <p style="margin: 0 0 8px; color: #6b7280; font-size: 14px;"><strong>Or copy and paste this link into your browser:</strong></p>
            <p style="margin: 0; color: #1e3a5f; font-size: 12px; word-break: break-all;">' . htmlspecialchars($resetUrl) . '</p>
        ', '#f5f7fa', '#6b7280') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            If you did not request this password reset, please ignore this email. Your password will remain unchanged.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Reservation Denied Email Template
 */
function getReservationDeniedEmailTemplate($userName, $facilityName, $date, $timeSlot, $note = '') {
    $header = getEmailHeader('Reservation Denied');
    $footer = getEmailFooter();
    $bookAgainUrl = base_url() . '/dashboard/book-facility';
    
    $noteHtml = '';
    if (!empty($note)) {
        $noteHtml = '
        <p style="margin: 15px 0 0; color: #6b7280; font-size: 14px;">
            <strong>Reason:</strong> ' . htmlspecialchars($note) . '
        </p>
        ';
    }
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">❌ Reservation Request Denied</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            We regret to inform you that your reservation request has been denied.
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #b23030; font-size: 16px;">Reservation Details</h3>
            <table cellpadding="0" cellspacing="0" style="width: 100%;">
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px; width: 120px;"><strong>Facility:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($facilityName) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Date:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars(date('F j, Y', strtotime($date))) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Time:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($timeSlot) . '</td>
                </tr>
            </table>
            ' . $noteHtml . '
        ', '#fdecee', '#dc3545') . '
        
        ' . getEmailButton('Book Another Facility', $bookAgainUrl, '#6384d2') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            You can submit a new reservation request with different dates or facilities. If you have questions, please contact our office.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Reservation Cancelled Email Template
 * @param string $buttonText Optional. Default "Book Another Facility".
 * @param string|null $buttonUrl Optional. Default link to book-facility.
 */
function getReservationCancelledEmailTemplate($userName, $facilityName, $date, $timeSlot, $note = '', $buttonText = 'Book Another Facility', $buttonUrl = null) {
    $header = getEmailHeader('Reservation Cancelled');
    $footer = getEmailFooter();
    $actionUrl = $buttonUrl !== null ? $buttonUrl : (base_url() . '/dashboard/book-facility');
    
    $noteHtml = '';
    if (!empty($note)) {
        $noteHtml = '
        <p style="margin: 15px 0 0; color: #6b7280; font-size: 14px;">
            <strong>Reason:</strong> ' . htmlspecialchars($note) . '
        </p>
        ';
    }
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">🚫 Reservation Cancelled</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Your reservation has been cancelled.
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #856404; font-size: 16px;">Cancelled Reservation</h3>
            <table cellpadding="0" cellspacing="0" style="width: 100%;">
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px; width: 120px;"><strong>Facility:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($facilityName) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Date:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars(date('F j, Y', strtotime($date))) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Time:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($timeSlot) . '</td>
                </tr>
            </table>
            ' . $noteHtml . '
        ', '#fff4e5', '#ffc107') . '
        
        ' . getEmailButton($buttonText, $actionUrl, '#6384d2') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            You can submit a new reservation request at any time. We apologize for any inconvenience.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Reservation Rescheduled (by User) Email Template
 */
function getReservationRescheduledEmailTemplate($userName, $facilityName, $oldDate, $oldTimeSlot, $newDate, $newTimeSlot, $reason, $requiresApproval = false) {
    $header = getEmailHeader('Reservation Rescheduled');
    $footer = getEmailFooter();
    $dashboardUrl = base_url() . '/dashboard/my-reservations';
    
    $approvalNote = '';
    if ($requiresApproval) {
        $approvalNote = getEmailInfoBox('
            <p style="margin: 0; color: #856404;">
                <strong>⚠️ Note:</strong> The new date requires re-approval. You will be notified once it is reviewed.
            </p>
        ', '#fff4e5', '#ffc107');
    }
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">📅 Reservation Rescheduled</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Your reservation for <strong>' . htmlspecialchars($facilityName) . '</strong> has been successfully rescheduled.
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #856404; font-size: 16px;">Previous Schedule</h3>
            <p style="margin: 0; color: #856404; text-decoration: line-through;">
                ' . htmlspecialchars(date('F j, Y', strtotime($oldDate))) . ' • ' . htmlspecialchars($oldTimeSlot) . '
            </p>
        ', '#fff4e5', '#ffc107') . '
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #0d7a43; font-size: 16px;">New Schedule</h3>
            <p style="margin: 0 0 10px; color: #0d7a43; font-size: 16px; font-weight: 600;">
                ' . htmlspecialchars(date('F j, Y', strtotime($newDate))) . ' • ' . htmlspecialchars($newTimeSlot) . '
            </p>
            <p style="margin: 0; color: #6b7280; font-size: 14px;">
                <strong>Reason:</strong> ' . htmlspecialchars($reason) . '
            </p>
        ', '#e3f8ef', '#0d7a43') . '
        
        ' . $approvalNote . '
        
        ' . getEmailButton('View My Reservations', $dashboardUrl, '#6384d2') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            Thank you for keeping us informed of your schedule changes.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Contact Form - Admin Notification Email Template
 */
function getContactFormAdminEmailTemplate($senderName, $senderEmail, $subject, $message) {
    $header = getEmailHeader('New Contact Form Submission');
    $footer = getEmailFooter();
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">📬 New Contact Form Message</h2>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            You have received a new message through the contact form.
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #1e3a5f; font-size: 16px;">Sender Information</h3>
            <table cellpadding="0" cellspacing="0" style="width: 100%;">
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px; width: 100px;"><strong>Name:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($senderName) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Email:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($senderEmail) . '</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Subject:</strong></td>
                    <td style="padding: 6px 0; color: #1e3a5f; font-size: 14px;">' . htmlspecialchars($subject) . '</td>
                </tr>
            </table>
        ', '#f0f8ff', '#2196f3') . '
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #1e3a5f; font-size: 16px;">Message</h3>
            <p style="margin: 0; color: #4a5568; font-size: 14px; line-height: 1.6; white-space: pre-wrap;">' . htmlspecialchars($message) . '</p>
        ', '#f5f7fa', '#6b7280') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            Please respond to this inquiry at your earliest convenience by replying to <strong>' . htmlspecialchars($senderEmail) . '</strong>.
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Contact Form - User Confirmation Email Template
 */
function getContactFormUserEmailTemplate($userName) {
    $header = getEmailHeader('Message Received');
    $footer = getEmailFooter();
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">✉️ We Received Your Message</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            Thank you for contacting the Barangay Culiat Facilities Reservation Office. We have received your message and will respond as soon as possible.
        </p>
        
        ' . getEmailInfoBox('
            <p style="margin: 0; color: #1976d2;">
                <strong>📋 What happens next:</strong><br>
                • Our team will review your message<br>
                • You\'ll receive a response within 1-2 business days<br>
                • Check your email regularly for our reply
            </p>
        ', '#e3f2fd', '#2196f3') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            <strong>Office Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM<br>
            <strong>Phone:</strong> (02) 1234-5678<br>
            <strong>Email:</strong> facilities@barangayculiat.gov.ph
        </p>
    ';
    
    return $header . $content . $footer;
}

/**
 * Maintenance Alert Email Template
 */
function getMaintenanceAlertEmailTemplate($userName, $facilityName, $maintenanceDate, $reason = '') {
    $header = getEmailHeader('Facility Maintenance Alert');
    $footer = getEmailFooter();
    
    $reasonHtml = '';
    if (!empty($reason)) {
        $reasonHtml = '
        <p style="margin: 10px 0 0; color: #6b7280; font-size: 14px;">
            <strong>Reason:</strong> ' . htmlspecialchars($reason) . '
        </p>
        ';
    }
    
    $content = '
        <h2 style="margin: 0 0 20px; color: #1e3a5f; font-size: 22px; font-weight: 600;">🔧 Facility Maintenance Notice</h2>
        <p style="margin: 0 0 15px; color: #4a5568; font-size: 16px;">Hi <strong>' . htmlspecialchars($userName) . '</strong>,</p>
        <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px;">
            We would like to inform you about scheduled maintenance for <strong>' . htmlspecialchars($facilityName) . '</strong>.
        </p>
        
        ' . getEmailInfoBox('
            <h3 style="margin: 0 0 12px; color: #856404; font-size: 16px;">Maintenance Details</h3>
            <p style="margin: 0; color: #856404; font-size: 14px;">
                <strong>Facility:</strong> ' . htmlspecialchars($facilityName) . '<br>
                <strong>Date:</strong> ' . htmlspecialchars($maintenanceDate) . '
            </p>
            ' . $reasonHtml . '
        ', '#fff4e5', '#ffc107') . '
        
        <p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;">
            The facility will be unavailable during this time. We apologize for any inconvenience and appreciate your understanding.
        </p>
    ';
    
    return $header . $content . $footer;
}
