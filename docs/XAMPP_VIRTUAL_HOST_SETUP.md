# XAMPP Virtual Host Setup for lgu.test
## Fix "Not Found" and XAMPP Dashboard Redirect Issues

---

## Problem

When accessing `lgu.test` or `lgu.test/login`, you get:
- ❌ Redirected to XAMPP dashboard
- ❌ "Not Found" error
- ❌ Routes don't work

**Cause:** Apache virtual host is not configured correctly or pointing to wrong directory.

---

## Solution: Configure XAMPP Virtual Host

### Step 1: Edit hosts file

1. **Open Notepad as Administrator**
   - Right-click Notepad → "Run as administrator"

2. **Open hosts file**
   - File → Open
   - Navigate to: `C:\Windows\System32\drivers\etc\`
   - Change file type to "All Files (*.*)"
   - Open `hosts` file

3. **Add this line** (if not already there):
   ```
   127.0.0.1    lgu.test
   ```

4. **Save the file**

---

### Step 2: Configure Apache Virtual Host

1. **Open XAMPP Control Panel**
   - Stop Apache if it's running

2. **Edit `httpd-vhosts.conf`**
   - Location: `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
   - Open in Notepad (or your editor)

3. **Add this virtual host configuration** (replace with your actual project path):

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

   **Important:** 
   - Replace `E:/Capstone_project/facilities_reservation_system` with your actual project path
   - Use forward slashes `/` or escaped backslashes `\\` in the path
   - Make sure the path points to where `index.php` is located

4. **Save the file**

---

### Step 3: Enable Virtual Hosts in Apache

1. **Edit `httpd.conf`**
   - Location: `C:\xampp\apache\conf\httpd.conf`
   - Open in Notepad

2. **Find this line** (around line 500-550):
   ```apache
   #Include conf/extra/httpd-vhosts.conf
   ```

3. **Uncomment it** (remove the `#`):
   ```apache
   Include conf/extra/httpd-vhosts.conf
   ```

4. **Save the file**

---

### Step 4: Verify DocumentRoot

**Make sure your virtual host DocumentRoot points to:**
- The folder containing `index.php`
- The folder containing `.htaccess`
- The folder containing `config/`, `resources/`, `public/`, etc.

**Example:**
```
E:/Capstone_project/facilities_reservation_system/  ← index.php is here
├── index.php
├── .htaccess
├── config/
├── resources/
├── public/
└── ...
```

**NOT:**
```
E:/Capstone_project/facilities_reservation_system/public/  ← Wrong! index.php is not here
```

---

### Step 5: Restart Apache

1. **In XAMPP Control Panel**
   - Click "Stop" for Apache
   - Wait a few seconds
   - Click "Start" for Apache
   - Check for errors in the log

2. **If Apache won't start:**
   - Check the error log: `C:\xampp\apache\logs\error.log`
   - Common issues:
     - Path syntax error (use forward slashes)
     - Port 80 already in use (change port or stop other web servers)
     - Syntax error in httpd-vhosts.conf

---

### Step 6: Test

1. **Open browser**
2. **Go to:** `http://lgu.test`
   - Should show your home page (not XAMPP dashboard)
3. **Go to:** `http://lgu.test/login`
   - Should show login page (not "Not Found")
4. **Go to:** `http://lgu.test/dashboard`
   - Should show dashboard (if logged in) or redirect to login

---

## Troubleshooting

### Still Redirects to XAMPP Dashboard

**Check:**
1. Virtual host DocumentRoot is correct
2. `httpd-vhosts.conf` is included in `httpd.conf`
3. Apache restarted after changes
4. No syntax errors in `httpd-vhosts.conf`

**Test virtual host:**
```apache
# Add this to httpd-vhosts.conf to test
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

### "Not Found" Error

**Check:**
1. `.htaccess` file exists in project root
2. `mod_rewrite` is enabled in Apache
3. `AllowOverride All` is set in virtual host

**Enable mod_rewrite:**
1. Open `C:\xampp\apache\conf\httpd.conf`
2. Find: `#LoadModule rewrite_module modules/mod_rewrite.so`
3. Uncomment: `LoadModule rewrite_module modules/mod_rewrite.so`
4. Restart Apache

### Port 80 Already in Use

**Option 1: Change XAMPP Port**
1. XAMPP Control Panel → Apache → Config → `httpd.conf`
2. Change `Listen 80` to `Listen 8080`
3. Update virtual host: `<VirtualHost *:8080>`
4. Access: `http://lgu.test:8080`

**Option 2: Stop Other Web Servers**
- Stop IIS (if running)
- Stop other Apache instances
- Stop Skype (uses port 80)

---

## Complete Example Configuration

**`C:\xampp\apache\conf\extra\httpd-vhosts.conf`:**
```apache
# Default XAMPP virtual host (comment out if you want)
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

**`C:\xampp\apache\conf\httpd.conf`:**
```apache
# Around line 500-550, make sure this is uncommented:
Include conf/extra/httpd-vhosts.conf

# Around line 150-200, make sure mod_rewrite is enabled:
LoadModule rewrite_module modules/mod_rewrite.so
```

---

## Quick Checklist

- [ ] `127.0.0.1 lgu.test` added to `C:\Windows\System32\drivers\etc\hosts`
- [ ] Virtual host configured in `httpd-vhosts.conf`
- [ ] DocumentRoot points to project root (where `index.php` is)
- [ ] `Include conf/extra/httpd-vhosts.conf` uncommented in `httpd.conf`
- [ ] `mod_rewrite` enabled in `httpd.conf`
- [ ] Apache restarted
- [ ] `http://lgu.test` works (shows home page)
- [ ] `http://lgu.test/login` works (shows login page)

---

**Last Updated**: January 2026
