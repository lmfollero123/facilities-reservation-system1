<?php
/**
 * AI Helper Functions for Conflict Detection and Facility Recommendation
 * 
 * These functions provide intelligent features for the reservation system:
 * - Conflict Detection: Checks for potential booking conflicts
 * - Facility Recommendation: Suggests best facilities based on requirements
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/time_helpers.php';

// Load ML integration if available
if (file_exists(__DIR__ . '/ai_ml_integration.php')) {
    require_once __DIR__ . '/ai_ml_integration.php';
}

/**
 * Detect potential conflicts for a reservation
 * 
 * BEST PRACTICE: Only APPROVED reservations create hard conflicts (block slot).
 * PENDING reservations are soft conflicts (warning shown, but booking allowed).
 * Multiple PENDING reservations are allowed for the same slot - admin decides which to approve.
 * 
 * @param int $facilityId Facility ID
 * @param string $date Reservation date (Y-m-d format)
 * @param string $timeSlot Time slot (e.g., "08:00 - 12:00")
 * @param int|null $excludeReservationId Reservation ID to exclude from conflict check (for updates)
 * @return array Conflict information with 'has_conflict' (hard), 'soft_conflicts', 'risk_score', 'alternatives'
 */
function detectBookingConflict($facilityId, $date, $timeSlot, $excludeReservationId = null) {
    $pdo = db();
    
    // Get APPROVED reservations (hard conflicts - block slot)
    $approvedReservationsStmt = $pdo->prepare(
        'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.purpose, r.priority_level,
                f.name AS facility_name, u.name AS requester_name
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         JOIN users u ON r.user_id = u.id
         WHERE r.facility_id = :facility_id
           AND r.reservation_date = :date
           AND r.status = "approved"
           ' . ($excludeReservationId ? 'AND r.id != :exclude_id' : '') . '
         ORDER BY r.created_at DESC'
    );
    
    $params = [
        'facility_id' => $facilityId,
        'date' => $date,
    ];
    
    if ($excludeReservationId) {
        $params['exclude_id'] = $excludeReservationId;
    }
    
    $approvedReservationsStmt->execute($params);
    $approvedReservations = $approvedReservationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get PENDING reservations (soft conflicts - warning only)
    $pendingReservationsStmt = $pdo->prepare(
        'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.purpose, r.priority_level,
                f.name AS facility_name, u.name AS requester_name, r.created_at, r.expires_at
         FROM reservations r
         JOIN facilities f ON r.facility_id = f.id
         JOIN users u ON r.user_id = u.id
         WHERE r.facility_id = :facility_id
           AND r.reservation_date = :date
           AND r.status = "pending"
           AND (r.expires_at IS NULL OR r.expires_at > NOW())
           ' . ($excludeReservationId ? 'AND r.id != :exclude_id' : '') . '
         ORDER BY r.priority_level ASC, r.created_at ASC'
    );
    
    $pendingReservationsStmt->execute($params);
    $pendingReservations = $pendingReservationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check for overlapping time ranges - HARD conflicts (approved only)
    $hardConflicts = [];
    foreach ($approvedReservations as $reservation) {
        if (timeSlotsOverlap($timeSlot, $reservation['time_slot'])) {
            $hardConflicts[] = $reservation;
        }
    }
    
    // Check for overlapping time ranges - SOFT conflicts (pending - warning only)
    $softConflicts = [];
    foreach ($pendingReservations as $reservation) {
        if (timeSlotsOverlap($timeSlot, $reservation['time_slot'])) {
            $softConflicts[] = $reservation;
        }
    }
    
    // Calculate risk score based on historical patterns + holiday/event tags
    $riskScore = calculateConflictRisk($facilityId, $date, $timeSlot);
    
    // Find alternative slots if hard conflict exists
    $alternatives = [];
    if (!empty($hardConflicts)) {
        $alternatives = findAlternativeSlots($facilityId, $date);
    }
    
    // Build message based on conflict type
    $message = 'No conflicts detected. This slot is available.';
    if (!empty($hardConflicts)) {
        $message = 'This time slot is already booked (approved reservation). Please select an alternative time.';
    } elseif (!empty($softConflicts)) {
        $count = count($softConflicts);
        $message = "Warning: {$count} pending reservation(s) exist for this slot. You can still book, but admin will approve only one.";
    } elseif ($riskScore > 70) {
        $message = 'High demand period detected. Consider booking in advance.';
    }
    
    return [
        'has_conflict' => !empty($hardConflicts),  // Hard conflict (approved) - blocks booking
        'conflicts' => $hardConflicts,              // Approved reservations (hard conflicts)
        'soft_conflicts' => $softConflicts,         // Pending reservations (soft conflicts - warning only)
        'pending_count' => count($softConflicts),   // Count of pending reservations for same slot
        'risk_score' => $riskScore,
        'alternatives' => $alternatives,
        'message' => $message
    ];
}

/**
 * Calculate conflict risk score based on historical patterns
 * 
 * @param int $facilityId Facility ID
 * @param string $date Reservation date
 * @param string $timeSlot Time slot
 * @return int Risk score (0-100, higher = more risk)
 */
function calculateConflictRisk($facilityId, $date, $timeSlot) {
    $pdo = db();
    
    // Check historical booking frequency for this facility, day of week, and time slot
    $dayOfWeek = date('l', strtotime($date));
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    
    $historyStmt = $pdo->prepare(
        'SELECT COUNT(*) AS booking_count
         FROM reservations
         WHERE facility_id = :facility_id
           AND DAYNAME(reservation_date) = :day_of_week
           AND time_slot = :time_slot
           AND reservation_date >= :start_date
           AND status = "approved"'
    );
    
    $historyStmt->execute([
        'facility_id' => $facilityId,
        'day_of_week' => $dayOfWeek,
        'time_slot' => $timeSlot,
        'start_date' => $sixMonthsAgo,
    ]);
    
    $historicalCount = (int)$historyStmt->fetchColumn();
    
    // Check pending bookings for same slot
    $pendingStmt = $pdo->prepare(
        'SELECT COUNT(*) AS pending_count
         FROM reservations
         WHERE facility_id = :facility_id
           AND reservation_date = :date
           AND time_slot = :time_slot
           AND status = "pending"'
    );
    
    $pendingStmt->execute([
        'facility_id' => $facilityId,
        'date' => $date,
        'time_slot' => $timeSlot,
    ]);
    
    $pendingCount = (int)$pendingStmt->fetchColumn();
    
    // Holiday / local event risk bump (Philippines + Barangay Culiat)
    $year = (int)date('Y', strtotime($date));
    $holidayList = [];
    $holidayList["$year-01-01"] = 'New Year\'s Day';
    $holidayList["$year-02-25"] = 'EDSA People Power Anniversary';
    $holidayList["$year-04-09"] = 'Araw ng Kagitingan';
    $holidayList[date('Y-m-d', strtotime("second sunday of May $year"))] = 'Mother\'s Day';
    $holidayList[date('Y-m-d', strtotime("second sunday of June $year"))] = 'Father\'s Day';
    $holidayList["$year-06-12"] = 'Independence Day';
    $holidayList["$year-08-21"] = 'Ninoy Aquino Day';
    $holidayList[date('Y-m-d', strtotime("last monday of August $year"))] = 'National Heroes Day';
    $holidayList["$year-11-01"] = 'All Saints\' Day';
    $holidayList["$year-11-02"] = 'All Souls\' Day';
    $holidayList["$year-11-30"] = 'Bonifacio Day';
    $holidayList["$year-12-25"] = 'Christmas Day';
    $holidayList["$year-12-30"] = 'Rizal Day';
    // Barangay Culiat local events
    $holidayList["$year-09-08"] = 'Barangay Culiat Fiesta';
    $holidayList["$year-02-11"] = 'Barangay Culiat Founding Day';
    
    $isHoliday = isset($holidayList[$date]);
    
    // Calculate risk score
    // Base risk from historical frequency (0-60 points)
    $historicalRisk = min(60, $historicalCount * 10);
    
    // Additional risk from pending bookings (0-30 points)
    $pendingRisk = min(30, $pendingCount * 15);
    
    // Holiday/event bump (0 or 20 points)
    $holidayRisk = $isHoliday ? 20 : 0;
    
    // Calculate base risk score
    $riskScore = min(100, $historicalRisk + $pendingRisk + $holidayRisk);
    
    // Add ML-based conflict prediction if available
    $mlConflictScore = 0;
    if (function_exists('predictConflictML')) {
        try {
            // Get facility capacity
            $facilityStmt = $pdo->prepare('SELECT capacity FROM facilities WHERE id = :facility_id');
            $facilityStmt->execute(['facility_id' => $facilityId]);
            $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
            $capacity = $facility['capacity'] ?? '100';
            
            $mlPrediction = predictConflictML(
                $facilityId,
                $date,
                $timeSlot,
                null, // expected_attendees not available in this function
                false, // is_commercial not available in this function
                $capacity
            );
            
            if (!isset($mlPrediction['error']) && isset($mlPrediction['conflict_probability'])) {
                // Convert ML probability (0-1) to risk score (0-100)
                $mlConflictScore = $mlPrediction['conflict_probability'] * 100;
                
                // Combine rule-based and ML scores (weighted average: 60% rule-based, 40% ML)
                $riskScore = ($riskScore * 0.6) + ($mlConflictScore * 0.4);
            }
        } catch (Exception $e) {
            // Silent fail - continue with rule-based only
            error_log("ML conflict prediction error in calculateConflictRisk: " . $e->getMessage());
        }
    }
    
    return min(100, $riskScore);
}

/**
 * Find alternative time slots for a facility on a given date
 * Calculates actual available time ranges based on existing bookings
 * 
 * @param int $facilityId Facility ID
 * @param string $date Reservation date
 * @return array Alternative slots with availability info
 */
function findAlternativeSlots($facilityId, $date) {
    $pdo = db();
    
    // Facility operating hours (default: 8:00 AM - 9:00 PM)
    $operatingStart = DateTime::createFromFormat('H:i', '08:00');
    $operatingEnd = DateTime::createFromFormat('H:i', '21:00');
    
    // Get only APPROVED bookings for calculating available slots
    // PENDING reservations don't block slots - they're temporary holds
    $bookingsStmt = $pdo->prepare(
        'SELECT time_slot 
         FROM reservations
         WHERE facility_id = :facility_id
           AND reservation_date = :date
           AND status = "approved"
         ORDER BY time_slot'
    );
    
    $bookingsStmt->execute([
        'facility_id' => $facilityId,
        'date' => $date,
    ]);
    
    $bookings = $bookingsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Parse all bookings into time ranges
    $bookedRanges = [];
    foreach ($bookings as $slot) {
        $parsed = parseTimeSlot($slot);
        if ($parsed) {
            $bookedRanges[] = [
                'start' => $parsed['start'],
                'end' => $parsed['end'],
            ];
        }
    }
    
    // Sort by start time
    usort($bookedRanges, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });
    
    // Find available gaps
    $alternatives = [];
    $currentTime = clone $operatingStart;
    
    foreach ($bookedRanges as $booking) {
        // If there's a gap before this booking
        if ($currentTime < $booking['start']) {
            $gapStart = clone $currentTime;
            $gapEnd = clone $booking['start'];
            
            // Only suggest gaps of at least 30 minutes
            $duration = $gapStart->diff($gapEnd);
            $durationMinutes = $duration->h * 60 + $duration->i;
            
            if ($durationMinutes >= 30) {
                $alternatives[] = [
                    'time_slot' => $gapStart->format('H:i') . ' - ' . $gapEnd->format('H:i'),
                    'available' => true,
                    'recommendation' => 'Available - No conflicts',
                ];
            }
        }
        
        // Move current time to end of this booking (or keep it if booking ends later)
        if ($booking['end'] > $currentTime) {
            $currentTime = clone $booking['end'];
        }
    }
    
    // Check if there's available time after the last booking until closing
    if ($currentTime < $operatingEnd) {
        $gapStart = clone $currentTime;
        $gapEnd = clone $operatingEnd;
        
        $duration = $gapStart->diff($gapEnd);
        $durationMinutes = $duration->h * 60 + $duration->i;
        
        if ($durationMinutes >= 30) {
            $alternatives[] = [
                'time_slot' => $gapStart->format('H:i') . ' - ' . $gapEnd->format('H:i'),
                'available' => true,
                'recommendation' => 'Available - No conflicts',
            ];
        }
    }
    
    // If no bookings exist, show the full day as available
    if (empty($bookedRanges)) {
        $alternatives[] = [
            'time_slot' => $operatingStart->format('H:i') . ' - ' . $operatingEnd->format('H:i'),
            'available' => true,
            'recommendation' => 'Available - No conflicts',
        ];
    }
    
    // Format time slots for display (convert 24h to 12h with AM/PM)
    foreach ($alternatives as &$alt) {
        $alt['display'] = formatTimeSlotForDisplay($alt['time_slot']);
    }
    
    return $alternatives;
}

/**
 * Recommend facilities based on user requirements
 * 
 * @param string|null $purpose Event purpose/description
 * @param string|null $expectedAttendance Expected number of attendees (as string, e.g., "100 persons")
 * @param array|null $requiredAmenities Array of required amenities
 * @param int|null $userId User ID for proximity-based recommendations (optional)
 * @param int $limit Number of recommendations to return
 * @return array Recommended facilities with match scores
 */
function recommendFacilities($purpose = null, $expectedAttendance = null, $requiredAmenities = null, $userId = null, $limit = 5) {
    require_once __DIR__ . '/geocoding.php';
    $pdo = db();
    
    // Get user coordinates if user ID provided
    $userCoords = null;
    if ($userId) {
        $userCoords = getUserCoordinates($userId);
    }
    
    // Get all available facilities with coordinates
    $facilitiesStmt = $pdo->query(
        'SELECT id, name, description, capacity, amenities, location, latitude, longitude, status
         FROM facilities
         WHERE status = "available"
         ORDER BY name'
    );
    $allFacilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $recommendations = [];
    
    foreach ($allFacilities as $facility) {
        $score = 0;
        $reasons = [];
        $distance = null;
        $distanceKm = null;
        
        // Score based on proximity (if user coordinates available)
        if ($userCoords && $facility['latitude'] !== null && $facility['longitude'] !== null) {
            $facilityCoords = [
                'lat' => (float)$facility['latitude'],
                'lng' => (float)$facility['longitude'],
            ];
            
            $distanceKm = calculateDistance(
                $userCoords['lat'],
                $userCoords['lng'],
                $facilityCoords['lat'],
                $facilityCoords['lng']
            );
            
            // Proximity scoring: closer facilities get higher scores
            // Within 1km: +30 points
            // 1-3km: +20 points
            // 3-5km: +10 points
            // 5-10km: +5 points
            // Beyond 10km: 0 points
            if ($distanceKm <= 1) {
                $proximityScore = 30;
                $reasons[] = 'Very close to you (' . formatDistance($distanceKm) . ')';
            } elseif ($distanceKm <= 3) {
                $proximityScore = 20;
                $reasons[] = 'Nearby (' . formatDistance($distanceKm) . ' away)';
            } elseif ($distanceKm <= 5) {
                $proximityScore = 10;
                $reasons[] = 'Moderately close (' . formatDistance($distanceKm) . ' away)';
            } elseif ($distanceKm <= 10) {
                $proximityScore = 5;
                $reasons[] = 'Within 10km (' . formatDistance($distanceKm) . ' away)';
            } else {
                $proximityScore = 0;
            }
            
            $score += $proximityScore;
            $distance = formatDistance($distanceKm);
        }
        
        // Score based on capacity match (if attendance provided)
        if ($expectedAttendance && $facility['capacity']) {
            $capacityMatch = matchCapacity($expectedAttendance, $facility['capacity']);
            $score += $capacityMatch['score'];
            if ($capacityMatch['score'] > 0) {
                $reasons[] = $capacityMatch['reason'];
            }
        }
        
        // Score based on amenities match
        if ($requiredAmenities && is_array($requiredAmenities) && !empty($requiredAmenities)) {
            $amenityMatch = matchAmenities($requiredAmenities, $facility['amenities']);
            $score += $amenityMatch['score'];
            if ($amenityMatch['score'] > 0) {
                $reasons[] = $amenityMatch['reason'];
            }
        }
        
        // Score based on purpose keywords (simple keyword matching)
        // Include name first (higher priority), then description
        if ($purpose) {
            $facilityText = $facility['name'] . ' ' . ($facility['description'] ?? '') . ' ' . ($facility['location'] ?? '');
            $purposeMatch = matchPurpose($purpose, $facilityText);
            $score += $purposeMatch['score'];
            if ($purposeMatch['score'] > 0) {
                $reasons[] = $purposeMatch['reason'];
            }
        }
        
        // Base score for availability (always show facilities)
        $baseScore = 10;
        $score += $baseScore;
        $reasons[] = 'Currently available';
        
        // Calculate popularity score (based on recent bookings)
        $popularityScore = calculatePopularityScore($facility['id']);
        $score += $popularityScore;
        
        // Always include facility (minimum score ensures it shows up)
        if ($score >= $baseScore) {
            $recommendation = [
                'facility_id' => $facility['id'],
                'name' => $facility['name'],
                'description' => $facility['description'],
                'capacity' => $facility['capacity'],
                'amenities' => $facility['amenities'],
                'location' => $facility['location'],
                'match_score' => min(100, $score),
                'reasons' => $reasons,
            ];
            
            // Add distance information if available
            if ($distance !== null) {
                $recommendation['distance'] = $distance;
                $recommendation['distance_km'] = $distanceKm;
            }
            
            $recommendations[] = $recommendation;
        }
    }
    
    // Sort by match score (descending), then by distance (ascending) if available
    usort($recommendations, function($a, $b) {
        // First sort by match score
        if ($b['match_score'] !== $a['match_score']) {
            return $b['match_score'] - $a['match_score'];
        }
        // If scores are equal, sort by distance (closer first)
        if (isset($a['distance_km']) && isset($b['distance_km'])) {
            return $a['distance_km'] <=> $b['distance_km'];
        }
        return 0;
    });
    
    return array_slice($recommendations, 0, $limit);
}

/**
 * Match expected attendance with facility capacity
 * 
 * @param string $expectedAttendance Expected attendance (e.g., "100 persons", "50 people")
 * @param string $facilityCapacity Facility capacity (e.g., "200 persons", "150")
 * @return array Match score and reason
 */
function matchCapacity($expectedAttendance, $facilityCapacity) {
    // Extract numbers from strings
    preg_match('/(\d+)/', $expectedAttendance, $expectedMatch);
    preg_match('/(\d+)/', $facilityCapacity, $capacityMatch);
    
    if (empty($expectedMatch) || empty($capacityMatch)) {
        return ['score' => 0, 'reason' => ''];
    }
    
    $expected = (int)$expectedMatch[1];
    $capacity = (int)$capacityMatch[1];
    
    if ($capacity >= $expected) {
        $ratio = $expected / $capacity;
        if ($ratio >= 0.8 && $ratio <= 1.0) {
            return ['score' => 40, 'reason' => 'Perfect capacity match (' . $expected . ' of ' . $capacity . ')'];
        } elseif ($ratio >= 0.5) {
            return ['score' => 30, 'reason' => 'Good capacity fit (' . $expected . ' of ' . $capacity . ')'];
        } else {
            return ['score' => 20, 'reason' => 'Adequate capacity (' . $expected . ' of ' . $capacity . ')'];
        }
    }
    
    return ['score' => 0, 'reason' => 'Capacity may be insufficient'];
}

/**
 * Match required amenities with facility amenities
 * 
 * @param array $requiredAmenities Array of required amenities
 * @param string|null $facilityAmenities Facility amenities (text)
 * @return array Match score and reason
 */
function matchAmenities($requiredAmenities, $facilityAmenities) {
    if (empty($facilityAmenities)) {
        return ['score' => 0, 'reason' => ''];
    }
    
    $facilityAmenitiesLower = strtolower($facilityAmenities);
    $matched = 0;
    $matchedItems = [];
    
    foreach ($requiredAmenities as $required) {
        $requiredLower = strtolower(trim($required));
        if (strpos($facilityAmenitiesLower, $requiredLower) !== false) {
            $matched++;
            $matchedItems[] = $required;
        }
    }
    
    if ($matched === 0) {
        return ['score' => 0, 'reason' => ''];
    }
    
    $matchRatio = $matched / count($requiredAmenities);
    $score = (int)($matchRatio * 30);
    
    return [
        'score' => $score,
        'reason' => 'Has ' . $matched . ' of ' . count($requiredAmenities) . ' required amenities: ' . implode(', ', $matchedItems)
    ];
}

/**
 * Match purpose keywords with facility description - IMPROVED for general terms
 * 
 * @param string $purpose Event purpose
 * @param string $facilityText Facility name and description
 * @return array Match score and reason
 */
function matchPurpose($purpose, $facilityText) {
    $purposeLower = strtolower(trim($purpose));
    $facilityLower = strtolower($facilityText);
    
    if (empty($purposeLower)) {
        return ['score' => 0, 'reason' => ''];
    }
    
    // Extract key words from purpose (remove common words)
    $stopWords = ['for', 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'of', 'with', 'by', 'is', 'are', 'was', 'were'];
    $purposeWords = array_filter(
        explode(' ', preg_replace('/[^a-z0-9\s]/', ' ', $purposeLower)),
        function($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        }
    );
    
    $score = 0;
    $matchedKeywords = [];
    $matchedReasons = [];
    
    // Check for direct keyword matches in facility name/description
    foreach ($purposeWords as $word) {
        if (strlen($word) > 2 && strpos($facilityLower, $word) !== false) {
            $matchedKeywords[] = $word;
            $score += 15;
        }
    }
    
    // Enhanced event type matching (more flexible - matches purpose to facility type)
    $eventPatterns = [
        'sports' => [
            'purpose_keywords' => ['sport', 'game', 'tournament', 'athletic', 'basketball', 'volleyball', 'badminton', 'tennis', 'fitness', 'exercise', 'zumba', 'dance', 'dancing', 'workout', 'gym', 'physical'],
            'facility_keywords' => ['court', 'sport', 'athletic', 'gym', 'fitness', 'basketball', 'volleyball', 'badminton', 'covered court', 'sports complex', 'multi-purpose', 'hall']
        ],
        'assembly' => [
            'purpose_keywords' => ['assembly', 'meeting', 'gathering', 'conference', 'seminar', 'workshop', 'training', 'forum', 'discussion', 'session'],
            'facility_keywords' => ['hall', 'convention', 'convention hall', 'function hall', 'meeting', 'room', 'center', 'centre', 'multi-purpose']
        ],
        'celebration' => [
            'purpose_keywords' => ['celebration', 'party', 'festival', 'event', 'program', 'programme', 'ceremony', 'anniversary', 'birthday', 'fiesta'],
            'facility_keywords' => ['hall', 'convention', 'amphitheater', 'amphitheatre', 'park', 'open space', 'multi-purpose']
        ],
        'cultural' => [
            'purpose_keywords' => ['cultural', 'show', 'performance', 'concert', 'presentation', 'exhibition', 'display', 'program', 'programme'],
            'facility_keywords' => ['hall', 'amphitheater', 'amphitheatre', 'theater', 'theatre', 'stage', 'convention', 'multi-purpose']
        ],
    ];
    
    $matchedEventType = null;
    foreach ($eventPatterns as $type => $pattern) {
        $purposeMatches = false;
        $facilityMatches = false;
        
        // Check if purpose contains any keywords for this event type
        foreach ($pattern['purpose_keywords'] as $keyword) {
            if (strpos($purposeLower, $keyword) !== false) {
                $purposeMatches = true;
                break;
            }
        }
        
        // Check if facility matches this event type
        foreach ($pattern['facility_keywords'] as $keyword) {
            if (strpos($facilityLower, $keyword) !== false) {
                $facilityMatches = true;
                break;
            }
        }
        
        // If both match, give high score
        if ($purposeMatches && $facilityMatches) {
            $score += 35;
            $matchedEventType = $type;
            $matchedReasons[] = 'Perfect match for ' . $type . ' activities';
            break;
        } elseif ($purposeMatches) {
            // Purpose matches but facility doesn't explicitly - still give some score
            $score += 15;
            if (!$matchedEventType) {
                $matchedEventType = $type;
                $matchedReasons[] = 'Suitable for ' . $type . ' activities';
            }
        }
    }
    
    // Check for capacity-related terms in purpose
    if (preg_match('/(\d+)\s*(person|people|pax|attendee)/i', $purpose, $attendanceMatch)) {
        $expectedAttendance = (int)$attendanceMatch[1];
        if (preg_match('/(\d+)\s*(person|people|pax|capacity)/i', $facilityText, $capacityMatch)) {
            $facilityCapacity = (int)$capacityMatch[1];
            if ($facilityCapacity >= $expectedAttendance) {
                $score += 20;
                $matchedReasons[] = 'Capacity suitable (' . $expectedAttendance . ' of ' . $facilityCapacity . ')';
            }
        }
    }
    
    // General facility type matching (court, hall, room, center, etc.) - more flexible
    $facilityTypes = [
        'court' => ['court', 'covered court', 'basketball court', 'volleyball court', 'sports court'],
        'hall' => ['hall', 'convention hall', 'function hall', 'multi-purpose hall'],
        'room' => ['room', 'meeting room', 'function room'],
        'center' => ['center', 'centre', 'community center', 'community centre'],
        'complex' => ['complex', 'sports complex'],
        'park' => ['park', 'open space', 'field'],
    ];
    
    foreach ($facilityTypes as $typeName => $typeKeywords) {
        $purposeHasType = false;
        $facilityHasType = false;
        
        foreach ($typeKeywords as $keyword) {
            if (strpos($purposeLower, $keyword) !== false) {
                $purposeHasType = true;
            }
            if (strpos($facilityLower, $keyword) !== false) {
                $facilityHasType = true;
            }
        }
        
        if ($purposeHasType && $facilityHasType) {
            $score += 25;
            $matchedReasons[] = 'Facility type matches (' . $typeName . ')';
            break;
        }
    }
    
    // If we have any matches, return score
    if ($score > 0) {
        $reason = !empty($matchedReasons) 
            ? implode(', ', array_slice($matchedReasons, 0, 2))
            : (!empty($matchedKeywords) 
                ? 'Matches keywords: ' . implode(', ', array_slice($matchedKeywords, 0, 3))
                : 'Relevant facility for your event');
        
        return ['score' => min(50, $score), 'reason' => $reason];
    }
    
    // Even if no direct match, give a small score if facility is available (so all facilities show up)
    return ['score' => 5, 'reason' => 'Available facility'];
}

/**
 * Calculate popularity score based on recent bookings
 * 
 * @param int $facilityId Facility ID
 * @return int Popularity score (0-10)
 */
function calculatePopularityScore($facilityId) {
    $pdo = db();
    
    $threeMonthsAgo = date('Y-m-d', strtotime('-3 months'));
    
    $popularityStmt = $pdo->prepare(
        'SELECT COUNT(*) AS booking_count
         FROM reservations
         WHERE facility_id = :facility_id
           AND reservation_date >= :start_date
           AND status = "approved"'
    );
    
    $popularityStmt->execute([
        'facility_id' => $facilityId,
        'start_date' => $threeMonthsAgo,
    ]);
    
    $bookingCount = (int)$popularityStmt->fetchColumn();
    
    // More bookings = higher popularity (capped at 10 points)
    return min(10, (int)($bookingCount / 2));
}

