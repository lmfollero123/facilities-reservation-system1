<?php
/**
 * Chatbot Response Variations
 * Provides human-like, varied responses for different intents
 */

/**
 * Get a random response from an array of variations
 */
function getRandomResponse(array $responses): string {
    return $responses[array_rand($responses)];
}

/**
 * Get greeting responses
 */
function getGreetingResponses(string $userName): array {
    return [
        "Hi {$userName}! 👋 Great to see you! How can I help you today?",
        "Hello {$userName}! 😊 What can I assist you with regarding facility reservations?",
        "Hey {$userName}! Nice to chat with you. What do you need help with?",
        "Hi there, {$userName}! Ready to help you with any facility booking questions.",
        "Hello! Welcome back, {$userName}. What would you like to know today?",
    ];
}

/**
 * Get goodbye responses
 */
function getGoodbyeResponses(): array {
    return [
        "Take care! If you need anything else, I'm here to help! 👋",
        "Have a wonderful day! Feel free to come back if you have more questions!",
        "Goodbye! Hope your facility booking goes smoothly! 😊",
        "See you later! Don't hesitate to ask if you need help with anything!",
        "Thanks for chatting! Wishing you the best with your reservations!",
    ];
}

/**
 * Get help responses
 */
function getHelpResponses(): array {
    return [
        "Sure thing! I'm here to help with:\n\n" .
        "• 📍 Finding and listing available facilities\n" .
        "• 📋 Understanding booking policies and rules\n" .
        "• 📅 Viewing and managing your reservations\n" .
        "• ✅ Checking availability for specific dates\n" .
        "• 🎫 Booking procedures and requirements\n" .
        "• ❌ Canceling or rescheduling bookings\n\n" .
        "What would you like to know more about?",
        
        "I'd be happy to help! Here's what I can assist you with:\n\n" .
        "• Browse and explore available facilities\n" .
        "• Learn about our booking policies\n" .
        "• Check your current reservations\n" .
        "• See what's available on specific dates\n" .
        "• Get guidance on how to make bookings\n" .
        "• Help with canceling reservations\n\n" .
        "Just ask me anything!",
        
        "Of course! I can help you with facility reservations. Here's what I know about:\n\n" .
        "🏢 Facilities - Find and learn about available spaces\n" .
        "📜 Policies - Understand booking rules and limits\n" .
        "📋 Your Bookings - View your reservation history\n" .
        "📅 Availability - Check dates and time slots\n" .
        "✍️ Booking Process - Guide you through making reservations\n" .
        "🔄 Changes - Help with modifications or cancellations\n\n" .
        "What would you like to explore?",
    ];
}

/**
 * Get fallback/unknown responses
 */
function getUnknownResponses(string $message): array {
    $msgLower = strtolower(trim($message));
    
    // Handle simple acknowledgments
    $acknowledgments = ['ok', 'okay', 'k', 'kk', 'alright', 'alrighty', 'sure', 'yep', 'yes', 'yeah', 'got it', 'gotcha', 'thanks', 'thank you', 'ty'];
    if (in_array($msgLower, $acknowledgments) || in_array($msgLower, array_map(function($a) { return $a . '!'; }, $acknowledgments))) {
        return [
            "You're welcome! Is there anything else I can help you with?",
            "Glad I could help! What else would you like to know?",
            "Sure thing! What can I assist you with regarding facility bookings?",
            "No problem! Feel free to ask me anything about facilities or reservations.",
        ];
    }
    
    // Handle inappropriate content (use word boundaries to avoid false positives like "hello")
    $inappropriate = ['\bfuck\b', '\bdamn\b', '\bshit\b', '\bhell\b'];
    foreach ($inappropriate as $pattern) {
        if (preg_match('/' . $pattern . '/i', $msgLower)) {
            return [
                "I understand you might be frustrated. Is there something specific about facility bookings I can help you with?",
                "I'm here to help with facility reservations. Could you rephrase your question?",
                "Let me help you with facility bookings. What would you like to know?",
            ];
        }
    }
    
    return [
        "Hmm, I'm not quite sure what you're asking about. Could you rephrase that? I'm best at helping with:\n• Facility availability\n• Booking policies\n• Your reservations\n\nWhat would you like to know?",
        
        "I want to make sure I understand you correctly. Could you try asking about facilities, bookings, or your reservations?",
        
        "I'm not entirely sure how to help with that. I'm really good at answering questions about:\n• Available facilities\n• Booking rules and policies\n• Your reservation status\n\nTry asking something like 'What facilities are available?' or 'Show my bookings'",
        
        "That's an interesting question! I'm focused on helping with facility reservations. Could you ask about:\n• Finding facilities\n• Booking policies\n• Your reservations\n\nWhat can I help you with?",
    ];
}

/**
 * Get empty response responses
 */
function getEmptyMessageResponses(): array {
    return [
        "I'd love to help, but I didn't catch that. Could you type your question?",
        "It looks like your message might be empty. What can I help you with?",
        "I'm ready to help! Just type your question about facilities or bookings.",
    ];
}

/**
 * Get facility listing responses (when facilities found)
 */
function getFacilitiesFoundResponses(): array {
    return [
        "\n\nYou can view more details and make a booking on the 'Book Facility' page. Need help with anything else?",
        "\n\nFeel free to browse these facilities and book one that suits your needs! Questions?",
        "\n\nAll of these are available for booking. Want to know more about any specific facility?",
    ];
}

/**
 * Get no facilities responses
 */
function getNoFacilitiesResponses(): array {
    return [
        "I'm sorry, but there are currently no facilities available for booking. Please check back later!",
        "Unfortunately, all facilities are currently unavailable. We recommend checking again in a few days.",
        "Right now, there aren't any facilities available. Keep an eye out for updates!",
    ];
}

/**
 * Get booking rules responses
 */
function getBookingRulesResponses(): array {
    return [
        "Here are our booking policies:\n\n" .
        "✅ All facilities are FREE for residents\n" .
        "📊 Maximum of 3 active reservations per user\n" .
        "⏱️ Bookings require administrator approval\n" .
        "📅 Only one booking per day is allowed\n" .
        "🔄 Rescheduling is allowed up to 3 days before the event\n\n" .
        "Need clarification on any of these?",
        
        "Sure! Here's what you need to know:\n\n" .
        "💰 Cost: Completely free for residents!\n" .
        "📈 Limits: Up to 3 active reservations\n" .
        "✅ Process: Requires admin approval\n" .
        "📆 Daily Limit: One booking per day\n" .
        "🔄 Changes: Can reschedule up to 3 days before\n\n" .
        "Anything else you'd like to know?",
    ];
}

/**
 * Get my bookings responses (when bookings found)
 */
function getBookingsFoundResponses(): array {
    return [
        "\n\nYou can view all your reservations and manage them on the 'My Reservations' page. Need help with anything specific?",
        "\n\nWant to modify or cancel any of these? Head to 'My Reservations' for more options!",
        "\n\nThese are your recent bookings. Check 'My Reservations' for the full list and to make changes.",
    ];
}

/**
 * Get no bookings responses
 */
function getNoBookingsResponses(): array {
    return [
        "You don't have any reservations yet. Ready to book your first facility? I can help guide you through the process!",
        "No bookings found in your account. Would you like to know how to make a reservation?",
        "Your reservation list is empty. Want to get started with booking a facility?",
    ];
}

/**
 * Get check availability responses
 */
function getCheckAvailabilityResponses(): array {
    return [
        "To check availability, head to the 'Book Facility' page and select your preferred date and time. The system will show you all available slots instantly!",
        "You can check availability by going to 'Book Facility' page. Just pick a date and see what's open!",
        "Easy! Visit the 'Book Facility' page, choose a date, and you'll see all available time slots right away.",
    ];
}

/**
 * Get book facility responses
 */
function getBookFacilityResponses(): array {
    return [
        "Great! To book a facility:\n1. Go to 'Book Facility' from your dashboard\n2. Select a facility, date, and time slot\n3. Fill in the details and submit\n\nYour request will be reviewed by our team. Need help with any step?",
        "Booking is simple! Navigate to 'Book Facility', pick your preferred facility and time, then complete the form. Our team will review it and get back to you!",
        "Ready to book? Head to the 'Book Facility' page, choose your facility and preferred date/time, fill in the form, and submit. We'll process your request!",
    ];
}

/**
 * Get cancel booking responses
 */
function getCancelBookingResponses(): array {
    return [
        "To cancel, go to 'My Reservations', find the booking you want to cancel, and click 'Cancel'. Note that cancellation policies may apply depending on timing.",
        "You can cancel by visiting 'My Reservations', locating your booking, and using the cancel option. Keep in mind there may be cancellation policies to consider.",
        "Head to 'My Reservations' to cancel any booking. Just find it in your list and click cancel. Be aware of any applicable cancellation policies!",
    ];
}
