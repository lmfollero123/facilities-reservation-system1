# AI Models Integration Status

## ✅ Fully Integrated Models (3/7)

These models are trained, have API scripts, and are integrated into the PHP system:

### 1. Conflict Detection ✅
- **Model File**: `ai/models/conflict_detection.pkl`
- **API Script**: `ai/api/predict_conflict.py`
- **PHP Function**: `predictConflictML()` in `config/ai_ml_integration.php`
- **Integration**: Used in `config/ai_helpers.php` → `calculateConflictRisk()`
- **Usage**: Automatically called when checking for booking conflicts
- **Test**: Available on "Test AI Models" page

### 2. Auto-Approval Risk Assessment ✅
- **Model File**: `ai/models/auto_approval_risk_model.pkl`
- **API Script**: `ai/api/predict_risk.py`
- **PHP Function**: `assessRiskML()` in `config/ai_ml_integration.php`
- **Integration**: Used in `config/auto_approval.php` → `evaluateAutoApproval()`
- **Usage**: Automatically called during auto-approval evaluation
- **Test**: Available on "Test AI Models" page

### 3. Chatbot Intent Classification ✅
- **Model File**: `ai/models/chatbot_intent_model.pkl`
- **API Script**: `ai/api/classify_intent.py`
- **PHP Function**: `classifyChatbotIntent()` in `config/ai_ml_integration.php`
- **Integration**: Used in `resources/views/pages/dashboard/ai_chatbot.php`
- **Usage**: Classifies user questions to provide appropriate responses
- **Test**: Available on "Test AI Models" page

---

## ⚠️ Trained but Not Integrated (4/7)

These models are trained but don't have API scripts or PHP integration yet:

### 4. Facility Recommendation ⚠️
- **Model File**: `ai/models/facility_recommendation_model.pkl` (exists)
- **API Script**: ❌ Not created
- **PHP Function**: ❌ Not created
- **Integration**: ❌ Not integrated
- **Status**: Model trained but no API/integration
- **Potential Usage**: Recommend facilities based on user requirements

### 5. Demand Forecasting ⚠️
- **Model File**: `ai/models/demand_forecasting_model.pkl` (may exist)
- **API Script**: ❌ Not created
- **PHP Function**: ❌ Not created
- **Integration**: ❌ Not integrated (could be used in `ai_scheduling.php`)
- **Status**: Model trained but no API/integration
- **Potential Usage**: Predict future booking demand for scheduling

### 6. Purpose Category Analysis ⚠️
- **Model File**: `ai/models/purpose_category_model.pkl` (exists)
- **API Script**: ❌ Not created
- **PHP Function**: ❌ Not created
- **Integration**: ❌ Not integrated
- **Status**: Model trained but no API/integration
- **Potential Usage**: Categorize reservation purposes (education, community, etc.)

### 7. Purpose Unclear Detection ⚠️
- **Model File**: `ai/models/purpose_unclear_model.pkl` (exists)
- **API Script**: ❌ Not created
- **PHP Function**: ❌ Not created
- **Integration**: ❌ Not integrated
- **Status**: Model trained but no API/integration
- **Potential Usage**: Detect if a reservation purpose is unclear/vague

---

## Testing

### Test Page
Visit: `/resources/views/pages/dashboard/test_ai_models.php`

The test page allows you to:
- View status of all models
- See which models are integrated vs available
- Test the 3 integrated models with sample data
- View detailed test results

### Manual Testing

#### Test Conflict Detection
```php
require_once 'config/ai_ml_integration.php';

$result = predictConflictML(
    facilityId: 1,
    reservationDate: '2026-02-15',
    timeSlot: '08:00 - 12:00',
    expectedAttendees: 50,
    isCommercial: false,
    capacity: '200'
);
print_r($result);
```

#### Test Auto-Approval Risk
```php
require_once 'config/ai_ml_integration.php';

$result = assessRiskML(
    facilityId: 1,
    userId: 1,
    reservationDate: '2026-02-15',
    timeSlot: '08:00 - 12:00',
    expectedAttendees: 50,
    isCommercial: false,
    facilityData: [...],
    userData: [...]
);
print_r($result);
```

#### Test Chatbot Intent
```php
require_once 'config/ai_ml_integration.php';

$result = classifyChatbotIntent("What facilities are available?");
print_r($result);
```

---

## Integration Checklist

To integrate the remaining models:

1. **Create API Script** (`ai/api/predict_*.py`)
   - Load model
   - Accept JSON input via stdin
   - Process input
   - Return JSON output

2. **Create PHP Function** (`config/ai_ml_integration.php`)
   - Use `callPythonModel()` helper
   - Handle errors gracefully
   - Return formatted results

3. **Integrate into System**
   - Add calls in appropriate PHP files
   - Add fallback logic for errors
   - Update documentation

4. **Test**
   - Test API script directly
   - Test PHP function
   - Test integration in system
   - Add to test page

---

## Notes

- All models use graceful degradation: if ML fails, system falls back to rule-based logic
- Models are called asynchronously and don't block system operations
- Error handling is built into all integration functions
- Model status can be checked using `checkMLModelsStatus()` function
