# OpenLiteSpeed PHP Configuration Fix

## Error Message
```
MIME type [application/x-httpd-php] for suffix '.php' does not allow serving as static file, access denied!
```

## Problem
OpenLiteSpeed (used by CyberPanel) is configured to serve PHP files as static files instead of executing them. This is a server configuration issue, not a file permissions issue.

## Solutions

### Solution 1: Check PHP Processor in CyberPanel (Recommended)

1. **Log into CyberPanel**
2. Go to **Websites** → **List Websites**
3. Find your domain: `cprf.infragovservices.com`
4. Click **Manage** (or the domain name)
5. Look for **PHP** section or **PHP Processor**
6. Ensure PHP is enabled and configured:
   - PHP should be **Enabled**
   - PHP Version should be selected (e.g., PHP 8.1, PHP 8.0)
   - **DO NOT select "all versions"** - select a specific version

### Solution 2: Re-create Virtual Host (If Solution 1 doesn't work)

1. In CyberPanel, go to **Websites** → **Create Website**
2. Note: You may need to back up your files first
3. Or contact support to re-configure the virtual host

### Solution 3: Check OpenLiteSpeed Configuration

If you have SSH access:

1. **Check PHP handler configuration:**
   ```bash
   # Check if PHP is configured
   /usr/local/lsws/bin/lsphp81 -v  # For PHP 8.1
   # or
   /usr/local/lsws/bin/lsphp80 -v  # For PHP 8.0
   ```

2. **Check virtual host configuration:**
   ```bash
   # Navigate to OpenLiteSpeed config
   cd /usr/local/lsws/conf/vhosts/
   # Look for your domain configuration
   ```

**Note:** Only do this if you have SSH access and know what you're doing.

### Solution 4: Contact Your Host (Indevfinite/CyberPanel Support)

Since this is a server configuration issue, contact your hosting provider:

1. **Tell them:**
   - Your domain: `cprf.infragovservices.com`
   - The error: "MIME type [application/x-httpd-php] for suffix '.php' does not allow serving as static file"
   - You need PHP files to be executed, not served as static files

2. **They need to:**
   - Enable PHP processing for your domain
   - Configure OpenLiteSpeed to execute PHP files
   - Ensure PHP handler is properly configured

### Solution 5: Verify PHP is Working

Create a test file `test_php.php` in your public_html:

```php
<?php
phpinfo();
?>
```

Then visit: `http://cprf.infragovservices.com/test_php.php`

- **If it shows PHP info:** PHP is working, the issue is elsewhere
- **If it shows/downloads the file:** PHP is not executing (this confirms the problem)
- **If it shows 500 error:** Different issue (check error logs)

**Remember to delete this file after testing!**

## Why This Happens

OpenLiteSpeed needs to be explicitly configured to execute PHP files. When you:
- Create a new website
- Deploy via Git
- Change PHP versions
- Restore from backup

The PHP handler might not be properly configured, causing PHP files to be served as static files.

## File Permissions Note

Even though this is NOT a permissions issue, ensure your files have correct permissions:
- Directories: `755`
- Files: `644`

But fixing permissions won't solve the OpenLiteSpeed PHP execution issue.

## Quick Checklist

- [ ] Checked PHP processor in CyberPanel
- [ ] Selected a specific PHP version (not "all versions")
- [ ] Tested with `phpinfo()` file
- [ ] Contacted hosting support if issue persists
- [ ] Verified file permissions are correct (755/644)

## Most Likely Fix

**In CyberPanel:**
1. Websites → Your Domain → Manage
2. PHP section → Enable PHP
3. Select a specific PHP version (PHP 8.1 or 8.0 recommended)
4. Save changes
5. Test again

This is the most common solution for this error.
