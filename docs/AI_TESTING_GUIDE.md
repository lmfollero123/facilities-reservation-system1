# AI Features Testing Guide

## Overview
This guide explains how to test the AI Conflict Detection and Facility Recommendation features in the Facilities Reservation System.

---

## Prerequisites

1. **Database Setup**: Ensure you have imported `database/schema.sql`
2. **Test Data**: You'll need some facilities and reservations in the database
3. **User Account**: Logged in as a Resident user

---

## Part 1: Testing Conflict Detection

### Setup Test Data

1. **Create Facilities** (via Facility Management):
   - Go to Facility Management (Admin/Staff)
   - Add at least 2-3 facilities (e.g., "Community Hall", "Sports Complex", "Amphitheater")

2. **Create Test Reservations** (to generate conflicts):
   - As Admin/Staff, go to Reservation Approvals
   - Or manually insert via phpMyAdmin:
   ```sql
   -- Get a facility ID and user ID first
   INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, status)
   VALUES 
   (1, 1, '2025-01-20', 'Morning (8AM - 12PM)', 'Test Event', 'approved'),
   (1, 1, '2025-01-20', 'Afternoon (1PM - 5PM)', 'Another Event', 'pending');
   ```

### Test Scenarios

#### Scenario 1: Exact Conflict Detection
1. **Navigate**: Go to "Book a Facility" page (as Resident)
2. **Select**:
   - Facility: Same facility as an existing approved reservation
   - Date: Same date as existing reservation
   - Time Slot: Same time slot as existing reservation
3. **Expected Result**:
   - ⚠️ Red warning box appears immediately
   - Message: "Conflict Detected: This time slot is already booked..."
   - Alternative time slots shown below
   - Form submission should be blocked (if conflict exists)

#### Scenario 2: High Risk Score (No Exact Conflict)
1. **Select**:
   - Facility: One with many historical bookings
   - Date: A future date
   - Time Slot: A popular time slot (e.g., "Morning" on a weekday)
2. **Expected Result**:
   - ⚠️ Yellow warning box appears
   - Message: "High demand period detected (Risk Score: X%)..."
   - Suggests booking in advance
   - Form can still be submitted (warning only)

#### Scenario 3: No Conflict
1. **Select**:
   - Facility: Any facility
   - Date: A future date with no bookings
   - Time Slot: Any time slot
2. **Expected Result**:
   - No warning appears
   - Form can be submitted normally

#### Scenario 4: Real-Time Checking
1. **Start**: Select a facility and date with no conflicts
2. **Change Time Slot**: Switch to a conflicting time slot
3. **Expected Result**:
   - Warning appears immediately (without page reload)
   - Change back to non-conflicting slot → warning disappears

### Verification Checklist
- [ ] Conflict warning appears for exact conflicts
- [ ] Alternative slots are suggested
- [ ] High risk warnings appear for popular slots
- [ ] Real-time checking works (no page reload needed)
- [ ] Warning disappears when conflict is resolved
- [ ] Form blocks submission on exact conflicts (server-side)

---

## Part 2: Testing Facility Recommendations

### Setup Test Data

1. **Create Facilities with Different Features**:
   ```sql
   -- Large capacity facility
   INSERT INTO facilities (name, description, capacity, amenities, status)
   VALUES ('Convention Hall', 'Large multi-purpose hall', '500 persons', 'Sound system, projector, air-conditioning, stage', 'available');
   
   -- Medium capacity facility
   INSERT INTO facilities (name, description, capacity, amenities, status)
   VALUES ('Community Center', 'Medium-sized community space', '200 persons', 'Sound system, chairs, tables', 'available');
   
   -- Small capacity facility
   INSERT INTO facilities (name, description, capacity, amenities, status)
   VALUES ('Meeting Room', 'Small meeting space', '50 persons', 'Projector, whiteboard', 'available');
   ```

2. **Create Some Historical Bookings** (for popularity scoring):
   ```sql
   -- Add some approved reservations to make facilities "popular"
   INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, status)
   VALUES 
   (1, 1, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'Morning (8AM - 12PM)', 'Event', 'approved'),
   (1, 1, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Afternoon (1PM - 5PM)', 'Event', 'approved'),
   (1, 2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Morning (8AM - 12PM)', 'Event', 'approved');
   ```

### Test Scenarios

#### Scenario 1: Purpose-Based Recommendations
1. **Navigate**: Go to "Book a Facility" page
2. **Enter Purpose**: Type "Barangay Assembly" or "Community Meeting"
3. **Expected Result**:
   - 🤖 Green recommendation box appears
   - Shows 3 recommended facilities
   - Each shows match percentage
   - Reasons displayed (e.g., "Perfect capacity match", "Has required amenities")

#### Scenario 2: Capacity Matching
1. **Enter Purpose**: Include attendance info, e.g., "Event for 150 people"
2. **Expected Result**:
   - Facilities with capacity ≥ 150 are recommended
   - Match score higher for facilities closer to 150 capacity
   - Shows capacity match reason

#### Scenario 3: Amenities Matching
1. **Enter Purpose**: Mention specific needs, e.g., "Event requiring sound system and projector"
2. **Expected Result**:
   - Facilities with those amenities score higher
   - Shows "Has X of Y required amenities" reason

#### Scenario 4: Popularity Scoring
1. **Check Recommendations**: Facilities with more historical bookings should have slightly higher scores
2. **Expected Result**:
   - Popular facilities appear in recommendations
   - "Currently available" reason shown for all

### Manual Testing via URL
You can also test recommendations by adding purpose to URL:
```
http://lgu.test/resources/views/pages/dashboard/book_facility.php?purpose=Barangay+Assembly
```

---

## Part 3: Testing Auto-Decline Feature

### Setup Test Data

1. **Create Expired Pending Reservation**:
   ```sql
   -- Create a pending reservation for yesterday
   INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, status)
   VALUES (1, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Morning (8AM - 12PM)', 'Past Event', 'pending');
   ```

### Test Scenarios

#### Scenario 1: Auto-Decline on View
1. **Navigate**: Go to Reservation Approvals (Admin/Staff)
2. **Expected Result**:
   - Expired pending reservations automatically denied
   - Message appears: "X pending reservation(s) automatically denied..."
   - Reservation status changed to "denied"
   - Notification sent to requester

#### Scenario 2: Auto-Decline on Detail View
1. **Navigate**: Go to Reservation Detail page for expired pending reservation
2. **Expected Result**:
   - Reservation automatically denied when page loads
   - Status updated to "denied"
   - History entry created with auto-decline note

#### Scenario 3: Same-Day Expiration
1. **Create**: Pending reservation for today, Morning slot (if current time > 12 PM)
2. **Expected Result**:
   - Auto-declined if time slot has passed
   - Afternoon slot auto-declined if current time > 5 PM
   - Evening slot auto-declined if current time > 9 PM

---

## Part 4: Testing Past Date Validation

### Test Scenarios

#### Scenario 1: Past Date Blocking
1. **Navigate**: Go to "Book a Facility"
2. **Select Date**: Try to select yesterday's date
3. **Expected Result**:
   - Date picker prevents selection (HTML5 `min` attribute)
   - If somehow submitted, server shows error: "Cannot book facilities for past dates"

#### Scenario 2: Today's Date
1. **Select Date**: Today's date
2. **Expected Result**:
   - Allowed (future dates only means >= today)

---

## Quick Test Checklist

### Conflict Detection
- [ ] Create a reservation for Facility A, Date X, Morning slot
- [ ] Try to book same Facility A, Date X, Morning slot → Should show conflict
- [ ] Try to book same Facility A, Date X, Afternoon slot → Should show alternatives
- [ ] Try to book Facility B, Date X, Morning slot → No conflict

### Facility Recommendations
- [ ] Enter purpose "Assembly" → Should recommend facilities suitable for assemblies
- [ ] Enter purpose "Sports tournament" → Should recommend sports facilities
- [ ] Check that recommendations show match scores and reasons

### Auto-Decline
- [ ] Create pending reservation for past date
- [ ] View Reservation Approvals → Should auto-decline
- [ ] Check notification sent to user
- [ ] Check audit log entry

### Past Date Validation
- [ ] Try to select past date → Should be blocked
- [ ] Try to submit past date via form → Should show error

---

## Troubleshooting

### Conflict Detection Not Working
- **Check**: JavaScript console for errors
- **Verify**: `ai_conflict_check.php` endpoint is accessible
- **Check**: Database has reservations to conflict with
- **Verify**: Facility ID, date, and time slot are being sent correctly

### Recommendations Not Showing
- **Check**: Purpose parameter is being passed
- **Verify**: Facilities exist in database with status = 'available'
- **Check**: PHP error logs for any issues in `ai_helpers.php`

### Auto-Decline Not Working
- **Verify**: Reservations have status = 'pending'
- **Check**: Reservation date is actually in the past
- **Verify**: Time slot logic matches current time correctly
- **Check**: Database connection and query execution

---

## Expected Behavior Summary

### Conflict Detection
- ✅ **Real-time**: Checks as you type/select (no page reload)
- ✅ **Visual Feedback**: Color-coded warnings (red for conflict, yellow for risk)
- ✅ **Alternatives**: Shows available alternative time slots
- ✅ **Server Validation**: Double-checks before saving to database

### Facility Recommendations
- ✅ **Smart Matching**: Considers capacity, amenities, purpose
- ✅ **Scoring**: Shows match percentage (0-100%)
- ✅ **Reasons**: Explains why each facility is recommended
- ✅ **Contextual**: Only shows when purpose is provided

### Auto-Decline
- ✅ **Automatic**: Runs when viewing pending reservations
- ✅ **Notification**: User is notified of auto-decline
- ✅ **Audit Trail**: Action is logged
- ✅ **History**: Status change recorded in reservation_history

---

## Testing with Sample Data

### Quick Setup Script (phpMyAdmin)
```sql
-- Create test facilities
INSERT INTO facilities (name, description, capacity, amenities, status) VALUES
('Large Convention Hall', 'Big hall for large events', '500 persons', 'Sound system, projector, stage, air-conditioning', 'available'),
('Community Center', 'Medium community space', '200 persons', 'Sound system, chairs, tables', 'available'),
('Small Meeting Room', 'Intimate meeting space', '50 persons', 'Projector, whiteboard', 'available');

-- Create test reservations (conflicts)
INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, status) VALUES
(1, 1, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Morning (8AM - 12PM)', 'Test Event 1', 'approved'),
(1, 1, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Afternoon (1PM - 5PM)', 'Test Event 2', 'pending'),
(1, 2, DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Morning (8AM - 12PM)', 'Test Event 3', 'approved');

-- Create expired pending reservation
INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, status) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Morning (8AM - 12PM)', 'Expired Event', 'pending');
```

---

## Next Steps After Testing

1. **Verify Results**: Check that all features work as expected
2. **Test Edge Cases**: Try unusual inputs, empty data, etc.
3. **Performance**: Check response times for conflict detection API
4. **User Experience**: Ensure warnings are clear and helpful
5. **Document Issues**: Note any bugs or improvements needed

---

## Support

If you encounter issues:
1. Check browser console (F12) for JavaScript errors
2. Check PHP error logs
3. Verify database has test data
4. Ensure all config files are included correctly




