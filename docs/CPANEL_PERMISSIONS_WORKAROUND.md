# cPanel File Manager Permissions Workaround

## The Problem

In some cPanel/File Manager interfaces, when you change permissions, ALL selected items (files AND folders) get changed to the same permission. You cannot set files to 644 and folders to 755 separately.

## Solutions

### Option 1: Use SSH/Terminal (If Available)

If you have SSH access (even through CyberPanel Terminal):

```bash
# Navigate to your web directory
cd /home/cprf.infragovservices.com/public_html

# Set all directories to 755
find . -type d -exec chmod 755 {} \;

# Set all files to 644
find . -type f -exec chmod 644 {} \;

# Ensure .htaccess is readable
chmod 644 .htaccess
```

### Option 2: Contact Your Host (Indevfinite)

Since you can't use chmod from Windows and File Manager has limitations:

1. **Contact Indevfinite Support**
2. **Ask them to:**
   - Set all directories to 755
   - Set all files to 644
   - Verify permissions are correct

3. **Provide them:**
   - Your domain: `cprf.infragovservices.com`
   - Your request: "Please set file permissions: directories to 755, files to 644"

### Option 3: Use CyberPanel Terminal (If Available)

1. In CyberPanel, look for **Terminal** or **SSH Terminal** option
2. If available, use the commands from Option 1

### Option 4: Accept Current Permissions (If Site Works)

**IMPORTANT:** If your website is actually WORKING:
- PHP is executing ✅
- Pages are loading ✅
- No actual errors ✅

Then your permissions might ALREADY be correct! The File Manager might just have a display/interface issue.

**Test this:**
- Visit your website
- Try logging in
- Test different pages
- If everything works, permissions are fine

### Option 5: Set Permissions to 644 (Everything)

If you MUST use File Manager and it changes everything:

1. Select all files AND folders
2. Set to `644`
3. **This is NOT ideal**, but if the site works, it's acceptable for files
4. **Warning:** Directories at 644 might cause issues

**Better:** Contact support to set permissions correctly.

## Checking Current Permissions

If you can access files, create a PHP file to check permissions:

```php
<?php
// check_permissions.php - DELETE AFTER USE
$path = __DIR__;
$items = scandir($path);
echo "<h2>Permissions Check</h2>";
echo "<table border='1'><tr><th>Item</th><th>Type</th><th>Permissions</th></tr>";
foreach ($items as $item) {
    if ($item == '.' || $item == '..') continue;
    $fullPath = $path . '/' . $item;
    $perms = fileperms($fullPath);
    $type = is_dir($fullPath) ? 'Directory' : 'File';
    $permStr = substr(sprintf('%o', $perms), -4);
    echo "<tr><td>$item</td><td>$type</td><td>$permStr</td></tr>";
}
echo "</table>";
?>
```

Visit: `http://yourdomain.com/check_permissions.php`

**Remember to delete this file after checking!**

## What Permissions Should Be

- **Directories:** `755` (drwxr-xr-x)
- **Files:** `644` (rw-r--r--)

## If 500 Error Persists

If you're STILL getting a 500 error AFTER fixing PHP configuration:

1. **Check the NEWEST error logs** (not old OpenLiteSpeed errors)
2. **Tell me:**
   - Which page/URL gives the error?
   - What does the error log say?
   - What happens when you visit the page?

The 500 error might be:
- PHP syntax error
- Database connection issue
- Missing file
- Something else (not permissions)

## Summary

**If File Manager won't let you set files and folders separately:**
1. Try SSH/Terminal (if available)
2. Contact your host to set permissions
3. If site works, permissions might be fine already
4. Focus on the ACTUAL 500 error (check logs for new errors)

**The 500 error is MORE IMPORTANT than permissions right now** - find out what the NEW error log says!
