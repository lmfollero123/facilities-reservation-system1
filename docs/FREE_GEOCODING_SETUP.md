# Free Geocoding Setup Guide

## Overview
The system now uses **OpenStreetMap Nominatim** for geocoding by default - **100% FREE** with no API key or credit card required!

## ✅ What's Included (FREE)

- ✅ **No API Key Required**
- ✅ **No Credit Card Required**
- ✅ **No Registration Required**
- ✅ **Unlimited Use** (with reasonable rate limiting)
- ✅ **Worldwide Coverage**
- ✅ **Automatic Setup** - Works out of the box!

## How It Works

### Default Configuration
The system is already configured to use OpenStreetMap Nominatim. No setup needed!

**File**: `config/geocoding.php`
```php
define('USE_OSM_GEOCODING', true); // Already set to true
```

### Rate Limiting
OpenStreetMap allows **1 request per second**. The system handles this automatically:
- When users add/update addresses, geocoding happens automatically
- Small delays are added between requests if needed
- Coordinates are cached in database (no repeated geocoding)

## Usage Examples

### For Users
1. Go to **Profile** page
2. Enter your address (e.g., "Barangay Culiat, Quezon City, Philippines")
3. Click **Save Changes**
4. System automatically geocodes your address
5. Coordinates are saved automatically

### For Administrators
1. Go to **Facility Management**
2. Add/edit a facility
3. Enter location address (e.g., "Municipal Hall, Barangay Culiat, Quezon City")
4. Click **Save Facility**
5. System automatically geocodes the address
6. Coordinates are saved automatically

## Address Format Tips

For best geocoding results, use complete addresses:

**Good Examples:**
- "Barangay Culiat, Quezon City, Metro Manila, Philippines"
- "Municipal Hall, Barangay Culiat, Quezon City"
- "123 Main Street, Barangay Culiat, Quezon City, Philippines"

**Less Specific (may still work):**
- "Barangay Culiat"
- "Quezon City"

**Tips:**
- Include city/town name
- Include province/region if applicable
- Include country for international addresses
- Be as specific as possible

## Testing Geocoding

### Test in Profile
1. Add a test address
2. Save profile
3. Check if coordinates appear
4. If coordinates show: ✅ Geocoding working!
5. If not: Check error logs or try more specific address

### Test via SQL
```sql
-- Check if coordinates were saved
SELECT id, name, address, latitude, longitude 
FROM users 
WHERE address IS NOT NULL;

-- Should show latitude and longitude if geocoding succeeded
```

## Troubleshooting

### Problem: Coordinates Not Saving

**Check 1: Address Format**
- Try a more specific address
- Include city and country
- Example: "Barangay Culiat, Quezon City, Philippines"

**Check 2: Internet Connection**
- OpenStreetMap requires internet access
- Check if server can access external APIs

**Check 3: PHP Error Logs**
- Check PHP error logs for geocoding errors
- Look for "OSM Geocoding" messages

**Check 4: Rate Limiting**
- If geocoding multiple addresses, wait 1 second between saves
- System handles this automatically, but manual testing might need delays

### Problem: Wrong Coordinates

**Solution:**
- Try a more specific address
- Include landmarks or street names
- Manually adjust coordinates if needed (manual entry takes priority)

### Problem: Geocoding Slow

**Normal Behavior:**
- OpenStreetMap has 1 request/second limit
- First geocoding may take 1-2 seconds
- Coordinates are cached, so subsequent loads are instant

## Manual Override

You can always manually enter coordinates:
1. Enter address (for reference)
2. Manually enter Latitude and Longitude
3. Manual coordinates take priority over geocoding
4. Useful for very specific locations

## Comparison: OSM vs Google Maps

| Feature | OpenStreetMap (Default) | Google Maps |
|---------|-------------------------|-------------|
| **Cost** | ✅ FREE Forever | 💰 Free tier, then paid |
| **API Key** | ❌ Not needed | ✅ Required |
| **Credit Card** | ❌ Not needed | ✅ Required |
| **Rate Limit** | 1 req/sec | 50 req/sec |
| **Coverage** | Worldwide | Worldwide |
| **Accuracy** | Very Good | Excellent |
| **Setup** | ✅ Automatic | ⚙️ Manual |

## Switching to Google Maps (Optional)

If you want to use Google Maps instead:

1. Get Google Maps API Key (requires credit card)
2. Edit `config/geocoding.php`:
   ```php
   define('USE_OSM_GEOCODING', false);
   define('GOOGLE_MAPS_API_KEY', 'YOUR_API_KEY_HERE');
   ```

## Best Practices

1. **Use Complete Addresses**: More specific = better results
2. **Cache Coordinates**: System automatically caches, no need to geocode twice
3. **Manual Override**: Use manual coordinates for very specific locations
4. **Test First**: Test with a few addresses before bulk import
5. **Monitor Usage**: Check error logs if geocoding fails

## FAQ

**Q: Is OpenStreetMap really free?**
A: Yes! 100% free, forever. No hidden costs.

**Q: Do I need to register?**
A: No registration required. Just use it!

**Q: What's the catch?**
A: Rate limit of 1 request per second (automatically handled by the system).

**Q: Can I use both OSM and Google Maps?**
A: Yes! System tries OSM first, falls back to Google if configured.

**Q: What if geocoding fails?**
A: You can manually enter coordinates. Manual entry takes priority.

**Q: Is it accurate?**
A: Very accurate! OpenStreetMap has excellent coverage, especially for urban areas.

---

**Ready to Use!** The system is already configured. Just add addresses and they'll be automatically geocoded! 🎉

