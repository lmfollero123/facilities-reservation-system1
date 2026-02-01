# My Reservations Page Redesign Plan

## Design Goals for Elderly-Friendly UI

### 1. **Typography & Readability**
- Minimum font size: 16px for body text, 18px for important info
- Line height: 1.6-1.8 for better readability
- High contrast text (WCAG AAA compliance)
- Clear, sans-serif fonts (Poppins)

### 2. **Visual Hierarchy**
- Large, clear status badges with icons
- Color-coded cards for different statuses
- Prominent action buttons (min 44px touch target)
- Generous white space between elements

### 3. **Card Layout**
- Each reservation in a distinct card
- Key information at the top (facility name, date, status)
- Expandable details section (less cognitive load)
- Clear separation between reservations

### 4. **Simplified Actions**
- Primary action buttons prominently displayed
- Secondary actions in dropdown/menu
- Clear button labels (no icons-only buttons)
- Confirmation dialogs for destructive actions

### 5. **Mobile Responsiveness**
- Stack elements vertically on mobile
- Touch-friendly buttons (min 44x44px)
- Simplified filters on mobile (drawer/modal)
- Swipe gestures for card actions

### 6. **Accessibility Features**
- ARIA labels for screen readers
- Keyboard navigation support
- Focus indicators
- Skip links for navigation

## New Layout Structure

```
┌─────────────────────────────────────┐
│ Page Header                         │
│ - Title (large, clear)              │
│ - Subtitle/description              │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ Quick Stats (optional)              │
│ - Total reservations                │
│ - Upcoming, Pending, etc.           │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ Filters (collapsible on mobile)    │
│ - Status tabs (large buttons)      │
│ - Search (large input)              │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ Reservation Card 1                  │
│ ┌─────────────────────────────────┐ │
│ │ 📍 Facility Name (Large)        │ │
│ │ 📅 Date & Time (Large)          │ │
│ │ ✅ Status Badge (Large, colored)│ │
│ ├─────────────────────────────────┤ │
│ │ [View Details ▼] (expandable)   │ │
│ │ - Timeline                       │ │
│ │ - Notes                          │ │
│ ├─────────────────────────────────┤ │
│ │ [Primary Action Button]          │ │
│ │ [Secondary Action Button]        │ │
│ └─────────────────────────────────┘ │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ Pagination (large, clear)           │
└─────────────────────────────────────┘
```

## Color Coding for Status
- **Pending**: Blue (#2196F3)
- **Approved**: Green (#4CAF50)
- **Denied**: Red (#F44336)
- **Cancelled**: Gray (#9E9E9E)
- **On Hold**: Orange (#FF9800)
- **Postponed**: Purple (#9C27B0)

## Implementation Steps
1. Create new card component with larger text
2. Add status icons and color coding
3. Implement collapsible details section
4. Redesign action buttons (larger, clearer)
5. Add mobile-responsive breakpoints
6. Test with accessibility tools
7. Add smooth transitions/animations
