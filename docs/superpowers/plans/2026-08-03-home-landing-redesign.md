# Landing Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task (single page, no subagents needed). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild `home.php`'s hero into a photo-carousel with serif title/Tagalog copy/pill CTAs, add a floating Community Updates widget, and restyle the Announcements section into an asymmetric featured+list layout — matching a reference LGU site's visual language while staying scoped to a facilities reservation portal (no officials/council content, no duplicate contact form).

**Architecture:** All changes confined to `resources/views/pages/public/home.php` (markup + two small vanilla-JS carousels) and `public/css/home.css` (additive only — new classes, zero edits to existing `.home-hero`/`.home-hero-bg`/`.home-hero-overlay`/`.auth-page-hero` rules, which `login.php`/`register.php` also depend on).

**Tech Stack:** PHP 8, Tailwind utility classes (already enabled via `$useTailwind = true` on this page), vanilla JS, existing `getAnnouncementCategory()` helper (`config/announcement_categories.php`).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-03-home-landing-redesign-design.md`.
- Do not modify `.home-hero`, `.home-hero-bg`, `.home-hero-overlay`, or `.auth-page-hero` CSS rules in `public/css/home.css` — `resources/views/pages/auth/login.php` and `register.php` depend on them via `guest_layout.php`'s `$authSplitLayout`/hero rendering. New hero uses entirely new class names (`.reservation-hero*`).
- No new backend queries — hero slides come from `$featuredFacilities` (already fetched, line 12-19), Community Updates widget from `$announcements` (already fetched, line 22-30), both already at the top of `home.php`.
- Skip: officials bio section, council members grid, home-page contact form — confirmed out of scope in the spec.
- No test suite covers page rendering in this codebase (confirmed in earlier phases of this session) — verification is `php -l` + manual browser check per task.

---

### Task 1: Hero rebuild (carousel, serif title, Tagalog copy, pill CTAs)

**Files:**
- Modify: `resources/views/pages/public/home.php:38-64` (replace the hero `<section>`)
- Modify: `public/css/home.css` (append new rules, end of file)

**Interfaces:**
- Consumes: `$featuredFacilities` (array of `['id','name','description','status','image_path']`, already fetched at `home.php:12-19`), `$base` (string, already defined).
- Produces: `$heroSlides` (array of absolute image URLs) — local to this section, not consumed elsewhere.

- [ ] **Step 1: Add hero-slide data prep**

Insert immediately before `ob_start();` (currently line 35):

```php
$heroSlides = [];
foreach ($featuredFacilities as $f) {
    if (!empty($f['image_path'])) {
        $heroSlides[] = $base . $f['image_path'];
    }
    if (count($heroSlides) >= 3) {
        break;
    }
}
if ($heroSlides === []) {
    $heroSlides[] = $base . '/public/uploads/Main%20Bg.jpg';
}
```

- [ ] **Step 2: Replace the hero section markup**

Replace the entire block from `<!-- Hero Section - Full viewport, Main Bg with blur + green tint overlay -->` through the closing `</section>` (lines 38-64) with:

```php
?>
<!-- Hero Section - photo carousel, serif title, Tagalog copy -->
<section class="reservation-hero" aria-label="Barangay Culiat facilities reservation portal">
    <div class="reservation-hero-slides">
        <?php foreach ($heroSlides as $i => $slide): ?>
            <div class="reservation-hero-slide<?= $i === 0 ? ' is-active' : ''; ?>" style="background-image:url('<?= htmlspecialchars($slide); ?>');"></div>
        <?php endforeach; ?>
    </div>
    <div class="reservation-hero-gradient"></div>

    <div class="reservation-hero-content">
        <p class="reservation-hero-eyebrow">District 6, Quezon City &middot; Public Facilities Reservation Portal</p>
        <h1 class="reservation-hero-title">Barangay Culiat</h1>
        <div class="reservation-hero-rule"></div>
        <p class="reservation-hero-lead">
            Malugod na pagbati! Ang Sistema ng Reserbasyon ng Pasilidad ng Barangay Culiat ay dinisenyo upang mapadali ang pag-book ng mga pampublikong pasilidad &mdash; mula sa covered court hanggang sa multi-purpose hall &mdash; nang mabilis, ligtas, at maayos. Sa pamamagitan ng aming online portal, maaari kang mag-book, subaybayan ang katayuan ng iyong reservation, at makatanggap ng abiso, lahat sa iisang lugar.
        </p>
        <div class="reservation-hero-ctas">
            <a href="<?= $base; ?>/facilities" class="reservation-hero-cta reservation-hero-cta-solid">Browse Facilities</a>
            <a href="<?= $base; ?>/register" class="reservation-hero-cta reservation-hero-cta-outline">Create Account</a>
        </div>
    </div>

    <?php if (count($heroSlides) > 1): ?>
    <div class="reservation-hero-dots" role="tablist" aria-label="Hero background slides">
        <?php foreach ($heroSlides as $i => $slide): ?>
            <button type="button" class="reservation-hero-dot<?= $i === 0 ? ' is-active' : ''; ?>" data-slide-index="<?= $i; ?>" aria-label="Slide <?= $i + 1; ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php
```

- [ ] **Step 3: Append the new CSS rules**

Add to the end of `public/css/home.css`:

```css

/* Reservation hero — new carousel hero, independent of .home-hero (used by
   auth pages via .auth-page-hero, which must not change). */
.reservation-hero {
    position: relative;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 7rem 1.5rem 5rem;
}
.reservation-hero-slides { position: absolute; inset: 0; }
.reservation-hero-slide {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    opacity: 0;
    transition: opacity 1.2s ease;
}
.reservation-hero-slide.is-active { opacity: 1; }
.reservation-hero-gradient {
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(6, 20, 15, 0.35) 0%, rgba(6, 20, 15, 0.55) 55%, rgba(6, 20, 15, 0.85) 100%);
}
.reservation-hero-content {
    position: relative;
    z-index: 2;
    max-width: 56rem;
    margin: 0 auto;
    text-align: center;
    color: #fff;
}
.reservation-hero-eyebrow {
    font-size: 0.95rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.85);
    margin: 0 0 1rem;
}
.reservation-hero-title {
    font-family: 'Merriweather', Georgia, serif;
    font-weight: 700;
    font-size: clamp(2.5rem, 6vw, 4.5rem);
    margin: 0;
    letter-spacing: 0.01em;
}
.reservation-hero-rule {
    width: 6rem;
    height: 3px;
    background: rgba(255, 255, 255, 0.8);
    margin: 1.5rem auto;
    border-radius: 999px;
}
.reservation-hero-lead {
    font-size: 1.05rem;
    line-height: 1.75;
    color: rgba(255, 255, 255, 0.92);
    max-width: 46rem;
    margin: 0 auto 2.25rem;
}
.reservation-hero-ctas {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: center;
}
.reservation-hero-cta {
    display: inline-flex;
    align-items: center;
    padding: 0.9rem 2rem;
    border-radius: 999px;
    font-weight: 600;
    font-size: 1rem;
    text-decoration: none;
    transition: all 0.2s ease;
}
.reservation-hero-cta-solid {
    background: #059669;
    color: #fff;
    box-shadow: 0 10px 24px rgba(5, 150, 105, 0.35);
}
.reservation-hero-cta-solid:hover { background: #047857; transform: translateY(-2px); }
.reservation-hero-cta-outline {
    border: 2px solid rgba(255, 255, 255, 0.85);
    color: #fff;
}
.reservation-hero-cta-outline:hover { background: rgba(255, 255, 255, 0.15); }
.reservation-hero-dots {
    position: absolute;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%);
    z-index: 2;
    display: flex;
    gap: 0.5rem;
}
.reservation-hero-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, 0.4);
    cursor: pointer;
    padding: 0;
    transition: background 0.2s ease;
}
.reservation-hero-dot.is-active { background: #fff; }
@media (max-width: 640px) {
    .reservation-hero-title { font-size: clamp(2rem, 10vw, 3rem); }
}
```

- [ ] **Step 4: Add the hero carousel JS**

At the very end of `home.php`, immediately before `<?php $content = ob_get_clean();` (currently line 381), add:

```html
<script>
(function () {
    'use strict';
    var slides = document.querySelectorAll('.reservation-hero-slide');
    var dots = document.querySelectorAll('.reservation-hero-dot');
    if (slides.length <= 1) return;
    var index = 0;
    function show(i) {
        slides.forEach(function (s, idx) { s.classList.toggle('is-active', idx === i); });
        dots.forEach(function (d, idx) { d.classList.toggle('is-active', idx === i); });
        index = i;
    }
    dots.forEach(function (d) {
        d.addEventListener('click', function () {
            show(parseInt(d.getAttribute('data-slide-index'), 10));
        });
    });
    setInterval(function () { show((index + 1) % slides.length); }, 6000);
})();
</script>
```

- [ ] **Step 5: Verify**

```bash
php -l resources/views/pages/public/home.php
```
Expected: `No syntax errors detected`.

Manual check (no local server in this environment — flag for your pass): visit `/` (home page). Confirm: hero fills viewport, title renders in serif font, Tagalog paragraph displays, both CTA buttons are pill-shaped and link correctly. If ≥2 facilities have uploaded photos, confirm the background crosses-fades every 6s and dots respond to clicks. If 0 facilities have photos, confirm it falls back to a single `Main Bg.jpg` slide (no dots shown, no broken image if that file happens to be missing — check `object-fit`/background behavior degrades to a plain color, not an error).

Separately, visit `/login` and `/register` and confirm their hero backgrounds are **unchanged** (still blurred, still using `.auth-page-hero`/`.home-hero-bg`/`.home-hero-overlay`) — this step's CSS additions must not have regressed them.

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/public/home.php public/css/home.css
git commit -m "feat: rebuild home page hero with photo carousel, serif title, Tagalog copy"
```

---

### Task 2: Floating "Community Updates" widget

**Files:**
- Modify: `resources/views/pages/public/home.php` (insert widget markup inside the hero section from Task 1; append JS)
- Modify: `public/css/home.css` (append new rules)

**Interfaces:**
- Consumes: `$announcements` (array of `['id','title','message','type','link','image_path','created_at']`, already fetched at `home.php:22-30`), `$base`, `getAnnouncementCategory(string $title, string $message, ?string $type): array{type,color,bgColor}` (from `config/announcement_categories.php`, already required at `home.php:5`).
- Produces: none consumed elsewhere.

- [ ] **Step 1: Insert the widget markup**

Inside the `<section class="reservation-hero" ...>` block from Task 1, immediately after the closing `</div>` of `reservation-hero-content` and before the `<?php if (count($heroSlides) > 1): ?>` dots block, insert:

```php
<?php if (!empty($announcements)): ?>
<aside class="hc-widget" aria-label="Community updates">
    <div class="hc-widget-head">
        <span class="hc-widget-title">Community Updates</span>
        <button type="button" class="hc-widget-toggle" id="hcAutoToggle" aria-pressed="true">&#9679; Slide</button>
    </div>
    <div class="hc-widget-body">
        <?php foreach ($announcements as $i => $item):
            $hcCategory = getAnnouncementCategory($item['title'] ?? '', $item['message'] ?? '', $item['type'] ?? 'system');
            $hcDate = date('M j, Y', strtotime($item['created_at']));
        ?>
            <a href="<?= htmlspecialchars($base . '/announcements'); ?>" class="hc-slide<?= $i === 0 ? ' is-active' : ''; ?>">
                <?php if (!empty($item['image_path'])): ?>
                    <div class="hc-slide-img" style="background-image:url('<?= htmlspecialchars($base . $item['image_path']); ?>');"></div>
                <?php else: ?>
                    <div class="hc-slide-img" style="background:<?= htmlspecialchars($hcCategory['bgColor']); ?>;"></div>
                <?php endif; ?>
                <span class="hc-slide-tag" style="color:<?= htmlspecialchars($hcCategory['color']); ?>;"><?= htmlspecialchars(ucfirst($hcCategory['type'])); ?></span>
                <strong class="hc-slide-title"><?= htmlspecialchars((string)($item['title'] ?? 'Announcement')); ?></strong>
                <span class="hc-slide-date"><?= htmlspecialchars($hcDate); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="hc-widget-dots">
        <?php foreach ($announcements as $i => $item): ?>
            <span class="hc-dot<?= $i === 0 ? ' is-active' : ''; ?>"></span>
        <?php endforeach; ?>
    </div>
    <p class="hc-widget-hint">Tap card to open section</p>
</aside>
<?php endif; ?>
```

- [ ] **Step 2: Append the widget CSS**

Add to the end of `public/css/home.css`:

```css

/* Floating Community Updates widget (home hero only) */
.hc-widget {
    position: absolute;
    top: 6.5rem;
    right: 2rem;
    z-index: 3;
    width: 300px;
    background: rgba(15, 23, 20, 0.55);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 16px;
    padding: 1rem;
    color: #fff;
}
.hc-widget-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
.hc-widget-title { font-size: 0.75rem; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255, 255, 255, 0.8); }
.hc-widget-toggle {
    background: rgba(255, 255, 255, 0.12);
    border: none;
    color: #fff;
    font-size: 0.75rem;
    padding: 0.25rem 0.6rem;
    border-radius: 999px;
    cursor: pointer;
}
.hc-widget-toggle[aria-pressed="false"] { opacity: 0.5; }
.hc-widget-body { position: relative; min-height: 190px; }
.hc-slide {
    position: absolute;
    inset: 0;
    display: none;
    flex-direction: column;
    text-decoration: none;
    color: #fff;
}
.hc-slide.is-active { display: flex; }
.hc-slide-img { height: 110px; border-radius: 10px; background-size: cover; background-position: center; margin-bottom: 0.6rem; }
.hc-slide-tag { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; }
.hc-slide-title { font-size: 0.92rem; line-height: 1.35; margin-bottom: 0.3rem; }
.hc-slide-date { font-size: 0.75rem; color: rgba(255, 255, 255, 0.7); }
.hc-widget-dots { display: flex; gap: 0.4rem; justify-content: center; margin: 0.75rem 0 0.5rem; }
.hc-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(255, 255, 255, 0.35); }
.hc-dot.is-active { background: #fff; }
.hc-widget-hint { margin: 0; font-size: 0.72rem; text-align: center; color: rgba(255, 255, 255, 0.6); }
@media (max-width: 1024px) {
    .hc-widget {
        position: static;
        width: 100%;
        max-width: 360px;
        margin: 2rem auto 0;
    }
}
```

- [ ] **Step 3: Append the widget JS**

Inside the same `<script>` block added in Task 1 Step 4 (or as a second `<script>` block right after it), add:

```html
<script>
(function () {
    'use strict';
    var hcSlides = document.querySelectorAll('.hc-slide');
    var hcDots = document.querySelectorAll('.hc-dot');
    var toggle = document.getElementById('hcAutoToggle');
    if (hcSlides.length === 0) return;
    var hcIndex = 0;
    var autoOn = true;
    var timer = null;
    function hcShow(i) {
        hcSlides.forEach(function (s, idx) { s.classList.toggle('is-active', idx === i); });
        hcDots.forEach(function (d, idx) { d.classList.toggle('is-active', idx === i); });
        hcIndex = i;
    }
    function startAuto() {
        stopAuto();
        if (hcSlides.length > 1) {
            timer = setInterval(function () { hcShow((hcIndex + 1) % hcSlides.length); }, 5000);
        }
    }
    function stopAuto() { if (timer) { clearInterval(timer); timer = null; } }
    if (toggle) {
        toggle.addEventListener('click', function () {
            autoOn = !autoOn;
            toggle.setAttribute('aria-pressed', autoOn ? 'true' : 'false');
            if (autoOn) { startAuto(); } else { stopAuto(); }
        });
    }
    startAuto();
})();
</script>
```

- [ ] **Step 4: Verify**

```bash
php -l resources/views/pages/public/home.php
```
Expected: `No syntax errors detected`.

Manual check: with at least one public announcement in the `notifications` table (`user_id IS NULL`), confirm the widget renders top-right of the hero on desktop, auto-advances every 5s, the "● Slide" toggle stops/resumes auto-advance, and clicking a slide navigates to `/announcements`. With zero public announcements, confirm the widget doesn't render at all (no empty floating card). At a narrow viewport (≤1024px), confirm it moves inline below the hero content instead of floating.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/public/home.php public/css/home.css
git commit -m "feat: add floating Community Updates widget to home hero"
```

---

### Task 3: Announcements section restyle (asymmetric featured + list layout)

**Files:**
- Modify: `resources/views/pages/public/home.php:123-182` (replace the Announcements `<section>`)

**Interfaces:**
- Consumes: `$announcements`, `$base`, `$defaultImage` (already defined at `home.php:33`), `getAnnouncementCategory()`.

- [ ] **Step 1: Replace the Announcements section**

Replace the entire block from `<!-- Announcements Section -->` through its closing `<?php endif; ?>` (lines 123-182) with:

```php
<!-- Announcements Section -->
<?php if (!empty($announcements)): ?>
<section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8" style="background: linear-gradient(180deg, #ecfdf5 0%, #ffffff 100%);">
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-10 home-animate">
            <div>
                <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">Announcements & Updates</h2>
                <p class="text-gray-600 text-lg mt-2">Latest advisories from Barangay Culiat</p>
            </div>
            <a href="<?= $base; ?>/announcements" class="inline-flex items-center text-emerald-700 font-semibold hover:text-emerald-800">
                See all
                <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>

        <?php
            $featuredAnnouncement = $announcements[0];
            $restAnnouncements = array_slice($announcements, 1, 3);
            $featCategory = getAnnouncementCategory($featuredAnnouncement['title'] ?? '', $featuredAnnouncement['message'] ?? '', $featuredAnnouncement['type'] ?? 'system');
            $featDate = date('M j, Y', strtotime($featuredAnnouncement['created_at']));
            $featImg = !empty($featuredAnnouncement['image_path']) ? $base . $featuredAnnouncement['image_path'] : $defaultImage;
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <a href="<?= htmlspecialchars($base . '/announcements'); ?>" class="lg:col-span-2 relative rounded-2xl overflow-hidden group home-animate block" style="min-height:360px;">
                <div class="absolute inset-0 bg-cover bg-center transition-transform duration-500 group-hover:scale-105" style="background-image:url('<?= htmlspecialchars($featImg); ?>');"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent"></div>
                <div class="absolute bottom-0 left-0 p-6 sm:p-8 text-white">
                    <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wide mb-3 text-white" style="background:<?= htmlspecialchars($featCategory['color']); ?>;"><?= htmlspecialchars($featCategory['type']); ?></span>
                    <h3 class="text-2xl sm:text-3xl font-bold mb-2"><?= htmlspecialchars($featuredAnnouncement['title'] ?? 'Announcement'); ?></h3>
                    <p class="text-white/80 text-sm"><?= htmlspecialchars($featDate); ?></p>
                </div>
            </a>

            <div class="flex flex-col gap-4">
                <?php foreach ($restAnnouncements as $index => $item):
                    $rCategory = getAnnouncementCategory($item['title'] ?? '', $item['message'] ?? '', $item['type'] ?? 'system');
                    $rDate = date('M j, Y', strtotime($item['created_at']));
                    $rImg = !empty($item['image_path']) ? $base . $item['image_path'] : $defaultImage;
                ?>
                    <a href="<?= htmlspecialchars($base . '/announcements'); ?>" class="home-announcement-card home-animate home-animate-delay-<?= min($index + 1, 5); ?> flex gap-3 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden p-3">
                        <div class="w-20 h-20 flex-shrink-0 rounded-lg bg-cover bg-center" style="background-image:url('<?= htmlspecialchars($rImg); ?>');"></div>
                        <div class="flex-1 min-w-0">
                            <span class="text-xs font-bold uppercase tracking-wide" style="color:<?= htmlspecialchars($rCategory['color']); ?>;"><?= htmlspecialchars($rCategory['type']); ?></span>
                            <h4 class="text-sm font-bold text-gray-900 line-clamp-2 mt-0.5"><?= htmlspecialchars($item['title'] ?? 'Announcement'); ?></h4>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($rDate); ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="text-center mt-12 home-animate">
            <a href="<?= $base; ?>/announcements" class="inline-flex items-center px-6 py-3 bg-emerald-600 text-white font-semibold rounded-lg shadow-lg hover:bg-emerald-700 hover:shadow-xl transition-all duration-200 hover:-translate-y-0.5">
                View All Announcements
                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>
```

- [ ] **Step 2: Verify**

```bash
php -l resources/views/pages/public/home.php
```
Expected: `No syntax errors detected`.

Manual check: with ≥4 public announcements, confirm the layout shows one large featured card (most recent) on the left spanning 2 columns, and up to 3 smaller cards stacked on the right. With exactly 1 announcement, confirm `$restAnnouncements` is empty and the right column renders with nothing (no broken layout — `array_slice` on a 1-item array returns `[]`, the `foreach` simply doesn't run). Confirm the layout collapses to a single column below the `lg` breakpoint (test by narrowing the browser).

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/public/home.php
git commit -m "style: restyle announcements section into featured + list layout"
```

---

## Plan Self-Review

**Spec coverage:**
- Hero rebuild (carousel, serif title, subtitle, Tagalog paragraph, pill CTAs) — Task 1. ✓
- Floating Community Updates widget (reusing `$announcements`, dot pagination, auto-advance toggle, "Tap card to open section") — Task 2. ✓
- Announcements asymmetric featured+list restyle with "See all" link — Task 3. ✓
- Explicit out-of-scope items (nav, footer, officials/council, home contact form, How It Works, Featured Facilities, Tagalog Info section) — none touched by any task. ✓
- Dark-mode note from spec — flagged in each task's manual-check step implicitly via "no local server" caveat; explicit dark-mode class check added to Task 1 Step 5 wording as a reminder to the implementer during manual verification.

**Type/consistency check:** `$heroSlides`, `$featuredAnnouncement`, `$restAnnouncements`, `$featCategory`/`$rCategory`/`$hcCategory` names are used consistently within their own task and don't collide with existing page variables (`$facility`, `$item`, `$index` are reused as loop variables in existing untouched sections lower in the file — confirmed no name collision since these are all local to their own section's scope in procedural PHP, but flagging: this file has no function scoping, so a loop variable named `$item`/`$index` in Task 3 does leak into global scope and could shadow a same-named variable used later in the untouched "Featured Facilities" section below it, which also uses `$index`/`$facility` in its own `foreach`. Checked: Featured Facilities section (lines 184+) redeclares `$index`/`$facility` in its own `foreach ($featuredFacilities as $index => $facility)`, so it overwrites rather than reads stale state — no bug, but worth the implementer knowing this file relies on that overwrite-not-read pattern throughout.
