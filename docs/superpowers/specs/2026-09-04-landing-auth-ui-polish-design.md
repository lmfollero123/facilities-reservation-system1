# Landing + Auth UI Polish

## Problem

UI/UX review of the landing page and login/register screens (using ui-ux-pro-max design guidance) surfaced three real, code-verified gaps. A fourth candidate — a perceived color clash on the login split-screen's right panel — was investigated and found to be a deliberate, well-built gradient motif (`public/css/auth-pages.css:527-583`); not touched.

## 1. Hero entrance animation

`resources/views/pages/public/home.php` already has a full scroll-reveal system (`.home-animate` / `.home-animate-left` / `.home-animate-right` / `.home-animate-scale` classes, driven by `public/js/home-animations.js`'s `IntersectionObserver` + `revealInView()`), applied to every section below the fold. The hero content (`.reservation-hero-eyebrow`, `.reservation-hero-title`, `.reservation-hero-rule`, `.reservation-hero-lead`, `.reservation-hero-ctas`) never got these classes, so it renders static while the rest of the page animates in.

**Fix:** add `home-animate` plus staggered delay classes to those five hero elements. Because `revealInView()` runs on load and checks `getBoundingClientRect()` against the viewport, elements already in view (the hero always is on load) get `.visible` added immediately — no JS changes required, class additions only.

Suggested stagger: eyebrow → delay-1, title → delay-2, rule → delay-3, lead → delay-4, CTAs → delay-5.

## 2. `prefers-reduced-motion` guard for `.home-animate*`

`public/css/home.css`'s `.home-animate*` family (lines ~76-129) has no reduced-motion override anywhere. `home-animations.js` only ever *adds* the `.visible` class — it never removes the initial `opacity:0`/`transform` state — so a user with reduced motion enabled still depends on JS running the same transition, just skips seeing it animate. This is a real accessibility gap (Priority 1 in the design guidance pulled from ui-ux-pro-max).

**Fix:** add to `home.css`:

```css
@media (prefers-reduced-motion: reduce) {
    .home-animate,
    .home-animate-left,
    .home-animate-right,
    .home-animate-scale {
        opacity: 1;
        transform: none;
        transition: none;
    }
}
```

This forces the final visible state immediately regardless of the `.visible` class / JS timing, for every current and future use of these classes sitewide.

## 3. Two small interaction gaps

### Community-updates carousel pause behavior

`resources/views/pages/public/home.php`'s inline script (~line 419) already has a manual pause toggle (`#hcAutoToggle`, `startAuto()`/`stopAuto()`, `autoOn` flag) — good — but:
- Does not pause on mouse hover or keyboard focus entering the widget.
- Auto-plays by default (`autoOn = true` at init) even when the visitor has `prefers-reduced-motion: reduce` set.

**Fix (same script block):**
- On init, check `window.matchMedia('(prefers-reduced-motion: reduce)').matches` — if true, start with `autoOn = false` and don't call `startAuto()` (leave the toggle button available so the visitor can still opt in manually; `aria-pressed` reflects the actual state).
- Add `mouseenter`/`focusin` listeners on the `.hc-widget` container that call `stopAuto()` (without touching the `autoOn` flag — this is a temporary interaction pause, not a state change).
- Add `mouseleave`/`focusout` listeners that call `startAuto()` only if `autoOn` is still `true`.

### Login/Register submit feedback

`resources/views/pages/auth/login.php` (`<button class="btn-primary" type="submit">Login Now</button>`) and `resources/views/pages/auth/register.php` (`<button class="btn-primary" type="submit" id="submitBtn">Create account</button>`) give no feedback on click — no disabled state, no spinner — until the full page navigation completes.

**Fix:** in each page's existing inline `<script>` block (matching the current per-page convention — e.g. login.php's password-toggle script), add a `submit` listener on the form that:
- Disables the submit button (`disabled = true`) to guard against double-submit.
- Swaps its inner text/HTML to a small inline spinner + "Signing in…" (login) / "Creating account…" (register).
- Does NOT call `preventDefault()` — the form still submits normally; this is purely visual feedback during the natural page navigation.

## Out of scope

- No GSAP, motion.dev, or React — all three fixes use the existing vanilla JS / CSS approach already in the codebase.
- No change to the login/register color palette or gradient.
- No change to carousel content, slide count, or timing interval (still 5000ms).

## Files touched

- `resources/views/pages/public/home.php` (hero classes, carousel script additions)
- `public/css/home.css` (reduced-motion media query)
- `resources/views/pages/auth/login.php` (submit loading-state script)
- `resources/views/pages/auth/register.php` (submit loading-state script)
