# Privacy Impact Assessment (PIA) Template — CPRF

**System:** Barangay Culiat Public Facilities Reservation System  
**Law:** Republic Act No. 10173 (Data Privacy Act of 2012)  
**Organization:** Barangay Culiat LGU / Capstone Team

---

## 1. System overview

| Field | Response |
|-------|----------|
| System name | CPRF — Facilities Reservation |
| Purpose | Online facility booking, approvals, attendance, LGU integrations |
| Data subjects | Residents, staff, visitors (contact form only) |
| Personal data collected | Name, email, mobile, address, Valid ID, booking details, attendance photos |

## 2. Data inventory

| Data element | Purpose | Storage | Retention |
|--------------|---------|---------|-----------|
| Account profile | Authentication, eligibility | `users` | Active + audit |
| Valid ID uploads | Residency verification | Secure document storage | Per archival policy |
| Reservations | Service delivery | `reservations` | Per LGU policy |
| Audit log | Accountability | `audit_log` | Per admin retention |
| Notifications | Service comms | `notifications` | Rolling archive |

## 3. Legal basis & consent

- [ ] Registration Terms & Privacy acceptance recorded
- [ ] SMS opt-in via notification preferences
- [ ] DPA self-export available in Profile

## 4. Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Unauthorized document access | Low | High | Role-based download, secure paths |
| Credential stuffing | Medium | High | Rate limits, OTP, lockout |
| Data breach via email | Low | Medium | SMTP TLS, rate limits |
| Over-collection | Low | Medium | Facilities-only scope, no social login |

## 5. Third parties

| Processor | Data shared | DPA / contract |
|-----------|-------------|----------------|
| SMTP (Brevo/Gmail) | Email, name | Provider terms |
| SMS gateway | Mobile, message | Provider terms |
| Google Gemini | Chat text (if enabled) | Google API terms |
| PayMongo (optional) | Payment metadata | Merchant agreement |

## 6. Recommendations

1. Conduct annual PIA review after major feature releases  
2. Train barangay staff on ID verification and waiver approvals  
3. Maintain incident response contact in System Settings  

## 7. Approval

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Data Protection Officer (designate) | | | |
| Barangay Captain / Authorized rep | | | |
| System Owner | | | |
