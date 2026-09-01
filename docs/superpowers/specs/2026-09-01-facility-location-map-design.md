# Facility Location Map on Booking Page

## Problem

On the booking page (`resources/views/pages/dashboard/book_facility.php`), picking a facility from the dropdown opens a details panel (name, status, location text, capacity, etc.) fetched via `facility-details-api.php`. The location is text-only. Residents can't see at a glance where the facility actually is.

## Goal

Show a small map with a pin at the facility's exact coordinates, right under the existing Location text, when a facility is selected.

## Data

`facilities.latitude` / `facilities.longitude` already exist (`database/migration_add_location_fields.sql`) and are curated via the admin picker in `facility_management.php` (Leaflet + OpenStreetMap tiles, no API key). Per user: virtually all facilities already have coordinates set.

### Backend change

`resources/views/pages/dashboard/facility-details-api.php` — add `latitude, longitude` to the existing SELECT:

```sql
SELECT id, name, location, capacity, capacity_threshold, description, amenities,
       rules, base_rate, is_free, status, image_path, image_citation,
       latitude, longitude
FROM facilities WHERE id = :id LIMIT 1
```

No other backend change needed.

## Frontend

In `book_facility.php`'s existing facility-details AJAX callback (the block that builds `html` from the `facility-details-api` response, around the "Location" section):

- If `facility.latitude` and `facility.longitude` are both present (non-null): render a map block directly after the Location `<p>` text.
- If either is null: render nothing extra — keep the existing text-only Location row. No placeholder, no "coordinates missing" message.

### Map block markup (inserted into the same `html` string build)

```html
<div id="bcf-facility-map" style="width:100%; height:170px; border-radius:10px; border:1px solid #e8ecf4; margin-top:0.6rem; overflow:hidden;"></div>
<a href="https://www.google.com/maps/search/?api=1&query=LAT,LNG" target="_blank" rel="noopener"
   style="display:inline-block; margin-top:0.5rem; font-size:0.85rem; color:var(--gov-blue-dark); text-decoration:none; font-weight:600;">
   Get directions →
</a>
```

(`LAT,LNG` substituted with the actual values; `target="_blank" rel="noopener"` matches the existing external-link pattern in `profile.php`.)

### Map initialization

After the `html` string is assigned to `facilityDetailsContent.innerHTML` (i.e., after the div exists in the DOM), initialize Leaflet:

- Reuse the page's existing Leaflet library (already loaded globally via `dashboard_layout.php` — same `leaflet@1.9.4` + OSM tiles used by `facility_management.php`'s admin picker). No new script/CSS tags, no API key.
- Keep a module-level variable (e.g. `let bcfFacilityMap = null;`) alongside the existing facility-details JS. Before creating a new map instance, if `bcfFacilityMap` is set, call `.remove()` on it first — the details panel is rebuilt via `innerHTML` on every facility switch, so a stale Leaflet instance would otherwise leak.
- `L.map('bcf-facility-map', { zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, touchZoom: false }).setView([lat, lng], 16)`
- Same OSM tile layer as `facility_management.php`:
  ```js
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(bcfFacilityMap);
  ```
- One marker at `[lat, lng]`, no popup (facility name is already shown in the panel header above).
- Only run this init when both coordinates are present; otherwise skip (and if a previous map instance exists from a prior facility that *did* have coordinates, still remove it before bailing, so switching from a mapped facility to an unmapped one doesn't leave a stray map mounted).

### Interaction

Locked down per approved design: no drag/zoom/scroll on the embedded map — it's a static-feeling preview, not something to explore. Real navigation happens via the "Get directions" link, which opens Google Maps in a new tab using the standard `https://www.google.com/maps/search/?api=1&query={lat},{lng}` URL (no API key required).

## Out of scope

- Geocoding from the free-text address (coordinates are already admin-curated and more accurate).
- Any placeholder/messaging for facilities missing coordinates.
- Interactive/pannable map, in-panel routing, or any Maps API key integration.

## Files touched

- `resources/views/pages/dashboard/facility-details-api.php` (SELECT change)
- `resources/views/pages/dashboard/book_facility.php` (details-panel HTML build + map init JS)
