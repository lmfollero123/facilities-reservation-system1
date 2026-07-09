# AI-Powered Reservation Demand Prediction System

## Overview

This document describes the AI-powered predictive reservation demand system for the LGU Facilities Reservation System. The system forecasts reservation demand for facilities based on historical booking patterns, holidays, seasonal trends, and time slot preferences.

## Architecture

### Component Structure

```
services/PredictionService.php
├── Core prediction logic
├── Historical data analysis
├── Holiday/event calendar
├── Demand scoring algorithm
└── Alternative slot suggestions

resources/views/pages/dashboard/ai_scheduling.php
├── 14-day demand forecast UI
├── High demand alerts
├── Facility-specific predictions
└── Interactive booking interface

resources/views/pages/dashboard/book_facility.php
├── Real-time demand prediction display
├── Alternative slot suggestions
└── Integration with conflict checking

resources/views/pages/dashboard/ai_conflict_check.php
├── API endpoint for demand predictions
├── Integration with conflict detection
└── JSON response with prediction data
```

### Design Principles

1. **Separation of Concerns**: Business logic separated from presentation
2. **Extensibility**: Designed for ML model replacement without API changes
3. **Graceful Degradation**: Falls back to rule-based predictions with insufficient data
4. **Performance**: Optimized queries with proper indexing
5. **Explainability**: Clear factors contributing to each prediction

## Prediction Algorithm

### Data Sources

1. **Historical Reservations**
   - Approved reservations from the last 6 months
   - Aggregated by facility, day of week, and time slot
   - Includes booking frequency and patterns

2. **Holiday/Event Calendar**
   - Philippine national holidays
   - Local barangay events
   - Configurable for future additions

3. **Temporal Factors**
   - Day of week patterns
   - Time slot categories (peak vs off-peak)
   - Seasonal variations (month-based)

4. **Recent Trends**
   - Last 30 days vs previous 30-60 days
   - Increasing/decreasing demand patterns

### Scoring Components

The final demand score (0-100) is calculated from multiple weighted factors:

#### 1. Base Historical Demand (40% weight)
- Analyzes average bookings per slot
- Normalized to 0-40 scale
- Formula: `min(40, (avg_bookings / 5) * 40)`

#### 2. Holiday/Event Impact (+25 points)
- Significant boost for known holidays/events
- Applied to the exact date of the holiday
- Examples: Christmas, Independence Day, local fiestas

#### 3. Weekend Impact (+15 points)
- Moderate boost for Saturday and Sunday
- Reflects higher weekend demand patterns

#### 4. Time Slot Impact (+20 points for peak, +5 for off-peak)
- Peak hours: 16:00-22:00 (evening slots)
- Off-peak: 08:00-16:00 (daytime slots)
- Reflects typical usage patterns

#### 5. Seasonal Impact (+20 for December, +15 for May, +10 for January)
- December: Holiday season
- May: Summer/fiesta season
- January: Post-holiday events

#### 6. Recent Trend Adjustment (±15 points)
- Compares last 30 days to previous 30-60 days
- Increasing trend: positive adjustment
- Decreasing trend: negative adjustment
- Capped at ±15 points to prevent overcorrection

### Classification System

| Score Range | Classification | Color | Meaning |
|-------------|---------------|-------|---------|
| 0-25% | Low | Green | Low competition, best choice |
| 26-50% | Medium | Yellow | Moderate usage |
| 51-75% | High | Orange | High competition |
| 76-100% | Very High | Red | Very high demand, consider alternatives |

### Confidence Score

Confidence is calculated based on data availability:
- Formula: `min(100, (total_historical_records / 20) * 100)`
- More historical data = higher confidence
- Minimum 5 records required for reliable predictions
- Falls back to 50% confidence with insufficient data

## Alternative Slot Suggestions

When demand is high (≥50%), the system suggests alternative slots:

### Suggestion Logic

1. **Same Day, Different Time**
   - Checks all common time slots for the same date
   - Filters for low/medium demand slots
   - Prioritizes slots with lower scores

2. **Adjacent Days, Same Time**
   - Checks ±2 days from selected date
   - Filters for low/medium demand slots
   - Excludes past dates

3. **Scoring and Ranking**
   - Sorts by demand score (lowest first)
   - Limits to top 5 suggestions
   - Provides reason for each suggestion

### Common Time Slots

The system checks these standard time slots:
- 08:00-10:00, 09:00-11:00, 10:00-12:00
- 13:00-15:00, 14:00-16:00, 15:00-17:00
- 16:00-18:00, 17:00-19:00, 18:00-20:00
- 19:00-21:00, 20:00-22:00

## API Integration

### Demand Prediction Endpoint

**File**: `resources/views/pages/dashboard/ai_conflict_check.php`

**Request**:
```php
POST /dashboard/ai-conflict-check
{
    "facility_id": 1,
    "date": "2024-12-25",
    "time_slot": "18:00-20:00"
}
```

**Response**:
```json
{
    "has_conflict": false,
    "demand_score": 85,
    "demand_classification": "Very High",
    "demand_confidence": 75,
    "demand_factors": [
        {
            "factor": "Historical Demand",
            "value": 3.2,
            "impact": 25.6
        },
        {
            "factor": "Holiday/Event",
            "value": "Christmas Day",
            "impact": 25
        },
        {
            "factor": "Peak Hours",
            "value": "18:00-20:00",
            "impact": 20
        }
    ],
    "demand_alternatives": [
        {
            "date": "2024-12-24",
            "time_slot": "14:00-16:00",
            "score": 35,
            "classification": "Medium",
            "reason": "Lower predicted demand"
        }
    ]
}
```

## Database Schema

### Existing Tables Used

No new database tables required. The system uses existing tables:

- **reservations**: Historical booking data
  - `facility_id`, `reservation_date`, `time_slot`, `status`
  - Only approved reservations are analyzed

- **facilities**: Facility information
  - `id`, `name`, `status`

### Performance Considerations

Recommended indexes for optimal performance:
```sql
CREATE INDEX idx_reservations_facility_date ON reservations(facility_id, reservation_date);
CREATE INDEX idx_reservations_status ON reservations(status);
CREATE INDEX idx_reservations_date_status ON reservations(reservation_date, status);
```

## Usage Examples

### 1. Get Facility Demand Forecast

```php
require_once 'services/PredictionService.php';

$pdo = db();
$predictionService = new PredictionService($pdo);

// Get 14-day forecast for a specific facility
$forecast = $predictionService->getFacilityDemandForecast($facilityId, 14);

foreach ($forecast as $dayForecast) {
    echo $dayForecast['date'] . "\n";
    foreach ($dayForecast['slots'] as $slot) {
        echo "  " . $slot['time_slot'] . ": " . $slot['score'] . "% " . $slot['classification'] . "\n";
    }
}
```

### 2. Predict Demand for Specific Slot

```php
$prediction = $predictionService->predictDemand($facilityId, '2024-12-25', '18:00-20:00');

echo "Demand Score: " . $prediction['score'] . "%\n";
echo "Classification: " . $prediction['classification'] . "\n";
echo "Confidence: " . $prediction['confidence'] . "%\n";

foreach ($prediction['factors'] as $factor) {
    echo $factor['factor'] . ": " . $factor['impact'] . "\n";
}
```

### 3. Get Alternative Slots for High Demand

```php
$alternatives = $predictionService->getAlternativeSlots($facilityId, '2024-12-25', '18:00-20:00');

foreach ($alternatives as $alt) {
    echo $alt['date'] . " " . $alt['time_slot'] . ": " . $alt['score'] . "%\n";
    echo "Reason: " . $alt['reason'] . "\n";
}
```

## Future Improvements

### Machine Learning Integration

The current rule-based system is designed to be replaced with ML models:

1. **Data Collection**: The system logs all conflict checks with demand scores
2. **Feature Engineering**: Historical factors are logged for ML training
3. **Model Training**: Can train models on logged data
4. **API Compatibility**: Same API endpoints, improved predictions

### Potential ML Features

- **Random Forest**: Ensemble method for non-linear patterns
- **Gradient Boosting**: XGBoost/LightGBM for complex interactions
- **Time Series Models**: ARIMA/Prophet for seasonal patterns
- **Neural Networks**: LSTM for sequential pattern recognition

### Additional Data Sources

- **Weather Data**: Impact on outdoor facility usage
- **School Calendar**: Academic year patterns
- **Local Events API**: Dynamic event data integration
- **Social Media Trends**: Event buzz detection

### Advanced Features

- **Real-time Updates**: Live demand tracking
- **A/B Testing**: Compare prediction accuracy
- **User Feedback**: Collect actual vs predicted demand
- **Dynamic Pricing**: Suggest pricing based on demand

## Configuration

### Holiday Calendar

Holidays are configured in `PredictionService.php`:

```php
private function loadHolidays() {
    // Add holidays for current and next year
    $this->holidays["$year-01-01"] = 'New Year\'s Day';
    $this->holidays["$year-12-25"] = 'Christmas Day';
    // Add local events
    $this->holidays["$year-09-08"] = 'Barangay Culiat Fiesta';
}
```

### Time Slot Categories

Peak and off-peak hours are configurable:

```php
private $peakHours = ['16:00-18:00', '18:00-20:00', '19:00-21:00', '20:00-22:00'];
private $offPeakHours = ['08:00-10:00', '09:00-11:00', '10:00-12:00', '14:00-16:00'];
```

### Prediction Parameters

Adjust prediction sensitivity:

```php
private $lookbackMonths = 6;  // Historical data window
private $minDataThreshold = 5; // Minimum records for reliable predictions
```

## Troubleshooting

### Issue: Predictions Always Show 50% Confidence

**Cause**: Insufficient historical data (less than 5 records)

**Solution**: 
- Wait for more approved reservations
- Lower `$minDataThreshold` in PredictionService
- Use rule-based fallbacks

### Issue: High Demand for All Slots

**Cause**: Overweighting of factors

**Solution**:
- Adjust factor weights in scoring algorithm
- Check for data anomalies
- Review holiday calendar accuracy

### Issue: Slow Performance

**Cause**: Missing database indexes

**Solution**:
- Add recommended indexes
- Optimize historical data queries
- Consider caching predictions

## Security Considerations

1. **Authentication**: All API endpoints require authenticated sessions
2. **CSRF Protection**: All POST requests include CSRF tokens
3. **Input Validation**: All inputs are sanitized and validated
4. **Rate Limiting**: Consider implementing rate limiting for API endpoints
5. **Data Privacy**: Historical data is aggregated, no personal information exposed

## Performance Metrics

### Target Performance

- **API Response Time**: < 200ms for single slot prediction
- **Forecast Generation**: < 1s for 14-day facility forecast
- **Database Queries**: < 50ms per query with proper indexing

### Monitoring

Log the following metrics:
- Prediction accuracy (actual vs predicted)
- API response times
- Database query performance
- User engagement with suggestions

## Conclusion

The AI-powered demand prediction system provides actionable insights for both users and administrators. The rule-based approach ensures reliability and explainability while being designed for future ML integration. The system helps users choose optimal time slots and enables administrators to anticipate and manage demand effectively.

## Version History

- **v1.0** (2024): Initial rule-based prediction system
  - Historical data analysis
  - Holiday/event integration
  - Demand scoring algorithm
  - Alternative slot suggestions
  - Booking page integration
