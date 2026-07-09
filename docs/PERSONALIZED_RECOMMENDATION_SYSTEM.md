# AI-Powered Personalized Reservation Recommendation System

## Overview

This document describes the AI-powered personalized reservation recommendation system for the LGU Facilities Reservation System. The system learns each user's reservation habits and automatically suggests facilities, dates, and time slots based on their previous reservations.

## Architecture

### Component Structure

```
services/RecommendationService.php
├── User history analysis
├── Pattern detection
├── Recommendation scoring
├── Fallback recommendations
└── Alternative suggestions

resources/views/pages/dashboard/ai_scheduling.php
├── Personalized recommendations UI
├── GSAP animations
├── Recommendation cards display
└── Quick booking integration
```

### Design Principles

1. **User-Centric**: Recommendations based on individual user behavior
2. **Graceful Degradation**: Fallback to popular choices for new users
3. **Modular Design**: Easy to replace with ML models
4. **Performance**: Optimized queries with proper indexing
5. **Explainability**: Clear reasons for each recommendation

## Database Analysis

### Available Personalization Fields

**User Reservation History** (`reservations` table)
- `facility_id` - Frequently reserved facilities
- `reservation_date` - Preferred days of week, patterns
- `time_slot` - Preferred time slots, duration patterns
- `purpose` - Event types (text analysis)
- `expected_attendees` - Typical group size
- `is_commercial` - Commercial vs personal use
- `created_at` - Reservation frequency, recency

**User Profile** (`users` table)
- `role` - Admin/Staff/Resident (affects recommendations)
- `created_at` - Account age (new vs experienced users)

**Facility Data** (`facilities` table)
- `name`, `description`, `location`, `capacity`, `amenities`
- `status` - Available facilities only
- `auto_approve` - Auto-approval eligibility

## Recommendation Algorithm

### Scoring Components (0-100%)

The final recommendation score is calculated from multiple weighted factors:

#### 1. Facility Frequency Score (35%)
- Count reservations per facility
- Normalize to 0-35 scale
- Formula: `(facility_count / total_reservations) * 35`

#### 2. Preferred Facility Bonus (15%)
- Additional bonus for user's most reserved facility
- Formula: `15 if matches preferred facility, else 0`

#### 3. Recency Factor (10%)
- More recent reservations weighted higher
- Formula: `max(0, 10 - (days_since_last / 18))`
- Decay over 180 days

#### 4. Capacity Match (5%)
- Boost if facility capacity matches typical attendee count
- Formula: `5 if capacity >= typical_attendees, else 0`

#### 5. Day of Week Preference (20%)
- Analyze most common day of week
- Boost for matching preferred day
- Formula: `20 if matches preferred day, else 0`

#### 6. Time Slot Preference (20%)
- Analyze most common time slots
- Boost for matching preferred time
- Formula: `20 if matches preferred time, else 0`

### Pattern Detection

The system analyzes the following user patterns:

1. **Facility Frequency**: Which facilities are reserved most often
2. **Day of Week**: Preferred days (Saturday, Sunday, etc.)
3. **Time Slot**: Preferred time ranges (morning, afternoon, evening)
4. **Duration**: Typical reservation length in hours
5. **Attendees**: Typical group size
6. **Recency**: How recently reservations were made

### Minimum History Threshold

- **Minimum reservations**: 3 for personalized recommendations
- **Lookback period**: 6 months of history
- Below threshold: Fallback to popular choices

## Fallback Recommendations

For new users with insufficient history:

### Popular Facilities
- Most reserved facilities in last 3 months
- Base score: 75%
- Reasons: Popular choice, high availability, well-rated

### Quiet Time Slots
- Least crowded time slots based on reservation count
- Reduces competition for new users

### Trending Reservations
- Most booked facilities in last 30 days
- Shows current demand patterns

## Alternative Suggestions

When preferred slot is unavailable:

### Same Day Alternatives
- Suggest different time slots on the same day
- Common time slots checked: 08:00-22:00 range

### Nearest Day Alternatives
- Check next 7 days for availability
- Default to preferred time slot

### Similar Facilities
- Find facilities with similar capacity (±20%)
- Maintains user's typical requirements

## API Integration

### Get Personalized Recommendations

**File**: `services/RecommendationService.php`

**Method**: `getPersonalizedRecommendations($userId)`

**Returns**:
```php
[
    [
        'facility_id' => 1,
        'facility_name' => 'Covered Court',
        'score' => 96,
        'reasons' => [
            '✓ Reserved 8 time(s)',
            '✓ Your most reserved facility',
            '✓ Preferred Saturday',
            '✓ Preferred time slot'
        ],
        'suggested_date' => '2024-12-28',
        'suggested_time' => '14:00-16:00',
        'suggested_duration' => 2,
        'suggested_attendees' => 20
    ]
]
```

### Get Alternative Suggestions

**Method**: `getAlternativeSuggestions($userId, $facilityId, $preferredDate, $preferredTime)`

**Returns**:
```php
[
    [
        'type' => 'same_day',
        'date' => '2024-12-28',
        'time_slot' => '16:00-18:00',
        'reason' => 'Same day, different time'
    ],
    [
        'type' => 'nearest_day',
        'date' => '2024-12-29',
        'time_slot' => '14:00-16:00',
        'reason' => 'Nearest available day'
    ]
]
```

## Database Queries

### User History Query
```sql
SELECT 
    r.id,
    r.facility_id,
    f.name as facility_name,
    r.reservation_date,
    r.time_slot,
    r.purpose,
    r.expected_attendees,
    r.is_commercial,
    r.status,
    r.created_at
FROM reservations r
JOIN facilities f ON r.facility_id = f.id
WHERE r.user_id = :user_id
    AND r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    AND r.status IN ('approved', 'completed')
ORDER BY r.reservation_date DESC
```

### Popular Facilities Query
```sql
SELECT f.id, f.name, COUNT(r.id) as reservation_count
FROM facilities f
JOIN reservations r ON f.id = r.facility_id
WHERE r.status = 'approved'
    AND r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
GROUP BY f.id, f.name
ORDER BY reservation_count DESC
LIMIT 5
```

### Quiet Time Slots Query
```sql
SELECT time_slot, COUNT(*) as count
FROM reservations
WHERE status = 'approved'
    AND reservation_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
GROUP BY time_slot
ORDER BY count ASC
LIMIT 5
```

## Performance Considerations

### Recommended Indexes
```sql
CREATE INDEX idx_reservations_user_date ON reservations(user_id, reservation_date);
CREATE INDEX idx_reservations_facility_date ON reservations(facility_id, reservation_date);
CREATE INDEX idx_reservations_status_date ON reservations(status, reservation_date);
```

### Query Optimization
- Single query for entire month forecast (not per-day)
- Cached pattern analysis results
- Limited lookback period (6 months)
- Efficient aggregation with GROUP BY

## User Interface

### Modern Design Features

1. **Gradient Backgrounds**: Purple-blue gradient for visual appeal
2. **Card Layout**: Grid-based recommendation cards
3. **GSAP Animations**: Smooth entrance animations with stagger
4. **Hover Effects**: Lift effect with shadow on hover
5. **Progress Indicators**: Animated top border on cards
6. **Responsive Design**: Adapts to different screen sizes

### Animation Timeline

1. **Page Header**: Fade in, slide up (0.8s)
2. **Welcome Section**: Fade in, slide up (0.8s, 0.2s delay)
3. **Recommendation Cards**: Staggered entrance (0.6s each, 0.15s stagger)
4. **Empty State**: Fade in, slide up (0.8s, 0.3s delay)

### GSAP Easing Functions

- `power3.out`: Smooth deceleration for headers
- `back.out(1.7)`: Bounce effect for cards
- Stagger delays create sequential appearance

## Usage Examples

### Get Recommendations for User
```php
require_once 'services/RecommendationService.php';

$pdo = db();
$recommendationService = new RecommendationService($pdo);
$userId = $_SESSION['user_id'];

$recommendations = $recommendationService->getPersonalizedRecommendations($userId);

foreach ($recommendations as $rec) {
    echo "Facility: " . $rec['facility_name'] . "\n";
    echo "Score: " . $rec['score'] . "%\n";
    echo "Suggested: " . $rec['suggested_date'] . " " . $rec['suggested_time'] . "\n";
    echo "Reasons: " . implode(', ', $rec['reasons']) . "\n";
}
```

### Get Alternatives for Unavailable Slot
```php
$alternatives = $recommendationService->getAlternativeSuggestions(
    $userId,
    $facilityId,
    $preferredDate,
    $preferredTime
);

foreach ($alternatives as $alt) {
    echo $alt['type'] . ": " . $alt['date'] . " " . $alt['time_slot'] . "\n";
    echo "Reason: " . $alt['reason'] . "\n";
}
```

## Future ML Integration

### Data Collection

The system logs all recommendation interactions:
- User clicks on recommendations
- Booking conversions from recommendations
- Alternative slot selections
- User feedback (if implemented)

### Potential ML Models

1. **Collaborative Filtering**: Find similar users and recommend their preferences
2. **Matrix Factorization**: Decompose user-facility interaction matrix
3. **Gradient Boosting**: XGBoost/LightGBM for complex feature interactions
4. **Neural Networks**: Deep learning for pattern recognition
5. **Reinforcement Learning**: Learn from user feedback over time

### Feature Engineering

- Temporal features: Day of week, month, season
- Behavioral features: Booking frequency, cancellation rate
- Contextual features: Event type, attendee count
- Social features: Similar users' preferences

### Model Training Pipeline

1. **Data Collection**: Gather user interaction data
2. **Feature Extraction**: Create feature vectors
3. **Model Training**: Train ML model on historical data
4. **Validation**: Test on holdout dataset
5. **Deployment**: Replace rule-based system
6. **Monitoring**: Track recommendation accuracy

### API Compatibility

The ML model will use the same API endpoints:
- `getPersonalizedRecommendations($userId)` - Same return format
- `getAlternativeSuggestions(...)` - Same return format
- Frontend requires no changes

## Configuration

### Service Parameters

```php
private $minHistoryForPersonalization = 3; // Minimum reservations
private $lookbackMonths = 6; // History window in months
```

### Scoring Weights

Adjust recommendation sensitivity by modifying weights:
- Facility frequency: 35%
- Preferred facility bonus: 15%
- Recency factor: 10%
- Capacity match: 5%
- Day preference: 20%
- Time preference: 20%

### Time Slot Definitions

Common time slots checked for alternatives:
- Morning: 08:00-12:00
- Afternoon: 13:00-17:00
- Evening: 18:00-22:00

## Security Considerations

1. **Authentication**: All recommendations require authenticated users
2. **Data Privacy**: Only user's own history analyzed
3. **Input Validation**: All inputs sanitized and validated
4. **SQL Injection**: Prepared statements for all queries
5. **Rate Limiting**: Consider implementing for API endpoints

## Troubleshooting

### Issue: No Recommendations Shown

**Cause**: User has less than 3 reservations

**Solution**: 
- System automatically uses fallback recommendations
- Fallback shows popular facilities instead

### Issue: Low Recommendation Scores

**Cause**: Inconsistent user behavior patterns

**Solution**:
- Increase lookback period for more data
- Adjust scoring weights
- Consider manual facility preferences

### Issue: Slow Performance

**Cause**: Missing database indexes

**Solution**:
- Add recommended indexes
- Optimize history queries
- Consider caching pattern analysis

## Metrics and Monitoring

### Key Performance Indicators

- **Recommendation Click Rate**: % of users clicking recommendations
- **Conversion Rate**: % of recommendations leading to bookings
- **User Satisfaction**: Feedback scores (if implemented)
- **Alternative Acceptance**: % of users accepting alternatives
- **Booking Speed**: Time reduction vs manual booking

### A/B Testing

Test recommendation effectiveness:
- Control group: No recommendations
- Test group: With recommendations
- Measure: Booking completion rate, time to book

## Conclusion

The AI-powered personalized recommendation system provides intelligent suggestions based on user behavior patterns. The rule-based approach ensures reliability and explainability while being designed for future ML integration. The system helps users book faster and discover optimal reservation options based on their preferences.

## Version History

- **v1.0** (2024): Initial rule-based recommendation system
  - User pattern analysis
  - Facility frequency scoring
  - Time/day preference detection
  - Fallback for new users
  - Alternative suggestions
  - Modern UI with GSAP animations
