# LGU Maintenance System Integration

## Overview

This document describes the integration between the Facilities Reservation System and the LGU Maintenance System (CIMM). The integration enables automatic facility detection and maintenance status synchronization between the two systems.

## Architecture

The integration is a two-way synchronization:

1. **Facilities System → LGU Maintenance System**: Shares facility data for automatic detection
2. **LGU Maintenance System → Facilities System**: Sends maintenance status updates to automatically set facility status

## API Endpoints

### Facilities System Endpoints

#### 1. Share Facility Data
**Endpoint**: `GET /public/api/facilities-share.php`

**Purpose**: Provides facility data to the LGU Maintenance System for automatic facility detection and matching.

**Authentication**: API Key (`FACILITIES_SECURE_KEY_2025`)

**Request Parameters**:
- `key` (required): API key for authentication

**Response Format**:
```json
{
  "success": true,
  "count": 10,
  "generated_at": "2026-07-10 01:57:00 PHT",
  "data": [
    {
      "facility_id": 1,
      "name": "Cassanova Multi-Purpose Building",
      "location": "Culiat, Quezon City",
      "description": "Multi-purpose facility for community events",
      "capacity": "200",
      "amenities": "Chairs, Tables, Sound System",
      "latitude": 14.69679995,
      "longitude": 121.07769286,
      "operating_hours": "8:00 AM - 8:00 PM",
      "current_status": "available",
      "created_at": "2026-01-01 00:00:00",
      "updated_at": "2026-07-10 00:00:00"
    }
  ]
}
```

#### 2. Receive Maintenance Status Updates (Webhook)
**Endpoint**: `POST /public/api/maintenance-webhook.php`

**Purpose**: Receives maintenance status updates from the LGU Maintenance System and automatically updates facility status.

**Authentication**: Bearer Token (`LGU_TO_FACILITIES_KEY_2025`)

**Request Headers**:
- `Content-Type: application/json`
- `Authorization: Bearer LGU_TO_FACILITIES_KEY_2025`

**Request Body**:
```json
{
  "facility_name": "Cassanova Multi-Purpose Building",
  "maintenance_status": "in_progress",
  "action": "start_maintenance"
}
```

**Actions**:
- `start_maintenance`: Sets facility status to 'maintenance' and handles existing reservations
- `end_maintenance`: Sets facility status back to 'available' and notifies users with postponed reservations
- `update_status`: Updates maintenance status without changing facility status

**Response Format**:
```json
{
  "success": true,
  "message": "Facility status updated successfully",
  "processed_at": "2026-07-10 01:57:00 PHT",
  "result": {
    "facility_id": 1,
    "facility_name": "Cassanova Multi-Purpose Building",
    "previous_status": "available",
    "new_status": "maintenance",
    "action_taken": "Facility set to maintenance status",
    "reservations_affected": {
      "pending_cancelled": 2,
      "approved_postponed": 1,
      "errors": []
    }
  }
}
```

### LGU Maintenance System Endpoints

#### 1. Maintenance Schedules API (Enhanced)
**Endpoint**: `GET /api/maintenance-schedules.php`

**Purpose**: Provides maintenance schedule data to the Facilities Reservation System and now also sends status updates when maintenance status changes.

**Authentication**: API Key (`CIMM_SECURE_KEY_2025`)

**Enhanced Behavior**:
- Automatically fetches facility data from the Facilities Reservation System
- Matches maintenance schedules to facilities using coordinates and keywords
- Sends status updates to the Facilities Reservation System webhook when:
  - Maintenance status changes to "In Progress" or "Delayed"
  - Maintenance status changes to "Completed"

**Facility Matching**:
- Uses 200m radius matching for coordinates
- Uses keyword matching for location text
- Falls back to hardcoded facilities if API fetch fails

## Integration Flow

### 1. Facility Detection Flow

```
LGU Maintenance System
    ↓
Fetches facilities from /api/facilities-share.php
    ↓
Stores facility names, coordinates, and keywords
    ↓
Matches maintenance schedules to facilities
    ↓
Sends status updates when maintenance status changes
```

### 2. Maintenance Status Update Flow

```
LGU Maintenance System
    ↓
Engineer sets maintenance status to "In Progress"
    ↓
API detects status change
    ↓
Sends POST to /api/maintenance-webhook.php
    ↓
Facilities System receives update
    ↓
Updates facility status to "maintenance"
    ↓
Handles existing reservations:
  - Pending reservations → Cancelled
  - Approved reservations → Postponed with priority
    ↓
Sends notifications to affected users
```

### 3. Maintenance Completion Flow

```
LGU Maintenance System
    ↓
Engineer sets maintenance status to "Completed"
    ↓
API detects status change
    ↓
Sends POST to /api/maintenance-webhook.php
    ↓
Facilities System receives update
    ↓
Updates facility status to "available"
    ↓
Notifies users with postponed reservations
    ↓
Users can reschedule with priority
```

## Reservation Handling

When a facility goes under maintenance:

### Pending Reservations
- Status: Cancelled
- Notification: Sent to user
- History: Recorded with reason

### Approved Reservations
- Status: Postponed
- Priority Flag: Set to TRUE
- Notification: Email + in-app notification
- History: Recorded with reason

When a facility becomes available again:

### Postponed Reservations
- Notification: Email + in-app notification
- Priority: Maintained for rescheduling
- Action: User can reschedule with priority

## API Keys

| System | Key | Purpose |
|--------|-----|---------|
| Facilities System | `FACILITIES_SECURE_KEY_2025` | Authenticates requests to share facility data |
| Facilities System | `LGU_TO_FACILITIES_KEY_2025` | Authenticates webhook requests from LGU system |
| LGU Maintenance System | `CIMM_SECURE_KEY_2025` | Authenticates requests to maintenance schedules API |

## Configuration

### Facilities System

Update the webhook URL in the LGU Maintenance System if your facilities system is hosted at a different URL:

**File**: `INTEGRATION/LGU/lgu-portal/public/api/maintenance-schedules.php`
```php
$webhookUrl = 'http://localhost/facilities-reservation-system1/public/api/maintenance-webhook.php';
```

### LGU Maintenance System

Update the facilities API URL if your facilities system is hosted at a different URL:

**File**: `INTEGRATION/LGU/lgu-portal/public/api/maintenance-schedules.php`
```php
$facilitiesApiUrl = 'http://localhost/facilities-reservation-system1/public/api/facilities-share.php?key=FACILITIES_SECURE_KEY_2025';
```

## Testing

### Test Facility Data Sharing
```bash
curl "http://localhost/facilities-reservation-system1/public/api/facilities-share.php?key=FACILITIES_SECURE_KEY_2025"
```

### Test Maintenance Status Update
```bash
curl -X POST \
  http://localhost/facilities-reservation-system1/public/api/maintenance-webhook.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer LGU_TO_FACILITIES_KEY_2025" \
  -d '{
    "facility_name": "Cassanova Multi-Purpose Building",
    "maintenance_status": "in_progress",
    "action": "start_maintenance"
  }'
```

### Test LGU Maintenance Schedules API
```bash
curl "http://localhost/INTEGRATION/LGU/lgu-portal/public/api/maintenance-schedules.php?key=CIMM_SECURE_KEY_2025"
```

## Security Considerations

1. **API Keys**: Keep API keys secure and rotate them regularly
2. **HTTPS**: Use HTTPS in production for all API calls
3. **IP Whitelisting**: Consider implementing IP whitelisting for webhook endpoints
4. **Rate Limiting**: Implement rate limiting to prevent abuse
5. **Input Validation**: All inputs are validated and sanitized
6. **Error Handling**: Errors are logged but not exposed to clients

## Troubleshooting

### Facility Not Detected
- Check if facility has coordinates in the Facilities System
- Verify facility name matches or contains keywords
- Check LGU Maintenance System logs for API fetch errors
- Ensure API key is correct

### Status Update Not Received
- Check webhook URL is correct in LGU Maintenance System
- Verify API key is correct
- Check Facilities System logs for webhook errors
- Ensure network connectivity between systems

### Reservations Not Handled
- Check if facility status actually changed
- Verify maintenance_helper.php is included
- Check database for reservation status updates
- Review notification system logs

## Future Enhancements

1. **Real-time Updates**: Implement WebSocket for real-time status updates
2. **Bidirectional Sync**: Enable facilities system to send maintenance requests to LGU system
3. **Enhanced Matching**: Use machine learning for better facility matching
4. **Audit Trail**: Detailed audit trail for all integration events
5. **Dashboard**: Integration status dashboard for monitoring
