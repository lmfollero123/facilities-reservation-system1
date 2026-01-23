# CIMM API Integration Guide

## Overview
This document provides instructions for setting up the API endpoint on **CIMM (Community Infrastructure Maintenance Management)** system to send maintenance schedule data to **CPRF (Facilities Reservation System)**.

## System Roles
- **CIMM** (`cimm.infragovservices.com`) - **Data Provider** (sends maintenance schedules)
- **CPRF** (`cprf.infragovservices.com`) - **Data Consumer** (receives and displays maintenance schedules)

---

## PART 1: CREATE API ENDPOINT ON CIMM DOMAIN

### File Location
Create the following file on your CIMM server:
```
/lgu-portal/public/api/maintenance-schedules.php
```

**Note**: Based on your server structure, the file should be at:
```
C:\xampp\htdocs\LGU\lgu-portal\public\api\maintenance-schedules.php
```

### API Code (READ-ONLY)
```php
<?php
// api/maintenance-schedules.php

require_once __DIR__ . '/../config/db.php';

// Set JSON header
header('Content-Type: application/json');

// CORS Headers - Allow only CPRF domain
header('Access-Control-Allow-Origin: https://cprf.infragovservices.com');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONAL: Simple API key for security
$API_KEY = 'CIMM_SECURE_KEY_2025';
if (($_GET['key'] ?? '') !== $API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// SQL Query to fetch maintenance schedules
$sql = "
    SELECT 
        sched_id,
        task,
        location,
        category,
        priority,
        status,
        assigned_team,
        starting_date,
        estimated_completion_date,
        created_at
    FROM maintenance_schedule
    ORDER BY starting_date ASC
";

$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

// Return JSON response
echo json_encode([
    'success' => true,
    'count' => count($data),
    'data' => $data
]);
```

### Security Notes
✅ **DB credentials stay private** - Only CIMM server has access  
✅ **Only CPRF domain allowed** - CORS restricts access  
✅ **API key required** - Prevents unauthorized access  
✅ **HTTPS used** - Secure data transmission  

---

## PART 2: API ENDPOINT DETAILS

### Endpoint URL
```
https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php
```

### Request Method
`GET`

### Required Parameters
| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `key` | string | API authentication key | `CIMM_SECURE_KEY_2025` |

### Example Request
```
GET https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025
```

---

## PART 3: RESPONSE FORMAT

### Success Response (200 OK)
```json
{
    "success": true,
    "count": 15,
    "data": [
        {
            "sched_id": "1",
            "task": "HVAC System Inspection",
            "location": "Community Convention Hall",
            "category": "HVAC / Cooling",
            "priority": "High",
            "status": "Scheduled",
            "assigned_team": "Facilities - HVAC Team",
            "starting_date": "2026-01-15 08:00:00",
            "estimated_completion_date": "2026-01-15 12:00:00",
            "created_at": "2026-01-10 10:30:00"
        },
        {
            "sched_id": "2",
            "task": "Electrical System Check",
            "location": "Municipal Sports Complex",
            "category": "Power & Electrical",
            "priority": "Medium",
            "status": "In Progress",
            "assigned_team": "Electrical Maintenance Team",
            "starting_date": "2026-01-20 09:00:00",
            "estimated_completion_date": "2026-01-22 17:00:00",
            "created_at": "2026-01-18 14:20:00"
        }
    ]
}
```

### Error Response (401 Unauthorized)
```json
{
    "error": "Unauthorized"
}
```

---

## PART 4: DATA FIELD MAPPING

### CIMM Database Fields → CPRF UI Fields

| CIMM DB Field | CPRF UI Field | Description |
|---------------|---------------|-------------|
| `sched_id` | Maintenance ID | Unique schedule identifier |
| `task` | Maintenance Type | Type of maintenance task |
| `location` | Facility Name | Name of the facility |
| `category` | Category | Maintenance category (HVAC, Electrical, etc.) |
| `priority` | Priority | Priority level (Low, Medium, High, Critical) |
| `status` | Status | Current status (Scheduled, In Progress, Completed, etc.) |
| `assigned_team` | Team | Team assigned to the maintenance |
| `starting_date` | Start Date | When maintenance starts (YYYY-MM-DD HH:MM:SS) |
| `estimated_completion_date` | End Date | Expected completion date (YYYY-MM-DD HH:MM:SS) |
| `created_at` | Created At | When the schedule was created |

---

## PART 5: DATE FORMAT REQUIREMENTS

### Required Format
- **Date Format**: `YYYY-MM-DD HH:MM:SS`
- **Example**: `2026-01-15 08:00:00`

### Valid Status Values
- `Scheduled`
- `In Progress` (or `In Progress`)
- `Completed`
- `Delayed`
- `Cancelled`

### Valid Priority Values
- `Low`
- `Medium`
- `High`
- `Critical`

---

## PART 6: TESTING THE API

### Using cURL
```bash
curl "https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025"
```

### Using Browser
Open in browser:
```
https://cimm.infragovservices.com/lgu-portal/public/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025
```

### Expected Result
You should see JSON data with maintenance schedules.

---

## PART 7: TROUBLESHOOTING

### Issue: 401 Unauthorized
**Solution**: Check that the API key matches exactly: `CIMM_SECURE_KEY_2025`

### Issue: CORS Error
**Solution**: Ensure the `Access-Control-Allow-Origin` header includes `https://cprf.infragovservices.com`

### Issue: Empty Data Array
**Solution**: 
- Check that `maintenance_schedule` table exists
- Verify table has data
- Check database connection in `config/db.php`

### Issue: Date Format Errors
**Solution**: Ensure dates are in `YYYY-MM-DD HH:MM:SS` format

---

## PART 8: SECURITY RECOMMENDATIONS

1. **Change API Key**: Replace `CIMM_SECURE_KEY_2025` with a strong, unique key
2. **HTTPS Only**: Ensure API is only accessible via HTTPS
3. **Rate Limiting**: Consider adding rate limiting to prevent abuse
4. **IP Whitelisting**: Optionally restrict access to CPRF server IP
5. **Logging**: Log all API access for security monitoring

---

## PART 9: INTEGRATION STATUS

Once the API is set up:
- CPRF will automatically fetch data every time the Maintenance Integration page loads
- Data syncs in real-time
- No manual intervention needed after initial setup

---

## CONTACT

For integration support or questions, contact the CPRF development team.

---

**Last Updated**: January 2026  
**Version**: 1.0
