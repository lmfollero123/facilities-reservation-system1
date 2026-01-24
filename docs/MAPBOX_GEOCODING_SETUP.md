# Mapbox Geocoding Setup Guide
## Free Rooftop-Level Geocoding for Facilities Reservation System

**Why Mapbox?**
- ✅ **FREE**: 100,000 requests/month (more than enough for most LGUs)
- ✅ **Rooftop Accuracy**: Pinpoints exact house/building locations (not just barangay-level)
- ✅ **No Credit Card Required**: Free tier is generous
- ✅ **Easy Setup**: Just get an access token

---

## Step 1: Get Mapbox Access Token

1. **Sign up for Mapbox** (free account)
   - Go to: https://account.mapbox.com/signup/
   - Create an account (or sign in if you have one)

2. **Get Your Access Token**
   - After signing in, go to: https://account.mapbox.com/access-tokens/
   - You'll see a **Default Public Token** (starts with `pk.`)
   - **Copy this token** (you'll need it in Step 2)

3. **Token Permissions**
   - The default token has geocoding permissions enabled by default
   - No additional configuration needed!

---

## Step 2: Configure Your Application

1. **Copy the example config file**
   ```bash
   cp config/geocoding_config.example.php config/geocoding_config.php
   ```

2. **Edit `config/geocoding_config.php`**
   ```php
   <?php
   define('MAPBOX_ACCESS_TOKEN', 'pk.your_actual_token_here');
   ```

3. **Save the file**
   - Make sure `geocoding_config.php` is NOT committed to git (contains your token)

---

## Step 3: Test Geocoding

1. **Go to Profile Page**
   - Navigate to: Dashboard → Profile
   - Enter an address in the "Address" field
   - Click outside the field (blur) or wait 800ms
   - You should see: "✓ Coordinates updated from address"
   - Latitude and Longitude fields should auto-fill

2. **Test with Different Addresses**
   - Try: "123 Main Street, Barangay Culiat, Quezon City"
   - Try: "Quezon City Hall, Quezon City"
   - Try: "SM North EDSA, Quezon City"
   - All should geocode to rooftop-level coordinates

---

## How It Works

**Geocoding Priority:**
1. **Mapbox** (if token configured) - Rooftop accuracy, free
2. **Google Maps** (if API key configured) - Rooftop accuracy, paid
3. **OpenStreetMap** (fallback) - Free, but less accurate (barangay-level)

**Automatic Fallback:**
- If Mapbox fails or token is missing, system tries Google Maps
- If Google Maps fails or key is missing, system falls back to OpenStreetMap
- You always get coordinates, even if one service fails

---

## Free Tier Limits

**Mapbox Free Tier:**
- ✅ 100,000 geocoding requests per month
- ✅ Rooftop-level accuracy
- ✅ No credit card required
- ✅ Perfect for LGU facilities reservation system

**Typical Usage:**
- User profile updates: ~100-500/month
- Facility management: ~10-50/month
- **Total: Well under 100k/month** ✅

---

## Troubleshooting

### Error: "Could not find coordinates for this address"

**Possible Causes:**
1. Mapbox token not configured or invalid
2. Address format unclear (try adding city/province)
3. Network/API issue

**Solutions:**
1. Check `config/geocoding_config.php` has valid token
2. Try a more complete address: "Street Name, Barangay, City, Province"
3. Check browser console for API errors
4. System will automatically fallback to OpenStreetMap if Mapbox fails

### Error: "Geocoding unavailable"

**Cause:** All geocoding services failed

**Solution:**
- Check internet connection
- Verify Mapbox token is correct
- Try again (may be temporary API issue)

---

## Advanced Configuration

### Use Google Maps Instead

If you prefer Google Maps (requires paid API key):

```php
// In config/geocoding_config.php
define('GOOGLE_MAPS_API_KEY', 'your_google_key');
define('GEOCODING_PROVIDER', 'google');
```

### Use OpenStreetMap Only

If you want to use only free OSM (no API key needed, but less accurate):

```php
// In config/geocoding_config.php
define('GEOCODING_PROVIDER', 'osm');
```

### Force Specific Provider

```php
// In config/geocoding_config.php
define('GEOCODING_PROVIDER', 'mapbox'); // or 'google' or 'osm'
```

---

## Security Notes

⚠️ **Important:**
- Never commit `geocoding_config.php` to version control
- Keep your Mapbox token private
- If token is exposed, regenerate it at: https://account.mapbox.com/access-tokens/

---

## Support

- **Mapbox Documentation**: https://docs.mapbox.com/api/search/geocoding/
- **Mapbox Account**: https://account.mapbox.com/
- **Free Tier Info**: https://www.mapbox.com/pricing/

---

**Last Updated**: January 2026
