<?php
/**
 * Chatbot-driven booking: slot filling, final review, and submission.
 *
 * The model only ever proposes slot values. This file owns the decisions —
 * what is still missing, whether the booking can be completed in chat at all,
 * and whether it is valid — so a hallucinated field can never become a
 * reservation. The confirm step re-validates the whole payload from scratch
 * and takes the resident's identity from the session/token, never the payload.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/reservation_helpers.php';
require_once __DIR__ . '/auto_approval.php';

/** Slots a booking cannot be submitted without. */
function frs_chatbot_required_slots(): array
{
    return ['facility_id', 'reservation_date', 'start_time', 'end_time', 'purpose', 'expected_attendees'];
}

/** Human labels for the slots, used when asking the resident for what's missing. */
function frs_chatbot_slot_labels(): array
{
    return [
        'facility_id' => 'facility',
        'reservation_date' => 'date',
        'start_time' => 'start time',
        'end_time' => 'end time',
        'purpose' => 'purpose',
        'expected_attendees' => 'number of attendees',
    ];
}

/**
 * Normalise whatever the model proposed into clean slot values, dropping
 * anything malformed so a bad value reads as "still missing" rather than
 * silently becoming part of a booking.
 *
 * @return array{slots: array<string,mixed>, missing: list<string>}
 */
function frs_chatbot_normalise_slots(array $data): array
{
    $slots = [];

    $facilityId = (int) ($data['facility_id'] ?? 0);
    if ($facilityId > 0) {
        $slots['facility_id'] = $facilityId;
    }

    $date = trim((string) ($data['reservation_date'] ?? ''));
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $slots['reservation_date'] = $date;
    }

    foreach (['start_time', 'end_time'] as $key) {
        $time = trim((string) ($data[$key] ?? ''));
        if ($time !== '' && preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $slots[$key] = substr('0' . $time, -5);
        }
    }

    // Some replies give a combined "HH:MM - HH:MM" slot instead of the two parts.
    $timeSlot = trim((string) ($data['time_slot'] ?? ''));
    if ($timeSlot !== '' && (!isset($slots['start_time']) || !isset($slots['end_time']))) {
        $parts = array_map('trim', explode('-', $timeSlot));
        if (count($parts) === 2) {
            foreach (['start_time' => $parts[0], 'end_time' => $parts[1]] as $key => $value) {
                if (!isset($slots[$key]) && preg_match('/^\d{1,2}:\d{2}$/', $value)) {
                    $slots[$key] = substr('0' . $value, -5);
                }
            }
        }
    }

    $purpose = trim((string) ($data['purpose'] ?? ''));
    if ($purpose !== '') {
        $slots['purpose'] = $purpose;
    }

    $attendees = $data['expected_attendees'] ?? $data['attendees'] ?? null;
    if ($attendees !== null && (int) $attendees > 0) {
        $slots['expected_attendees'] = (int) $attendees;
    }

    $missing = [];
    foreach (frs_chatbot_required_slots() as $slot) {
        if (!isset($slots[$slot])) {
            $missing[] = $slot;
        }
    }

    return ['slots' => $slots, 'missing' => $missing];
}

/**
 * Reasons a booking cannot be finished inside the chat. Each of these needs a
 * file upload, which only the booking form can do — the resident is handed off
 * there with the slots prefilled rather than being told "no".
 *
 * @return string|null Null when the chat can complete the booking.
 */
function frs_chatbot_handoff_reason(PDO $pdo, int $userId, int $facilityId): ?string
{
    $identity = frs_resident_identity_allows_booking($pdo, $userId);
    if (empty($identity['ok'])) {
        return 'valid_id';
    }

    $stmt = $pdo->prepare('SELECT role, is_culiat_resident FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $exemptFromReferral = !empty($user['is_culiat_resident'])
        || in_array((string) ($user['role'] ?? ''), ['Staff', 'Admin'], true);
    if (!$exemptFromReferral) {
        return 'referral';
    }

    $stmt = $pdo->prepare('SELECT requires_document FROM facilities WHERE id = ? LIMIT 1');
    $stmt->execute([$facilityId]);
    if (!empty($stmt->fetchColumn())) {
        return 'facility_document';
    }

    return null;
}

/** Resident-facing explanation for each handoff reason. */
function frs_chatbot_handoff_message(string $reason): string
{
    return match ($reason) {
        'valid_id' => 'Kailangan mo munang mag-upload ng valid ID bago makapag-book. Buksan ko na ang booking form para sa iyo.',
        'referral' => 'Dahil hindi ka rehistradong residente ng Barangay Culiat, kailangan ng referral at ID nito. Buksan ko na ang booking form para maupload mo.',
        'facility_document' => 'Kailangan ng supporting document ang pasilidad na ito. Buksan ko na ang booking form para maupload mo.',
        default => 'Buksan ko na ang booking form para tapusin ang booking.',
    };
}

/**
 * Decide what should happen with the slots the model proposed.
 *
 * @return array{action:string,...}|null Null when there is nothing booking-related to do.
 */
function frs_chatbot_booking_state(PDO $pdo, int $userId, array $data): ?array
{
    $normalised = frs_chatbot_normalise_slots($data);
    $slots = $normalised['slots'];
    $missing = $normalised['missing'];

    if ($slots === []) {
        return null;
    }

    if ($missing !== []) {
        $labels = frs_chatbot_slot_labels();
        return [
            'action' => 'booking_incomplete',
            'slots' => $slots,
            'missing' => $missing,
            'missing_labels' => array_values(array_map(
                static fn (string $slot) => $labels[$slot] ?? $slot,
                $missing
            )),
        ];
    }

    $handoff = frs_chatbot_handoff_reason($pdo, $userId, (int) $slots['facility_id']);
    if ($handoff !== null) {
        return [
            'action' => 'booking_needs_form',
            'reason' => $handoff,
            'message' => frs_chatbot_handoff_message($handoff),
            'slots' => $slots,
        ];
    }

    // Dry-run the real validator so the resident is told about a conflict or a
    // booking-limit breach before being asked to confirm, not after.
    $timeSlot = $slots['start_time'] . ' - ' . $slots['end_time'];
    $check = frs_validate_resident_booking_request($pdo, $userId, [
        'facility_id' => $slots['facility_id'],
        'reservation_date' => $slots['reservation_date'],
        'time_slot' => $timeSlot,
        'purpose' => $slots['purpose'],
        'expected_attendees' => $slots['expected_attendees'],
    ]);

    if (empty($check['ok'])) {
        return [
            'action' => 'booking_rejected',
            'message' => (string) ($check['message'] ?? 'Hindi matuloy ang booking.'),
            'slots' => $slots,
        ];
    }

    $facility = $check['facility'] ?? [];

    return [
        'action' => 'booking_review',
        'slots' => $slots,
        'review' => [
            'facility_id' => (int) $slots['facility_id'],
            'facility_name' => (string) ($facility['name'] ?? ('Facility #' . $slots['facility_id'])),
            'reservation_date' => $slots['reservation_date'],
            'time_slot' => $timeSlot,
            'purpose' => $slots['purpose'],
            'expected_attendees' => (int) $slots['expected_attendees'],
            'is_free' => !empty($facility['is_free']),
        ],
    ];
}

/**
 * Create the reservation the resident confirmed.
 *
 * Everything is validated again here — this is the only place that writes, and
 * it trusts nothing from the client except the slot values, which it re-checks.
 *
 * @return array{ok:bool,message:string,reservation_id?:int,status?:string,error?:string,http?:int}
 */
function frs_chatbot_create_reservation(PDO $pdo, int $userId, array $payload): array
{
    $normalised = frs_chatbot_normalise_slots($payload);
    $slots = $normalised['slots'];
    if ($normalised['missing'] !== []) {
        return ['ok' => false, 'message' => 'Kulang pa ang detalye ng booking.', 'error' => 'incomplete', 'http' => 422];
    }

    $facilityId = (int) $slots['facility_id'];
    $date = $slots['reservation_date'];
    $timeSlot = $slots['start_time'] . ' - ' . $slots['end_time'];
    $purpose = $slots['purpose'];
    $attendees = (int) $slots['expected_attendees'];

    $handoff = frs_chatbot_handoff_reason($pdo, $userId, $facilityId);
    if ($handoff !== null) {
        return [
            'ok' => false,
            'message' => frs_chatbot_handoff_message($handoff),
            'error' => 'needs_form',
            'http' => 422,
        ];
    }

    $check = frs_validate_resident_booking_request($pdo, $userId, [
        'facility_id' => $facilityId,
        'reservation_date' => $date,
        'time_slot' => $timeSlot,
        'purpose' => $purpose,
        'expected_attendees' => $attendees,
    ]);
    if (empty($check['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($check['message'] ?? 'Booking validation failed.'),
            'error' => (string) ($check['error'] ?? 'validation'),
            'http' => (int) ($check['http'] ?? 400),
        ];
    }

    $facility = $check['facility'] ?? [];
    $advanceDays = (int) (frs_resident_booking_limit_config()['advance_max_days'] ?? 60);
    $auto = evaluateAutoApproval($facilityId, $date, $timeSlot, $attendees, false, $userId, $advanceDays);
    $autoApprovedByRules = !empty($auto['auto_approve']);

    $paymentsEnabled = false;
    $requirePayment = false;
    $paymentWindow = 60;
    $paymentsCfgPath = __DIR__ . '/payments.php';
    if (file_exists($paymentsCfgPath)) {
        $paymentsCfg = require $paymentsCfgPath;
        if (is_array($paymentsCfg)) {
            $paymentsEnabled = !empty($paymentsCfg['enabled']);
            $requirePayment = !empty($paymentsCfg['require_payment_for_reservations']);
            $candidateWindow = (int) ($paymentsCfg['payment_window_minutes'] ?? 60);
            if ($candidateWindow > 0) {
                $paymentWindow = $candidateWindow;
            }
        }
    }

    $hybridPaymentMode = $paymentsEnabled && $requirePayment && empty($facility['is_free']);
    if ($hybridPaymentMode) {
        $status = $autoApprovedByRules ? 'pending_payment' : 'pending';
        $isAutoApproved = false;
    } else {
        $status = $autoApprovedByRules ? 'approved' : 'pending';
        $isAutoApproved = $autoApprovedByRules;
    }

    $paymentDueAt = date('Y-m-d H:i:s', strtotime('+' . $paymentWindow . ' minutes'));
    $expiresAt = $status === 'pending_payment'
        ? $paymentDueAt
        : ($status === 'pending' ? date('Y-m-d H:i:s', strtotime('+' . frs_pending_expiry_hours() . ' hours')) : null);

    try {
        $pdo->beginTransaction();
        if (function_exists('frs_lock_facility_for_booking')) {
            frs_lock_facility_for_booking($pdo, $facilityId);
        }
        if (function_exists('detectBookingConflict')) {
            $conflict = detectBookingConflict($facilityId, $date, $timeSlot);
            if (!empty($conflict['has_conflict'])) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'message' => (string) ($conflict['message'] ?? 'May nauna nang reservation sa oras na iyon.'),
                    'error' => 'conflict',
                    'http' => 409,
                ];
            }
        }

        $cols = ['user_id', 'facility_id', 'reservation_date', 'time_slot', 'purpose', 'status', 'expected_attendees', 'is_commercial', 'auto_approved'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', '0', '?'];
        $values = [$userId, $facilityId, $date, $timeSlot, $purpose, $status, $attendees, $isAutoApproved ? 1 : 0];

        foreach (['payment_due_at' => $paymentDueAt, 'expires_at' => $expiresAt] as $column => $value) {
            try {
                $pdo->query("SELECT {$column} FROM reservations LIMIT 1");
                $cols[] = $column;
                $placeholders[] = '?';
                $values[] = $value;
            } catch (Throwable $e) {
                // Column not present on this deployment.
            }
        }

        $pdo->prepare(
            'INSERT INTO reservations (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
        )->execute($values);
        $reservationId = (int) $pdo->lastInsertId();

        $historyNote = match ($status) {
            'pending_payment' => 'Auto-approved by rules via AI assistant. Awaiting payment.',
            'approved' => 'Automatically approved via AI assistant.',
            default => 'Submitted via AI assistant. Pending staff review.',
        };
        $pdo->prepare(
            'INSERT INTO reservation_history (reservation_id, status, note, created_by) VALUES (?, ?, ?, ?)'
        )->execute([$reservationId, $status === 'pending_payment' ? 'pending' : $status, $historyNote, $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Chatbot booking failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Hindi na-save ang reservation. Pakisubukan ulit.', 'error' => 'book_failed', 'http' => 500];
    }

    $facilityName = (string) ($facility['name'] ?? 'facility');
    if (file_exists(__DIR__ . '/notifications.php')) {
        require_once __DIR__ . '/notifications.php';
        if (function_exists('createNotification')) {
            $title = match ($status) {
                'pending_payment' => 'Payment required',
                'approved' => 'Reservation approved',
                default => 'Reservation submitted',
            };
            $body = match ($status) {
                'pending_payment' => 'Your hold for ' . $facilityName . ' is ready. Complete payment to secure the slot.',
                'approved' => 'Your booking for ' . $facilityName . ' is confirmed.',
                default => 'Your request for ' . $facilityName . ' is pending staff review.',
            };
            createNotification(
                $userId,
                'booking',
                $title,
                $body,
                base_path() . '/dashboard/reservation-detail?id=' . $reservationId
            );
        }
    }

    $message = match ($status) {
        'pending_payment' => 'Na-hold ang ' . $facilityName . '. Kumpletuhin ang bayad para ma-secure ang slot.',
        'approved' => 'Kumpirmado na ang booking mo sa ' . $facilityName . '!',
        default => 'Naisumite na ang request mo sa ' . $facilityName . '. Hintayin ang review ng staff.',
    };

    return [
        'ok' => true,
        'message' => $message,
        'reservation_id' => $reservationId,
        'status' => $status,
    ];
}
