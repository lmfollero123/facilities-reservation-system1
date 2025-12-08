<?php
/**
 * AI Facility Recommendations API Endpoint
 * Returns JSON response with recommended facilities
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['user_authenticated'] ?? false)) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['purpose'])) {
    $purpose = $_POST['purpose'] ?? $_GET['purpose'] ?? '';
    $purpose = trim($purpose);
    
    // Get user ID for proximity-based recommendations
    $userId = $_SESSION['user_id'] ?? null;
    
    if (strlen($purpose) >= 3) {
        $recommendations = recommendFacilities($purpose, null, null, $userId, 5);
        echo json_encode([
            'success' => true,
            'recommendations' => $recommendations,
            'count' => count($recommendations)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter at least 3 characters'
        ]);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}


