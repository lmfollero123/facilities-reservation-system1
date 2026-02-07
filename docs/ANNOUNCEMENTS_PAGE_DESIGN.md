# Announcements Page Design Specification

## Overview
This document outlines the design and layout for a dedicated announcements page that integrates seamlessly with your LGU Facilities Reservation System using your established design patterns.

---

## Design System Reference

### Colors & Gradients
- **Primary Blue**: `#6384d2`, `#285ccd`
- **Accent Colors**: 
  - Emergency/Alert: `#ef4444` (Red)
  - Event: `#6384d2` (Blue)
  - Health: `#10b981` (Green)
  - Deadline: `#f59e0b` (Amber)
  - Advisory: `#3b82f6` (Light Blue)
- **Neutral**: `#1f2937`, `#4b5563`, `#6b7280`, `#9ca3af`
- **Background**: White with glass-morphism effects

### Typography
- **Font Family**: Poppins (Google Fonts)
- **Headings**: Bold, Dark colors (`#1e3a5f`, `#1f2937`)
- **Body Text**: `#4b5563`, `#6b7280`
- **Size Hierarchy**: 2.5rem (h1), 1.75rem (h2), 1.25rem (h3), 1rem (body)

### Components
- **Cards**: Rounded corners (16px), white background, subtle shadows
- **Buttons**: Gradient background, rounded (8px)
- **Icons**: Bootstrap Icons (bi-*)
- **Glass-morphism**: `backdrop-filter: blur()`, `background: rgba()`

---

## Page Layout Structure

### 1. **Header Section**
```
┌─────────────────────────────────────────┐
│  Page Title: "Announcements"            │
│  Subtitle: Descriptive text             │
│  Quick Filters/Sort Options             │
└─────────────────────────────────────────┘
```

**Elements:**
- Large, prominent title
- Optional subtitle with branding
- Filter buttons: All, Emergency, Events, Health, Deadlines
- Sort dropdown: Newest, Oldest, Important First
- Search bar (optional)

---

### 2. **Main Content Grid**

#### Desktop Layout (3 Columns)
```
┌──────────────┬──────────────┬──────────────┐
│  Card 1      │  Card 2      │  Card 3      │
│  (Featured)  │              │              │
├──────────────┼──────────────┼──────────────┤
│  Card 4      │  Card 5      │  Card 6      │
│              │              │              │
└──────────────┴──────────────┴──────────────┘
```

#### Tablet Layout (2 Columns)
```
┌──────────────┬──────────────┐
│  Card 1      │  Card 2      │
├──────────────┼──────────────┤
│  Card 3      │  Card 4      │
└──────────────┴──────────────┘
```

#### Mobile Layout (1 Column)
```
┌──────────────┐
│  Card 1      │
├──────────────┤
│  Card 2      │
├──────────────┤
│  Card 3      │
└──────────────┘
```

---

### 3. **Announcement Card Structure**

```
┌─────────────────────────────────────────┐
│ ▓▓▓ [Icon] TYPE    DATE                 │
│ ▓▓▓                                     │
│ ┌─────────────────────────────────────┐ │
│ │       Featured Image (Optional)      │ │
│ │       Height: 200px, Fit: Cover      │ │
│ └─────────────────────────────────────┘ │
│                                         │
│ Title Goes Here                         │
│                                         │
│ Brief message preview text that         │
│ appears in the announcement card...     │
│                                         │
│ [Read More →]  [Share]  [Pin]          │
└─────────────────────────────────────────┘
```

**Card Features:**
- **Left Accent Bar**: 4px colored bar (type-specific)
- **Header**: Icon + Type Label + Date
- **Image**: Optional, 200px height, cover fit
- **Title**: Bold, 1.25rem, 2-line max
- **Message**: Preview text, 150 char max, ellipsis
- **Footer Actions**: Links and buttons
- **Hover Effect**: Lift up (-4px transform), enhanced shadow

---

### 4. **Card Components Breakdown**

#### Card Header
```
┌─────────────────────────────────────┐
│ ┌───┐                               │
│ │ 🔔 │  EMERGENCY  M d, Y           │
│ └───┘  48x48px icon box             │
│        Gradient background           │
└─────────────────────────────────────┘
```

- Icon Box: 48px square, rounded (12px), gradient fill
- Type Badge: Uppercase, 10px font, 600 weight
- Date: Calendar icon + formatted date (M d, Y)

#### Card Body
- **Title**: Max 2 lines with ellipsis
- **Message**: Max 4 lines, trimmed to ~150 chars
- **Accessibility**: Proper color contrast (WCAG AA)

#### Card Footer
```
[Read More →]  [Share]  [Save]
```

- Action links with icons
- Hover animations (color change, icon movement)
- Optional secondary actions

---

## Responsive Breakpoints

| Breakpoint | Grid Columns | Card Width | Use Case |
|------------|------------|-----------|----------|
| Mobile     | 1          | 100%      | < 481px  |
| Tablet     | 2          | ~48%      | 481-1024px |
| Desktop    | 3          | ~31%      | ≥ 1025px |

**Key Transitions:**
- Padding: 1rem (mobile) → 1.25rem (tablet) → 1.5rem (desktop)
- Gap: 1rem (mobile) → 1.25rem (tablet) → 1.5rem (desktop)
- Image Height: 160px (mobile) → 180px (tablet) → 200px (desktop)

---

## Filter & Sort System

### Category Filters
- **All** (default)
- **🚨 Emergency** (Red)
- **🎉 Events** (Blue)
- **💚 Health** (Green)
- **⏰ Deadlines** (Amber)
- **ℹ️ Advisory** (Light Blue)
- **📢 General** (Gray)

### Sorting Options
- **Newest First** (default)
- **Oldest First**
- **Most Important First** (Emergency → Deadline → Event → Health → Advisory)

### Search Functionality
- Full-text search on title and message
- Tag-based search
- Date range filter

---

## Special Announcement States

### 1. Featured/Pinned Announcement
```
┌─────────────────────────────────────┐
│ ⭐ FEATURED                          │
│ ┌──┐                                │
│ │🔔│ Title in Larger Font           │
│ └──┘                                │
│  [Full-width highlight variant]     │
│  Larger image, more prominent       │
└─────────────────────────────────────┘
```

### 2. Urgent/Emergency Announcement
```
┌─────────────────────────────────────┐
│ 🔴 RED LEFT ACCENT BAR (6px width)  │
│ ANIMATION: Subtle pulse effect      │
│ Icon: Alert triangle                │
│ Background: Slight red tint overlay │
└─────────────────────────────────────┘
```

### 3. New Announcement Badge
```
⭐ NEW  |  Added within 24 hours
```

---

## Color-Coded Accent System

Each announcement type has:
1. **Left Bar Color** (4-6px accent bar)
2. **Icon Gradient** (background for icon box)
3. **Badge Color** (type label background)

### Type Mapping
```
Emergency  → #ef4444 (Red)      + Icon: ⚠️
Event      → #6384d2 (Blue)     + Icon: 🎉
Health     → #10b981 (Green)    + Icon: 💚
Deadline   → #f59e0b (Amber)    + Icon: ⏰
Advisory   → #3b82f6(Sky)       + Icon: ℹ️
General    → #6b7280 (Gray)     + Icon: 📢
```

---

## Empty States

### No Announcements
```
┌─────────────────────────────────────┐
│                                     │
│        📭 No Announcements          │
│                                     │
│   There are currently no updates    │
│   to display. Check back soon!      │
│                                     │
│        [← Back to Home]             │
│                                     │
└─────────────────────────────────────┘
```

### No Results from Filter
```
┌─────────────────────────────────────┐
│                                     │
│      🔍 No Announcements Found      │
│                                     │
│   No announcements match your       │
│   selected filter.                  │
│                                     │
│      [Clear Filters] [Browse All]   │
│                                     │
└─────────────────────────────────────┘
```

---

## Pagination/Load More

### Option 1: Pagination
```
[← Previous]  Page 1 of 5  [Next →]
```

### Option 2: Load More Button
```
┌─────────────────────────────┐
│  ↓ Load More Announcements  │
└─────────────────────────────┘
```

### Option 3: Infinite Scroll
Auto-load next 12 announcements when user scrolls near bottom

---

## Animation Effects

### Card Hover
```css
Transform: translateY(-4px)
Transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1)
Shadow: 0 12px 24px rgba(0,0,0,0.12)
```

### Icon Link Hover
```css
Color change: #285ccd → #1e40af
Icon movement: translateX(4px)
Transition: 0.2s ease
```

### Load Animation
```css
Opacity: 0 → 1
Animation: fadeInUp 0.5s ease-out
Stagger: 50ms between cards
```

### Accent Bar on Urgent (Optional)
```css
Animation: pulse 2s infinite
Background: Semi-transparent red tint
```

---

## Accessibility Features

- **ARIA Labels**: Each announcement type clearly labeled
- **Icon + Text**: Never rely on icon alone
- **Color Contrast**: WCAG AA compliant (4.5:1 minimum)
- **Keyboard Navigation**: Full tabbing support
- **Focus States**: Clear focus indicators on buttons
- **Mobile Touch**: 44x44px minimum touch targets
- **Alt Text**: All images have descriptive alt text
- **Semantic HTML**: Proper heading hierarchy, landmarks

---

## Code Integration Points

### PHP/Backend
- Fetch announcements: `$announcements = fetchAnnouncements($filters, $sort, $page)`
- Pagination: `$totalPages = calculatePages($total, $perPage)`
- Category Detection: `getAnnouncementCategory($title, $message, $type)`
- Image Validation: `validateImagePath($imagePath)`

### CSS Classes
- `.announcements-container` - Main wrapper
- `.announcements-header` - Title section
- `.announcements-filters` - Filter bar
- `.announcements-grid` - Card grid
- `.announcement-card` - Individual card
- `.announcement-card-header` - Card header
- `.announcement-card-body` - Card content
- `.announcement-card-footer` - Card actions
- `.announcement-icon` - Icon container
- `.announcement-type` - Type label
- `.announcement-[type]` - Type-specific class

### JavaScript Features
- Filter toggle
- Sort selection
- Search functionality
- Load more / pagination
- Card animation triggers
- Modal popup for full announcement

---

## Browser Support

- Chrome/Edge: 90+
- Firefox: 88+
- Safari: 14+
- Mobile browsers: iOS 14+, Android 10+

---

## Performance Considerations

- **Images**: Lazy loading, WebP format with fallback
- **Cards**: CSS Grid for efficient layout
- **Animations**: GPU-accelerated transforms
- **Grid Columns**: CSS Grid with auto-fit for flexibility
- **Load Time**: Paginate announcements (12-24 per page)
- **Bundle Size**: Minimal inline CSS, no external dependencies

---

## Example Mobile-First CSS Structure

```css
/* Mobile defaults */
.announcements-grid {
    grid-template-columns: 1fr;
    gap: 1rem;
}

/* Tablet */
@media (min-width: 481px) {
    .announcements-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1.25rem;
    }
}

/* Desktop */
@media (min-width: 1025px) {
    .announcements-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 1.5rem;
    }
}
```

---

## Related System Components

- **Home Page**: Uses 3-card featured announcements section
- **Dashboard**: Admin panel for announcement management
- **Notifications API**: Backend for storing announcements
- **User Preferences**: Allow users to subscribe to announcement types
- **Email Integration**: Optional email notification on new announcements

---

## Next Steps

1. Review design with stakeholders
2. Create HTML prototype
3. Implement responsive CSS
4. Add PHP backend integration
5. Add filter/sort functionality
6. Optimize for mobile
7. Accessibility audit
8. Performance testing
9. User testing
10. Deploy to production
