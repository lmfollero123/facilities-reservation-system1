<?php
/**
 * Geocoding Helper Functions
 * 
 * Converts addresses to coordinates using free geocoding services
 * Uses OpenStreetMap Nominatim API (free, no API key required)
 * Falls back to Google Maps API if configured
 */

// Geocoding Service Configuration
// Option 1: OpenStreetMap Nominatim (FREE, no API key needed) - DEFAULT
define('USE_OSM_GEOCODING', true); // Set to false to use Google Maps instead

// Option 2: Google Maps API (requires API key and credit card)
define('GOOGLE_MAPS_API_KEY', ''); // Set your API key here if using Google Maps
define('GOOGLE_MAPS_GEOCODING_URL', 'https://maps.googleapis.com/maps/api/geocode/json');

// OpenStreetMap Nominatim Configuration
define('OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search');
define('OSM_USER_AGENT', 'LGU Facilities Reservation System'); // Required by OSM

/**
 * Geocode an address to coordinates using OpenStreetMap Nominatim (FREE)
 * 
 * @param string $address Full address string
 * @return array|null Array with 'lat' and 'lng' keys, or null on failure
 */
function geocodeAddressOSM($address)
{
    if (empty($address)) {
        return null;
    }
    
    // Build request URL
    $params = [
        'q' => $address,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 1,
    ];
    
    $url = OSM_NOMINATIM_URL . '?' . http_build_query($params);
    
    // Use cURL with proper User-Agent (required by OSM)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, OSM_USER_AGENT); // Required by OSM
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept-Language: en-US,en;q=0.9',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        error_log("OSM Geocoding API error: HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (is_array($data) && !empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
        return [
            'lat' => (float)$data[0]['lat'],
            'lng' => (float)$data[0]['lon'],
        ];
    }
    
    error_log("OSM Geocoding failed: No results found for address: $address");
    return null;
}

/**
 * Geocode an address to coordinates using Google Maps API
 * 
 * @param string $address Full address string
 * @return array|null Array with 'lat' and 'lng' keys, or null on failure
 */
function geocodeAddressGoogle($address)
{
    if (empty($address)) {
        return null;
    }
    
    // If API key is not configured, return null
    if (empty(GOOGLE_MAPS_API_KEY)) {
        return null;
    }
    
    $url = GOOGLE_MAPS_GEOCODING_URL . '?address=' . urlencode($address) . '&key=' . GOOGLE_MAPS_API_KEY;
    
    // Use cURL to make the API request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        error_log("Google Geocoding API error: HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if ($data['status'] === 'OK' && !empty($data['results'])) {
        $location = $data['results'][0]['geometry']['location'];
        return [
            'lat' => (float)$location['lat'],
            'lng' => (float)$location['lng'],
        ];
    }
    
    // Log error status
    error_log("Google Geocoding failed: " . ($data['status'] ?? 'Unknown error'));
    return null;
}

/**
 * Geocode an address to coordinates (uses configured service)
 * 
 * @param string $address Full address string
 * @return array|null Array with 'lat' and 'lng' keys, or null on failure
 */
function geocodeAddress($address)
{
    if (empty($address)) {
        return null;
    }
    
    // Use OpenStreetMap by default (free, no API key needed)
    if (USE_OSM_GEOCODING) {
        $result = geocodeAddressOSM($address);
        if ($result) {
            return $result;
        }
        // Fallback to Google Maps if OSM fails and Google is configured
        if (!empty(GOOGLE_MAPS_API_KEY)) {
            return geocodeAddressGoogle($address);
        }
        return null;
    }
    
    // Use Google Maps if configured
    if (!empty(GOOGLE_MAPS_API_KEY)) {
        $result = geocodeAddressGoogle($address);
        if ($result) {
            return $result;
        }
    }
    
    // Fallback to OSM if Google fails
    return geocodeAddressOSM($address);
}

/**
 * Calculate distance between two coordinates using Haversine formula
 * 
 * @param float $lat1 Latitude of first point
 * @param float $lng1 Longitude of first point
 * @param float $lat2 Latitude of second point
 * @param float $lng2 Longitude of second point
 * @param string $unit 'km' for kilometers, 'mi' for miles (default: 'km')
 * @return float Distance in specified unit
 */
function calculateDistance($lat1, $lng1, $lat2, $lng2, $unit = 'km')
{
    if ($lat1 == $lat2 && $lng1 == $lng2) {
        return 0;
    }
    
    // Haversine formula
    $earthRadius = ($unit === 'mi') ? 3959 : 6371; // Radius in miles or kilometers
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distance = $earthRadius * $c;
    
    return round($distance, 2);
}

/**
 * Get user coordinates (from database or geocode if needed)
 * 
 * @param int $userId User ID
 * @return array|null Array with 'lat' and 'lng' keys, or null
 */
function getUserCoordinates($userId)
{
    require_once __DIR__ . '/database.php';
    $pdo = db();
    
    $stmt = $pdo->prepare("SELECT latitude, longitude, address FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return null;
    }
    
    // If coordinates exist, return them
    if ($user['latitude'] !== null && $user['longitude'] !== null) {
        return [
            'lat' => (float)$user['latitude'],
            'lng' => (float)$user['longitude'],
        ];
    }
    
    // If address exists but no coordinates, try to geocode
    if (!empty($user['address'])) {
        $coords = geocodeAddress($user['address']);
        
        // Save coordinates to database if geocoding succeeded
        if ($coords) {
            $updateStmt = $pdo->prepare("UPDATE users SET latitude = ?, longitude = ? WHERE id = ?");
            $updateStmt->execute([$coords['lat'], $coords['lng'], $userId]);
        }
        
        return $coords;
    }
    
    return null;
}

/**
 * Get facility coordinates (from database or geocode if needed)
 * 
 * @param int $facilityId Facility ID
 * @return array|null Array with 'lat' and 'lng' keys, or null
 */
function getFacilityCoordinates($facilityId)
{
    require_once __DIR__ . '/database.php';
    $pdo = db();
    
    $stmt = $pdo->prepare("SELECT latitude, longitude, location FROM facilities WHERE id = ?");
    $stmt->execute([$facilityId]);
    $facility = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facility) {
        return null;
    }
    
    // If coordinates exist, return them
    if ($facility['latitude'] !== null && $facility['longitude'] !== null) {
        return [
            'lat' => (float)$facility['latitude'],
            'lng' => (float)$facility['longitude'],
        ];
    }
    
    // If location exists but no coordinates, try to geocode
    if (!empty($facility['location'])) {
        $coords = geocodeAddress($facility['location']);
        
        // Save coordinates to database if geocoding succeeded
        if ($coords) {
            $updateStmt = $pdo->prepare("UPDATE facilities SET latitude = ?, longitude = ? WHERE id = ?");
            $updateStmt->execute([$coords['lat'], $coords['lng'], $facilityId]);
        }
        
        return $coords;
    }
    
    return null;
}

/**
 * Format distance for display
 * 
 * @param float $distance Distance in kilometers
 * @return string Formatted distance string
 */
function formatDistance($distance)
{
    if ($distance < 1) {
        return round($distance * 1000) . ' m'; // Show in meters if less than 1km
    }
    return round($distance, 1) . ' km';
}

