# Fix: Redirected to XAMPP Dashboard
## Solution for "book-facility" and other routes redirecting to XAMPP dashboard

---

## Problem

When accessing `lgu.test/dashboard/book-facility` or other routes, you get redirected to XAMPP dashboard instead of your application.

**Cause:** Apache virtual host DocumentRoot is pointing to the wrong directory (likely `C:/xampp/htdocs` instead of your project folder).

---

## Solution: Fix Virtual Host DocumentRoot

### Step 1: Check Your Project Path

Your project is located at:
```
E:/Capstone_project/facilities_reservation_system/
```

**Verify this is correct** - `index.php` should be in this folder.

---

### Step 2: Edit XAMPP Virtual Host Configuration

1. **Open XAMPP Control Panel**
   - Stop Apache

2. **Edit `httpd-vhosts.conf`**
   - Location: `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
   - Open in Notepad (as Administrator)

3. **Find your `lgu.test` virtual host** and make sure DocumentRoot is correct:

   ```apache
   <VirtualHost *:80>
       ServerName lgu.test
       DocumentRoot "E:/Capstone_project/facilities_reservation_system"
       <Directory "E:/Capstone_project/facilities_reservation_system">
           Options Indexes FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   **CRITICAL:** 
   - DocumentRoot must point to the folder containing `index.php`
   - Use forward slashes `/` in the path
   - Both `DocumentRoot` and `<Directory>` must have the SAME path

4. **Save the file**

---

### Step 3: Comment Out Default XAMPP Virtual Host (Important!)

In the same `httpd-vhosts.conf` file, **comment out or remove** the default localhost virtual host that points to `C:/xampp/htdocs`:

```apache
# Comment out this default virtual host:
# <VirtualHost *:80>
#     ServerName localhost
#     DocumentRoot "C:/xampp/htdocs"
#     <Directory "C:/xampp/htdocs">
#         Options Indexes FollowSymLinks
#         AllowOverride All
#         Require all granted
#     </Directory>
# </VirtualHost>
```

**Why?** If this default virtual host is active, it might catch requests before your `lgu.test` virtual host.

---

### Step 4: Verify mod_rewrite is Enabled

1. **Edit `httpd.conf`**
   - Location: `C:\xampp\apache\conf\httpd.conf`

2. **Find and uncomment:**
   ```apache
   LoadModule rewrite_module modules/mod_rewrite.so
   ```

3. **Make sure virtual hosts are included:**
   ```apache
   Include conf/extra/httpd-vhosts.conf
   ```

4. **Save the file**

---

### Step 5: Restart Apache

1. **In XAMPP Control Panel**
   - Click "Stop" for Apache
   - Wait 5 seconds
   - Click "Start" for Apache
   - Check the log for errors (red text)

2. **If Apache won't start:**
   - Check `C:\xampp\apache\logs\error.log`
   - Look for syntax errors in `httpd-vhosts.conf`
   - Common issues:
     - Path syntax error (use `/` not `\`)
     - Missing quotes around paths
     - Port 80 already in use

---

### Step 6: Test

1. **Clear browser cache** (Ctrl+Shift+Delete)

2. **Test these URLs:**
   - `http://lgu.test` → Should show home page
   - `http://lgu.test/login` → Should show login page
   - `http://lgu.test/dashboard/book-facility` → Should show book facility page
   - `http://lgu.test/dashboard` → Should show dashboard

3. **If still redirecting to XAMPP dashboard:**
   - Check `http://lgu.test/test_routing.php` (diagnostic script)
   - Verify DocumentRoot in the output

---

## Complete Example Configuration

**`C:\xampp\apache\conf\extra\httpd-vhosts.conf`:**

```apache
# Default localhost (commented out to prevent conflicts)
# <VirtualHost *:80>
#     ServerName localhost
#     DocumentRoot "C:/xampp/htdocs"
#     <Directory "C:/xampp/htdocs">
#         Options Indexes FollowSymLinks
#         AllowOverride All
#         Require all granted
#     </Directory>
# </VirtualHost>

# LGU Facilities Reservation System
<VirtualHost *:80>
    ServerName lgu.test
    DocumentRoot "E:/Capstone_project/facilities_reservation_system"
    <Directory "E:/Capstone_project/facilities_reservation_system">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog "logs/lgu.test-error.log"
    CustomLog "logs/lgu.test-access.log" common
</VirtualHost>
```

**Important Notes:**
- Replace `E:/Capstone_project/facilities_reservation_system` with your actual project path
- Use forward slashes `/` in paths
- Both `DocumentRoot` and `<Directory>` must match exactly
- Comment out the default localhost virtual host

---

## Quick Diagnostic

**Run this test:**
1. Go to: `http://lgu.test/test_routing.php`
2. Check the output:
   - `DOCUMENT_ROOT` should show your project path
   - `Current File Location` should show your project path
   - If they don't match → Virtual host is misconfigured

---

## Alternative: Use Direct File Access (Temporary Workaround)

If virtual host is still not working, you can temporarily access files directly:

- `http://lgu.test/index.php` (should route correctly)
- `http://lgu.test/resources/views/pages/dashboard/book_facility.php` (direct access)

But this is not ideal - fix the virtual host instead!

---

## Still Not Working?

**Check these:**

1. **Hosts file** - Make sure `127.0.0.1 lgu.test` is in `C:\Windows\System32\drivers\etc\hosts`
2. **Apache error log** - `C:\xampp\apache\logs\error.log` for specific errors
3. **Virtual host order** - Put `lgu.test` virtual host BEFORE the default localhost one
4. **Apache modules** - Make sure `mod_rewrite` and `mod_headers` are enabled

---

**Last Updated**: January 2026
