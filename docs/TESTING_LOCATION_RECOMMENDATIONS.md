# Testing Location-Based Recommendations

## Quick Testing Guide

### Step 1: Verify Your User Coordinates

1. **Go to Profile Page**
   - Navigate to: `Profile` in the sidebar
   - Or direct URL: `/resources/views/pages/dashboard/profile.php`

2. **Add Your Coordinates Manually**
   - Scroll to the "Address" section
   - Enter your address (optional, for reference)
   - **Important**: Enter your **Latitude** and **Longitude** manually
   
   **How to Find Your Coordinates:**
   - Go to [Google Maps](https://www.google.com/maps)
   - Search for your location (e.g., "Barangay Culiat, Quezon City")
   - Right-click on the location marker
   - Click on the coordinates that appear (e.g., "14.6760, 121.0437")
   - Copy the latitude (first number) and longitude (second number)
   - Paste them into the profile form

3. **Save Your Profile**
   - Click "Save Changes"
   - You should see: "✓ Location coordinates saved"

### Step 2: Verify Facilities Have Coordinates

1. **Go to Facility Management**
   - Navigate to: `Facility Management` (Admin/Staff only)
   - Or direct URL: `/resources/views/pages/dashboard/facility_management.php`

2. **Check Each Facility**
   - Click "Edit Details" on a facility
   - Verify it has:
     - Location address filled in
     - **Latitude** and **Longitude** filled in
   - If missing, add them manually:
     - Find facility location on Google Maps
     - Right-click → Copy coordinates
     - Paste into Latitude/Longitude fields
   - Save the facility

### Step 3: Test Recommendations

#### Option A: Use the Test Page (Recommended)

1. **Access Test Page**
   - Direct URL: `/resources/views/pages/dashboard/test_location_recommendations.php`
   - Or add it to sidebar temporarily

2. **Review the Test Page**
   - **Your Location Information**: Shows your coordinates
   - **Test Recommendations**: Shows recommendations with distances
   - **All Facilities**: Shows all facilities sorted by distance from you
   - **Debug Information**: Shows technical details

3. **What to Look For:**
   - ✅ Your coordinates are displayed
   - ✅ Facilities show distances (e.g., "2.5 km")
   - ✅ Recommendations are sorted by distance
   - ✅ Closer facilities appear first

#### Option B: Test in Booking Page

1. **Go to Book Facility**
   - Navigate to: `Book a Facility`
   - Or direct URL: `/resources/views/pages/dashboard/book_facility.php`

2. **Enter Event Purpose**
   - Type something like "zumba" or "meeting"
   - Wait for recommendations to appear

3. **Check Recommendations**
   - Look for distance displayed next to facility names
   - Example: "Covered Court (85% match) • 1.2 km away"
   - Closer facilities should appear higher in the list

### Step 4: Verify It's Working

**Signs it's working:**
- ✅ Distance appears next to facility names in recommendations
- ✅ Facilities closer to you appear first (when match scores are similar)
- ✅ Test page shows your coordinates and facility distances
- ✅ Recommendations include "Nearby" or "Very close" in reasons

**If it's NOT working:**
- ❌ No distance shown in recommendations
- ❌ Facilities not sorted by distance
- ❌ Test page shows "Not set" for coordinates

## Troubleshooting

### Problem: No Distance Showing

**Check 1: User Coordinates**
```sql
SELECT id, name, address, latitude, longitude 
FROM users 
WHERE id = YOUR_USER_ID;
```
- If `latitude` or `longitude` is NULL, add them in profile

**Check 2: Facility Coordinates**
```sql
SELECT id, name, location, latitude, longitude 
FROM facilities 
WHERE status = 'available';
```
- If any facility has NULL coordinates, add them in facility management

**Check 3: Test Page**
- Go to test page: `/resources/views/pages/dashboard/test_location_recommendations.php`
- Check "Debug Information" section
- Verify "User Coordinates" shows your lat/lng
- Verify "Facilities with Coordinates" shows facilities have coordinates

### Problem: Recommendations Not Sorted by Distance

**Possible Causes:**
1. Match scores are very different (purpose match dominates)
2. User coordinates not set
3. Facility coordinates not set

**Solution:**
- Test with a generic purpose (like "event") to see distance-based sorting
- Verify coordinates are set for both user and facilities
- Check test page to see actual distances

### Problem: Coordinates Not Saving

**Check:**
1. Profile form has latitude/longitude fields visible
2. Values are valid numbers (e.g., 14.6760, not "14.6760°")
3. No JavaScript errors in browser console
4. Database columns exist (run migration if needed)

**SQL Check:**
```sql
-- Verify columns exist
DESCRIBE users;
DESCRIBE facilities;

-- Should see latitude and longitude columns
```

## Example Test Data

### Sample User Coordinates (Quezon City area)
- **Latitude**: 14.6760
- **Longitude**: 121.0437
- **Address**: "Barangay Culiat, Quezon City"

### Sample Facility Coordinates
- **Facility 1** (Close):
  - Latitude: 14.6770
  - Longitude: 121.0440
  - Distance: ~0.1 km
  
- **Facility 2** (Medium):
  - Latitude: 14.6800
  - Longitude: 121.0500
  - Distance: ~0.8 km
  
- **Facility 3** (Far):
  - Latitude: 14.6900
  - Longitude: 121.0600
  - Distance: ~2.0 km

## Expected Behavior

### When Working Correctly:

1. **User enters purpose "zumba"**
   - System shows 5 recommendations
   - Each shows distance (e.g., "1.2 km away")
   - Facilities closer to user appear first (if match scores are similar)

2. **Test Page Shows:**
   - User coordinates: ✓ Set
   - Facilities with coordinates: X / Total
   - Recommendations with distance: X / Total
   - Facilities sorted by distance

3. **Recommendation Reasons Include:**
   - "Very close to you (0.5 km)"
   - "Nearby (1.2 km away)"
   - "Moderately close (3.5 km away)"

## Quick SQL Queries for Testing

```sql
-- Check your user coordinates
SELECT id, name, address, latitude, longitude 
FROM users 
WHERE email = 'your-email@example.com';

-- Check all facilities with coordinates
SELECT id, name, location, latitude, longitude 
FROM facilities 
WHERE latitude IS NOT NULL AND longitude IS NOT NULL;

-- Count facilities with coordinates
SELECT 
    COUNT(*) as total,
    COUNT(latitude) as with_coordinates,
    COUNT(*) - COUNT(latitude) as missing_coordinates
FROM facilities 
WHERE status = 'available';
```

## Next Steps After Testing

Once verified working:
1. Add coordinates for all facilities
2. Encourage users to add their addresses/coordinates
3. Monitor recommendation quality
4. Consider adding map visualization (future enhancement)

---

**Need Help?** Check the test page at `/resources/views/pages/dashboard/test_location_recommendations.php` for detailed debugging information.


