# Quick Fix: lgu.test Routing Issues

## Problem
- `lgu.test` redirects to XAMPP dashboard
- `lgu.test/login` shows "Not Found"
- Routes don't work

## Solution: Configure XAMPP Virtual Host

### Quick Steps:

1. **Edit hosts file** (as Administrator)
   - File: `C:\Windows\System32\drivers\etc\hosts`
   - Add: `127.0.0.1    lgu.test`

2. **Edit `httpd-vhosts.conf`**
   - File: `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
   - Add:
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
   **Replace the path with your actual project path!**

3. **Enable virtual hosts in `httpd.conf`**
   - File: `C:\xampp\apache\conf\httpd.conf`
   - Find: `#Include conf/extra/httpd-vhosts.conf`
   - Uncomment: `Include conf/extra/httpd-vhosts.conf`

4. **Enable mod_rewrite in `httpd.conf`**
   - Find: `#LoadModule rewrite_module modules/mod_rewrite.so`
   - Uncomment: `LoadModule rewrite_module modules/mod_rewrite.so`

5. **Restart Apache** in XAMPP Control Panel

6. **Test:** `http://lgu.test` should work!

---

**For detailed instructions, see: `docs/XAMPP_VIRTUAL_HOST_SETUP.md`**
