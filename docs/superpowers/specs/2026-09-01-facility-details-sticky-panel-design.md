# Sticky, Height-Capped Facility Details Panel

## Problem

On the booking page (`book_facility.php`), the facility-details aside (`.bcf-aside-col`, `#facility-details-aside`) grows taller than the calendar column next to it once a facility is selected (image, map, description, amenities, rules, equipment, pricing). This leaves large dead space below the shorter calendar column, and the details panel scrolls away with the rest of the page.

## Fix

At the existing `min-width: 1025px` breakpoint (where `.booking-hub-grid.bcf-has-aside` already switches to the two-column `minmax(0, 1fr) 320px` layout), add to `.bcf-aside-col`:

```css
@media (min-width: 1025px) {
    .bcf-aside-col {
        position: sticky;
        top: 1rem;
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
    }
    .bcf-aside-col::-webkit-scrollbar {
        width: 6px;
    }
    .bcf-aside-col::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
    }
}
```

No fixed/sticky header exists on this page (confirmed in `dashboard_layout.php`), so `top: 1rem` needs no header-offset calculation.

Below 1025px (mobile/tablet, single-column layout), no change — the panel stays full-height and static, matching current behavior.

## Out of scope

- No JS changes.
- No change to panel content or the map/details-loading logic added in the prior facility-location-map change.
- No fade-gradient overflow indicator — the thin custom scrollbar is enough visual affordance for this scope.

## Files touched

- `resources/views/pages/dashboard/book_facility.php` (CSS only, near existing `.booking-hub-grid` / `.bcf-aside-col` rules around line 1933-1949)
