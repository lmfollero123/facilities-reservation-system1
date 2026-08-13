# Landing Page (home.php) Redesign — Design

## Context

User wants the landing page's look and feel to match a reference LGU e-services site (Barangay Culiat's own site, screenshots supplied), adapted to this system's actual purpose — a **public facilities reservation portal**, not a full e-gov services site. Confirmed: keep our own content (no officials/council bios, no duplicate contact form on home — we have a dedicated `/contact` page already); match the *visual language* only where it fits.

Reference sections and their fate:
| Reference section | Decision |
|---|---|
| Hero (photo carousel, serif title, Tagalog paragraph, pill CTAs) | **Rebuild** — biggest visual gap vs. our current hero |
| Floating "Community Updates" card | **Add new** — reuses data we already fetch |
| Announcements (featured + list layout) | **Restyle** — reuses existing `$announcements` query |
| Punong Barangay bio | Skip — no officials data model, not applicable |
| Council Members grid | Skip — same reason |
| Contact form on home | Skip — duplicates existing `/contact` page |
| Footer (green, brand/links/map/legal) | Already matches — our `resources/views/components/footer.php` is structurally near-identical. No redesign, skip. |

## 1. Hero rebuild

File: `resources/views/pages/public/home.php` (hero section, lines ~38-64 today).

- **Background**: carousel instead of static image. Slides = up to 3 real facility photos from `$featuredFacilities` (already fetched, `image_path` or `frs_facility_placeholder_image()` fallback — reuses the helper added earlier this session), with the admin-uploaded `Main Bg.jpg` as slide 1 if no facility photos exist yet. Dark bottom-heavy gradient overlay (replacing current lighter blur+tint) so white serif text reads directly on any photo. Dot indicators + prev/next arrows, auto-advance every ~6s, pause on hover — plain JS, no new library (matches this codebase's convention, e.g. the vanilla-JS pattern already used in `energy_efficiency.php`'s scroll-shadow script).
- **Title**: "Barangay Culiat" in `font-family: 'Merriweather', Georgia, serif` (already loaded in `guest_layout.php:127-128`, no new font dependency).
- **Subtitle line**: "District 6, Quezon City · Public Facilities Reservation Portal".
- **Tagalog paragraph**: rewritten to describe booking facilities specifically (draft below), replacing the current English paragraph.
- **CTAs**: two pill-shaped buttons (`border-radius: 999px`, replacing current `rounded-lg`), same targets as today — solid "Browse Facilities" → `/facilities`, outline "Create Account" → `/register`.

Draft Tagalog copy:
> "Malugod na pagbati! Ang Sistema ng Reserbasyon ng Pasilidad ng Barangay Culiat ay dinisenyo upang mapadali ang pag-book ng mga pampublikong pasilidad — mula sa covered court hanggang sa multi-purpose hall — nang mabilis, ligtas, at maayos. Sa pamamagitan ng aming online portal, maaari kang mag-book, subaybayan ang katayuan ng iyong reservation, at makatanggap ng abiso, lahat sa iisang lugar."

## 2. Floating "Community Updates" card (new)

- Fixed-position card, top-right of hero on desktop (≥1025px); on smaller viewports it moves inline below the hero content instead of floating over the photo (floating over a photo doesn't work at narrow widths — no viable overlay space).
- Data: reuses `$announcements` (already fetched in `home.php:22-30`, `notifications` table, `user_id IS NULL`). No new query.
- Content per slide: category tag (via existing `getAnnouncementCategory()` helper, same one the Announcements section uses), title, date, thumbnail if `image_path` set else a subtle placeholder tint.
- Controls: dot pagination, prev/next arrows, a small "● Slide" pill toggling auto-advance on/off (matches reference), "Tap card to open section" footer text linking to `/announcements`.
- Implementation: small vanilla-JS carousel component, scoped to this widget only (own class prefix `hc-` for "home community" to avoid collisions).

## 3. Announcements section restyle

File: `resources/views/pages/public/home.php` (lines ~123-182 today).

- Replace the current vertical stack with an asymmetric layout: one large featured card (first/most recent announcement — full-bleed image with dark gradient overlay, category pill, large title, date + location icons, excerpt) on the left, and up to 3 smaller list-style cards (thumbnail + category + title + date) stacked on the right — same `auto-fit` responsive collapse to single column below `lg`.
- "See all" text link top-right of the section header (in addition to the existing "View All Announcements" button at the bottom — reference has both, matches the pattern of giving impatient users a fast exit).
- Same `$announcements` data, same `getAnnouncementCategory()` category coloring already in use — no backend changes.

## Out of scope (explicit)

- Nav bar — not touching `navbar_guest.php`; the reference's Services/Committee/People/Downloads/Report/About mega-nav belongs to a full e-gov site, not a reservation portal.
- Footer — already matches, no changes.
- How It Works section, Featured Facilities grid, Tagalog "Important Information" section — keep as-is, no redesign (reference doesn't show equivalents to compare against; these are already reasonably polished).
- Official barangay seal graphic — we don't have the actual Barangay Culiat emblem asset (only Quezon City's seal and a generic InfraGov logo, neither matches). Hero renders without a seal graphic; can be added later if the user supplies the real asset.

## Testing

- Hero carousel: verify it degrades gracefully to a single static slide when `$featuredFacilities` is empty and `Main Bg.jpg` doesn't exist either (should fall back to a solid color/gradient background, never a broken image).
- Floating widget: verify it doesn't render at all when `$announcements` is empty (no empty floating card).
- Announcements restyle: verify featured card always shows the most recent announcement; verify layout collapses to single column below `lg` breakpoint without overlap.
- Dark mode: `guest_layout.php:70-73` reads `localStorage.getItem('publicTheme')` / `('theme')` and sets `data-theme="dark"` on `<html>` — a user who enabled dark mode in the dashboard sees it here too, even with no visible toggle on this page. Verify the hero overlay, pill CTAs, and both new/restyled Announcements cards render legibly under `html[data-theme="dark"]` — don't assume light mode is the only state.
