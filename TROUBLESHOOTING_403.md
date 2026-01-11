# 403 Forbidden Error - Troubleshooting Guide

## Critical Checks for CyberPanel

### 1. **Check Coraza WAF (Most Common Issue!)**
CyberPanel uses Coraza WAF which can block requests:
- Go to **CyberPanel Dashboard → Advanced → WAF**
- **Temporarily disable WAF** for your domain
- Test your website again
- If it works → WAF is blocking, you need to whitelist your requests or adjust rules

### 2. **Verify Document Root Path**
In CyberPanel Website settings:
- Check what the **Document Root** path is set to
- It should point to where `index.php` is located
- Common paths:
  - `/home/username/cprf.infragovservices.com/public_html/`
  - `/home/username/cprf.infragovservices.com/`
- **Make sure it matches where GitManager deployed your files**

### 3. **Check GitManager Deployment Location**
- GitManager usually clones to: `/home/username/cprf.infragovservices.com/`
- But Document Root might be set to `/home/username/cprf.infragovservices.com/public_html/`
- **If files are in root but Document Root points to public_html → 403 error!**

### 4. **Check OpenLiteSpeed Virtual Host Settings**
- Go to **CyberPanel → Websites → Your Domain → Settings**
- Verify:
  - Website Status: **Active**
  - PHP Version: **Selected**
  - Document Root: **Correct path** (where index.php is)

### 5. **Check File Ownership**
Via SSH:
```bash
# Find the correct user (usually your domain name or username)
ls -la /home/

# Set ownership (replace 'username' with actual user)
chown -R username:username /home/username/cprf.infragovservices.com/
```

### 6. **Check Apache Error Logs**
In CyberPanel:
- Go to **Logs → Error Logs**
- Look for your domain
- Check for specific error messages about why access is denied

## Quick Test Checklist

1. ✅ Can you see `index.php` in CyberPanel File Manager?
2. ✅ What's the full path shown in File Manager?
3. ✅ Does the Document Root in Website Settings match that path?
4. ✅ Is WAF enabled? (Try disabling it temporarily)
5. ✅ Is the website status "Active" in CyberPanel?
