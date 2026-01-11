<?php
/**
 * AI Models Testing Page
 * Tests all available ML models and their integration
 */

// Handle AJAX requests FIRST before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['test'])) {
    // Start session for AJAX requests
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    require_once __DIR__ . '/../../../../config/database.php';
    require_once __DIR__ . '/../../../../config/ai_ml_integration.php';
    
    // Suppress any output before JSON
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    
    $testType = $_GET['test'];
    $inputJson = file_get_contents('php://input');
    $input = json_decode($inputJson, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input: ' . json_last_error_msg()]);
        exit;
    }
    
    $pdo = db();
    $userId = $_SESSION['user_id'] ?? 1;
    
    try {
        switch ($testType) {
            case 'conflict':
                if (!function_exists('predictConflictML')) {
                    echo json_encode(['success' => false, 'error' => 'Function predictConflictML not available']);
                    exit;
                }
                
                $result = predictConflictML(
                    $input['facility_id'],
                    $input['reservation_date'],
                    $input['time_slot'],
                    $input['expected_attendees'],
                    $input['is_commercial'],
                    $input['capacity']
                );
                
                echo json_encode([
                    'success' => !isset($result['error']),
                    'result' => $result,
                    'stderr' => $result['stderr'] ?? null
                ]);
                break;
                
            case 'risk':
                if (!function_exists('assessRiskML')) {
                    echo json_encode(['success' => false, 'error' => 'Function assessRiskML not available']);
                    exit;
                }
                
                // Get facility and user data
                $facilityStmt = $pdo->prepare('SELECT * FROM facilities WHERE id = :id LIMIT 1');
                $facilityStmt->execute(['id' => $input['facility_id']]);
                $facilityData = $facilityStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                $userStmt = $pdo->prepare('SELECT id, is_verified, (SELECT COUNT(*) FROM reservations WHERE user_id = users.id) as booking_count, 0 as violation_count FROM users WHERE id = :id LIMIT 1');
                $userStmt->execute(['id' => $input['user_id']]);
                $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                $result = assessRiskML(
                    $input['facility_id'],
                    $input['user_id'],
                    $input['reservation_date'],
                    $input['time_slot'],
                    $input['expected_attendees'],
                    $input['is_commercial'],
                    $facilityData,
                    $userData
                );
                
                echo json_encode([
                    'success' => !isset($result['error']),
                    'result' => $result,
                    'stderr' => $result['stderr'] ?? null
                ]);
                break;
                
            case 'chatbot':
                if (!function_exists('classifyChatbotIntent')) {
                    echo json_encode(['success' => false, 'error' => 'Function classifyChatbotIntent not available']);
                    exit;
                }
                
                $results = [];
                foreach ($input['messages'] as $message) {
                    $result = classifyChatbotIntent($message);
                    $results[] = [
                        'message' => $message,
                        'intent' => $result['intent'] ?? 'unknown',
                        'confidence' => $result['confidence'] ?? 0.0,
                        'error' => $result['error'] ?? null
                    ];
                }
                
                echo json_encode([
                    'success' => true,
                    'results' => $results
                ]);
                break;
                
            case 'purpose_category':
                if (!function_exists('classifyPurposeCategory')) {
                    echo json_encode(['success' => false, 'error' => 'Function classifyPurposeCategory not available']);
                    exit;
                }
                
                $results = [];
                foreach ($input['purposes'] as $purpose) {
                    $result = classifyPurposeCategory($purpose);
                    $results[] = [
                        'purpose' => $purpose,
                        'category' => $result['category'] ?? 'private',
                        'confidence' => $result['confidence'] ?? 0.0,
                        'error' => $result['error'] ?? null
                    ];
                }
                
                echo json_encode([
                    'success' => true,
                    'results' => $results
                ]);
                break;
                
            case 'purpose_unclear':
                if (!function_exists('detectUnclearPurpose')) {
                    echo json_encode(['success' => false, 'error' => 'Function detectUnclearPurpose not available']);
                    exit;
                }
                
                $results = [];
                foreach ($input['purposes'] as $purpose) {
                    $result = detectUnclearPurpose($purpose);
                    $results[] = [
                        'purpose' => $purpose,
                        'is_unclear' => $result['is_unclear'] ?? true,
                        'probability' => $result['probability'] ?? 0.5,
                        'confidence' => $result['confidence'] ?? 0.0,
                        'error' => $result['error'] ?? null
                    ];
                }
                
                echo json_encode([
                    'success' => true,
                    'results' => $results
                ]);
                break;
                
            case 'facility_recommendation':
                if (!function_exists('recommendFacilitiesML')) {
                    echo json_encode(['success' => false, 'error' => 'Function recommendFacilitiesML not available']);
                    exit;
                }
                
                // Get facilities from database
                $facilitiesStmt = $pdo->query('SELECT id, name, capacity, amenities, status FROM facilities WHERE status = "available" LIMIT 10');
                $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $result = recommendFacilitiesML(
                    facilities: $facilities,
                    userId: $input['user_id'] ?? $userId,
                    purpose: $input['purpose'] ?? 'Community meeting',
                    expectedAttendees: $input['expected_attendees'] ?? 50,
                    timeSlot: $input['time_slot'] ?? '08:00 - 12:00',
                    reservationDate: $input['reservation_date'] ?? date('Y-m-d', strtotime('+7 days')),
                    isCommercial: $input['is_commercial'] ?? false,
                    userBookingCount: $input['user_booking_count'] ?? 0,
                    limit: 5
                );
                
                echo json_encode([
                    'success' => !isset($result['error']),
                    'result' => $result
                ]);
                break;
                
            case 'demand_forecasting':
                if (!function_exists('forecastDemandML')) {
                    echo json_encode(['success' => false, 'error' => 'Function forecastDemandML not available']);
                    exit;
                }
                
                $results = [];
                $testDates = [
                    date('Y-m-d', strtotime('+7 days')),
                    date('Y-m-d', strtotime('+14 days')),
                    date('Y-m-d', strtotime('+30 days')),
                ];
                
                foreach ($testDates as $date) {
                    $result = forecastDemandML(
                        facilityId: $input['facility_id'] ?? 1,
                        date: $date,
                        historicalData: null
                    );
                    $results[] = [
                        'date' => $date,
                        'facility_id' => $input['facility_id'] ?? 1,
                        'predicted_count' => $result['predicted_count'] ?? 0.0,
                        'confidence' => $result['confidence'] ?? 0.0,
                        'error' => $result['error'] ?? null
                    ];
                }
                
                echo json_encode([
                    'success' => true,
                    'results' => $results
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Unknown test type']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } catch (Error $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    exit;
}

// Regular page load (not AJAX)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['user_authenticated'] ?? false)) {
    require_once __DIR__ . '/../../../../config/app.php';
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';

$pageTitle = 'AI Models Testing | LGU Facilities Reservation';
$pdo = db();
$userId = $_SESSION['user_id'] ?? 1;

ob_start();
?>

<style>
.test-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}
.test-section {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.test-section h2 {
    margin-top: 0;
    color: #1f3a5f;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 0.5rem;
}
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
}
.status-available {
    background: #d1fae5;
    color: #065f46;
}
.status-unavailable {
    background: #fee2e2;
    color: #991b1b;
}
.status-integrated {
    background: #dbeafe;
    color: #1e40af;
}
.test-result {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 6px;
    background: #f9fafb;
    border-left: 4px solid #3b82f6;
}
.test-result.success {
    border-left-color: #10b981;
    background: #ecfdf5;
}
.test-result.error {
    border-left-color: #ef4444;
    background: #fef2f2;
}
.test-button {
    background: #1f3a5f;
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: 1rem;
    margin-top: 1rem;
}
.test-button:hover {
    background: #1e3a8a;
}
.model-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}
.model-card {
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background: #f9fafb;
}
.model-card h3 {
    margin-top: 0;
    font-size: 1.125rem;
}
pre {
    background: #1f2937;
    color: #f9fafb;
    padding: 1rem;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 0.875rem;
}
</style>

<div class="test-container">
    <h1>🤖 AI Models Testing & Status</h1>
    <p>Test all available ML models and verify their integration status.</p>

    <?php
    // Get model status
    $modelStatus = checkMLModelsStatus();
    
    // Define integration status
    $integrationStatus = [
        'conflict_detection' => true,  // Integrated in ai_helpers.php
        'auto_approval_risk' => true,  // Integrated in auto_approval.php
        'chatbot_intent' => true,      // Integrated in ai_chatbot.php
        'purpose_category' => true,    // Integrated in book_facility.php
        'purpose_unclear' => true,     // Integrated in book_facility.php
        'facility_recommendation' => true,  // Integrated in book_facility.php (facility_recommendations_api.php)
        'demand_forecasting' => true,  // Integrated (API available, can be used in scheduling pages)
    ];
    ?>

    <!-- Model Status Overview -->
    <div class="test-section">
        <h2>📊 Model Status Overview</h2>
        <div class="model-info">
            <?php foreach ($modelStatus as $modelName => $status): ?>
                <?php
                $isIntegrated = $integrationStatus[$modelName] ?? false;
                $isAvailable = $status['available'] ?? false;
                ?>
                <div class="model-card">
                    <h3><?= ucfirst(str_replace('_', ' ', $modelName)) ?></h3>
                    <div>
                        <span class="status-badge <?= $isAvailable ? 'status-available' : 'status-unavailable' ?>">
                            <?= $isAvailable ? '✓ Available' : '✗ Not Available' ?>
                        </span>
                        <?php if ($isIntegrated): ?>
                            <span class="status-badge status-integrated" style="margin-left: 0.5rem;">
                                ✓ Integrated
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-unavailable" style="margin-left: 0.5rem;">
                                ⚠ Not Integrated
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isAvailable): ?>
                        <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">
                            Size: <?= number_format(($status['size'] ?? 0) / 1024, 2) ?> KB
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Test 1: Conflict Detection -->
    <div class="test-section">
        <h2>1. Conflict Detection Model</h2>
        <p><strong>Status:</strong> 
            <span class="status-badge <?= ($integrationStatus['conflict_detection'] && $modelStatus['conflict_detection']['available']) ? 'status-integrated' : 'status-unavailable' ?>">
                <?= ($integrationStatus['conflict_detection'] && $modelStatus['conflict_detection']['available']) ? 'Integrated & Available' : 'Not Ready' ?>
            </span>
        </p>
        <p><strong>Integration:</strong> Used in booking conflict detection (<code>config/ai_helpers.php</code>)</p>
        
        <?php if ($modelStatus['conflict_detection']['available'] && function_exists('predictConflictML')): ?>
            <button class="test-button" onclick="testConflictDetection()">Run Test</button>
            <div id="conflict-result"></div>
        <?php else: ?>
            <p style="color: #991b1b;">Model not available or function not loaded.</p>
        <?php endif; ?>
    </div>

    <!-- Test 2: Auto-Approval Risk -->
    <div class="test-section">
        <h2>2. Auto-Approval Risk Assessment</h2>
        <p><strong>Status:</strong>
            <span class="status-badge <?= ($integrationStatus['auto_approval_risk'] && $modelStatus['auto_approval_risk']['available']) ? 'status-integrated' : 'status-unavailable' ?>">
                <?= ($integrationStatus['auto_approval_risk'] && $modelStatus['auto_approval_risk']['available']) ? 'Integrated & Available' : 'Not Ready' ?>
            </span>
        </p>
        <p><strong>Integration:</strong> Used in auto-approval evaluation (<code>config/auto_approval.php</code>)</p>
        
        <?php if ($modelStatus['auto_approval_risk']['available'] && function_exists('assessRiskML')): ?>
            <button class="test-button" onclick="testRiskAssessment()">Run Test</button>
            <div id="risk-result"></div>
        <?php else: ?>
            <p style="color: #991b1b;">Model not available or function not loaded.</p>
        <?php endif; ?>
    </div>

    <!-- Test 3: Chatbot Intent Classification -->
    <div class="test-section">
        <h2>3. Chatbot Intent Classification</h2>
        <p><strong>Status:</strong>
            <span class="status-badge <?= ($integrationStatus['chatbot_intent'] && $modelStatus['chatbot_intent']['available']) ? 'status-integrated' : 'status-unavailable' ?>">
                <?= ($integrationStatus['chatbot_intent'] && $modelStatus['chatbot_intent']['available']) ? 'Integrated & Available' : 'Not Ready' ?>
            </span>
        </p>
        <p><strong>Integration:</strong> Used in chatbot responses (<code>resources/views/pages/dashboard/ai_chatbot.php</code>)</p>
        
        <?php if ($modelStatus['chatbot_intent']['available'] && function_exists('classifyChatbotIntent')): ?>
            <button class="test-button" onclick="testChatbotIntent()">Run Test</button>
            <div id="chatbot-result"></div>
        <?php else: ?>
            <p style="color: #991b1b;">Model not available or function not loaded.</p>
        <?php endif; ?>
    </div>

    <!-- Test 4: Purpose Category Classification -->
    <div class="test-section">
        <h2>4. Purpose Category Classification</h2>
        <p><strong>Status:</strong>
            <span class="status-badge <?= ($integrationStatus['purpose_category'] && $modelStatus['purpose_category']['available']) ? 'status-integrated' : 'status-unavailable' ?>">
                <?= ($integrationStatus['purpose_category'] && $modelStatus['purpose_category']['available']) ? 'Integrated & Available' : 'Not Ready' ?>
            </span>
        </p>
        <p><strong>Integration:</strong> Used in reservation booking (<code>resources/views/pages/dashboard/book_facility.php</code>)</p>
        
        <?php if ($modelStatus['purpose_category']['available'] && function_exists('classifyPurposeCategory')): ?>
            <button class="test-button" onclick="testPurposeCategory()">Run Test</button>
            <div id="purpose-category-result"></div>
        <?php else: ?>
            <p style="color: #991b1b;">Model not available or function not loaded.</p>
        <?php endif; ?>
    </div>

    <!-- Test 5: Purpose Unclear Detection -->
    <div class="test-section">
        <h2>5. Purpose Unclear Detection</h2>
        <p><strong>Status:</strong>
            <span class="status-badge <?= ($integrationStatus['purpose_unclear'] && $modelStatus['purpose_unclear']['available']) ? 'status-integrated' : 'status-unavailable' ?>">
                <?= ($integrationStatus['purpose_unclear'] && $modelStatus['purpose_unclear']['available']) ? 'Integrated & Available' : 'Not Ready' ?>
            </span>
        </p>
        <p><strong>Integration:</strong> Used in reservation booking (<code>resources/views/pages/dashboard/book_facility.php</code>)</p>
        
        <?php if ($modelStatus['purpose_unclear']['available'] && function_exists('detectUnclearPurpose')): ?>
            <button class="test-button" onclick="testPurposeUnclear()">Run Test</button>
            <div id="purpose-unclear-result"></div>
        <?php else: ?>
            <p style="color: #991b1b;">Model not available or function not loaded.</p>
        <?php endif; ?>
    </div>

    <!-- Test 6: Facility Recommendation -->
    <div class="test-section">
        <h2>6. Facility Recommendation</h2>
        <p><strong>Status:</strong>
            <span class="status-badge <?= ($integrationStatus['facility_recommendation'] && $modelStatus['facility_recommendation']['available']) ? 'status-integrated' : 'status-unavailable' ?>">
                <?= ($integrationStatus['facility_recommendation'] && $modelStatus['facility_recommendation']['available']) ? 'Integrated & Available' : ($integrationStatus['facility_recommendation'] ? 'Integrated (Needs Training)' : 'Not Ready') ?>
            </span>
        </p>
        <p><strong>Integration:</strong> Used in booking form recommendations (<code>resources/views/pages/dashboard/book_facility.php</code>)</p>
        <p><small>Note: Requires 5+ approved reservations to train. Falls back to rule-based recommendations if model not available.</small></p>
        
        <?php if (function_exists('recommendFacilitiesML')): ?>
            <button class="test-button" onclick="testFacilityRecommendation()">Run Test</button>
            <div id="facility-recommendation-result"></div>
        <?php else: ?>
            <p style="color: #991b1b;">Function not available.</p>
        <?php endif; ?>
    </div>

    <!-- Test 7: Demand Forecasting -->
    <div class="test-section">
        <h2>7. Demand Forecasting</h2>
        <p><strong>Status:</strong>
            <span class="status-badge <?= ($integrationStatus['demand_forecasting'] && $modelStatus['demand_forecasting']['available']) ? 'status-integrated' : 'status-unavailable' ?>">
                <?= ($integrationStatus['demand_forecasting'] && $modelStatus['demand_forecasting']['available']) ? 'Integrated & Available' : ($integrationStatus['demand_forecasting'] ? 'Integrated (Needs Training)' : 'Not Ready') ?>
            </span>
        </p>
        <p><strong>Integration:</strong> API available for scheduling pages (<code>config/ai_ml_integration.php</code>)</p>
        <p><small>Note: Requires 30+ reservations to train. Can be integrated into Smart Scheduler page.</small></p>
        
        <?php if (function_exists('forecastDemandML')): ?>
            <button class="test-button" onclick="testDemandForecasting()">Run Test</button>
            <div id="demand-forecasting-result"></div>
        <?php else: ?>
            <p style="color: #991b1b;">Function not available.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function testConflictDetection() {
    const resultDiv = document.getElementById('conflict-result');
    resultDiv.innerHTML = '<div class="test-result">Testing... Please wait.</div>';
    
    fetch('<?= base_path(); ?>/resources/views/pages/dashboard/test_ai_models.php?test=conflict', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            facility_id: 1,
            reservation_date: '2026-02-15',
            time_slot: '08:00 - 12:00',
            expected_attendees: 50,
            is_commercial: false,
            capacity: '200'
        })
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = `<div class="test-result ${data.success ? 'success' : 'error'}">
            <pre>${JSON.stringify(data, null, 2)}</pre>
        </div>`;
    })
    .catch(err => {
        resultDiv.innerHTML = `<div class="test-result error">Error: ${err.message}</div>`;
    });
}

function testRiskAssessment() {
    const resultDiv = document.getElementById('risk-result');
    resultDiv.innerHTML = '<div class="test-result">Testing... Please wait.</div>';
    
    fetch('<?= base_path(); ?>/resources/views/pages/dashboard/test_ai_models.php?test=risk', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            facility_id: 1,
            user_id: <?= $userId; ?>,
            reservation_date: '2026-02-15',
            time_slot: '08:00 - 12:00',
            expected_attendees: 50,
            is_commercial: false
        })
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = `<div class="test-result ${data.success ? 'success' : 'error'}">
            <pre>${JSON.stringify(data, null, 2)}</pre>
        </div>`;
    })
    .catch(err => {
        resultDiv.innerHTML = `<div class="test-result error">Error: ${err.message}</div>`;
    });
}

function testChatbotIntent() {
    const resultDiv = document.getElementById('chatbot-result');
    resultDiv.innerHTML = '<div class="test-result">Testing... Please wait.</div>';
    
    const testMessages = [
        'What facilities are available?',
        'Show my bookings',
        'Hello',
        'What are the booking policies?'
    ];
    
    fetch('<?= base_path(); ?>/resources/views/pages/dashboard/test_ai_models.php?test=chatbot', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({messages: testMessages})
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = `<div class="test-result ${data.success ? 'success' : 'error'}">
            <pre>${JSON.stringify(data, null, 2)}</pre>
        </div>`;
    })
    .catch(err => {
        resultDiv.innerHTML = `<div class="test-result error">Error: ${err.message}</div>`;
    });
}

function testPurposeCategory() {
    const resultDiv = document.getElementById('purpose-category-result');
    resultDiv.innerHTML = '<div class="test-result">Testing... Please wait.</div>';
    
    const testPurposes = [
        'Barangay General Assembly',
        'Basketball Tournament',
        'Zumba Fitness Class',
        'Wedding Reception',
        'Community Meeting',
        'Private Party'
    ];
    
    fetch('<?= base_path(); ?>/resources/views/pages/dashboard/test_ai_models.php?test=purpose_category', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({purposes: testPurposes})
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = `<div class="test-result ${data.success ? 'success' : 'error'}">
            <pre>${JSON.stringify(data, null, 2)}</pre>
        </div>`;
    })
    .catch(err => {
        resultDiv.innerHTML = `<div class="test-result error">Error: ${err.message}</div>`;
    });
}

function testPurposeUnclear() {
    const resultDiv = document.getElementById('purpose-unclear-result');
    resultDiv.innerHTML = '<div class="test-result">Testing... Please wait.</div>';
    
    const testPurposes = [
        'Barangay General Assembly for community development',
        'test',
        'asdf',
        'Wedding celebration with family and friends',
        'gg',
        'Community health seminar and workshop'
    ];
    
    fetch('<?= base_path(); ?>/resources/views/pages/dashboard/test_ai_models.php?test=purpose_unclear', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({purposes: testPurposes})
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = `<div class="test-result ${data.success ? 'success' : 'error'}">
            <pre>${JSON.stringify(data, null, 2)}</pre>
        </div>`;
    })
    .catch(err => {
        resultDiv.innerHTML = `<div class="test-result error">Error: ${err.message}</div>`;
    });
}

function testFacilityRecommendation() {
    const resultDiv = document.getElementById('facility-recommendation-result');
    resultDiv.innerHTML = '<div class="test-result">Testing... Please wait.</div>';
    
    fetch('<?= base_path(); ?>/resources/views/pages/dashboard/test_ai_models.php?test=facility_recommendation', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            purpose: 'Community General Assembly',
            expected_attendees: 100,
            time_slot: '08:00 - 12:00',
            reservation_date: '<?= date('Y-m-d', strtotime('+7 days')); ?>',
            is_commercial: false
        })
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = `<div class="test-result ${data.success ? 'success' : 'error'}">
            <pre>${JSON.stringify(data, null, 2)}</pre>
        </div>`;
    })
    .catch(err => {
        resultDiv.innerHTML = `<div class="test-result error">Error: ${err.message}</div>`;
    });
}

function testDemandForecasting() {
    const resultDiv = document.getElementById('demand-forecasting-result');
    resultDiv.innerHTML = '<div class="test-result">Testing... Please wait.</div>';
    
    fetch('<?= base_path(); ?>/resources/views/pages/dashboard/test_ai_models.php?test=demand_forecasting', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            facility_id: 1
        })
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = `<div class="test-result ${data.success ? 'success' : 'error'}">
            <pre>${JSON.stringify(data, null, 2)}</pre>
        </div>`;
    })
    .catch(err => {
        resultDiv.innerHTML = `<div class="test-result error">Error: ${err.message}</div>`;
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
?>
