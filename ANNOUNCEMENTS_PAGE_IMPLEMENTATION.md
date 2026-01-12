# Announcements Page - Implementation Guide

## Quick Start

The announcements page has been designed using your system's existing design patterns and is ready to use. This guide covers setup, customization, and deployment.

---

## Files Created

1. **[ANNOUNCEMENTS_PAGE_DESIGN.md](ANNOUNCEMENTS_PAGE_DESIGN.md)** - Detailed design specification and layout guidelines
2. **[resources/views/pages/public/announcements.php](resources/views/pages/public/announcements.php)** - Ready-to-use PHP template

---

## Features Included

✅ **Responsive Design**
- Mobile: 1 column
- Tablet: 2 columns  
- Desktop: 3 columns

✅ **Filter System**
- All Announcements
- Emergency
- Events
- Health
- Deadlines
- Advisory
- General

✅ **Sorting Options**
- Newest First (default)
- Oldest First
- Most Important First

✅ **Visual Elements**
- Color-coded accent bars
- Gradient icon containers
- Category labels with icons
- Optional announcement images
- "Read More" links

✅ **Mobile Optimized**
- Touch-friendly buttons
- Full-width cards
- Responsive typography
- Adaptive images

✅ **Accessibility**
- Semantic HTML
- WCAG AA color contrast
- Icon + text labels
- Keyboard navigation
- Focus indicators

---

## Setup Instructions

### 1. Add Route (if needed)

Edit your router configuration to add the announcements page route:

```php
// In your routes file (e.g., routes.php or index.php)
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Add this route:
if ($path === 'announcements' || $path === '/announcements') {
    require_once __DIR__ . '/resources/views/pages/public/announcements.php';
    exit;
}
```

### 2. Add Navigation Link

Add a link to the announcements page in your navigation:

**In navbar_guest.php:**
```php
<a href="<?= base_path(); ?>/announcements" class="nav-link">
    <i class="bi bi-megaphone"></i> Announcements
</a>
```

**In footer or home page:**
```php
<a href="<?= base_path(); ?>/announcements" class="btn btn-outline">
    View All Announcements <i class="bi bi-arrow-right"></i>
</a>
```

### 3. Database Requirements

The page queries from your existing `notifications` table with these requirements:

```sql
-- Ensure these columns exist:
- id (INT PRIMARY KEY)
- title (VARCHAR)
- message (TEXT)
- type (VARCHAR) -- 'system' for public announcements
- link (VARCHAR, nullable)
- image_path (VARCHAR, nullable)
- created_at (TIMESTAMP)
- user_id (INT, nullable) -- Must be NULL for public announcements
```

Current structure matches your existing setup ✓

---

## Customization

### Changing Colors

Edit the color variables in the CSS section of `announcements.php`:

```css
/* Line 240-246 */
$accentColors = [
    'primary' => '#6384d2',      /* Your primary blue */
    'danger' => '#ef4444',       /* Emergency red */
    'success' => '#10b981',      /* Health green */
    'warning' => '#f59e0b',      /* Deadline amber */
    'info' => '#3b82f6',         /* Advisory blue */
    'secondary' => '#6b7280',    /* General gray */
];
```

### Changing Grid Columns

Mobile-first grid configuration (lines 75-89):

```css
/* Desktop: 3 columns */
@media (min-width: 1025px) {
    .announcements-grid {
        grid-template-columns: repeat(3, 1fr);  /* Change 3 to desired columns */
        gap: 2rem;
    }
}

/* Tablet: 2 columns */
@media (min-width: 481px) {
    .announcements-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1.75rem;
    }
}
```

### Changing Items Per Page

```php
// Line 28
$perPage = 12;  // Change to 6, 9, 15, etc.
```

### Changing Card Height/Image Size

```css
/* Line 156 */
.announcement-image {
    height: 200px;  /* Change to 150px, 250px, etc. */
    object-fit: cover;
}
```

### Category Detection Logic

The page auto-detects announcement types based on title/message keywords (lines 43-67):

```php
'patterns' => [
    'emergency' => ['/emergency|urgent|alert|critical/i'],
    'event' => ['/event|activity|program|ceremony/i'],
    'health' => ['/health|medical|vaccine|clinic/i'],
    'deadline' => ['/deadline|due date|submit by/i'],
    'advisory' => ['/advisory|notice|reminder|please note/i'],
];
```

**To add keywords:** Edit the regex patterns to match your announcement titles.

---

## Theming to Match Your Design

The page already uses your system's design:
- **Font**: Poppins (via your global CSS)
- **Colors**: Your blue gradient (#6384d2 → #285ccd)
- **Shadows**: Consistent with your card system
- **Spacing**: Mobile-first responsive approach
- **Icons**: Bootstrap Icons (already in your system)
- **Layout**: Grid-based like your facility cards

No additional CSS needed! ✓

---

## Testing Checklist

- [ ] **Responsive**: Test on mobile, tablet, desktop
- [ ] **Filters**: Click each category, verify results
- [ ] **Sorting**: Test "Newest", "Oldest", "Important"
- [ ] **Pagination**: Navigate pages if 12+ announcements exist
- [ ] **Images**: Display correctly at different sizes
- [ ] **Links**: "Read More" links work correctly
- [ ] **Empty State**: Create zero announcements, verify empty message
- [ ] **Accessibility**: Tab through page, use keyboard navigation
- [ ] **Performance**: Page loads in under 2 seconds
- [ ] **Icons**: All Bootstrap Icons display correctly

---

## Troubleshooting

### Images Not Showing
- Check `image_path` in database has correct format: `/public/img/announcements/filename.jpg`
- Verify file exists in `public/img/announcements/`
- Check file permissions (644 for files)

### Announcements Not Displaying
- Verify `user_id` is NULL for public announcements (not for specific users)
- Check `type` is 'system' or modify query if using different type
- Verify database connection in `config/database.php`

### Filters Not Working
- Ensure metadata contains category information
- Check database query logic for your specific schema
- Review announcement category detection (lines 43-67)

### Styling Issues
- Clear browser cache (Ctrl+Shift+Delete)
- Check if global CSS `style.css` is loaded before this page
- Verify no CSS conflicts with other pages

### Pagination Not Showing
- Need 13+ announcements to show pagination
- Check `$perPage = 12;` is set correctly
- Verify total count query is accurate

---

## Advanced Features (Optional)

### 1. Add Search Functionality

```php
// Add to line 25 (after $page variable)
$search = $_GET['search'] ?? '';

// Modify query to include:
if (!empty($search)) {
    $baseQuery .= " AND (title LIKE :search OR message LIKE :search)";
    $stmt->execute(['search' => "%{$search}%", ...]);
}
```

### 2. Add "Featured" Announcements

```php
// In admin announcement management, add:
$featured = $_POST['featured'] ?? 0;

// In query:
$baseQuery .= " ORDER BY featured DESC, created_at DESC";
```

### 3. Add Announcement Expiration

```sql
ALTER TABLE notifications ADD COLUMN expires_at TIMESTAMP NULL;
```

```php
// In query:
$baseQuery .= " AND (expires_at IS NULL OR expires_at > NOW())";
```

### 4. Add Email Subscriptions

```php
// Allow users to subscribe to categories:
// Create notifications_subscriptions table
// Track user preferences
// Send emails on new announcements
```

### 5. Add Social Share Buttons

```html
<!-- Add to announcement-card-footer -->
<div class="share-buttons" style="display: flex; gap: 0.5rem; margin-left: auto;">
    <a href="https://twitter.com/intent/tweet?text=<?= urlencode($item['title']); ?>&url=<?= urlencode($link); ?>" 
       class="btn btn-sm" title="Share on Twitter">
        <i class="bi bi-twitter"></i>
    </a>
    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($link); ?>" 
       class="btn btn-sm" title="Share on Facebook">
        <i class="bi bi-facebook"></i>
    </a>
</div>
```

---

## Performance Optimization

### Database Indexing

Add indexes to improve query performance:

```sql
-- Speed up announcements queries
ALTER TABLE notifications ADD INDEX idx_user_created (user_id, created_at);
ALTER TABLE notifications ADD INDEX idx_type_created (type, created_at);

-- Speed up metadata search (if using JSON)
ALTER TABLE notifications ADD FULLTEXT INDEX ft_title_message (title, message);
```

### Image Optimization

1. **Lazy Loading** (already implemented with `alt` tags)
2. **WebP Format**: Convert images to WebP for smaller file sizes
3. **Responsive Images**:

```html
<picture>
    <source srcset="image.webp" type="image/webp">
    <img src="image.jpg" alt="announcement">
</picture>
```

### Pagination Caching

For high-traffic sites, cache announcement counts:

```php
$totalAnnouncements = apcu_fetch('announcements_count_' . $filter);
if ($totalAnnouncements === false) {
    $totalAnnouncements = $pdo->query($countQuery)->fetch()['total'];
    apcu_store('announcements_count_' . $filter, $totalAnnouncements, 3600);
}
```

---

## Browser Support

| Browser | Version | Support |
|---------|---------|---------|
| Chrome  | 90+     | ✓       |
| Firefox | 88+     | ✓       |
| Safari  | 14+     | ✓       |
| Edge    | 90+     | ✓       |
| Mobile  | iOS 14+ | ✓       |

---

## Integration with Existing Pages

### Add to Home Page

Show featured announcements on home page:

```php
// In home.php, modify announcements section:
$announcementsStmt = $pdo->prepare(
    'SELECT id, title, message, type, link, image_path, created_at 
     FROM notifications 
     WHERE user_id IS NULL 
     ORDER BY created_at DESC 
     LIMIT 6'
);
```

### Link to Announcements Page

```php
<!-- From home.php announcements section -->
<a href="<?= base_path(); ?>/announcements" class="btn btn-primary">
    View All Announcements
</a>
```

---

## Admin Management Interface

The admin announcement management page already exists:
- **File**: `resources/views/pages/dashboard/announcements_manage.php`
- **Route**: `/dashboard/announcements-manage`
- **Features**: Create, Edit, Delete announcements

The public announcements page pulls from the same `notifications` table.

---

## SEO Considerations

### Meta Tags

Add to `announcements.php` head if needed:

```html
<meta name="description" content="View the latest announcements and updates from Barangay Culiat">
<meta name="keywords" content="announcements, news, updates, events, barangay">
<meta property="og:title" content="Announcements | Barangay Culiat">
<meta property="og:description" content="Stay informed with latest news from our barangay">
```

### Structured Data

Add JSON-LD for search engines:

```json
{
  "@context": "https://schema.org",
  "@type": "NewsArticle",
  "headline": "<?= $announcement['title']; ?>",
  "datePublished": "<?= $announcement['created_at']; ?>",
  "image": "<?= $announcement['image_path']; ?>"
}
```

---

## Support & Resources

- **Design Spec**: See [ANNOUNCEMENTS_PAGE_DESIGN.md](ANNOUNCEMENTS_PAGE_DESIGN.md)
- **Bootstrap Icons**: https://icons.getbootstrap.com
- **Poppins Font**: https://fonts.google.com/specimen/Poppins
- **CSS Grid Guide**: https://css-tricks.com/snippets/css/complete-guide-grid/

---

## Version History

- **v1.0** (Current): Initial release with responsive grid, filters, pagination
- **Planned**: Advanced search, email subscriptions, social sharing

---

## Questions?

Refer to related files:
- `config/database.php` - Database connection
- `config/app.php` - Application constants
- `resources/views/layouts/guest_layout.php` - Page template
- `public/css/style.css` - Global styling
- `resources/views/pages/public/home.php` - Similar card layout reference
