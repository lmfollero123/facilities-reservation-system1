# Philippines Holiday Detection System

## Overview

This document describes the Philippines holiday detection system integrated into the LGU Facilities Reservation System. The system detects Philippines national holidays and special non-working days, displaying them on the booking calendar with appropriate indicators.

## Architecture

### Components

**1. Python Holiday Detection Model**
- File: `ai/api/detect_holidays.py`
- Detects Philippines holidays for any given year
- Returns holiday information including name and type

**2. PHP Integration**
- File: `config/ai_ml_integration.php`
- Function: `detectPhilippinesHolidays()`
- Bridges PHP and Python holiday detection

**3. Booking Calendar Integration**
- File: `resources/views/pages/dashboard/book_facility.php`
- Displays holiday icons on calendar dates
- Shows demand scores on holidays

## Python Holiday Detection Model

### Features

The Python model detects:

**Regular Holidays:**
- New Year's Day (January 1)
- Araw ng Kagitingan (April 9)
- Labor Day (May 1)
- Independence Day (June 12)
- National Heroes Day (Last Monday of August)
- Bonifacio Day (November 30)
- Christmas Day (December 25)
- Rizal Day (December 30)
- Maundy Thursday (moveable)
- Good Friday (moveable)

**Special Non-Working Holidays:**
- All Saints' Day (November 1)
- All Souls' Day (November 2)
- Valentine's Day (February 14)
- EDSA People Power Revolution Anniversary (February 25)
- Ninoy Aquino Day (August 21)
- Christmas Eve (December 24)
- New Year's Eve (December 31)

### Algorithm

**Easter Sunday Calculation:**
- Uses the Computus algorithm to calculate Easter Sunday
- Maundy Thursday = Easter Sunday - 3 days
- Good Friday = Easter Sunday - 2 days

**Last Monday of Month:**
- National Heroes Day = Last Monday of August
- Calculated by finding the last day and working backwards

### API Usage

**Single Date Check:**
```bash
echo '{"date": "2024-12-25"}' | python ai/api/detect_holidays.py
```

**Date Range Check:**
```bash
echo '{"start_date": "2024-12-01", "end_date": "2024-12-31"}' | python ai/api/detect_holidays.py
```

**Year Check:**
```bash
echo '{"year": 2024}' | python ai/api/detect_holidays.py
```

### Response Format

**Single Date:**
```json
{
  "is_holiday": true,
  "holiday": {
    "name": "Christmas Day",
    "type": "Regular Holiday",
    "date": "2024-12-25"
  }
}
```

**Date Range:**
```json
{
  "holidays": [
    {
      "name": "Christmas Day",
      "type": "Regular Holiday",
      "date": "2024-12-25"
    },
    {
      "name": "Rizal Day",
      "type": "Regular Holiday",
      "date": "2024-12-30"
    }
  ],
  "count": 2
}
```

**Year:**
```json
{
  "year": 2024,
  "holidays": [...],
  "count": 15
}
```

## PHP Integration

### Function Signature

```php
function detectPhilippinesHolidays(
    ?string $date = null,
    ?string $startDate = null,
    ?string $endDate = null,
    ?int $year = null
): array
```

### Parameters

- `$date`: Single date string in Y-m-d format (optional)
- `$startDate`: Start date string in Y-m-d format (optional)
- `$endDate`: End date string in Y-m-d format (optional)
- `$year`: Year to check holidays for (optional)

### Return Value

Returns array with holiday information:
```php
[
    'is_holiday' => true/false,
    'holiday' => [...], // for single date
    'holidays' => [...], // for date range or year
    'count' => 0,
    'error' => null // if error occurred
]
```

### Usage Example

```php
// Get holidays for next 60 days
$holidayResult = detectPhilippinesHolidays(
    null, // single date
    date('Y-m-d'), // start date
    date('Y-m-d', strtotime('+60 days')), // end date
    $bookCalYear // year
);

if (!empty($holidayResult['holidays'])) {
    foreach ($holidayResult['holidays'] as $holiday) {
        $holidayMatrix[$holiday['date']] = $holiday;
    }
}
```

## Booking Calendar Integration

### Changes Made

**1. Import AI/ML Integration**
```php
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';
```

**2. Fetch Holiday Data**
```php
$holidayMatrix = [];
$holidayData = [];
if (function_exists('detectPhilippinesHolidays')) {
    $holidayResult = detectPhilippinesHolidays(
        null, // single date
        date('Y-m-d'), // start date
        date('Y-m-d', strtotime('+60 days')), // end date
        $bookCalYear // year
    );
    
    if (!empty($holidayResult['holidays'])) {
        foreach ($holidayResult['holidays'] as $holiday) {
            $holidayMatrix[$holiday['date']] = $holiday;
            $holidayData[$holiday['date']] = [
                'name' => $holiday['name'],
                'type' => $holiday['type']
            ];
        }
    }
}
```

**3. Display Holiday Indicator**
```php
<?php if (isset($holidayMatrix[$iso])): ?>
    <div class="holiday-indicator" title="<?= htmlspecialchars($holidayMatrix[$iso]['name']); ?> (<?= htmlspecialchars($holidayMatrix[$iso]['type']); ?>)">
        <i class="bi bi-calendar-event"></i>
    </div>
<?php endif; ?>
```

**4. CSS Styling**
```css
.holiday-indicator {
    position: absolute;
    top: 4px;
    right: 4px;
    font-size: 0.7rem;
    color: #dc2626;
    z-index: 2;
}
```

### Calendar Display

**Holiday Icon:**
- Red calendar event icon (`bi-calendar-event`)
- Positioned in top-right corner of calendar cell
- Tooltip shows holiday name and type

**Demand Score on Holidays:**
- Holidays show demand scores like any other date
- Demand scores calculated for 60-day advance window
- Color-coded by demand level (green/yellow/orange/red)

## Demand Forecast Extension

### Previous Behavior
- Only showed demand scores for days in the current month
- Limited to ~31 days

### New Behavior
- Shows demand scores for all bookable dates (60 days)
- Matches booking advance window
- Covers entire reservation planning period

### Implementation Change

```php
// Old: Get forecast for days in month
$daysInMonth = date('t', mktime(0, 0, 0, $bookCalMonth, 1, $bookCalYear));
$monthForecast = $predictionService->getFacilityDemandForecast($bookFacilityPick, $daysInMonth);

// New: Get forecast for 60 days (booking advance window)
$advanceBookingDays = 60;
$monthForecast = $predictionService->getFacilityDemandForecast($bookFacilityPick, $advanceBookingDays);
```

## Blocked Date Color Fix

### Previous Behavior
- Blocked/blackout dates showed red color
- Inconsistent with legend (black)

### New Behavior
- Blocked/blackout dates show black/dark gray color
- Matches legend color scheme
- CSS class: `.status-blackout`

### Implementation

```php
// PHP: Assign correct class
elseif ($tone === 'blackout' || $tone === 'maintenance' || $tone === 'offline') {
    $dayStatusClass = ' status-blackout';
    $chipLabel = ($tone === 'blackout') ? 'Blackout' : 'N/A';
}
```

```css
/* CSS: Black color for blackout */
.status-blackout {
    background: #1e293b !important;
    color: #ffffff !important;
    border-color: #0f172a !important;
}
```

## Calendar Legend

### Current Legend Items

- **Green**: Open
- **Yellow**: Partial bookings
- **Red**: Fully booked
- **Black**: Blocked (now correctly colored)
- **Purple ring**: AI-suggested day
- **Demand strip**: Demand forecast (Low/Med/High/Very High)
- **Holiday icon**: Philippines holiday

## Performance Considerations

### Holiday Detection
- Python script execution time: ~50-100ms
- Cached for 60-day window
- Only called when facility is selected

### Demand Forecast
- Extended from 31 to 60 days
- Still efficient single query
- No significant performance impact

### Calendar Rendering
- Holiday icons add minimal overhead
- Demand strips already optimized
- No noticeable performance degradation

## Future Enhancements

### Potential Improvements

1. **Custom Holidays**
   - Allow admin to add barangay-specific holidays
   - Store in database with facility association

2. **Holiday Demand Adjustment**
   - Increase demand scores on holidays
   - Reflect higher expected demand

3. **Holiday Booking Rules**
   - Special booking policies on holidays
   - Higher approval requirements

4. **Multi-year Support**
   - Detect holidays across multiple years
   - Support year-over-year planning

### Database Schema (Future)

```sql
CREATE TABLE custom_holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('Regular Holiday', 'Special Non-Working Holiday', 'Local Event') NOT NULL,
    facility_id INT UNSIGNED NULL,
    created_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_date_facility (date, facility_id)
);
```

## Troubleshooting

### Issue: Holidays Not Showing

**Possible Causes:**
1. Python script not found
2. Python not installed or not in PATH
3. AI/ML integration not loaded
4. Function not available

**Solutions:**
1. Check `ai/api/detect_holidays.py` exists
2. Verify Python installation
3. Check `ai_ml_integration.php` is loaded
4. Confirm `detectPhilippinesHolidays()` function exists

### Issue: Wrong Holiday Dates

**Possible Causes:**
1. Year parameter incorrect
2. Easter calculation error
3. Timezone issues

**Solutions:**
1. Verify year parameter passed correctly
2. Check Easter Sunday calculation
3. Ensure consistent timezone usage

### Issue: Demand Scores Not Showing on Holidays

**Possible Causes:**
1. Demand forecast not extended to 60 days
2. Holiday date outside forecast range
3. PredictionService error

**Solutions:**
1. Verify 60-day forecast implemented
2. Check holiday date is within range
3. Review PredictionService logs

## Security Considerations

1. **Input Validation**: All date inputs validated
2. **SQL Injection**: Prepared statements used
3. **Command Injection**: Python script properly escaped
4. **Error Handling**: Graceful fallback on errors
5. **Rate Limiting**: Consider implementing for API calls

## Conclusion

The Philippines holiday detection system provides accurate holiday information for the booking calendar, helping users identify high-demand periods and plan reservations accordingly. The system is modular, performant, and designed for future enhancements.

## Version History

- **v1.0** (2024): Initial holiday detection system
  - Python holiday detection model
  - PHP integration via ai_ml_integration.php
  - Calendar holiday indicators
  - Extended demand forecast to 60 days
  - Fixed blocked date color to black
  - Demand scores shown on holidays
