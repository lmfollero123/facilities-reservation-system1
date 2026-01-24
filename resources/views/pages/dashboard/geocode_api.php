<?php
/**
 * Geocode API – convert address to coordinates.
 * Used by profile (and others) to auto-fill lat/long when user types address.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$address = trim($_POST['address'] ?? $_GET['address'] ?? '');
if ($address === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Address is required']);
    exit;
}

require_once __DIR__ . '/../../../../config/geocoding.php';

$coords = geocodeAddress($address);
if ($coords) {
    echo json_encode(['lat' => $coords['lat'], 'lng' => $coords['lng']]);
} else {
    http_response_code(422);
    echo json_encode(['error' => 'Could not find coordinates for this address']);
}
