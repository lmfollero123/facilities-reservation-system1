# Product Backlog (Ordered, 50+ Items)

1. Enforce email OTP on login (live) – monitor deliverability and retries.
2. Resident-only registration with Barangay Culiat address validation.
3. Mandatory supporting document upload (Valid ID primary; other options removed from UI).
4. Terms & Conditions (Data Privacy Act) modal auto-open + required acceptance checkbox.
5. Admin review queue showing uploaded documents with download links.
6. Approval email to users when account is approved.
7. Denial/lock notification to users (email + in-app) with lock reason shown on login.
8. Robust document storage under `public/uploads/documents/{userId}` with safe names.
9. Input validation for document size/type (PDF/JPG/PNG/WebP).
10. Admin ability to re-request documents or add remarks on missing docs.
11. Registration rate limiting by IP.
12. Login rate limiting and lockout tracking.
13. OTP expiry (10 minutes) and attempt limits with clear UX.
14. OTP resend throttling and messaging.
15. Session security (regenerate ID, timeout, SameSite, HttpOnly).
16. Password policy enforcement (length/complexity).
17. Forgot password + reset token email flow (pages: forgot, reset).
18. Profile page: address + lat/long inputs with geocoding fallback.
19. Facility form: lat/long fields and validation.
20. Public facility listing with glass-morphism landing background; hero CTAs side-by-side/equal width.
21. Facility details page with image citation hover behavior (non-intrusive).
22. Dashboard recent activities pagination. (Done)
23. Audit trail pagination and filtering.
24. Reports quick-export (CSV/HTML-to-PDF) from sidebar.
25. Calendar: clickable events to open reservation detail; facility detail calendar dates redirect to login → dashboard calendar.
26. AI recommendations factoring distance (Haversine) and purpose; holiday/event risk tagging (PH holidays + Brgy. Culiat). (Done)
27. AI conflict detection on booking submit; persistent warning UI (no flicker). (Done)
28. Collapsible dashboard sections with persisted state (re-enabled).
29. Mobile sidebar overlay with backdrop and close button.
30. Responsive tables via `.table-responsive` wrappers on key modules.
31. Status badges for roles (resident/admin/staff) with contrast.
32. Reservation detail page shows requester role badge.
33. Auto-decline expired pending reservations.
34. Notifications panel lazy-load + mark-as-read.
35. Security headers/CSP aligned with external CDNs (fonts, Chart.js, jsdelivr SimpleLightbox/Bootstrap Icons).
36. File upload hardening (`validateFileUpload`).
37. Rate-limit table and security logs for auditability.
38. Login attempts table for lockout visibility.
39. Admin: view reservation history timeline.
40. Admin: add optional notes on approve/deny.
41. Resident: “My Reservations” status visibility.
42. User management filters by role/status; lock reason capture.
43. Facility management: recent activity pagination.
44. Facility management: collapsible sections.
45. Booking form conflict warning UI with alternatives; booking limits (≤3 active/30 days, ≤60-day advance, ≤1/day). (Done)
46. Booking form AI recommendation display with distance labels; holiday/event pills on calendar modal. (Done)
47. Public facilities: cityhall background and blur overlay; higher-contrast facility cards.
48. Guest navbar mobile toggle and background transparency.
49. Footer/guest layout layering over landing background.
50. Contact inquiries: public form → DB table + admin email + dashboard view.
51. Forgot password templates and reset UX polish.
52. Dashboard filters (status/facility/date range) with charts responding to filters.
53. Reports page charts (monthly/status/top facilities).
54. Sidebar order: Reports & Analytics under Operations; calendar link via book page modal.
55. Button modernization (larger, consistent radius/shadows, confirmation buttons).
56. Hero CTA buttons equal width; dashboard buttons wrap labels cleanly.
57. Migration: security tables (rate_limits, security_logs, login_attempts).
58. Migration: user documents table and document enum.
59. Migration: OTP columns on users.
60. Migration: profile picture column.
61. Migration: location fields on users/facilities.
62. Migration: lock_reason on users; contact_inquiries table.
63. Export reports: ensure HTML-for-PDF is print-friendly.
64. OTP email templates: friendly copy and expiry note.
65. Approval/lock email templates: include next steps, reason, and contact info.
66. Documentation: FLOWCHART/DFD/WFD updated to latest flows (forgot/reset, contact inquiries, booking limits, AI holidays/events).
67. Documentation: SECURITY and security implementation summary.
68. Documentation: Free geocoding setup (OpenStreetMap).
69. Documentation: Location-based recommendations behavior.
70. Test page for location-based recommendations (debug).

Notes:
- Items 1–15 are security/auth/onboarding critical; address first for production readiness.
- Items 16–45 are UX/feature completeness for booking and management flows.
- Items 46–60 are polish, docs, and supporting infra.




