<?php
require_once __DIR__ . '/app.php';

function paymongoConfig(): array
{
    $paymentsPath = __DIR__ . '/payments.php';
    if (file_exists($paymentsPath)) {
        $payments = require $paymentsPath;
        if (is_array($payments)) {
            $pm = $payments['paymongo'] ?? [];
            return [
                'enabled' => !empty($payments['enabled']),
                'currency' => strtoupper((string)($payments['currency'] ?? 'PHP')),
                'secret_key' => (string)($pm['secret_key'] ?? ''),
                'webhook_secret' => (string)($pm['webhook_secret'] ?? ''),
                'api_version' => (string)($pm['api_version'] ?? 'v2'),
            ];
        }
    }

    // Backward compatibility for alternate config file name.
    $legacyPath = __DIR__ . '/paymongo.php';
    if (file_exists($legacyPath)) {
        $cfg = require $legacyPath;
        return is_array($cfg) ? $cfg : [];
    }
    return [];
}

function paymongoEnabled(): bool
{
    $cfg = paymongoConfig();
    return !empty($cfg['enabled']) && !empty(trim((string)($cfg['secret_key'] ?? '')));
}

function paymongoApiVersion(): string
{
    $version = strtolower(trim((string)(paymongoConfig()['api_version'] ?? 'v2')));
    return in_array($version, ['v1', 'v2'], true) ? $version : 'v2';
}

function paymongoApiBaseUrl(): string
{
    return 'https://api.paymongo.com/' . paymongoApiVersion() . '/';
}

/**
 * Public return URLs for PayMongo Hosted Checkout (must return HTTP 200 when fetched).
 */
function frs_paymongo_return_url(int $reservationId, string $outcome): string
{
    return base_url() . '/payment-return?reservation_id=' . $reservationId . '&payment=' . rawurlencode($outcome);
}

function paymongoRequest(string $method, string $endpoint, array $payload = [], ?string $apiVersion = null): array
{
    $cfg = paymongoConfig();
    $secretKey = trim((string)($cfg['secret_key'] ?? ''));
    if ($secretKey === '') {
        return ['ok' => false, 'error' => 'PayMongo secret key is not configured.'];
    }

    $version = $apiVersion ?? paymongoApiVersion();
    if (!in_array($version, ['v1', 'v2'], true)) {
        $version = paymongoApiVersion();
    }

    $url = 'https://api.paymongo.com/' . $version . '/' . ltrim($endpoint, '/');
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secretKey . ':'),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if (!empty($payload) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        return ['ok' => false, 'error' => 'PayMongo request failed: ' . $err];
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Invalid PayMongo response.', 'http' => $http];
    }

    if ($http < 200 || $http >= 300) {
        $detail = $json['errors'][0]['detail'] ?? null;
        $msg = $detail ? (string)$detail : ('HTTP ' . $http);
        if ($http === 404 && stripos($msg, 'non-200') !== false) {
            $msg .= ' Verify APP_URL is set to your public HTTPS site URL and that /payment-return is reachable.';
        }
        return ['ok' => false, 'error' => $msg, 'http' => $http, 'response' => $json];
    }

    return ['ok' => true, 'data' => $json];
}

function paymongoCreateCheckoutSession(array $params): array
{
    return paymongoRequest('POST', 'checkout_sessions', [
        'data' => [
            'attributes' => $params,
        ],
    ]);
}

function paymongoRetrieveEvent(string $eventId): array
{
    return paymongoRequest('GET', 'events/' . rawurlencode($eventId));
}

function paymongoRetrieveCheckoutSession(string $checkoutId): array
{
    $primary = paymongoApiVersion();
    $versions = array_values(array_unique([$primary, $primary === 'v2' ? 'v1' : 'v2']));

    $last = ['ok' => false, 'error' => 'Unable to retrieve checkout session.'];
    foreach ($versions as $version) {
        $resp = paymongoRequest('GET', 'checkout_sessions/' . rawurlencode($checkoutId), [], $version);
        if (!empty($resp['ok'])) {
            $resp['api_version'] = $version;
            return $resp;
        }
        $last = $resp;
        if ((int)($resp['http'] ?? 0) !== 404) {
            break;
        }
    }

    return $last;
}

/**
 * Normalize checkout session JSON from PayMongo retrieve/create responses.
 *
 * @return array<string, mixed>
 */
function paymongoCheckoutSessionResource(array $apiJson): array
{
    if (isset($apiJson['data']['attributes']) && is_array($apiJson['data']['attributes'])) {
        return $apiJson['data'];
    }
    if (isset($apiJson['attributes']) && is_array($apiJson['attributes'])) {
        return $apiJson;
    }

    return $apiJson['data'] ?? $apiJson;
}

/**
 * Whether a retrieved checkout session reflects a completed payment (v1 + v2).
 */
function paymongoCheckoutSessionIsPaid(array $apiJson): bool
{
    $attrs = paymongoCheckoutSessionResource($apiJson)['attributes'] ?? [];

    // PayMongo v2 sessions stay "active" until expired; payment is in payments[].
    foreach ($attrs['payments'] ?? [] as $payment) {
        if (!is_array($payment)) {
            continue;
        }
        $pStatus = strtolower((string)(
            $payment['attributes']['status']
            ?? $payment['status']
            ?? ''
        ));
        if (in_array($pStatus, ['paid', 'succeeded'], true)) {
            return true;
        }
    }

    $pi = $attrs['payment_intent'] ?? null;
    if (is_array($pi)) {
        $piStatus = strtolower((string)(
            $pi['attributes']['status']
            ?? $pi['status']
            ?? ''
        ));
        if (in_array($piStatus, ['succeeded', 'paid'], true)) {
            return true;
        }
    }

    if (!empty($attrs['paid_at']) || !empty($attrs['completed_at'])) {
        return true;
    }

    $sessionStatus = strtolower((string)($attrs['status'] ?? ''));
    return in_array($sessionStatus, ['paid', 'completed', 'succeeded'], true);
}

/**
 * Parse PayMongo webhook payloads (legacy event resource + newer checkout v2 shapes).
 *
 * @return array{event_type: string, checkout_id: string, reservation_id: int, event_id: string}
 */
function paymongoParseWebhookPayload(array $payload): array
{
    $root = $payload['data'] ?? $payload;
    $eventId = (string)($root['id'] ?? '');

    $eventType = (string)(
        $root['attributes']['type']
        ?? $root['type']
        ?? $payload['event_type']
        ?? ''
    );

    $resource = $root['attributes']['data'] ?? $root['data'] ?? [];
    if (isset($resource['type'], $resource['data']) && is_array($resource['data'])) {
        // v2 send.webhook envelope: data.type + data.data (checkout session)
        $resource = $resource['data'];
    } elseif (isset($resource['data']) && is_array($resource['data']) && isset($resource['data']['id'])) {
        $resource = $resource['data'];
    }

    $checkoutId = (string)($resource['id'] ?? '');
    if ($checkoutId !== '' && !str_starts_with($checkoutId, 'cs_')) {
        $checkoutId = '';
    }

    if ($checkoutId === '') {
        $checkoutId = (string)(
            $resource['attributes']['checkout_session_id']
            ?? $resource['attributes']['checkout_session']['id']
            ?? ''
        );
    }

    $metadata = $resource['attributes']['metadata']
        ?? $resource['attributes']['checkout_session']['attributes']['metadata']
        ?? [];

    if ($eventType === '' && paymongoCheckoutSessionIsPaid(['data' => $resource])) {
        $eventType = 'checkout_session.payment.paid';
    }

    return [
        'event_type' => $eventType,
        'checkout_id' => $checkoutId,
        'reservation_id' => (int)($metadata['reservation_id'] ?? 0),
        'event_id' => $eventId,
    ];
}

/**
 * Poll PayMongo and finalize a pending_payment reservation when checkout is paid.
 *
 * @return array{ok: bool, message: string, changed: bool}
 */
function frs_try_sync_reservation_payment(PDO $pdo, int $reservationId, ?int $actingUserId = null): array
{
    $resStmt = $pdo->prepare(
        'SELECT id, user_id, status FROM reservations WHERE id = :id LIMIT 1'
    );
    $resStmt->execute(['id' => $reservationId]);
    $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        return ['ok' => false, 'message' => 'Reservation not found.', 'changed' => false];
    }

    $ownerId = (int)($reservation['user_id'] ?? 0);
    if ($actingUserId !== null && $actingUserId !== $ownerId) {
        $role = (string)($_SESSION['role'] ?? '');
        if (!in_array($role, ['Admin', 'Staff'], true)) {
            return ['ok' => false, 'message' => 'Reservation not found.', 'changed' => false];
        }
    }

    if (($reservation['status'] ?? '') === 'approved') {
        return ['ok' => true, 'message' => 'Reservation already approved.', 'changed' => false];
    }

    if (($reservation['status'] ?? '') !== 'pending_payment') {
        return ['ok' => false, 'message' => 'Reservation is not awaiting payment.', 'changed' => false];
    }

    if (!paymongoEnabled()) {
        return ['ok' => false, 'message' => 'PayMongo is not configured.', 'changed' => false];
    }

    $payStmt = $pdo->prepare(
        'SELECT id, provider_checkout_id, status
         FROM payments
         WHERE reservation_id = :reservation_id
           AND provider_checkout_id IS NOT NULL
           AND provider_checkout_id != ""
         ORDER BY id DESC'
    );
    $payStmt->execute(['reservation_id' => $reservationId]);
    $paymentRows = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$paymentRows) {
        return ['ok' => false, 'message' => 'No checkout session found for this reservation.', 'changed' => false];
    }

    $lastError = 'Payment has not been completed yet.';

    foreach ($paymentRows as $paymentRow) {
        if (($paymentRow['status'] ?? '') === 'paid') {
            $result = frs_finalize_reservation_payment($pdo, $reservationId, $ownerId, [], (int)$paymentRow['id']);
            if (!empty($result['ok'])) {
                return ['ok' => true, 'message' => $result['message'], 'changed' => true];
            }
        }
    }

    foreach ($paymentRows as $paymentRow) {
        $checkoutId = (string)($paymentRow['provider_checkout_id'] ?? '');
        if ($checkoutId === '') {
            continue;
        }

        $checkoutResp = paymongoRetrieveCheckoutSession($checkoutId);
        if (empty($checkoutResp['ok'])) {
            $lastError = 'Unable to verify payment status: ' . ($checkoutResp['error'] ?? 'Unknown error');
            continue;
        }

        if (!paymongoCheckoutSessionIsPaid($checkoutResp['data'] ?? [])) {
            $lastError = 'Payment has not been completed yet for checkout ' . $checkoutId . '.';
            continue;
        }

        $result = frs_finalize_reservation_payment(
            $pdo,
            $reservationId,
            $ownerId,
            $checkoutResp['data'] ?? [],
            (int)$paymentRow['id']
        );

        return [
            'ok' => !empty($result['ok']),
            'message' => (string)($result['message'] ?? ''),
            'changed' => !empty($result['ok']),
        ];
    }

    return ['ok' => false, 'message' => $lastError, 'changed' => false];
}

/**
 * Sync all pending_payment reservations for a user (e.g. after returning from PayMongo).
 *
 * @return int Number of reservations moved to approved
 */
function frs_sync_user_pending_payments(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !paymongoEnabled()) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM reservations WHERE user_id = :uid AND status = "pending_payment" ORDER BY id ASC'
    );
    $stmt->execute(['uid' => $userId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return 0;
    }

    $changed = 0;
    foreach ($ids as $reservationId) {
        try {
            $result = frs_try_sync_reservation_payment($pdo, (int)$reservationId, $userId);
            if (!empty($result['changed'])) {
                $changed++;
            }
        } catch (Throwable $e) {
            error_log('Pending payment sync error for RES-' . (int)$reservationId . ': ' . $e->getMessage());
        }
    }

    return $changed;
}

/**
 * Finalize reservation after PayMongo reports a paid checkout session.
 *
 * @return array{ok: bool, message: string}
 */
function frs_finalize_reservation_payment(
    PDO $pdo,
    int $reservationId,
    int $ownerUserId,
    array $checkoutResponse,
    ?int $paymentRowId = null
): array {
    $payQuery = 'SELECT id, status FROM payments WHERE reservation_id = :reservation_id';
    $payParams = ['reservation_id' => $reservationId];
    if ($paymentRowId !== null && $paymentRowId > 0) {
        $payQuery .= ' AND id = :payment_id';
        $payParams['payment_id'] = $paymentRowId;
    }
    $payQuery .= ' ORDER BY id DESC LIMIT 1';

    $latestPayStmt = $pdo->prepare($payQuery);
    $latestPayStmt->execute($payParams);
    $latestPayment = $latestPayStmt->fetch(PDO::FETCH_ASSOC);

    if (!$latestPayment) {
        return ['ok' => false, 'message' => 'Payment record not found.'];
    }

    $alreadyPaidInDb = ($latestPayment['status'] ?? '') === 'paid';
    $paidInPaymongo = $checkoutResponse !== [] && paymongoCheckoutSessionIsPaid($checkoutResponse);

    if (!$alreadyPaidInDb && !$paidInPaymongo) {
        return ['ok' => false, 'message' => 'Payment has not been completed yet.'];
    }

    if ($alreadyPaidInDb) {
        $resCheck = $pdo->prepare('SELECT status FROM reservations WHERE id = :id LIMIT 1');
        $resCheck->execute(['id' => $reservationId]);
        if (($resCheck->fetchColumn() ?: '') === 'approved') {
            return ['ok' => true, 'message' => 'Payment already confirmed.'];
        }
    }

    $pdo->beginTransaction();

    try {
        $updatePay = $pdo->prepare(
            'UPDATE payments
             SET status = "paid",
                 paid_at = COALESCE(paid_at, NOW()),
                 payload_json = :payload_json
             WHERE id = :id'
        );
        $updatePay->execute([
            'payload_json' => $checkoutResponse !== [] ? json_encode($checkoutResponse) : null,
            'id' => (int)$latestPayment['id'],
        ]);

        $updateReservation = $pdo->prepare(
            'UPDATE reservations
             SET status = "approved", auto_approved = 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = "pending_payment"'
        );
        $updateReservation->execute(['id' => $reservationId]);

        $hist = $pdo->prepare(
            'INSERT INTO reservation_history (reservation_id, status, note, created_by)
             VALUES (:reservation_id, "approved", :note, NULL)'
        );
        $hist->execute([
            'reservation_id' => $reservationId,
            'note' => 'Payment confirmed via PayMongo. Reservation secured.',
        ]);

        require_once __DIR__ . '/notifications.php';
        createNotification(
            $ownerUserId,
            'booking',
            'Payment Confirmed',
            'Your payment was successful. Reservation #' . $reservationId . ' is now approved.',
            base_path() . '/dashboard/book-facility?module=mine'
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'message' => 'Payment confirmed.'];
}

/**
 * Verify PayMongo webhook signature (Paymongo-Signature: t=...,te=...,li=...).
 *
 * @see https://developers.paymongo.com/docs/webhook-implementation-best-practices
 */
function paymongoVerifyWebhookSignature(string $rawPayload, string $signatureHeader, ?string $webhookSecret = null, int $toleranceSeconds = 300): bool
{
    $secret = trim((string)($webhookSecret ?? paymongoConfig()['webhook_secret'] ?? ''));
    if ($secret === '' || $signatureHeader === '' || $rawPayload === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        $pair = explode('=', $segment, 2);
        if (count($pair) === 2) {
            $parts[trim($pair[0])] = trim($pair[1]);
        }
    }

    $timestamp = $parts['t'] ?? '';
    $liveSig = $parts['li'] ?? '';
    $testSig = $parts['te'] ?? '';
    $providedSig = $liveSig !== '' ? $liveSig : $testSig;

    if ($timestamp === '' || $providedSig === '') {
        return false;
    }

    if ($toleranceSeconds > 0 && abs(time() - (int)$timestamp) > $toleranceSeconds) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);

    return hash_equals($expected, $providedSig);
}
