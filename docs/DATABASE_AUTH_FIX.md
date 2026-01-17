# Fixing MySQL Authentication Error (auth_gssapi_client)

## Problem
If you encounter this error:
```
SQLSTATE[HY000] [2054] The server requested authentication method unknown to the client [auth_gssapi_client]
```

This means MySQL is trying to use an authentication method that PHP PDO doesn't support.

## Solution 1: Change MySQL User Authentication Method (Recommended)

Run these commands in MySQL to change the user's authentication plugin:

```sql
-- Connect to MySQL as root
mysql -u root -p

-- Change authentication method for your user
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '';

-- Or if using password:
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'your_password';

-- Flush privileges
FLUSH PRIVILEGES;
```

## Solution 2: Update MySQL Configuration (Alternative)

Edit your `my.ini` (Windows) or `my.cnf` (Linux/Mac) file and add:

```ini
[mysqld]
default-authentication-plugin=mysql_native_password
```

Then restart MySQL service.

## Solution 3: Use Different MySQL User

Create a new MySQL user with native password authentication:

```sql
CREATE USER 'facilities_user'@'localhost' IDENTIFIED WITH mysql_native_password BY 'your_password';
GRANT ALL PRIVILEGES ON facilities_reservation.* TO 'facilities_user'@'localhost';
FLUSH PRIVILEGES;
```

Then update `config/database.php`:
```php
const DB_USER = 'facilities_user';
const DB_PASS = 'your_password';
```

## For XAMPP Users

XAMPP typically comes with MySQL that uses `mysql_native_password` by default. If you're seeing this error:

1. Make sure you're using XAMPP's MySQL (not a separately installed MySQL)
2. Try Solution 1 above to reset the root user
3. Restart Apache and MySQL services in XAMPP Control Panel

## Verification

After applying the fix, test the connection:
```php
<?php
require_once 'config/database.php';
try {
    $pdo = db();
    echo "Database connection successful!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
```
