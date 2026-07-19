# API Reference

## Overview

The Facilities Reservation System provides both public and authenticated API endpoints. Most endpoints return JSON responses. The system uses a custom routing pattern via `index.php` as the front controller.

**Base URL**: Determined dynamically via `base_url()` function  
**Content-Type**: `application/json` for API responses  
**Authentication**: Session-based for dashboard APIs, none for public APIs

## Authentication

### Session-Based Authentication

Dashboard API endpoints require an active PHP session with `user_authenticated` set to `true`.

**Session Requirements**:
- `user_id`: Valid user ID
- `user_authenticated`: Boolean `true`
- `role`: User role (Admin/Staff/Resident)

**CSRF Protection**: All POST requests require a valid CSRF token:
- Header: `X-CSRF-Token` or
- Form field: `csrf_token`

### Rate Limiting

Some endpoints have rate limiting enforced via `config/security.php`:
- Login: 5 attempts per email per 15 minutes
- Registration: 5 attempts per IP per hour
- Email verification: 3 attempts per email per hour
- Gemini chatbot: 10 requests per user per minute

## Public APIs

### GET /api/public/availability

Get facility availability for a date range.

**Authentication**: Not required

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| facility_id | integer | Yes | Facility ID |
| start_date | date (YYYY-MM-DD) | Yes | Start date |
| end_date | date (YYYY-MM-DD) | Yes | End date |

**Response**:
```json
{
  "success": true,
  "facility_id": 1,
  "dates": {
    "2024-01-15": {
      "available": true,
      "slots": ["09:00-11:00", "14:00-16:00"]
    },
    "2024-01-16": {
      "available": false,
      "reason": "maintenance"
    }
  }
}
```

**File**: `resources/views/pages/public/api/availability.php`

---

### POST /paymongo-webhook

PayMongo payment webhook handler.

**Authentication**: Not required (signature verification used)

**Headers**:
- `Paymongo-Signature`: HMAC signature for webhook verification

**Request Body**:
```json
{
  "data": {
    "id": "evt_123",
    "attributes": {
      "type": "payment.paid",
      "data": {
        "attributes": {
          "checkout_id": "chk_123",
          "amount": 10000,
          "currency": "PHP",
          "status": "paid"
        }
      }
    }
  }
}
```

**Response**:
```json
{
  "success": true,
  "message": "Webhook processed"
}
```

**File**: `resources/views/pages/public/api/paymongo_webhook.php`

---

### POST /contact-handler

Handle public contact form submissions.

**Authentication**: Not required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| name | string | Yes | Contact name |
| email | string | Yes | Contact email |
| organization | string | No | Organization name |
| message | string | Yes | Inquiry message |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "message": "Your inquiry has been submitted"
}
```

**File**: `resources/views/pages/public/contact_handler.php`

---

## Dashboard APIs

### POST /dashboard/ai-chatbot

AI chatbot endpoint for dashboard widget.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| message | string | Yes | User message |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "reply": "I can help you book a facility...",
  "action": null,
  "data": null
}
```

**Response with Prefill Action**:
```json
{
  "reply": "I've found a good time slot for you...",
  "action": "prefill_booking",
  "data": {
    "facility_id": 1,
    "reservation_date": "2024-01-15",
    "start_time": "14:00",
    "end_time": "16:00",
    "purpose": "Birthday party",
    "expected_attendees": 20
  }
}
```

**File**: `resources/views/pages/dashboard/ai_chatbot.php`

---

### POST /dashboard/session-keepalive

Keep session alive to prevent timeout.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "remaining": 300
}
```

**File**: `resources/views/pages/dashboard/session_keepalive.php`

---

### POST /dashboard/ai-recommendations-api

Get AI-powered facility recommendations.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| purpose | string | Yes | Event purpose |
| expected_attendees | integer | Yes | Expected number of attendees |
| date | string | Yes | Preferred date (YYYY-MM-DD) |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "recommendations": [
    {
      "facility_id": 1,
      "name": "Convention Hall",
      "match_score": 0.95,
      "reason": "Large capacity suitable for your event size"
    }
  ]
}
```

**File**: `resources/views/pages/dashboard/ai_recommendations_api.php`

---

### POST /dashboard/ai-conflict-check

Check for booking conflicts using AI.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| facility_id | integer | Yes | Facility ID |
| date | string | Yes | Reservation date (YYYY-MM-DD) |
| time_slot | string | Yes | Time slot (HH:MM - HH:MM) |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "has_conflict": false,
  "soft_conflicts": [],
  "risk_score": 10,
  "message": "No conflicts detected"
}
```

**File**: `resources/views/pages/dashboard/ai_conflict_check.php`

---

### POST /dashboard/notifications-api

Get user notifications.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| limit | integer | No | Maximum notifications (default: 10) |
| unread_only | boolean | No | Only unread notifications (default: false) |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "notifications": [
    {
      "id": 1,
      "type": "booking",
      "title": "Reservation Approved",
      "message": "Your reservation has been approved",
      "link": "/dashboard/book-facility?module=mine",
      "is_read": false,
      "created_at": "2024-01-15 10:30:00"
    }
  ],
  "unread_count": 3
}
```

**File**: `resources/views/pages/dashboard/notifications_api.php`

---

### POST /dashboard/notifications-mark-read

Mark notification as read.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| notification_id | integer | Yes | Notification ID |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "message": "Notification marked as read"
}
```

**File**: `resources/views/pages/dashboard/notifications.php` (POST handler)

---

### POST /dashboard/occupancy-live

Get live occupancy data for facilities.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| facility_id | integer | No | Specific facility ID (null = all) |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "timestamp": "2024-01-15 14:30:00",
  "facilities": [
    {
      "facility_id": 1,
      "name": "Convention Hall",
      "current_occupancy": 45,
      "capacity": 100,
      "occupancy_percent": 45,
      "status": "active"
    }
  ]
}
```

**File**: `resources/views/pages/dashboard/occupancy_live_api.php`

---

### POST /dashboard/geocode-api

Geocode address to coordinates.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| address | string | Yes | Address to geocode |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "address": "123 Main St, Quezon City",
  "latitude": 14.676,
  "longitude": 121.0437,
  "formatted": "123 Main St, Quezon City, Metro Manila, Philippines"
}
```

**File**: `resources/views/pages/dashboard/geocode_api.php`

---

### POST /dashboard/booking-smart-hints

Get smart hints for booking form.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| facility_id | integer | Yes | Facility ID |
| date | string | Yes | Reservation date (YYYY-MM-DD) |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "hints": [
    {
      "type": "info",
      "message": "This facility has a 4-hour maximum duration for auto-approval"
    },
    {
      "type": "warning",
      "message": "High demand expected on this date"
    }
  ]
}
```

**File**: `resources/views/pages/dashboard/booking_smart_hints_api.php`

---

### POST /dashboard/calendar-availability

Get availability for calendar view.

**Authentication**: Required

**Request Body**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| facility_id | integer | Yes | Facility ID |
| start_date | string | Yes | Start date (YYYY-MM-DD) |
| end_date | string | Yes | End date (YYYY-MM-DD) |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "availability": {
    "2024-01-15": {
      "available_slots": ["09:00-11:00", "14:00-16:00"],
      "booked_slots": ["11:00-14:00"]
    }
  }
}
```

**File**: `resources/views/pages/dashboard/api/availability_api.php`

---

## Error Responses

All API endpoints return error responses in the following format:

```json
{
  "success": false,
  "error": "Error message",
  "code": "ERROR_CODE"
}
```

### Common Error Codes

| Code | Description |
|------|-------------|
| `UNAUTHORIZED` | Session expired or not authenticated |
| `FORBIDDEN` | User lacks permission |
| `INVALID_REQUEST` | Invalid request parameters |
| `CSRF_INVALID` | Invalid CSRF token |
| `RATE_LIMITED` | Rate limit exceeded |
| `SERVER_ERROR` | Internal server error |

### HTTP Status Codes

| Status | Description |
|--------|-------------|
| 200 | Success |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 429 | Too Many Requests (rate limit) |
| 500 | Internal Server Error |

## CSRF Token Handling

### Getting CSRF Token

CSRF tokens are generated per session and available in:

1. **JavaScript global variable**: `window.CSRF_TOKEN`
2. **PHP function**: `csrf_token()`
3. **Form helper**: `csrf_field()` outputs hidden input

### Including CSRF Token

**In AJAX requests**:
```javascript
const formData = new URLSearchParams();
formData.append('csrf_token', window.CSRF_TOKEN);
```

**In fetch headers**:
```javascript
headers: {
  'X-CSRF-Token': window.CSRF_TOKEN
}
```

## AJAX Dashboard Navigation

Dashboard pages support AJAX navigation via the `X-Requested-With: FRS-Dashboard-Nav` header.

### Request

```http
GET /dashboard/book-facility
X-Requested-With: FRS-Dashboard-Nav
```

### Response

Returns only the `<section class="dashboard-content">` HTML fragment, not the full page.

### JavaScript Handler

The `dashboard-navigation.js` file handles this automatically for sidebar navigation.

## Rate Limiting Details

### Rate Limit Headers

Rate-limited endpoints may return these headers:

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | Maximum requests per window |
| `X-RateLimit-Remaining` | Remaining requests |
| `X-RateLimit-Reset` | Unix timestamp when limit resets |

### Rate Limit Configuration

Rate limits are configured in `config/security.php`:

| Action | Limit | Window |
|--------|-------|--------|
| Login attempts | 5 | 15 minutes (per email) |
| Registration | 5 | 1 hour (per IP) |
| Email verification | 3 | 1 hour (per email) |
| Gemini chatbot | 10 | 1 minute (per user) |
| Contact form | 10 | 1 hour (per IP) |

## Webhooks

### PayMongo Webhook

**Endpoint**: `POST /paymongo-webhook`

**Authentication**: HMAC signature verification

**Signature Calculation**:
```php
$signature = hash_hmac('sha256', $payload, $webhook_secret);
```

**Events Handled**:
- `payment.paid` - Payment successful
- `payment.failed` - Payment failed
- `payment.expired` - Payment expired

**Idempotency**: Webhooks are idempotent based on `provider_event_id`

## File Upload APIs

### Upload Profile Picture

**Endpoint**: `POST /dashboard/profile` (multipart/form-data)

**Request**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| profile_picture | file | Yes | Image file (JPEG, PNG, WebP) |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "message": "Profile picture updated",
  "profile_picture": "/public/uploads/profile_pictures/abc123.jpg"
}
```

### Upload User Document

**Endpoint**: `POST /dashboard/book-facility` (multipart/form-data)

**Request**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| doc_valid_id | file | Yes | Valid ID document |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "message": "Document uploaded successfully"
}
```

### Upload Reservation Document

**Endpoint**: `POST /dashboard/book-facility` (multipart/form-data)

**Request**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| event_supporting_doc | file | Yes | Supporting document |
| event_document_type | string | Yes | Document type |
| csrf_token | string | Yes | CSRF token |

**Response**:
```json
{
  "success": true,
  "message": "Document uploaded successfully"
}
```

## Export APIs

### Download Export

**Endpoint**: `POST /dashboard/download_export`

**Request**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| report_type | string | Yes | Report type (reservations, users, facilities) |
| start_date | string | No | Start date filter |
| end_date | string | No | End date filter |
| format | string | No | Export format (csv, excel) |
| csrf_token | string | Yes | CSRF token |

**Response**: File download (CSV or Excel)

**File**: `resources/views/pages/dashboard/download_export.php`

---

### Calendar ICS Export

**Endpoint**: `GET /dashboard/calendar_export_ics`

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| facility_id | integer | No | Filter by facility |
| start_date | string | No | Start date (YYYY-MM-DD) |
| end_date | string | No | End date (YYYY-MM-DD) |

**Response**: ICS file download

**File**: `resources/views/pages/dashboard/calendar_export_ics.php`

---

## Notification APIs

### Create Notification

**Endpoint**: Internal function (not exposed as HTTP API)

**Function**: `createNotification($userId, $type, $title, $message, $link)`

**File**: `config/notifications.php`

---

### Send Email Notification

**Endpoint**: Internal function (not exposed as HTTP API)

**Function**: `sendEmail($to, $name, $subject, $body)`

**File**: `config/mail_helper.php`

---

### Send SMS Notification

**Endpoint**: Internal function (not exposed as HTTP API)

**Function**: `sendSms($mobile, $message)`

**File**: `config/sms_helper.php`

---

## Security Headers

All API responses include security headers:

| Header | Value |
|--------|-------|
| `X-Content-Type-Options` | nosniff |
| `X-Frame-Options` | SAMEORIGIN |
| `X-XSS-Protection` | 1; mode=block |
| `Referrer-Policy` | strict-origin-when-cross-origin |
| `Permissions-Policy` | geolocation=(), microphone=(), camera=() |

## CORS

The system does not implement CORS headers as it is designed for same-origin requests only. Cross-origin requests should be proxied or use server-side integration.

## API Versioning

The system does not currently implement API versioning. All endpoints are considered version 1. Future breaking changes should include versioning in the URL path (e.g., `/api/v2/...`).

## Testing APIs

### cURL Examples

**Get Availability**:
```bash
curl -X GET "http://localhost/api/public/availability?facility_id=1&start_date=2024-01-15&end_date=2024-01-20"
```

**AI Chatbot**:
```bash
curl -X POST http://localhost/dashboard/ai-chatbot \
  -H "Cookie: PHPSESSID=your_session_id" \
  -d "message=Book the Convention Hall for tomorrow" \
  -d "csrf_token=your_csrf_token"
```

**Session Keepalive**:
```bash
curl -X POST http://localhost/dashboard/session-keepalive \
  -H "Cookie: PHPSESSID=your_session_id" \
  -d "csrf_token=your_csrf_token"
```
