# Reports & Analytics Module (Front-End Blueprint)

## Page
- `resources/views/pages/dashboard/reports.php`
- Sidebar entry: “Reports & Analytics”
- Route hint: `/dashboard/reports`

## Purpose
- Give LGU administrators a consolidated view of reservation activity and facility utilization.
- Provide a placeholder surface for AI-driven insights about peak demand and usage trends.

## Layout
1. **Summary Metrics & Charts (left column)**
   - KPI row with:
     - Total Reservations (month)
     - Approval Rate
     - Utilization rate
   - Horizontal bar-style visualization of utilization by facility (Hall, Sports Complex, Amphitheater).
   - Reservation outcomes table: Approved / Declined / Cancelled.

2. **AI Insights & Exports (right column)**
   - Dark-themed AI insight panel summarizing forecasted peak demand patterns.
   - Quick export card with buttons for monthly PDF and raw CSV (UI only).

## Styling Hooks
- `.reports-grid` — two-column layout (collapses on mobile).
- `.report-card` — reusable analytics card container.
- `.kpi-row` / `.kpi` — numeric highlight blocks.
- `.bar-row`, `.bar-track`, `.bar-fill` — simple bar chart representation.
- `.ai-panel`, `.ai-chip` — visual treatment for AI predictive insights.

## Integration Points
- **Data Layer:** Replace hard-coded metrics with live data from reservations and facilities.
- **AI Predictive Scheduling:** Feed model outputs directly into the AI panel, including confidence levels.
- **Exports:** Wire export buttons to backend report generators (PDF/CSV).
- **Filters:** Extend the page with date range, facility, and role filters as reporting needs grow.

## Next Steps
1. Connect to real metrics endpoints once backend is ready.
2. Add trend indicators (month-over-month deltas) and mini charts.
3. Expose links from reports back into Calendar and Reservations for drill-down.



