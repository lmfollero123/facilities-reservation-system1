# CIMM Integration Setup Guide (For CPRF Team)

## Quick Answer: How to Connect to CIMM

**You don't need to do anything in your database!** The connection happens via API calls.

### What You Need to Do:

1. **Share the documentation with CIMM team**
   - Give them: `docs/CIMM_API_INTEGRATION.md`
   - This tells them exactly how to set up their API endpoint

2. **Wait for CIMM to set up their API**
   - They need to create: `https://cimm.infragovservices.com/api/maintenance-schedules.php`
   - They need to use API key: `CIMM_SECURE_KEY_2025`

3. **Test the connection**
   - Run: `php test_cimm_connection.php` from your project root
   - Or check the Maintenance Integration page - it will show connection errors

---

## Current Status: "Disconnected"

### Why It Shows Disconnected:

The system shows "Disconnected" because:
- ❌ CIMM API endpoint doesn't exist yet, OR
- ❌ API endpoint exists but returns an error, OR
- ❌ Network/SSL issues preventing connection

### What Happens Automatically:

✅ **Your system (CPRF) is already configured** to fetch data from CIMM  
✅ **No database changes needed** - data is fetched via API  
✅ **Automatic sync** - Data refreshes every time you load the page  

---

## Step-by-Step Setup Process

### Step 1: Share Documentation with CIMM
```
File to share: docs/CIMM_API_INTEGRATION.md
```

This document contains:
- Complete API code they need to implement
- Security configuration
- Testing instructions
- Troubleshooting guide

### Step 2: CIMM Sets Up Their API

CIMM needs to:
1. Create file: `/api/maintenance-schedules.php` on their server
2. Use the code from `CIMM_API_INTEGRATION.md`
3. Ensure their `maintenance_schedule` table has data
4. Test the endpoint: `https://cimm.infragovservices.com/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025`

### Step 3: Test Connection

**Option A: Command Line Test**
```bash
php test_cimm_connection.php
```

**Option B: Browser Test**
1. Go to Maintenance Integration page
2. Check the Integration Status card
3. If connected: Shows "✓ Connected" with green badge
4. If disconnected: Shows error message with solution

**Option C: Direct API Test**
Open in browser:
```
https://cimm.infragovservices.com/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025
```

Should return JSON like:
```json
{
    "success": true,
    "count": 5,
    "data": [...]
}
```

---

## Troubleshooting

### Error: "Connection failed: Unable to reach CIMM API"
**Cause**: CIMM API endpoint doesn't exist or is unreachable  
**Solution**: Ask CIMM to create the API endpoint

### Error: "API endpoint not found: 404"
**Cause**: CIMM hasn't created `/api/maintenance-schedules.php` yet  
**Solution**: Share `CIMM_API_INTEGRATION.md` with CIMM team

### Error: "Unauthorized: API key incorrect"
**Cause**: API key mismatch  
**Solution**: Verify CIMM is using `CIMM_SECURE_KEY_2025` (or update your code to match theirs)

### Error: "CORS Error" (in browser console)
**Cause**: CIMM hasn't set CORS headers correctly  
**Solution**: CIMM needs to add: `Access-Control-Allow-Origin: https://cprf.infragovservices.com`

### Status: Connected but "No schedules found"
**Cause**: Connection works but CIMM database has no maintenance schedules  
**Solution**: This is normal - wait for CIMM to add maintenance schedules

---

## How It Works (Technical)

1. **Page Load**: When you open Maintenance Integration page
2. **API Call**: System calls `fetchCIMMMaintenanceSchedules()` function
3. **HTTP Request**: Makes GET request to CIMM API
4. **Data Mapping**: Converts CIMM format to CPRF format
5. **Display**: Shows schedules in calendar and list views

**No database writes needed** - All data comes from CIMM API in real-time!

---

## Configuration

### Current API Settings (in `services/cimm_api.php`):

```php
$apiUrl = 'https://cimm.infragovservices.com/api/maintenance-schedules.php';
$apiKey = 'CIMM_SECURE_KEY_2025';
```

### To Change API Key:

If CIMM uses a different API key, update `services/cimm_api.php`:
```php
$apiKey = 'YOUR_NEW_API_KEY';
```

---

## Next Steps

1. ✅ **Share** `docs/CIMM_API_INTEGRATION.md` with CIMM team
2. ⏳ **Wait** for CIMM to set up their API endpoint
3. ✅ **Test** using `php test_cimm_connection.php`
4. ✅ **Verify** on Maintenance Integration page

---

## Support

If connection issues persist:
1. Check PHP error logs for detailed error messages
2. Run `test_cimm_connection.php` for diagnostic info
3. Verify CIMM API is accessible from your server
4. Check firewall/network settings

---

**Remember**: You (CPRF) are the **consumer** - you receive data.  
CIMM is the **provider** - they send data.  
You just need to wait for them to set up their API endpoint!
