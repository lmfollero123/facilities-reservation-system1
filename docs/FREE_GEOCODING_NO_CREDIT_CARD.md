# Free Geocoding Setup (No Credit Card Required)
## Using Photon and OpenStreetMap - Completely Free!

---

## ✅ Current Configuration

**Your system is now configured to use FREE geocoding services that require NO signup and NO credit card:**

1. **Photon (Komoot)** - Default, best free option
   - ✅ No signup required
   - ✅ No credit card required
   - ✅ Good accuracy (often better than basic OSM)
   - ✅ Works immediately

2. **OpenStreetMap Nominatim** - Fallback
   - ✅ No signup required
   - ✅ No credit card required
   - ✅ Free forever
   - ✅ Improved parameters for better accuracy

---

## How It Works

**Geocoding Priority (Automatic):**
1. **Photon** (tries first) - Free, no signup, good accuracy
2. **OpenStreetMap** (fallback) - Free, no signup, basic accuracy
3. **Mapbox** (if configured) - Requires credit card
4. **Google Maps** (if configured) - Paid

**You don't need to do anything!** The system automatically uses Photon, which requires no configuration.

---

## Testing

1. **Go to Profile Page**
   - Dashboard → Profile
   - Enter an address: "Barangay Culiat, Quezon City"
   - Click outside the field or wait 800ms
   - You should see: "✓ Coordinates updated from address"
   - Latitude and Longitude should auto-fill

2. **Test Different Addresses**
   - "Quezon City Hall, Quezon City"
   - "SM North EDSA, Quezon City"
   - "123 Main Street, Barangay Culiat, Quezon City"

---

## Accuracy

**Photon Geocoding:**
- ✅ Good accuracy for most addresses
- ✅ Often finds exact building locations
- ✅ Better than basic OpenStreetMap queries
- ⚠️ May not always be rooftop-level (but usually close)

**OpenStreetMap (with improved parameters):**
- ✅ Better accuracy with country bias (Philippines)
- ✅ Improved with deduplication
- ⚠️ May be barangay-level for some addresses

**For best results:**
- Use complete addresses: "Street, Barangay, City, Province"
- Include landmarks: "Near SM North, Quezon City"
- Be specific: "Barangay Culiat, Quezon City" (not just "Quezon City")

---

## Optional: Improve Accuracy Further

If you want even better accuracy in the future:

1. **Mapbox** (if you get a credit card later)
   - Rooftop-level accuracy
   - 100k requests/month free
   - Requires credit card for signup (but free tier is generous)

2. **Google Maps** (if you have budget)
   - Rooftop-level accuracy
   - Paid service

**For now, Photon is perfect for your needs!** ✅

---

## Configuration

**Current default (no action needed):**
```php
// In config/geocoding.php
define('GEOCODING_PROVIDER', 'photon'); // Free, no signup, no credit card
```

**To use OpenStreetMap instead:**
```php
// In config/geocoding_config.php (if you create it)
define('GEOCODING_PROVIDER', 'osm');
```

**To use Mapbox (if you get it later):**
```php
// In config/geocoding_config.php
define('MAPBOX_ACCESS_TOKEN', 'pk.your_token');
define('GEOCODING_PROVIDER', 'mapbox');
```

---

## Troubleshooting

### "Could not find coordinates for this address"

**Try:**
1. More complete address: "Street Name, Barangay, City, Province"
2. Include landmarks: "Near [Landmark], City"
3. System will automatically try Photon → OSM → (others if configured)

### "Geocoding unavailable"

**Check:**
1. Internet connection
2. Try again (may be temporary API issue)
3. System will automatically fallback to OSM if Photon fails

---

## Summary

✅ **No configuration needed** - Photon works immediately  
✅ **No signup required** - Completely free  
✅ **No credit card required** - No payment needed  
✅ **Good accuracy** - Better than basic OSM  
✅ **Automatic fallback** - Always gets coordinates if possible  

**You're all set!** Just use the system normally - geocoding will work automatically. 🎉

---

**Last Updated**: January 2026
