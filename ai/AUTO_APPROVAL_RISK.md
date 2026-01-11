# Auto-Approval Risk Assessment Model

## Overview

The Auto-Approval Risk Assessment Model uses machine learning to predict the risk level of reservation requests, helping determine whether they should be auto-approved or require manual review.

## How It Works

### Training Process

1. **Data Collection**: Loads historical reservations (approved, denied, cancelled) from the database
2. **Feature Engineering**: Extracts features from reservations:
   - **Facility Features**: Facility ID, auto_approve setting, capacity, max_duration_hours, capacity_threshold
   - **User Features**: User ID, verification status, booking count, violation count
   - **Time Features**: Start hour, end hour, duration, day of week, month, is_weekend, is_holiday
   - **Booking Features**: Expected attendees, capacity ratio, duration ratio, is_commercial, advance_days
   - **Rule Compliance**: Flags for within_capacity_threshold, within_duration_limit, within_advance_window

3. **Risk Labeling**: Calculates risk labels based on:
   - Auto-approved and approved → Low risk (0)
   - Denied or cancelled → High risk (1)
   - Approved but not auto-approved → High risk (manual review was needed)

4. **Model Training**: Trains a Random Forest Classifier to predict risk levels

### Inference Process

When evaluating a reservation request:
1. Extract features from the request (facility, user, time, booking details)
2. Use trained model to predict risk level (0 = Low, 1 = High)
3. Get risk probability and confidence scores
4. Return assessment results

## Files

- **`scripts/train_auto_approval_risk.py`**: Training script
- **`src/auto_approval_risk.py`**: Inference module with `AutoApprovalRiskModel` class
- **`models/auto_approval_risk_model.pkl`**: Trained model (created after training)
- **`models/auto_approval_risk_encoders.pkl`**: Label encoders for categorical features

## Usage

### Training

```bash
cd ai
python scripts/train_auto_approval_risk.py
```

**Requirements**: At least 10 reservations in the database (mix of approved, denied, cancelled).

### Inference (Python)

```python
from src.auto_approval_risk import AutoApprovalRiskModel

model = AutoApprovalRiskModel()
model.load_model()

risk_assessment = model.assess_reservation_risk(
    facility_id=1,
    user_id=1,
    reservation_date='2026-02-15',
    time_slot='08:00 - 12:00',
    expected_attendees=50,
    is_commercial=False,
    facility_auto_approve=True,
    facility_capacity=200,
    facility_max_duration_hours=8.0,
    facility_capacity_threshold=150,
    user_is_verified=True,
    user_booking_count=5,
    user_violation_count=0
)

print(f"Risk Level: {'Low' if risk_assessment['is_low_risk'] else 'High'}")
print(f"Risk Probability: {risk_assessment['risk_probability']:.2%}")
print(f"Confidence: {risk_assessment['confidence']:.2%}")
```

## Integration with PHP

The model can be integrated with PHP's existing `evaluateAutoApproval()` function to:
1. **Enhance Rule-Based System**: Use ML predictions as an additional signal
2. **Risk Scoring**: Provide risk scores alongside rule-based checks
3. **Explainability**: Show ML-based risk factors to staff

### Example PHP Integration

```php
// After rule-based evaluation, get ML risk assessment
$mlRiskCommand = sprintf(
    'python ai/src/auto_approval_risk_api.py --facility_id=%d --user_id=%d --date="%s" --time_slot="%s" --attendees=%d --commercial=%d',
    $facilityId,
    $userId,
    $reservationDate,
    $timeSlot,
    $expectedAttendees,
    $isCommercial ? 1 : 0
);
$mlOutput = shell_exec($mlRiskCommand);
$mlRisk = json_decode($mlOutput, true);

// Combine rule-based and ML-based results
if ($result['eligible'] && $mlRisk['is_low_risk']) {
    // Both rule-based and ML suggest auto-approval
    $result['auto_approve'] = true;
    $result['ml_risk_score'] = $mlRisk['risk_probability'];
}
```

## Model Performance

The model is evaluated using:
- **Accuracy**: Overall prediction accuracy
- **Precision**: Of high-risk predictions, how many were actually high-risk
- **Recall**: Of actual high-risk cases, how many were caught
- **F1-Score**: Balance between precision and recall

## Risk Levels

- **Low Risk (0)**: Safe to auto-approve
  - High confidence that reservation will be successful
  - User has good history, facility allows auto-approval, booking meets all criteria
  
- **High Risk (1)**: Requires manual review
  - Suspicious patterns detected
  - User has violations, booking is unusual, or other risk factors

## Limitations

1. **Data Requirements**: Needs at least 10 reservations with mix of outcomes
2. **Class Imbalance**: May have many more high-risk than low-risk samples
3. **Cold Start**: New facilities or users with no history may be flagged as high-risk
4. **No Real-time Learning**: Model must be retrained periodically

## Future Improvements

1. **Confidence Thresholds**: Different thresholds for auto-approval vs. flagging
2. **Explainability**: SHAP values to show which features contribute to risk
3. **Hybrid Approach**: Combine rule-based checks with ML predictions
4. **Feature Engineering**: Add more features (time between bookings, facility popularity, etc.)
5. **Real-time Updates**: Incremental learning as new bookings are processed
