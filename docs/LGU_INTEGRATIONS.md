# LGU 1 System Integrations

## Current Integration Status

| System | Status | Direction | Notes |
|--------|--------|-----------|-------|
| **CIMM (Maintenance)** | ✅ Partial | Outbound pull | `scripts/sync_cimm_maintenance.php`; requires `CIMM_API_KEY` |
| **Infrastructure Management** | 🟡 Preview | — | Mock UI only; not connected |
| **Utilities Billing** | 🟡 Preview | — | Mock UI only; not connected |
| **Inbound REST webhooks** | ❌ Not deployed | Inbound | `/api/integrations/*` returns HTTP 501 |

### External Services (Non-LGU)
- **SMTP Email Service**: Gmail (planned: Brevo/domain SMTP)
- **Geocoding Service**: OpenStreetMap Nominatim (optional Google Maps API)
- **Gemini AI**: Chatbot + reports when `GEMINI_API_KEY` is configured

---

## LGU 1 System Ecosystem

### 1. **Infrastructure Management**
### 2. **Utilities Billing & Management**
### 3. **Community Infrastructure Maintenance Management**
### 4. **Public Facilities Reservation System** ← *Your System*

---

## Potential Integration Points

### 🔗 **1. Community Infrastructure Maintenance Management**
**Integration Level**: ⭐⭐⭐ **HIGH PRIORITY**

**Why**: Your system tracks facility status (`available`, `maintenance`, `offline`) and maintenance scheduling.

**Potential Integrations**:
- **Facility Status Sync**: When maintenance is scheduled in Maintenance Management, automatically set facility status to `maintenance` in your system
- **Maintenance Calendar**: Block booking dates when maintenance is scheduled
- **Maintenance Notifications**: Notify users with pending/approved reservations when maintenance is scheduled
- **Maintenance History**: Link facility maintenance records to reservation history

**Data Exchange**:
- **Outbound**: Facility status changes, maintenance window requests
- **Inbound**: Maintenance schedules, maintenance completion notifications

**Implementation (current)**:
- **Outbound**: CPRF pulls schedules from CIMM API (`fetchCIMMMaintenanceSchedules` in `services/cimm_api.php`)
- **Sync job**: `php scripts/sync_cimm_maintenance.php` (cron recommended every 15 min)
- **UI**: `/dashboard/maintenance-integration` displays schedules; DB writes happen only via sync job
- **Inbound webhooks**: Not deployed — documented routes return HTTP 501

---

### 🔗 **2. Infrastructure Management**
**Integration Level**: ⭐⭐ **MEDIUM PRIORITY**

**Why**: Infrastructure projects may affect facility availability (renovations, expansions, new facilities).

**Potential Integrations**:
- **Project Timeline Sync**: Block facilities during construction/renovation projects
- **New Facility Integration**: Automatically add new facilities when projects are completed
- **Project Notifications**: Alert users about facility closures due to projects
- **Capacity Updates**: Update facility capacity when expansion projects complete

**Data Exchange**:
- **Outbound**: Facility availability, booking conflicts
- **Inbound**: Project timelines, facility closures, new facility data

**Implementation**:
- API integration for project milestones
- Automated facility status updates based on project phases

---

### 🔗 **3. Utilities Billing & Management**
**Integration Level**: ⭐⭐ **MEDIUM PRIORITY**

**Why**: Facility usage may affect utility billing, and utility issues may affect facility availability.

**Potential Integrations**:
- **Utility Cost Tracking**: Link facility usage to utility consumption
- **Billing Integration**: Include facility rental fees in utility bills (if applicable)
- **Utility Outage Alerts**: Block facilities when utilities are unavailable
- **Energy Usage Reporting**: Track facility energy consumption per reservation

**Data Exchange**:
- **Outbound**: Facility usage data, reservation schedules
- **Inbound**: Utility outage schedules, billing information

**Implementation**:
- API for utility outage notifications
- Data export for billing reconciliation

---

## Integration Architecture Recommendations

### Option 1: API Gateway Pattern (Recommended)
```
┌─────────────────────────────────────────┐
│      LGU 1 API Gateway                 │
│  (Centralized Integration Hub)         │
└──────────────┬──────────────────────────┘
               │
       ┌───────┴────────┐
       │                │
┌──────▼──────┐  ┌──────▼──────────────────┐
│ Facilities  │  │ Maintenance Management    │
│ Reservation │  │ Infrastructure Management │
│ System      │  │ Utilities Billing         │
└─────────────┘  └──────────────────────────┘
```

### Option 2: Direct API Integration
- Each system exposes REST APIs
- Direct point-to-point integration
- More flexible but requires more maintenance

### Option 3: Shared Database (Not Recommended)
- Shared tables across systems
- Tight coupling, harder to maintain
- Security concerns

---

## Implementation Priority

### Phase 1: High Priority (Immediate Value)
1. **Community Infrastructure Maintenance Management**
   - Facility status sync
   - Maintenance calendar blocking

### Phase 2: Medium Priority (Strategic Value)
2. **Infrastructure Management**
   - Project timeline integration
   - New facility automation

3. **Utilities Billing & Management**
   - Utility outage alerts
   - Billing data integration

---

## Technical Requirements for Integration

### For Your System (Public Facilities Reservation)

#### ✅ Implemented
- **CIMM outbound sync**: Pull maintenance schedules; update facility status and blackout dates via cron
- **Facility Status Management**: `available`, `maintenance`, `offline`
- **Calendar / blackout system**: Date-based blocking (`facility_blackout_dates`)
- **Notification, audit, export** capabilities

#### 🟡 Preview UI only
- Infrastructure Projects integration page (mock sample data)
- Utilities Billing integration page (mock sample data)

#### ❌ Planned — not deployed (inbound API)
The following routes are **documented for future LGU-to-CPRF integration** but return **HTTP 501 Not Implemented** if called today:
   - `POST /api/integrations/maintenance/schedule` - Receive maintenance schedules *(501 — use outbound CIMM pull instead)*
   - `POST /api/integrations/projects/timeline` - Receive project timelines
   - `POST /api/integrations/utilities/outage` - Receive utility outage alerts

2. **Outbound endpoints** (CPRF would expose to other LGU systems — also planned):
   - `GET /api/integrations/facilities/status` - Provide facility status
   - `GET /api/integrations/reservations/analytics` - Provide usage analytics
   - `GET /api/integrations/facilities/locations` - Provide facility locations
   - `GET /api/integrations/facilities/usage` - Provide facility usage data for billing

3. **Webhook support** (planned): Real-time event notifications

4. **Authentication** (planned): API keys or OAuth for inter-system communication

5. **Data format**: JSON (standardized schemas TBD)

---

## Current System Capabilities for Integration

### ✅ Already Available:
- **Facility Status Management**: `available`, `maintenance`, `offline` statuses
- **Calendar System**: Date-based blocking and availability
- **Notification System**: Can send notifications for status changes
- **Audit Trail**: Tracks all facility and reservation changes
- **Data Export**: CSV and HTML-for-PDF reports
- **Geocoding**: Facility location coordinates
- **CIMM outbound sync**: `scripts/sync_cimm_maintenance.php`

### 🔧 Needs Development:
- **Inbound REST API Endpoints**: Documented routes return 501 until implemented
- **Webhook Handler**: For real-time event processing
- **Integration Authentication**: API keys/OAuth for third-party callers
- **Data Mapping Layer**: Convert between system formats
- **Infrastructure / Utilities live APIs**: Replace preview mock pages

---

## Summary

**Current State**: CPRF has **partial CIMM integration** (outbound pull + cron sync). Infrastructure and Utilities pages are **preview/mock UI only**. Inbound `/api/integrations/*` routes are **not deployed**.

**Recommended First Integration**: **CIMM maintenance sync** — already partially live; stabilize cron and facility matching.

**Integration Approach**: Outbound pull for CIMM today; API Gateway + inbound webhooks when other LGU systems are ready.

**Status by system**:
1. **Community Infrastructure Maintenance Management** — ✅ Partial (outbound)
2. **Infrastructure Management** — 🟡 Preview UI
3. **Utilities Billing & Management** — 🟡 Preview UI

**Next Steps**:
1. Schedule `sync_cimm_maintenance.php` in cron on production
2. Define inbound API contracts with other LGU teams when ready
3. Replace Infrastructure/Utilities preview pages with live APIs