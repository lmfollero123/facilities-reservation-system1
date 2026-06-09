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
require_once __DIR__ . '/../../../../config/paymongo_helper.php';
require_once __DIR__ . '/../../../../config/mail_helper.php';
require_once __DIR__ . '/../../../../config/email_templates.php';
require_once __DIR__ . '/../../../../config/reservation_helpers.php';
require_once __DIR__ . '/../../../../config/time_helpers.php';
require_once __DIR__ . '/../../../../config/sms_helper.php';
require_once __DIR__ . '/../../../../config/ui_helpers.php';

$pdo = db();

if (isset($_GET['tab']) && $_GET['tab'] === 'reservations') {
    $rq = $_GET;
    unset($rq['tab']);
    $rq['module'] = 'mine';
    header('Location: ' . base_path() . '/dashboard/book-facility?' . http_build_query($rq));
    exit;
}

$reservationsHubMine = (($_SERVER['_RESERVATIONS_HUB_ROUTE'] ?? '') === 'mine') || (isset($_GET['module']) && $_GET['module'] === 'mine');
$pageTitle = $reservationsHubMine ? 'My Reservations | LGU Facilities Reservation' : 'Book a Facility | LGU Facilities Reservation';
$success = '';
$error = '';
$bcfOpenBookingModal = false;
$conflictWarning = null;
$recommendations = [];

$BOOKING_LIMIT_ACTIVE = 3; // max active (pending+approved) in window
$BOOKING_LIMIT_WINDOW_DAYS = 30; // rolling window for active bookings
$BOOKING_ADVANCE_MAX_DAYS = 60; // max days ahead
$BOOKING_PER_DAY = 1; // max bookings per user per day (pending+approved)

// Check if user is verified and if they have uploaded a valid ID
// Note: Staff and Admin are automatically considered verified
$userId = $_SESSION['user_id'] ?? null;
$sessionActorId = (int)$userId;
$viewerRole = (string)($_SESSION['role'] ?? 'Resident');
$canBookOnBehalf = in_array($viewerRole, ['Admin', 'Staff'], true);
$walkInResidents = [];
$selectedBookForUserId = (int)($_POST['book_for_user_id'] ?? $_GET['book_for'] ?? 0);
if ($canBookOnBehalf && $pdo) {
    try {
        $wr = $pdo->query("SELECT id, name, email FROM users WHERE role = 'Resident' AND status = 'active' ORDER BY name");
        $walkInResidents = $wr ? $wr->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $walkInResidents = [];
    }
}
$bookingSubjectId = ($canBookOnBehalf && $selectedBookForUserId > 0) ? $selectedBookForUserId : $sessionActorId;
$userVerificationStmt = $pdo->prepare('SELECT is_verified, role FROM users WHERE id = :user_id');
$userVerificationStmt->execute(['user_id' => $userId]);
$userVerificationData = $userVerificationStmt->fetch(PDO::FETCH_ASSOC);
$isVerified = (bool)($userVerificationData['is_verified'] ?? false);
$userRole = $userVerificationData['role'] ?? 'Resident';
$isAdminUser = ($userRole === 'Admin');

// Staff and Admin are automatically verified (no ID upload required)
$isVerifiedOrPrivileged = $isVerified || in_array($userRole, ['Staff', 'Admin'], true);

// Check if user has already uploaded a valid ID document
$hasValidIdDocStmt = $pdo->prepare('SELECT id FROM user_documents WHERE user_id = :user_id AND document_type = "valid_id" AND is_archived = 0 LIMIT 1');
$hasValidIdDocStmt->execute(['user_id' => $bookingSubjectId]);
$hasValidIdDocument = (bool)$hasValidIdDocStmt->fetch();
if ($canBookOnBehalf && $bookingSubjectId !== $sessionActorId) {
    $subVer = $pdo->prepare('SELECT is_verified FROM users WHERE id = :id LIMIT 1');
    $subVer->execute(['id' => $bookingSubjectId]);
    $isVerified = (bool)$subVer->fetchColumn();
    $isVerifiedOrPrivileged = true;
}

try {
    $facilitiesStmt = $pdo->query('SELECT id, name, base_rate, status, operating_hours FROM facilities ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $facilities = [];
    $error = 'Unable to load facilities right now.';
}

// Backward-compatible schema checks so booking works even when some payment migrations are not yet applied.
$reservationColumns = [];
$reservationStatusValues = [];
$historyStatusValues = [];
try {
    $schemaStmt = $pdo->query('SHOW COLUMNS FROM reservations');
    foreach ($schemaStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $field = (string)($col['Field'] ?? '');
        $type = (string)($col['Type'] ?? '');
        if ($field !== '') {
            $reservationColumns[$field] = true;
            if ($field === 'status' && stripos($type, 'enum(') === 0 && preg_match_all("/'([^']+)'/", $type, $m)) {
                $reservationStatusValues = $m[1];
            }
        }
    }

    $histSchemaStmt = $pdo->query('SHOW COLUMNS FROM reservation_history');
    foreach ($histSchemaStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $field = (string)($col['Field'] ?? '');
        $type = (string)($col['Type'] ?? '');
        if ($field === 'status' && stripos($type, 'enum(') === 0 && preg_match_all("/'([^']+)'/", $type, $m)) {
            $historyStatusValues = $m[1];
            break;
        }
    }
} catch (Throwable $e) {
    // Keep defaults empty; logic below safely falls back.
}

$supportsPendingPayment = in_array('pending_payment', $reservationStatusValues, true);
$historySupportsPendingPayment = in_array('pending_payment', $historyStatusValues, true);
$hasPriorityLevelColumn = isset($reservationColumns['priority_level']);
$hasExpiresAtColumn = isset($reservationColumns['expires_at']);
$hasPaymentDueAtColumn = isset($reservationColumns['payment_due_at']);
$activeBookingStatusesSql = $supportsPendingPayment
    ? '"pending_payment","pending","approved"'
    : '"pending","approved"';

// Prepare base path for AJAX calls
$basePath = base_path();

$postedBookingAction = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['action'] ?? null) : null;
$isReservationsMgmtPost = $postedBookingAction !== null && in_array((string)$postedBookingAction, ['edit_details', 'reschedule', 'cancel_reservation'], true);

$message = '';
$messageType = 'success';
$canViewOtherReservationDetails = in_array($viewerRole, ['Admin', 'Staff'], true);
$GLOBALS['frsMineNotifyPath'] = base_path() . '/dashboard/book-facility?module=mine';
$GLOBALS['frsMineNotifyAbsUrl'] = base_url() . '/dashboard/book-facility?module=mine';
autoDeclineExpiredReservations();
$frsCsrfOk = frs_csrf_ok();
$frsCsrfError = 'Your session expired or the form is invalid. Please refresh and try again.';
require_once __DIR__ . '/includes/reservations_mine_post_handlers.php';
require_once __DIR__ . '/../../../../config/reservation_documents.php';

// Check if pre-filled from Smart Scheduler (for showing notification)
$prefillFacilityId = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : null;
$prefillTimeSlot = isset($_GET['time_slot']) ? trim($_GET['time_slot']) : null;

// Open booking modal on load when AI chatbot or scheduler passes prefill query params
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach (['facility_id', 'reservation_date', 'time_slot', 'start_time', 'end_time', 'purpose', 'expected_attendees', 'open_booking'] as $bcfOpenKey) {
        if (!empty($_GET[$bcfOpenKey])) {
            $bcfOpenBookingModal = true;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$frsCsrfOk && $isReservationsMgmtPost) {
    $message = $frsCsrfError;
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$frsCsrfOk && !$isReservationsMgmtPost) {
    $error = $frsCsrfError;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isReservationsMgmtPost) {
    $facilityId = (int)($_POST['facility_id'] ?? 0);
    $date = $_POST['reservation_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $purpose = trim($_POST['purpose'] ?? '');
    $bookingNotes = trim($_POST['booking_notes'] ?? '');
    if ($purpose !== '' && $bookingNotes !== '') {
        $purpose .= "\n\n--- Additional notes ---\n" . $bookingNotes;
    }
    $expectedAttendees = isset($_POST['expected_attendees']) ? (int)$_POST['expected_attendees'] : 0;
    $isCommercial = false;
    $priorityLevel = 3;

    $bookingUserId = $sessionActorId;
    if ($canBookOnBehalf && !empty($_POST['book_for_user_id'])) {
        $bfid = (int)$_POST['book_for_user_id'];
        $resChk = $pdo->prepare("SELECT id, name FROM users WHERE id = :id AND role = 'Resident' AND status = 'active' LIMIT 1");
        $resChk->execute(['id' => $bfid]);
        $resRow = $resChk->fetch(PDO::FETCH_ASSOC);
        if (!$resRow) {
            $error = 'Please select a valid active resident for walk-in booking.';
        } else {
            $bookingUserId = $bfid;
            $purpose .= "\n\n--- Walk-in booking ---\nAssisted by: " . trim((string)($_SESSION['name'] ?? 'Staff'));
        }
    }
    if (empty($error)) {
        $userId = $bookingUserId;
        $selectedBookForUserId = $bookingUserId;
    }

    $eventPermitType = in_array($_POST['event_document_type'] ?? '', ['event_permit', 'barangay_resolution', 'letter_request', 'other'], true)
        ? (string)$_POST['event_document_type'] : 'event_permit';
    $eventPermitFile = $_FILES['event_supporting_doc'] ?? null;
    
    // Check if user is verified - if not, require ID upload
    $userVerificationStmt = $pdo->prepare('SELECT is_verified, role FROM users WHERE id = :user_id');
    $userVerificationStmt->execute(['user_id' => $userId]);
    $userVerifyRow = $userVerificationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $isVerified = (bool)($userVerifyRow['is_verified'] ?? false);
    $postUserRole = (string)($userVerifyRow['role'] ?? $userRole);
    $isAdminUser = ($postUserRole === 'Admin');
    $isVerifiedOrPrivileged = $isVerified || in_array($postUserRole, ['Staff', 'Admin'], true);
    
    $validIdFile = $_FILES['doc_valid_id'] ?? null;
    $hasValidIdUpload = $validIdFile && isset($validIdFile['tmp_name']) && $validIdFile['error'] === UPLOAD_ERR_OK && $validIdFile['size'] > 0;
    
    $staffAssistedBooking = $canBookOnBehalf && $bookingUserId !== $sessionActorId;
    if ($staffAssistedBooking) {
        $isVerifiedOrPrivileged = true;
    }

    // If user is not verified and hasn't uploaded a valid ID, require ID upload
    if (!$staffAssistedBooking && !$isVerifiedOrPrivileged && !$hasValidIdDocument && !$hasValidIdUpload) {
        $error = 'Please upload a valid ID document. Unverified users must submit a valid ID when making a reservation.';
    }
    
    // If user already has a valid ID document uploaded, don't allow another upload
    if (!$isVerifiedOrPrivileged && $hasValidIdDocument && $hasValidIdUpload) {
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
        } elseif (!$isAdminUser && $reservationDate > (clone $today)->modify('+' . $BOOKING_ADVANCE_MAX_DAYS . ' days')) {
            $error = "Bookings are allowed only up to {$BOOKING_ADVANCE_MAX_DAYS} days in advance.";
        }
    }

    if (!$error && $date && $startTime && function_exists('frs_is_start_time_past_for_date')) {
        if (frs_is_start_time_past_for_date($date, $startTime)) {
            $earliestHi = frs_minutes_to_hhmm(frs_earliest_bookable_start_minutes());
            $error = 'For reservations today, the earliest start time is ' . $earliestHi . ' (Philippine time). Past time slots are not available.';
        }
    }

    if (!$error && $expectedAttendees < 1) {
        $error = 'Expected number of attendees is required (enter at least 1).';
    }

    // Enforce occupancy vs listing capacity when a numeric capacity is configured
    if (!$error && $facilityId && $expectedAttendees >= 1) {
        $capLookup = $pdo->prepare('SELECT capacity FROM facilities WHERE id = :id LIMIT 1');
        $capLookup->execute(['id' => $facilityId]);
        $capCell = $capLookup->fetchColumn();
        if ($capCell !== false && preg_match('/(\d{1,7})/', (string)$capCell, $cm)) {
            $maxListed = (int)$cm[1];
            if ($expectedAttendees > $maxListed) {
                $error = 'Expected attendees (' . $expectedAttendees . ') cannot exceed this facility\'s maximum occupancy (' . $maxListed . ').';
            }
        }
    }
    
    if (!$error && !$isAdminUser) {
        // Active bookings cap in rolling window
        $windowEnd = date('Y-m-d', strtotime('+' . ($BOOKING_LIMIT_WINDOW_DAYS - 1) . ' days'));
        $activeCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM reservations
             WHERE user_id = :uid
               AND reservation_date BETWEEN :start AND :end
               AND status IN (' . $activeBookingStatusesSql . ')'
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
                   AND status IN (' . $activeBookingStatusesSql . ')'
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

    if (!$error && !$isAdminUser) {
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

        // Block bookings on synced blackout dates (e.g., CIMM maintenance windows).
        if (!$error && !empty($date)) {
            $blackoutStmt = $pdo->prepare(
                'SELECT reason FROM facility_blackout_dates WHERE facility_id = :facility_id AND blackout_date = :date LIMIT 1'
            );
            $blackoutStmt->execute([
                'facility_id' => $facilityId,
                'date' => $date,
            ]);
            $blackout = $blackoutStmt->fetch(PDO::FETCH_ASSOC);
            if ($blackout) {
                $reason = trim((string)($blackout['reason'] ?? 'Facility maintenance'));
                $error = '⚠️ This facility is unavailable on the selected date.'
                    . ($reason !== '' ? (' Reason: ' . $reason . '.') : '');
            }
        }
    }
    
    // AI Purpose Analysis - Check if purpose is unclear or categorize it
    $purposeAnalysis = null;
    $purposeCategory = null;
    if (!$error && !$isAdminUser && !empty($purpose) && function_exists('detectUnclearPurpose')) {
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
    
    if (!$error && !$isAdminUser) {
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

            $paymentsEnabled = false;
            $requirePaymentForReservations = false;
            $paymentsCfgPath = __DIR__ . '/../../../../config/payments.php';
            if (file_exists($paymentsCfgPath)) {
                $paymentsCfg = require $paymentsCfgPath;
                $paymentsEnabled = !empty($paymentsCfg['enabled']);
                $requirePaymentForReservations = !empty($paymentsCfg['require_payment_for_reservations']);
            }

            $autoApprovedByRules = !empty($autoApprovalResult['auto_approve']);
            $hybridPaymentMode = $paymentsEnabled && $requirePaymentForReservations;

            if ($hybridPaymentMode) {
                // Hybrid policy:
                // - Auto-approvable reservations go straight to payment step.
                // - Manual-approval reservations stay pending until staff approval.
                $initialStatus = ($supportsPendingPayment && $autoApprovedByRules) ? 'pending_payment' : 'pending';
                $isAutoApproved = false;
            } else {
                // Legacy mode (no payment gate): keep original auto-approval behavior.
                $initialStatus = $autoApprovedByRules ? 'approved' : 'pending';
                $isAutoApproved = $autoApprovedByRules;
            }

            $paymentWindowMinutes = 30;
            if (isset($paymentsCfg) && is_array($paymentsCfg)) {
                $candidateWindow = (int)($paymentsCfg['payment_window_minutes'] ?? 30);
                if ($candidateWindow > 0) {
                    $paymentWindowMinutes = $candidateWindow;
                }
            }
            $paymentDueAt = date('Y-m-d H:i:s', strtotime('+' . $paymentWindowMinutes . ' minutes'));
            // Payment holds use payment window; normal pending keeps 48-hour expiry.
            $expiresAt = $initialStatus === 'pending_payment'
                ? $paymentDueAt
                : ($initialStatus === 'pending' ? date('Y-m-d H:i:s', strtotime('+48 hours')) : null);
            
            // Insert reservation with dynamic columns based on current database schema.
            $insertColumns = [
                'user_id',
                'facility_id',
                'reservation_date',
                'time_slot',
                'purpose',
                'status',
                'expected_attendees',
                'is_commercial',
                'auto_approved',
            ];
            $insertParams = [
                'user' => $userId,
                'facility' => $facilityId,
                'date' => $date,
                'slot' => $timeSlot,
                'purpose' => $purpose,
                'status' => $initialStatus,
                'attendees' => $expectedAttendees,
                'commercial' => $isCommercial ? 1 : 0,
                'auto_approved' => $isAutoApproved ? 1 : 0,
            ];

            if ($hasPriorityLevelColumn) {
                $insertColumns[] = 'priority_level';
                $insertParams['priority'] = $priorityLevel;
            }
            if ($hasExpiresAtColumn) {
                $insertColumns[] = 'expires_at';
                $insertParams['expires'] = $expiresAt;
            }
            if ($hasPaymentDueAtColumn) {
                $insertColumns[] = 'payment_due_at';
                $insertParams['payment_due_at'] = $paymentDueAt;
            }

            $insertPlaceholders = [
                ':user',
                ':facility',
                ':date',
                ':slot',
                ':purpose',
                ':status',
                ':attendees',
                ':commercial',
                ':auto_approved',
            ];
            if ($hasPriorityLevelColumn) {
                $insertPlaceholders[] = ':priority';
            }
            if ($hasExpiresAtColumn) {
                $insertPlaceholders[] = ':expires';
            }
            if ($hasPaymentDueAtColumn) {
                $insertPlaceholders[] = ':payment_due_at';
            }

            $pdo->beginTransaction();
            frs_lock_facility_for_booking($pdo, $facilityId);
            $recheckConflict = detectBookingConflict($facilityId, $date, $timeSlot);
            if ($recheckConflict['has_conflict']) {
                $pdo->rollBack();
                throw new Exception('Conflict detected: ' . ($recheckConflict['message'] ?? 'This time slot is no longer available.'));
            }

            $stmt = $pdo->prepare(
                'INSERT INTO reservations (' . implode(', ', $insertColumns) . ')
                 VALUES (' . implode(', ', $insertPlaceholders) . ')'
            );
            $stmt->execute($insertParams);
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
            $historyStatus = ($initialStatus === 'pending_payment' && !$historySupportsPendingPayment) ? 'pending' : $initialStatus;
            if ($initialStatus === 'pending_payment') {
                $historyNote = 'Auto-approved by rules. Awaiting payment confirmation.';
            } elseif ($initialStatus === 'approved') {
                $historyNote = 'Automatically approved by system - all conditions met.';
            } else {
                $historyNote = 'Pending manual review by staff.';
            }
            
            // Add purpose analysis note if purpose is unclear
            if ($purposeAnalysis && $purposeAnalysis['is_unclear']) {
                $historyNote .= ' | Purpose flagged as unclear by AI';
            }
            
            $historyStmt->execute([
                'res_id' => $newReservationId,
                'status' => $historyStatus,
                'note' => $historyNote
            ]);
            
            // Log audit event
            $auditDetails = 'RES-' . $newReservationId . ' – ' . $facilityName . ' (' . $date . ' ' . $timeSlot . ')';
            if ($isAutoApproved) {
                $auditDetails .= ' [Auto-approved]';
            }
            $staffLabel = trim((string)($_SESSION['name'] ?? 'Staff'));
            logAudit('Created reservation request', 'Reservations', $auditDetails, $staffAssistedBooking ? $sessionActorId : null);
            if ($staffAssistedBooking) {
                logAudit('Walk-in booking created', 'Reservations', 'RES-' . $newReservationId . ' for user #' . $bookingUserId . ' by ' . $staffLabel, $sessionActorId);
            }
            $pdo->commit();
            if (!empty($eventPermitFile['tmp_name']) && ($eventPermitFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $docResult = frs_store_reservation_document((int)$newReservationId, $eventPermitFile, $sessionActorId, $eventPermitType);
                if (!$docResult['ok']) {
                    $success .= ' Note: supporting document was not saved — ' . ($docResult['error'] ?? 'upload error');
                }
            }
            
            if ($initialStatus === 'pending_payment') {
                createNotification(
                    $userId,
                    'booking',
                    'Reservation Hold Created',
                    'Your reservation for ' . $facilityName . ' is on hold. Complete payment to secure the slot.',
                    base_path() . '/dashboard/pay-now?reservation_id=' . (int)$newReservationId
                );
                $success = 'Reservation approved. Please complete payment to finalize and secure your slot.';
            } elseif ($initialStatus === 'approved') {
                createNotification(
                    $userId,
                    'booking',
                    'Reservation Approved',
                    'Your reservation for ' . $facilityName . ' has been approved.',
                    base_path() . '/dashboard/book-facility?module=mine'
                );
                $success = 'Reservation automatically approved! Your booking has been confirmed.';
            } else {
                createNotification(
                    $userId,
                    'booking',
                    'Reservation Submitted',
                    'Your reservation request for ' . $facilityName . ' has been submitted and is pending review.',
                    base_path() . '/dashboard/book-facility?module=mine'
                );
                $success = 'Reservation submitted successfully and is pending review.';
            }
            $userMobileStmt = $pdo->prepare('SELECT mobile FROM users WHERE id = :id LIMIT 1');
            $userMobileStmt->execute(['id' => $userId]);
            $bookingSmsPayload = [
                'facility_name' => $facilityName,
                'reservation_date' => $date,
                'time_slot' => $timeSlot,
                'requester_mobile' => $userMobileStmt->fetchColumn() ?: null,
            ];
            $smsStatusKey = $initialStatus === 'pending_payment' ? 'pending_payment' : ($initialStatus === 'approved' ? 'approved' : 'pending');
            sendReservationStatusSms($bookingSmsPayload, $smsStatusKey);
            if ($purposeAnalysis && $purposeAnalysis['is_unclear']) {
                $success .= ' ⚠️ ' . htmlspecialchars($purposeAnalysis['warning']);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_starts_with($e->getMessage(), 'Conflict detected')) {
                $error = '⚠️ ' . $e->getMessage();
            } else {
                $error = 'Unable to submit reservation. Please try again later.';
            }
            error_log('Reservation submission error: ' . $e->getMessage());
        }
        }
    } elseif (!$error && $isAdminUser) {
        // Admin exemption: bypass booking policy constraints and proceed directly.
        try {
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

            $paymentsEnabled = false;
            $requirePaymentForReservations = false;
            $paymentsCfgPath = __DIR__ . '/../../../../config/payments.php';
            if (file_exists($paymentsCfgPath)) {
                $paymentsCfg = require $paymentsCfgPath;
                $paymentsEnabled = !empty($paymentsCfg['enabled']);
                $requirePaymentForReservations = !empty($paymentsCfg['require_payment_for_reservations']);
            }

            $autoApprovedByRules = !empty($autoApprovalResult['auto_approve']);
            $hybridPaymentMode = $paymentsEnabled && $requirePaymentForReservations;

            if ($hybridPaymentMode) {
                $initialStatus = ($supportsPendingPayment && $autoApprovedByRules) ? 'pending_payment' : 'pending';
                $isAutoApproved = false;
            } else {
                $initialStatus = $autoApprovedByRules ? 'approved' : 'pending';
                $isAutoApproved = $autoApprovedByRules;
            }

            $paymentWindowMinutes = 30;
            if (isset($paymentsCfg) && is_array($paymentsCfg)) {
                $candidateWindow = (int)($paymentsCfg['payment_window_minutes'] ?? 30);
                if ($candidateWindow > 0) {
                    $paymentWindowMinutes = $candidateWindow;
                }
            }
            $paymentDueAt = date('Y-m-d H:i:s', strtotime('+' . $paymentWindowMinutes . ' minutes'));
            $expiresAt = $initialStatus === 'pending_payment'
                ? $paymentDueAt
                : ($initialStatus === 'pending' ? date('Y-m-d H:i:s', strtotime('+48 hours')) : null);

            $insertColumns = [
                'user_id',
                'facility_id',
                'reservation_date',
                'time_slot',
                'purpose',
                'status',
                'expected_attendees',
                'is_commercial',
                'auto_approved',
            ];
            $insertParams = [
                'user' => $userId,
                'facility' => $facilityId,
                'date' => $date,
                'slot' => $timeSlot,
                'purpose' => $purpose,
                'status' => $initialStatus,
                'attendees' => $expectedAttendees,
                'commercial' => $isCommercial ? 1 : 0,
                'auto_approved' => $isAutoApproved ? 1 : 0,
            ];

            if ($hasPriorityLevelColumn) {
                $insertColumns[] = 'priority_level';
                $insertParams['priority'] = $priorityLevel;
            }
            if ($hasExpiresAtColumn) {
                $insertColumns[] = 'expires_at';
                $insertParams['expires'] = $expiresAt;
            }
            if ($hasPaymentDueAtColumn) {
                $insertColumns[] = 'payment_due_at';
                $insertParams['payment_due_at'] = $paymentDueAt;
            }

            $insertPlaceholders = [
                ':user',
                ':facility',
                ':date',
                ':slot',
                ':purpose',
                ':status',
                ':attendees',
                ':commercial',
                ':auto_approved',
            ];
            if ($hasPriorityLevelColumn) {
                $insertPlaceholders[] = ':priority';
            }
            if ($hasExpiresAtColumn) {
                $insertPlaceholders[] = ':expires';
            }
            if ($hasPaymentDueAtColumn) {
                $insertPlaceholders[] = ':payment_due_at';
            }

            $pdo->beginTransaction();
            frs_lock_facility_for_booking($pdo, $facilityId);
            $recheckConflict = detectBookingConflict($facilityId, $date, $timeSlot);
            if ($recheckConflict['has_conflict']) {
                $pdo->rollBack();
                throw new Exception('Conflict detected: ' . ($recheckConflict['message'] ?? 'This time slot is no longer available.'));
            }

            $stmt = $pdo->prepare(
                'INSERT INTO reservations (' . implode(', ', $insertColumns) . ')
                 VALUES (' . implode(', ', $insertPlaceholders) . ')'
            );
            $stmt->execute($insertParams);
            $newReservationId = $pdo->lastInsertId();

            $facilityStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = :id');
            $facilityStmt->execute(['id' => $facilityId]);
            $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
            $facilityName = $facility ? $facility['name'] : 'Unknown Facility';

            $historyStmt = $pdo->prepare(
                'INSERT INTO reservation_history (reservation_id, status, note, created_by) 
                 VALUES (:res_id, :status, :note, NULL)'
            );
            $historyStatus = ($initialStatus === 'pending_payment' && !$historySupportsPendingPayment) ? 'pending' : $initialStatus;
            if ($initialStatus === 'pending_payment') {
                $historyNote = 'Admin override: approved and awaiting payment confirmation.';
            } elseif ($initialStatus === 'approved') {
                $historyNote = 'Admin override: reservation approved.';
            } else {
                $historyNote = 'Admin override: submitted without resident booking limits.';
            }

            $historyStmt->execute([
                'res_id' => $newReservationId,
                'status' => $historyStatus,
                'note' => $historyNote
            ]);

            logAudit('Created reservation request (admin exempt)', 'Reservations', 'RES-' . $newReservationId . ' – ' . $facilityName . ' (' . $date . ' ' . $timeSlot . ')');
            $pdo->commit();
            if (!empty($eventPermitFile['tmp_name']) && ($eventPermitFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $docResult = frs_store_reservation_document((int)$newReservationId, $eventPermitFile, $sessionActorId, $eventPermitType);
                if (!$docResult['ok']) {
                    $success .= ' Note: supporting document was not saved — ' . ($docResult['error'] ?? 'upload error');
                }
            }

            if ($initialStatus === 'pending_payment') {
                createNotification(
                    $userId,
                    'booking',
                    'Reservation Hold Created',
                    'Your reservation for ' . $facilityName . ' is on hold. Complete payment to secure the slot.',
                    base_path() . '/dashboard/pay-now?reservation_id=' . (int)$newReservationId
                );
                $success = 'Admin booking submitted. Payment is required to finalize this reservation.';
            } elseif ($initialStatus === 'approved') {
                createNotification(
                    $userId,
                    'booking',
                    'Reservation Approved',
                    'Your reservation for ' . $facilityName . ' has been approved.',
                    base_path() . '/dashboard/book-facility?module=mine'
                );
                $success = 'Admin booking approved successfully.';
            } else {
                createNotification(
                    $userId,
                    'booking',
                    'Reservation Submitted',
                    'Your reservation request for ' . $facilityName . ' has been submitted and is pending review.',
                    base_path() . '/dashboard/book-facility?module=mine'
                );
                $success = 'Admin booking submitted successfully (rule exemptions applied).';
            }
            $userMobileStmt = $pdo->prepare('SELECT mobile FROM users WHERE id = :id LIMIT 1');
            $userMobileStmt->execute(['id' => $userId]);
            $bookingSmsPayload = [
                'facility_name' => $facilityName,
                'reservation_date' => $date,
                'time_slot' => $timeSlot,
                'requester_mobile' => $userMobileStmt->fetchColumn() ?: null,
            ];
            $smsStatusKey = $initialStatus === 'pending_payment' ? 'pending_payment' : ($initialStatus === 'approved' ? 'approved' : 'pending');
            sendReservationStatusSms($bookingSmsPayload, $smsStatusKey);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_starts_with($e->getMessage(), 'Conflict detected')) {
                $error = '⚠️ ' . $e->getMessage();
            } else {
                $error = 'Unable to submit reservation. Please try again later.';
            }
            error_log('Reservation submission error (admin exempt): ' . $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error)) {
    $bcfOpenBookingModal = true;
}

// Get facility recommendations - will be loaded via AJAX when user types in purpose field
$recommendations = [];

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

require_once __DIR__ . '/../../../../config/booking_calendar_status.php';

// Booking calendar shares year/month GET params with the My Reservations tab; supports legacy cal_month / cal_fac.
$bookCalYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$bookCalMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$legacyCalMonthStr = $_GET['cal_month'] ?? '';
if (is_string($legacyCalMonthStr) && preg_match('/^(\d{4})-(\d{2})$/', $legacyCalMonthStr, $cm)) {
    $bookCalYear = (int)$cm[1];
    $bookCalMonth = (int)$cm[2];
}
if ($bookCalMonth < 1 || $bookCalMonth > 12) {
    $bookCalMonth = (int)date('n');
}
if ($bookCalYear < 2000 || $bookCalYear > 2100) {
    $bookCalYear = (int)date('Y');
}
$bookFacilityPick = (int)($_GET['book_fac'] ?? $_GET['cal_fac'] ?? ($prefillFacilityId ?? 0));

$calendarToneMatrix = [];
if ($bookFacilityPick > 0) {
    $calendarToneMatrix = frs_facility_calendar_matrix($pdo, $bookFacilityPick, $bookCalYear, $bookCalMonth);
}
$bookCalFirstDay = sprintf('%04d-%02d-01', $bookCalYear, $bookCalMonth);
$bookCalAnchor = new DateTimeImmutable($bookCalFirstDay);
$bookCalNavPrev = $bookCalAnchor->modify('-1 month');
$bookCalNavNext = $bookCalAnchor->modify('+1 month');
$bookCalMonthLabel = $bookCalAnchor->format('F Y');
$bookCalMonthTs = mktime(0, 0, 0, $bookCalMonth, 1, $bookCalYear);
$bookFirstWeekday = (int)date('w', $bookCalMonthTs);
$bookDaysInMonth = (int)date('t', $bookCalMonthTs);
$todayISO = date('Y-m-d');
$bcfNowDt = new DateTime('now', frs_app_timezone());

$bookPaneQuery = ['year' => $bookCalYear, 'month' => $bookCalMonth];
if ($bookFacilityPick > 0) {
    $bookPaneQuery['book_fac'] = $bookFacilityPick;
}
$minePaneQuery = array_merge(['module' => 'mine'], $bookPaneQuery);
if (isset($_GET['scope']) && $_GET['scope'] === 'all' && in_array((string)($_SESSION['role'] ?? ''), ['Admin', 'Staff'], true)) {
    $minePaneQuery['scope'] = 'all';
}

$GLOBALS['frsMineCalOnHubBookFacility'] = strpos($_SERVER['REQUEST_URI'] ?? '', '/book-facility') !== false;

$frsScriptJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $frsScriptJsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$frsJsonForInlineScript = static function ($data) use ($frsScriptJsonFlags): string {
    $j = json_encode($data, $frsScriptJsonFlags);
    return ($j !== false) ? $j : '{}';
};

ob_start();

$bookCalQuery = static function (array $extra): string {
    return '?' . http_build_query($extra);
};
?>
<style>
.book-facility-compact .booking-card { padding: 0.95rem; }
.book-facility-compact .booking-form { display: grid; gap: 0.75rem; }
.book-facility-compact .booking-form label { margin-bottom: 0; }
.book-facility-compact .booking-form textarea { min-height: 74px; }
.book-facility-compact .booking-form small { margin-top: 0.15rem !important; }
.book-facility-compact #conflict-warning { margin-top: 0.45rem !important; }
/* Full-width rows: global .booking-wrapper uses 2 columns on desktop — these must span or we get an empty column */
.book-facility-compact.booking-wrapper {
    grid-template-columns: 1fr !important;
}
.book-facility-compact .booking-hub-tabs,
.book-facility-compact #booking-pane-reservations,
.book-facility-compact #booking-pane-book {
    grid-column: 1 / -1;
    width: 100%;
    max-width: 100%;
}
@media (min-width: 980px) {
    .book-facility-compact .booking-form { grid-template-columns: 1fr 1fr; column-gap: 0.85rem; }
    .book-facility-compact .booking-form > label,
    .book-facility-compact .booking-form > div,
    .book-facility-compact .booking-form > section { grid-column: 1 / -1; }
    .book-facility-compact .booking-form > label:nth-of-type(1),
    .book-facility-compact .booking-form > label:nth-of-type(2),
    .book-facility-compact .booking-form > label:nth-of-type(3),
    .book-facility-compact .booking-form > label:nth-of-type(4),
    .book-facility-compact .booking-form > label:nth-of-type(8),
    .book-facility-compact .booking-form > label:nth-of-type(9) { grid-column: span 1; }
}
.booking-hub-tabs { border-bottom: 2px solid #e8ecf4; margin-bottom: 1.25rem; }
.booking-hub-tab {
    display: inline-block;
    padding: 0.55rem 1rem;
    border-radius: 8px 8px 0 0;
    text-decoration: none;
    color: #4c5b7c;
    font-weight: 600;
    border: 1px solid transparent;
}
.booking-hub-tab.is-active {
    background: var(--bg-secondary, #fff);
    border-color: var(--border-color, #e8ecf4);
    border-bottom-color: var(--bg-secondary, #fff);
    color: var(--text-primary, #1e3a5f);
    margin-bottom: -2px;
}
.booking-hub-grid {
    display: grid;
    gap: 1rem;
    margin-bottom: 1rem;
    align-items: start;
}
@media (min-width: 1024px) {
    .booking-hub-grid { grid-template-columns: minmax(280px, 1fr) minmax(300px, 380px); }
}
.bcf-cal-panel .bcf-cal-grid-head {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0.25rem;
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 600;
    text-align: center;
    margin: 0.75rem 0 0.25rem;
}
.bcf-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0.35rem;
}
.bcf-cal-filler { min-height: 2.5rem; }
.bcf-cal-day {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    min-height: 2.75rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
}
.bcf-cal-day:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); }
.bcf-cal-day:disabled { cursor: not-allowed; opacity: 0.45; }
.bcf-cal-past { opacity: 0.45; cursor: default; }
.bcf-tone-green { background: #dcfce7; border-color: #86efac; color: #14532d; }
.bcf-tone-yellow { background: #fef9c3; border-color: #fde047; color: #713f12; }
.bcf-tone-red { background: #fee2e2; border-color: #fca5a5; color: #7f1d1d; }
.bcf-tone-blackout { background: #e2e8f0; border-color: #94a3b8; color: #1e293b; }
.bcf-tone-maintenance, .bcf-tone-offline { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
.bcf-tone-muted { background: #f1f5f9; border-color: #e2e8f0; color: #94a3b8; cursor: default; }
.bcf-sq { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 3px; margin-right: 0.25rem; vertical-align: middle; }
/* Viewport-fixed overlay — must stay under document.body because .dashboard-content uses transform animations,
   which would otherwise make fixed positioning relative to the whole tall content area */
.bcf-modal-overlay {
    position: fixed !important;
    inset: 0 !important;
    width: 100vw !important;
    max-width: 100vw;
    height: 100vh !important;
    height: 100dvh !important;
    min-height: 100vh !important;
    margin: 0 !important;
    box-sizing: border-box;
    background: rgba(10, 24, 55, 0.55);
    backdrop-filter: blur(6px);
    z-index: 12060;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    animation: bcfFade 0.2s ease;
    overscroll-behavior: contain;
}
.bcf-modal-overlay.is-open { display: flex !important; }
@keyframes bcfFade { from { opacity: 0; } to { opacity: 1; } }
.bcf-modal-dialog {
    background: var(--bg-secondary, #fff);
    border-radius: 16px;
    width: min(720px, 100%);
    max-height: min(88vh, 900px);
    max-height: min(88dvh, 900px);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.22);
    transform: scale(0.98);
    animation: bcfPop 0.22s ease forwards;
    margin: auto;
    flex-shrink: 1;
}
@keyframes bcfPop { to { transform: scale(1); } }
.bcf-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid #e8ecf4;
    flex-shrink: 0;
}
.bcf-modal-body {
    padding: 1rem 1.1rem 1.25rem;
    overflow: auto;
    -webkit-overflow-scrolling: touch;
}
.bcf-walkin-box {
    padding: 1rem;
    background: var(--info-bg, #eff6ff);
    border: 1px solid var(--info-border, #93c5fd);
    border-radius: 8px;
    margin-bottom: 1rem;
}
.bcf-walkin-title {
    margin: 0 0 0.75rem;
    color: var(--info-text, #1e40af);
    font-size: 1rem;
}
@media (max-width: 719px) {
    .bcf-modal-overlay { align-items: flex-end; padding: 0; justify-content: center; }
    .bcf-modal-dialog {
        width: 100%;
        max-height: 90vh;
        max-height: 90dvh;
        border-radius: 16px 16px 0 0;
    }
}
.bcf-res-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.65rem; }
.bcf-res-li {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 0.5rem 0.75rem;
    align-items: start;
    padding: 0.75rem;
    border: 1px solid #e8ecf4;
    border-radius: 10px;
    background: #f8fbff;
}
@media (max-width: 600px) {
    .bcf-res-li { grid-template-columns: 1fr; }
}
.hub-raw-status { text-transform: none; font-weight: 600; }
.bcf-cal-toolbar-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    width: 100%;
}
.bcf-cal-toolbar-form { width: 100%; margin: 0; }
.bcf-cal-month-heading {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--gov-blue-dark, #1e3a5f);
    letter-spacing: 0.02em;
}
.bcf-cal-toolbar-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: flex-end;
    gap: 0.65rem 0.85rem;
    width: 100%;
    max-width: 640px;
    margin-inline: auto;
}
.bcf-cal-fac-field { flex: 1 1 220px; min-width: min(280px, 100%); max-width: 340px; }
.bcf-cal-fac-field-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--gov-blue-dark, #1e3a5f);
    margin-bottom: 0.3rem;
    display: block;
}
.bcf-cal-fac-field .bcf-cal-shell {
    position: relative;
}
.bcf-cal-fac-field .bcf-cal-shell .bi {
    position: absolute;
    left: 0.95rem;
    top: 50%;
    transform: translateY(-50%);
    color: #8b95b5;
    pointer-events: none;
    font-size: 1rem;
    z-index: 1;
}
.bcf-cal-fac-select {
    width: 100%;
    padding: 0.72rem 0.95rem 0.72rem 2.75rem;
    border: 2px solid var(--border-color, #e1e6f3);
    border-radius: 10px;
    font-size: 0.95rem;
    font-family: inherit;
    font-weight: 500;
    color: var(--text-primary, var(--gov-blue-dark, #1e3a5f));
    background: var(--bg-tertiary, #fafbfd);
    min-height: 46px;
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    appearance: auto;
}
.bcf-cal-fac-select:hover { background: var(--bg-secondary, #fff); border-color: var(--border-color, #cdd6ea); }
.bcf-cal-fac-select:focus {
    outline: none;
    border-color: var(--primary-color, var(--gov-blue, #0047ab));
    background: var(--bg-secondary, #fff);
    box-shadow: 0 0 0 3px rgba(0, 71, 171, 0.1);
}
.bcf-cal-nav-cluster {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    justify-content: center;
    flex: 0 0 auto;
}
.bcf-cal-nav-cluster .bcf-cal-nav-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem 1rem;
    min-width: 5.65rem;
    font-size: 0.8125rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-align: center;
    white-space: nowrap;
    border-radius: 10px;
    border: 2px solid #dfe5f3;
    box-sizing: border-box;
    line-height: 1.25;
}
.bcf-cal-nav-cluster .bcf-cal-nav-btn:hover {
    border-color: var(--gov-blue, #0047ab);
    color: var(--gov-blue, #0047ab);
    background: #f8faff;
}
.input-wrapper.bcf-scroll-select-slot { position: relative; z-index: 0; }
.bcf-scroll-select-slot .bcf-time-select-native {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
    opacity: 0;
    pointer-events: none;
    white-space: nowrap;
}
.bcf-scroll-select-trigger {
    width: 100%;
    padding: 0.9rem 2.75rem;
    padding-right: 2.25rem;
    border: 2px solid #e1e6f3;
    border-radius: 8px;
    font-size: 1rem;
    font-family: inherit;
    font-weight: 500;
    color: var(--gov-blue-dark, #1e3a5f);
    background: #fafbfd;
    text-align: left;
    cursor: pointer;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    box-sizing: border-box;
}
.bcf-scroll-select-trigger:hover {
    background: var(--gov-white, #fff);
}
.bcf-scroll-select-trigger:focus {
    outline: none;
    border-color: var(--gov-blue, #0047ab);
    box-shadow: 0 0 0 3px rgba(0, 71, 171, 0.1);
    background: var(--gov-white, #fff);
}
.bcf-scroll-select-trigger[disabled] {
    opacity: 0.65;
    cursor: not-allowed;
}
.bcf-scroll-select-text {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.bcf-scroll-select-chevron {
    flex-shrink: 0;
    color: #64748b;
    font-size: 0.7rem;
    line-height: 1;
}
ul.bcf-scroll-select-menu {
    list-style: none;
    margin: 0;
    padding: 0.35rem;
    border-radius: 10px;
    border: 2px solid #e1e6f3;
    background: #fff;
    box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18);
    max-height: min(42vh, 260px);
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    z-index: 13070;
}
.bcf-scroll-select-option {
    padding: 0.52rem 0.72rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.95rem;
}
.bcf-scroll-select-option:hover,
.bcf-scroll-select-option[data-active="1"] {
    background: #eef4ff;
    color: var(--gov-blue-dark, #1e3a5f);
}
.bcf-scroll-select-option.bcf-opt-disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.booking-form .input-wrapper.bcf-res-date-wrapper .bcf-res-date-readonly {
    padding-left: 2.75rem;
    width: 100%;
}
.bcf-res-date-readonly {
    padding: 0.72rem 0.95rem;
    border: 2px dashed #cdd6ea;
    border-radius: 8px;
    background: #f4f7fc;
    font-weight: 600;
    color: var(--gov-blue-dark, #1e3a5f);
    min-height: 44px;
    display: flex;
    align-items: center;
    gap: 0.65rem;
    box-sizing: border-box;
}
.bcf-res-date-readonly-empty {
    color: #94a3b8;
    font-weight: 500;
    font-style: italic;
}
.bcf-purpose-first-card {
    border: 1px solid var(--border-color, #e1e6f3);
    border-radius: 10px;
    padding: 0.85rem 1rem;
    margin-bottom: 1rem;
    background: var(--bg-secondary, #fafbff);
}
.bcf-purpose-first-card textarea {
    width: 100%;
    min-height: 72px;
    margin-top: 0.35rem;
    padding: 0.55rem 0.65rem;
    border-radius: 8px;
    border: 2px solid var(--border-color, #dbe3f5);
    background: var(--bg-tertiary, #fff);
    color: var(--text-primary, inherit);
    font-size: 0.92rem;
    box-sizing: border-box;
}
.bcf-purpose-first-card input {
    background: var(--bg-tertiary, #fff);
    color: var(--text-primary, inherit);
    border-color: var(--border-color, #dbe3f5);
}
.bcf-purpose-first-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: flex-end;
    margin-top: 0.5rem;
}
.bcf-purpose-first-row label {
    font-size: 0.82rem;
    color: var(--text-secondary, #475569);
}
.bcf-smart-hints-bar {
    margin-top: 0.75rem;
    padding: 0.65rem 0.75rem;
    border-radius: 8px;
    background: var(--accent-ai-bg, #f5f3ff);
    border: 1px solid var(--accent-ai-border, #ddd6fe);
    font-size: 0.84rem;
    color: var(--accent-ai-text, #4c1d95);
    line-height: 1.45;
    display: none;
}
.bcf-smart-hints-bar.is-visible { display: block; }
.bcf-smart-hints-actions { margin-top: 0.5rem; }
.bcf-smart-hints-link {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    font-size: 0.82rem;
    text-decoration: none;
    border-radius: 8px;
    border: 1px solid var(--accent-ai-border, #7c3aed);
    color: var(--accent-ai-text, #5b21b6);
    font-weight: 600;
}
.my-reservations-calendar-cell.bcf-ai-suggest-date:not(.empty) {
    box-shadow: inset 0 0 0 2px #7c3aed;
    background: linear-gradient(135deg, rgba(124, 58, 237, 0.07), rgba(255, 255, 255, 0));
}
.my-reservations-calendar-cell.bcf-ai-suggest-date:not(.empty) .date-label {
    font-weight: 800;
    color: #5b21b6;
}
</style>
<div class="page-header">
    <div class="breadcrumb">
        <span>Reservations</span><span class="sep">/</span><span><?= $reservationsHubMine ? 'My Reservations' : 'Book a Facility'; ?></span>
    </div>
    <?php if ($reservationsHubMine): ?>
        <?= frs_page_title('My Reservations', 'Upcoming and past bookings: status, reschedule, payments, and permit downloads.'); ?>
    <?php else: ?>
        <?= frs_page_title('Book a Facility', 'Search facilities, pick date and time, attach event permits if required. Staff can book for a resident.'); ?>
    <?php endif; ?>
</div>

<?php if (!empty($message)): ?>
    <div class="message <?= $messageType === 'error' ? 'error' : 'success'; ?>" style="background:<?= $messageType === 'error' ? '#fdecee;color:#b23030' : '#e8f5e9;color:#2e7d32'; ?>;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="message success" style="background:#e3f8ef;color:#0d7a43;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($success); ?>
    </div>
<?php elseif ($error): ?>
    <div class="message error" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper book-facility-compact">
    <nav class="booking-hub-tabs" aria-label="Booking sections">
        <a class="booking-hub-tab <?= !$reservationsHubMine ? 'is-active' : ''; ?>" href="<?= htmlspecialchars(base_path() . '/dashboard/book-facility' . $bookCalQuery($bookPaneQuery)); ?>">Book a Facility</a>
        <a class="booking-hub-tab <?= $reservationsHubMine ? 'is-active' : ''; ?>" href="<?= htmlspecialchars(base_path() . '/dashboard/book-facility' . $bookCalQuery($minePaneQuery)); ?>">My Reservations</a>
    </nav>

    <div id="booking-pane-reservations" style="display: <?= $reservationsHubMine ? 'block' : 'none'; ?>;">
        <?php require __DIR__ . '/includes/reservations_hub_mine_tab.php'; ?>
    </div>

    <div id="booking-pane-book" style="display: <?= !$reservationsHubMine ? 'block' : 'none'; ?>;">
        <div class="booking-hub-grid">
            <div class="booking-card booking-calendar-myres-panel">
                <h2 class="bcf-label-row" style="margin-top:0;">
                    Book a facility
                    <?= frs_field_tip('Describe your event first to see AI-assisted picks: suggested venues (by distance and fit) and calendar days with availability for the top match. Then choose a facility and date as usual.'); ?>
                </h2>
                <div class="bcf-purpose-first-card" id="bcf-purpose-first-card">
                    <label for="bcf-purpose-preview" class="bcf-label-row" style="font-weight:600; font-size:0.9rem; color:var(--text-primary, #1e293b);">
                        Purpose of reservation
                        <?= frs_field_tip('This stays in sync with the booking form. Purple-ring days on the calendar = open or partially booked days for the best-matching facility this month.'); ?>
                    </label>
                    <textarea id="bcf-purpose-preview" name="bcf_purpose_preview" maxlength="2000" placeholder="e.g., Youth basketball practice, barangay assembly, zumba class…"></textarea>
                    <div class="bcf-purpose-first-row">
                        <label>Expected attendees (optional)
                            <input type="number" id="bcf-purpose-attendees-preview" min="1" max="50000" placeholder="50" style="display:block; width:7rem; margin-top:0.25rem; padding:0.45rem 0.5rem; border-radius:8px; border:2px solid #dbe3f5;">
                        </label>
                    </div>
                    <div id="bcf-smart-hints-bar" class="bcf-smart-hints-bar" role="status" aria-live="polite"></div>
                </div>
                <?php
                    $bookCalNavPrevParams = array_merge($bookPaneQuery, [
                        'year' => (int)$bookCalNavPrev->format('Y'),
                        'month' => (int)$bookCalNavPrev->format('n'),
                    ]);
                    $bookCalNavNextParams = array_merge($bookPaneQuery, [
                        'year' => (int)$bookCalNavNext->format('Y'),
                        'month' => (int)$bookCalNavNext->format('n'),
                    ]);
                    ?>
                <div class="bcf-cal-toolbar-wrap">
                    <div class="bcf-cal-month-heading"><?= htmlspecialchars($bookCalMonthLabel); ?></div>
                    <form method="get" action="<?= htmlspecialchars(base_path() . '/dashboard/book-facility'); ?>" class="bcf-cal-toolbar-form booking-cal-toolbar">
                        <input type="hidden" name="year" value="<?= (int)$bookCalYear; ?>">
                        <input type="hidden" name="month" value="<?= (int)$bookCalMonth; ?>">
                        <div class="bcf-cal-toolbar-grid">
                            <div class="bcf-cal-fac-field">
                                <label class="bcf-cal-fac-field-label" for="book-fac-cal-select">Facility</label>
                                <div class="bcf-cal-shell">
                                    <i class="bi bi-building" aria-hidden="true"></i>
                                    <select id="book-fac-cal-select" name="book_fac" class="bcf-cal-fac-select" onchange="this.form.submit()" aria-label="Choose facility for calendar">
                                        <option value="0">Choose a facility…</option>
                                        <?php foreach ($facilities as $f): ?>
                                            <option value="<?= (int)$f['id']; ?>" <?= $bookFacilityPick === (int)$f['id'] ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars((string)$f['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="bcf-cal-nav-cluster">
                                <a class="btn-outline bcf-cal-nav-btn" href="<?= htmlspecialchars(base_path() . '/dashboard/book-facility' . $bookCalQuery($bookCalNavPrevParams)); ?>">← Prev</a>
                                <a class="btn-outline bcf-cal-nav-btn" href="<?= htmlspecialchars(base_path() . '/dashboard/book-facility' . $bookCalQuery($bookCalNavNextParams)); ?>">Next →</a>
                                <a class="btn-outline bcf-cal-nav-btn" href="<?= htmlspecialchars(base_path() . '/dashboard/book-facility' . $bookCalQuery(array_merge($bookPaneQuery, ['year' => (int)date('Y'), 'month' => (int)date('n')]))); ?>">Today</a>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="my-reservations-calendar" style="min-height:auto;">
                    <div class="my-reservations-calendar-header" style="margin-bottom:0.65rem;">
                        <div class="my-reservations-legend">
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#22c55e;"></span> Open</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#eab308;"></span> Partial bookings</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#ef4444;"></span> Fully booked</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#94a3b8;"></span> Blocked</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="border:2px solid #7c3aed; background:transparent;"></span> AI-suggested day</div>
                        </div>
                    </div>
                    <div class="my-reservations-calendar-grid">
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $w): ?>
                            <div class="my-reservations-calendar-dayname"><?= $w; ?></div>
                        <?php endforeach; ?>
                        <?php for ($jx = 0; $jx < $bookFirstWeekday; $jx++): ?>
                            <div class="my-reservations-calendar-cell empty"></div>
                        <?php endfor; ?>
                        <?php for ($bd = 1; $bd <= $bookDaysInMonth; $bd++):
                            $iso = sprintf('%04d-%02d-%02d', $bookCalYear, $bookCalMonth, $bd);
                            if (!$bookFacilityPick) {
                                $tone = ($iso < $todayISO) ? 'past' : 'muted';
                            } else {
                                $tone = $calendarToneMatrix[$iso] ?? 'green';
                            }
                            $dayStatusClass = '';
                            $chipLabel = '';
                            if ($iso < $todayISO) {
                                $chipLabel = '';
                            } elseif (!$bookFacilityPick) {
                                $chipLabel = '—';
                            } else {
                                if ($tone === 'green') {
                                    $dayStatusClass = ' status-approved';
                                    $chipLabel = 'Open';
                                } elseif ($tone === 'yellow') {
                                    $dayStatusClass = ' status-pending';
                                    $chipLabel = 'Busy';
                                } elseif ($tone === 'red') {
                                    $dayStatusClass = ' status-denied';
                                    $chipLabel = 'Full';
                                } elseif ($tone === 'blackout' || $tone === 'maintenance' || $tone === 'offline') {
                                    $dayStatusClass = ' status-denied';
                                    $chipLabel = ($tone === 'blackout') ? 'Blackout' : 'N/A';
                                } elseif ($tone === 'muted') {
                                    $chipLabel = '—';
                                } elseif ($tone === 'past') {
                                    $chipLabel = '';
                                } else {
                                    $chipLabel = '';
                                }
                            }
                            $bookPickable = ($iso >= $todayISO) && $bookFacilityPick > 0 && in_array($tone, ['green', 'yellow', 'red'], true);
                            $cellCls = 'my-reservations-calendar-cell';
                            if ($iso === $todayISO) {
                                $cellCls .= ' today';
                            }
                            if (!$bookPickable) {
                                $cellCls .= ' empty';
                            }
                            $cellCls .= $dayStatusClass;
                            if ($bookPickable) {
                                $cellCls .= ' bcf-book-cal-cell';
                            }
                            ?>
                            <div class="<?= htmlspecialchars($cellCls, ENT_QUOTES, 'UTF-8'); ?>" data-cal-date="<?= htmlspecialchars($iso, ENT_QUOTES, 'UTF-8'); ?>"<?= $bookPickable ? ' role="button" tabindex="0" data-bcf-date="' . htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                                <div class="date-label"><?= (int)$bd; ?></div>
                                <?php if ($chipLabel !== ''): ?>
                                    <div class="status-chip"><?= htmlspecialchars($chipLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="bcf-label-row" style="margin-top:1rem; gap:0.5rem;">
                    <button type="button" class="btn-primary" id="bcf-open-booking-explicit">Open booking form</button>
                    <?= frs_field_tip('Tap a coloured future day on the calendar, or use this button if you already know your date.'); ?>
                </div>
            </div>

            <aside class="booking-card bcf-aside-col">
                <div id="facility-details-container" style="display: none;">
                    <h2 id="facility-details-title">Facility Details</h2>
                    <div id="facility-details-content">
                        <div style="text-align: center; padding: 2rem; color: #8b95b5;">
                            <p>Select a facility from the calendar dropdown to view details.</p>
                        </div>
                    </div>
                </div>
                <div id="facility-placeholder" style="display: block;">
                    <h2>Facility Details</h2>
                    <div style="text-align: center; padding: 2rem; color: #8b95b5;">
                        <p>Select a facility from the calendar dropdown to view details.</p>
                    </div>
                </div>
            </aside>
        </div>

        <div id="booking-flow-modal" class="bcf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bcf-modal-title" aria-hidden="true">
            <div class="bcf-modal-dialog" role="document">
                <div class="bcf-modal-head">
                    <h2 id="bcf-modal-title" style="margin:0; font-size:1.05rem;">New reservation</h2>
                    <button type="button" class="btn-outline" id="bcf-close-booking-modal" aria-label="Close">&times;</button>
                </div>
                <div class="bcf-modal-body">
                    <section class="booking-card" style="box-shadow:none;border:none;padding:0;background:transparent;margin:0;">
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
        <form id="main-booking-form" class="booking-form" method="POST" enctype="multipart/form-data">
            <?= csrf_field(); ?>
            <?php if ($canBookOnBehalf && !empty($walkInResidents)): ?>
            <div class="bcf-walkin-box">
                <h4 class="bcf-label-row bcf-walkin-title">
                    Walk-in / assisted booking
                    <?= frs_field_tip('Create a reservation on behalf of a resident (barangay counter service).'); ?>
                </h4>
                <label>
                    Resident
                    <select name="book_for_user_id" id="book-for-user-id" style="width:100%; padding:0.55rem; border-radius:8px; border:2px solid #dbe3f5;">
                        <option value="">— Book for myself (staff/admin) —</option>
                        <?php foreach ($walkInResidents as $wr): ?>
                            <option value="<?= (int)$wr['id']; ?>" <?= $selectedBookForUserId === (int)$wr['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($wr['name']); ?> (<?= htmlspecialchars($wr['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php endif; ?>
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
                </div>
            </label>


            <label>
                <span class="bcf-label-row">Reservation Date <?= frs_field_tip('Chosen from the month grid on the booking page (not editable here).'); ?></span>
                <input type="hidden" name="reservation_date" id="reservation-date" value="">
                <div class="input-wrapper bcf-res-date-wrapper">
                    <i class="bi bi-calendar input-icon"></i>
                    <div id="bcf-reservation-date-display" class="bcf-res-date-readonly bcf-res-date-readonly-empty" role="status" aria-live="polite">Select a date on the calendar.</div>
                </div>
            </label>

            <label>
                Start Time
                <div class="input-wrapper bcf-scroll-select-slot">
                    <i class="bi bi-clock input-icon"></i>
                    <button type="button" id="bcf-start-time-trigger" class="bcf-scroll-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="bcf-start-time-menu">
                        <span class="bcf-scroll-select-text">Select start time…</span>
                        <span class="bcf-scroll-select-chevron">▾</span>
                    </button>
                    <select name="start_time" id="start-time" class="bcf-time-select-native" required tabindex="-1" aria-hidden="true">
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
            </label>

            <label>
                End Time
                <div class="input-wrapper bcf-scroll-select-slot">
                    <i class="bi bi-clock input-icon"></i>
                    <button type="button" id="bcf-end-time-trigger" class="bcf-scroll-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="bcf-end-time-menu">
                        <span class="bcf-scroll-select-text">Select end time…</span>
                        <span class="bcf-scroll-select-chevron">▾</span>
                    </button>
                    <select name="end_time" id="end-time" class="bcf-time-select-native" required tabindex="-1" aria-hidden="true">
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
            </label>

            <label>
                <span class="bcf-label-row">Quick slot (from gaps on this day) <?= frs_field_tip('Populated from availability for the chosen date; pick a range, then fine-tune start/end below if needed.'); ?></span>
                <div class="input-wrapper">
                    <i class="bi bi-clock input-icon"></i>
                    <select id="bcf-slot-from-availability">
                        <option value="">Load by choosing facility + date…</option>
                    </select>
                </div>
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
                <span id="conflict-hint-wrap" style="display:none;"><?= frs_field_tip('Risk factors may include holidays/events, pending requests, and historical demand.'); ?></span>
            </div>

            <label>
                Purpose of Use
                <textarea name="purpose" id="purpose-input" rows="3" placeholder="e.g., Zumba class, Barangay General Assembly, Sports tournament" required></textarea>
            </label>

            <label>
                Expected Number of Attendees <span style="color:#dc3545;">*</span>
                <div class="input-wrapper">
                    <i class="bi bi-people input-icon"></i>
                    <input type="number" name="expected_attendees" id="expected-attendees" min="1" required inputmode="numeric" placeholder="e.g., 50">
                </div>
                <p id="bcf-capacity-msg" style="display:none; color:#b23030; margin:0.35rem 0 0; font-size:0.85rem;"></p>
            </label>

            <div id="ai-recommendations" style="display:none; background:#e3f8ef; border:1px solid #0d7a43; border-radius:8px; padding:1rem; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#0d7a43; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>🤖</span> AI Recommendations
                </h4>
                <p id="recommendations-intro" style="margin:0 0 0.5rem; color:#0d7a43; font-size:0.85rem;">Enter your purpose, choose a date on the calendar, and start/end times. Suggestions use 50 attendees until you enter a number.</p>
                <p id="recommendations-best-times" style="margin:0 0 0.75rem; color:#0d7a43; font-size:0.82rem; font-weight:600; display:none;"></p>
                <div id="recommendations-loading" style="display:none; color:#0d7a43; font-size:0.85rem; font-style:italic;">Loading recommendations...</div>
                <div id="recommendations-list" style="color:#0d7a43; font-size:0.85rem;"></div>
            </div>

            <label>
                Notes for staff (optional, appended to purpose on save)
                <textarea name="booking_notes" id="booking-notes" rows="2" maxlength="1200" placeholder="Parking, setup time, accessibility, etc."></textarea>
            </label>

            <div class="frs-notice-panel frs-notice-muted">
                <h4 class="bcf-label-row" style="margin:0 0 0.75rem; font-size:0.95rem; color:var(--text-primary, inherit);">
                    Supporting document (optional)
                    <?= frs_field_tip('Event permit, barangay resolution, or letter of request — recommended for large events.'); ?>
                </h4>
                <label style="display:block; margin-bottom:0.5rem;">
                    Document type
                    <select name="event_document_type" style="width:100%; padding:0.5rem; border-radius:8px; margin-top:0.25rem;">
                        <option value="event_permit">Event / activity permit</option>
                        <option value="barangay_resolution">Barangay resolution</option>
                        <option value="letter_request">Letter of request</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>
                    Upload file (PDF or image, max 8MB)
                    <input type="file" name="event_supporting_doc" accept=".pdf,image/*" style="margin-top:0.35rem; width:100%;">
                </label>
            </div>

            <?php if (!$isVerified && !$hasValidIdDocument && !($canBookOnBehalf && $selectedBookForUserId > 0)): ?>
            <div style="padding:1rem; background:#fff4e5; border:2px solid #ffc107; border-radius:8px; margin-top:1rem;">
                <h4 class="bcf-label-row" style="margin:0 0 0.75rem; color:#856404; font-size:1rem;">
                    ⚠️ Valid ID Required
                    <?= frs_field_tip('Your account is not yet verified. Upload a valid government-issued ID to proceed. Accepted: PDF, JPG, PNG. Max 5MB (Birth Certificate, Barangay ID, Resident ID, Driver\'s License, etc.).'); ?>
                </h4>
                <label>
                    Upload Valid ID (Required for unverified accounts)
                    <input type="file" name="doc_valid_id" accept=".pdf,image/*" required style="margin-top:0.5rem; padding:0.75rem; border:1px solid #ddd; border-radius:6px; width:100%;">
                </label>
            </div>
            <?php elseif (!$isVerified && $hasValidIdDocument): ?>
            <div style="padding:1rem; background:#e7f3ff; border:2px solid #2196F3; border-radius:8px; margin-top:1rem;">
                <h4 style="margin:0 0 0.5rem; color:#1976D2; font-size:1rem;">📋 Valid ID Submitted</h4>
                <p style="margin:0; color:#1976D2; font-size:0.9rem; line-height:1.5;">
                    Your valid ID document has been submitted and is awaiting admin verification. Once verified, you'll be able to use auto-approval features.
                </p>
            </div>
            <?php endif; ?>

            <button class="btn-primary" type="submit" id="bcf-submit-booking">Submit Booking Request</button>
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
                    </section>
                </div>
            </div>
        </div>

    </div><!-- end booking-pane-book -->
</div><!-- end booking-wrapper -->

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

    function frsFieldTipHtml(text) {
        const safe = escapeHtml(String(text));
        return '<span class="frs-tip"><button type="button" class="frs-tip-btn" data-frs-tip="' + safe + '" aria-describedby="frs-tip-float" aria-label="More information">i</button></span>';
    }

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

    const eventMap = <?= $frsJsonForInlineScript($eventMap); ?>;
    const basePath = <?= $frsJsonForInlineScript((string)$basePath); ?>;

    /** POST with session cookie + CSRF (works even if main.js is cached/old). */
    function bcfFetchPost(url, params) {
        const body = params instanceof URLSearchParams ? params : new URLSearchParams(params || {});
        if (window.CSRF_TOKEN_NAME && window.CSRF_TOKEN) {
            body.set(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);
        }
        const headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
        if (window.CSRF_TOKEN) {
            headers['X-CSRF-Token'] = window.CSRF_TOKEN;
        }
        return fetch(url, {
            method: 'POST',
            headers: headers,
            body: body.toString(),
            credentials: 'same-origin'
        });
    }

    const BCF_OPEN_ON_LOAD = <?= !empty($bcfOpenBookingModal) ? 'true' : 'false'; ?>;
    const bookCalFacilityId = <?= (int)$bookFacilityPick; ?>;
    const BCF_CAL_YEAR = <?= (int)$bookCalYear; ?>;
    const BCF_CAL_MONTH = <?= (int)$bookCalMonth; ?>;
    const BCF_TODAY_ISO = <?= $frsJsonForInlineScript($todayISO); ?>;
    const BCF_EARLIEST_START_MIN = <?= (int)frs_earliest_bookable_start_minutes($bcfNowDt); ?>;
    window._bcfMaxFacilityCapacity = null;
    window._bcfLastConflictHard = false;

    function bcfHintEscape(t) {
        if (t === null || t === undefined) return '';
        const d = document.createElement('div');
        d.textContent = String(t);
        return d.innerHTML;
    }

    let bcfSmartHintsTimer = null;
    function bcfClearCalendarAiHints() {
        document.querySelectorAll('.bcf-ai-suggest-date').forEach(function (el) {
            el.classList.remove('bcf-ai-suggest-date');
        });
        const bar = document.getElementById('bcf-smart-hints-bar');
        if (bar) {
            bar.classList.remove('is-visible');
            bar.innerHTML = '';
        }
    }

    function bcfApplyCalendarAiHints(payload) {
        bcfClearCalendarAiHints();
        const bar = document.getElementById('bcf-smart-hints-bar');
        const dates = payload && payload.highlight_dates ? payload.highlight_dates : [];
        const dateSet = new Set(dates);
        document.querySelectorAll('[data-cal-date]').forEach(function (cell) {
            const d = cell.getAttribute('data-cal-date');
            if (d && dateSet.has(d)) {
                cell.classList.add('bcf-ai-suggest-date');
            }
        });
        if (!bar) return;
        if (!payload || !payload.facilities || payload.facilities.length === 0) {
            return;
        }
        const top = payload.facilities[0];
        let html = '<strong>Top match:</strong> ' + bcfHintEscape(top.name);
        if (top.distance) {
            html += ' <span style="opacity:.88">(' + bcfHintEscape(top.distance) + ')</span>';
        }
        if (dates.length) {
            html += '. Purple rings show days with open or partial availability for that venue.';
        } else {
            html += '. No open/partial days left this month for that venue — try another month or facility.';
        }
        if (payload.best_times_label) {
            html += '<br><span style="font-size:0.82rem;opacity:.92">' + bcfHintEscape(payload.best_times_label) + '</span>';
        }
        if (payload.primary_facility_id) {
            const u = basePath + '/dashboard/book-facility?year=' + encodeURIComponent(String(BCF_CAL_YEAR)) + '&month=' + encodeURIComponent(String(BCF_CAL_MONTH)) + '&book_fac=' + encodeURIComponent(String(payload.primary_facility_id));
            html += '<div class="bcf-smart-hints-actions"><a class="btn-outline bcf-smart-hints-link" href="' + u + '">Show this facility on the calendar</a></div>';
        }
        bar.innerHTML = html;
        bar.classList.add('is-visible');
    }

    async function bcfFetchBookingSmartHints() {
        const ta = document.getElementById('bcf-purpose-preview');
        const purpose = ta ? (ta.value || '').trim() : '';
        if (purpose.length < 3) {
            bcfClearCalendarAiHints();
            return;
        }
        const attEl = document.getElementById('bcf-purpose-attendees-preview');
        let fd = new URLSearchParams();
        fd.append('purpose', purpose);
        fd.append('year', String(BCF_CAL_YEAR));
        fd.append('month', String(BCF_CAL_MONTH));
        const attV = attEl ? attEl.value.trim() : '';
        if (attV !== '') fd.append('expected_attendees', attV);
        try {
            const res = await bcfFetchPost(basePath + '/dashboard/booking-smart-hints', fd);
            const raw = await res.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                console.error('booking-smart-hints: invalid JSON', raw.slice(0, 400));
                return;
            }
            if (data.error && (!data.facilities || data.facilities.length === 0)) {
                bcfClearCalendarAiHints();
                return;
            }
            bcfApplyCalendarAiHints(data);
        } catch (e) {
            console.error('booking-smart-hints', e);
        }
    }

    function bcfDebouncedSmartHints() {
        if (bcfSmartHintsTimer) clearTimeout(bcfSmartHintsTimer);
        bcfSmartHintsTimer = setTimeout(bcfFetchBookingSmartHints, 550);
    }

    const bcfPurposePreview = document.getElementById('bcf-purpose-preview');
    const bcfPurposeAttPrev = document.getElementById('bcf-purpose-attendees-preview');
    if (bcfPurposePreview) {
        try {
            const saved = sessionStorage.getItem('bcf_booking_purpose');
            if (saved && !bcfPurposePreview.value) {
                bcfPurposePreview.value = saved;
                const pi0 = document.getElementById('purpose-input');
                if (pi0 && !pi0.value) pi0.value = saved;
            }
        } catch (e) { /* ignore */ }
        bcfPurposePreview.addEventListener('input', function () {
            try { sessionStorage.setItem('bcf_booking_purpose', this.value); } catch (e) { /* ignore */ }
            const pi = document.getElementById('purpose-input');
            if (pi) pi.value = this.value;
            bcfDebouncedSmartHints();
        });
    }
    if (bcfPurposeAttPrev) {
        bcfPurposeAttPrev.addEventListener('input', bcfDebouncedSmartHints);
        bcfPurposeAttPrev.addEventListener('change', function () { bcfFetchBookingSmartHints(); });
    }

    function refreshBookingGates() {
        const btn = document.getElementById('bcf-submit-booking');
        const capMsg = document.getElementById('bcf-capacity-msg');
        if (!btn) return;
        const maxC = window._bcfMaxFacilityCapacity;
        const attendeeInputEl = document.getElementById('expected-attendees');
        const attTrim = attendeeInputEl && attendeeInputEl.value ? attendeeInputEl.value.trim() : '';
        let attendeeMissing = (attTrim === '');
        let n = attTrim !== '' ? parseInt(attTrim, 10) : NaN;
        if (!attendeeMissing && (isNaN(n) || n < 1)) attendeeMissing = true;
        let capErr = false;
        if (!attendeeMissing && maxC != null && !isNaN(n) && n > maxC) {
            capErr = true;
        }
        if (capErr && capMsg) {
            capMsg.style.display = 'block';
            capMsg.textContent = 'Maximum occupancy for this facility is ' + maxC + '. Enter a number equal to or below that.';
        } else if (attendeeMissing && capMsg) {
            capMsg.style.display = 'block';
            capMsg.textContent = 'Expected number of attendees is required.';
        } else if (capMsg) {
            capMsg.style.display = 'none';
            capMsg.textContent = '';
        }
        btn.disabled = !!(window._bcfLastConflictHard || capErr || attendeeMissing);
    }

    function updateBcfReservationDateReadout() {
        const disp = document.getElementById('bcf-reservation-date-display');
        if (!disp || !dateInput) return;
        const raw = (dateInput.value || '').trim();
        disp.classList.toggle('bcf-res-date-readonly-empty', !raw);
        if (!raw) {
            disp.textContent = 'Select a date on the calendar.';
            return;
        }
        const parsed = new Date(raw + 'T12:00:00');
        if (!isNaN(parsed.getTime())) {
            disp.textContent = parsed.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        } else {
            disp.textContent = raw;
        }
    }

    let bcfActiveTimeMenu = null;

    function bcfEnsureTimeMenus() {
        if (document.getElementById('bcf-start-time-menu')) return;
        ['bcf-start-time-menu', 'bcf-end-time-menu'].forEach(function (menuId) {
            const ul = document.createElement('ul');
            ul.id = menuId;
            ul.className = 'bcf-scroll-select-menu';
            ul.setAttribute('role', 'listbox');
            ul.hidden = true;
            ul.style.display = 'none';
            ul.dataset.open = '';
            document.body.appendChild(ul);
        });
    }

    function bcfGetScrollSelectElements(nativeSel) {
        const triggerId = nativeSel.id === 'start-time' ? 'bcf-start-time-trigger' : 'bcf-end-time-trigger';
        const menuId = nativeSel.id === 'start-time' ? 'bcf-start-time-menu' : 'bcf-end-time-menu';
        const trigger = document.getElementById(triggerId);
        const menu = document.getElementById(menuId);
        const txt = trigger ? trigger.querySelector('.bcf-scroll-select-text') : null;
        return { trigger, menu, txt };
    }

    function bcfCloseTimeMenus() {
        ['bcf-start-time-menu', 'bcf-end-time-menu'].forEach(function (mid) {
            const menu = document.getElementById(mid);
            const tid = mid === 'bcf-start-time-menu' ? 'bcf-start-time-trigger' : 'bcf-end-time-trigger';
            const trigger = document.getElementById(tid);
            if (menu) {
                menu.hidden = true;
                menu.style.display = 'none';
                menu.dataset.open = '';
            }
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
        bcfActiveTimeMenu = null;
    }

    function bcfPlaceTimeMenu(trigger, menu) {
        const r = trigger.getBoundingClientRect();
        const pad = 4;
        const maxH = Math.min(window.innerHeight * 0.42, 260);
        const top = Math.min(r.bottom + pad, window.innerHeight - maxH - 12);
        menu.style.position = 'fixed';
        menu.style.left = Math.max(8, r.left) + 'px';
        menu.style.top = Math.max(8, top) + 'px';
        menu.style.width = Math.min(r.width, window.innerWidth - 16) + 'px';
        menu.style.maxHeight = maxH + 'px';
    }

    function bcfRebuildTimeMenus() {
        bcfEnsureTimeMenus();
        [startTimeInput, endTimeInput].forEach(function (nativeSel) {
            if (!nativeSel) return;
            const { trigger, menu, txt } = bcfGetScrollSelectElements(nativeSel);
            if (!trigger || !menu) return;
            menu.innerHTML = '';
            Array.from(nativeSel.options).forEach(function (opt, idx) {
                if (idx === 0 && opt.value === '') return;
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.dataset.value = opt.value;
                li.textContent = (opt.text || '').trim() || opt.value;
                li.className = opt.disabled ? 'bcf-scroll-select-option bcf-opt-disabled' : 'bcf-scroll-select-option';
                if (opt.disabled) li.setAttribute('aria-disabled', 'true');
                menu.appendChild(li);
            });
            const si = nativeSel.selectedIndex;
            const selOpt = si >= 0 ? nativeSel.options[si] : null;
            if (txt) {
                if (selOpt && selOpt.value) {
                    txt.textContent = (selOpt.text || '').trim();
                } else if (nativeSel.id === 'start-time') {
                    txt.textContent = 'Select start time…';
                } else {
                    txt.textContent = 'Select end time…';
                }
            }
        });
    }

    let bcfTimeMenusWired = false;
    function bcfAttachTimeMenusOnce() {
        if (bcfTimeMenusWired || !startTimeInput || !endTimeInput) return;
        const stTrig = document.getElementById('bcf-start-time-trigger');
        const enTrig = document.getElementById('bcf-end-time-trigger');
        if (!stTrig || !enTrig) return;
        bcfEnsureTimeMenus();
        bcfTimeMenusWired = true;

        function openNativeMenu(nativeSel, trig) {
            const { menu } = bcfGetScrollSelectElements(nativeSel);
            if (!menu || !trig) return;
            if (bcfActiveTimeMenu && bcfActiveTimeMenu.trigger === trig) {
                bcfCloseTimeMenus();
                return;
            }
            bcfCloseTimeMenus();
            bcfRebuildTimeMenus();
            if (menu.querySelectorAll('li').length === 0) {
                return;
            }
            menu.hidden = false;
            menu.style.display = 'block';
            trig.setAttribute('aria-expanded', 'true');
            bcfPlaceTimeMenu(trig, menu);
            bcfActiveTimeMenu = { menu: menu, trigger: trig, native: nativeSel };
        }

        stTrig.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            openNativeMenu(startTimeInput, stTrig);
        });
        enTrig.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            openNativeMenu(endTimeInput, enTrig);
        });

        ['bcf-start-time-menu', 'bcf-end-time-menu'].forEach(function (menuId) {
            const menu = document.getElementById(menuId);
            if (!menu) return;
            menu.addEventListener('click', function (ev) {
                const li = ev.target.closest('li');
                if (!li || li.classList.contains('bcf-opt-disabled')) return;
                const val = li.dataset.value;
                const sel = menuId === 'bcf-start-time-menu' ? startTimeInput : endTimeInput;
                if (!sel || val === undefined) return;
                sel.value = String(val);
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                bcfRebuildTimeMenus();
                bcfCloseTimeMenus();
            });
        });

        document.addEventListener('mousedown', function (down) {
            if (!bcfActiveTimeMenu) return;
            const t = down.target;
            if (bcfActiveTimeMenu.menu.contains(t) || bcfActiveTimeMenu.trigger.contains(t)) return;
            bcfCloseTimeMenus();
        });
        window.addEventListener('resize', function () { bcfCloseTimeMenus(); }, { passive: true });
        window.addEventListener('scroll', function (ev) {
            if (!bcfActiveTimeMenu) return;
            const menu = bcfActiveTimeMenu.menu;
            const t = ev.target;
            if (t === menu || (t && typeof Node !== 'undefined' && t instanceof Node && menu.contains(t))) {
                return;
            }
            bcfCloseTimeMenus();
        }, true);
    }

    const bookingFlowModal = document.getElementById('booking-flow-modal');
    /* Reparent so position:fixed is viewport-relative (.dashboard-content uses CSS animation with transform). */
    if (bookingFlowModal && bookingFlowModal.parentNode !== document.body) {
        document.body.appendChild(bookingFlowModal);
    }
    bcfAttachTimeMenusOnce();
    bcfRebuildTimeMenus();

    function openBookingFlowModal() {
        if (!bookingFlowModal) return;
        bookingFlowModal.classList.add('is-open');
        bookingFlowModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('bcf-modal-active');
        document.body.style.overflow = 'hidden';
        updateBcfReservationDateReadout();
        bcfCloseTimeMenus();
        const pv = document.getElementById('bcf-purpose-preview');
        const pi = document.getElementById('purpose-input');
        if (pv && pi) {
            if (pi.value && !pv.value) pv.value = pi.value;
            else if (pv.value && !pi.value) pi.value = pv.value;
        }
        document.dispatchEvent(new CustomEvent('bcf-booking-modal-opened'));
        bcfDebouncedSmartHints();
        if (facilitySel && facilitySel.value) {
            filterTimeSlotsByOperatingHours();
        } else {
            applySameDayPastTimeCutoff();
            bcfRebuildTimeMenus();
        }
    }
    function closeBookingFlowModal() {
        if (!bookingFlowModal) return;
        bcfCloseTimeMenus();
        bookingFlowModal.classList.remove('is-open');
        bookingFlowModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('bcf-modal-active');
        if (!document.querySelector('.modal-confirm.open')) {
            document.body.style.overflow = '';
        }
    }

    function bcfApplyTimeSlotToInputs(slotStr) {
        if (!slotStr || !startTimeInput || !endTimeInput) return;
        const timeMatch = String(slotStr).match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
        if (!timeMatch) return;
        startTimeInput.value = timeMatch[1] + ':' + timeMatch[2];
        endTimeInput.value = timeMatch[3] + ':' + timeMatch[4];
        startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
        endTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function bcfApplyChatbotPrefill(d) {
        if (!d || typeof d !== 'object') return;
        if (d.facility_id && facilitySel) {
            const opt = facilitySel.querySelector('option[value="' + String(d.facility_id) + '"]');
            if (opt) {
                facilitySel.value = String(d.facility_id);
                facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        if (d.reservation_date && dateInput) {
            dateInput.value = d.reservation_date;
            dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            updateBcfReservationDateReadout();
        }
        if (d.start_time && startTimeInput) {
            startTimeInput.value = d.start_time;
            startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (d.end_time && endTimeInput) {
            endTimeInput.value = d.end_time;
            endTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if ((!d.start_time || !d.end_time) && d.time_slot) {
            bcfApplyTimeSlotToInputs(d.time_slot);
        } else if (d.start_time && d.end_time) {
            bcfApplyTimeSlotToInputs(d.start_time + ' - ' + d.end_time);
        }
        const purposeInput = document.getElementById('purpose-input');
        const purposePreview = document.getElementById('bcf-purpose-preview');
        if (d.purpose) {
            if (purposeInput) purposeInput.value = d.purpose;
            if (purposePreview) purposePreview.value = d.purpose;
        }
        const attendeesInput = document.getElementById('expected-attendees');
        if (d.expected_attendees && attendeesInput) {
            attendeesInput.value = String(d.expected_attendees);
        }
        if (facilitySel && facilitySel.value) {
            filterTimeSlotsByOperatingHours();
        } else {
            applySameDayPastTimeCutoff();
        }
        bcfRebuildTimeMenus();
        openBookingFlowModal();
        setTimeout(function () {
            if (typeof checkConflict === 'function') checkConflict();
        }, 250);
    }

    window.openBookingFlowModal = openBookingFlowModal;
    window.bcfApplyChatbotPrefill = bcfApplyChatbotPrefill;
    
    // Prefill from query params (facility_id, reservation_date, time_slot, purpose, expected_attendees) - MUST BE DECLARED EARLY
    const qp = new URLSearchParams(window.location.search);
    const preFacility = qp.get('facility_id');
    const preDate = qp.get('reservation_date');
    const preSlot = qp.get('time_slot'); // Legacy support for pre-filled slots
    const preStartTime = qp.get('start_time');
    const preEndTime = qp.get('end_time');
    const prePurpose = qp.get('purpose');
    const preAttendees = qp.get('expected_attendees');
    
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
            const aeClear = document.getElementById('expected-attendees');
            if (aeClear) aeClear.removeAttribute('max');
            refreshBookingGates();
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
            const response = await bcfFetchPost(basePath + '/dashboard/facility-details-api', { facility_id: facilityId });
            
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
                    html += '<p style="margin: 0.35rem 0 0; color: #1b1b1f; line-height: 1.6;" class="bcf-label-row">Auto-approval: ' + escapeHtml(facility.capacity_threshold) + ' attendees '
                        + frsFieldTipHtml('Bookings at or below this attendee count may be auto-approved when other rules allow.') + '</p>';
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
                html += '</div>';
            }
            
            // Update content
            facilityDetailsContent.innerHTML = html;
            if (typeof window.frsRefreshFieldTips === 'function') {
                window.frsRefreshFieldTips();
            }
            window._bcfMaxFacilityCapacity = null;
            if (facility.capacity) {
                const capMatch = String(facility.capacity).match(/(\d{1,7})/);
                if (capMatch) {
                    window._bcfMaxFacilityCapacity = parseInt(capMatch[1], 10);
                }
            }
            const attendeeEl = document.getElementById('expected-attendees');
            if (attendeeEl) {
                attendeeEl.removeAttribute('max');
                if (window._bcfMaxFacilityCapacity != null && !isNaN(window._bcfMaxFacilityCapacity)) {
                    attendeeEl.setAttribute('max', String(window._bcfMaxFacilityCapacity));
                }
            }
            refreshBookingGates();
            
        } catch (error) {
            console.error('Error loading facility details:', error);
            facilityDetailsContent.innerHTML = '<div style="text-align: center; padding: 2rem; color: #b23030;"><p>Unable to load facility details. Please try again.</p></div>';
            const aeErr = document.getElementById('expected-attendees');
            if (aeErr) aeErr.removeAttribute('max');
            refreshBookingGates();
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

    // Check facility status when selected
    facilitySel?.addEventListener('change', function() {
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
            window._bcfLastConflictHard = false;
            refreshBookingGates();
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
        refreshBookingGates();
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
            const url = basePath + '/dashboard/ai-conflict-check';
            console.log('Checking conflict:', { fid, date, timeSlot, url });
            const resp = await bcfFetchPost(url, { facility_id: fid, date: date, time_slot: timeSlot });
            
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
            window._bcfLastConflictHard = false;
            const key = `${fid}|${date}|${timeSlot}`;
            
            // Add small delay to make loading state visible (professional feel)
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // Hard conflict (approved reservation) - block booking
            if (data.has_conflict) {
                window._bcfLastConflictHard = true;
                showMessage(data.message || 'This slot is already booked (approved reservation). Please select an alternative time.', data.alternatives || [], data.risk_score ?? null, eventLabel, 'hard');
                lastShown = {fid, date, timeSlot};
            } 
            // Soft conflict (pending reservations) - show warning but allow booking
            else if (data.soft_conflicts && data.soft_conflicts.length > 0) {
                window._bcfLastConflictHard = false;
                const pendingCount = data.pending_count || data.soft_conflicts.length;
                const msg = `Warning: ${pendingCount} pending reservation(s) exist for this slot. You can still book, but admin will approve only one based on priority.`;
                showMessage(msg, [], data.risk_score ?? null, eventLabel, 'soft');
                lastShown = {fid, date, timeSlot};
            } 
            // High risk or holiday
            else if ((data.risk_score ?? 0) >= 50 || eventLabel) {
                window._bcfLastConflictHard = false;
                const msg = eventLabel
                    ? `Higher demand expected (${eventLabel}). Consider alternative slots.`
                    : 'Higher demand expected. Consider alternative slots.';
                showMessage(msg, data.alternatives || [], data.risk_score ?? null, eventLabel, 'risk');
                lastShown = {fid, date, timeSlot};
            } else {
                window._bcfLastConflictHard = false;
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
            window._bcfLastConflictHard = false;
            refreshBookingGates();
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
    
    function bcfIsReservationDateToday() {
        const d = dateInput ? (dateInput.value || '').trim() : '';
        return d !== '' && d === BCF_TODAY_ISO;
    }

    function bcfMinutesFromHi(hi) {
        const p = (hi || '').split(':');
        if (p.length < 2) return 0;
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    /** Disable start/end slots that are already past when booking for today (PH server clock). */
    function applySameDayPastTimeCutoff() {
        if (!startTimeInput || !endTimeInput) return;
        if (!bcfIsReservationDateToday()) {
            return;
        }
        const earliest = BCF_EARLIEST_START_MIN;

        startTimeInput.querySelectorAll('option').forEach(function (opt) {
            if (opt.value === '') return;
            const mins = bcfMinutesFromHi(opt.value);
            if (mins < earliest) {
                opt.disabled = true;
                opt.style.display = 'none';
            }
        });

        if (startTimeInput.value && bcfMinutesFromHi(startTimeInput.value) < earliest) {
            startTimeInput.value = '';
            endTimeInput.value = '';
        }

    }

    // Time validation: ensure end time is after start time and within limits
    function validateTimes() {
        if (!startTimeInput || !endTimeInput) return true;
        
        const startTime = startTimeInput.value;
        const endTime = endTimeInput.value;
        
        if (!startTime || !endTime) return true;

        if (bcfIsReservationDateToday() && bcfMinutesFromHi(startTime) < BCF_EARLIEST_START_MIN) {
            const eh = Math.floor(BCF_EARLIEST_START_MIN / 60);
            const em = BCF_EARLIEST_START_MIN % 60;
            startTimeInput.setCustomValidity('For today, start time must be ' + String(eh).padStart(2, '0') + ':' + String(em).padStart(2, '0') + ' or later.');
            return false;
        }
        startTimeInput.setCustomValidity('');
        
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
    endTimeInput?.addEventListener('change', function () { bcfRebuildTimeMenus(); });
    
    // Update end time options based on start time selection
    startTimeInput?.addEventListener('change', function() {
        if (!startTimeInput || !endTimeInput) return;
        const startTime = startTimeInput.value;
        if (!startTime) {
            // Enable all end time options if no start time selected
            const endOptions = endTimeInput.querySelectorAll('option');
            endOptions.forEach(option => option.disabled = false);
            bcfRebuildTimeMenus();
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
        bcfRebuildTimeMenus();
    });
    
    // Facility Recommendations (dashboard route — not legacy ai_recommendations_api.php)
    const purposeInput = document.getElementById('purpose-input');
    const recommendationsDiv = document.getElementById('ai-recommendations');
    const recommendationsList = document.getElementById('recommendations-list');
    let recommendationTimeout = null;

    const expectedAttendeesInput = document.getElementById('expected-attendees');

    /** Hint until purpose + calendar date + times exist; attendees optional (API defaults to 50). */
    function syncAiRecommendationsGate() {
        const purpose = purposeInput?.value?.trim() || '';
        const loadingEl = document.getElementById('recommendations-loading');
        const bestTimesEl = document.getElementById('recommendations-best-times');
        const introEl = document.getElementById('recommendations-intro');

        if (!purpose || purpose.length < 3) {
            if (recommendationsDiv) recommendationsDiv.style.display = 'none';
            if (loadingEl) loadingEl.style.display = 'none';
            return;
        }

        const date = (dateInput?.value || '').trim();
        const startTime = (startTimeInput?.value || '').trim();
        const endTime = (endTimeInput?.value || '').trim();

        const missing = [];
        if (!date) {
            missing.push('reservation date — tap a day on the month grid (or close the modal and pick a date first)');
        }
        if (!startTime || !endTime) {
            missing.push('start and end times — use the time dropdowns above');
        }

        if (missing.length) {
            if (recommendationsDiv) recommendationsDiv.style.display = 'block';
            if (introEl) {
                introEl.textContent = 'Still needed for AI suggestions: ' + missing.join(' ') + ' Expected attendees can be filled later (defaults to 50 for ranking until you enter a number).';
            }
            if (loadingEl) loadingEl.style.display = 'none';
            if (bestTimesEl) bestTimesEl.style.display = 'none';
            if (recommendationsList) recommendationsList.innerHTML = '';
            return;
        }

        fetchRecommendationsCore();
    }

    function debouncedSyncAiRecommendations() {
        if (recommendationTimeout) clearTimeout(recommendationTimeout);
        recommendationTimeout = setTimeout(syncAiRecommendationsGate, 600);
    }

    document.addEventListener('bcf-booking-modal-opened', function () {
        debouncedSyncAiRecommendations();
    });
    
    async function fetchRecommendationsCore() {
        const purpose = purposeInput?.value?.trim();
        if (!purpose || purpose.length < 3) {
            return;
        }

        const loadingEl = document.getElementById('recommendations-loading');
        
        const date = dateInput?.value;
        const startTime = startTimeInput?.value;
        const endTime = endTimeInput?.value;
        if (!date || !startTime || !endTime) return;

        const timeSlot = startTime + ' - ' + endTime;
        const expectedAttendeesRaw = (expectedAttendeesInput?.value || '').trim();
        const expectedAttendees = expectedAttendeesRaw !== '' ? expectedAttendeesRaw : '50';
        // Show loading indicator
        if (recommendationsDiv) recommendationsDiv.style.display = 'block';
        if (loadingEl) loadingEl.style.display = 'block';
        if (recommendationsList) recommendationsList.innerHTML = '';
        
        try {
            const formData = new URLSearchParams();
            formData.append('purpose', purpose);
            formData.append('reservation_date', date);
            formData.append('time_slot', timeSlot);
            formData.append('expected_attendees', expectedAttendees);
            
            const response = await bcfFetchPost(basePath + '/dashboard/facility-recommendations', formData);
            
            if (!response.ok) {
                throw new Error('Failed to fetch recommendations');
            }
            
            const rawBody = await response.text();
            let data;
            try {
                data = JSON.parse(rawBody);
            } catch (parseErr) {
                console.error('facility-recommendations: non-JSON response', rawBody.substring(0, 400));
                throw parseErr;
            }
            if (data.error) {
                console.warn('facility-recommendations:', data.error);
                if (loadingEl) loadingEl.style.display = 'none';
                if (recommendationsDiv) recommendationsDiv.style.display = 'block';
                const errIntro = document.getElementById('recommendations-intro');
                if (errIntro) errIntro.textContent = String(data.error);
                if (recommendationsList) recommendationsList.innerHTML = '';
                return;
            }
            
            // Store recommendations data for use in selectRecommendation
            currentRecommendationsData = data;
            
            // Hide loading indicator
            if (loadingEl) loadingEl.style.display = 'none';

            const introEl = document.getElementById('recommendations-intro');
            if (introEl) {
                const engine = data.recommendation_engine || (data.ml_enabled ? 'sklearn' : 'php_rules');
                if (engine === 'php_rules') {
                    introEl.textContent = 'Suggested facilities for your event (match level, distance, and capacity):';
                } else {
                    introEl.textContent = 'AI-ranked facilities for your event (match level, distance, and capacity):';
                }
            }
            
            if (data.recommendations && data.recommendations.length > 0) {
                if (recommendationsDiv) recommendationsDiv.style.display = 'block';
                const bestTimesEl = document.getElementById('recommendations-best-times');
                if (bestTimesEl) {
                    if (data.best_times_label) {
                        const timesIcon = data.suggested_times_source === 'database' ? '📊 ' : '🕐 ';
                        bestTimesEl.textContent = timesIcon + data.best_times_label;
                        bestTimesEl.style.display = 'block';
                    } else {
                        bestTimesEl.style.display = 'none';
                    }
                }
                if (recommendationsList) {
                    const recScores = data.recommendations.map(function (r) {
                        return r.ml_relevance_score != null ? Number(r.ml_relevance_score) : NaN;
                    }).filter(function (n) { return !isNaN(n); });
                    function bcfMatchTier(score) {
                        const s = Number(score);
                        if (isNaN(s) || recScores.length === 0) return 'MEDIUM';
                        const max = Math.max.apply(null, recScores);
                        const min = Math.min.apply(null, recScores);
                        if (max === min) return 'HIGH';
                        const ratio = (s - min) / (max - min);
                        if (ratio >= 0.66) return 'HIGH';
                        if (ratio >= 0.33) return 'MEDIUM';
                        return 'LOW';
                    }
                    function bcfTierStyle(tier) {
                        if (tier === 'HIGH') return 'background:#dcfce7;color:#14532d;border:1px solid #86efac;';
                        if (tier === 'LOW') return 'background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;';
                        return 'background:#fef9c3;color:#713f12;border:1px solid #fde047;';
                    }
                    recommendationsList.innerHTML = data.recommendations.map((rec, idx) => {
                        const tier = bcfMatchTier(rec.ml_relevance_score);
                        const tierStyle = bcfTierStyle(tier);
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
                        }
                        
                        const hoursRow = operatingHoursDisplay
                            ? `<div style="font-size:0.75rem; color:#0d7a43; font-weight:500; margin-top:0.25rem;">${operatingHoursDisplay}</div>`
                            : '';
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
                                    <span style="font-weight:700;font-size:0.75rem;padding:0.2rem 0.5rem;border-radius:999px;${tierStyle}">${tier} match</span>
                                </div>
                                <div style="font-size:0.8rem; color:#666; margin-top:0.25rem; margin-bottom:0.25rem;">${reason}</div>
                                ${hoursRow}
                                <div style="font-size:0.7rem; color:#8b95b5; margin-top:0.25rem; font-style:italic;">Click to select this facility</div>
                            </div>
                        `;
                    }).join('');
                }
            } else {
                if (recommendationsDiv) recommendationsDiv.style.display = 'block';
                const emptyIntro = document.getElementById('recommendations-intro');
                if (emptyIntro) emptyIntro.textContent = 'No facility suggestions returned (none available or filters excluded all). Try a different purpose or check that facilities are marked available.';
                const bestTimesElEmpty = document.getElementById('recommendations-best-times');
                if (bestTimesElEmpty) bestTimesElEmpty.style.display = 'none';
            }
        } catch (error) {
            console.error('Recommendation error:', error);
            if (loadingEl) loadingEl.style.display = 'none';
            if (recommendationsDiv) recommendationsDiv.style.display = 'block';
            const failIntro = document.getElementById('recommendations-intro');
            if (failIntro) {
                failIntro.textContent = 'Could not load suggestions. Confirm you are logged in and open DevTools → Network for POST ' + basePath + '/dashboard/facility-recommendations (expect 200 JSON).';
            }
            if (recommendationsList) recommendationsList.innerHTML = '';
        }
    }

    purposeInput?.addEventListener('input', function () {
        const pv = document.getElementById('bcf-purpose-preview');
        if (pv) pv.value = this.value;
        try { sessionStorage.setItem('bcf_booking_purpose', this.value); } catch (e) { /* ignore */ }
        bcfDebouncedSmartHints();
        debouncedSyncAiRecommendations();
    });
    purposeInput?.addEventListener('blur', syncAiRecommendationsGate);
    dateInput?.addEventListener('change', syncAiRecommendationsGate);
    dateInput?.addEventListener('input', debouncedSyncAiRecommendations);
    startTimeInput?.addEventListener('change', syncAiRecommendationsGate);
    endTimeInput?.addEventListener('change', syncAiRecommendationsGate);
    startTimeInput?.addEventListener('input', debouncedSyncAiRecommendations);
    endTimeInput?.addEventListener('input', debouncedSyncAiRecommendations);
    expectedAttendeesInput?.addEventListener('input', function () {
        refreshBookingGates();
        debouncedSyncAiRecommendations();
    });
    expectedAttendeesInput?.addEventListener('change', function () {
        refreshBookingGates();
        syncAiRecommendationsGate();
    });
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
            startOptions.forEach(function (opt) {
                if (opt.value === '') return;
                opt.style.display = '';
                opt.disabled = false;
            });
            endOptions.forEach(function (opt) {
                if (opt.value === '') return;
                opt.style.display = '';
                opt.disabled = false;
            });
            applySameDayPastTimeCutoff();
            bcfRebuildTimeMenus();
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
        applySameDayPastTimeCutoff();
        bcfRebuildTimeMenus();
    }
    
    // Filter time slots when facility is selected
    facilitySel?.addEventListener('change', function() {
        filterTimeSlotsByOperatingHours();
        checkConflict();
    });

    // Prefill modal facility from Smart Scheduler (?facility_id=) or calendar toolbar (?book_fac=)
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
    } else if (bookCalFacilityId > 0 && facilitySel) {
        const calOpt = facilitySel.querySelector('option[value="' + String(bookCalFacilityId) + '"]');
        if (calOpt) {
            facilitySel.value = String(bookCalFacilityId);
            filterTimeSlotsByOperatingHours();
            facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    
    // Also filter on initial page load if facility is already selected
    if (facilitySel && facilitySel.value) {
        setTimeout(() => filterTimeSlotsByOperatingHours(), 100);
    }
    if (preDate && dateInput) {
        dateInput.value = preDate;
        dateInput.dispatchEvent(new Event('change', { bubbles: true }));
        updateBcfReservationDateReadout();
    }
    updateBcfReservationDateReadout();
    if (preSlot) {
        bcfApplyTimeSlotToInputs(preSlot);
    } else if (preStartTime || preEndTime) {
        if (preStartTime && startTimeInput) {
            startTimeInput.value = preStartTime;
            startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (preEndTime && endTimeInput) {
            endTimeInput.value = preEndTime;
            endTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        bcfRebuildTimeMenus();
    }
    const prefillPurposeEl = document.getElementById('purpose-input');
    const prefillAttendeesEl = document.getElementById('expected-attendees');
    if (prePurpose && prefillPurposeEl) prefillPurposeEl.value = prePurpose;
    if (preAttendees && prefillAttendeesEl) prefillAttendeesEl.value = preAttendees;

    setTimeout(function () {
        if (typeof syncAiRecommendationsGate === 'function') syncAiRecommendationsGate();
    }, 300);

    // If all fields are pre-filled, trigger conflict check after a short delay
    if (preFacility && preDate && startTimeInput?.value && endTimeInput?.value) {
        setTimeout(() => {
            checkConflict();
        }, 200);
    }

    const bcfQuickSlotSel = document.getElementById('bcf-slot-from-availability');
    let bcfAvailTimer = null;
    function normalizedDash(s) {
        return String(s).replace(/\u2013|\u2014/g, '-');
    }
    async function refillBcfAvailabilitySlots() {
        if (!bcfQuickSlotSel || !facilitySel || !dateInput) return;
        const fid = facilitySel.value;
        const dt = dateInput.value;
        bcfQuickSlotSel.innerHTML = '<option value="">Loading gaps…</option>';
        if (!fid || !dt) {
            bcfQuickSlotSel.innerHTML = '<option value="">Choose facility + date first</option>';
            return;
        }
        const opt = facilitySel.options[facilitySel.selectedIndex];
        const fname = opt ? opt.text.trim() : '';
        try {
            const url = basePath + '/api/public/availability?date=' + encodeURIComponent(dt);
            const resp = await fetch(url);
            if (!resp.ok) {
                throw new Error('availability');
            }
            const data = await resp.json();
            const fac = (data.facilities || []).find(f => String(f.facility_name).trim() === fname);
            bcfQuickSlotSel.innerHTML = '<option value="">Pick an open gap…</option>';
            if (!fac || !fac.timeline) {
                return;
            }
            fac.timeline.forEach(seg => {
                if (seg.type !== 'available' || !seg.range) return;
                const r = normalizedDash(seg.range);
                const pm = r.match(/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/);
                if (!pm) return;
                const optEl = document.createElement('option');
                optEl.value = pm[1] + '|' + pm[2];
                optEl.textContent = r;
                bcfQuickSlotSel.appendChild(optEl);
            });
        } catch (e2) {
            bcfQuickSlotSel.innerHTML = '<option value="">Could not load gaps</option>';
        }
    }
    function debouncedRefillAvail() {
        clearTimeout(bcfAvailTimer);
        bcfAvailTimer = setTimeout(refillBcfAvailabilitySlots, 450);
    }
    bcfQuickSlotSel?.addEventListener('change', function () {
        if (!startTimeInput || !endTimeInput || !this.value) return;
        const parts = this.value.split('|');
        if (parts.length === 2) {
            startTimeInput.value = parts[0];
            endTimeInput.value = parts[1];
            startTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
            endTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
            debouncedCheckConflict();
        }
    });
    facilitySel?.addEventListener('change', debouncedRefillAvail);
    dateInput?.addEventListener('change', function () {
        filterTimeSlotsByOperatingHours();
        debouncedRefillAvail();
    });

    document.getElementById('bcf-open-booking-explicit')?.addEventListener('click', function () {
        if (!dateInput || !dateInput.value) {
            alert('Please select a date on the calendar first.');
            return;
        }
        openBookingFlowModal();
        debouncedRefillAvail();
    });
    document.getElementById('bcf-close-booking-modal')?.addEventListener('click', closeBookingFlowModal);
    bookingFlowModal?.addEventListener('click', function (e) {
        if (e.target === bookingFlowModal) {
            closeBookingFlowModal();
        }
    });
    document.addEventListener('keydown', function (ke) {
        if (ke.key !== 'Escape' || !bookingFlowModal || !bookingFlowModal.classList.contains('is-open')) return;
        if (bcfActiveTimeMenu) {
            bcfCloseTimeMenus();
            ke.preventDefault();
            return;
        }
        closeBookingFlowModal();
    });

    document.getElementById('main-booking-form')?.addEventListener('submit', function (ev) {
        const d = dateInput ? (dateInput.value || '').trim() : '';
        if (!d) {
            ev.preventDefault();
            alert('Please choose a reservation date by selecting a day on the calendar.');
            return;
        }
        const attEl = document.getElementById('expected-attendees');
        const attRaw = attEl ? (attEl.value || '').trim() : '';
        const n = parseInt(attRaw, 10);
        if (!attRaw || isNaN(n) || n < 1) {
            ev.preventDefault();
            alert('Please enter the expected number of attendees (at least 1).');
            if (attEl) attEl.reportValidity();
            return;
        }
        const maxC = window._bcfMaxFacilityCapacity;
        if (maxC != null && !isNaN(maxC) && n > maxC) {
            ev.preventDefault();
            alert('Expected attendees (' + n + ') cannot exceed this facility\'s maximum occupancy (' + maxC + ').');
        }
    });

    document.querySelectorAll('.bcf-book-cal-cell').forEach(function (cell) {
        function activateBookingCalDate() {
            const ds = cell.getAttribute('data-bcf-date');
            if (!ds || !dateInput || !facilitySel) return;
            dateInput.value = ds;
            // Required so AI recommendations & other listeners see the new date (value alone does not fire handlers).
            dateInput.dispatchEvent(new Event('input', { bubbles: true }));
            dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            if (bookCalFacilityId) {
                facilitySel.value = String(bookCalFacilityId);
                facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            openBookingFlowModal();
            debouncedRefillAvail();
            setTimeout(debouncedCheckConflict, 200);
        }
        cell.addEventListener('click', activateBookingCalDate);
        cell.addEventListener('keydown', function (ke) {
            if (ke.key === 'Enter' || ke.key === ' ') {
                ke.preventDefault();
                activateBookingCalDate();
            }
        });
    });

    if (BCF_OPEN_ON_LOAD) {
        setTimeout(function () {
            openBookingFlowModal();
            debouncedRefillAvail();
        }, 200);
    }

    refreshBookingGates();
    
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
