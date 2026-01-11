# File Permissions Setup Guide

## Standard Permissions for PHP Web Application

After deploying via Git or uploading files, you need to set proper file permissions for security and functionality.

### Standard Permissions

- **Directories**: `755` (drwxr-xr-x) - Owner can read/write/execute, group and others can read/execute
- **Files**: `644` (rw-r--r--) - Owner can read/write, group and others can only read
- **Executable scripts** (if needed): `755` - Usually not required for PHP files as web server executes them

### Quick Setup Commands

**Important**: Run these commands from your web root directory (where your files are deployed).

#### Option 1: Set permissions recursively (Recommended for fresh deployment)

```bash
# Navigate to your web root directory
cd /home/yourdomain.com/public_html  # Adjust path as needed

# Set all directories to 755
find . -type d -exec chmod 755 {} \;

# Set all files to 644
find . -type f -exec chmod 644 {} \;

# Set .htaccess to 644 (important for Apache)
chmod 644 .htaccess

# If you have any executable scripts (like cron jobs), set them to 755
# find . -name "*.sh" -exec chmod 755 {} \;
```

#### Option 2: Set permissions for specific directories/files

```bash
# Navigate to your web root directory
cd /home/yourdomain.com/public_html

# Set directory permissions
chmod 755 .
chmod -R 755 public/
chmod -R 755 resources/
chmod -R 755 config/
chmod -R 755 database/  # If accessible

# Set file permissions
chmod 644 index.php
chmod 644 .htaccess
chmod -R 644 public/css/
chmod -R 644 public/js/
chmod -R 644 public/img/
chmod -R 644 resources/views/

# Set PHP files to 644 (they don't need to be executable)
find . -type f -name "*.php" -exec chmod 644 {} \;
```

#### Option 3: Using CyberPanel File Manager (GUI Method)

1. Log into CyberPanel
2. Go to **Files** → **File Manager**
3. Navigate to your domain's directory
4. Select directories/files
5. Right-click → **Change Permissions**
6. Set directories to `755` and files to `644`

### Specific Files/Directories

#### Critical Files
- `.htaccess` - Must be `644` (Apache needs to read it)
- `index.php` - Should be `644`
- `config/database.php` - Should be `644` (or `600` for extra security if possible)

#### Directories That Must Be Executable (755)
- Root directory (`/`)
- `public/`
- `public/css/`
- `public/js/`
- `public/img/`
- `resources/`
- `resources/views/`
- `resources/views/pages/`
- `resources/views/components/`
- `config/` (if accessed directly)

#### Writable Directories (if needed for uploads/logs)
If you have directories that need to be writable by the web server:

```bash
# Example: If you have an uploads directory
chmod 755 uploads/
# Or if the web server user needs write access:
chmod 775 uploads/
# And ensure proper ownership (usually www-data or apache user)
chown -R www-data:www-data uploads/
```

### Verification

After setting permissions, verify they're correct:

```bash
# Check directory permissions
ls -ld /path/to/your/directory

# Check file permissions
ls -l /path/to/your/file

# Check recursive permissions
ls -laR | head -50
```

### Common Issues

#### Issue: 403 Forbidden Error
- **Solution**: Ensure directories have `755` permissions
- Check `.htaccess` is readable (`644`)

#### Issue: Files Not Loading (CSS/JS)
- **Solution**: Ensure files have `644` permissions
- Check directory permissions are `755`

#### Issue: PHP Files Not Executing
- **Solution**: Usually a server configuration issue, not permissions
- PHP files should be `644`, not `755`
- Web server (Apache/Nginx) executes PHP files

### Security Notes

1. **Never use 777** (world-writable) - This is a security risk
2. **Use 644 for files** - Only owner needs write access
3. **Use 755 for directories** - Allows web server to traverse directories
4. **Protect config files** - Consider `600` for sensitive config files (if web server can still read them)

### After Git Deployment

When you deploy via Git, files might inherit permissions from your local system. Always run permission commands after deployment:

```bash
# Quick reset after Git pull/deploy
cd /home/yourdomain.com/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 644 .htaccess
```

### Automation (Optional)

Create a script to automate this:

```bash
#!/bin/bash
# save as: fix_permissions.sh

cd /home/yourdomain.com/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 644 .htaccess
echo "Permissions fixed!"
```

Then run: `bash fix_permissions.sh`
