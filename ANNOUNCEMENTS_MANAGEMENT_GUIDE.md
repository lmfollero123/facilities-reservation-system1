# Announcement Management Page - Staff/Admin Guide

## Overview

The **Announcements Management** page allows staff and admins to create, view, and delete public announcements that appear on the homepage. These announcements are designed to communicate important information to residents in a professional, organized manner.

---

## Access & Permissions

**Who Can Access:**
- Admin users
- Staff users
- (Not available to Residents)

**How to Access:**
1. Login to the dashboard
2. Click **Announcements** in the left sidebar under "Operations"
3. Or navigate to: `/resources/views/pages/dashboard/announcements_manage.php`

---

## Page Layout

### Section 1: Create New Announcement Form
Use this form to publish a new announcement to the homepage.

### Section 2: Published Announcements Table
View all announcements that are currently live on the homepage.

---

## Creating an Announcement

### Required Fields

#### 1. **Title** (Max 200 characters)
- **What to enter**: A clear, official-sounding title
- **Examples**:
  - "Water Interruption Notice - Scheduled Maintenance"
  - "Community Clean-Up Drive This Saturday"
  - "Barangay Hall Temporary Closure"
  - "Updated Office Hours for 2026"

- **Tips**:
  - Be specific and clear
  - Lead with the most important information
  - Avoid vague titles like "Important Notice"

#### 2. **Message** (2-4 sentences recommended)
- **What to enter**: Detailed explanation of the announcement
- **Examples**:
  
  > Water service will be interrupted on Saturday, January 15, 2026, from 8 AM to 4 PM for pipeline maintenance. Please store water beforehand. For emergencies, contact the Water Department at 123-456-7890.

  > The Barangay Culiat Community Center invites all residents to join our Clean-Up Drive on January 20, 2026. We will meet at 7 AM near the plaza. Bring your own tools and gloves.

- **Tips**:
  - Answer the key questions: What? When? Where? Why? What to do?
  - Be direct and avoid unnecessary words
  - Write in an official, professional tone
  - Use present tense for current information

### Optional Fields

#### 3. **Category** (Default: General)
Select how the announcement should be categorized:
- **General** - Regular updates and information
- **Urgent / Emergency** - Time-sensitive emergencies (water, power, disasters)
- **Advisory / Notice** - Official notices, schedule changes, maintenance
- **Event / Activity** - Community events, programs, celebrations

**Note**: Category affects how the announcement displays on the homepage with color coding.

#### 4. **Optional Link**
Add a URL to a related page or document:
- Example: `https://barangayculiat.gov.ph/maintenance-schedule`
- Example: `/resources/views/pages/public/facilities.php`

When residents click "Read More", they will be taken to this link (if provided).

#### 5. **Feature Image** (Optional)
Upload an image to display with the announcement:
- **Supported formats**: JPEG, PNG, GIF, WebP
- **Max file size**: 5MB
- **Recommended size**: 800x400 pixels
- **Usage**: Images help draw attention and provide visual context

---

## How Announcements Appear on Homepage

### Visual Layout

```
┌─────────────────────────────────────────┐
│ [CATEGORY]         Jan 12, 2026         │
│                                         │
│ Announcement Title Goes Here            │
│                                         │
│ Brief message preview text that         │
│ shows 1-2 sentences from the message    │
│                                         │
│ [Read More →]                           │
└─────────────────────────────────────────┘
```

### Color Coding
- **Urgent / Emergency** → Red background, red label
- **Advisory / Notice** → Blue background, blue label
- **Event / Activity** → Green background, green label
- **General** → Gray background, gray label

### Homepage Display Rules
1. **Latest 5 announcements** are shown on homepage
2. **Newest first** - Newest announcements appear at the top
3. **Full archive** - Residents can click "View All Announcements" to see every announcement
4. **Mobile-optimized** - Announcements display perfectly on phones and tablets

---

## Managing Published Announcements

### Viewing Details
The **Published Announcements** table shows:
- **Title**: First 50 characters (hover for full title)
- **Preview**: First 100 characters of the message
- **Image**: Thumbnail if an image was uploaded
- **Published Date/Time**: When the announcement was created
- **Actions**: Delete button

### Deleting an Announcement
1. Find the announcement in the table
2. Click the **Delete** button (trash icon)
3. Confirm when prompted
4. Announcement is immediately removed from homepage

**Note**: Deleted announcements cannot be recovered. They will no longer appear on the homepage or in the archive.

---

## Best Practices for LGU Announcements

### Writing Tips
✅ **DO:**
- Be clear and direct
- Use official language
- Include important dates and times
- Provide contact information when relevant
- Proofread before publishing

❌ **DON'T:**
- Use ALL CAPS (except for headers)
- Use excessive punctuation!!!
- Include personal opinions
- Use slang or informal language
- Forget to add a date or time

### Content Examples

#### ✅ Good Announcement

**Title:** Water Supply Interruption - January 15, 2026

**Message:** Water service will be interrupted on January 15, 2026, from 8 AM to 4 PM for scheduled pipeline maintenance. Please prepare water supplies beforehand. For emergencies, contact the Water Department at (02) 1234-5678.

**Category:** Urgent / Emergency

---

#### ❌ Poor Announcement

**Title:** IMPORTANT!!!

**Message:** Hey everyone there's some stuff happening this weekend lol don't forget about it thanks

**Category:** General

---

### Update Frequency
- **High Priority**: Post immediately (emergencies, urgent closures)
- **Standard**: Post at least 48 hours before the event
- **Archives**: Keep announcements visible for 2-4 weeks, then delete

---

## Accessibility & Compliance

### For Government Compliance
✅ All announcements include:
- Clear title
- Date and time published
- Category/type information
- Professional language
- Contact information (when applicable)

### For Residents
✅ Announcements are designed for:
- **Seniors**: Large, readable fonts
- **PWDs (People with Disabilities)**: Accessible colors and structure
- **Mobile users**: Full-width, responsive design
- **Non-readers**: Optional images, clear category icons

---

## Troubleshooting

### Issue: Image doesn't upload

**Solutions:**
- Check file size (max 5MB)
- Verify file format (JPEG, PNG, GIF, or WebP)
- Check file permissions
- Try a different image file

### Issue: Announcement doesn't appear on homepage

**Possible causes:**
1. Announcement is published but 5+ newer announcements exist (check archive)
2. Page not refreshed - wait 30 seconds and refresh browser
3. Database connection issue - contact IT

### Issue: Special characters (é, ñ, etc.) display incorrectly

**Solution:**
- Use copy/paste from Word or another source to maintain proper encoding
- Or use HTML entities: `&eacute;`, `&ntilde;`, etc.

### Issue: Can't click the delete button

**Solution:**
- Ensure you have Admin or Staff role
- Try refreshing the page
- Check browser console for errors (F12)

---

## Audit Trail

All announcement actions are logged for compliance:
- **Creation**: Admin/Staff name + timestamp + title
- **Deletion**: Admin/Staff name + timestamp + title

View the Audit Trail in the dashboard to see all announcement activities.

---

## Integration with Other Features

### Dashboard Notifications
- Staff are notified when new announcements are created
- Admins receive email notifications (if configured)

### Homepage Display
- Shows latest 5 announcements
- Ordered by creation date (newest first)
- Supports category filtering (future feature)

### Dedicated Announcements Page
- Residents can view all announcements
- Filter by category
- Sort by date
- Search announcements

---

## Tips for Effective Announcements

### 1. Use Clear Dates and Times
❌ Bad: "Next week sometime"
✅ Good: "Monday, January 20, 2026, 9:00 AM - 5:00 PM"

### 2. Include Contact Information
```
For more information, contact:
Barangay Culiat Office
Tel: (02) 1234-5678
Email: culiat.barangay@gov.ph
Hours: Monday-Friday, 8 AM - 5 PM
```

### 3. Use Consistent Language
- Always use "January 20, 2026" format (not "Jan. 20" or "20/01/2026")
- Always write "Barangay Culiat" (not "Barangay Culiat" or "Brgy. Culiat")
- Always use official titles

### 4. Add Images When Possible
- Event announcements: Post flyer or event photo
- Infrastructure announcements: Show the affected area
- Advisory: Use appropriate warning or information icon image

### 5. Review Before Publishing
- Check spelling and grammar
- Verify all dates are correct
- Ensure phone numbers are accurate
- Test links (if adding URL)

---

## Keyboard Shortcuts

### Form Navigation
- **Tab**: Move to next field
- **Shift + Tab**: Move to previous field
- **Enter**: Submit form (when focused on submit button)

---

## Quick Checklist Before Publishing

Before clicking "Create Announcement":

- [ ] Title is clear and specific
- [ ] Message is 2-4 sentences
- [ ] Message includes all important details (what, when, where, why, who)
- [ ] Date/time format is correct
- [ ] Contact information included (if relevant)
- [ ] No spelling or grammar errors
- [ ] Category selected correctly
- [ ] Image added (if appropriate)
- [ ] Link added (if relevant)
- [ ] Ready to publish? Click "Create Announcement"

---

## Frequently Asked Questions

**Q: How long do announcements stay on the homepage?**
A: Latest 5 announcements. Older ones move to the full archive page.

**Q: Can I edit an announcement after publishing?**
A: Currently no. Delete and create a new one with corrections.

**Q: Do residents get email notifications?**
A: Currently no (email subscription feature coming soon).

**Q: Can announcements be scheduled for future dates?**
A: Currently no. Create when ready to publish.

**Q: What happens to deleted announcements?**
A: They're permanently removed from the database and not recoverable.

**Q: Can I see how many people viewed an announcement?**
A: Analytics coming in a future update.

**Q: Can announcements be translated to other languages?**
A: Currently supports English only. Multilingual support coming soon.

---

## Support & Feedback

For questions or issues with the announcement system:
1. Check the Troubleshooting section above
2. Contact your system administrator
3. Email: support@barangayculiat.gov.ph

---

## Related Pages

- [Public Announcements Page](../public/announcements.php) - Where residents view announcements
- [Homepage](../public/home.php) - Where announcements appear
- [Dashboard](./index.php) - Main dashboard
- [Audit Trail](./audit_trail.php) - View all system actions

---

**Last Updated**: January 2026
**Version**: 1.0
