# MySQL Connection Troubleshooting Guide

## "MySQL server has gone away" Error

This error typically occurs when:
1. **Large file uploads** exceed MySQL's `max_allowed_packet` setting
2. **Connection timeouts** after long-running operations
3. **Stale connections** that have been idle too long

---

## Solutions Implemented

### 1. Automatic Connection Recovery
The system now automatically:
- Checks if the connection is alive before queries
- Reconnects automatically if the connection is lost
- Retries failed queries once after reconnection

### 2. File Size Limits
- Image uploads are limited to **5MB** to prevent packet size issues
- Only allowed formats: JPG, PNG, GIF, WEBP

### 3. Connection Timeout Settings
- Connection timeout set to 30 seconds
- Session timeout set to 600 seconds (10 minutes)
- Interactive timeout set to 600 seconds

---

## MySQL Configuration Recommendations

If you continue to experience issues, you may need to adjust MySQL settings in `my.ini` (XAMPP) or `my.cnf`:

### For XAMPP (Windows):
1. Open `C:\xampp\mysql\bin\my.ini`
2. Find the `[mysqld]` section
3. Add or modify these settings:

```ini
[mysqld]
max_allowed_packet = 16M
wait_timeout = 600
interactive_timeout = 600
```

4. Restart MySQL service in XAMPP Control Panel

### For Linux/Mac:
1. Edit `/etc/mysql/my.cnf` or `/etc/my.cnf`
2. Add the same settings under `[mysqld]`
3. Restart MySQL: `sudo service mysql restart`

---

## Testing the Fix

1. **Try adding a facility** with a small image (< 1MB)
2. **Try adding a facility** with a larger image (2-5MB)
3. **Wait a few minutes** and try another operation to test connection recovery

---

## Common Causes

### Large Images
- **Problem**: Images over 5MB can cause connection issues
- **Solution**: Compress images before uploading or increase `max_allowed_packet`

### Long Idle Time
- **Problem**: Connection times out after being idle
- **Solution**: System now automatically reconnects when needed

### Multiple Simultaneous Operations
- **Problem**: Too many connections or large transactions
- **Solution**: System uses connection pooling and retry logic

---

## Error Messages

### "MySQL server has gone away" (Error 2006)
- **Cause**: Connection lost during operation
- **Fix**: System automatically retries with new connection

### "Packet too large"
- **Cause**: File exceeds `max_allowed_packet`
- **Fix**: Reduce file size or increase MySQL setting

### "Connection timeout"
- **Cause**: Operation took too long
- **Fix**: System automatically reconnects and retries

---

## Prevention Tips

1. **Compress images** before uploading (use tools like TinyPNG or ImageOptim)
2. **Keep images under 2MB** for best performance
3. **Avoid uploading multiple large files** simultaneously
4. **Monitor MySQL logs** if issues persist

---

## Still Having Issues?

If problems continue:
1. Check MySQL error logs in XAMPP: `C:\xampp\mysql\data\*.err`
2. Verify MySQL service is running properly
3. Check available disk space
4. Review PHP error logs: `C:\xampp\php\logs\php_error_log`

---

## Technical Details

### Connection Recovery Flow:
1. Query fails with "server has gone away"
2. System detects the error
3. Creates new PDO connection
4. Retries the query once
5. If retry fails, shows user-friendly error message

### File Upload Validation:
- Size check: 5MB maximum
- Format check: JPG, JPEG, PNG, GIF, WEBP only
- Security: Sanitized filenames, directory creation

---

## Database Configuration

The system uses these PDO settings:
- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` - Throws exceptions on errors
- `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC` - Returns associative arrays
- `PDO::ATTR_TIMEOUT => 30` - 30 second connection timeout
- `PDO::ATTR_PERSISTENT => false` - No persistent connections (prevents stale connections)

---

## Need Help?

If you continue experiencing issues:
1. Check the error message details
2. Review MySQL and PHP error logs
3. Verify MySQL service is running
4. Try restarting MySQL service
5. Check system resources (RAM, disk space)









