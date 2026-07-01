# Product Backlog — Barangay Culiat Facilities Reservation System

**Last updated:** June 2026  
**Companion doc:** `MODULES_LIST.md` — full catalog of what is **currently in the system**.

This backlog is organized into three sections:
1. **Shipped** — major capabilities already implemented (reference for defense / stakeholder reviews)
2. **Partial / optional** — exists in code but incomplete, disabled, or depends on external config
3. **Planned / future** — not yet built; prioritized suggestions for next iterations

---

## 1. Shipped (Implemented)

### Authentication & users
- Resident-only registration with Barangay Culiat street dropdown + house number
- Valid ID upload (optional at registration; required path for ID verification)
- Terms & Data Privacy modal + required acceptance
- Email verification, forgot/reset password
- Login with email OTP and optional Google Authenticator (TOTP)
- Session hardening, CSRF, rate limits, login lockout, password policy
- Profile with geocoding, profile photo, notification preferences
- User Management: approve/deny, verify ID, lock/unlock, reset password, violation history
- Admin/staff **create user** (Resident or Staff) with emailed credentials
- Role-based access (Resident, Staff, Admin)
- Data Privacy Act user data export

### Public portal
- Landing, facilities list, facility details, announcements archive, FAQ, contact form
- Privacy / Terms / Legal pages
- Guest facility assistant (availability widget)

### Facilities
- Full facility CRUD, images, citations, operating hours, geocoding
- Blackout dates, auto-approval settings, extension fees
- Facility check-in QR (generate, regenerate, print poster)
- CIMM maintenance integration (when API key configured)

### Booking & reservations
- Book a facility with purpose, attendees, commercial flag, event permits
- AI conflict detection + alternative slots; AI recommendations + distance scoring
- Booking limits, auto-approval, auto-decline expired pending
- My Reservations (calendar + list, reschedule, cancel, past-event styling)
- Staff reservation management (approve, deny, postpone, on hold, modify, violations)
- Reservation detail + timeline; staff walk-in booking
- Reservation extensions; 24h booking reminders (email/SMS/in-app)
- Optional PayMongo payment flow (`PAYMENTS_ENABLED`)

### Attendance & occupancy
- Manual check-in/out (Time Tracking) with optional photo proof
- Facility QR scan check-in/out at venue
- Live Occupancy dashboard strip + full monitor page
- Attendance reminders; no-show / late violation recording

### AI & scheduling
- Smart Scheduler page; Gemini chatbot + ML intent fallback + booking prefill
- Python ML: conflict, recommendations, risk, purpose analysis
- Holiday/event risk tagging; performance indexes for AI queries

### Calendar & reports
- Month/week/day calendar, iCal export, clickable events
- Dashboard KPIs with filters; Reports charts + CSV/PDF export

### Communications
- In-app notifications; email templates; SMS (opt-in)
- Announcements management; contact inquiries inbox; editable public contact info

### Administration & security
- Audit trail + export; document management & archival cron
- Secure document storage; security logs; PHPUnit + CI workflow

### UX / polish (selected)
- Collapsible sidebar groups; mobile sidebar overlay
- Dark mode surfaces (dashboard pages)
- Responsive layouts; confirmation modals; status badges
- User Management modals portaled to viewport center

---

## 2. Partial / Optional (In codebase, not fully productized)

| Item | Current state | To complete |
|------|---------------|-------------|
| **Demand forecasting** | Python API + training scripts | Surface in Reports / Smart Scheduler UI |
| **PayMongo payments** | Wired but off by default | Enable only if LGU charges fees; run payment migration |
| **CIMM live sync** | Full PHP + cron | Deploy CIMM host + `CIMM_API_KEY` on production |
| **Infrastructure Projects integration** | Preview UI + mock data | Connect to real LGU infrastructure API |
| **Utilities integration** | Preview UI + mock data | Connect to real utilities/outage API |
| **Integrations API gateway** | Stub returns 501 | Implement unified gateway if microservices multiply |
| **Filipino/Tagalog UI** | English only | i18n layer (deferred Sprint C4/D7) |
| **Brevo SMTP** | May still use prior mail config | Cutover + deliverability testing if required |

---

## 3. Planned / Future Backlog (Not built)

Priority is suggestive — adjust with LGU stakeholders.

### High value (operations)
1. **Forgot check-in waiver flow** — resident self-report within window; staff approve waiver (reduce unfair no-shows)
2. **Bulk user import** — CSV upload for barangay registry sync
3. **Re-request documents** — admin action to ask user to re-upload expired/invalid ID
4. **Staff notification digest** — daily email summary of pending approvals and occupancy alerts
5. **Facility capacity vs. attendees validation** — hard block when over capacity

### Medium value (UX & reach)
6. **Filipino/Tagalog UI** — labels, emails, SMS templates
7. **PWA / offline-friendly** — installable web app for mobile residents
8. **SMS two-way confirm** — reply YES to confirm attendance
9. **Public facility availability calendar embed** — iframe/widget for barangay website
10. **Advanced reports** — demand heatmaps, peak hours, revenue (if payments on)

### Integrations
11. **Live Infrastructure Projects API** — replace mock data
12. **Live Utilities / outage API** — replace mock data
13. **Barangay ID / QR identity verify** — optional third-party identity check
14. **Unified LGU API gateway** — auth, rate limit, audit for all microservices

### AI / analytics
15. **Demand forecasting dashboard** — visual forecast per facility
16. **SHAP / explainability** — show why auto-approval was denied
17. **Chatbot voice input** — accessibility for mobile users
18. **Anomaly detection** — flag suspicious booking patterns

### Security & compliance (production hardening)
19. **Separate document storage bucket** — S3-compatible or isolated volume
20. **WAF / bot protection** beyond Turnstile on login
21. **Annual privacy impact assessment template** — LGU compliance artifact
22. **Automated backup restore drill** — documented RTO/RPO test

### DevOps
23. **Staging environment parity checklist** — mirror production cron + `.env`
24. **Health check endpoint** — DB, mail, SMS, Python ML, CIMM status
25. **Structured application logging** — JSON logs for centralized monitoring

---

## 4. Historical numbered backlog (archived)

The previous version of this file listed 70 numbered items (auth polish, AI holidays, responsive tables, etc.). **Those items are now shipped** and are summarized in Section 1 above. The old list is preserved in git history (`BACKLOG.md` prior to June 2026) if line-by-line traceability is needed for capstone documentation.

---

## How to keep this current

| When you… | Update… |
|-----------|---------|
| Ship a major feature | `MODULES_LIST.md` + add to Section 1 here |
| Add a stub or flag-gated feature | Section 2 here |
| Defer or propose new work | Section 3 here |
| Complete a sprint | `CAPSTONE_IMPLEMENTATION_PLAN.md` + `SPRINT_D_PLAN.md` |

**Source of truth for routes:** `index.php`  
**Source of truth for navigation:** `resources/views/components/sidebar_dashboard.php`
