# AI Models Integration Summary

## ✅ What Was Created

### 1. PHP Integration Helper (`config/ai_ml_integration.php`)
Ready-to-use PHP functions to call Python ML models:
- `predictConflictML()` - Predict booking conflicts
- `assessRiskML()` - Assess auto-approval risk
- `classifyChatbotIntent()` - Classify chatbot questions
- `checkMLModelsStatus()` - Check which models are available
- `callPythonModel()` - Generic function to call any Python script

### 2. Python API Scripts (`ai/api/`)
Command-line scripts that PHP can call:
- `predict_conflict.py` - Conflict detection API
- `predict_risk.py` - Risk assessment API
- `classify_intent.py` - Chatbot intent classification API

### 3. Documentation
- `docs/AI_INTEGRATION_GUIDE.md` - Complete integration guide with examples

## 🚀 Quick Start

### Step 1: Test Python Integration

```php
<?php
require_once __DIR__ . '/config/ai_ml_integration.php';

// Check if models are available
$status = checkMLModelsStatus();
print_r($status);
```

### Step 2: Use ML Predictions

```php
// Conflict Detection
$conflict = predictConflictML(
    facilityId: 1,
    reservationDate: '2026-02-15',
    timeSlot: '08:00 - 12:00',
    expectedAttendees: 50,
    isCommercial: false,
    capacity: '200'
);

// Auto-Approval Risk
$risk = assessRiskML(
    facilityId: 1,
    userId: 1,
    reservationDate: '2026-02-15',
    timeSlot: '08:00 - 12:00',
    expectedAttendees: 50,
    isCommercial: false,
    facilityData: $facilityData,
    userData: $userData
);

// Chatbot Intent
$intent = classifyChatbotIntent("What facilities are available?");
```

### Step 3: Integrate with Existing Code

Add ML predictions to existing functions in:
- `config/ai_helpers.php` - Enhance conflict detection
- `config/auto_approval.php` - Enhance auto-approval evaluation
- Chatbot endpoints - Use intent classification

## 📝 Integration Examples

See `docs/AI_INTEGRATION_GUIDE.md` for:
- Complete code examples
- Integration with existing functions
- Error handling
- Best practices
- Troubleshooting

## ⚠️ Requirements

1. **Python installed** and accessible from command line
2. **Models trained** (run training scripts first)
3. **PHP permissions** to execute Python scripts

## 🔧 Troubleshooting

If Python is not found, update `getPythonPath()` in `config/ai_ml_integration.php` with your Python path.

For detailed troubleshooting, see `docs/AI_INTEGRATION_GUIDE.md`.
