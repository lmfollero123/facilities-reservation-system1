# LGU Integration Deployment & Testing Guide

## Overview

This guide covers deploying and testing the LGU Maintenance System integration between:
- **CPRF Live**: `https://cprf.infragovservices.com`
- **CIMM Live**: `https://cimm.infragovservices.com`

## Deployment Steps

### 1. Deploy to CPRF Live System

**Files to deploy:**
- `public/api/facilities-share.php` - NEW file
- `public/api/maintenance-webhook.php` - NEW file
- `docs/LGU_INTEGRATION.md` - NEW documentation

**Deployment commands:**
```bash
# Push changes to git
git add public/api/facilities-share.php
git add public/api/maintenance-webhook.php
git add docs/LGU_INTEGRATION.md
git commit -m "Add LGU Maintenance System integration endpoints"
git push origin main

# Or deploy via your hosting panel
```

**Verify deployment:**
```bash
# Test facilities share endpoint
curl "https://cprf.infragovservices.com/public/api/facilities-share.php?key=FACILITIES_SECURE_KEY_2025"

# Expected response: JSON with facility data
```

### 2. Deploy to LGU/CIMM Live System

**Files to deploy:**
- `INTEGRATION/LGU/lgu-portal/public/api/maintenance-schedules.php` - MODIFIED
- `INTEGRATION/LGU/lgu-portal/public/api/fetch-facilities.php` - NEW file

**Changes in maintenance-schedules.php:**
- Added `sendFacilityStatusUpdate()` function
- Added facility fetching from CPRF system
- Added automatic status update triggers
- Updated webhook URL to `https://cprf.infragovservices.com/public/api/maintenance-webhook.php`
- Updated facilities API URL to `https://cprf.infragovservices.com/public/api/facilities-share.php`

**Deployment commands:**
```bash
# In the LGU system repository
git add INTEGRATION/LGU/lgu-portal/public/api/maintenance-schedules.php
git add INTEGRATION/LGU/lgu-portal/public/api/fetch-facilities.php
git commit -m "Add CPRF facilities integration and webhook notifications"
git push origin main
```

**Verify deployment:**
```bash
# Test maintenance schedules API
curl "https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025"

# Expected response: JSON with maintenance schedules
```

## Testing Procedure

### Phase 1: API Connectivity Testing

**Test 1: CPRF → CIMM (Facilities Share)**
```bash
curl "https://cprf.infragovservices.com/public/api/facilities-share.php?key=FACILITIES_SECURE_KEY_2025"
```
Expected: JSON response with facility data including coordinates

**Test 2: CIMM → CPRF (Maintenance Schedules)**
```bash
curl "https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025"
```
Expected: JSON response with maintenance schedules

**Test 3: CIMM → CPRF (Webhook)**
```bash
curl -X POST \
  https://cprf.infragovservices.com/public/api/maintenance-webhook.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer LGU_TO_FACILITIES_KEY_2025" \
  -d '{
    "facility_name": "Cassanova Multi-Purpose Building",
    "maintenance_status": "in_progress",
    "action": "start_maintenance"
  }'
```
Expected: JSON response with success status

### Phase 2: Integration Testing

**Test 4: Facility Detection**
1. Access CIMM maintenance schedules API
2. Verify it fetches facilities from CPRF
3. Check that facility matching works (coordinates + keywords)

**Test 5: Maintenance Status Sync**
1. In CIMM system, create a maintenance schedule for a known facility
2. Set status to "In Progress"
3. Verify webhook is called automatically
4. Check CPRF facility status changes to "maintenance"
5. Verify reservations are handled (cancelled/postponed)

**Test 6: Maintenance Completion**
1. In CIMM system, mark maintenance as "Completed"
2. Verify webhook is called automatically
3. Check CPRF facility status changes to "available"
4. Verify users with postponed reservations are notified

### Phase 3: UI Testing

**Test 7: CPRF Maintenance Integration Page**
1. Access `https://cprf.infragovservices.com/dashboard/maintenance-integration`
2. Verify connection status shows "Connected"
3. Click "Sync Now" button
4. Verify schedules are displayed
5. Check facility status updates in database

**Test 8: Facility Management Page**
1. Access facility management in CPRF
2. Verify facility status changes are reflected
3. Check that facilities can be manually set to maintenance

## Localhost vs Live Hosting

### Localhost Testing
- **CPRF Local**: `http://localhost/facilities-reservation-system1`
- **LGU Local**: `http://localhost/INTEGRATION/LGU/lgu-portal/public`

**Current localhost configuration:**
- LGU webhook URL: `https://cprf.infragovservices.com/public/api/maintenance-webhook.php` (points to live)
- LGU facilities API: `https://cprf.infragovservices.com/public/api/facilities-share.php` (points to live)
- CPRF CIMM API: `https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php` (points to live)

**For pure localhost testing**, you would need to:
1. Change webhook URL in LGU to `http://localhost/facilities-reservation-system1/public/api/maintenance-webhook.php`
2. Change facilities API URL in LGU to `http://localhost/facilities-reservation-system1/public/api/facilities-share.php`
3. Change CIMM API URL in CPRF to `http://localhost/INTEGRATION/LGU/lgu-portal/public/api/maintenance-schedules.php`

### Live Hosting Testing
- **CPRF Live**: `https://cprf.infragovservices.com`
- **CIMM Live**: `https://cimm.infragovservices.com`

**Current live configuration (what we just deployed):**
- LGU webhook URL: `https://cprf.infragovservices.com/public/api/maintenance-webhook.php` ✓
- LGU facilities API: `https://cprf.infragovservices.com/public/api/facilities-share.php` ✓
- CPRF CIMM API: `https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php` ✓

## Rollback Plan

If issues occur during testing:

### Rollback CPRF Changes
```bash
# Remove the new API endpoints
rm public/api/facilities-share.php
rm public/api/maintenance-webhook.php
git commit -m "Rollback LGU integration endpoints"
git push origin main
```

### Rollback LGU Changes
```bash
# Revert maintenance-schedules.php to previous version
git checkout HEAD~1 INTEGRATION/LGU/lgu-portal/public/api/maintenance-schedules.php
rm INTEGRATION/LGU/lgu-portal/public/api/fetch-facilities.php
git commit -m "Rollback CPRF integration"
git push origin main
```

## Monitoring

### Log Files to Monitor

**CPRF System:**
- Error logs for webhook failures
- Audit logs for facility status changes
- Reservation history for automated changes

**LGU System:**
- Error logs for webhook send failures
- Maintenance schedule logs
- API access logs

### Key Metrics

- Webhook success rate
- Facility status update latency
- Reservation handling accuracy
- API response times

## Security Considerations

1. **API Keys**: Ensure production API keys are:
   - Different from development keys
   - Stored in environment variables
   - Rotated regularly

2. **HTTPS**: All production endpoints must use HTTPS

3. **Rate Limiting**: Consider implementing rate limiting on webhook endpoints

4. **IP Whitelisting**: Consider whitelisting CIMM server IP for webhook access

## Contact Information

**For deployment issues:**
- CPRF Team: [contact info]
- CIMM/LGU Team: [contact info]

**For integration support:**
- Technical documentation: `docs/LGU_INTEGRATION.md`
- API reference: `docs/LGU_INTEGRATION.md`
