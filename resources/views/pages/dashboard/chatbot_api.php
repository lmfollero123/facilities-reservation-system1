<?php
/**
 * Chatbot API Endpoint
 * Handles chatbot requests and uses ML intent classification
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
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';
require_once __DIR__ . '/../../../../services/RecommendationService.php';

// Load ML integration if available
if (file_exists(__DIR__ . '/../../../../config/ai_ml_integration.php')) {
    require_once __DIR__ . '/../../../../config/ai_ml_integration.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['question'])) {
    $question = $_POST['question'] ?? $_GET['question'] ?? '';
    $question = trim($question);
    
    if (empty($question)) {
        echo json_encode([
            'success' => false,
            'error' => 'Question is required'
        ]);
        exit;
    }
    
    // Classify intent using ML model
    $intentResult = ['intent' => 'unknown', 'confidence' => 0.0];
    if (function_exists('classifyChatbotIntent')) {
        try {
            $intentResult = classifyChatbotIntent($question);
        } catch (Exception $e) {
            error_log("Chatbot intent classification error: " . $e->getMessage());
        }
    }
    
    $intent = $intentResult['intent'] ?? 'unknown';
    $confidence = $intentResult['confidence'] ?? 0.0;
    
    // Fallback: Check for recommendation-related keywords if intent is unknown or low confidence
    $recommendationKeywords = [
        'recommend', 'suggestion', 'best time', 'preferred', 'usual', 'frequently',
        'most reserved', 'my history', 'my pattern', 'my schedule', 'personalized',
        'for me', 'similar to my', 'less crowded', 'quiet', 'my favorite'
    ];
    
    $isRecommendationQuery = false;
    foreach ($recommendationKeywords as $keyword) {
        if (stripos($question, $keyword) !== false) {
            $isRecommendationQuery = true;
            break;
        }
    }
    
    // Override intent if recommendation keywords detected
    if ($isRecommendationQuery && ($intent === 'unknown' || $confidence < 0.5)) {
        $intent = 'get_recommendations';
    }
    
    $pdo = db();
    $response = '';
    $data = null;
    
    // Initialize RecommendationService
    $recommendationService = new RecommendationService($pdo);
    $userId = $_SESSION['user_id'] ?? null;
    
    // Handle different intents
    switch ($intent) {
        case 'get_recommendations':
            if ($userId) {
                $recommendations = $recommendationService->getPersonalizedRecommendations($userId);
                
                if (!empty($recommendations)) {
                    $topRec = $recommendations[0];
                    $response = "Based on your reservation history, I recommend **" . htmlspecialchars($topRec['facility_name']) . "** with a " . $topRec['score'] . "% match score.\n\n";
                    
                    if (isset($topRec['is_fallback']) && $topRec['is_fallback']) {
                        $response .= "Since you're new to our system, I'm showing you popular choices among users.\n\n";
                    }
                    
                    $response .= "**Suggested Schedule:**\n";
                    $response .= "• Date: " . date('l, F j, Y', strtotime($topRec['suggested_date'])) . "\n";
                    $response .= "• Time: " . htmlspecialchars($topRec['suggested_time']) . "\n";
                    $response .= "• Duration: " . $topRec['suggested_duration'] . " hours\n";
                    $response .= "• Expected attendees: " . $topRec['suggested_attendees'] . "\n\n";
                    
                    $response .= "**Why this recommendation:**\n";
                    foreach ($topRec['reasons'] as $reason) {
                        $response .= "• " . htmlspecialchars($reason) . "\n";
                    }
                    
                    if (count($recommendations) > 1) {
                        $response .= "\n**Other options:**\n";
                        for ($i = 1; $i < min(3, count($recommendations)); $i++) {
                            $rec = $recommendations[$i];
                            $response .= "• " . htmlspecialchars($rec['facility_name']) . " (" . $rec['score'] . "% match)\n";
                        }
                    }
                    
                    $response .= "\nWould you like me to help you book this reservation?";
                    $data = ['recommendations' => $recommendations];
                } else {
                    $response = "I don't have enough reservation history to provide personalized recommendations yet. ";
                    $response .= "Start making a few reservations, and I'll be able to suggest the best options for you based on your preferences.\n\n";
                    $response .= "In the meantime, you can check the available facilities on the booking page.";
                }
            } else {
                $response = "Please log in to get personalized recommendations.";
            }
            break;
        case 'list_facilities':
            $stmt = $pdo->query(
                'SELECT id, name, description, capacity, location, status 
                 FROM facilities 
                 WHERE status = "available"
                 ORDER BY name'
            );
            $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response = "Here are the available facilities:\n\n";
            foreach ($facilities as $facility) {
                $response .= "• " . htmlspecialchars($facility['name']);
                if ($facility['location']) {
                    $response .= " (" . htmlspecialchars($facility['location']) . ")";
                }
                $response .= "\n";
            }
            $response .= "\nYou can book any of these facilities through the booking page.";
            $data = ['facilities' => $facilities];
            break;
            
        case 'facility_details':
            // Try to extract facility name from question
            $facilityName = '';
            if (preg_match('/facility\s+([^.?!]+)/i', $question, $matches)) {
                $facilityName = trim($matches[1]);
            }
            
            if ($facilityName) {
                $stmt = $pdo->prepare(
                    'SELECT id, name, description, capacity, amenities, location, status 
                     FROM facilities 
                     WHERE name LIKE :name AND status = "available"
                     LIMIT 1'
                );
                $stmt->execute(['name' => '%' . $facilityName . '%']);
                $facility = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($facility) {
                    $response = "Here's information about " . htmlspecialchars($facility['name']) . ":\n\n";
                    if ($facility['description']) {
                        $response .= htmlspecialchars($facility['description']) . "\n\n";
                    }
                    if ($facility['capacity']) {
                        $response .= "Capacity: " . htmlspecialchars($facility['capacity']) . "\n";
                    }
                    if ($facility['location']) {
                        $response .= "Location: " . htmlspecialchars($facility['location']) . "\n";
                    }
                    if ($facility['amenities']) {
                        $response .= "Amenities: " . htmlspecialchars($facility['amenities']) . "\n";
                    }
                    $data = ['facility' => $facility];
                } else {
                    $response = "I couldn't find a facility matching that name. Please check the facilities list.";
                }
            } else {
                $response = "Which facility would you like to know about? Please mention the facility name.";
            }
            break;
            
        case 'book_facility':
            $response = "To book a facility:\n\n";
            $response .= "1. Go to the 'Book Facility' page\n";
            $response .= "2. Select a facility, date, and time slot\n";
            $response .= "3. Fill in the required information\n";
            $response .= "4. Submit your reservation request\n\n";
            $response .= "Your request will be reviewed and you'll be notified once it's approved.";
            break;
            
        case 'check_availability':
            $response = "To check facility availability:\n\n";
            $response .= "1. Go to the 'Book Facility' page\n";
            $response .= "2. Select a facility and date\n";
            $response .= "3. The system will show you available time slots\n\n";
            $response .= "Or you can view the calendar for each facility on the facilities page.";
            break;
            
        case 'booking_rules':
            $response = "Booking Rules:\n\n";
            $response .= "• Maximum 3 active reservations at a time\n";
            $response .= "• Maximum 30 days of bookings in a 30-day period\n";
            $response .= "• Maximum 1 reservation per day\n";
            $response .= "• Reservations can be made up to 60 days in advance\n";
            $response .= "• Commercial reservations require manual approval\n";
            $response .= "• Users must be verified to make reservations\n\n";
            $response .= "For more details, please check the terms and conditions.";
            break;
            
        case 'cancel_booking':
            $response = "To cancel a booking:\n\n";
            $response .= "1. Go to 'My Reservations'\n";
            $response .= "2. Find the reservation you want to cancel\n";
            $response .= "3. Click the 'Cancel' button\n\n";
            $response .= "Note: Cancellations may be subject to policies. Please check the terms.";
            break;
            
        case 'my_bookings':
            $userId = $_SESSION['user_id'] ?? null;
            if ($userId) {
                $stmt = $pdo->prepare(
                    'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.purpose,
                            f.name as facility_name
                     FROM reservations r
                     JOIN facilities f ON r.facility_id = f.id
                     WHERE r.user_id = :user_id
                     ORDER BY r.reservation_date DESC, r.created_at DESC
                     LIMIT 10'
                );
                $stmt->execute(['user_id' => $userId]);
                $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if ($bookings) {
                    $response = "Here are your recent reservations:\n\n";
                    foreach ($bookings as $booking) {
                        $response .= "• " . htmlspecialchars($booking['facility_name']) . " - ";
                        $response .= date('M j, Y', strtotime($booking['reservation_date'])) . " ";
                        $response .= "(" . htmlspecialchars($booking['time_slot']) . ") - ";
                        $response .= ucfirst($booking['status']) . "\n";
                    }
                    $response .= "\nView all reservations in 'My Reservations' page.";
                    $data = ['bookings' => $bookings];
                } else {
                    $response = "You don't have any reservations yet. Book a facility to get started!";
                }
            } else {
                $response = "Please log in to view your bookings.";
            }
            break;
            
        case 'greeting':
            $response = "Hello! I'm here to help you with facility reservations. ";
            $response .= "You can ask me about available facilities, booking procedures, or your reservations.";
            break;
            
        case 'goodbye':
            $response = "Thank you! If you need more help, just ask. Have a great day!";
            break;
            
        case 'help':
            $response = "I can help you with:\n\n";
            $response .= "• Listing available facilities\n";
            $response .= "• Getting facility details\n";
            $response .= "• Booking procedures\n";
            $response .= "• Checking availability\n";
            $response .= "• Booking rules and policies\n";
            $response .= "• Viewing your reservations\n";
            $response .= "• Cancelling bookings\n";
            $response .= "• Personalized recommendations based on your history\n\n";
            $response .= "Just ask me anything about facility reservations!";
            break;
            
        default:
            $response = "I'm not sure how to help with that. ";
            $response .= "You can ask me about facilities, bookings, or reservations. ";
            $response .= "Type 'help' to see what I can do.";
            break;
    }
    
    echo json_encode([
        'success' => true,
        'intent' => $intent,
        'confidence' => $confidence,
        'response' => $response,
        'data' => $data,
    ]);
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
