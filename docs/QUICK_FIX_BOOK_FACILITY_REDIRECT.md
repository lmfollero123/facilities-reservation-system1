# Quick Fix: Book Facility Page Redirects to XAMPP Dashboard

## Problem
Accessing `lgu.test/dashboard/book-facility` redirects to XAMPP dashboard.

## Solution: Fix Apache Virtual Host

### Step 1: Edit Virtual Host File

**File:** `C:\xampp\apache\conf\extra\httpd-vhosts.conf`

**Make sure it looks like this:**

```apache
# Comment out or remove the default localhost virtual host
# <VirtualHost *:80>
#     ServerName localhost
#     DocumentRoot "C:/xampp/htdocs"
#     ...
# </VirtualHost>

# Your lgu.test virtual host
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

**Replace `E:/Capstone_project/facilities_reservation_system` with your actual project path!**

### Step 2: Restart Apache

1. XAMPP Control Panel → Stop Apache
2. Wait 5 seconds
3. Start Apache
4. Check for errors (red text in log)

### Step 3: Test

- `http://lgu.test` → Should work
- `http://lgu.test/dashboard/book-facility` → Should work now!

---

**For detailed instructions, see: `docs/FIX_XAMPP_DASHBOARD_REDIRECT.md`**
