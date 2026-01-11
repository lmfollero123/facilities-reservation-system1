# AI Models Integration Guide

This guide explains how to integrate the Python AI/ML models into the PHP system.

## Overview

The AI models are Python-based and can be called from PHP using command-line execution. This guide provides:

1. **PHP Helper Functions**: Ready-to-use functions to call Python models
2. **Integration Examples**: How to integrate with existing PHP code
3. **Troubleshooting**: Common issues and solutions

## Setup

### 1. Python Environment

Ensure Python is installed and accessible from command line:

```bash
python --version
# or
python3 --version
```

### 2. Verify Models

Check if models are trained:

```php
require_once __DIR__ . '/config/ai_ml_integration.php';

$status = checkMLModelsStatus();
print_r($status);
```

## Integration Methods

### Method 1: Using PHP Helper Functions (Recommended)

The `config/ai_ml_integration.php` file provides helper functions:

#### Conflict Detection

```php
require_once __DIR__ . '/config/ai_ml_integration.php';

$result = predictConflictML(
    facilityId: 1,
    reservationDate: '2026-02-15',
    timeSlot: '08:00 - 12:00',
    expectedAttendees: 50,
    isCommercial: false,
    capacity: '200'
);

if (isset($result['error'])) {
    // Fallback to rule-based detection
    echo "ML model error: " . $result['error'];
} else {
    echo "Conflict probability: " . ($result['conflict_probability'] * 100) . "%";
    if ($result['is_conflict']) {
        echo " - Conflict detected!";
    }
}
```

#### Auto-Approval Risk Assessment

```php
require_once __DIR__ . '/config/ai_ml_integration.php';

// Get facility and user data first
$facilityData = [
    'auto_approve' => true,
    'capacity' => '200',
    'max_duration_hours' => 8.0,
    'capacity_threshold' => 150,
];

$userData = [
    'is_verified' => true,
    'booking_count' => 5,
    'violation_count' => 0,
];

$result = assessRiskML(
    facilityId: 1,
    userId: 1,
    reservationDate: '2026-02-15',
    timeSlot: '08:00 - 12:00',
    expectedAttendees: 50,
    isCommercial: false,
    facilityData: $facilityData,
    userData: $userData
);

if ($result['is_low_risk']) {
    echo "Low risk - safe to auto-approve";
} else {
    echo "High risk - manual review recommended";
}
```

#### Chatbot Intent Classification

```php
require_once __DIR__ . '/config/ai_ml_integration.php';

$result = classifyChatbotIntent("What facilities are available?");

echo "Intent: " . $result['intent'];
echo "Confidence: " . ($result['confidence'] * 100) . "%";
```

### Method 2: Direct Integration with Existing Functions

#### Enhance Conflict Detection

Modify `config/ai_helpers.php` to include ML predictions:

```php
// In calculateConflictRisk() function
require_once __DIR__ . '/ai_ml_integration.php';

// After rule-based checks, add ML prediction
$mlPrediction = predictConflictML(
    $facilityId,
    $reservationDate,
    $timeSlot,
    $expectedAttendees,
    $isCommercial,
    $facility['capacity']
);

// Combine rule-based and ML-based risk
$finalRisk = ($riskScore + ($mlPrediction['conflict_probability'] * 100)) / 2;
```

#### Enhance Auto-Approval Evaluation

Modify `config/auto_approval.php` to include ML risk assessment:

```php
// In evaluateAutoApproval() function
require_once __DIR__ . '/ai_ml_integration.php';

// After rule-based evaluation, add ML assessment
$mlRisk = assessRiskML(
    $facilityId,
    $userId,
    $reservationDate,
    $timeSlot,
    $expectedAttendees,
    $isCommercial,
    $facility,
    $userData
);

// Use ML risk as additional signal
if ($result['eligible'] && $mlRisk['is_low_risk'] && $mlRisk['confidence'] > 0.7) {
    // High confidence ML prediction supports auto-approval
    $result['ml_risk_score'] = $mlRisk['risk_probability'];
    $result['ml_confidence'] = $mlRisk['confidence'];
}
```

## Integration Examples

### Example 1: Booking Form with ML Conflict Detection

```php
// In book_facility.php
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facilityId = (int)$_POST['facility_id'];
    $date = $_POST['reservation_date'];
    $timeSlot = $_POST['start_time'] . ' - ' . $_POST['end_time'];
    
    // Get ML conflict prediction
    $mlConflict = predictConflictML(
        $facilityId,
        $date,
        $timeSlot,
        $_POST['expected_attendees'] ?? null,
        isset($_POST['is_commercial']),
        $facility['capacity']
    );
    
    // Show ML prediction to user
    if ($mlConflict['is_conflict'] && $mlConflict['confidence'] > 0.6) {
        $warning = "ML model predicts high conflict probability (" . 
                   ($mlConflict['conflict_probability'] * 100) . "%)";
        // Display warning
    }
}
```

### Example 2: Chatbot Integration

```php
// In chatbot_api.php
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';

$question = $_POST['question'] ?? $_GET['question'] ?? '';

if ($question) {
    $intent = classifyChatbotIntent($question);
    
    switch ($intent['intent']) {
        case 'list_facilities':
            // Return list of facilities
            $response = getAvailableFacilities();
            break;
            
        case 'book_facility':
            $response = "To book a facility, please visit the booking page...";
            break;
            
        case 'check_availability':
            $response = "Please provide facility name and date to check availability.";
            break;
            
        default:
            $response = "I'm not sure how to help with that. Can you rephrase?";
    }
    
    echo json_encode([
        'intent' => $intent['intent'],
        'response' => $response,
        'confidence' => $intent['confidence'],
    ]);
}
```

## Troubleshooting

### Python Not Found

**Error**: `Python script not found` or `Failed to execute Python script`

**Solution**:
1. Ensure Python is installed and in PATH
2. Update `getPythonPath()` in `config/ai_ml_integration.php` with correct path
3. Test: Run `python --version` from command line

### Model File Not Found

**Error**: `Model file not found`

**Solution**:
1. Train the model: `python scripts/train_[model_name].py`
2. Check model files exist in `ai/models/` directory
3. Verify file permissions

### JSON Parse Errors

**Error**: `Failed to parse Python script output`

**Solution**:
1. Check Python script outputs valid JSON
2. Verify PHP's `json_decode()` is working
3. Check for Python errors in error logs

### Performance Issues

**Issue**: Script execution is slow

**Solutions**:
1. Consider caching ML predictions for identical requests
2. Use async execution for non-critical predictions
3. Consider REST API approach for better performance
4. Optimize Python script startup time

## Best Practices

1. **Graceful Degradation**: Always have fallback to rule-based logic if ML fails
2. **Error Handling**: Check for errors and handle gracefully
3. **Caching**: Cache predictions for identical inputs (when appropriate)
4. **Logging**: Log ML predictions for monitoring and improvement
5. **Testing**: Test ML integration thoroughly before production
6. **Performance**: Monitor execution time and optimize as needed

## Next Steps

1. **REST API**: Consider creating Flask/FastAPI endpoints for better performance
2. **Caching**: Implement Redis/Memcached for prediction caching
3. **Monitoring**: Track ML prediction accuracy and performance
4. **A/B Testing**: Compare ML predictions vs rule-based logic
5. **Continuous Improvement**: Retrain models periodically with new data
