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

function paymongoRequest(string $method, string $endpoint, array $payload = []): array
{
    $cfg = paymongoConfig();
    $secretKey = trim((string)($cfg['secret_key'] ?? ''));
    if ($secretKey === '') {
        return ['ok' => false, 'error' => 'PayMongo secret key is not configured.'];
    }

    $url = 'https://api.paymongo.com/v1/' . ltrim($endpoint, '/');
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
        return ['ok' => false, 'error' => 'Invalid PayMongo response.'];
    }

    if ($http < 200 || $http >= 300) {
        $msg = $json['errors'][0]['detail'] ?? ('HTTP ' . $http);
        return ['ok' => false, 'error' => (string)$msg, 'response' => $json];
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
