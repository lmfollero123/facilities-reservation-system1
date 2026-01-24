<?php
/**
 * User Data Export - PDF Format
 * Legally compliant with RA 10173 (Data Privacy Act of 2012)
 * Implements Right to Access and Right to Data Portability
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/data_export.php';

// Authentication check
if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$exportId = (int)($_GET['id'] ?? 0);

if (!$userId || !$exportId) {
    die('Invalid request');
}

// Get export record
$export = getExportFile($exportId);

if (!$export || $export['user_id'] != $userId) {
    die('Export not found or access denied');
}

// Load the JSON data
$filepath = app_root_path() . '/' . $export['file_path'];
if (!file_exists($filepath)) {
    die('Export file not found');
}

$jsonData = json_decode(file_get_contents($filepath), true);
if (!$jsonData) {
    die('Invalid export data');
}

$user = $jsonData['user'] ?? [];
$exportType = $jsonData['export_type'] ?? 'full';
$exportedAt = $jsonData['exported_at'] ?? date('Y-m-d H:i:s');

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Personal Data Export - <?= htmlspecialchars($user['name'] ?? 'User'); ?></title>
    <style>
        @media print {
            @page {
                margin: 1.5cm 1cm;
                size: A4;
            }
            
            .no-print {
                display: none !important;
            }
            
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
        
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.5;
            color: #333;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .print-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .print-container {
                max-width: 100%;
                box-shadow: none;
                padding: 0;
            }
        }
        
        .header {
            background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%);
            color: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 8px;
        }
        
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 22pt;
            font-weight: 600;
        }
        
        .header p {
            margin: 0;
            font-size: 10pt;
            opacity: 0.9;
        }
        
        .legal-notice {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
            font-size: 9pt;
            line-height: 1.6;
        }
        
        .legal-notice strong {
            color: #1976d2;
        }
        
        .meta-info {
            background: #f8f9fa;
            border-left: 4px solid #6384d2;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        
        .meta-info table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .meta-info td {
            padding: 6px 10px;
            font-size: 9pt;
        }
        
        .meta-info td:first-child {
            font-weight: 600;
            color: #555;
            width: 150px;
        }
        
        .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .section h2 {
            color: #1e3a5f;
            font-size: 14pt;
            margin: 0 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #6384d2;
        }
        
        .section h3 {
            color: #285ccd;
            font-size: 11pt;
            margin: 15px 0 10px 0;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 20px 0;
            font-size: 9pt;
        }
        
        .data-table th {
            background: #285ccd;
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
        }
        
        .data-table td {
            padding: 8px;
            border-bottom: 1px solid #e0e6ed;
            vertical-align: top;
        }
        
        .data-table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .info-box {
            background: #fff4e5;
            border-left: 4px solid #ffc107;
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 4px;
            font-size: 9pt;
        }
        
        .info-box strong {
            color: #856404;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 8pt;
            font-weight: 600;
        }
        
        .badge-active {
            background: #e3f8ef;
            color: #0d7a43;
        }
        
        .badge-pending {
            background: #fff4e5;
            color: #856404;
        }
        
        .badge-approved {
            background: #e3f8ef;
            color: #0d7a43;
        }
        
        .badge-denied {
            background: #fdecee;
            color: #b23030;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e0e6ed;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #285ccd;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(40, 92, 205, 0.3);
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #1e4ba8;
        }
        
        .no-data {
            text-align: center;
            padding: 20px;
            color: #999;
            font-style: italic;
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">🖨️ Print / Save as PDF</button>
    
    <div class="print-container">
        <div class="header">
            <h1>🏛️ Personal Data Export</h1>
            <p>Barangay Culiat Facilities Reservation System</p>
        </div>
        
        <div class="legal-notice">
            <strong>📋 Data Privacy Act Compliance (RA 10173)</strong><br>
            This export is provided in accordance with your rights under Republic Act No. 10173 (Data Privacy Act of 2012), specifically:
            <ul style="margin: 8px 0 0 20px; padding: 0;">
                <li><strong>Right to Access</strong> - You have the right to obtain a copy of your personal data</li>
                <li><strong>Right to Data Portability</strong> - You have the right to receive your data in a structured, commonly used format</li>
            </ul>
            This document contains personal information. Please handle it securely and do not share it with unauthorized parties.
        </div>
        
        <div class="meta-info">
            <table>
                <tr>
                    <td>Export Generated:</td>
                    <td><?= date('F j, Y g:i A', strtotime($exportedAt)); ?></td>
                </tr>
                <tr>
                    <td>Export Type:</td>
                    <td><?= ucfirst(htmlspecialchars($exportType)); ?> Data Export</td>
                </tr>
                <tr>
                    <td>Data Subject:</td>
                    <td><?= htmlspecialchars($user['name'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>Expires:</td>
                    <td><?= date('F j, Y', strtotime($export['expires_at'])); ?> (<?= DATA_EXPORT_EXPIRATION_DAYS; ?> days from generation)</td>
                </tr>
            </table>
        </div>
        
        <!-- Profile Information -->
        <div class="section">
            <h2>👤 Profile Information</h2>
            <table class="data-table">
                <tr>
                    <td style="width: 150px;"><strong>Full Name:</strong></td>
                    <td><?= htmlspecialchars($user['name'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td><strong>Email Address:</strong></td>
                    <td><?= htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td><strong>Mobile Number:</strong></td>
                    <td><?= htmlspecialchars($user['mobile'] ?? 'Not provided'); ?></td>
                </tr>
                <tr>
                    <td><strong>Address:</strong></td>
                    <td><?= htmlspecialchars($user['address'] ?? 'Not provided'); ?></td>
                </tr>
                <tr>
                    <td><strong>Role:</strong></td>
                    <td><?= htmlspecialchars($user['role'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td><strong>Account Status:</strong></td>
                    <td>
                        <span class="badge badge-<?= strtolower($user['status'] ?? 'pending'); ?>">
                            <?= ucfirst(htmlspecialchars($user['status'] ?? 'N/A')); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><strong>Account Created:</strong></td>
                    <td><?= $user['created_at'] ? date('F j, Y g:i A', strtotime($user['created_at'])) : 'N/A'; ?></td>
                </tr>
                <tr>
                    <td><strong>Last Login:</strong></td>
                    <td><?= $user['last_login_at'] ? date('F j, Y g:i A', strtotime($user['last_login_at'])) : 'Never'; ?></td>
                </tr>
            </table>
            
            <?php if (isset($user['profile'])): ?>
                <h3>📍 Location Data</h3>
                <table class="data-table">
                    <tr>
                        <td style="width: 150px;"><strong>Latitude:</strong></td>
                        <td><?= htmlspecialchars($user['profile']['latitude'] ?? 'Not set'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Longitude:</strong></td>
                        <td><?= htmlspecialchars($user['profile']['longitude'] ?? 'Not set'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Profile Picture:</strong></td>
                        <td><?= htmlspecialchars($user['profile']['profile_picture'] ?? 'No profile picture'); ?></td>
                    </tr>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Reservations -->
        <?php if (isset($user['reservations']) && !empty($user['reservations'])): ?>
            <div class="section">
                <h2>📅 Reservation History</h2>
                <p style="font-size: 9pt; color: #666; margin-bottom: 15px;">
                    Total Reservations: <strong><?= count($user['reservations']); ?></strong>
                </p>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user['reservations'] as $reservation): ?>
                            <tr>
                                <td><?= htmlspecialchars($reservation['facility_name'] ?? 'N/A'); ?></td>
                                <td><?= date('M d, Y', strtotime($reservation['reservation_date'])); ?></td>
                                <td style="font-size: 8pt;"><?= htmlspecialchars($reservation['time_slot'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($reservation['status']); ?>">
                                        <?= ucfirst(htmlspecialchars($reservation['status'])); ?>
                                    </span>
                                </td>
                                <td style="font-size: 8pt;"><?= date('M d, Y', strtotime($reservation['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($exportType === 'full' || $exportType === 'reservations'): ?>
            <div class="section">
                <h2>📅 Reservation History</h2>
                <div class="no-data">No reservations found</div>
            </div>
        <?php endif; ?>
        
        <!-- Documents -->
        <?php if (isset($user['documents']) && !empty($user['documents'])): ?>
            <div class="section">
                <h2>📄 Uploaded Documents</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user['documents'] as $doc): ?>
                            <tr>
                                <td><?= ucfirst(str_replace('_', ' ', htmlspecialchars($doc['document_type']))); ?></td>
                                <td style="font-size: 8pt;"><?= htmlspecialchars($doc['file_name']); ?></td>
                                <td><?= number_format($doc['file_size'] / 1024, 2); ?> KB</td>
                                <td><?= date('M d, Y', strtotime($doc['uploaded_at'])); ?></td>
                                <td><?= $doc['is_archived'] ? 'Archived' : 'Active'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($exportType === 'full' || $exportType === 'documents'): ?>
            <div class="section">
                <h2>📄 Uploaded Documents</h2>
                <div class="no-data">No documents found</div>
            </div>
        <?php endif; ?>
        
        <!-- Violations -->
        <?php if (isset($user['violations']) && !empty($user['violations'])): ?>
            <div class="section">
                <h2>⚠️ Violation Records</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Severity</th>
                            <th>Description</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user['violations'] as $violation): ?>
                            <tr>
                                <td><?= ucfirst(str_replace('_', ' ', htmlspecialchars($violation['violation_type']))); ?></td>
                                <td>
                                    <span class="badge badge-<?= $violation['severity'] === 'high' || $violation['severity'] === 'critical' ? 'denied' : 'pending'; ?>">
                                        <?= ucfirst(htmlspecialchars($violation['severity'])); ?>
                                    </span>
                                </td>
                                <td style="font-size: 8pt;"><?= htmlspecialchars($violation['description'] ?? '-'); ?></td>
                                <td><?= date('M d, Y', strtotime($violation['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Notifications -->
        <?php if (isset($user['notifications']) && !empty($user['notifications'])): ?>
            <div class="section">
                <h2>🔔 Recent Notifications</h2>
                <p style="font-size: 9pt; color: #666; margin-bottom: 15px;">
                    Showing last <?= count($user['notifications']); ?> notifications
                </p>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Message</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($user['notifications'], 0, 20) as $notif): ?>
                            <tr>
                                <td><?= ucfirst(htmlspecialchars($notif['type'] ?? 'general')); ?></td>
                                <td><?= htmlspecialchars($notif['title'] ?? 'N/A'); ?></td>
                                <td style="font-size: 8pt;"><?= htmlspecialchars(substr($notif['message'] ?? '', 0, 100)); ?><?= strlen($notif['message'] ?? '') > 100 ? '...' : ''; ?></td>
                                <td style="font-size: 8pt;"><?= date('M d, Y H:i', strtotime($notif['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <strong>ℹ️ Data Retention Notice:</strong><br>
            This export file will be automatically deleted after <?= DATA_EXPORT_EXPIRATION_DAYS; ?> days for security purposes. 
            If you need another copy, you can generate a new export from your profile settings.
        </div>
        
        <div class="footer">
            <p><strong>Barangay Culiat Facilities Reservation System</strong></p>
            <p>This is a system-generated document in compliance with RA 10173 (Data Privacy Act of 2012)</p>
            <p>For questions about your data, contact the LGU Data Privacy Officer</p>
            <p>© <?= date('Y'); ?> Barangay Culiat, Quezon City. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
