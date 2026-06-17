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

function paymongoRequest(string $method, string $endpoint, array $payload = []): array
{
    $cfg = paymongoConfig();
    $secretKey = trim((string)($cfg['secret_key'] ?? ''));
    if ($secretKey === '') {
        return ['ok' => false, 'error' => 'PayMongo secret key is not configured.'];
    }

    $url = paymongoApiBaseUrl() . ltrim($endpoint, '/');
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
    return paymongoRequest('GET', 'checkout_sessions/' . rawurlencode($checkoutId));
}

/**
 * Finalize reservation after PayMongo reports a paid checkout session.
 *
 * @return array{ok: bool, message: string}
 */
function frs_finalize_reservation_payment(PDO $pdo, int $reservationId, int $userId, array $checkoutResponse): array
{
    $attrs = $checkoutResponse['data']['attributes'] ?? [];
    $paymentStatus = strtolower((string)($attrs['payment_intent']['attributes']['status'] ?? ''));
    if ($paymentStatus === '' && !empty($attrs['payments'][0]['attributes']['status'])) {
        $paymentStatus = strtolower((string)$attrs['payments'][0]['attributes']['status']);
    }
    $isPaid = in_array($paymentStatus, ['succeeded', 'paid'], true);

    if (!$isPaid) {
        return ['ok' => false, 'message' => 'Payment has not been completed yet.'];
    }

    $latestPayStmt = $pdo->prepare(
        'SELECT id, provider_checkout_id, status
         FROM payments
         WHERE reservation_id = :reservation_id AND user_id = :user_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $latestPayStmt->execute(['reservation_id' => $reservationId, 'user_id' => $userId]);
    $latestPayment = $latestPayStmt->fetch(PDO::FETCH_ASSOC);

    if (!$latestPayment) {
        return ['ok' => false, 'message' => 'Payment record not found.'];
    }

    if (($latestPayment['status'] ?? '') === 'paid') {
        return ['ok' => true, 'message' => 'Payment already confirmed.'];
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
            'payload_json' => json_encode($checkoutResponse),
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
            $userId,
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
