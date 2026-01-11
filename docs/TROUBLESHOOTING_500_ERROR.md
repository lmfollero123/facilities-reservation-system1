# Troubleshooting 500 Internal Server Error

## Quick Diagnosis Steps

### 1. Check PHP Error Logs in CyberPanel

1. Log into CyberPanel
2. Go to **Logs** → **Error Logs** (or **PHP Error Logs**)
3. Look for recent errors that match the time you got the 500 error
4. The error message will tell you exactly what's wrong

**Common locations for error logs:**
- `/home/cprf.infragovservices.com/logs/error_log`
- `/home/cprf.infragovservices.com/logs/php_error.log`
- CyberPanel → Logs → PHP Error Logs

### 2. Enable Error Display (Temporary - for debugging only)

**⚠️ WARNING: Only use this temporarily for debugging. Disable it after fixing the issue.**

Create or edit `public_html/php_error_test.php`:

```php
<?php
// Temporary error display - DELETE THIS FILE AFTER DEBUGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/resources/views/pages/public/home.php';
?>
```

Then visit: `http://cprf.infragovservices.com/php_error_test.php`

**Remember to delete this file after debugging!**

### 3. File Permissions in cPanel File Manager

**The Problem:** When you select all files and folders together, they all get set to the same permission.

**The Solution:** Set files and folders **SEPARATELY**

#### Step-by-Step for cPanel/Indevfinite File Manager:

1. **Set Directory Permissions First:**
   - Open File Manager
   - Navigate to your domain's `public_html` folder
   - **Select ONLY directories (folders)** - Don't select files
   - Right-click → **Change Permissions** (or **Change Perms**)
   - Set to: `755`
   - Click **Change Permissions**

2. **Set File Permissions Second:**
   - In the same folder
   - **Select ONLY files** - Don't select directories
   - You may need to select files one by one, or use the pattern:
     - Select all `.php` files
     - Set to `644`
     - Select all `.css` files
     - Set to `644`
     - Select all `.js` files
     - Set to `644`
     - Select `.htaccess`
     - Set to `644`

3. **Manual Method (if File Manager doesn't allow selective selection):**
   - Set permissions for each type of file individually
   - Use file type filters if available in your File Manager

#### Recommended Permissions:

**Directories (Folders):**
- All directories: `755`

**Files:**
- All `.php` files: `644`
- All `.css` files: `644`
- All `.js` files: `644`
- All `.html` files: `644`
- `.htaccess`: `644`
- `index.php`: `644`
- Image files (`.jpg`, `.png`, `.gif`): `644`

### 4. Common 500 Error Causes

#### A. Permission Issues

**Symptoms:**
- 500 error immediately
- No specific error in logs (just "Permission denied")

**Fix:**
- Ensure directories are `755`
- Ensure files are `644`
- Ensure `.htaccess` is `644`

#### B. PHP Syntax Errors

**Symptoms:**
- Error log shows: `Parse error: syntax error...`

**Fix:**
- Check the file mentioned in the error
- Look for missing semicolons, brackets, quotes
- Check for recent changes you made

#### C. Missing Files/Directories

**Symptoms:**
- Error log shows: `Failed to open stream: No such file or directory`

**Fix:**
- Verify all files were uploaded/deployed correctly
- Check file paths are correct

#### D. Database Connection Issues

**Symptoms:**
- Error log shows: `SQLSTATE[HY000] [1045] Access denied...`

**Fix:**
- Check `config/database.php` credentials
- Verify database exists and user has permissions

#### E. .htaccess Issues

**Symptoms:**
- 500 error on all pages
- Works when `.htaccess` is renamed

**Fix:**
- Check `.htaccess` syntax
- Ensure Apache modules are enabled (mod_rewrite, mod_headers)
- Try temporarily renaming `.htaccess` to `.htaccess.backup` to test

### 5. Quick Test Procedure

1. **Check if it's a permission issue:**
   - Rename `.htaccess` to `.htaccess.backup`
   - Try accessing the site
   - If it works, the issue is with `.htaccess`
   - If not, continue to step 2

2. **Check if it's a PHP error:**
   - Create a simple `test.php` file:
     ```php
     <?php phpinfo(); ?>
     ```
   - Access `http://yourdomain.com/test.php`
   - If this works, PHP is running
   - If not, contact your host

3. **Check if `index.php` works:**
   - Access `http://yourdomain.com/index.php` directly
   - Check error logs if it doesn't work

### 6. Using SSH (If Available)

If you can access SSH (even via CyberPanel Terminal):

```bash
# Navigate to your web directory
cd /home/cprf.infragovservices.com/public_html

# Set directory permissions
find . -type d -exec chmod 755 {} \;

# Set file permissions
find . -type f -exec chmod 644 {} \;

# Ensure .htaccess is readable
chmod 644 .htaccess

# Check for PHP errors
tail -n 50 /home/cprf.infragovservices.com/logs/error_log
```

### 7. Contact Your Host (Indevfinite)

If none of the above works:

1. Check if they have SSH access available
2. Ask them to check error logs for you
3. Ask them to verify file permissions
4. Provide them with the error message from logs

### 8. Quick Fix Checklist

- [ ] Checked PHP error logs in CyberPanel
- [ ] Verified `.htaccess` exists and is `644`
- [ ] Verified `index.php` exists and is `644`
- [ ] Set all directories to `755` (selected separately)
- [ ] Set all files to `644` (selected separately)
- [ ] Checked database connection in `config/database.php`
- [ ] Verified all files were deployed correctly
- [ ] Tried accessing `index.php` directly

### Security Note

**After fixing the 500 error:**
- Remove any temporary error display files
- Ensure `display_errors` is off in production
- Verify file permissions are correct (not `777`)
