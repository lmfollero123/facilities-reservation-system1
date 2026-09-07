# Live Facility Status ↔ Facility Map Sync

## Problem

Two independent dashboard widgets sit side by side: `occupancy_dashboard_strip.php` (auto-cycling single-facility hero carousel) and the Leaflet-based `frs_facility_filter_map()` (all facility pins at once). No connection between them, and the carousel only ever shows one facility, auto-advancing every 5.5s.

## Changes

### 1. Strip becomes a scrollable list of all facilities (no auto-slide)

`resources/views/components/occupancy_dashboard_strip.php`: replace the `.occ-dash-carousel` (stage + prev/next + dots) markup with a `.occ-dash-list` container holding one compact row per facility. Each row: thumbnail (48×48), name, status pill (reusing existing `.occ-dash-pill` classes/logic), one meta line (next/current slot or "No reservations today"). Fixed height `380px` (matching `.facility-map-canvas`) with internal `overflow-y: auto` scroll. "View all" button and modal stay unchanged. The header summary line ("N of M facilities busy") stays unchanged.

`public/js/occupancy-dashboard-strip.js`: remove `AUTO_MS`/`autoTimer`/`startAuto`/`stopAuto`/`goTo`/dot-click/prev-next-click logic entirely. Replace `renderCarousel()` with `renderList()`, rendering all facilities as rows via a new `renderListRow(fac, isSelected)` (parallels the existing `renderModalRow`). Add a `selectedId` variable (default `null` — no row selected by default) and a click handler on the list container that sets `selectedId` and re-renders (for the highlight) when a row is clicked, then dispatches the sync event (see below). The 45s live-refresh (`refresh()`/`applySnapshot()`) stays, re-rendering the list instead of the single hero card.

### 2. Sync event

Both files listen for and dispatch a single custom event: `document.dispatchEvent(new CustomEvent('frs:facility-status-select', { detail: { id, source } }))`, `source` is `'strip'` or `'map'`.

- Strip: on row click, dispatch with `source:'strip'`, set `selectedId`, re-render list (border+tint highlight). Also listen for the event; if `detail.source === 'map'`, set `selectedId = detail.id`, re-render, and scroll that row into view (`row.scrollIntoView({block:'nearest'})`).
- Map (`public/js/dashboard-charts.js`, `initFrsFacilityFilterMap`): keep a `markersById` lookup (built alongside the existing `markerGroup.addLayer(marker)` loop) in the `frsFacilityMapConfigs[mapId]` registry entry. On marker click, in addition to the **existing** `frsFacilityMapNavigate(config, p.id)` call (unchanged — still filters the charts below), also dispatch the sync event with `source:'map'`. Also listen for the event; if `detail.source === 'strip'`, look up the marker by id, `map.setView(marker.getLatLng(), 17)` and `marker.openPopup()`.

Each listener ignores the event when `detail.source` matches itself, preventing loops.

## Visual detail

Selected row: `border-left: 3px solid #059669` (matches the app's established emerald accent) + `background: #ecfdf5` tint — color is not the only signal (per accessibility guidance), the border is the primary indicator, tint is secondary.

## Out of scope

- No change to the map's existing chart-filter behavior on pin click.
- No change to the "View all" modal, its search/filter, or the 45s refresh polling.
- No change to facility data/schema — this is presentation + interaction only.

## Files touched

- `resources/views/components/occupancy_dashboard_strip.php` (markup + CSS)
- `public/js/occupancy-dashboard-strip.js` (list rendering, remove carousel logic, add sync event)
- `public/js/dashboard-charts.js` (markersById lookup, dispatch + listen for sync event)
