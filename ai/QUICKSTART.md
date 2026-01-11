# Quick Start Guide - AI Training

## Step 1: Install Dependencies

First, activate your virtual environment and install required packages:

```bash
# Windows
cd ai
venv\Scripts\activate
pip install -r requirements.txt

# Linux/Mac
cd ai
source venv/bin/activate
pip install -r requirements.txt
```

## Step 2: Configure Database

Edit `config.py` and update your MySQL database credentials:

```python
DB_CONFIG = {
    'host': 'localhost',
    'port': 3306,
    'user': 'root',        # Your MySQL username
    'password': 'your_password',  # Your MySQL password
    'database': 'facilities_reservation',
    'charset': 'utf8mb4'
}
```

## Step 3: Extract Training Data

Extract data from your database:

```bash
python scripts/extract_data.py
```

This will create CSV files in the `data/` directory:
- `reservations.csv` - All reservations
- `facilities.csv` - All facilities
- `users.csv` - All users
- `conflicts.csv` - Historical conflict data

## Step 4: Train Models

### Train Conflict Detection Model

```bash
python scripts/train_conflict_detection.py
```

This will:
- Load reservation data
- Extract features (time, date, facility, user attributes)
- Train a Random Forest classifier
- Save the model to `models/conflict_detection.pkl`
- Display accuracy and feature importance

**Note:** You need at least 10-20 reservations for meaningful training. If you have fewer, the system will use rule-based conflict detection (already implemented in PHP).

## What's Next?

1. **Collect More Data**: The more reservations you have, the better the model will be
2. **Train Facility Recommendation Model**:
   
   This script trains a Random Forest model to predict facility relevance scores based on:
   - User preferences and booking history
   - Facility features (capacity, amenities)
   - Purpose keywords (meeting, celebration, sports, etc.)
   - Time/date features (day of week, holidays, time slots)
   - Booking features (expected attendees, capacity ratio, commercial flag)
   
   **Requirements**: At least 5 approved reservations in the database.
   
   ```bash
   python scripts/train_facility_recommendation.py
   ```
   
   **What it does**:
   - Loads historical approved reservations
   - Extracts features (purpose keywords, time features, facility features)
   - Calculates relevance scores based on booking patterns
   - Trains a Random Forest Regressor to predict relevance
   - Saves model to `models/facility_recommendation_model.pkl`
   - Saves label encoders to `models/facility_recommendation_encoders.pkl`
   
   **Output**: Model evaluation metrics (RMSE, MAE, R²) and feature importance rankings.

3. **Train Auto-Approval Risk Assessment Model**:

   This script trains a Random Forest classifier to predict risk levels for reservations:
   - **Low Risk (0)**: Safe to auto-approve
   - **High Risk (1)**: Requires manual review
   
   **Requirements**: At least 10 reservations with mix of outcomes (approved, denied, cancelled).
   
   ```bash
   python scripts/train_auto_approval_risk.py
   ```
   
   **What it does**:
   - Loads historical reservations
   - Extracts features (facility settings, user history, time/date features, booking details)
   - Calculates risk labels based on booking outcomes
   - Trains a Random Forest Classifier to predict risk
   - Saves model to `models/auto_approval_risk_model.pkl`
   
   **Output**: Model evaluation metrics (Accuracy, Precision, Recall, F1-Score) and feature importance.

4. **Train NLP Purpose Analysis Model**:

   Categorizes reservation purposes and detects unclear/suspicious purposes.
   
   ```bash
   python scripts/train_purpose_analysis.py
   ```
   
   **Output**: Purpose category classifier and unclear purpose detector models.

5. **Train Demand Forecasting Model**:

   Predicts future booking demand for facilities using time series analysis.
   
   **Requirements**: At least 30 reservations for meaningful forecasting.
   
   ```bash
   python scripts/train_demand_forecasting.py
   ```

6. **Train Chatbot Intent Classification Model**:

   Classifies user questions into intents for the chatbot.
   
   ```bash
   python scripts/train_chatbot_intents.py
   ```
   
   **Note**: This model uses synthetic training data and works immediately.

## Current Status

✅ **Completed:**
- Project structure
- Data extraction script
- Database connection utilities
- Conflict detection model ✅
- Facility recommendation model (script ready)
- Auto-approval risk assessment model ✅
- Demand forecasting model (script ready)
- NLP purpose analysis model ✅
- Chatbot intent classification model ✅

📋 **Next Steps:**
- Collect more reservation data for models that need it
- Create API endpoints for PHP integration
- Retrain models periodically as data accumulates

## Troubleshooting

### Database Connection Error
- Check your MySQL credentials in `config.py`
- Ensure MySQL server is running
- Verify database name is correct

### Not Enough Data
- The system currently uses rule-based conflict detection (PHP)
- ML models need at least 10-20 samples per class to train effectively
- Continue using the system - data will accumulate over time

### Import Errors
- Make sure virtual environment is activated
- Run `pip install -r requirements.txt` again
- Check that all files are in correct directories

## Model Files

Trained models are saved in `models/` directory:
- `conflict_detection.pkl` - Conflict prediction model
- `conflict_detection_features.pkl` - Feature names for inference

**Important:** Don't commit model files to git (they're in `.gitignore`)
