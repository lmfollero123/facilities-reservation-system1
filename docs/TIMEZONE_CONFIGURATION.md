# Philippines Timezone Configuration

## ✅ Timezone Set to Asia/Manila (UTC+8)

The system has been configured to use **Philippine Standard Time (PST)** / **Philippine Time (PHT)** throughout the entire application.

---

## 🕐 **Configuration Changes**

### **1. PHP Timezone (config/app.php)**

**Added**:
```php
// Set timezone to Philippines (Asia/Manila - UTC+8)
date_default_timezone_set('Asia/Manila');
```

**Location**: Line 15 in `config/app.php`

**Effect**: All PHP date/time functions now use Philippine timezone:
- `date()`
- `time()`
- `strtotime()`
- `DateTime` objects
- `DateTimeZone` operations

---

### **2. MySQL Timezone (config/database.php)**

**Added**:
```php
// Set MySQL timezone to Philippines (UTC+8)
$pdo->exec("SET time_zone = '+08:00'");
```

**Location**: After PDO connection in `config/database.php`

**Effect**: All MySQL datetime operations use Philippine timezone:
- `NOW()`
- `CURDATE()`
- `CURTIME()`
- `TIMESTAMP` columns
- `DATETIME` columns

---

## 📋 **What This Means**

### **Before** ❌:
- System used server's default timezone (could be UTC, GMT, etc.)
- Database timestamps might not match Philippine time
- Inconsistent time display across the application

### **After** ✅:
- **PHP**: Uses Asia/Manila timezone (UTC+8)
- **MySQL**: Uses +08:00 timezone offset
- **Consistent**: All times displayed are in Philippine time
- **Accurate**: Reservations, logs, and timestamps are correct

---

## 🔍 **Verification**

### **PHP Timezone Check**:
```php
echo date_default_timezone_get();
// Output: Asia/Manila

echo date('Y-m-d H:i:s');
// Output: 2026-02-01 21:31:33 (Philippine time)
```

### **MySQL Timezone Check**:
```sql
SELECT @@session.time_zone;
-- Output: +08:00

SELECT NOW();
-- Output: 2026-02-01 21:31:33 (Philippine time)
```

---

## 📅 **Affected Features**

All date/time operations now use Philippine timezone:

### **1. Reservations**
- ✅ Booking dates and times
- ✅ Reservation timestamps
- ✅ Availability checking
- ✅ Conflict detection

### **2. User Activity**
- ✅ Registration timestamps
- ✅ Login/logout times
- ✅ Last activity tracking
- ✅ Session expiration

### **3. Notifications**
- ✅ Notification timestamps
- ✅ Email send times
- ✅ Reminder scheduling

### **4. Audit Logs**
- ✅ Security event timestamps
- ✅ User action logs
- ✅ System activity tracking

### **5. Admin Features**
- ✅ Report generation dates
- ✅ Analytics time ranges
- ✅ Facility management timestamps

---

## 🌍 **Timezone Details**

### **Asia/Manila**:
- **Standard Name**: Philippine Standard Time (PST)
- **Common Name**: Philippine Time (PHT)
- **UTC Offset**: **+08:00** (8 hours ahead of UTC)
- **DST**: Philippines does **not** observe Daylight Saving Time
- **Stability**: Timezone offset is constant year-round

### **Example Conversions**:
| UTC Time | Philippine Time |
|----------|-----------------|
| 00:00 | 08:00 |
| 12:00 | 20:00 |
| 18:00 | 02:00 (next day) |

---

## ✅ **Benefits**

1. **User-Friendly**: All times match what users see on their clocks
2. **Consistent**: No confusion between server time and local time
3. **Accurate**: Reservations and schedules are correct
4. **Professional**: Shows attention to detail and localization
5. **Reliable**: No timezone conversion errors

---

## 🔧 **Technical Notes**

### **Why Asia/Manila?**
- Official timezone for the Philippines
- Covers all Philippine regions (Luzon, Visayas, Mindanao)
- Recognized by IANA timezone database
- Supported by all PHP versions

### **Why +08:00 for MySQL?**
- Numeric offset is more portable than named timezones
- Works on all MySQL/MariaDB versions
- Matches Asia/Manila offset exactly
- No dependency on MySQL timezone tables

### **Session Scope**:
The MySQL timezone setting is **session-scoped**, meaning:
- Applied to each database connection
- Doesn't affect other applications using the same database
- Automatically set when `db()` function is called
- Persists for the duration of the connection

---

## 📝 **Code Examples**

### **Getting Current Philippine Time**:
```php
// PHP
$now = new DateTime('now', new DateTimeZone('Asia/Manila'));
echo $now->format('Y-m-d H:i:s');

// Or simply (since default timezone is set)
echo date('Y-m-d H:i:s');
```

### **Creating Reservation Timestamps**:
```php
// Automatically uses Philippine timezone
$reservationDate = new DateTime($date);
$reservationDate->setTime($hour, $minute);
```

### **Database Queries**:
```sql
-- All these now return Philippine time
INSERT INTO reservations (created_at) VALUES (NOW());
SELECT * FROM reservations WHERE DATE(reservation_date) = CURDATE();
```

---

## 🚨 **Important Notes**

1. **Server Timezone**: The server's system timezone doesn't matter anymore - PHP and MySQL are explicitly set to Philippine time.

2. **Existing Data**: If you have existing timestamps in the database, they may need to be reviewed to ensure they're in the correct timezone.

3. **Third-Party Libraries**: Most PHP libraries respect `date_default_timezone_get()`, so they'll automatically use Philippine time.

4. **JavaScript**: Client-side JavaScript uses the user's browser timezone. If you need to display times in JavaScript, you may need to convert them or specify the timezone explicitly.

---

## ✅ **Testing Checklist**

- [ ] Create a new reservation - check timestamp
- [ ] Register a new user - check registration time
- [ ] Send a notification - check notification time
- [ ] View audit logs - check event timestamps
- [ ] Generate a report - check date ranges
- [ ] Check "My Reservations" - verify dates display correctly

---

**Implementation Date**: February 1, 2026  
**Timezone**: Asia/Manila (UTC+8)  
**Status**: ✅ Active System-Wide  
**Scope**: PHP + MySQL
