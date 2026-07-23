<?php
/**
 * LGU Energy Efficiency integration client.
 *
 * Push/pull integration: CPRF pushes manual facility meter readings to the
 * Energy system's POST /api/v1/cprf/facility-readings, and pulls facilities
 * and engineer-approved recommendations from its GET /api/v1/cprf/* endpoints.
 * Auth is a shared bearer token (ENERGY_API_TOKEN, matching the Energy app's
 * CPRF_INTEGRATION_TOKEN).
 */

declare(strict_types=1);

function energy_api_base_url(): string
{
    $url = trim((string)(function_exists('env_value') ? env_value('ENERGY_API_URL', '') : (getenv('ENERGY_API_URL') ?: '')));
    return rtrim($url, '/');
}

function energy_api_token(): string
{
    return trim((string)(function_exists('env_value') ? env_value('ENERGY_API_TOKEN', '') : (getenv('ENERGY_API_TOKEN') ?: '')));
}

function energy_api_enabled(): bool
{
    $flag = strtolower(trim((string)(function_exists('env_value') ? env_value('ENERGY_SYNC_ENABLED', 'true') : (getenv('ENERGY_SYNC_ENABLED') ?: 'true'))));
    return !in_array($flag, ['0', 'false', 'off', 'no'], true);
}

/**
 * Low-level request against the Energy system's CPRF API.
 *
 * @param array<string, mixed>|null $body
 * @param array<string, mixed> $query
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function energy_api_request(string $method, string $path, ?array $body = null, array $query = []): array
{
    $baseUrl = energy_api_base_url();
    $token = energy_api_token();
    if ($baseUrl === '' || $token === '') {
        return [
            'success' => false,
            'data' => null,
            'error' => 'Energy API is not configured (set ENERGY_API_URL and ENERGY_API_TOKEN in .env).',
            'http_code' => 0,
        ];
    }

    $url = $baseUrl . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'User-Agent: CPRF-Facilities-Reservation/1.0',
    ];

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        $msg = 'Connection failed: ' . ($curlError ?: 'Unable to reach Energy API');
        error_log('Energy API Error: ' . $msg);
        return ['success' => false, 'data' => null, 'error' => $msg, 'http_code' => $httpCode];
    }

    $json = json_decode((string)$response, true);

    if ($httpCode === 401 || $httpCode === 503) {
        $msg = is_array($json) ? (string)($json['message'] ?? 'Invalid or missing Energy API token') : 'Invalid or missing Energy API token';
        return ['success' => false, 'data' => null, 'error' => $msg, 'http_code' => $httpCode];
    }

    if ($httpCode === 422) {
        $msg = is_array($json) ? (string)($json['message'] ?? 'Validation failed') : 'Validation failed';
        return ['success' => false, 'data' => is_array($json) ? $json : null, 'error' => $msg, 'http_code' => $httpCode];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'data' => null,
            'error' => 'HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200),
            'http_code' => $httpCode,
        ];
    }

    if (!is_array($json)) {
        return ['success' => false, 'data' => null, 'error' => 'Invalid JSON from Energy API', 'http_code' => $httpCode];
    }

    return ['success' => true, 'data' => $json, 'error' => null, 'http_code' => $httpCode];
}

/**
 * Fetch the Energy system's facility list (for the mapping tab).
 * Aggregates up to 5 pages of 100; 'data' is a flat list of facility rows.
 *
 * @return array{success: bool, data: array<int, array<string, mixed>>, error: ?string}
 */
function fetchEnergyFacilities(): array
{
    $all = [];
    $page = 1;
    do {
        $result = energy_api_request('GET', '/api/v1/cprf/facilities', null, ['per_page' => 100, 'page' => $page]);
        if (!$result['success']) {
            return ['success' => false, 'data' => $all, 'error' => $result['error']];
        }
        $rows = $result['data']['data'] ?? [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $all[] = $row;
            }
        }
        $hasNext = !empty($result['data']['next_page_url']);
        $page++;
    } while ($hasNext && $page <= 5);

    return ['success' => true, 'data' => $all, 'error' => null];
}

/**
 * Fetch recommendations (raw Laravel paginator array in 'data').
 *
 * @param array<string, mixed> $query e.g. ['status' => 'approved', 'updated_since' => '...', 'page' => 1]
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function fetchEnergyRecommendations(array $query = []): array
{
    return energy_api_request('GET', '/api/v1/cprf/recommendations', null, $query);
}

/**
 * Push one facility meter reading.
 *
 * @param array<string, mixed> $payload from frs_energy_build_reading_payload()
 * @return array{success: bool, data: ?array<string, mixed>, error: ?string, http_code: int}
 */
function pushEnergyFacilityReading(array $payload): array
{
    return energy_api_request('POST', '/api/v1/cprf/facility-readings', $payload);
}
