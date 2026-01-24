<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    // Use HTTP for localhost/lgu.test, HTTPS detection can be unreliable
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = (strpos($host, 'localhost') !== false || 
               strpos($host, '127.0.0.1') !== false || 
               strpos($host, 'lgu.test') !== false);
    $protocol = $isLocal ? 'http' : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $redirectUrl = $protocol . '://' . $host . base_path() . '/login';
    header('Location: ' . $redirectUrl);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/notifications.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';
require_once __DIR__ . '/../../../../config/auto_approval.php';
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';

$pdo = db();
$pageTitle = 'Book a Facility | LGU Facilities Reservation';
$success = '';
$error = '';
$conflictWarning = null;
$recommendations = [];

$BOOKING_LIMIT_ACTIVE = 3; // max active (pending+approved) in window
$BOOKING_LIMIT_WINDOW_DAYS = 30; // rolling window for active bookings
$BOOKING_ADVANCE_MAX_DAYS = 60; // max days ahead
$BOOKING_PER_DAY = 1; // max bookings per user per day (pending+approved)

// Check if user is verified and if they have uploaded a valid ID
$userId = $_SESSION['user_id'] ?? null;
$userVerificationStmt = $pdo->prepare('SELECT is_verified FROM users WHERE id = :user_id');
$userVerificationStmt->execute(['user_id' => $userId]);
$isVerified = (bool)($userVerificationStmt->fetchColumn() ?? false);

// Check if user has already uploaded a valid ID document
$hasValidIdDocStmt = $pdo->prepare('SELECT id FROM user_documents WHERE user_id = :user_id AND document_type = "valid_id" AND is_archived = 0 LIMIT 1');
$hasValidIdDocStmt->execute(['user_id' => $userId]);
$hasValidIdDocument = (bool)$hasValidIdDocStmt->fetch();

try {
    $facilitiesStmt = $pdo->query('SELECT id, name, base_rate, status, operating_hours FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $facilities = [];
    $error = 'Unable to load facilities right now.';
}

// Prepare base path for AJAX calls
$basePath = base_path();

// Check if pre-filled from Smart Scheduler (for showing notification)
$prefillFacilityId = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : null;
$prefillTimeSlot = isset($_GET['time_slot']) ? trim($_GET['time_slot']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facilityId = (int)($_POST['facility_id'] ?? 0);
    $date = $_POST['reservation_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $purpose = trim($_POST['purpose'] ?? '');
    $docRef = trim($_POST['doc_ref'] ?? '');
    $expectedAttendees = !empty($_POST['expected_attendees']) ? (int)$_POST['expected_attendees'] : null;
    $isCommercial = isset($_POST['is_commercial']) && $_POST['is_commercial'] === '1';
    $priorityLevel = isset($_POST['priority_level']) ? (int)$_POST['priority_level'] : 3; // Default: Private Individual
    // Validate priority level (1=LGU/Barangay, 2=Community/Org, 3=Private)
    if ($priorityLevel < 1 || $priorityLevel > 3) {
        $priorityLevel = 3;
    }
    
    // Check if user is verified - if not, require ID upload
    $userVerificationStmt = $pdo->prepare('SELECT is_verified FROM users WHERE id = :user_id');
    $userVerificationStmt->execute(['user_id' => $userId]);
    $isVerified = (bool)($userVerificationStmt->fetchColumn() ?? false);
    
    $validIdFile = $_FILES['doc_valid_id'] ?? null;
    $hasValidIdUpload = $validIdFile && isset($validIdFile['tmp_name']) && $validIdFile['error'] === UPLOAD_ERR_OK && $validIdFile['size'] > 0;
    
    // If user is not verified and hasn't uploaded a valid ID, require ID upload
    if (!$isVerified && !$hasValidIdDocument && !$hasValidIdUpload) {
        $error = 'Please upload a valid ID document. Unverified users must submit a valid ID when making a reservation.';
    }
    
    // If user already has a valid ID document uploaded, don't allow another upload
    if (!$isVerified && $hasValidIdDocument && $hasValidIdUpload) {
        $error = 'You have already submitted a valid ID document. Please wait for admin verification.';
    }
    
    // Validate time inputs and create time slot string
    if (!$startTime || !$endTime) {
        $error = 'Please select both start and end times.';
    } else {
        // Validate time format and range
        $startTimeObj = DateTime::createFromFormat('H:i', $startTime);
        $endTimeObj = DateTime::createFromFormat('H:i', $endTime);
        
        if (!$startTimeObj || !$endTimeObj) {
            $error = 'Invalid time format. Please use valid time values.';
        } elseif ($endTimeObj <= $startTimeObj) {
            $error = 'End time must be after start time.';
        } else {
            // Calculate duration in hours
            $duration = $startTimeObj->diff($endTimeObj);
            $durationHours = $duration->h + ($duration->i / 60);
            
            // Validate maximum duration (4 hours for auto-approval, but allow longer for manual approval)
            if ($durationHours > 12) {
                $error = 'Reservation duration cannot exceed 12 hours.';
            } elseif ($durationHours < 0.5) {
                $error = 'Reservation duration must be at least 30 minutes.';
            } else {
                // Format time slot as "HH:MM - HH:MM" for storage
                $timeSlot = $startTime . ' - ' . $endTime;
            }
        }
    }

    if (!$facilityId || !$date || !$purpose || !$userId) {
        $error = 'Please complete all required fields.';
    } else {
        // Validate date - ensure it's not in the past (use server timezone)
        $today = new DateTime('today', new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila'));
        $reservationDate = DateTime::createFromFormat('Y-m-d', $date, new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila'));
        if (!$reservationDate) {
            $error = 'Invalid date format.';
        } elseif ($reservationDate < $today) {
            $error = 'Cannot book facilities for past dates. Please select today or a future date.';
        } elseif ($reservationDate > (clone $today)->modify('+' . $BOOKING_ADVANCE_MAX_DAYS . ' days')) {
            $error = "Bookings are allowed only up to {$BOOKING_ADVANCE_MAX_DAYS} days in advance.";
        }
    }
    
    if (!$error) {
        // Active bookings cap in rolling window
        $windowEnd = date('Y-m-d', strtotime('+' . ($BOOKING_LIMIT_WINDOW_DAYS - 1) . ' days'));
        $activeCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reservations
             WHERE user_id = :uid
               AND reservation_date BETWEEN :start AND :end
               AND status IN ("pending","approved")'
        );
        // Note: postponed and on_hold reservations don't count as active bookings
        $activeCountStmt->execute([
            'uid' => $userId,
            'start' => date('Y-m-d'),
            'end' => $windowEnd,
        ]);
        $activeCount = (int)$activeCountStmt->fetchColumn();

        if ($activeCount >= $BOOKING_LIMIT_ACTIVE) {
            $error = "Limit reached: You can have up to {$BOOKING_LIMIT_ACTIVE} active reservations (pending/approved) within the next {$BOOKING_LIMIT_WINDOW_DAYS} days.";
        } else {
            // Per-day cap
            $perDayStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM reservations
                 WHERE user_id = :uid
                   AND reservation_date = :date
                   AND status IN ("pending","approved")'
            );
            // Note: postponed and on_hold reservations don't count toward daily limit
            $perDayStmt->execute([
                'uid' => $userId,
                'date' => $date,
            ]);
            $perDayCount = (int)$perDayStmt->fetchColumn();

            if ($perDayCount >= $BOOKING_PER_DAY) {
                $error = "Limit reached: You can only have {$BOOKING_PER_DAY} booking on this date.";
            }
        }
    }

    if (!$error) {
        // Check facility status - prevent booking if under maintenance or offline
        $facilityStatusStmt = $pdo->prepare('SELECT status, name FROM facilities WHERE id = :id');
        $facilityStatusStmt->execute(['id' => $facilityId]);
        $facilityStatus = $facilityStatusStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$facilityStatus) {
            $error = 'Invalid facility selected. Please select a valid facility.';
        } elseif ($facilityStatus['status'] === 'maintenance') {
            $error = '⚠️ This facility is currently under maintenance and cannot be booked at this time. Please select a different facility or check back later.';
        } elseif ($facilityStatus['status'] === 'offline') {
            $error = '⚠️ This facility is currently offline and unavailable for booking. Please select a different facility.';
        }
    }
    
    // AI Purpose Analysis - Check if purpose is unclear or categorize it
    $purposeAnalysis = null;
    $purposeCategory = null;
    if (!$error && !empty($purpose) && function_exists('detectUnclearPurpose')) {
        try {
            $unclearResult = detectUnclearPurpose($purpose);
            if (!isset($unclearResult['error']) && $unclearResult['is_unclear'] && $unclearResult['probability'] > 0.7) {
                // Purpose is unclear - warn user but allow booking (will be flagged for review)
                $purposeAnalysis = [
                    'warning' => 'Your purpose description seems unclear or too brief. Please provide more details to help us process your request faster.',
                    'is_unclear' => true,
                    'probability' => $unclearResult['probability']
                ];
            }
            
            // Also classify the purpose category
            if (function_exists('classifyPurposeCategory')) {
                $categoryResult = classifyPurposeCategory($purpose);
                if (!isset($categoryResult['error'])) {
                    $purposeCategory = $categoryResult['category'];
                }
            }
        } catch (Exception $e) {
            error_log("Purpose analysis error: " . $e->getMessage());
        }
    }
    
    if (!$error) {
        // AI Conflict Detection - BEST PRACTICE: Only APPROVED blocks, PENDING shows warning
        $conflictCheck = detectBookingConflict($facilityId, $date, $timeSlot);
        
        // Hard conflict (approved reservation) - BLOCK booking
        if ($conflictCheck['has_conflict']) {
            $error = '⚠️ Conflict Detected: ' . $conflictCheck['message'];
            $conflictWarning = $conflictCheck;
        } else {
            // Soft conflict (pending) or high risk - show warning but allow booking
            if (!empty($conflictCheck['soft_conflicts']) || $conflictCheck['risk_score'] > 70) {
                $conflictWarning = $conflictCheck;
            }
        }
        
        // Only proceed if no hard conflict (approved reservations)
        // Soft conflicts (pending) are allowed - admin will decide
        if (!$conflictCheck['has_conflict']) {
        try {
            // If user uploaded an ID during booking, save it
            if (!$isVerified && $hasValidIdUpload) {
                require_once __DIR__ . '/../../../../config/secure_documents.php';
                $result = saveDocumentToSecureStorage($validIdFile, $userId, 'valid_id');
                
                if ($result['success']) {
                    // Store document in database
                    $docStmt = $pdo->prepare("INSERT INTO user_documents (user_id, document_type, file_path, file_name, file_size) VALUES (?, ?, ?, ?, ?)");
                    $docStmt->execute([
                        $userId,
                        'valid_id',
                        $result['file_path'],
                        basename($result['file_path']),
                        (int)$validIdFile['size']
                    ]);
                    
                    // Note: User verification status will be updated by admin after review
                    // For now, we just save the document
                } else {
                    $error = 'Failed to save ID document. Please try again.';
                    throw new Exception('Document upload failed');
                }
            }
            
            // Evaluate auto-approval conditions
            $autoApprovalResult = evaluateAutoApproval(
                $facilityId,
                $date,
                $timeSlot,
                $expectedAttendees,
                $isCommercial,
                $userId,
                $BOOKING_ADVANCE_MAX_DAYS
            );
            
            // Determine initial status based on auto-approval evaluation
            // Note: If user is not verified, auto-approval will fail, so status will be 'pending'
            $initialStatus = $autoApprovalResult['auto_approve'] ? 'approved' : 'pending';
            $isAutoApproved = $autoApprovalResult['auto_approve'];
            
            // Set expires_at for pending reservations (48 hours from now)
            $expiresAt = null;
            if ($initialStatus === 'pending') {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));
            }
            
            // Insert reservation with determined status, priority, and expiration
            $stmt = $pdo->prepare(
                'INSERT INTO reservations (
                    user_id, facility_id, reservation_date, time_slot, purpose, 
                    status, expected_attendees, is_commercial, auto_approved, priority_level, expires_at
                ) VALUES (
                    :user, :facility, :date, :slot, :purpose, 
                    :status, :attendees, :commercial, :auto_approved, :priority, :expires
                )'
            );
            $stmt->execute([
                'user' => $userId,
                'facility' => $facilityId,
                'date' => $date,
                'slot' => $timeSlot,
                'purpose' => $purpose,
                'status' => $initialStatus,
                'attendees' => $expectedAttendees,
                'commercial' => $isCommercial ? 1 : 0,
                'auto_approved' => $isAutoApproved ? 1 : 0,
                'priority' => $priorityLevel,
                'expires' => $expiresAt,
            ]);
            $newReservationId = $pdo->lastInsertId();
            
            // Get facility name for audit log
            $facilityStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = :id');
            $facilityStmt->execute(['id' => $facilityId]);
            $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
            $facilityName = $facility ? $facility['name'] : 'Unknown Facility';
            
            // Log reservation history entry
            $historyStmt = $pdo->prepare(
                'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
                 VALUES (:res_id, :status, :note, NULL)'
            );
            $historyNote = $isAutoApproved 
                ? 'Automatically approved by system - all conditions met'
                : 'Pending manual review by staff';
            
            // Add purpose analysis note if purpose is unclear
            if ($purposeAnalysis && $purposeAnalysis['is_unclear']) {
                $historyNote .= ' | Purpose flagged as unclear by AI';
            }
            
            $historyStmt->execute([
                'res_id' => $newReservationId,
                'status' => $initialStatus,
                'note' => $historyNote
            ]);
            
            // Log audit event
            $auditDetails = 'RES-' . $newReservationId . ' – ' . $facilityName . ' (' . $date . ' ' . $timeSlot . ')';
            if ($isAutoApproved) {
                $auditDetails .= ' [Auto-approved]';
            }
            logAudit('Created reservation request', 'Reservations', $auditDetails);
            
            // Create notifications
            if ($isAutoApproved) {
                // Auto-approved: notify user only
                createNotification(
                    $userId, 
                    'booking', 
                    'Reservation Approved', 
                    'Your reservation request for ' . $facilityName . ' on ' . date('F j, Y', strtotime($date)) . ' (' . $timeSlot . ') has been automatically approved.', 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php'
                );
                $success = 'Reservation automatically approved! Your booking has been confirmed.';
            } else {
                // Pending: notify admin/staff and user
                $staffStmt = $pdo->query("SELECT id FROM users WHERE role IN ('Admin', 'Staff') AND status = 'active'");
                $staffUsers = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                
                $notifTitle = 'New Reservation Request';
                $notifMessage = 'A new reservation request has been submitted for ' . $facilityName . ' on ' . date('F j, Y', strtotime($date)) . ' (' . $timeSlot . ').';
                $notifLink = base_path() . '/resources/views/pages/dashboard/reservations_manage.php';
                
                foreach ($staffUsers as $staffId) {
                    createNotification($staffId, 'booking', $notifTitle, $notifMessage, $notifLink);
                }
                
                createNotification(
                    $userId, 
                    'booking', 
                    'Reservation Submitted', 
                    'Your reservation request for ' . $facilityName . ' has been submitted and is pending review.', 
                    base_path() . '/resources/views/pages/dashboard/my_reservations.php'
                );
                
                $success = 'Reservation submitted successfully. You will receive an update once it is reviewed.';
                
                // Include reason if provided
                if (!empty($autoApprovalResult['reason']) && $autoApprovalResult['reason'] !== 'All conditions met for auto-approval') {
                    $success .= ' (Note: ' . htmlspecialchars($autoApprovalResult['reason']) . ')';
                }
                
                // Include purpose analysis warning if purpose is unclear
                if ($purposeAnalysis && $purposeAnalysis['is_unclear']) {
                    $success .= ' ⚠️ ' . htmlspecialchars($purposeAnalysis['warning']);
                }
            }
        } catch (Throwable $e) {
            $error = 'Unable to submit reservation. Please try again later.';
            error_log('Reservation submission error: ' . $e->getMessage());
        }
        }
    }
}

// Get facility recommendations - will be loaded via AJAX when user types in purpose field
$recommendations = [];

$myReservations = [];
if (!empty($_SESSION['user_id'])) {
    $myStmt = $pdo->prepare(
        'SELECT r.reservation_date, r.time_slot, r.status, f.name AS facility
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         WHERE r.user_id = :user_id
         ORDER BY r.created_at DESC
         LIMIT 5'
    );
    $myStmt->execute(['user_id' => $_SESSION['user_id']]);
    $myReservations = $myStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get availability snapshot for next 14 days
$today = date('Y-m-d');
$endDate = date('Y-m-d', strtotime('+13 days'));

$availabilityStmt = $pdo->prepare(
    'SELECT r.reservation_date, r.status, COUNT(*) as reservation_count
     FROM reservations r
     WHERE r.reservation_date >= :start_date AND r.reservation_date <= :end_date
     GROUP BY r.reservation_date, r.status'
);
$availabilityStmt->execute([
    'start_date' => $today,
    'end_date' => $endDate,
]);
$availabilityData = $availabilityStmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date
$availabilityByDate = [];
foreach ($availabilityData as $row) {
    $date = $row['reservation_date'];
    if (!isset($availabilityByDate[$date])) {
        $availabilityByDate[$date] = ['approved' => 0, 'pending' => 0, 'blocked' => 0];
    }
    if ($row['status'] === 'approved') {
        $availabilityByDate[$date]['approved'] = (int)$row['reservation_count'];
    } elseif ($row['status'] === 'pending') {
        $availabilityByDate[$date]['pending'] = (int)$row['reservation_count'];
    }
}

$resDetailStmt = $pdo->prepare(
    'SELECT r.reservation_date, r.time_slot, r.status, r.purpose, f.name AS facility_name, f.status AS facility_status, u.name AS requester
     FROM reservations r
     JOIN facilities f ON r.facility_id = f.id
     JOIN users u ON r.user_id = u.id
     WHERE r.reservation_date >= :start_date AND r.reservation_date <= :end_date
       AND r.status IN ("pending","approved","denied","cancelled")
     ORDER BY r.reservation_date, r.time_slot'
);
$resDetailStmt->execute([
    'start_date' => $today,
    'end_date' => $endDate,
]);
$resDetailByDate = [];
foreach ($resDetailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $resDetailByDate[$row['reservation_date']][] = $row;
}

// Build maintenance status per date (check if any reservation on that date involves a facility in maintenance)
$maintenanceByDate = [];
foreach ($resDetailByDate as $date => $reservations) {
    foreach ($reservations as $reservation) {
        if ($reservation['facility_status'] === 'maintenance' || $reservation['facility_status'] === 'offline') {
            $maintenanceByDate[$date] = true;
            break;
        }
    }
}

$yearNow = (int)date('Y');
$years = [$yearNow, $yearNow + 1];
$holidayList = [];
foreach ($years as $yr) {
    $holidayList["$yr-01-01"] = 'New Year\'s Day';
    $holidayList["$yr-02-25"] = 'EDSA People Power Anniversary';
    $holidayList["$yr-04-09"] = 'Araw ng Kagitingan';
    $holidayList[date('Y-m-d', strtotime("second sunday of May $yr"))] = 'Mother\'s Day';
    $holidayList[date('Y-m-d', strtotime("second sunday of June $yr"))] = 'Father\'s Day';
    $holidayList["$yr-06-12"] = 'Independence Day';
    $holidayList["$yr-08-21"] = 'Ninoy Aquino Day';
    $holidayList["$yr-08-26"] = 'National Heroes Day';
    $holidayList["$yr-11-01"] = 'All Saints\' Day';
    $holidayList["$yr-11-02"] = 'All Souls\' Day';
    $holidayList["$yr-11-30"] = 'Bonifacio Day';
    $holidayList["$yr-12-25"] = 'Christmas Day';
    $holidayList["$yr-12-30"] = 'Rizal Day';
    // Barangay Culiat local events
    $holidayList["$yr-09-08"] = 'Barangay Culiat Fiesta';
    $holidayList["$yr-02-11"] = 'Barangay Culiat Founding Day';
}

$eventMap = [];
for ($i = 0; $i < 30; $i++) {
    $d = date('Y-m-d', strtotime("+$i days"));
    if (isset($holidayList[$d])) {
        $eventMap[$d] = $holidayList[$d];
    }
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span>Book a Facility</span>
    </div>
    <h1>Book a Facility</h1>
    <small>Submit a reservation request for LGU-managed venues.</small>
</div>

<?php if ($success): ?>
    <div class="message success" style="background:#e3f8ef;color:#0d7a43;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($success); ?>
    </div>
<?php elseif ($error): ?>
    <div class="message error" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Reservation Details</h2>
        <?php if ($prefillFacilityId && $prefillTimeSlot): ?>
            <div style="background:#e3f8ef; border:2px solid #0d7a43; border-radius:8px; padding:1rem; margin-bottom:1rem;">
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <span style="font-size:1.5rem;">✓</span>
                    <div>
                        <strong style="color:#0d7a43;">Pre-filled from Smart Scheduler</strong>
                        <p style="margin:0.25rem 0 0; color:#0d7a43; font-size:0.9rem;">
                            The form has been pre-filled with the recommended facility and time slot (<strong><?= htmlspecialchars($prefillTimeSlot); ?></strong>). 
                            Please select a reservation date (preferably the next occurrence of the recommended day) and review all details before submitting.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <form class="booking-form" method="POST" enctype="multipart/form-data">
            <label>
                Facility
                <div class="input-wrapper">
                    <i class="bi bi-building input-icon"></i>
                    <select name="facility_id" id="facility-select" required>
                        <option value="">Select a facility...</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= $facility['id']; ?>" 
                                    data-status="<?= htmlspecialchars($facility['status']); ?>"
                                    data-operating-hours="<?= htmlspecialchars($facility['operating_hours'] ?? ''); ?>">
                                <?= htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">All facilities are provided free of charge.</small>
                </div>
            </label>


            <label>
                Reservation Date
                <div class="input-wrapper">
                    <i class="bi bi-calendar input-icon"></i>
                    <input type="date" name="reservation_date" id="reservation-date" min="<?= date('Y-m-d'); ?>" required>
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Only future dates are allowed.</small>
            </label>

            <label>
                Start Time
                <div class="input-wrapper">
                    <i class="bi bi-clock input-icon"></i>
                    <select name="start_time" id="start-time" required>
                        <option value="">Select start time...</option>
                        <?php
                        // Generate 30-minute increments from 8:00 AM to 9:00 PM
                        for ($hour = 8; $hour <= 21; $hour++) {
                            for ($minute = 0; $minute < 60; $minute += 30) {
                                if ($hour == 21 && $minute > 0) break; // Stop at 9:00 PM
                                $timeValue = sprintf('%02d:%02d', $hour, $minute);
                                $timeDisplay = date('g:i A', strtotime($timeValue));
                                echo '<option value="' . $timeValue . '">' . $timeDisplay . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Facility operating hours: 8:00 AM - 9:00 PM</small>
            </label>

            <label>
                End Time
                <div class="input-wrapper">
                    <i class="bi bi-clock input-icon"></i>
                    <select name="end_time" id="end-time" required>
                        <option value="">Select end time...</option>
                        <?php
                        // Generate 30-minute increments from 8:00 AM to 9:00 PM
                        for ($hour = 8; $hour <= 21; $hour++) {
                            for ($minute = 0; $minute < 60; $minute += 30) {
                                if ($hour == 21 && $minute > 0) break; // Stop at 9:00 PM
                                $timeValue = sprintf('%02d:%02d', $hour, $minute);
                                $timeDisplay = date('g:i A', strtotime($timeValue));
                                echo '<option value="' . $timeValue . '">' . $timeDisplay . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Must be after start time. Maximum duration: 4 hours (if facility has auto-approval enabled).
                </small>
            </label>

            <div id="conflict-warning" style="display:none; border-radius:8px; padding:1rem; margin-top:1rem; transition: all 0.3s ease;">
                <div id="conflict-header" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                    <span id="conflict-icon" style="font-size:1.2rem;">⏳</span>
                    <h4 id="conflict-title" style="margin:0; font-size:0.95rem;">Checking Availability...</h4>
                </div>
                <p id="conflict-message" style="margin:0 0 0.75rem; font-size:0.85rem;"></p>
                <div id="conflict-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; font-size:0.85rem; font-weight:600;">Alternative time slots:</p>
                    <ul id="alternatives-list" style="margin:0; padding-left:1.25rem; font-size:0.85rem;"></ul>
                </div>
                <p id="conflict-risk" style="margin:0; font-size:0.82rem; display:none;"></p>
                <small id="conflict-hint" style="display:none; font-size:0.8rem; opacity:0.8;">Risk factors may include holidays/events, pending requests, and historical demand.</small>
            </div>

            <label>
                Purpose of Use
                <textarea name="purpose" id="purpose-input" rows="3" placeholder="e.g., Zumba class, Barangay General Assembly, Sports tournament" required></textarea>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Describe your event - AI will suggest the best facilities for you.</small>
            </label>

            <div style="margin: 1.5rem 0; padding: 1rem; background: rgba(255, 255, 255, 0.1); border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2);">
                <label style="display: flex !important; flex-direction: row !important; align-items: flex-start; gap: 0.75rem; cursor: pointer; margin-bottom: 0 !important;">
                    <input type="checkbox" name="is_commercial" value="1" id="is-commercial" style="width: 18px !important; height: 18px !important; min-width: 18px !important; flex-shrink: 0 !important; cursor: pointer; margin-top: 0.125rem; margin-right: 0 !important;">
                    <span style="flex: 1; line-height: 1.6; margin-top: 0;">
                        <span style="color: #1b1b1f; font-size: 0.9rem;">This reservation is for commercial purposes (e.g., business events, paid workshops, sales activities)</span>
                        <small style="color: #8b95b5; font-size: 0.85rem; display: block; margin-top: 0.5rem; line-height: 1.5;">
                            Commercial reservations require manual approval by LGU staff.
                        </small>
                    </span>
                </label>
            </div>

            <label>
                Expected Number of Attendees (Optional)
                <div class="input-wrapper">
                    <i class="bi bi-people input-icon"></i>
                    <input type="number" name="expected_attendees" id="expected-attendees" min="1" placeholder="e.g., 50">
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Helps ensure facility capacity is appropriate. Some facilities have capacity limits for auto-approval.
                </small>
            </label>

            <label>
                Event Priority Level
                <div class="input-wrapper">
                    <i class="bi bi-star input-icon"></i>
                    <select name="priority_level" id="priority-level" required>
                        <option value="3" selected>Private Individual Event</option>
                        <option value="2">Community/Organization Event</option>
                        <option value="1">LGU/Barangay Official Event</option>
                    </select>
                </div>
                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                    Priority helps admins make fair decisions when multiple requests compete for the same slot. LGU/Barangay events have highest priority.
                </small>
            </label>

            <div id="ai-recommendations" style="display:none; background:#e3f8ef; border:1px solid #0d7a43; border-radius:8px; padding:1rem; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#0d7a43; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>🤖</span> AI Recommendations
                </h4>
                <p id="recommendations-intro" style="margin:0 0 0.5rem; color:#0d7a43; font-size:0.85rem;">Based on your purpose, here are recommended facilities:</p>
                <p id="recommendations-best-times" style="margin:0 0 0.75rem; color:#0d7a43; font-size:0.82rem; font-weight:600; display:none;"></p>
                <div id="recommendations-loading" style="display:none; color:#0d7a43; font-size:0.85rem; font-style:italic;">Loading recommendations...</div>
                <div id="recommendations-list" style="color:#0d7a43; font-size:0.85rem;"></div>
            </div>

            <label>
                Supporting Document Reference
                <div class="input-wrapper">
                    <i class="bi bi-file-text input-icon"></i>
                    <input type="text" name="doc_ref" placeholder="Document No. / Request Letter Ref.">
                </div>
            </label>

            <?php if (!$isVerified && !$hasValidIdDocument): ?>
            <div style="padding:1rem; background:#fff4e5; border:2px solid #ffc107; border-radius:8px; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#856404; font-size:1rem;">⚠️ Valid ID Required</h4>
                <p style="margin:0 0 1rem; color:#856404; font-size:0.9rem; line-height:1.5;">
                    Your account is not yet verified. Please upload a valid government-issued ID to proceed with this reservation. Once verified, you'll be able to use auto-approval features.
                </p>
                <label>
                    Upload Valid ID (Required for unverified accounts)
                    <input type="file" name="doc_valid_id" accept=".pdf,image/*" required style="margin-top:0.5rem; padding:0.75rem; border:1px solid #ddd; border-radius:6px; width:100%;">
                </label>
                <small style="color:#856404; font-size:0.85rem; display:block; margin-top:0.5rem;">
                    Accepted: PDF, JPG, PNG. Max 5MB. Any government-issued ID (Birth Certificate, Barangay ID, Resident ID, Driver's License, etc.) is acceptable.
                </small>
            </div>
            <?php elseif (!$isVerified && $hasValidIdDocument): ?>
            <div style="padding:1rem; background:#e7f3ff; border:2px solid #2196F3; border-radius:8px; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#1976D2; font-size:1rem;">📋 Valid ID Submitted</h4>
                <p style="margin:0; color:#1976D2; font-size:0.9rem; line-height:1.5;">
                    Your valid ID document has been submitted and is awaiting admin verification. Once verified, you'll be able to use auto-approval features.
                </p>
            </div>
            <?php endif; ?>

            <button class="btn-primary" type="submit">Submit Booking Request</button>
        </form>

        <?php if ($conflictWarning && $conflictWarning['has_conflict']): ?>
            <div style="background:#fdecee; border:1px solid #b23030; border-radius:8px; padding:1rem; margin-top:1.5rem;">
                <h4 style="margin:0 0 0.5rem; color:#b23030; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚠️</span> Conflict Detected
                </h4>
                <p style="margin:0 0 0.75rem; color:#b23030; font-size:0.85rem;">
                    <?= htmlspecialchars($conflictWarning['message']); ?>
                </p>
                <?php if (!empty($conflictWarning['alternatives'])): ?>
                    <p style="margin:0 0 0.5rem; color:#b23030; font-size:0.85rem; font-weight:600;">Alternative time slots for this date:</p>
                    <ul style="margin:0; padding-left:1.25rem; color:#b23030; font-size:0.85rem;">
                        <?php foreach ($conflictWarning['alternatives'] as $alt): ?>
                            <?php if ($alt['available']): ?>
                                <li style="margin-bottom:0.25rem;">
                                    <strong><?= htmlspecialchars($alt['time_slot']); ?></strong> - <?= htmlspecialchars($alt['recommendation']); ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php elseif ($conflictWarning && !empty($conflictWarning['soft_conflicts'])): ?>
            <div style="background:#fff4e5; border:1px solid #ffc107; border-radius:8px; padding:1rem; margin-top:1.5rem;">
                <h4 style="margin:0 0 0.5rem; color:#856404; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚠️</span> Soft Conflict - Pending Reservations
                </h4>
                <p style="margin:0 0 0.75rem; color:#856404; font-size:0.85rem;">
                    <?= htmlspecialchars($conflictWarning['message']); ?>
                </p>
                <p style="margin:0; color:#856404; font-size:0.85rem; font-weight:600;">
                    Note: You can still submit your booking. Admin will review all pending requests and approve the one with highest priority (LGU events > Community events > Private events).
                </p>
            </div>
        <?php elseif ($conflictWarning && $conflictWarning['risk_score'] > 70): ?>
            <div style="background:#fff4e5; border:1px solid #ffc107; border-radius:8px; padding:1rem; margin-top:1.5rem;">
                <h4 style="margin:0 0 0.5rem; color:#856404; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚠️</span> High Demand Warning
                </h4>
                <p style="margin:0; color:#856404; font-size:0.85rem;">
                    This time slot has high historical demand (Risk Score: <?= $conflictWarning['risk_score']; ?>%). 
                    Consider booking well in advance or selecting an alternative time.
                </p>
            </div>
        <?php endif; ?>

        <div style="background:#fff4e5; border:1px solid #ffc107; border-radius:8px; padding:1rem; margin-top:1.5rem;">
            <h3 style="margin:0 0 0.5rem; color:#856404; font-size:1rem; display:flex; align-items:center; gap:0.5rem;">
                <span>⚠️</span> Important Notice
            </h3>
            <p style="margin:0; color:#856404; font-size:0.9rem; line-height:1.6;">
                <strong>Emergency Override Policy:</strong> In case of emergencies (e.g., evacuation centers, disaster response, urgent LGU/Barangay needs), 
                the LGU reserves the right to override or cancel existing reservations. Affected residents will be notified immediately. 
                All facilities are provided free of charge for public use.
            </p>
        </div>

        <div class="schedule-board" style="display:none;" id="availabilitySnapshotContainer">
            <header>
                <h3>Availability Snapshot</h3>
                <a class="btn-outline" href="<?= base_path(); ?>/resources/views/pages/dashboard/calendar.php">View Full Calendar</a>
            </header>
            <div class="schedule-grid" id="availabilitySnapshot" title="Click on dates for details">
                <?php for ($i = 0; $i < 14; $i++): ?>
                    <?php
                    $currentDate = date('Y-m-d', strtotime("+$i days"));
                    $dayNumber = date('d', strtotime($currentDate));
                    $dayName = date('D', strtotime($currentDate));
                    $dateData = $availabilityByDate[$currentDate] ?? ['approved' => 0, 'pending' => 0, 'blocked' => 0];
                    $eventLabel = $eventMap[$currentDate] ?? null;
                    
                    // Determine status
                    $status = 'available';
                    $title = 'Available';
                    $hasMaintenanceOnDate = isset($maintenanceByDate[$currentDate]) && $maintenanceByDate[$currentDate];
                    
                    if ($hasMaintenanceOnDate && ($dateData['approved'] > 0 || $dateData['pending'] > 0)) {
                        $status = 'unavailable';
                        $title = 'Maintenance + ' . ($dateData['approved'] + $dateData['pending']) . ' booking(s)';
                    } elseif ($hasMaintenanceOnDate) {
                        $status = 'unavailable';
                        $title = 'Maintenance';
                    } elseif ($dateData['approved'] > 0 && $dateData['pending'] > 0) {
                        $status = 'requested';
                        $title = $dateData['approved'] . ' approved, ' . $dateData['pending'] . ' pending';
                    } elseif ($dateData['approved'] > 0) {
                        $status = 'requested';
                        $title = $dateData['approved'] . ' booking(s)';
                    } elseif ($dateData['pending'] > 0) {
                        $status = 'requested';
                        $title = $dateData['pending'] . ' pending request(s)';
                    }
                    
                    // Highlight today
                    $isToday = $currentDate === date('Y-m-d');
                    ?>
                    <div class="schedule-cell <?= $status; ?><?= $isToday ? ' today' : ''; ?>" 
                         data-date="<?= $currentDate; ?>"
                         data-event="<?= htmlspecialchars($eventLabel ?? '', ENT_QUOTES); ?>"
                         title="<?= htmlspecialchars($title . ' - ' . date('M d, Y', strtotime($currentDate))); ?>">
                        <span class="cell-dow"><?= $dayName; ?></span>
                        <span class="cell-month"><?= date('M', strtotime($currentDate)); ?></span>
                        <span class="cell-day"><?= $dayNumber; ?></span>
                        <?php if ($dateData['approved'] > 0 || $dateData['pending'] > 0): ?>
                            <span class="cell-count">
                                <?= $dateData['approved'] + $dateData['pending']; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($eventLabel): ?>
                                    <?php
                                        $e = strtolower($eventLabel);
                                        $color = '#16a34a';
                                        if (strpos($e, 'christmas') !== false) $color = '#b91c1c';
                                        elseif (strpos($e, 'new year') !== false) $color = '#9333ea';
                                        elseif (strpos($e, 'fiesta') !== false) $color = '#2563eb';
                                        elseif (strpos($e, 'independence') !== false) $color = '#0f766e';
                                        elseif (strpos($e, 'heroes') !== false) $color = '#2563eb';
                                        elseif (strpos($e, 'bonifacio') !== false) $color = '#334155';
                                        elseif (strpos($e, 'all saints') !== false || strpos($e, 'all souls') !== false) $color = '#92400e';
                                    ?>
                                    <span class="event-pill" data-color="<?= $color; ?>">
                                <?= htmlspecialchars($eventLabel); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="legend">
                <span><span class="dot" style="background:#f6f8fc"></span> Available</span>
                <span><span class="dot" style="background:#fde9ec"></span> Blocked / Maintenance</span>
                <span><span class="dot" style="background:#fff4e5"></span> Has Bookings</span>
                <span><span class="dot" style="background:#dbeafe; border:1px solid #2563eb;"></span> Holiday / Barangay Event</span>
            </div>
            <small style="display:block; margin-top:0.75rem; color:#8b95b5; font-size:0.85rem;">
                Showing next 14 days. Click on dates for details.
            </small>
        </div>

        <!-- Button to show availability -->
        <div style="margin-top: 1.5rem; text-align: center;">
            <button type="button" class="btn-outline" id="showAvailableDatesBtn" style="padding: 0.75rem 1.5rem; font-size: 0.95rem;">
                📅 Show Available Dates
            </button>
            <a class="btn-outline" href="<?= base_path(); ?>/resources/views/pages/dashboard/calendar.php" style="padding: 0.75rem 1.5rem; font-size: 0.95rem; margin-left: 0.75rem;">
                View Full Calendar
            </a>
        </div>

        <!-- Availability Calendar Modal -->
        <?php
        $rangeStartLabel = date('M d, Y');
        $rangeEndLabel = date('M d, Y', strtotime('+13 days'));
        ?>
        <div id="availabilityCalendarModal" class="modal-overlay" style="display:none;">
            <div class="modal-container" style="max-width:900px;">
                <div class="modal-header">
                    <div>
                        <h3 style="margin:0;">Availability Calendar</h3>
                        <small style="color:#64748b;">Next 14 days · <?= $rangeStartLabel; ?> — <?= $rangeEndLabel; ?></small>
                    </div>
                    <button type="button" class="btn-outline" id="closeAvailabilityCalendar" aria-label="Close calendar">Close</button>
                </div>
                <div class="modal-body">
                    <div class="schedule-grid full-grid">
                        <?php for ($i = 0; $i < 14; $i++): ?>
                            <?php
                            $currentDate = date('Y-m-d', strtotime("+$i days"));
                            $dayNumber = date('d', strtotime($currentDate));
                            $dayName = date('D', strtotime($currentDate));
                            $dateData = $availabilityByDate[$currentDate] ?? ['approved' => 0, 'pending' => 0, 'blocked' => 0];
                            $eventLabel = $eventMap[$currentDate] ?? null;

                            $status = 'available';
                            $title = 'Available';
                            $hasMaintenanceOnDate = isset($maintenanceByDate[$currentDate]) && $maintenanceByDate[$currentDate];

                            if ($hasMaintenanceOnDate && ($dateData['approved'] > 0 || $dateData['pending'] > 0)) {
                                $status = 'unavailable';
                                $title = 'Maintenance + ' . ($dateData['approved'] + $dateData['pending']) . ' booking(s)';
                            } elseif ($hasMaintenanceOnDate) {
                                $status = 'unavailable';
                                $title = 'Maintenance';
                            } elseif ($dateData['approved'] > 0 && $dateData['pending'] > 0) {
                                $status = 'requested';
                                $title = $dateData['approved'] . ' approved, ' . $dateData['pending'] . ' pending';
                            } elseif ($dateData['approved'] > 0) {
                                $status = 'requested';
                                $title = $dateData['approved'] . ' booking(s)';
                            } elseif ($dateData['pending'] > 0) {
                                $status = 'requested';
                                $title = $dateData['pending'] . ' pending request(s)';
                            }

                            $isToday = $currentDate === date('Y-m-d');
                            ?>
                            <div class="schedule-cell <?= $status; ?><?= $isToday ? ' today' : ''; ?>" title="<?= htmlspecialchars($title . ' - ' . date('M d, Y', strtotime($currentDate))); ?>">
                                <span class="cell-dow"><?= $dayName; ?></span>
                                <span class="cell-month"><?= date('M', strtotime($currentDate)); ?></span>
                                <span class="cell-day"><?= $dayNumber; ?></span>
                                <?php if ($dateData['approved'] > 0 || $dateData['pending'] > 0): ?>
                                    <span class="cell-count">
                                        <?= $dateData['approved'] + $dateData['pending']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($eventLabel): ?>
                                    <span class="event-pill"><?= htmlspecialchars($eventLabel); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 24, 55, 0.55);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            z-index: 3000;
        }
        .modal-container {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 60px rgba(0,0,0,0.25);
            width: min(1100px, 100%);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e8ecf4;
        }
        .modal-body {
            padding: 1.25rem;
            overflow: auto;
        }
        .schedule-grid.full-grid {
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }
        </style>

        <!-- Day Details Modal -->
        <div id="dayDetailModal" class="modal-overlay" style="display:none;">
            <div class="modal-container" style="max-width: 640px;">
                <div class="modal-header">
                    <div>
                        <h3 id="dayDetailTitle" style="margin:0;">Date</h3>
                        <small id="dayDetailSub" style="color:#64748b;"></small>
                    </div>
                    <button type="button" class="btn-outline" id="closeDayDetail" aria-label="Close details">Close</button>
                </div>
                <div class="modal-body" id="dayDetailBody"></div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const showBtn = document.getElementById('showAvailableDatesBtn');
            const snapshotContainer = document.getElementById('availabilitySnapshotContainer');
            const snapshotGrid = document.getElementById('availabilitySnapshot');
            const closeBtn = document.getElementById('closeAvailabilityCalendar');
            const modal = document.getElementById('availabilityCalendarModal');
            const dayModal = document.getElementById('dayDetailModal');
            const closeDayDetail = document.getElementById('closeDayDetail');
            const dayDetailTitle = document.getElementById('dayDetailTitle');
            const dayDetailSub = document.getElementById('dayDetailSub');
            const dayDetailBody = document.getElementById('dayDetailBody');
            const resDetail = <?= json_encode($resDetailByDate); ?>;
            const eventMapJS = <?= json_encode($eventMap); ?>;

            function openAvailabilityModal() {
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            }
            function closeAvailabilityModal() {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
            function openDayModal(dateStr) {
                const friendly = new Date(dateStr + 'T00:00:00');
                dayModal.style.display = 'flex';
                document.body.classList.add('modal-open');
                dayDetailTitle.textContent = friendly.toDateString();
                const eventLabel = eventMapJS[dateStr] || '';
                dayDetailSub.textContent = eventLabel ? `Event/Holiday: ${eventLabel}` : '';
                const items = resDetail[dateStr] || [];
                if (!items.length) {
                    dayDetailBody.innerHTML = '<p style="color:#8b95b5;">No pending or approved reservations for this date.</p>';
                } else {
                    dayDetailBody.innerHTML = '<ul style="list-style:none; padding-left:0; margin:0; display:flex; flex-direction:column; gap:0.75rem;">' +
                        items.map(it => {
                            const statusClass = `status-${it.status}`;
                            return `<li style="padding:0.75rem; border:1px solid #e8ecf4; border-radius:10px; background:#f8fbff;">
                                <div style="display:flex; justify-content:space-between; gap:0.5rem; align-items:flex-start;">
                                    <div>
                                        <strong>${it.facility_name || ''}</strong><br>
                                        <span style="color:#5b6888;">${it.time_slot}</span>
                                    </div>
                                    <span class="status-badge ${statusClass}" style="text-transform:capitalize;">${it.status}</span>
                                </div>
                                <div style="margin-top:0.35rem; color:#475569; font-size:0.9rem;">${it.purpose || ''}</div>
                                <div style="margin-top:0.25rem; color:#8b95b5; font-size:0.85rem;">Requester: ${it.requester || ''}</div>
                            </li>`;
                        }).join('') + '</ul>';
                }
            }
            function closeDayModal() {
                dayModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }

            // Show/hide snapshot container when clicking the button
            if (showBtn && snapshotContainer) {
                showBtn.addEventListener('click', () => {
                    if (snapshotContainer.style.display === 'none' || !snapshotContainer.style.display) {
                        snapshotContainer.style.display = 'block';
                        showBtn.textContent = '📅 Hide Available Dates';
                    } else {
                        snapshotContainer.style.display = 'none';
                        showBtn.textContent = '📅 Show Available Dates';
                    }
                });
            }
            if (closeBtn) closeBtn.addEventListener('click', closeAvailabilityModal);
            modal?.addEventListener('click', (e) => {
                if (e.target === modal) closeAvailabilityModal();
            });
            closeDayDetail?.addEventListener('click', closeDayModal);
            dayModal?.addEventListener('click', (e) => {
                if (e.target === dayModal) closeDayModal();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    if (modal && modal.style.display === 'flex') closeAvailabilityModal();
                    if (dayModal && dayModal.style.display === 'flex') closeDayModal();
                }
            });

            function bindCells(scope) {
                scope.querySelectorAll('.schedule-cell').forEach(cell => {
                    const dateStr = cell.getAttribute('data-date');
                    cell.addEventListener('click', (e) => {
                        if (!dateStr) return;
                        e.stopPropagation(); // Prevent grid click from firing
                        openDayModal(dateStr);
                    });
                    // event color
                    const pill = cell.querySelector('.event-pill');
                    if (pill && pill.dataset.color) {
                        pill.style.background = pill.dataset.color;
                        pill.style.color = '#fff';
                        pill.style.border = '1px solid rgba(0,0,0,0.05)';
                    }
                });
            }
            bindCells(document);
            bindCells(modal);
        });
        </script>
    </section>

    <aside class="booking-card">
        <div id="facility-details-container" style="display: none;">
            <h2 id="facility-details-title">Facility Details</h2>
            <div id="facility-details-content">
                <div style="text-align: center; padding: 2rem; color: #8b95b5;">
                    <p>Select a facility from the dropdown to view details</p>
                </div>
            </div>
        </div>
        
        <div id="facility-placeholder" style="display: block;">
            <h2>Facility Details</h2>
            <div style="text-align: center; padding: 2rem; color: #8b95b5;">
                <p>Select a facility from the dropdown to view details</p>
            </div>
        </div>

        <div class="booking-card" style="margin-top:1.5rem;">
            <h3>My Recent Reservations</h3>
            <?php if (empty($myReservations)): ?>
                <p>No reservations yet. Submitted bookings will appear here.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Facility</th>
                        <th>Schedule</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($myReservations as $reservation): ?>
                        <tr>
                            <td><?= htmlspecialchars($reservation['facility']); ?></td>
                            <td><?= htmlspecialchars($reservation['reservation_date']); ?> • <?= htmlspecialchars($reservation['time_slot']); ?></td>
                            <td><?= ucfirst($reservation['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <a class="btn-outline" style="margin-top:0.75rem; text-align:center;" href="<?= base_path(); ?>/dashboard/my-reservations">View full history</a>
            <?php endif; ?>
        </div>
    </aside>
</div>

<!-- Maintenance Warning Modal -->
<div id="maintenanceWarningModal" class="modal-confirm" style="display: none; opacity: 0; visibility: hidden; z-index: 2000;">
    <div class="modal-dialog" style="max-width: 500px; z-index: 2001;">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
            <div style="font-size: 3rem;">🔧</div>
            <div>
                <h3 style="margin: 0 0 0.5rem; color: var(--gov-blue-dark);">Facility Under Maintenance</h3>
                <p style="margin: 0; color: #4c5b7c; font-size: 0.95rem;" id="maintenanceModalMessage"></p>
            </div>
        </div>
        <p style="color: #856404; background: #fff4e5; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #ffc107;">
            <strong>⚠️ Important:</strong> This facility is currently unavailable for booking. Please select a different facility or check back later when maintenance is complete.
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-primary" onclick="closeMaintenanceWarning()">OK, I Understand</button>
        </div>
    </div>
</div>

<script>
function closeMaintenanceWarning() {
    const modal = document.getElementById('maintenanceWarningModal');
    if (modal) {
        modal.style.display = 'none';
        modal.style.opacity = '0';
        modal.style.visibility = 'hidden';
        modal.classList.remove('open');
    }
    // Always restore body scroll
    document.body.style.overflow = '';
    document.body.style.removeProperty('overflow');
    
    // Clear facility dropdown after user acknowledges warning
    const facilitySelect = document.getElementById('facility-select');
    if (facilitySelect) {
        facilitySelect.value = '';
        // Trigger change event to clear any related UI
        facilitySelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Tell main.js to skip its legacy conflict handler on this page
    window.DISABLE_CONFLICT_CHECK = true;

    // Get form elements
    const facilitySel = document.getElementById('facility-select');
    const dateInput = document.getElementById('reservation-date');
    const startTimeInput = document.getElementById('start-time');
    const endTimeInput = document.getElementById('end-time');
    const messageBox = document.getElementById('conflict-warning');
    const messageText = document.getElementById('conflict-message');
    const altWrap = document.getElementById('conflict-alternatives');
    const altList = document.getElementById('alternatives-list');
    const riskLine = document.getElementById('conflict-risk');

    const eventMap = <?= json_encode($eventMap); ?>;
    const basePath = <?= json_encode($basePath); ?>;
    
    // Prefill from query params (facility_id, reservation_date, time_slot) - MUST BE DECLARED EARLY
    const qp = new URLSearchParams(window.location.search);
    const preFacility = qp.get('facility_id');
    const preDate = qp.get('reservation_date');
    const preSlot = qp.get('time_slot'); // Legacy support for pre-filled slots
    
    // Facility details container elements
    const facilityDetailsContainer = document.getElementById('facility-details-container');
    const facilityPlaceholder = document.getElementById('facility-placeholder');
    const facilityDetailsTitle = document.getElementById('facility-details-title');
    const facilityDetailsContent = document.getElementById('facility-details-content');
    
    // Function to fetch and display facility details
    async function loadFacilityDetails(facilityId) {
        if (!facilityId || facilityId === '') {
            // Hide details, show placeholder
            if (facilityDetailsContainer) facilityDetailsContainer.style.display = 'none';
            if (facilityPlaceholder) facilityPlaceholder.style.display = 'block';
            return;
        }
        
        try {
            // Show loading state
            if (facilityDetailsContainer) {
                facilityDetailsContainer.style.display = 'block';
                facilityDetailsContent.innerHTML = '<div style="text-align: center; padding: 2rem; color: #8b95b5;"><p>Loading facility details...</p></div>';
            }
            if (facilityPlaceholder) facilityPlaceholder.style.display = 'none';
            
            // Fetch facility details via AJAX
            const response = await fetch(basePath + '/resources/views/pages/dashboard/facility-details-api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'facility_id=' + encodeURIComponent(facilityId)
            });
            
            if (!response.ok) {
                throw new Error('Failed to load facility details');
            }
            
            const facility = await response.json();
            
            if (facility.error) {
                throw new Error(facility.error);
            }
            
            // Build facility details HTML
            let html = '';
            
            // Facility name and status
            html += '<div style="margin-bottom: 1.5rem;">';
            html += '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">';
            html += '<h3 style="margin: 0; font-size: 1.25rem; color: var(--gov-blue-dark);">' + escapeHtml(facility.name) + '</h3>';
            const statusClass = facility.status === 'available' ? 'status-available' : (facility.status === 'maintenance' ? 'status-maintenance' : 'status-offline');
            let badgeStyle = 'text-transform: capitalize; padding: 0.35rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; display: inline-block;';
            if (facility.status === 'available') {
                badgeStyle += ' background: #28a745; color: #fff;';
            } else if (facility.status === 'maintenance') {
                badgeStyle += ' background: #ff9800; color: #fff;';
            } else {
                badgeStyle += ' background: #e53935; color: #fff;';
            }
            html += '<span class="status-badge ' + statusClass + '" style="' + badgeStyle + '">' + escapeHtml(facility.status) + '</span>';
            html += '</div>';
            html += '</div>';
            
            // Location
            if (facility.location) {
                html += '<div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e8ecf4;">';
                html += '<div style="display: flex; align-items: flex-start; gap: 0.5rem;">';
                html += '<span style="font-size: 1.2rem; line-height: 1.5;">📍</span>';
                html += '<div style="flex: 1;">';
                html += '<strong style="color: #5b6888; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Location</strong>';
                html += '<p style="margin: 0; color: #1b1b1f; line-height: 1.6;">' + escapeHtml(facility.location) + '</p>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            }
            
            // Capacity
            if (facility.capacity) {
                html += '<div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e8ecf4;">';
                html += '<div style="display: flex; align-items: flex-start; gap: 0.5rem;">';
                html += '<span style="font-size: 1.2rem; line-height: 1.5;">👥</span>';
                html += '<div style="flex: 1;">';
                html += '<strong style="color: #5b6888; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Capacity</strong>';
                html += '<p style="margin: 0; color: #1b1b1f; line-height: 1.6;">' + escapeHtml(facility.capacity) + '</p>';
                if (facility.capacity_threshold) {
                    html += '<small style="color: #8b95b5; font-size: 0.85rem; display: block; margin-top: 0.25rem;">Auto-approval threshold: ' + escapeHtml(facility.capacity_threshold) + '</small>';
                }
                html += '</div>';
                html += '</div>';
                html += '</div>';
            }
            
            // Description
            if (facility.description) {
                html += '<div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e8ecf4;">';
                html += '<strong style="color: #5b6888; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">Description</strong>';
                html += '<p style="margin: 0; color: #1b1b1f; line-height: 1.6; white-space: pre-wrap;">' + escapeHtml(facility.description) + '</p>';
                html += '</div>';
            }
            
            // Amenities
            if (facility.amenities) {
                html += '<div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e8ecf4;">';
                html += '<strong style="color: #5b6888; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">Amenities</strong>';
                html += '<p style="margin: 0; color: #1b1b1f; line-height: 1.6; white-space: pre-wrap;">' + escapeHtml(facility.amenities) + '</p>';
                html += '</div>';
            }
            
            // Rules & Regulations
            if (facility.rules) {
                html += '<div style="margin-bottom: 1rem;">';
                html += '<strong style="color: #5b6888; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">Rules & Regulations</strong>';
                const rules = facility.rules.split(/\r\n|\r|\n/).filter(r => r.trim() !== '');
                if (rules.length > 0) {
                    html += '<ol style="margin: 0; padding-left: 1.25rem; color: #1b1b1f; line-height: 1.8;">';
                    rules.forEach(rule => {
                        html += '<li style="margin-bottom: 0.5rem;">' + escapeHtml(rule.trim()) + '</li>';
                    });
                    html += '</ol>';
                }
                html += '</div>';
            }
            
            // Base rate (though facilities are free)
            if (facility.base_rate) {
                html += '<div style="margin-top: 1rem; padding: 1rem; background: #f9fafc; border-radius: 8px; border-left: 4px solid var(--gov-blue);">';
                html += '<strong style="color: #5b6888; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Usage</strong>';
                html += '<p style="margin: 0; color: #1b1b1f; font-weight: 600;">Free of Charge</p>';
                html += '<small style="color: #8b95b5; font-size: 0.85rem; display: block; margin-top: 0.25rem;">This facility is provided free of charge for public use by the LGU/Barangay.</small>';
                html += '</div>';
            }
            
            // Update content
            facilityDetailsContent.innerHTML = html;
            
        } catch (error) {
            console.error('Error loading facility details:', error);
            facilityDetailsContent.innerHTML = '<div style="text-align: center; padding: 2rem; color: #b23030;"><p>Unable to load facility details. Please try again.</p></div>';
        }
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Load facility details when facility is selected
    facilitySel?.addEventListener('change', function() {
        const facilityId = this.value;
        loadFacilityDetails(facilityId);
    });
    
    // Load facility details if pre-filled
    if (preFacility && facilitySel) {
        setTimeout(() => {
            loadFacilityDetails(preFacility);
        }, 300);
    }

    // Check facility status when selected
    facilitySel.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            // Ensure body scroll is restored when no facility is selected
            document.body.style.overflow = '';
            return; // Skip if no selection or placeholder selected
        }
        
        const facilityStatus = selectedOption.getAttribute('data-status');
        const facilityName = selectedOption.text;
        const modal = document.getElementById('maintenanceWarningModal');
        const modalMessage = document.getElementById('maintenanceModalMessage');
        
        // Only proceed if modal elements exist
        if (!modal || !modalMessage) {
            console.error('Maintenance warning modal elements not found');
            return;
        }
        
        if (facilityStatus === 'maintenance' || facilityStatus === 'offline') {
            // Set message based on status
            if (facilityStatus === 'maintenance') {
                modalMessage.textContent = 
                    `"${facilityName}" is currently under maintenance and cannot be booked at this time.`;
            } else {
                modalMessage.textContent = 
                    `"${facilityName}" is currently offline and unavailable for booking.`;
            }
            
            // Show modal with proper styling
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.visibility = 'visible';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        } else {
            // Ensure body scroll is restored for available facilities
            document.body.style.overflow = '';
        }
    });

    function clearMessage() {
        if (!messageBox) return;
        // Fade out before hiding
        messageBox.style.opacity = '0';
        setTimeout(() => {
            if (messageBox) {
                messageBox.style.display = 'none';
            }
            if (messageText) messageText.textContent = '';
            if (altWrap) altWrap.style.display = 'none';
            if (altList) altList.innerHTML = '';
            if (riskLine) {
                riskLine.style.display = 'none';
                riskLine.textContent = '';
            }
        }, 300);
    }

    function showMessage(text, alternatives, riskScore, eventLabel, conflictType = 'hard') {
        // Show message box with fade in
        messageBox.style.display = 'block';
        messageBox.style.opacity = '0';
        
        // Force reflow
        messageBox.offsetHeight;
        
        // Fade in
        setTimeout(() => {
            messageBox.style.opacity = '1';
        }, 10);
        
        messageText.textContent = text;
        
        const conflictIcon = document.getElementById('conflict-icon');
        const conflictTitle = document.getElementById('conflict-title');
        
        // Change styling based on conflict type
        if (conflictType === 'success' || conflictType === 'available') {
            // Success/No conflict - green
            messageBox.style.background = '#e3f8ef';
            messageBox.style.border = '2px solid #0d7a43';
            messageText.style.color = '#0d7a43';
            if (conflictIcon) conflictIcon.textContent = '✓';
            if (conflictTitle) conflictTitle.textContent = 'Slot Available';
            if (conflictTitle) conflictTitle.style.color = '#0d7a43';
        } else if (conflictType === 'soft') {
            // Soft conflict (pending) - yellow/warning but allow submission
            messageBox.style.background = '#fff4e5';
            messageBox.style.border = '2px solid #ffc107';
            messageText.style.color = '#856404';
            if (conflictIcon) conflictIcon.textContent = '⚠️';
            if (conflictTitle) conflictTitle.textContent = 'Warning - Pending Reservations';
            if (conflictTitle) conflictTitle.style.color = '#856404';
        } else if (conflictType === 'risk') {
            // High risk - orange/warning
            messageBox.style.background = '#fff4e5';
            messageBox.style.border = '2px solid #ffc107';
            messageText.style.color = '#856404';
            if (conflictIcon) conflictIcon.textContent = '⚠️';
            if (conflictTitle) conflictTitle.textContent = 'High Demand Period';
            if (conflictTitle) conflictTitle.style.color = '#856404';
        } else {
            // Hard conflict (approved) - red/error, blocks submission
            messageBox.style.background = '#fdecee';
            messageBox.style.border = '2px solid #b23030';
            messageText.style.color = '#b23030';
            if (conflictIcon) conflictIcon.textContent = '✗';
            if (conflictTitle) conflictTitle.textContent = 'Conflict Detected';
            if (conflictTitle) conflictTitle.style.color = '#b23030';
        }
        
        if (alternatives && alternatives.length) {
            altWrap.style.display = 'block';
            // Use display field if available, otherwise use time_slot
            altList.innerHTML = alternatives
                .filter(a => a.available !== false) // Only show available slots
                .map(a => {
                    const slotDisplay = a.display || a.time_slot || '';
                    return `<li><strong>${slotDisplay}</strong> — ${a.recommendation || 'Available'}</li>`;
                })
                .join('');
        } else {
            altWrap.style.display = 'none';
            altList.innerHTML = '';
        }
        if (riskScore !== null && riskScore !== undefined) {
            riskLine.style.display = 'block';
            const parts = [];
            parts.push(`Risk score: ${riskScore}`);
            if (eventLabel) parts.push(`Event/Holiday: ${eventLabel}`);
            riskLine.textContent = parts.join(' • ');
        } else {
            if (eventLabel) {
                riskLine.style.display = 'block';
                riskLine.textContent = `Event/Holiday: ${eventLabel}`;
            } else {
                riskLine.style.display = 'none';
            }
        }
    }

    let lastShown = null;
    let conflictCheckTimeout = null;

    async function checkConflict() {
        // Clear any pending timeout
        if (conflictCheckTimeout) {
            clearTimeout(conflictCheckTimeout);
            conflictCheckTimeout = null;
        }

        const fid = facilitySel?.value;
        const date = dateInput?.value;
        const startTime = startTimeInput?.value;
        const endTime = endTimeInput?.value;
        
        if (!fid || !date || !startTime || !endTime) {
            clearMessage();
            return;
        }
        
        // Validate that times are valid
        if (startTime === '' || endTime === '' || startTime === endTime) {
            clearMessage();
            return;
        }
        
        // Build time slot string in format "HH:MM - HH:MM"
        const timeSlot = startTime + ' - ' + endTime;
        
        // Ensure message box exists
        if (!messageBox) {
            console.error('Conflict warning message box not found');
            return;
        }
        
        // Show loading state with professional styling
        messageBox.style.display = 'block';
        messageBox.style.opacity = '1';
        messageBox.style.background = '#f0f4ff';
        messageBox.style.border = '2px solid #6366f1';
        if (messageText) {
            messageText.style.color = '#4f46e5';
            messageText.textContent = 'Checking availability and conflicts...';
        }
        
        const conflictIcon = document.getElementById('conflict-icon');
        const conflictTitle = document.getElementById('conflict-title');
        if (conflictIcon) conflictIcon.textContent = '⏳';
        if (conflictTitle) {
            conflictTitle.textContent = 'Checking Availability...';
            conflictTitle.style.color = '#4f46e5';
        }
        
        if (altWrap) altWrap.style.display = 'none';
        if (riskLine) riskLine.style.display = 'none';
        
        try {
            const url = basePath + '/resources/views/pages/dashboard/ai_conflict_check.php';
            const body = `facility_id=${encodeURIComponent(fid)}&date=${encodeURIComponent(date)}&time_slot=${encodeURIComponent(timeSlot)}`;
            
            console.log('Checking conflict:', { fid, date, timeSlot, url });
            
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            
            if (!resp.ok) {
                console.error('Conflict check failed:', resp.status, resp.statusText);
                if (messageBox) {
                    messageBox.style.display = 'none';
                }
                return;
            }
            
            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text);
                console.log('Conflict check response:', data);
            } catch (e) {
                console.error('Failed to parse response:', text);
                if (messageBox) {
                    messageBox.style.display = 'none';
                }
                return;
            }
            
            if (data.error) {
                console.error('Conflict check error:', data.error);
                if (messageBox) {
                    messageBox.style.display = 'none';
                }
                return;
            }
            
            const eventLabel = eventMap[date] || null;
            const key = `${fid}|${date}|${timeSlot}`;
            
            // Add small delay to make loading state visible (professional feel)
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // Hard conflict (approved reservation) - block booking
            if (data.has_conflict) {
                showMessage(data.message || 'This slot is already booked (approved reservation). Please select an alternative time.', data.alternatives || [], data.risk_score ?? null, eventLabel, 'hard');
                lastShown = {fid, date, timeSlot};
            } 
            // Soft conflict (pending reservations) - show warning but allow booking
            else if (data.soft_conflicts && data.soft_conflicts.length > 0) {
                const pendingCount = data.pending_count || data.soft_conflicts.length;
                const msg = `Warning: ${pendingCount} pending reservation(s) exist for this slot. You can still book, but admin will approve only one based on priority.`;
                showMessage(msg, [], data.risk_score ?? null, eventLabel, 'soft');
                lastShown = {fid, date, timeSlot};
            } 
            // High risk or holiday
            else if ((data.risk_score ?? 0) >= 50 || eventLabel) {
                const msg = eventLabel
                    ? `Higher demand expected (${eventLabel}). Consider alternative slots.`
                    : 'Higher demand expected. Consider alternative slots.';
                showMessage(msg, data.alternatives || [], data.risk_score ?? null, eventLabel, 'risk');
                lastShown = {fid, date, timeSlot};
            } else {
                // No conflicts - show success message
                const successMsg = eventLabel 
                    ? `Slot is available! Note: ${eventLabel} may increase demand.`
                    : '✓ This time slot is available for booking!';
                showMessage(successMsg, [], data.risk_score ?? null, eventLabel, 'success');
                lastShown = {fid, date, timeSlot};
                
                // Keep success message visible - don't auto-hide
            }
        } catch (e) {
            console.error('Conflict check exception:', e);
            console.error('Error stack:', e.stack);
            if (messageBox && messageText) {
                messageBox.style.display = 'block';
                messageBox.style.opacity = '1';
                messageBox.style.background = '#fdecee';
                messageBox.style.border = '2px solid #b23030';
                messageText.style.color = '#b23030';
                messageText.textContent = 'Error checking availability. Please try again.';
            }
        }
    }

    function debouncedCheckConflict() {
        // OPTIMIZED: Debounce: wait 500ms after user stops changing inputs before checking
        // This reduces API calls when user is still typing/selecting
        if (conflictCheckTimeout) {
            clearTimeout(conflictCheckTimeout);
        }
        conflictCheckTimeout = setTimeout(checkConflict, 500);
    }

    // Add event listeners for both 'change' and 'input' events
    // 'change' fires on blur, 'input' fires as user types/selects
    if (facilitySel) {
        facilitySel.addEventListener('change', debouncedCheckConflict);
    }
    if (dateInput) {
        dateInput.addEventListener('change', debouncedCheckConflict);
        dateInput.addEventListener('input', debouncedCheckConflict);
    }
    if (startTimeInput) {
        startTimeInput.addEventListener('change', debouncedCheckConflict);
    }
    if (endTimeInput) {
        endTimeInput.addEventListener('change', debouncedCheckConflict);
    }
    
    // Also trigger check when time inputs are updated via time picker
    startTimeInput?.addEventListener('blur', function() {
        if (facilitySel?.value && dateInput?.value && startTimeInput?.value && endTimeInput?.value) {
            checkConflict();
        }
    });
    endTimeInput?.addEventListener('blur', function() {
        if (facilitySel?.value && dateInput?.value && startTimeInput?.value && endTimeInput?.value) {
            checkConflict();
        }
    });
    
    // Time validation: ensure end time is after start time and within limits
    // Note: Times are now select dropdowns with only 30-minute increments, so no rounding needed
    function validateTimes() {
        if (!startTimeInput || !endTimeInput) return true;
        
        const startTime = startTimeInput.value;
        const endTime = endTimeInput.value;
        
        if (!startTime || !endTime) return true;
        
        const start = new Date('2000-01-01T' + startTime);
        const end = new Date('2000-01-01T' + endTime);
        
        if (end <= start) {
            endTimeInput.setCustomValidity('End time must be after start time');
            return false;
        }
        
        const durationMs = end - start;
        const durationHours = durationMs / (1000 * 60 * 60);
        
        if (durationHours > 12) {
            endTimeInput.setCustomValidity('Reservation duration cannot exceed 12 hours');
            return false;
        }
        
        if (durationHours < 0.5) {
            endTimeInput.setCustomValidity('Reservation duration must be at least 30 minutes');
            return false;
        }
        
        endTimeInput.setCustomValidity('');
        
        // After validation passes, trigger conflict check if all fields are filled
        if (facilitySel?.value && dateInput?.value) {
            debouncedCheckConflict();
        }
        
        return true;
    }
    
    startTimeInput?.addEventListener('change', validateTimes);
    endTimeInput?.addEventListener('change', validateTimes);
    
    // Update end time options based on start time selection
    startTimeInput?.addEventListener('change', function() {
        if (!startTimeInput || !endTimeInput) return;
        const startTime = startTimeInput.value;
        if (!startTime) {
            // Enable all end time options if no start time selected
            const endOptions = endTimeInput.querySelectorAll('option');
            endOptions.forEach(option => option.disabled = false);
            return;
        }
        
        // Filter end time options to only show times after start time
        const [startH, startM] = startTime.split(':').map(Number);
        const startMinutes = startH * 60 + startM;
        const endOptions = endTimeInput.querySelectorAll('option');
        endOptions.forEach(option => {
            if (option.value === '') {
                option.disabled = false;
                return;
            }
            const [endH, endM] = option.value.split(':').map(Number);
            const endMinutes = endH * 60 + endM;
            option.disabled = endMinutes <= startMinutes;
        });
        
        // If current end time is invalid, clear it
        const endTime = endTimeInput.value;
        if (endTime) {
            const [endH, endM] = endTime.split(':').map(Number);
            const endMinutes = endH * 60 + endM;
            if (endMinutes <= startMinutes) {
                endTimeInput.value = '';
            }
        }
        validateTimes();
    });
    
    // Facility Recommendations
    const purposeInput = document.getElementById('purpose-input');
    const recommendationsDiv = document.getElementById('ai-recommendations');
    const recommendationsList = document.getElementById('recommendations-list');
    let recommendationTimeout = null;
    
    async function fetchRecommendations() {
        const purpose = purposeInput?.value?.trim();
        if (!purpose || purpose.length < 5) {
            if (recommendationsDiv) recommendationsDiv.style.display = 'none';
            return;
        }
        
        // OPTIMIZED: Skip if essential fields are missing (reduces unnecessary API calls)
        const date = dateInput?.value;
        const startTime = startTimeInput?.value;
        const endTime = endTimeInput?.value;
        
        if (!date || !startTime || !endTime) {
            // Don't fetch recommendations without date/time context
            return;
        }
        
        const timeSlot = startTime + ' - ' + endTime;
        const expectedAttendees = document.querySelector('input[name="expected_attendees"]')?.value || 50;
        const isCommercial = document.getElementById('is-commercial')?.checked || false;
        
        // Show loading indicator
        const loadingEl = document.getElementById('recommendations-loading');
        const listEl = document.getElementById('recommendations-list');
        if (recommendationsDiv) recommendationsDiv.style.display = 'block';
        if (loadingEl) loadingEl.style.display = 'block';
        if (listEl) listEl.innerHTML = '';
        
        try {
            const formData = new URLSearchParams();
            formData.append('purpose', purpose);
            formData.append('reservation_date', date);
            formData.append('time_slot', timeSlot);
            formData.append('expected_attendees', expectedAttendees);
            if (isCommercial) formData.append('is_commercial', '1');
            
            const response = await fetch(basePath + '/resources/views/pages/dashboard/facility_recommendations_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch recommendations');
            }
            
            const data = await response.json();
            
            // Store recommendations data for use in selectRecommendation
            currentRecommendationsData = data;
            
            // Hide loading indicator
            if (loadingEl) loadingEl.style.display = 'none';
            
            if (data.recommendations && data.recommendations.length > 0) {
                if (recommendationsDiv) recommendationsDiv.style.display = 'block';
                const bestTimesEl = document.getElementById('recommendations-best-times');
                if (bestTimesEl) {
                    if (data.best_times_label) {
                        bestTimesEl.textContent = '🕐 ' + data.best_times_label;
                        bestTimesEl.style.display = 'block';
                    } else {
                        bestTimesEl.style.display = 'none';
                    }
                }
                if (recommendationsList) {
                    recommendationsList.innerHTML = data.recommendations.map((rec, idx) => {
                        const score = rec.ml_relevance_score != null ? Number(rec.ml_relevance_score).toFixed(1) : 'N/A';
                        let reason = rec.reason || 'Recommended based on your event purpose';
                        if (rec.distance && reason.indexOf(rec.distance) === -1) {
                            reason += ' · ' + rec.distance + ' from you';
                        }
                        
                        // Format operating hours for display
                        let operatingHoursDisplay = '';
                        if (rec.operating_hours) {
                            const hours = rec.operating_hours;
                            // Try to format nicely
                            if (hours.includes('-')) {
                                const parts = hours.split('-').map(h => h.trim());
                                if (parts.length === 2) {
                                    // Convert 24-hour to 12-hour if needed
                                    const formatTime = (timeStr) => {
                                        if (timeStr.match(/^\d{2}:\d{2}$/)) {
                                            const [h, m] = timeStr.split(':').map(Number);
                                            const period = h >= 12 ? 'PM' : 'AM';
                                            const hour12 = h > 12 ? h - 12 : (h === 0 ? 12 : h);
                                            return `${hour12}:${m.toString().padStart(2, '0')} ${period}`;
                                        }
                                        return timeStr;
                                    };
                                    operatingHoursDisplay = `🕐 ${formatTime(parts[0])} - ${formatTime(parts[1])}`;
                                } else {
                                    operatingHoursDisplay = `🕐 ${hours}`;
                                }
                            } else {
                                operatingHoursDisplay = `🕐 ${hours}`;
                            }
                        } else {
                            operatingHoursDisplay = '🕐 8:00 AM - 9:00 PM (default)';
                        }
                        
                        return `
                            <div class="recommendation-item" 
                                 data-facility-id="${rec.id}" 
                                 data-facility-name="${rec.name.replace(/"/g, '&quot;')}"
                                 style="padding:0.75rem; margin-bottom:0.5rem; background:white; border-radius:6px; border:1px solid #d0e8d0; cursor:pointer; transition:all 0.2s ease;"
                                 onmouseover="this.style.background='#f0f8f0'; this.style.borderColor='#0d7a43';"
                                 onmouseout="this.style.background='white'; this.style.borderColor='#d0e8d0';"
                                 onclick="selectRecommendation(${rec.id}, '${rec.name.replace(/'/g, "\\'")}')">
                                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:0.25rem;">
                                    <strong style="color:#0d7a43; font-size:0.95rem;">${idx + 1}. ${rec.name}</strong>
                                    <span style="color:#0d7a43; font-weight:bold; font-size:0.9rem;">Score: ${score}</span>
                                </div>
                                <div style="font-size:0.8rem; color:#666; margin-top:0.25rem; margin-bottom:0.25rem;">${reason}</div>
                                <div style="font-size:0.75rem; color:#0d7a43; font-weight:500; margin-top:0.25rem;">${operatingHoursDisplay}</div>
                                <div style="font-size:0.7rem; color:#8b95b5; margin-top:0.25rem; font-style:italic;">Click to select this facility</div>
                            </div>
                        `;
                    }).join('');
                }
            } else {
                if (recommendationsDiv) recommendationsDiv.style.display = 'none';
            }
        } catch (error) {
            console.error('Recommendation error:', error);
            if (loadingEl) loadingEl.style.display = 'none';
            if (recommendationsDiv) recommendationsDiv.style.display = 'none';
        }
    }
    
    // OPTIMIZED: Debounced recommendation fetching - reduced delay for faster response
    function debouncedFetchRecommendations() {
        if (recommendationTimeout) {
            clearTimeout(recommendationTimeout);
        }
        // Reduced to 600ms for faster response while still debouncing rapid typing
        recommendationTimeout = setTimeout(fetchRecommendations, 600);
    }
    
    purposeInput?.addEventListener('input', debouncedFetchRecommendations);
    purposeInput?.addEventListener('blur', fetchRecommendations);
    dateInput?.addEventListener('change', fetchRecommendations);
    startTimeInput?.addEventListener('change', fetchRecommendations);
    endTimeInput?.addEventListener('change', fetchRecommendations);
    
    // Function to select a recommendation and auto-fill facility and suggested time
    // Make it globally accessible for inline onclick handlers
    window.selectRecommendation = function(facilityId, facilityName) {
        if (!facilitySel || !startTimeInput || !endTimeInput) return;
        
        // Set the facility
        facilitySel.value = facilityId;
        filterTimeSlotsByOperatingHours(); // Filter time slots first
        
        // Try to set suggested time from recommendations if available
        if (currentRecommendationsData && currentRecommendationsData.suggested_times && currentRecommendationsData.suggested_times.length > 0) {
            // Use the first suggested time slot (format: "HH:MM - HH:MM")
            const suggestedSlot = currentRecommendationsData.suggested_times[0];
            if (suggestedSlot && suggestedSlot.includes(' - ')) {
                const [start, end] = suggestedSlot.split(' - ').map(t => t.trim());
                // Times are already in 24-hour format (e.g., "08:00", "12:00")
                if (start && end && start.match(/^\d{2}:\d{2}$/) && end.match(/^\d{2}:\d{2}$/)) {
                    // Set times after filtering is done
                    setTimeout(() => {
                        // Check if the times are still available after filtering
                        const startOpt = startTimeInput.querySelector(`option[value="${start}"]`);
                        const endOpt = endTimeInput.querySelector(`option[value="${end}"]`);
                        if (startOpt && !startOpt.disabled && endOpt && !endOpt.disabled) {
                            startTimeInput.value = start;
                            endTimeInput.value = end;
                            startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
                            endTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
                        } else {
                            // If suggested time is not available, use first available time
                            const firstStartOpt = startTimeInput.querySelector('option:not([disabled]):not([value=""])');
                            if (firstStartOpt) {
                                startTimeInput.value = firstStartOpt.value;
                                startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }
                    }, 150);
                }
            }
        }
        
        facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
        
        // Scroll to facility select to show it's been selected
        facilitySel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        
        // Show a brief notification
        const notification = document.createElement('div');
        notification.style.cssText = 'position:fixed; top:20px; right:20px; background:#0d7a43; color:white; padding:1rem 1.5rem; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:10000; font-size:0.9rem;';
        notification.textContent = `✓ Selected: ${facilityName}`;
        document.body.appendChild(notification);
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 2000);
    };
    
    // Function to parse operating hours and return start/end times in 24-hour format
    function parseOperatingHours(operatingHours) {
        if (!operatingHours || operatingHours.trim() === '') {
            return { start: 8, end: 21 }; // Default: 8:00 AM - 9:00 PM
        }
        
        const hours = operatingHours.trim();
        
        // Try to parse formats like "09:00-16:00" or "9:00-16:00"
        const match24 = hours.match(/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/);
        if (match24) {
            const startHour = parseInt(match24[1]);
            const endHour = parseInt(match24[3]);
            return { start: startHour, end: endHour };
        }
        
        // Try to parse formats like "8:00 AM - 4:00 PM"
        const match12 = hours.match(/(\d{1,2}):(\d{2})\s*(AM|PM)\s*-\s*(\d{1,2}):(\d{2})\s*(AM|PM)/i);
        if (match12) {
            let startHour = parseInt(match12[1]);
            const startPeriod = match12[3].toUpperCase();
            let endHour = parseInt(match12[4]);
            const endPeriod = match12[6].toUpperCase();
            
            if (startPeriod === 'PM' && startHour !== 12) startHour += 12;
            if (startPeriod === 'AM' && startHour === 12) startHour = 0;
            
            if (endPeriod === 'PM' && endHour !== 12) endHour += 12;
            if (endPeriod === 'AM' && endHour === 12) endHour = 0;
            
            return { start: startHour, end: endHour };
        }
        
        // Fallback to default
        return { start: 8, end: 21 };
    }
    
    // Function to filter time slots based on facility operating hours
    function filterTimeSlotsByOperatingHours() {
        if (!facilitySel || !startTimeInput || !endTimeInput) return;
        
        const selectedOption = facilitySel.options[facilitySel.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            // Reset to default if no facility selected
            const startOptions = startTimeInput.querySelectorAll('option');
            const endOptions = endTimeInput.querySelectorAll('option');
            startOptions.forEach(opt => opt.style.display = '');
            endOptions.forEach(opt => opt.style.display = '');
            return;
        }
        
        const operatingHours = selectedOption.getAttribute('data-operating-hours') || '';
        const { start: openHour, end: closeHour } = parseOperatingHours(operatingHours);
        
        // Filter start time options
        const startOptions = startTimeInput.querySelectorAll('option');
        startOptions.forEach(opt => {
            if (opt.value === '') {
                opt.style.display = '';
                return;
            }
            const [hour, minute] = opt.value.split(':').map(Number);
            const isWithinHours = hour >= openHour && hour < closeHour;
            opt.style.display = isWithinHours ? '' : 'none';
            opt.disabled = !isWithinHours;
        });
        
        // Filter end time options
        const endOptions = endTimeInput.querySelectorAll('option');
        endOptions.forEach(opt => {
            if (opt.value === '') {
                opt.style.display = '';
                return;
            }
            const [hour, minute] = opt.value.split(':').map(Number);
            // End time can be at closeHour:00 (inclusive)
            const isWithinHours = hour >= openHour && hour <= closeHour;
            opt.style.display = isWithinHours ? '' : 'none';
            opt.disabled = !isWithinHours;
        });
        
        // Update help text
        const startHelp = startTimeInput.parentElement.nextElementSibling;
        const endHelp = endTimeInput.parentElement.nextElementSibling;
        if (startHelp && startHelp.tagName === 'SMALL') {
            if (operatingHours) {
                const { start, end } = parseOperatingHours(operatingHours);
                const formatHour = (h) => {
                    const period = h >= 12 ? 'PM' : 'AM';
                    const hour12 = h > 12 ? h - 12 : (h === 0 ? 12 : h);
                    return `${hour12}:00 ${period}`;
                };
                startHelp.textContent = `Facility operating hours: ${formatHour(start)} - ${formatHour(end)}`;
            } else {
                startHelp.textContent = 'Facility operating hours: 8:00 AM - 9:00 PM';
            }
        }
        
        // Clear invalid selections
        const currentStart = startTimeInput.value;
        if (currentStart) {
            const [hour, minute] = currentStart.split(':').map(Number);
            if (hour < openHour || hour >= closeHour) {
                startTimeInput.value = '';
            }
        }
        
        const currentEnd = endTimeInput.value;
        if (currentEnd) {
            const [hour, minute] = currentEnd.split(':').map(Number);
            if (hour < openHour || hour > closeHour) {
                endTimeInput.value = '';
            }
        }
    }
    
    // Filter time slots when facility is selected
    facilitySel?.addEventListener('change', function() {
        filterTimeSlotsByOperatingHours();
        checkConflict();
    });

    // Prefill values and trigger change events
    if (preFacility && facilitySel) {
        const prefilledOption = facilitySel.querySelector(`option[value="${preFacility}"]`);
        if (prefilledOption) {
            const prefilledStatus = prefilledOption.getAttribute('data-status');
            // Only prefill if facility is available (not maintenance/offline)
            if (prefilledStatus === 'available') {
                facilitySel.value = preFacility;
                filterTimeSlotsByOperatingHours(); // Filter time slots based on operating hours
                facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (prefilledStatus === 'maintenance' || prefilledStatus === 'offline') {
                // If pre-filled facility is maintenance/offline, show warning modal
                setTimeout(() => {
                    facilitySel.value = preFacility;
                    filterTimeSlotsByOperatingHours(); // Filter time slots based on operating hours
                    facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
                }, 100);
            }
        }
    }
    
    // Also filter on initial page load if facility is already selected
    if (facilitySel && facilitySel.value) {
        setTimeout(() => filterTimeSlotsByOperatingHours(), 100);
    }
    if (preDate && dateInput) {
        dateInput.value = preDate;
        dateInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (preSlot && startTimeInput && endTimeInput) {
        // Parse time slot format "HH:MM - HH:MM" or legacy format
        const timeMatch = preSlot.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
        if (timeMatch) {
            startTimeInput.value = timeMatch[1] + ':' + timeMatch[2];
            endTimeInput.value = timeMatch[3] + ':' + timeMatch[4];
            startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // If all fields are pre-filled, trigger conflict check after a short delay
    if (preFacility && preDate && startTimeInput?.value && endTimeInput?.value) {
        setTimeout(() => {
            checkConflict();
        }, 200);
    }
    
    // Safety: Ensure body scroll is restored on page load
    document.body.style.overflow = '';
    document.body.style.removeProperty('overflow');
    
    // Add safety cleanup: restore scroll if modal is closed by clicking outside
    const modal = document.getElementById('maintenanceWarningModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            // If clicking the backdrop (not the dialog), close modal
            if (e.target === modal) {
                closeMaintenanceWarning();
            }
        });
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
