# Facility Recommendation Model

## Overview

The Facility Recommendation Model uses machine learning to predict which facilities are most relevant for a user's booking request. It learns from historical booking patterns to provide personalized recommendations.

## How It Works

### Training Process

1. **Data Collection**: Loads historical approved reservations from the database
2. **Feature Engineering**: Extracts features from reservations:
   - **User Features**: User ID, booking history count
   - **Facility Features**: Facility ID, capacity, amenities count
   - **Purpose Features**: Keywords extracted from purpose text (meeting, celebration, sports, education, religious, community, feeding, commercial)
   - **Time Features**: Start hour, end hour, duration, day of week, month, is_weekend, is_holiday
   - **Booking Features**: Expected attendees, capacity ratio, is_commercial flag

3. **Relevance Scoring**: Calculates relevance scores based on:
   - How often a facility was booked for similar purposes
   - How often a user booked a specific facility
   - Recency of bookings (more recent = higher score)

4. **Model Training**: Trains a Random Forest Regressor to predict relevance scores

### Inference Process

When a user requests facility recommendations:
1. Extract features from the booking request (purpose, time slot, date, expected attendees)
2. For each available facility, prepare feature vector
3. Use trained model to predict relevance score
4. Sort facilities by relevance score
5. Return top N recommendations

## Files

- **`scripts/train_facility_recommendation.py`**: Training script
- **`src/facility_recommendation.py`**: Inference module with `FacilityRecommendationModel` class
- **`models/facility_recommendation_model.pkl`**: Trained model (created after training)
- **`models/facility_recommendation_encoders.pkl`**: Label encoders for categorical features

## Usage

### Training

```bash
cd ai
python scripts/train_facility_recommendation.py
```

**Requirements**: At least 5 approved reservations in the database.

### Inference (Python)

```python
from src.facility_recommendation import FacilityRecommendationModel

model = FacilityRecommendationModel()
model.load_model()

facilities = [
    {'id': 1, 'name': 'Court', 'capacity': '200', 'amenities': 'Parking, Restrooms'},
    # ... more facilities
]

recommendations = model.recommend_facilities(
    facilities=facilities,
    user_id=1,
    purpose='Basketball tournament',
    expected_attendees=50,
    time_slot='08:00 - 12:00',
    reservation_date='2026-02-15',
    is_commercial=False,
    user_booking_count=5,
    limit=5
)

# Recommendations are sorted by ML relevance score
for rec in recommendations:
    print(f"{rec['name']}: ML Score = {rec['ml_relevance_score']:.2f}")
```

## Integration with PHP

The model can be integrated with PHP through:
1. **REST API** (recommended): Create a Flask/FastAPI endpoint that PHP calls
2. **Direct Python execution**: Use `exec()` or `shell_exec()` to run Python scripts
3. **Shared model file**: Both PHP and Python access the same model files

### Example PHP Integration (via Python script)

```php
// Call Python script to get ML recommendations
$command = sprintf(
    'python ai/src/facility_recommendation_api.py --user_id=%d --purpose="%s" --facilities_json=\'%s\'',
    $userId,
    escapeshellarg($purpose),
    json_encode($facilities)
);
$output = shell_exec($command);
$ml_scores = json_decode($output, true);
```

## Model Performance

The model is evaluated using:
- **RMSE** (Root Mean Squared Error): Lower is better
- **MAE** (Mean Absolute Error): Lower is better  
- **R² Score**: Higher is better (1.0 = perfect predictions)

## Feature Importance

After training, the model shows which features are most important for predictions:
- Higher importance = feature has more influence on recommendations
- Common important features: facility_id, purpose keywords, user history, capacity ratio

## Limitations

1. **Cold Start Problem**: New facilities or users with no history may have lower scores
2. **Data Requirements**: Needs at least 5 approved reservations to train effectively
3. **Purpose Keywords**: Relies on keyword matching - may miss nuanced purposes
4. **No Real-time Learning**: Model must be retrained periodically to learn new patterns

## Future Improvements

1. **Collaborative Filtering**: Add user-user and facility-facility similarity
2. **Deep Learning**: Use neural networks for better purpose understanding
3. **Real-time Updates**: Incremental learning as new bookings come in
4. **A/B Testing**: Compare ML recommendations vs rule-based recommendations
5. **Hybrid Approach**: Combine ML scores with rule-based scores (distance, capacity match)
