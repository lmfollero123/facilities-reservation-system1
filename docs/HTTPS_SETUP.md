# HTTPS Setup Guide
## For Local Development and Production

---

## Local Development (lgu.test, localhost)

### Current Configuration

**HTTPS is DISABLED for local development by default:**
- ✅ `localhost` - No HTTPS redirect
- ✅ `127.0.0.1` - No HTTPS redirect  
- ✅ `lgu.test` - No HTTPS redirect (added to exclude list)

**You can access your site normally:**
- `http://lgu.test` ✅ Works
- `http://localhost` ✅ Works
- `http://127.0.0.1` ✅ Works

### If You Want HTTPS on Local Development

**Option 1: Disable HTTPS Enforcement (Recommended for Local)**
```php
// In config/security.php, change:
define('FORCE_HTTPS', false);
```

**Option 2: Set Up Local SSL (Advanced)**
1. Install **mkcert**: https://github.com/FiloSottile/mkcert
2. Create local CA: `mkcert -install`
3. Generate certificate: `mkcert lgu.test localhost 127.0.0.1`
4. Configure XAMPP/Apache to use the certificate
5. Then HTTPS will work on `https://lgu.test`

**For now, just use HTTP on local development** - it's fine for testing!

---

## Production Server

### Before Enabling HTTPS

1. **Get SSL Certificate**
   - Let's Encrypt (free): https://letsencrypt.org/
   - Commercial SSL certificate
   - Cloud provider SSL (Cloudflare, AWS, etc.)

2. **Configure Apache/Nginx**
   - Install SSL certificate
   - Configure virtual host for HTTPS
   - Test: `https://yourdomain.com` should work

3. **Then Enable HTTPS Redirect**
   - The code is already set up!
   - Just make sure `FORCE_HTTPS` is `true` in `config/security.php`
   - Production domain (e.g., `cprf.infragovservices.com`) will automatically redirect HTTP → HTTPS

---

## Troubleshooting

### Issue: "Not Secure" Warning on lgu.test

**Cause:** HTTPS redirect is enabled but no SSL certificate is configured.

**Solution:** 
- **Option A (Recommended)**: Disable HTTPS for local development
  ```php
  // In config/security.php
  define('FORCE_HTTPS', false);
  ```
  
- **Option B**: Add more domains to exclude list
  ```php
  // In config/security.php
  define('HTTPS_EXCLUDE_HOSTS', 'localhost,127.0.0.1,lgu.test,your-local-domain.test');
  ```

### Issue: Redirect Loop

**Cause:** HTTPS redirect is enabled but SSL isn't working properly.

**Solution:**
1. Disable HTTPS redirect temporarily: `define('FORCE_HTTPS', false);`
2. Fix SSL certificate configuration
3. Re-enable HTTPS redirect

### Issue: Redirects to XAMPP Dashboard

**Cause:** Apache virtual host configuration issue.

**Solution:**
1. Check your `httpd-vhosts.conf` file
2. Make sure `lgu.test` points to the correct document root
3. Ensure `ServerName lgu.test` is set correctly

---

## Configuration Files

### `config/security.php`
```php
// Enable/disable HTTPS redirect
define('FORCE_HTTPS', true); // Set to false to disable

// Domains to exclude from HTTPS redirect
define('HTTPS_EXCLUDE_HOSTS', 'localhost,127.0.0.1,lgu.test');
```

### `.htaccess`
- Also has HTTPS redirect rules
- Excludes: `localhost`, `127.0.0.1`, `lgu.test`

---

## Quick Fix for Local Development

**To disable HTTPS redirect completely for local development:**

1. Open `config/security.php`
2. Find: `define('FORCE_HTTPS', true);`
3. Change to: `define('FORCE_HTTPS', false);`
4. Save and refresh your browser

**Or add your local domain to exclude list:**
```php
define('HTTPS_EXCLUDE_HOSTS', 'localhost,127.0.0.1,lgu.test,your-other-local-domain.test');
```

---

## Production Checklist

Before going live:
- [ ] SSL certificate installed and working
- [ ] Test `https://yourdomain.com` works
- [ ] `FORCE_HTTPS` is `true` in `config/security.php`
- [ ] Production domain is NOT in `HTTPS_EXCLUDE_HOSTS`
- [ ] HTTP redirects to HTTPS automatically
- [ ] All pages load correctly over HTTPS

---

**Last Updated**: January 2026
