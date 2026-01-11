# Performance Troubleshooting Guide

## Quick Diagnosis

If your website is slow after seeding reservations, run this diagnostic:

```bash
php scripts/check_database_indexes.php
```

## Common Causes & Solutions

### 1. Missing Database Indexes

**Symptom**: Slow queries, especially when filtering by status, date, user_id, or facility_id.

**Solution**: Ensure performance indexes are created:

```sql
-- Run this in phpMyAdmin or MySQL
SOURCE database/migration_add_performance_indexes.sql;
```

Or manually:

```sql
CREATE INDEX IF NOT EXISTS idx_reservations_status_date ON reservations(status, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_facility_date ON reservations(facility_id, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_user ON reservations(user_id);
```

### 2. Too Many Reservations Loaded at Once

**Symptom**: Dashboard or listing pages are very slow.

**Solution**: Check if queries have LIMIT clauses. Queries should limit results:

```php
// Good: Has LIMIT
SELECT * FROM reservations WHERE user_id = ? ORDER BY created_at DESC LIMIT 10

// Bad: No LIMIT
SELECT * FROM reservations WHERE user_id = ? ORDER BY created_at DESC
```

### 3. N+1 Query Problem

**Symptom**: Multiple queries for each reservation (loading facility names, user names, etc.).

**Solution**: Use JOINs instead of separate queries:

```php
// Good: Single query with JOIN
SELECT r.*, f.name as facility_name, u.name as user_name 
FROM reservations r
LEFT JOIN facilities f ON r.facility_id = f.id
LEFT JOIN users u ON r.user_id = u.id
WHERE r.user_id = ?
LIMIT 10

// Bad: N+1 queries
SELECT * FROM reservations WHERE user_id = ?;  // Then for each result:
SELECT name FROM facilities WHERE id = ?;      // N queries
```

### 4. Unoptimized Date Range Queries

**Symptom**: Slow queries when filtering by date ranges.

**Solution**: Ensure date indexes exist and use indexed columns:

```sql
-- Ensure this index exists
CREATE INDEX idx_reservations_status_date ON reservations(status, reservation_date);
```

### 5. Too Many Seeded Reservations

**Symptom**: If you seeded hundreds or thousands of reservations, queries will naturally be slower.

**Solution**: 
- Use LIMIT clauses in queries
- Add proper WHERE filters
- Consider archiving old reservations
- Use pagination for listings

## Quick Performance Fixes

### Run This SQL to Add Missing Indexes:

```sql
-- Add/verify critical indexes
CREATE INDEX IF NOT EXISTS idx_reservations_status_date ON reservations(status, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_facility_date ON reservations(facility_id, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_user ON reservations(user_id);
CREATE INDEX IF NOT EXISTS idx_reservations_user_status ON reservations(user_id, status);
CREATE INDEX IF NOT EXISTS idx_reservations_date_status ON reservations(reservation_date, status);
```

### Check Query Performance:

Run EXPLAIN on slow queries to see if indexes are being used:

```sql
EXPLAIN SELECT * FROM reservations WHERE user_id = 1 ORDER BY created_at DESC LIMIT 10;
```

Look for:
- `type: ref` or `type: range` (good - using index)
- `type: ALL` (bad - full table scan)
- `key: idx_reservations_user` (good - using index)

### Reduce Seeded Data (if needed):

If you seeded too many reservations for testing, you can delete some:

```sql
-- Delete test reservations (be careful!)
DELETE FROM reservations WHERE id > 100;  -- Adjust ID threshold as needed
```

Or use the cleanup script:

```bash
php scripts/cleanup_old_data.php
```

## Expected Performance

After proper indexing:
- Dashboard load: < 500ms
- Reservation listing (with LIMIT): < 200ms
- Single reservation view: < 100ms
- Booking form: < 300ms

If performance is still poor after indexing, check:
1. Server resources (CPU, RAM, disk I/O)
2. Database connection pooling
3. Query execution plans (EXPLAIN)
4. PHP execution time limits

## Monitoring

Check slow queries in MySQL:

```sql
-- Enable slow query log (if not already enabled)
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;  -- Log queries taking > 1 second
```

Then check the slow query log for problematic queries.
