# Issues to Fix - Summary Document

## Issue 1: Approval Redirect Problem
**Problem**: After approving a reservation, the page redirects to `http://lgu.test/index.php?id=66` which shows the landing page instead of staying on the reservations management page.

**Root Cause**: The form in `reservations_manage.php` doesn't have an explicit `action` attribute, causing it to submit to the current URL. However, the routing system might be stripping the clean URL.

**Solution**: Add explicit form action attribute:
```php
<form method="POST" action="<?= base_path(); ?>/dashboard/reservations-manage" style="display:flex; gap:0.5rem; flex:1; min-width:300px;">
```

**Files to Update**:
- `resources/views/pages/dashboard/reservations_manage.php` (lines 654, and similar forms)

---

## Issue 2: Email Templates Need Improvement
**Status**: ✅ COMPLETED
**Solution**: Created `config/email_templates.php` with beautiful HTML email templates including:
- OTP emails with styled code display
- Account approved emails with call-to-action buttons
- Account verified emails with benefits list
- Account locked emails with reason display
- Reservation approved/postponed emails with details tables

**Next Steps**: Update all `sendEmail()` calls to use the new template functions.

---

## Issue 3: Dark Mode Modal Issues
**Problem**: Various modals stay white in dark mode, making text unreadable.

**Modals to Fix**:
1. Record violation pop-up modal
2. Other modals (need to identify all)

**Solution**: Add dark mode CSS rules for all modal components:
```css
[data-theme="dark"] .modal-dialog,
[data-theme="dark"] .modal-content {
    background: var(--bg-secondary) !important;
    color: var(--text-primary) !important;
}

[data-theme="dark"] .modal-header {
    border-color: var(--border-color) !important;
}
```

**Files to Update**:
- `public/css/style.css` - Add dark mode modal styles
- Search for all modal implementations and ensure they use consistent classes

---

## Implementation Priority
1. ✅ Email templates created
2. 🔄 Fix approval redirect (HIGH PRIORITY)
3. 🔄 Update email calls to use new templates (MEDIUM PRIORITY)
4. 🔄 Fix dark mode modals (HIGH PRIORITY)
