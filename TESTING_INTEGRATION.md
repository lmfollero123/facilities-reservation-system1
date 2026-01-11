# Testing AI Models Integration

## Quick Test Guide

### 1. Test Conflict Detection

1. Go to **Book Facility** page
2. Select a facility, date, and time
3. The conflict detection will automatically use ML if available
4. Check browser console or server logs for ML prediction info

**What to expect:**
- Rule-based conflict detection works as before
- ML predictions enhance risk scores (if model is available)
- If ML fails, system falls back to rule-based only

---

### 2. Test Auto-Approval with ML

1. Make a reservation that meets all rule-based conditions:
   - Facility has auto_approve enabled
   - Date is not blacked out
   - Within capacity/duration limits
   - Non-commercial
   - User is verified
   - No violations
   - Within advance booking window

2. Check if reservation gets auto-approved or pending
3. If ML model is available, check the result array for `ml_risk` data

**What to expect:**
- Rule-based auto-approval works as before
- ML risk assessment adds additional intelligence
- High-confidence ML high-risk predictions can override auto-approval
- If ML fails, system uses rule-based only

---

### 3. Test Chatbot API

**Option A: Direct URL Test**
```
http://your-domain/resources/views/pages/dashboard/chatbot_api.php?question=What facilities are available?
```

**Option B: Use Existing Chatbot**
1. Go to the chatbot page (if available)
2. Ask questions like:
   - "What facilities are available?"
   - "How do I book a facility?"
   - "What are the booking rules?"
3. Check responses - should use ML intent classification if available

**What to expect:**
- ML classifies user questions into intents
- Appropriate responses based on detected intent
- Falls back to keyword matching if ML unavailable

---

## Check Model Status

Create a test file: `test_ml_status.php`

```php
<?php
require_once __DIR__ . '/config/ai_ml_integration.php';

header('Content-Type: application/json');

$status = checkMLModelsStatus();
echo json_encode($status, JSON_PRETTY_PRINT);
```

Visit: `http://your-domain/test_ml_status.php`

---

## Expected Behavior

### If Models Are Available:
- ✅ ML predictions enhance rule-based logic
- ✅ Results include ML prediction data
- ✅ Better accuracy and intelligence

### If Models Are NOT Available:
- ✅ System works normally with rule-based logic
- ✅ No errors or broken functionality
- ✅ Graceful degradation

### If Python/Models Fail:
- ✅ Errors logged but don't break functionality
- ✅ System continues with rule-based logic
- ✅ User experience unchanged

---

## Troubleshooting

### Python Not Found
- Check Python is installed: `python --version`
- Update `getPythonPath()` in `config/ai_ml_integration.php`

### Models Not Found
- Train models: `python scripts/train_*.py`
- Check models exist in `ai/models/` directory

### Permission Errors
- Check PHP can execute Python scripts
- Check file permissions on `ai/` directory

### JSON Parse Errors
- Check Python scripts output valid JSON
- Check for Python errors in error logs

---

## Next Steps After Testing

1. **Monitor Performance**: Check if ML predictions improve results
2. **Collect Feedback**: See if users notice improvements
3. **Retrain Models**: As more data accumulates, retrain for better performance
4. **Fine-tune**: Adjust ML weights/confidence thresholds based on results
