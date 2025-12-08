# Location-Based Facility Recommendations

## Overview
The system now includes location-based facility recommendations that suggest facilities based on proximity to the user's address. This feature uses Google Maps Geocoding API to convert addresses to coordinates and calculates distances using the Haversine formula.

## Features

### 1. **Address Storage**
- Users can add their address in the profile page
- Facilities have location addresses stored
- Addresses are automatically geocoded to coordinates (latitude/longitude)

### 2. **Automatic Geocoding**
- When a user adds/updates their address, the system attempts to geocode it using Google Maps API
- When a facility location is added/updated, it's automatically geocoded
- Coordinates are stored in the database for future use

### 3. **Proximity-Based Scoring**
Facilities are scored based on distance from user:
- **Within 1km**: +30 points (Very close)
- **1-3km**: +20 points (Nearby)
- **3-5km**: +10 points (Moderately close)
- **5-10km**: +5 points (Within 10km)
- **Beyond 10km**: 0 points

### 4. **Distance Display**
- Recommendations show distance from user (e.g., "2.5 km away")
- Facilities are sorted by match score, then by distance (closer first)

## Setup Instructions

### 1. Database Migration
Run the migration to add location fields:
```sql
-- Run: database/migration_add_location_fields.sql
```

### 2. Geocoding Service Setup

#### Option 1: OpenStreetMap Nominatim (FREE - Default) ✅
**No setup required!** The system uses OpenStreetMap Nominatim by default, which is:
- ✅ **100% Free** - No API key needed
- ✅ **No Credit Card Required**
- ✅ **No Registration Required**
- ✅ **Unlimited Use** (with rate limiting: 1 request per second)

The system is already configured to use this service. Just add addresses and they'll be automatically geocoded!

**Rate Limiting:**
- OpenStreetMap allows 1 request per second
- The system automatically handles this
- For bulk geocoding, add a small delay between requests

#### Option 2: Google Maps API (Optional)
If you prefer Google Maps (requires API key and credit card):

1. Get API Key:
   - Go to [Google Cloud Console](https://console.cloud.google.com/)
   - Create a new project or select existing one
   - Enable "Geocoding API"
   - Create credentials (API Key)
   - Restrict the API key to "Geocoding API" for security

2. Configure API Key:
   Edit `config/geocoding.php`:
   ```php
   define('USE_OSM_GEOCODING', false); // Use Google Maps instead
   define('GOOGLE_MAPS_API_KEY', 'YOUR_API_KEY_HERE');
   ```

### 3. Manual Coordinate Entry
You can still manually enter coordinates if needed:
- Useful for very specific locations
- Useful if geocoding fails
- Coordinates take priority over geocoding

## Usage

### For Users

1. **Add Your Address**
   - Go to Profile page
   - Enter your address in the "Address" field
   - Save profile
   - System will automatically geocode your address (if API key is configured)

2. **Get Location-Based Recommendations**
   - When booking a facility, enter your event purpose
   - System will show facilities sorted by:
     - Match score (purpose, capacity, amenities)
     - Distance from your address
   - Distance is displayed next to each recommendation

### For Administrators

1. **Add Facility Location**
   - Go to Facility Management
   - Enter facility location address
   - Optionally enter latitude/longitude manually
   - If only address is provided, system will geocode it automatically (if API key is configured)

2. **Manual Coordinates**
   - If geocoding fails or API is not configured
   - Enter coordinates manually in Latitude/Longitude fields
   - You can find coordinates using:
     - Google Maps (right-click location → coordinates)
     - Online coordinate finders

## Technical Details

### Database Schema
```sql
-- Users table
ALTER TABLE users
    ADD COLUMN address VARCHAR(255) NULL,
    ADD COLUMN latitude DECIMAL(10, 8) NULL,
    ADD COLUMN longitude DECIMAL(11, 8) NULL;

-- Facilities table
ALTER TABLE facilities
    ADD COLUMN latitude DECIMAL(10, 8) NULL,
    ADD COLUMN longitude DECIMAL(11, 8) NULL;
```

### Distance Calculation
Uses Haversine formula to calculate great-circle distance between two points on Earth:
- Accurate for distances up to a few hundred kilometers
- Returns distance in kilometers
- Formula accounts for Earth's curvature

### Geocoding Process
1. User/facility address is entered
2. System checks if coordinates already exist
3. If not, attempts to geocode using Google Maps API
4. If successful, saves coordinates to database
5. If failed, coordinates remain NULL (can be entered manually)

## API Usage

### OpenStreetMap Nominatim API (Default)
- **Cost**: 100% FREE - No charges ever
- **Rate Limit**: 1 request per second (automatically handled)
- **API Key**: Not required
- **Credit Card**: Not required
- **Usage**: Only called when address is added/updated, not on every recommendation
- **Coverage**: Worldwide coverage via OpenStreetMap data

### Google Maps Geocoding API (Optional)
- **Cost**: First $200/month free (40,000 requests), then paid
- **Rate Limit**: 50 requests per second
- **API Key**: Required
- **Credit Card**: Required for billing
- **Usage**: Only called when address is added/updated, not on every recommendation

### Optimization
- Coordinates are cached in database
- Geocoding only happens once per address
- Recommendations use cached coordinates (no API calls)

## Troubleshooting

### Geocoding Not Working
1. Check if API key is configured in `config/geocoding.php`
2. Verify API key has "Geocoding API" enabled
3. Check API key restrictions
4. Review PHP error logs for API errors

### Coordinates Not Saving
1. Check database migration was run
2. Verify latitude/longitude columns exist
3. Check for database errors in logs

### Recommendations Not Showing Distance
1. Ensure user has address and coordinates saved
2. Ensure facilities have coordinates saved
3. Check that geocoding succeeded for both user and facilities

## Best Practices

1. **Address Format**: Use complete addresses for better geocoding accuracy
   - Example: "Barangay Culiat, Quezon City, Metro Manila, Philippines"
   
2. **API Key Security**: 
   - Restrict API key to specific IP addresses (if possible)
   - Limit to Geocoding API only
   - Monitor API usage in Google Cloud Console

3. **Manual Coordinates**: 
   - Use decimal degrees format (e.g., 14.6760, 121.0437)
   - Verify coordinates using Google Maps before saving

4. **Testing**:
   - Test with known addresses first
   - Verify coordinates are saved correctly
   - Test recommendations with different user addresses

## Future Enhancements

Possible improvements:
- Map view showing facilities and user location
- Route directions to facilities
- Multiple address support (home, work)
- Distance-based filtering (show only facilities within X km)
- Integration with Google Maps for visual display

---

**Last Updated**: 2024
**Version**: 1.0

