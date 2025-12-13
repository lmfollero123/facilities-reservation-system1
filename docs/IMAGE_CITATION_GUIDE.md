# Image Citation Guide

## Overview
This guide explains how to properly attribute images used in the Facilities Reservation System, especially when sourcing images from Google Maps or other external sources.

---

## Why Citations Are Important

1. **Legal Compliance**: Proper attribution helps comply with copyright and licensing requirements
2. **Transparency**: Shows where images come from, building trust with users
3. **Professionalism**: Demonstrates attention to detail and respect for intellectual property
4. **Future-Proofing**: Makes it easier to track and update image sources

---

## How to Add Image Citations

### Step 1: Upload Your Image
1. Go to **Facility Management** (Admin/Staff only)
2. Click **"Add Facility"** or **"Edit Details"** on an existing facility
3. Upload your facility image using the **"Facility Image"** field

### Step 2: Add Citation
In the **"Image Source / Citation"** field, enter one of the following formats:

#### For Google Maps Images:
```
Google Maps
```
or
```
Google Maps - [Location Name]
```
Example: `Google Maps - Barangay Hall`

#### For Photographer Attribution:
```
Photo by [Photographer Name]
```
Example: `Photo by Juan Dela Cruz`

#### For URL Sources:
```
[Source Name] - [URL]
```
Example: `LGU Official Website - https://example.com/image.jpg`

#### For Multiple Sources:
```
Google Maps, Photo by [Name]
```

---

## Best Practices

### ✅ DO:
- **Always include citations** when uploading images from external sources
- **Be specific**: Include location, photographer name, or source URL when available
- **Keep it concise**: Citations should be clear but not overly long
- **Update citations** if you replace an image with a new one

### ❌ DON'T:
- Leave citation field empty for external images
- Use vague citations like "Internet" or "Found online"
- Forget to update citations when changing images
- Copy images without proper attribution

---

## Where Citations Appear

### Public Pages:
- **Facility Details Page**: Citation appears as a small overlay at the bottom of the facility hero image
- Format: `📷 Image: [Your Citation]`

### Admin Pages:
- **Facility Management**: Citation is stored and can be edited when updating facility details

---

## Google Maps API Integration (Future Enhancement)

While the current system uses manual citations, you can integrate Google Maps API in the future to:
- Automatically fetch facility images from Google Maps
- Auto-populate citations with proper Google Maps attribution
- Include Street View images with automatic attribution

### Current Manual Approach:
1. Download image from Google Maps
2. Upload to system
3. Manually enter citation: "Google Maps"

### Future API Approach (Optional):
1. Enter Google Maps Place ID
2. System fetches image automatically
3. Citation auto-populated: "Google Maps - [Place Name]"

---

## Example Citations

### Example 1: Google Maps
```
Google Maps - Barangay Covered Court
```

### Example 2: Photographer
```
Photo by Maria Santos, LGU Staff
```

### Example 3: Official Source
```
LGU Official Website - https://lgu.gov.ph/facilities/hall.jpg
```

### Example 4: Multiple Sources
```
Google Maps, Enhanced by LGU Communications Office
```

---

## Legal Considerations

### Google Maps Images:
- Google Maps images are subject to Google's Terms of Service
- For commercial use, review Google's licensing requirements
- Attribution helps but may not be sufficient for all use cases
- Consider using Google Maps API for proper licensing compliance

### General Image Usage:
- Always check image licenses before use
- Some images require explicit permission
- Public domain images may still benefit from attribution
- When in doubt, consult legal counsel

---

## Troubleshooting

### Citation Not Showing?
1. Check that citation field is filled in Facility Management
2. Verify facility has an uploaded image
3. Clear browser cache and refresh page

### Need to Update Citation?
1. Go to Facility Management
2. Click "Edit Details" on the facility
3. Update the "Image Source / Citation" field
4. Save changes

---

## Database Structure

The citation is stored in the `facilities` table:
- **Column**: `image_citation`
- **Type**: `VARCHAR(500)`
- **Nullable**: Yes (but should be filled for external images)

---

## Migration

To add the citation field to existing databases, run:
```sql
ALTER TABLE facilities
    ADD COLUMN image_citation VARCHAR(500) NULL 
    COMMENT 'Image source/citation (e.g., Google Maps, photographer name, etc.)' 
    AFTER image_path;
```

See `database/migration_add_image_citation.sql` for the complete migration script.

---

## Questions?

For questions about image citations or licensing:
- Contact your LGU IT Department
- Review Google Maps Terms of Service
- Consult with legal counsel for commercial use cases










