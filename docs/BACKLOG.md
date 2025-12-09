# Product Backlog (Ordered, 50+ Items)

1. Enforce email OTP on login (live) – monitor deliverability and retries.
2. Resident-only registration with Barangay Culiat address validation.
3. Mandatory supporting document upload (≥1 of Birth Cert/Valid ID/Barangay ID/Resident ID).
4. Admin review queue showing uploaded documents with download links.
5. Approval email to users when account is approved.
6. Denial/lock notification to users (email + in-app).
7. Robust document storage under `public/uploads/documents/{userId}` with safe names.
8. Input validation for document size/type (PDF/JPG/PNG/WebP).
9. Admin ability to re-request documents or add remarks on missing docs.
10. Registration rate limiting by IP.
11. Login rate limiting and lockout tracking.
12. OTP expiry (10 minutes) and attempt limits with clear UX.
13. OTP resend throttling and messaging.
14. Session security (regenerate ID, timeout, SameSite, HttpOnly).
15. Password policy enforcement (length/complexity).
16. Profile page: address + lat/long inputs with geocoding fallback.
17. Facility form: lat/long fields and validation.
18. Public facility listing with glass-morphism landing background.
19. Facility details page with image citation hover behavior.
20. Dashboard recent activities pagination.
21. Audit trail pagination and filtering.
22. Reports quick-export (CSV/HTML-to-PDF) from sidebar.
23. Calendar: clickable events to open reservation detail.
24. AI recommendations factoring distance (Haversine) and purpose.
25. AI conflict detection on booking submit.
26. Collapsible dashboard sections with persisted state.
27. Mobile sidebar overlay with backdrop and close button.
28. Responsive tables via `.table-responsive` wrappers on key modules.
29. Status badges for roles (resident/admin/staff) with contrast.
30. Reservation detail page shows requester role badge.
31. Auto-decline expired pending reservations.
32. Notifications panel lazy-load + mark-as-read.
33. Security headers/CSP aligned with external CDNs (fonts, Chart.js).
34. File upload hardening (`validateFileUpload`).
35. Rate-limit table and security logs for auditability.
36. Login attempts table for lockout visibility.
37. Admin: view reservation history timeline.
38. Admin: add optional notes on approve/deny.
39. Resident: “My Reservations” status visibility.
40. User management filters by role/status.
41. Facility management: recent activity pagination.
42. Facility management: collapsible sections.
43. Booking form conflict warning UI with alternatives.
44. Booking form AI recommendation display with distance labels.
45. Public facilities: cityhall background and blur overlay.
46. Guest navbar mobile toggle and background transparency.
47. Footer/guest layout layering over landing background.
48. Test page for location-based recommendations (debug).
49. Documentation: FLOWCHART/DFD/WFD updated to latest flows.
50. Documentation: SECURITY and security implementation summary.
51. Documentation: Free geocoding setup (OpenStreetMap).
52. Documentation: Location-based recommendations behavior.
53. Migration: security tables (rate_limits, security_logs, login_attempts).
54. Migration: user documents table and document enum.
55. Migration: OTP columns on users.
56. Migration: profile picture column.
57. Migration: location fields on users/facilities.
58. Export reports: ensure HTML-for-PDF is print-friendly.
59. OTP email templates: friendly copy and expiry note.
60. Approval email templates: include next steps and contact info.

Notes:
- Items 1–15 are security/auth/onboarding critical; address first for production readiness.
- Items 16–45 are UX/feature completeness for booking and management flows.
- Items 46–60 are polish, docs, and supporting infra.


