<?php
/**
 * Geocoding Helper Functions
 * 
 * Converts addresses to coordinates using free geocoding services
 * Priority: Photon (free, no signup) > OpenStreetMap (free, no signup) > Mapbox > Google Maps
 */

// Geocoding Service Configuration
// Priority order: Photon (FREE, no signup) > OSM (FREE, no signup) > Mapbox (requires credit card) > Google Maps (paid)
// Set to 'photon', 'osm', 'mapbox', or 'google'
if (!defined('GEOCODING_PROVIDER')) {
    define('GEOCODING_PROVIDER', 'photon'); // Default: Photon (free, no signup, no credit card)
}

// Load optional config (copy geocoding_config.example.php → geocoding_config.php)
$geocodeCfg = __DIR__ . '/geocoding_config.php';
if (file_exists($geocodeCfg)) {
    require_once $geocodeCfg;
}

// Mapbox Configuration (FREE tier: 100,000 requests/month, rooftop accuracy)
if (!defined('MAPBOX_ACCESS_TOKEN')) {
    $k = getenv('MAPBOX_ACCESS_TOKEN');
    define('MAPBOX_ACCESS_TOKEN', $k !== false ? $k : '');
}
if (!defined('MAPBOX_GEOCODING_URL')) {
    define('MAPBOX_GEOCODING_URL', 'https://api.mapbox.com/geocoding/v5/mapbox.places');
}

// Google Maps API (requires paid API key)
if (!defined('GOOGLE_MAPS_API_KEY')) {
    $k = getenv('GOOGLE_MAPS_API_KEY');
    define('GOOGLE_MAPS_API_KEY', $k !== false ? $k : '');
}
if (!defined('GOOGLE_MAPS_GEOCODING_URL')) {
    define('GOOGLE_MAPS_GEOCODING_URL', 'https://maps.googleapis.com/maps/api/geocode/json');
}

// Photon Geocoding (Komoot) - FREE, no signup, no credit card, good accuracy
if (!defined('PHOTON_GEOCODING_URL')) {
    define('PHOTON_GEOCODING_URL', 'https://photon.komoot.io/api');
}

// OpenStreetMap Nominatim Configuration (FREE, no API key, improved accuracy with better params)
define('OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search');
define('OSM_USER_AGENT', 'LGU Facilities Reservation System'); // Required by OSM

// Backward compatibility: USE_OSM_GEOCODING
if (!defined('USE_OSM_GEOCODING')) {
    define('USE_OSM_GEOCODING', GEOCODING_PROVIDER === 'osm');
}

/**
 * Geocode an address to coordinates using Photon (Komoot) - FREE, no signup, no credit card
 * Good accuracy, often better than basic OSM
 * 
 * @param string $address Full address string
 * @return array|null Array with 'lat' and 'lng' keys, or null on failure
 */
function geocodeAddressPhoton($address)
{
    if (empty($address)) {
        return null;
    }
    
    // Photon API: forward geocoding (address → coordinates)
    // No API key needed, completely free
    $url = PHOTON_GEOCODING_URL . '?q=' . urlencode($address) . '&limit=1';
    
    // Add country bias for Philippines
    $url .= '&lang=en';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: LGU Facilities Reservation System',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        error_log("Photon Geocoding API error: HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    
    // Photon response format: { "features": [ { "geometry": { "coordinates": [lng, lat] } } ] }
    if (isset($data['features']) && !empty($data['features']) && isset($data['features'][0]['geometry']['coordinates'])) {
        $coords = $data['features'][0]['geometry']['coordinates'];
        // Photon returns [longitude, latitude] - we need [latitude, longitude]
        return [
            'lat' => (float)$coords[1],
            'lng' => (float)$coords[0],
        ];
    }
    
    error_log("Photon Geocoding failed: No results found for address: $address");
    return null;
}

/**
 * Geocode an address to coordinates using Mapbox (requires credit card for signup)
 * 
 * @param string $address Full address string
 * @return array|null Array with 'lat' and 'lng' keys, or null on failure
 */
function geocodeAddressMapbox($address)
{
    if (empty($address)) {
        return null;
    }
    
    // If access token is not configured, return null
    if (empty(MAPBOX_ACCESS_TOKEN)) {
        return null;
    }
    
    // Mapbox Geocoding API: forward geocoding (address → coordinates)
    $url = MAPBOX_GEOCODING_URL . '/' . urlencode($address) . '.json?access_token=' . MAPBOX_ACCESS_TOKEN . '&limit=1&country=PH';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        error_log("Mapbox Geocoding API error: HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    
    // Mapbox response format: { "features": [ { "center": [lng, lat], "relevance": ... } ] }
    if (isset($data['features']) && !empty($data['features']) && isset($data['features'][0]['center'])) {
        $center = $data['features'][0]['center'];
        // Mapbox returns [longitude, latitude] - we need [latitude, longitude]
        return [
            'lat' => (float)$center[1],
            'lng' => (float)$center[0],
        ];
    }
    
    error_log("Mapbox Geocoding failed: No results found for address: $address");
    return null;
}

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
    
    // Build request URL with improved parameters for better accuracy
    $params = [
        'q' => $address,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 1,
        'countrycodes' => 'ph', // Bias for Philippines
        'accept-language' => 'en',
        'dedupe' => 1, // Remove duplicates
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
 * Geocode an address to coordinates (uses configured service with fallback chain)
 * Priority: Photon (free, no signup) > OSM (free, no signup) > Mapbox > Google Maps
 * 
 * @param string $address Full address string
 * @return array|null Array with 'lat' and 'lng' keys, or null on failure
 */
function geocodeAddress($address)
{
    if (empty($address)) {
        return null;
    }
    
    $provider = defined('GEOCODING_PROVIDER') ? GEOCODING_PROVIDER : 'photon';
    
    // Try Photon first (free, no signup, no credit card, good accuracy)
    if ($provider === 'photon' || (empty($provider) && $provider !== 'mapbox' && $provider !== 'google' && $provider !== 'osm')) {
        $result = geocodeAddressPhoton($address);
        if ($result) {
            return $result;
        }
    }
    
    // Try OpenStreetMap (free, no signup, improved parameters)
    if ($provider === 'osm' || USE_OSM_GEOCODING || ($provider === 'photon' && !$result)) {
        $result = geocodeAddressOSM($address);
        if ($result) {
            return $result;
        }
    }
    
    // Try Mapbox if configured (requires credit card for signup)
    if ($provider === 'mapbox' && !empty(MAPBOX_ACCESS_TOKEN)) {
        $result = geocodeAddressMapbox($address);
        if ($result) {
            return $result;
        }
    }
    
    // Try Google Maps if configured (paid)
    if ($provider === 'google' && !empty(GOOGLE_MAPS_API_KEY)) {
        $result = geocodeAddressGoogle($address);
        if ($result) {
            return $result;
        }
    }
    
    // Last resort fallback chain: Photon → OSM → Mapbox → Google
    $result = geocodeAddressPhoton($address);
    if ($result) return $result;
    
    $result = geocodeAddressOSM($address);
    if ($result) return $result;
    
    if (!empty(MAPBOX_ACCESS_TOKEN)) {
        $result = geocodeAddressMapbox($address);
        if ($result) return $result;
    }
    
    if (!empty(GOOGLE_MAPS_API_KEY)) {
        $result = geocodeAddressGoogle($address);
        if ($result) return $result;
    }
    
    return null;
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

