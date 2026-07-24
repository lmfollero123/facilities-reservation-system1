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
require_once __DIR__ . '/../../../../config/flash_helper.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/blackout_dates.php';
require_once __DIR__ . '/../../../../services/PredictionService.php';
require_once __DIR__ . '/../../../../services/HolidayService.php';

// Check permissions for booking facility
$role = $_SESSION['role'] ?? 'Resident';
$reservationsHubMine = (($_SERVER['_RESERVATIONS_HUB_ROUTE'] ?? '') === 'mine') || (isset($_GET['module']) && $_GET['module'] === 'mine');

// Read permission controls page access for both booking and my reservations
if (!frs_can_read($role, 'reservations')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

// Create permission controls ability to submit bookings
$canCreateReservations = frs_can_create($role, 'reservations');
require_once __DIR__ . '/../../../../config/lookups.php';
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

$pageTitle = $reservationsHubMine ? 'My Reservations | LGU Facilities Reservation' : 'Book a Facility | LGU Facilities Reservation';
$success = '';
$error = '';
$errorField = '';
$bcfOpenBookingModal = false;
$conflictWarning = null;
$recommendations = [];

$BOOKING_ADVANCE_MAX_DAYS = frs_resident_booking_limit_config()['advance_max_days'];

// Check if user is verified and if they have uploaded a valid ID
// Note: Staff and Admin are automatically considered verified
$userId = $_SESSION['user_id'] ?? null;
$sessionActorId = (int)$userId;
$viewerRole = (string)($_SESSION['role'] ?? 'Resident');
$canBookOnBehalf = in_array($viewerRole, ['Admin', 'Staff'], true);
$walkInSelectedResident = null;
$selectedBookForUserId = (int)($_POST['book_for_user_id'] ?? $_GET['book_for'] ?? 0);
if ($canBookOnBehalf && $pdo && $selectedBookForUserId > 0) {
    try {
        $wr = $pdo->prepare(
            "SELECT id, name, email FROM users WHERE id = :id AND role = 'Resident' AND status = 'active' LIMIT 1"
        );
        $wr->execute(['id' => $selectedBookForUserId]);
        $walkInSelectedResident = $wr->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$walkInSelectedResident) {
            $selectedBookForUserId = 0;
        }
    } catch (Throwable $e) {
        $walkInSelectedResident = null;
        $selectedBookForUserId = 0;
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
    $facilitiesStmt = $pdo->query("SELECT id, name, base_rate, status, operating_hours FROM facilities WHERE status != 'deleted' ORDER BY name");
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
    $upcomingCimmByFacility = frs_facilities_upcoming_cimm_maintenance_map($pdo);
} catch (Throwable $e) {
    $facilities = [];
    $upcomingCimmByFacility = [];
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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !empty($userId)) {
    frs_sync_user_pending_payments($pdo, (int)$userId);
}
$frsCsrfOk = frs_csrf_ok();
$frsCsrfError = 'Your session expired or the form is invalid. Please refresh and try again.';
require_once __DIR__ . '/includes/reservations_mine_post_handlers.php';
if ($message !== '' && $messageType === 'success') {
    frs_flash_success($message);
    $message = '';
}
require_once __DIR__ . '/../../../../config/reservation_documents.php';
require_once __DIR__ . '/../../../../config/ai_demo_scenarios.php';

// Expand ?demo_scenario=low|medium|high into full booking prefill params
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && !empty($_GET['demo_scenario'])
    && empty($_GET['demo_loaded'])
    && frs_ai_demo_can_load_scenarios($role)
) {
    $demoKey = trim((string)$_GET['demo_scenario']);
    $demoResolved = frs_ai_demo_resolve_scenario($pdo, $demoKey);
    if ($demoResolved) {
        $demoParams = array_merge($_GET, $demoResolved['params']);
        unset($demoParams['demo_scenario']);
        header('Location: ' . base_path() . '/dashboard/book-facility?' . http_build_query($demoParams));
        exit;
    }
}

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
    $textCheck = frs_validate_booking_text_fields($purpose, $bookingNotes);
    if (!$textCheck['ok']) {
        $error = $textCheck['message'];
        $errorField = $textCheck['field'];
    }
    if ($purpose !== '' && $bookingNotes !== '' && empty($error)) {
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
        $errorField = 'doc_valid_id';
    }
    
    // If user already has a valid ID document uploaded, don't allow another upload
    if (!$isVerifiedOrPrivileged && $hasValidIdDocument && $hasValidIdUpload) {
        $error = 'You have already submitted a valid ID document. Please wait for admin verification.';
        $errorField = 'doc_valid_id';
    }
    
    // Validate time inputs and create time slot string
    if (!$startTime || !$endTime) {
        $error = 'Please select both start and end times.';
        $errorField = $startTime ? 'end_time' : 'start_time';
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
        if (!$facilityId) {
            $errorField = 'facility_id';
        } elseif (!$date) {
            $errorField = 'reservation_date';
        } elseif (!$purpose) {
            $errorField = 'purpose';
        }
    } else {
        // Validate date - ensure it's not in the past (use server timezone)
        $today = new DateTime('today', new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila'));
        $reservationDate = DateTime::createFromFormat('Y-m-d', $date, new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila'));
        if (!$reservationDate) {
            $error = 'Invalid date format.';
        } elseif ($reservationDate < $today) {
            $error = 'Cannot book facilities for past dates. Please select today or a future date.';
        } elseif (frs_booking_limits_apply_to_user($pdo, $userId) && $reservationDate > (clone $today)->modify('+' . $BOOKING_ADVANCE_MAX_DAYS . ' days')) {
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
        $errorField = 'expected_attendees';
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
    
    if (!$error && frs_booking_limits_apply_to_user($pdo, $userId)) {
        $limitCheck = frs_validate_resident_booking_limits($pdo, $userId, $date, $activeBookingStatusesSql);
        if (!$limitCheck['ok']) {
            $error = $limitCheck['message'];
        }
    }

    if (!$error) {
        // Check facility status - prevent booking if under maintenance or offline
        $facilityStatusStmt = $pdo->prepare('SELECT status, name FROM facilities WHERE id = :id');
        $facilityStatusStmt->execute(['id' => $facilityId]);
        $facilityStatus = $facilityStatusStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$facilityStatus || $facilityStatus['status'] === 'deleted') {
            $error = 'Invalid facility selected. Please select a valid facility.';
        } elseif ($facilityStatus['status'] === 'offline') {
            $error = '⚠️ This facility is currently offline and unavailable for booking. Please select a different facility.';
        }
        // status === maintenance: do NOT blanket-reject. CIMM sets that status for the
        // active window but dated blackouts define which days are blocked (e.g. Jul 12–Aug 9).

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
                $enriched = frs_blackout_enrich_row($blackout);
                if (($enriched['source_type'] ?? '') === 'cimm') {
                    $error = '⚠️ This facility has scheduled CIMM maintenance on the selected date'
                        . (' (' . ($enriched['display_reason'] ?? 'maintenance') . ').')
                        . ' Please choose another date or facility.';
                } elseif (($enriched['source_type'] ?? '') === 'ipms') {
                    $error = '⚠️ This facility has an ongoing IPMS infrastructure project on the selected date'
                        . (' (' . ($enriched['display_reason'] ?? 'project') . ').')
                        . ' Please choose another date or facility.';
                } else {
                    $error = '⚠️ This facility is blacked out on the selected date'
                        . (' (' . ($enriched['display_reason'] ?? 'unavailable') . ').')
                        . ' Please choose another date or facility.';
                }
            } elseif (($facilityStatus['status'] ?? '') === 'maintenance') {
                // Staff set "maintenance" with no dated blackouts → still block everywhere.
                // Facilities with CIMM Sync blackouts stay bookable on non-blackout days.
                $anyBoStmt = $pdo->prepare('SELECT 1 FROM facility_blackout_dates WHERE facility_id = :facility_id LIMIT 1');
                $anyBoStmt->execute(['facility_id' => $facilityId]);
                if (!$anyBoStmt->fetchColumn()) {
                    $error = '⚠️ This facility is currently under maintenance and cannot be booked at this time. Please select a different facility or check back later.';
                }
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
            frs_flash_success($success);
            header('Location: ' . base_path() . '/dashboard/book-facility?module=mine');
            exit;
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
            frs_flash_success($success);
            header('Location: ' . base_path() . '/dashboard/book-facility?module=mine');
            exit;
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

$bookingFieldSelectors = [
    'facility_id' => '#facility-select',
    'reservation_date' => '#bcf-reservation-date-display',
    'start_time' => '#bcf-start-time-trigger',
    'end_time' => '#bcf-end-time-trigger',
    'purpose' => '#purpose-input',
    'booking_notes' => '#booking-notes',
    'expected_attendees' => '#expected-attendees',
    'doc_valid_id' => 'input[name="doc_valid_id"]',
    'book_for_user_id' => '#bcf-walkin-search',
];

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

// Get demand forecast for the selected facility and month
$demandForecastMatrix = [];
if ($bookFacilityPick > 0) {
    $predictionService = new PredictionService($pdo);
    
    // Get forecast for 60 days (booking advance window) instead of just days in month
    $advanceBookingDays = 60;
    $monthForecast = $predictionService->getFacilityDemandForecast($bookFacilityPick, $advanceBookingDays);
    
    if (!empty($monthForecast)) {
        foreach ($monthForecast as $dayForecast) {
            $date = $dayForecast['date'];
            $slots = $dayForecast['slots'] ?? [];
            
            if (!empty($slots)) {
                $totalScore = 0;
                $slotCount = count($slots);
                
                foreach ($slots as $slot) {
                    $totalScore += $slot['score'];
                }
                
                $avgScore = $slotCount > 0 ? round($totalScore / $slotCount) : 0;
                
                // Determine classification
                $classification = 'Low';
                if ($avgScore >= 76) $classification = 'Very High';
                elseif ($avgScore >= 51) $classification = 'High';
                elseif ($avgScore >= 26) $classification = 'Medium';
                
                $demandForecastMatrix[$date] = [
                    'score' => $avgScore,
                    'classification' => $classification
                ];
            }
        }
    }
}

// Get Philippines holidays for the selected calendar month/year
$holidayMatrix = [];
$holidayData = [];
$holidayService = new HolidayService();

$monthStart = sprintf('%04d-%02d-01', $bookCalYear, $bookCalMonth);
$monthEnd = sprintf('%04d-%02d-%02d', $bookCalYear, $bookCalMonth, date('t', mktime(0, 0, 0, $bookCalMonth, 1, $bookCalYear)));

$holidayList = $holidayService->getHolidaysInRange($monthStart, $monthEnd);

if (!empty($holidayList)) {
    foreach ($holidayList as $holiday) {
        $holidayMatrix[$holiday['date']] = $holiday;
        $holidayData[$holiday['date']] = [
            'name' => $holiday['name'],
            'type' => $holiday['type']
        ];
    }
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
.bcf-facility-image-wrap {
    margin: -0.15rem 0 1.25rem;
    border-radius: 10px;
    overflow: hidden;
    background: #eef1f7;
    border: 1px solid #e8ecf4;
}
.bcf-facility-image {
    display: block;
    width: 100%;
    max-height: 200px;
    object-fit: cover;
    object-position: center;
}
.bcf-facility-image-citation {
    padding: 0.35rem 0.65rem;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 0.72rem;
    line-height: 1.4;
}
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
.bcf-tone-blackout { background: #fee2e2; border-color: #fca5a5; color: #7f1d1d; }
.bcf-tone-cimm_maintenance { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
.bcf-upcoming-cimm-notice {
    margin: 0.5rem 0 0;
    padding: 0.65rem 0.85rem;
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 8px;
    color: #92400e;
    font-size: 0.88rem;
    line-height: 1.45;
}
.bcf-tone-maintenance, .bcf-tone-offline { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
.bcf-tone-muted { background: #f1f5f9; border-color: #e2e8f0; color: #94a3b8; cursor: default; }
.bcf-sq { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 3px; margin-right: 0.25rem; vertical-align: middle; }
/* Viewport-fixed overlay — must stay under document.body because .dashboard-content uses transform animations,
   which would otherwise make fixed positioning relative to the whole tall content area */
.bcf-modal-overlay {
    position: fixed !important;
    inset: 0 !important;
    width: 100% !important;
    max-width: 100%;
    height: 100% !important;
    height: 100dvh !important;
    min-height: 100% !important;
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

/* Dark mode for booking modal */
html[data-theme="dark"] .bcf-modal-head {
    border-bottom-color: #334155;
}

html[data-theme="dark"] .bcf-modal-head h2 {
    color: #f1f5f9;
}

html[data-theme="dark"] .bcf-modal-close {
    color: #94a3b8;
}

html[data-theme="dark"] .bcf-modal-close:hover {
    background: #334155;
    color: #f1f5f9;
}

.bcf-modal-body {
    padding: 1rem 1.1rem 1.25rem;
    overflow: auto;
    -webkit-overflow-scrolling: touch;
}

html[data-theme="dark"] .bcf-modal-body {
    color: #cbd5e1;
}

html[data-theme="dark"] .bcf-walkin-box {
    background: #1e3a5f;
    border-color: #3b82f6;
}

html[data-theme="dark"] .bcf-walkin-title {
    color: #93c5fd;
}

html[data-theme="dark"] .bcf-res-li {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .bcf-res-list-item-title {
    color: #f1f5f9;
}

html[data-theme="dark"] .bcf-res-list-item-meta {
    color: #94a3b8;
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
.bcf-walkin-combo {
    position: relative;
    margin-top: 0.35rem;
}
.bcf-walkin-search-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.bcf-walkin-search {
    width: 100%;
    padding: 0.55rem 2.25rem 0.55rem 0.75rem;
    border-radius: 8px;
    border: 2px solid #dbe3f5;
    font-size: 0.95rem;
    background: #fff;
    color: #1e293b;
}
.bcf-walkin-search:focus {
    outline: none;
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.15);
}
.bcf-walkin-clear {
    position: absolute;
    right: 0.35rem;
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    padding: 0.25rem 0.4rem;
    border-radius: 4px;
}
.bcf-walkin-clear:hover {
    color: #334155;
    background: #f1f5f9;
}
.bcf-walkin-list {
    position: absolute;
    z-index: 50;
    left: 0;
    right: 0;
    top: calc(100% + 0.25rem);
    margin: 0;
    padding: 0.35rem 0;
    list-style: none;
    max-height: 240px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #dbe3f5;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
}
.bcf-walkin-option {
    padding: 0.55rem 0.75rem;
    font-size: 0.9rem;
    color: #1e293b;
    cursor: pointer;
}
.bcf-walkin-option:hover,
.bcf-walkin-option.is-active {
    background: #eff6ff;
}
.bcf-walkin-option-self {
    color: #475569;
    font-style: italic;
    border-bottom: 1px solid #e2e8f0;
}
.bcf-walkin-more,
.bcf-walkin-empty {
    padding: 0.5rem 0.75rem;
    font-size: 0.82rem;
    color: #64748b;
    pointer-events: none;
}
.bcf-walkin-hint {
    margin: 0.35rem 0 0;
    font-size: 0.78rem;
    color: #64748b;
}
html[data-theme="dark"] .bcf-walkin-search {
    background: #1e293b;
    border-color: #475569;
    color: #f1f5f9;
}
html[data-theme="dark"] .bcf-walkin-list {
    background: #1e293b;
    border-color: #475569;
}
html[data-theme="dark"] .bcf-walkin-option {
    color: #f1f5f9;
}
html[data-theme="dark"] .bcf-walkin-option:hover,
html[data-theme="dark"] .bcf-walkin-option.is-active {
    background: #334155;
}
@media (max-width: 719px) {
    .bcf-modal-overlay { align-items: flex-end; padding: 0; justify-content: center; }
    .bcf-modal-dialog {
        width: 100%;
        max-height: 92vh;
        max-height: 92dvh;
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

.bcf-cal-month-select,
.bcf-cal-year-select {
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    font-size: 0.9rem;
    font-weight: 600;
    color: #1e293b;
    cursor: pointer;
    min-width: 120px;
}

.bcf-cal-month-select:hover,
.bcf-cal-year-select:hover {
    border-color: #3b82f6;
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

/* Demand Forecast Strip */
.demand-strip {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    font-weight: 700;
    border-radius: 0 0 6px 6px;
    margin-top: 4px;
}

.demand-strip.demand-low {
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
    color: #166534;
}

.demand-strip.demand-medium {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}

.demand-strip.demand-high {
    background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%);
    color: #9a3412;
}

.demand-strip.demand-very-high {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #dc2626;
}

.demand-score {
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.holiday-indicator {
    position: absolute;
    top: 4px;
    right: 4px;
    font-size: 0.7rem;
    color: #dc2626;
    z-index: 2;
}

.my-reservations-calendar-cell {
    position: relative;
    min-height: 70px;
}

.status-blackout {
    background: #fecaca !important;
    color: #7f1d1d !important;
    border-color: #f87171 !important;
}
.status-cimm-maintenance {
    background: #fde68a !important;
    color: #92400e !important;
    border-color: #fbbf24 !important;
}

/* ---- Mobile Book Facility / hub chrome ---- */
@media (max-width: 640px) {
    .book-facility-compact.booking-wrapper,
    .book-facility-compact .booking-card,
    .book-facility-compact .my-reservations-calendar,
    .book-facility-compact .bcf-cal-toolbar-wrap,
    .book-facility-compact .bcf-purpose-first-card {
        max-width: 100% !important;
        min-width: 0 !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .book-facility-compact .my-reservations-calendar-grid {
        grid-template-columns: repeat(7, minmax(0, 1fr)) !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .book-facility-compact .my-reservations-calendar-cell {
        min-width: 0 !important;
        overflow: hidden;
    }
    .book-facility-compact .bcf-purpose-first-card textarea,
    .book-facility-compact .bcf-cal-fac-select,
    .book-facility-compact input,
    .book-facility-compact select,
    .book-facility-compact textarea {
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    .booking-hub-tabs {
        display: flex;
        flex-wrap: nowrap;
        gap: 0.4rem;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        border-bottom: none;
        margin-bottom: 0.85rem;
        padding-bottom: 0.15rem;
        scrollbar-width: thin;
    }
    .booking-hub-tab {
        flex: 0 0 auto;
        border-radius: 999px;
        border: 1px solid #dbe3ef;
        padding: 0.5rem 0.9rem;
        margin-bottom: 0;
        white-space: nowrap;
        font-size: 0.88rem;
    }
    .booking-hub-tab.is-active {
        margin-bottom: 0;
        border-color: var(--gov-blue, #0047ab);
        background: #eff6ff;
        color: var(--gov-blue-dark, #1e3a5f);
    }
    .bcf-purpose-first-card {
        padding: 0.7rem 0.75rem;
    }
    .bcf-purpose-first-row {
        flex-direction: column;
        align-items: stretch;
    }
    .bcf-cal-toolbar-grid {
        flex-direction: column;
        align-items: stretch;
        max-width: none;
        gap: 0.55rem;
    }
    .bcf-cal-fac-field {
        flex: 1 1 auto;
        min-width: 0;
        max-width: none;
    }
    .bcf-cal-nav-cluster {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem;
    }
    .bcf-cal-month-select,
    .bcf-cal-year-select {
        min-width: 0;
        width: 100%;
        font-size: 0.85rem;
        padding: 0.45rem 0.5rem;
    }
    .bcf-cal-nav-cluster .bcf-cal-nav-btn {
        min-width: 0;
        width: 100%;
        padding: 0.45rem 0.35rem;
        font-size: 0.78rem;
        grid-column: 1 / -1;
    }
    .book-facility-compact .my-reservations-calendar-cell .status-chip[data-chip-short],
    .my-reservations-calendar-cell .status-chip[data-chip-short] {
        font-size: 0;
        line-height: 0;
    }
    .book-facility-compact .my-reservations-calendar-cell .status-chip[data-chip-short]::after,
    .my-reservations-calendar-cell .status-chip[data-chip-short]::after {
        content: attr(data-chip-short);
        font-size: 0.55rem;
        line-height: 1.15;
        font-weight: 700;
    }
    .book-facility-compact .my-reservations-legend,
    .my-reservations-legend {
        flex-wrap: wrap;
        overflow-x: visible;
        gap: 0.35rem 0.65rem;
        padding-bottom: 0.25rem;
        font-size: 0.72rem;
        width: 100%;
        max-width: 100%;
    }
    .my-reservations-legend-item {
        flex: 0 1 auto;
        min-width: 0;
        white-space: normal;
    }
    .my-reservations-legend-item.my-reservations-legend-demand {
        margin-left: 0 !important;
        border-left: none !important;
        padding-left: 0 !important;
    }
    .bcf-cal-month-heading {
        font-size: 0.95rem;
        text-align: center;
    }
    .book-facility-compact .my-reservations-calendar-cell,
    .my-reservations-calendar-cell {
        min-height: 44px !important;
        min-width: 0 !important;
    }
    .my-reservations-calendar-grid {
        min-width: 0 !important;
        flex: 0 1 auto !important;
        grid-auto-rows: minmax(44px, auto) !important;
    }
    .demand-strip {
        height: 4px;
        font-size: 0;
        pointer-events: none;
    }
    .demand-strip .demand-score {
        display: none;
    }
    .holiday-indicator {
        font-size: 0.55rem;
        top: 2px;
        right: 2px;
    }
    .bcf-facility-image {
        max-height: 140px;
    }
    .bcf-aside-col h2 {
        font-size: 1.05rem;
    }
    .booking-hub-grid {
        gap: 0.75rem;
    }
}

@media (max-width: 719px) {
    .bcf-modal-overlay {
        width: 100% !important;
        max-width: 100% !important;
        height: 100% !important;
        min-height: 100% !important;
        align-items: flex-end;
        padding: 0;
        justify-content: center;
    }
    .bcf-modal-dialog {
        width: 100%;
        max-height: 92vh;
        max-height: 92dvh;
        border-radius: 16px 16px 0 0;
        margin: 0;
    }
    .bcf-modal-body {
        overflow: auto;
        -webkit-overflow-scrolling: touch;
        padding: 0.75rem 0.85rem calc(0.35rem + env(safe-area-inset-bottom, 0px));
    }
    .bcf-modal-sticky-actions {
        position: sticky;
        bottom: 0;
        z-index: 3;
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        margin-top: 1rem;
        padding: 0.75rem 0 0.35rem;
        background: linear-gradient(180deg, rgba(255,255,255,0) 0%, var(--bg-secondary, #fff) 28%);
        border-top: 1px solid #e8ecf4;
    }
    .bcf-modal-sticky-actions .btn-primary,
    .bcf-modal-sticky-actions .btn-outline {
        width: 100%;
        min-height: 46px;
        justify-content: center;
    }
}

.bcf-modal-sticky-actions {
    margin-top: 1.25rem;
}
.bcf-modal-sticky-actions .btn-primary {
    width: 100%;
}

/* Avoid 100vw scrollbar on all sizes */
.bcf-modal-overlay {
    width: 100% !important;
    max-width: 100%;
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

<?php if ($error): ?>
    <div class="message error booking-error" data-error-field="<?= htmlspecialchars($errorField); ?>" style="background:#fdecee;color:#b23030;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.5rem;">
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
        <?php require __DIR__ . '/partials/ai_demo_scenario_panel.php'; ?>
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
                <div data-frs-partial-id="bcf-calendar" data-frs-partial-root>
                <div class="bcf-cal-toolbar-wrap">
                    <div class="bcf-cal-month-heading"><?= htmlspecialchars($bookCalMonthLabel); ?></div>
                    <form method="get" action="<?= htmlspecialchars(base_path() . '/dashboard/book-facility'); ?>" class="bcf-cal-toolbar-form booking-cal-toolbar" data-frs-partial="bcf-calendar" data-frs-partial-auto>
                        <div class="bcf-cal-toolbar-grid">
                            <div class="bcf-cal-fac-field">
                                <label class="bcf-cal-fac-field-label" for="book-fac-cal-select">Facility</label>
                                <div class="bcf-cal-shell">
                                    <i class="bi bi-building" aria-hidden="true"></i>
                                    <select id="book-fac-cal-select" name="book_fac" class="bcf-cal-fac-select" aria-label="Choose facility for calendar">
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
                                <select name="month" class="bcf-cal-month-select" aria-label="Select month">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m; ?>" <?= $bookCalMonth === $m ? 'selected' : ''; ?>>
                                            <?= date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <select name="year" class="bcf-cal-year-select" aria-label="Select year">
                                    <?php 
                                    $currentYear = (int)date('Y');
                                    for ($y = $currentYear; $y <= $currentYear + 2; $y++): ?>
                                        <option value="<?= $y; ?>" <?= $bookCalYear === $y ? 'selected' : ''; ?>>
                                            <?= $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <a class="btn-outline bcf-cal-nav-btn" data-frs-partial="bcf-calendar" href="<?= htmlspecialchars(base_path() . '/dashboard/book-facility' . $bookCalQuery(array_merge($bookPaneQuery, ['year' => (int)date('Y'), 'month' => (int)date('n')]))); ?>">Today</a>
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
                            <div class="my-reservations-legend-item my-reservations-legend-demand" style="margin-left: 1rem; border-left: 1px solid #e2e8f0; padding-left: 1rem;">
                                <span style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Demand:</span>
                            </div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#dcfce7;"></span> Low</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fef3c7;"></span> Med</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fed7aa;"></span> High</div>
                            <div class="my-reservations-legend-item"><span class="my-reservations-legend-dot" style="background:#fee2e2;"></span> Very High</div>
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
                            $chipShort = '';
                            if ($iso < $todayISO) {
                                $chipLabel = '';
                            } elseif (!$bookFacilityPick) {
                                $chipLabel = '—';
                                $chipShort = '—';
                            } else {
                                if ($tone === 'green') {
                                    $dayStatusClass = ' status-approved';
                                    $chipLabel = 'Open';
                                    $chipShort = 'Open';
                                } elseif ($tone === 'yellow') {
                                    $dayStatusClass = ' status-pending';
                                    $chipLabel = 'Busy';
                                    $chipShort = 'Busy';
                                } elseif ($tone === 'red') {
                                    $dayStatusClass = ' status-denied';
                                    $chipLabel = 'Full';
                                    $chipShort = 'Full';
                                } elseif ($tone === 'blackout' || $tone === 'cimm_maintenance' || $tone === 'maintenance' || $tone === 'offline') {
                                    $dayStatusClass = $tone === 'cimm_maintenance' ? ' status-cimm-maintenance' : ' status-blackout';
                                    if ($tone === 'blackout') {
                                        $chipLabel = 'Blackout';
                                        $chipShort = 'Blk';
                                    } elseif ($tone === 'cimm_maintenance') {
                                        $chipLabel = 'Sched. maint.';
                                        $chipShort = 'Maint';
                                    } elseif ($tone === 'maintenance') {
                                        $chipLabel = 'Maintenance';
                                        $chipShort = 'Maint';
                                    } elseif ($tone === 'offline') {
                                        $chipLabel = 'Offline';
                                        $chipShort = 'Off';
                                    } else {
                                        $chipLabel = 'N/A';
                                        $chipShort = 'N/A';
                                    }
                                } elseif ($tone === 'muted') {
                                    $chipLabel = '—';
                                    $chipShort = '—';
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
                                    <div class="status-chip" title="<?= htmlspecialchars($chipLabel, ENT_QUOTES, 'UTF-8'); ?>"<?= $chipShort !== '' ? ' data-chip-short="' . htmlspecialchars($chipShort, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?= htmlspecialchars($chipLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if (isset($holidayMatrix[$iso])): ?>
                                    <div class="holiday-indicator" title="<?= htmlspecialchars($holidayMatrix[$iso]['name']); ?> (<?= htmlspecialchars($holidayMatrix[$iso]['type']); ?>)">
                                        <i class="bi bi-calendar-event"></i>
                                    </div>
                                <?php endif; ?>
                                <?php if (isset($demandForecastMatrix[$iso]) && $iso >= $todayISO): ?>
                                    <?php 
                                    $demand = $demandForecastMatrix[$iso];
                                    $demandClass = 'demand-low';
                                    if ($demand['score'] >= 76) $demandClass = 'demand-very-high';
                                    elseif ($demand['score'] >= 51) $demandClass = 'demand-high';
                                    elseif ($demand['score'] >= 26) $demandClass = 'demand-medium';
                                    ?>
                                    <div class="demand-strip <?= $demandClass; ?>" title="Demand: <?= $demand['score']; ?>% (<?= $demand['classification']; ?>)">
                                        <span class="demand-score"><?= $demand['score']; ?>%</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
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
            <?php if ($canBookOnBehalf): ?>
            <?php
            $walkInSelectedLabel = $walkInSelectedResident
                ? ($walkInSelectedResident['name'] . ' (' . $walkInSelectedResident['email'] . ')')
                : '';
            ?>
            <div class="bcf-walkin-box" id="bcf-walkin-picker" data-selected-label="<?= htmlspecialchars($walkInSelectedLabel, ENT_QUOTES, 'UTF-8'); ?>">
                <h4 class="bcf-label-row bcf-walkin-title">
                    Walk-in / assisted booking
                    <?= frs_field_tip('Create a reservation on behalf of a resident (barangay counter service). Search by name or email — only the first 10 matches load at a time.'); ?>
                </h4>
                <label>
                    Resident
                    <div class="bcf-walkin-combo">
                        <input type="hidden" name="book_for_user_id" id="book-for-user-id" value="<?= $selectedBookForUserId > 0 ? (int)$selectedBookForUserId : ''; ?>">
                        <div class="bcf-walkin-search-wrap">
                            <input type="search" id="bcf-walkin-search" class="bcf-walkin-search"
                                placeholder="Search resident by name or email…"
                                value="<?= htmlspecialchars($walkInSelectedLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                autocomplete="off" autocapitalize="off" spellcheck="false"
                                aria-autocomplete="list" aria-controls="bcf-walkin-list" aria-expanded="false">
                            <button type="button" id="bcf-walkin-clear" class="bcf-walkin-clear" aria-label="Clear selection"<?= $selectedBookForUserId > 0 ? '' : ' hidden'; ?>>&times;</button>
                        </div>
                        <ul id="bcf-walkin-list" class="bcf-walkin-list" role="listbox" hidden></ul>
                        <p class="bcf-walkin-hint" id="bcf-walkin-hint">Showing up to 10 residents. Type to search for others.</p>
                    </div>
                </label>
            </div>
            <?php endif; ?>
            <label>
                <span>Facility <span class="frs-required-mark" aria-hidden="true">*</span></span>
                <div class="input-wrapper">
                    <i class="bi bi-building input-icon"></i>
                    <select name="facility_id" id="facility-select" required>
                        <option value="">Select a facility...</option>
                        <?php foreach ($facilities as $facility):
                            $fid = (int)$facility['id'];
                            $upcomingCimm = $upcomingCimmByFacility[$fid] ?? null;
                            $upcomingLabel = $upcomingCimm ? frs_format_cimm_maintenance_window($upcomingCimm) : '';
                        ?>
                            <option value="<?= $facility['id']; ?>" 
                                    data-status="<?= htmlspecialchars($facility['status']); ?>"
                                    data-operating-hours="<?= htmlspecialchars($facility['operating_hours'] ?? ''); ?>"
                                    data-upcoming-cimm="<?= htmlspecialchars($upcomingLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-upcoming-cimm-reason="<?= htmlspecialchars($upcomingCimm['display_reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars($facility['name']); ?><?= $upcomingLabel !== '' ? ' — maintenance ' . $upcomingLabel : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p id="bcf-upcoming-cimm-notice" class="bcf-upcoming-cimm-notice" hidden role="status"></p>
            </label>


            <label>
                <span class="bcf-label-row">Reservation Date <span class="frs-required-mark" aria-hidden="true">*</span> <?= frs_field_tip('Chosen from the month grid on the booking page (not editable here).'); ?></span>
                <input type="hidden" name="reservation_date" id="reservation-date" value="">
                <div class="input-wrapper bcf-res-date-wrapper">
                    <i class="bi bi-calendar input-icon"></i>
                    <div id="bcf-reservation-date-display" class="bcf-res-date-readonly bcf-res-date-readonly-empty" role="status" aria-live="polite">Select a date on the calendar.</div>
                </div>
            </label>

            <label>
                <span>Start Time <span class="frs-required-mark" aria-hidden="true">*</span></span>
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
                <span>End Time <span class="frs-required-mark" aria-hidden="true">*</span></span>
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
                <p id="demand-prediction" style="margin:0.5rem 0 0.75rem; font-size:0.85rem; display:none;"></p>
                <div id="demand-alternatives" style="display:none;">
                    <p style="margin:0 0 0.5rem; font-size:0.85rem; font-weight:600;">Suggested alternatives (lower demand):</p>
                    <ul id="demand-alternatives-list" style="margin:0; padding-left:1.25rem; font-size:0.85rem;"></ul>
                </div>
                <span id="conflict-hint-wrap" style="display:none;"><?= frs_field_tip('Risk factors may include holidays/events, pending requests, and historical demand.'); ?></span>
            </div>

            <label>
                <span>Purpose of Use <span class="frs-required-mark" aria-hidden="true">*</span></span>
                <textarea name="purpose" id="purpose-input" rows="3" maxlength="<?= (int)FRS_BOOKING_PURPOSE_MAX; ?>" placeholder="e.g., Zumba class, Barangay General Assembly, Sports tournament" required></textarea>
                <p class="bcf-char-count" id="purpose-char-count" aria-live="polite">0 / <?= (int)FRS_BOOKING_PURPOSE_MAX; ?></p>
            </label>

            <label>
                <span>Expected Number of Attendees <span class="frs-required-mark" aria-hidden="true">*</span></span>
                <div class="input-wrapper">
                    <i class="bi bi-people input-icon"></i>
                    <input type="number" name="expected_attendees" id="expected-attendees" min="1" required inputmode="numeric" placeholder="e.g., 50">
                </div>
                <p id="bcf-capacity-msg" style="display:none; color:#b23030; margin:0.35rem 0 0; font-size:0.85rem;"></p>
            </label>

            <label>
                Notes for staff (optional, appended to purpose on save)
                <textarea name="booking_notes" id="booking-notes" rows="2" maxlength="<?= (int)FRS_BOOKING_NOTES_MAX; ?>" placeholder="Parking, setup time, accessibility, etc."></textarea>
                <p class="bcf-char-count" id="booking-notes-char-count" aria-live="polite">0 / <?= (int)FRS_BOOKING_NOTES_MAX; ?></p>
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

            <?php if ($canCreateReservations): ?>
            <div class="bcf-modal-sticky-actions">
                <button class="btn-primary" type="button" id="bcf-submit-booking">Submit Booking Request</button>
            </div>
            <?php else: ?>
            <div style="padding:1rem; background:#fff3cd; border:1px solid #ffc107; border-radius:8px; margin-top:1rem;">
                <p style="margin:0; color:#856404; font-size:0.9rem; font-weight:600;">
                    ⚠️ You do not have permission to create bookings. Please contact your administrator.
                </p>
            </div>
            <?php endif; ?>
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

<!-- Booking Confirmation Modal -->
<div id="bookingConfirmModal" class="modal-confirm" style="display: none; opacity: 0; visibility: hidden; z-index: 13000; position: fixed; inset: 0;">
    <div class="modal-dialog booking-confirm-dialog" style="z-index: 13001;">
        <div class="booking-confirm-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--gov-blue), var(--gov-blue-dark)); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-clipboard-check" style="font-size: 1.5rem; color: white;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; color: var(--gov-blue-dark); font-size: 1.25rem; font-weight: 700;">Confirm Booking</h3>
                    <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.875rem;">Review your reservation details</p>
                </div>
            </div>
            <button type="button" onclick="closeBookingConfirmModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; padding: 0.5rem; line-height: 1;" aria-label="Close">&times;</button>
        </div>

        <div class="booking-confirm-grid">
            <div class="booking-confirm-card">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-building" style="color: var(--gov-blue);"></i>
                    <span>Facility & Schedule</span>
                </div>
                <div class="booking-confirm-fields">
                    <div class="booking-confirm-field booking-confirm-field--full">
                        <span class="booking-confirm-label">Facility</span>
                        <span id="confirm-facility" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Date</span>
                        <span id="confirm-date" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Duration</span>
                        <span id="confirm-duration" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field booking-confirm-field--full">
                        <span class="booking-confirm-label">Time</span>
                        <span id="confirm-time" class="booking-confirm-value"></span>
                    </div>
                </div>
            </div>

            <div class="booking-confirm-card">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-file-text" style="color: var(--gov-blue);"></i>
                    <span>Event Details</span>
                </div>
                <div class="booking-confirm-fields booking-confirm-fields--single">
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Purpose</span>
                        <span id="confirm-purpose" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Attendees</span>
                        <span id="confirm-attendees" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Notes</span>
                        <span id="confirm-notes" class="booking-confirm-value"></span>
                    </div>
                </div>
            </div>

            <div class="booking-confirm-card">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-paperclip" style="color: var(--gov-blue);"></i>
                    <span>Supporting Document</span>
                </div>
                <div class="booking-confirm-fields booking-confirm-fields--single">
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Type</span>
                        <span id="confirm-doc-type" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">File</span>
                        <span id="confirm-doc-file" class="booking-confirm-value"></span>
                    </div>
                </div>
            </div>

            <div class="booking-confirm-card booking-confirm-card--cost">
                <div class="booking-confirm-card-title">
                    <i class="bi bi-currency-peso"></i>
                    <span>Cost Breakdown</span>
                </div>
                <div class="booking-confirm-fields booking-confirm-fields--single">
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Rate per Hour</span>
                        <span id="confirm-rate" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field">
                        <span class="booking-confirm-label">Hours</span>
                        <span id="confirm-hours" class="booking-confirm-value"></span>
                    </div>
                    <div class="booking-confirm-field" style="margin-top: 0.35rem; padding-top: 0.65rem; border-top: 1px solid rgba(255,255,255,0.25);">
                        <span class="booking-confirm-label">Total Cost</span>
                        <span id="confirm-total" class="booking-confirm-value booking-confirm-total"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="booking-confirm-actions">
            <button type="button" class="btn-outline" onclick="closeBookingConfirmModal()">Edit Booking</button>
            <button type="button" class="btn-primary" onclick="submitBooking()">Confirm & Submit</button>
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

function closeBookingConfirmModal() {
    const modal = document.getElementById('bookingConfirmModal');
    if (modal) {
        modal.style.display = 'none';
        modal.style.opacity = '0';
        modal.style.visibility = 'hidden';
        modal.classList.remove('open');
    }
    document.body.style.overflow = '';
    document.body.style.removeProperty('overflow');
}

function bcfEnsureBookingModalOpen() {
    if (typeof window.openBookingFlowModal === 'function') {
        window.openBookingFlowModal();
    }
}

function bcfCloseBookingFlowModalForValidation() {
    if (typeof window.closeBookingFlowModal === 'function') {
        window.closeBookingFlowModal();
    }
}

function bcfGetBookingValidationRules() {
    const purposeMax = <?= (int)FRS_BOOKING_PURPOSE_MAX; ?>;
    const notesMax = <?= (int)FRS_BOOKING_NOTES_MAX; ?>;
    const docValidId = document.querySelector('input[name="doc_valid_id"]');
    const modalFocusDelay = 380;

    return [
        {
            selector: '#reservation-date',
            focusSelector: '#bcf-reservation-date-display',
            test: function (el) { return !!(el && String(el.value || '').trim()); },
            message: 'Please choose a reservation date on the calendar.',
            beforeFocus: function () {
                bcfCloseBookingFlowModalForValidation();
                const cal = document.querySelector('.booking-calendar-myres-panel');
                if (cal) {
                    cal.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            },
            focusDelay: 120,
        },
        {
            selector: '#facility-select',
            focusSelector: '#facility-select',
            test: function (el) { return !!(el && String(el.value || '').trim()); },
            message: 'Please select a facility.',
            beforeFocus: bcfEnsureBookingModalOpen,
            focusDelay: modalFocusDelay,
        },
        {
            selector: '#start-time',
            focusSelector: '#bcf-start-time-trigger',
            test: function (el) { return !!(el && String(el.value || '').trim()); },
            message: 'Please select a start time.',
            beforeFocus: bcfEnsureBookingModalOpen,
            focusDelay: modalFocusDelay,
        },
        {
            selector: '#end-time',
            focusSelector: '#bcf-end-time-trigger',
            test: function (el) {
                const start = document.getElementById('start-time');
                const endVal = el ? String(el.value || '').trim() : '';
                const startVal = start ? String(start.value || '').trim() : '';
                if (!endVal || !startVal) return false;
                try {
                    return endVal > startVal;
                } catch (e) {
                    console.error('End time comparison error:', e);
                    return false;
                }
            },
            message: 'End time must be after start time.',
            beforeFocus: bcfEnsureBookingModalOpen,
            focusDelay: modalFocusDelay,
        },
        {
            selector: '#purpose-input',
            focusSelector: '#purpose-input',
            test: function (el) {
                const val = el ? String(el.value || '').trim() : '';
                return val.length > 0 && val.length <= purposeMax;
            },
            message: 'Please enter a purpose (' + purposeMax + ' characters max).',
            beforeFocus: bcfEnsureBookingModalOpen,
            focusDelay: modalFocusDelay,
        },
        {
            selector: '#expected-attendees',
            focusSelector: '#expected-attendees',
            test: function (el) {
                const val = el ? String(el.value || '').trim() : '';
                if (!val) return false;
                const n = parseInt(val, 10);
                return !isNaN(n) && n >= 1;
            },
            message: 'Please enter the expected number of attendees (at least 1).',
            beforeFocus: bcfEnsureBookingModalOpen,
            focusDelay: modalFocusDelay,
        },
        {
            selector: '#booking-notes',
            focusSelector: '#booking-notes',
            test: function (el) {
                const val = el ? String(el.value || '') : '';
                return val.length <= notesMax;
            },
            message: 'Notes for staff must be ' + notesMax + ' characters or fewer.',
            beforeFocus: bcfEnsureBookingModalOpen,
            focusDelay: modalFocusDelay,
        },
    ].concat(docValidId && docValidId.required ? [{
        selector: 'input[name="doc_valid_id"]',
        focusSelector: 'input[name="doc_valid_id"]',
        test: function (el) { return !!(el && el.files && el.files.length > 0); },
        message: 'Please upload a valid ID document.',
        beforeFocus: bcfEnsureBookingModalOpen,
        focusDelay: modalFocusDelay,
    }] : []);
}

function openBookingConfirmModal() {
    const form = document.getElementById('main-booking-form');
    if (!form) return;

    if (typeof window.frsFocusFirstInvalid === 'function') {
        const result = window.frsFocusFirstInvalid(bcfGetBookingValidationRules());
        if (!result.ok) {
            return;
        }
    }

    const facilitySelect = document.getElementById('facility-select');
    const dateInput = document.getElementById('reservation-date');
    const startTimeInput = document.getElementById('start-time');
    const endTimeInput = document.getElementById('end-time');
    const purposeInput = document.getElementById('purpose-input');
    const attendeesInput = document.getElementById('expected-attendees');
    const notesInput = document.getElementById('booking-notes');
    const docTypeSelect = document.querySelector('select[name="event_document_type"]');
    const docFileInput = document.querySelector('input[name="event_supporting_doc"]');

    // Calculate duration and cost
    const startTime = new Date(`2000-01-01T${startTimeInput.value}`);
    const endTime = new Date(`2000-01-01T${endTimeInput.value}`);
    const durationMs = endTime - startTime;
    const durationHours = durationMs / (1000 * 60 * 60);
    const selectedOption = facilitySelect.options[facilitySelect.selectedIndex];
    const facilityName = selectedOption.text;
    const facilityData = <?= $frsJsonForInlineScript($facilities); ?>;
    const facilityInfo = facilityData.find(f => f.id == facilitySelect.value);
    const ratePerHour = facilityInfo ? parseFloat(facilityInfo.base_rate) : 0;
    const totalCost = ratePerHour * durationHours;

    // Populate modal
    document.getElementById('confirm-facility').textContent = facilityName;
    document.getElementById('confirm-date').textContent = new Date(dateInput.value).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    document.getElementById('confirm-time').textContent = `${formatTime(startTimeInput.value)} - ${formatTime(endTimeInput.value)}`;
    document.getElementById('confirm-duration').textContent = `${durationHours.toFixed(1)} hour(s)`;
    document.getElementById('confirm-purpose').textContent = purposeInput ? purposeInput.value : '';
    document.getElementById('confirm-attendees').textContent = attendeesInput.value;
    document.getElementById('confirm-notes').textContent = notesInput.value || 'None';
    document.getElementById('confirm-doc-type').textContent = docTypeSelect ? docTypeSelect.options[docTypeSelect.selectedIndex].text : 'None';
    document.getElementById('confirm-doc-file').textContent = docFileInput.files.length > 0 ? docFileInput.files[0].name : 'None';
    document.getElementById('confirm-rate').textContent = `₱${ratePerHour.toFixed(2)}`;
    document.getElementById('confirm-hours').textContent = durationHours.toFixed(1);
    document.getElementById('confirm-total').textContent = `₱${totalCost.toFixed(2)}`;

    // Show modal
    const modal = document.getElementById('bookingConfirmModal');
    modal.style.display = 'flex';
    modal.style.opacity = '1';
    modal.style.visibility = 'visible';
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function formatTime(time24) {
    const [hours, minutes] = time24.split(':');
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
}

function submitBooking() {
    const form = document.getElementById('main-booking-form');
    if (form) {
        form.submit();
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
    const purposeInput = document.getElementById('purpose-input');
    const messageBox = document.getElementById('conflict-warning');
    const messageText = document.getElementById('conflict-message');
    const altWrap = document.getElementById('conflict-alternatives');
    const altList = document.getElementById('alternatives-list');
    const riskLine = document.getElementById('conflict-risk');
    const demandPrediction = document.getElementById('demand-prediction');
    const demandAlternativesWrap = document.getElementById('demand-alternatives');
    const demandAlternativesList = document.getElementById('demand-alternatives-list');

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
    const BCF_FIELD_SELECTORS = <?= json_encode($bookingFieldSelectors, JSON_UNESCAPED_SLASHES); ?>;
    const BCF_PURPOSE_MAX = <?= (int)FRS_BOOKING_PURPOSE_MAX; ?>;
    const BCF_NOTES_MAX = <?= (int)FRS_BOOKING_NOTES_MAX; ?>;

    function bcfBindCharCount(textarea, counterEl, max) {
        if (!textarea || !counterEl) {
            return;
        }
        function update() {
            const len = (textarea.value || '').length;
            counterEl.textContent = len + ' / ' + max;
            counterEl.classList.toggle('is-near-limit', len > max * 0.9);
            counterEl.classList.toggle('is-at-limit', len >= max);
        }
        textarea.addEventListener('input', update);
        update();
    }

    bcfBindCharCount(document.getElementById('purpose-input'), document.getElementById('purpose-char-count'), BCF_PURPOSE_MAX);
    bcfBindCharCount(document.getElementById('booking-notes'), document.getElementById('booking-notes-char-count'), BCF_NOTES_MAX);

    (function initWalkInResidentPicker() {
        const root = document.getElementById('bcf-walkin-picker');
        if (!root) return;

        const hidden = document.getElementById('book-for-user-id');
        const search = document.getElementById('bcf-walkin-search');
        const list = document.getElementById('bcf-walkin-list');
        const hint = document.getElementById('bcf-walkin-hint');
        const clearBtn = document.getElementById('bcf-walkin-clear');
        const apiUrl = basePath + '/dashboard/walk-in-residents-api';
        let debounceTimer = null;
        let selectedLabel = root.dataset.selectedLabel || '';

        function setSelection(id, label) {
            hidden.value = id ? String(id) : '';
            search.value = label || '';
            selectedLabel = label || '';
            if (clearBtn) clearBtn.hidden = !id;
            list.hidden = true;
            search.setAttribute('aria-expanded', 'false');
        }

        function renderResults(residents, hasMore, query) {
            list.innerHTML = '';

            const selfLi = document.createElement('li');
            selfLi.className = 'bcf-walkin-option bcf-walkin-option-self';
            selfLi.setAttribute('role', 'option');
            selfLi.dataset.id = '';
            selfLi.textContent = '— Book for myself (staff/admin) —';
            selfLi.addEventListener('mousedown', function (e) {
                e.preventDefault();
                setSelection('', '');
            });
            list.appendChild(selfLi);

            (residents || []).forEach(function (r) {
                const label = r.name + ' (' + r.email + ')';
                const li = document.createElement('li');
                li.className = 'bcf-walkin-option';
                li.setAttribute('role', 'option');
                li.dataset.id = String(r.id);
                li.textContent = label;
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    setSelection(r.id, label);
                });
                list.appendChild(li);
            });

            if (hasMore) {
                const more = document.createElement('li');
                more.className = 'bcf-walkin-more';
                more.textContent = 'More residents available — refine your search.';
                list.appendChild(more);
            } else if (!residents.length && query) {
                const empty = document.createElement('li');
                empty.className = 'bcf-walkin-empty';
                empty.textContent = 'No matching residents found.';
                list.appendChild(empty);
            }

            list.hidden = false;
            search.setAttribute('aria-expanded', 'true');

            if (hint) {
                hint.textContent = query
                    ? (residents.length ? 'Search results (max ' + residents.length + ').' : 'No matches for that search.')
                    : 'Showing first 10 residents alphabetically. Type to search others.';
            }
        }

        function loadResidents(query) {
            const params = new URLSearchParams({ limit: '10' });
            if (query) params.set('q', query);
            fetch(apiUrl + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'FRS-Dashboard' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) return;
                    renderResults(data.residents || [], !!data.has_more, query);
                })
                .catch(function () { /* ignore */ });
        }

        search.addEventListener('focus', function () {
            loadResidents(search.value.trim());
        });

        search.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = search.value.trim();
            if (q !== selectedLabel) {
                hidden.value = '';
                if (clearBtn) clearBtn.hidden = true;
            }
            debounceTimer = setTimeout(function () {
                loadResidents(q);
            }, 280);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                setSelection('', '');
                search.focus();
                loadResidents('');
            });
        }

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                list.hidden = true;
                search.setAttribute('aria-expanded', 'false');
            }
        });
    })();

    const bookingErr = document.querySelector('.booking-error[data-error-field]');
    if (bookingErr && bookingErr.dataset.errorField && window.frsFocusByFieldKey) {
        setTimeout(function () {
            if (BCF_OPEN_ON_LOAD && typeof window.openBookingFlowModal === 'function') {
                window.openBookingFlowModal();
            }
            window.frsFocusByFieldKey(bookingErr.dataset.errorField, BCF_FIELD_SELECTORS);
        }, BCF_OPEN_ON_LOAD ? 350 : 120);
    }
    const bookCalFacilityId = <?= (int)$bookFacilityPick; ?>;
    const BCF_CAL_YEAR = <?= (int)$bookCalYear; ?>;
    const BCF_CAL_MONTH = <?= (int)$bookCalMonth; ?>;
    window._bcfCalYear = BCF_CAL_YEAR;
    window._bcfCalMonth = BCF_CAL_MONTH;

    function bcfSyncCalFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const y = parseInt(params.get('year') || '', 10);
        const m = parseInt(params.get('month') || '', 10);
        if (!isNaN(y) && y >= 2020 && y <= 2100) window._bcfCalYear = y;
        if (!isNaN(m) && m >= 1 && m <= 12) window._bcfCalMonth = m;
    }
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
            const u = basePath + '/dashboard/book-facility?year=' + encodeURIComponent(String(window._bcfCalYear)) + '&month=' + encodeURIComponent(String(window._bcfCalMonth)) + '&book_fac=' + encodeURIComponent(String(payload.primary_facility_id));
            html += '<div class="bcf-smart-hints-actions"><a class="btn-outline bcf-smart-hints-link" data-frs-partial="bcf-calendar" href="' + u + '">Show this facility on the calendar</a></div>';
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
        fd.append('year', String(window._bcfCalYear));
        fd.append('month', String(window._bcfCalMonth));
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

    const BCF_USER_ID = <?= (int)($_SESSION['user_id'] ?? 0); ?>;
    const BCF_PURPOSE_KEY = 'bcf_booking_purpose_' + BCF_USER_ID;

    const bcfPurposePreview = document.getElementById('bcf-purpose-preview');
    const bcfPurposeAttPrev = document.getElementById('bcf-purpose-attendees-preview');
    if (bcfPurposePreview) {
        try {
            const saved = sessionStorage.getItem(BCF_PURPOSE_KEY);
            if (saved && !bcfPurposePreview.value) {
                bcfPurposePreview.value = saved;
                const pi0 = document.getElementById('purpose-input');
                if (pi0 && !pi0.value) pi0.value = saved;
            }
        } catch (e) { /* ignore */ }
        bcfPurposePreview.addEventListener('input', function () {
            try { sessionStorage.setItem(BCF_PURPOSE_KEY, this.value); } catch (e) { /* ignore */ }
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
        // Only disable button for hard conflicts (facility fully booked), not for missing required fields
        // Validation will handle missing fields on click
        btn.disabled = !!window._bcfLastConflictHard;
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

    const bookingConfirmModal = document.getElementById('bookingConfirmModal');
    /* Reparent confirmation modal to body to ensure it appears above booking modal */
    if (bookingConfirmModal && bookingConfirmModal.parentNode !== document.body) {
        document.body.appendChild(bookingConfirmModal);
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
    window.openBookingFlowModal = openBookingFlowModal;
    window.closeBookingFlowModal = closeBookingFlowModal;

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

            if (facility.image_url) {
                html += '<div class="bcf-facility-image-wrap">';
                html += '<img class="bcf-facility-image" src="' + escapeHtml(facility.image_url) + '" alt="' + escapeHtml(facility.name || 'Facility') + ' photo">';
                if (facility.image_citation) {
                    html += '<div class="bcf-facility-image-citation" title="Image source">📷 ' + escapeHtml(facility.image_citation) + '</div>';
                }
                html += '</div>';
            }
            
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
            if (demandPrediction) {
                demandPrediction.style.display = 'none';
                demandPrediction.textContent = '';
            }
            if (demandAlternativesWrap) {
                demandAlternativesWrap.style.display = 'none';
                demandAlternativesList.innerHTML = '';
            }
            window._bcfLastConflictHard = false;
            refreshBookingGates();
        }, 300);
    }

    function showDemandPrediction(data) {
        if (!demandPrediction || !data.demand_score) return;
        
        const score = data.demand_score;
        const classification = data.demand_classification;
        const confidence = data.demand_confidence;
        
        let color = '#166534';
        let label = 'Low Demand';
        
        if (score >= 76) {
            color = '#dc2626';
            label = 'Very High Demand';
        } else if (score >= 51) {
            color = '#9a3412';
            label = 'High Demand';
        } else if (score >= 26) {
            color = '#92400e';
            label = 'Medium Demand';
        }
        
        demandPrediction.style.display = 'block';
        demandPrediction.style.color = color;
        demandPrediction.innerHTML = `<strong>Predicted Demand:</strong> ${score}% (${label})`;
        
        if (confidence) {
            demandPrediction.innerHTML += ` • Confidence: ${confidence}%`;
        }
        
        // Show alternative suggestions if demand is high
        if (score >= 50 && data.demand_alternatives && data.demand_alternatives.length > 0) {
            if (demandAlternativesWrap && demandAlternativesList) {
                demandAlternativesWrap.style.display = 'block';
                demandAlternativesList.innerHTML = '';
                
                data.demand_alternatives.slice(0, 3).forEach(alt => {
                    const li = document.createElement('li');
                    const altDate = new Date(alt.date);
                    const formattedDate = altDate.toLocaleDateString('en-US', { 
                        weekday: 'short', 
                        month: 'short', 
                        day: 'numeric' 
                    });
                    li.innerHTML = `<strong>${formattedDate}</strong> • ${alt.time_slot} (${alt.score}% ${alt.classification}) - ${alt.reason}`;
                    demandAlternativesList.appendChild(li);
                });
            }
        } else {
            if (demandAlternativesWrap) {
                demandAlternativesWrap.style.display = 'none';
            }
        }
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
            // High risk - red/warning
            messageBox.style.background = '#fee2e2';
            messageBox.style.border = '2px solid #dc2626';
            messageText.style.color = '#dc2626';
            if (conflictIcon) conflictIcon.textContent = '⚠️';
            if (conflictTitle) conflictTitle.textContent = 'High Demand Period';
            if (conflictTitle) conflictTitle.style.color = '#dc2626';
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
        if (demandPrediction) demandPrediction.style.display = 'none';
        if (demandAlternativesWrap) demandAlternativesWrap.style.display = 'none';
        
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
                showDemandPrediction(data);
                lastShown = {fid, date, timeSlot};
            } 
            // High risk or holiday
            else if ((data.risk_score ?? 0) >= 50 || eventLabel) {
                window._bcfLastConflictHard = false;
                const msg = eventLabel
                    ? `Higher demand expected (${eventLabel}). Consider alternative slots.`
                    : 'Higher demand expected. Consider alternative slots.';
                showMessage(msg, data.alternatives || [], data.risk_score ?? null, eventLabel, 'risk');
                showDemandPrediction(data);
                lastShown = {fid, date, timeSlot};
            } else {
                window._bcfLastConflictHard = false;
                // No conflicts - show success message
                const successMsg = eventLabel 
                    ? `Slot is available! Note: ${eventLabel} may increase demand.`
                    : '✓ This time slot is available for booking!';
                showMessage(successMsg, [], data.risk_score ?? null, eventLabel, 'success');
                showDemandPrediction(data);
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

    function bcfUpdateUpcomingCimmNotice() {
        const notice = document.getElementById('bcf-upcoming-cimm-notice');
        if (!notice || !facilitySel) {
            return;
        }
        const opt = facilitySel.options[facilitySel.selectedIndex];
        if (!opt || !opt.value) {
            notice.hidden = true;
            notice.textContent = '';
            return;
        }
        const windowLabel = (opt.getAttribute('data-upcoming-cimm') || '').trim();
        const reason = (opt.getAttribute('data-upcoming-cimm-reason') || 'Scheduled maintenance').trim();
        if (!windowLabel) {
            notice.hidden = true;
            notice.textContent = '';
            return;
        }
        notice.hidden = false;
        notice.textContent = 'Upcoming CIMM maintenance (' + windowLabel + '): ' + reason
            + '. Those dates are blocked on the calendar below even while this facility still shows Available.';
    }

    // Add event listeners for both 'change' and 'input' events
    // 'change' fires on blur, 'input' fires as user types/selects
    if (facilitySel) {
        facilitySel.addEventListener('change', function () {
            bcfUpdateUpcomingCimmNotice();
            debouncedCheckConflict();
        });
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
    
    purposeInput?.addEventListener('input', function () {
        const pv = document.getElementById('bcf-purpose-preview');
        if (pv) pv.value = this.value;
        try { sessionStorage.setItem(BCF_PURPOSE_KEY, this.value); } catch (e) { /* ignore */ }
        if (typeof bcfDebouncedSmartHints === 'function') {
            bcfDebouncedSmartHints();
        }
    });
    
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
        bcfUpdateUpcomingCimmNotice();
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
    const prefillPurposePreview = document.getElementById('bcf-purpose-preview');
    const prefillAttendeesPreview = document.getElementById('bcf-purpose-attendees-preview');
    if (prePurpose && prefillPurposePreview) prefillPurposePreview.value = prePurpose;
    if (preAttendees && prefillAttendeesPreview) prefillAttendeesPreview.value = preAttendees;

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

    document.getElementById('bcf-submit-booking')?.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (typeof window.frsFocusFirstInvalid === 'function') {
            const result = window.frsFocusFirstInvalid(bcfGetBookingValidationRules());
            if (!result.ok) {
                return;
            }
        }
        const attEl = document.getElementById('expected-attendees');
        const attRaw = attEl ? (attEl.value || '').trim() : '';
        const n = parseInt(attRaw, 10);
        const maxC = window._bcfMaxFacilityCapacity;
        if (maxC != null && !isNaN(maxC) && n > maxC) {
            if (window.frsFocusBySelector) {
                window.frsFocusBySelector('#expected-attendees');
            }
            return;
        }
        openBookingConfirmModal();
    });

    document.getElementById('main-booking-form')?.addEventListener('submit', function (ev) {
        if (typeof window.frsFocusFirstInvalid === 'function') {
            const result = window.frsFocusFirstInvalid(bcfGetBookingValidationRules());
            if (!result.ok) {
                ev.preventDefault();
                return;
            }
        }
        const attEl = document.getElementById('expected-attendees');
        const attRaw = attEl ? (attEl.value || '').trim() : '';
        const n = parseInt(attRaw, 10);
        const maxC = window._bcfMaxFacilityCapacity;
        if (maxC != null && !isNaN(maxC) && n > maxC) {
            ev.preventDefault();
            if (window.frsFocusBySelector) {
                window.frsFocusBySelector('#expected-attendees');
            }
        }
    });

    const bookPane = document.getElementById('booking-pane-book');
    if (bookPane && !bookPane.dataset.bcfCalDelegated) {
        bookPane.dataset.bcfCalDelegated = '1';
        function activateBookingCalDate(cell) {
            const ds = cell.getAttribute('data-bcf-date');
            if (!ds || !dateInput || !facilitySel) return;
            dateInput.value = ds;
            dateInput.dispatchEvent(new Event('input', { bubbles: true }));
            dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            const calSel = document.getElementById('book-fac-cal-select');
            if (calSel && calSel.value) {
                facilitySel.value = calSel.value;
                facilitySel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            openBookingFlowModal();
            debouncedRefillAvail();
            setTimeout(debouncedCheckConflict, 200);
        }
        bookPane.addEventListener('click', function (e) {
            const cell = e.target.closest('.bcf-book-cal-cell');
            if (cell) activateBookingCalDate(cell);
        });
        bookPane.addEventListener('keydown', function (e) {
            const cell = e.target.closest('.bcf-book-cal-cell');
            if (!cell) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activateBookingCalDate(cell);
            }
        });
    }

    window.frsOnPartialLoaded = function (partialId) {
        if (partialId !== 'bcf-calendar') return;
        bcfSyncCalFromUrl();
        const params = new URLSearchParams(window.location.search);
        const bookFac = params.get('book_fac');
        const calSel = document.getElementById('book-fac-cal-select');
        if (bookFac && calSel) {
            calSel.value = bookFac;
        }
        if (calSel && calSel.value && typeof loadFacilityDetails === 'function') {
            loadFacilityDetails(calSel.value);
        }
        if (typeof bcfDebouncedSmartHints === 'function') {
            bcfDebouncedSmartHints();
        }
    };

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
