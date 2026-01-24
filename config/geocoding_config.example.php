<?php
/**
 * Geocoding configuration.
 * Copy this file to geocoding_config.php and set your API keys (if using paid services).
 * Do not commit geocoding_config.php if it contains your real keys.
 *
 * DEFAULT: Photon (FREE, no signup, no credit card required) ✅
 * - Good accuracy, often better than basic OSM
 * - No API key needed
 * - No signup required
 * - Works immediately!
 *
 * OPTIONAL: Mapbox (requires credit card for signup)
 * Get a token: https://account.mapbox.com/access-tokens/
 * Free tier: 100,000 requests/month, rooftop-level geocoding accuracy
 */
// define('MAPBOX_ACCESS_TOKEN', 'YOUR_MAPBOX_ACCESS_TOKEN');

/**
 * OPTIONAL: Google Maps API (requires paid API key)
 * Get a key: https://developers.google.com/maps/documentation/geocoding/get-api-key
 * Enable "Geocoding API" for the key.
 */
// define('GOOGLE_MAPS_API_KEY', 'YOUR_GOOGLE_MAPS_GEOCODING_API_KEY');

/**
 * OPTIONAL: Override geocoding provider
 * Options: 
 *   - 'photon' (default, FREE, no signup, no credit card) ✅ Recommended
 *   - 'osm' (FREE, no signup, no credit card, basic accuracy)
 *   - 'mapbox' (requires credit card for signup, rooftop accuracy)
 *   - 'google' (paid, rooftop accuracy)
 */
// define('GEOCODING_PROVIDER', 'photon');
