# Complete AI Models Documentation

## Overview

This directory contains machine learning models for the Facilities Reservation System. All models use scikit-learn and are trained on historical reservation data.

## Available Models

### 1. ✅ Conflict Detection Model
**File**: `models/conflict_detection.pkl`  
**Training Script**: `scripts/train_conflict_detection.py`  
**Status**: ✅ Trained and ready

Predicts booking conflicts before they occur.

**Features**:
- Time features (start/end hour, duration)
- Date features (day of week, month, weekend, holiday)
- Facility features (capacity)
- User features (expected attendees, capacity ratio)
- Commercial flag

**Usage**: Loads model and predicts conflict probability for new reservations.

---

### 2. ✅ Facility Recommendation Model
**Files**: 
- `models/facility_recommendation_model.pkl`
- `models/facility_recommendation_encoders.pkl`
**Training Script**: `scripts/train_facility_recommendation.py`  
**Status**: ✅ Training script ready (needs 5+ approved reservations)

Recommends facilities based on user requirements and booking history.

**Features**:
- User preferences and booking history
- Facility features (capacity, amenities)
- Purpose keywords (meeting, celebration, sports, etc.)
- Time/date features
- Booking features (expected attendees, capacity ratio, commercial flag)

**Usage**: Predicts relevance scores for facilities based on user query.

---

### 3. ✅ Auto-Approval Risk Assessment Model
**Files**:
- `models/auto_approval_risk_model.pkl`
- `models/auto_approval_risk_encoders.pkl`
**Training Script**: `scripts/train_auto_approval_risk.py`  
**Status**: ✅ Trained and ready

Predicts risk level for reservations (low risk = auto-approve, high risk = manual review).

**Features**:
- Facility settings (auto_approve, capacity, thresholds)
- User features (verification, booking count, violations)
- Time/date features
- Booking features (attendees, capacity ratio, duration ratio, commercial flag)
- Rule compliance flags

**Usage**: Assesses risk for reservation requests.

---

### 4. ✅ Demand Forecasting Model
**File**: `models/demand_forecasting_model.pkl`  
**Training Script**: `scripts/train_demand_forecasting.py`  
**Status**: ✅ Training script ready (needs 30+ reservations)

Predicts future booking demand for facilities.

**Features**:
- Time series features (year, month, day, day of week, week)
- Lag features (previous day, week, month)
- Rolling averages (7-day, 30-day)
- Holiday flags
- Facility ID

**Usage**: Forecasts booking demand for future dates.

---

### 5. ✅ NLP Purpose Analysis Model
**Files**:
- `models/purpose_category_model.pkl`
- `models/purpose_category_vectorizer.pkl`
- `models/purpose_unclear_model.pkl`
- `models/purpose_unclear_vectorizer.pkl`
**Training Script**: `scripts/train_purpose_analysis.py`  
**Status**: ✅ Trained and ready

Categorizes reservation purposes and detects unclear/suspicious purposes.

**Categories**:
- community, sports, education, religious, celebration, private, government, unclear

**Usage**: 
- Classifies purpose text into categories
- Detects unclear/vague purposes

---

### 6. ✅ Chatbot Intent Classification Model
**Files**:
- `models/chatbot_intent_model.pkl`
- `models/chatbot_intent_vectorizer.pkl`
**Training Script**: `scripts/train_chatbot_intents.py`  
**Status**: ✅ Trained and ready

Classifies user questions into intents for the chatbot.

**Intents**:
- list_facilities, facility_details, book_facility, check_availability
- booking_rules, cancel_booking, my_bookings
- greeting, goodbye, help, unknown

**Usage**: Classifies user questions to determine appropriate response.

---

## Training All Models

```bash
cd ai
venv\Scripts\activate  # Windows
# or
source venv/bin/activate  # Linux/Mac

# Train all models
python scripts/train_conflict_detection.py
python scripts/train_facility_recommendation.py
python scripts/train_auto_approval_risk.py
python scripts/train_demand_forecasting.py
python scripts/train_purpose_analysis.py
python scripts/train_chatbot_intents.py
```

## Model Requirements

| Model | Minimum Data | Status |
|-------|-------------|--------|
| Conflict Detection | 10 reservations | ✅ Ready |
| Facility Recommendation | 5 approved reservations | ⚠️ Needs more data |
| Auto-Approval Risk | 10 reservations | ✅ Ready |
| Demand Forecasting | 30 reservations | ⚠️ Needs more data |
| Purpose Analysis | 10 reservations | ✅ Ready |
| Chatbot Intents | N/A (synthetic data) | ✅ Ready |

## Integration with PHP

Models can be integrated with PHP through:
1. **Direct Python execution**: Use `exec()` or `shell_exec()`
2. **REST API** (recommended): Create Flask/FastAPI endpoints
3. **Shared model files**: Both PHP and Python access same model files

See individual model documentation files for integration examples.

## Model Files Structure

```
ai/
├── models/                    # Trained model files (*.pkl)
├── scripts/                   # Training scripts
│   ├── train_conflict_detection.py
│   ├── train_facility_recommendation.py
│   ├── train_auto_approval_risk.py
│   ├── train_demand_forecasting.py
│   ├── train_purpose_analysis.py
│   └── train_chatbot_intents.py
├── src/                       # Inference modules
│   ├── data_loader.py
│   ├── facility_recommendation.py
│   └── auto_approval_risk.py
├── config.py                  # Configuration
└── requirements.txt           # Python dependencies
```

## Next Steps

1. **Collect More Data**: More reservations = better models
2. **Retrain Periodically**: As data accumulates, retrain for better performance
3. **Create API Endpoints**: Build REST API for PHP integration
4. **Monitor Performance**: Track model accuracy over time
5. **A/B Testing**: Compare ML predictions vs rule-based logic

## Notes

- Models use Random Forest algorithms (classifiers/regressors)
- All models support incremental improvements as more data is collected
- Model files are gitignored (see `.gitignore`)
- Training scripts handle data validation and error cases gracefully
