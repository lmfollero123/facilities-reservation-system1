<?php
/**
 * Facility Recommendations API
 * Returns ML-based facility recommendations based on user input
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';

header('Content-Type: application/json');

$pdo = db();
$userId = $_SESSION['user_id'] ?? 0;

// Get input parameters
$purpose = trim($_POST['purpose'] ?? $_GET['purpose'] ?? '');
$expectedAttendees = !empty($_POST['expected_attendees']) ? (int)$_POST['expected_attendees'] : (!empty($_GET['expected_attendees']) ? (int)$_GET['expected_attendees'] : 50);
$timeSlot = $_POST['time_slot'] ?? $_GET['time_slot'] ?? '08:00 - 12:00';
$reservationDate = $_POST['reservation_date'] ?? $_GET['reservation_date'] ?? date('Y-m-d');
$isCommercial = isset($_POST['is_commercial']) && $_POST['is_commercial'] === '1';

if (empty($purpose)) {
    echo json_encode(['error' => 'Purpose is required']);
    exit;
}

try {
    // Get user booking count
    $userBookingStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = :user_id');
    $userBookingStmt->execute(['user_id' => $userId]);
    $userBookingCount = (int)$userBookingStmt->fetchColumn();
    
    // Get all available facilities
    $facilitiesStmt = $pdo->query('SELECT id, name, capacity, amenities, status FROM facilities WHERE status = "available" ORDER BY name');
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($facilities)) {
        echo json_encode(['recommendations' => []]);
        exit;
    }
    
    // OPTIMIZED: Try ML-based recommendations if available, but with quick timeout fallback
    if (function_exists('recommendFacilitiesML')) {
        try {
            // Set execution time limit for ML call (max 3 seconds)
            $startTime = microtime(true);
            $mlTimeLimit = 3.0; // seconds
            
            $recommendations = recommendFacilitiesML(
                facilities: $facilities,
                userId: $userId,
                purpose: $purpose,
                expectedAttendees: $expectedAttendees,
                timeSlot: $timeSlot,
                reservationDate: $reservationDate,
                isCommercial: $isCommercial,
                userBookingCount: $userBookingCount,
                limit: 5
            );
            
            $mlTime = microtime(true) - $startTime;
            
            // Check if ML call took too long or errored - fallback to rule-based
            if ($mlTime > $mlTimeLimit || isset($recommendations['error'])) {
                error_log("ML recommendations too slow ({$mlTime}s) or error - using rule-based fallback");
                // Fall through to rule-based recommendations below
            } elseif (!empty($recommendations['recommendations'])) {
                // ML succeeded quickly and returned results
                echo json_encode([
                    'recommendations' => $recommendations['recommendations'],
                    'ml_enabled' => true,
                    'ml_time' => round($mlTime, 2)
                ]);
                exit;
            }
        } catch (Exception $e) {
            error_log("Facility recommendation ML exception: " . $e->getMessage());
            // Fall through to rule-based recommendations
        } catch (Throwable $e) {
            error_log("Facility recommendation ML fatal error: " . $e->getMessage());
            // Fall through to rule-based recommendations
        }
    }
    
    error_log("Using rule-based recommendations fallback");
    
    // Fallback: Rule-based recommendations (simple keyword matching)
    $purposeLower = strtolower($purpose);
    $scoredFacilities = [];
    
    foreach ($facilities as $facility) {
        $score = 0.0;
        $reasons = [];
        
        // Simple keyword matching
        if (stripos($purposeLower, 'sports') !== false || stripos($purposeLower, 'basketball') !== false || stripos($purposeLower, 'volleyball') !== false) {
            if (stripos($facility['amenities'] ?? '', 'court') !== false || stripos($facility['name'], 'sports') !== false) {
                $score += 2.0;
                $reasons[] = 'Matches sports/athletic activities';
            }
        }
        
        if (stripos($purposeLower, 'meeting') !== false || stripos($purposeLower, 'assembly') !== false || stripos($purposeLower, 'conference') !== false) {
            if (stripos($facility['amenities'] ?? '', 'conference') !== false || stripos($facility['name'], 'hall') !== false) {
                $score += 2.0;
                $reasons[] = 'Suitable for meetings/conferences';
            }
        }
        
        if (stripos($purposeLower, 'celebration') !== false || stripos($purposeLower, 'party') !== false || stripos($purposeLower, 'wedding') !== false) {
            if (stripos($facility['name'], 'hall') !== false || stripos($facility['amenities'] ?? '', 'event') !== false) {
                $score += 2.0;
                $reasons[] = 'Great for celebrations/events';
            }
        }
        
        // Capacity matching
        $capacity = (int)filter_var($facility['capacity'] ?? '100', FILTER_SANITIZE_NUMBER_INT);
        if ($capacity >= $expectedAttendees * 0.8 && $capacity <= $expectedAttendees * 1.5) {
            $score += 1.0;
            $reasons[] = 'Capacity matches expected attendees';
        }
        
        $scoredFacilities[] = [
            'id' => $facility['id'],
            'name' => $facility['name'],
            'capacity' => $facility['capacity'],
            'amenities' => $facility['amenities'],
            'ml_relevance_score' => $score,
            'reason' => !empty($reasons) ? implode('; ', $reasons) : 'General purpose facility'
        ];
    }
    
    // Sort by score (descending)
    usort($scoredFacilities, function($a, $b) {
        return $b['ml_relevance_score'] <=> $a['ml_relevance_score'];
    });
    
    echo json_encode([
        'recommendations' => array_slice($scoredFacilities, 0, 5),
        'ml_enabled' => false
    ]);
    
} catch (Exception $e) {
    error_log("Facility recommendation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to get recommendations']);
}
