<?php
/**
 * RecommendationService - AI-powered Personalized Reservation Recommendations
 * 
 * This service provides personalized facility, date, and time slot recommendations
 * based on user's reservation history and behavior patterns.
 * 
 * @package LGU Facilities Reservation System
 */

class RecommendationService
{
    private $pdo;
    private $minHistoryForPersonalization = 3; // Minimum reservations for personalized recommendations
    private $lookbackMonths = 6; // Analyze last 6 months of history
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Get personalized recommendations for a user
     * 
     * @param int $userId User ID
     * @return array Personalized recommendations
     */
    public function getPersonalizedRecommendations($userId)
    {
        // Get user's reservation history
        $userHistory = $this->getUserHistory($userId);
        
        // Check if user has enough history for personalization
        $hasEnoughHistory = count($userHistory) >= $this->minHistoryForPersonalization;
        
        if ($hasEnoughHistory) {
            return $this->generatePersonalizedRecommendations($userId, $userHistory);
        } else {
            return $this->generateFallbackRecommendations($userId);
        }
    }
    
    /**
     * Get user's reservation history
     */
    private function getUserHistory($userId)
    {
        $windowStart = date('Y-m-d', strtotime("-{$this->lookbackMonths} months"));
        
        $sql = "SELECT 
                    r.id,
                    r.facility_id,
                    f.name as facility_name,
                    r.reservation_date,
                    r.time_slot,
                    r.purpose,
                    r.expected_attendees,
                    r.is_commercial,
                    r.status,
                    r.created_at
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id
                WHERE r.user_id = :user_id
                    AND r.reservation_date >= :start_date
                    AND r.status IN ('approved', 'completed')
                ORDER BY r.reservation_date DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'start_date' => $windowStart
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Generate personalized recommendations based on user history
     */
    private function generatePersonalizedRecommendations($userId, $userHistory)
    {
        // Analyze user patterns
        $patterns = $this->analyzeUserPatterns($userHistory);
        
        // Get available facilities
        $availableFacilities = $this->getAvailableFacilities();
        
        // Score each facility
        $recommendations = [];
        foreach ($availableFacilities as $facility) {
            $score = $this->calculateFacilityScore($facility, $patterns, $userHistory);
            if ($score > 0) {
                $recommendations[] = [
                    'facility_id' => $facility['id'],
                    'facility_name' => $facility['name'],
                    'score' => $score,
                    'reasons' => $this->generateReasons($facility, $patterns, $score),
                    'suggested_date' => $this->suggestDate($patterns),
                    'suggested_time' => $this->suggestTime($patterns),
                    'suggested_duration' => $patterns['typical_duration'],
                    'suggested_attendees' => $patterns['typical_attendees']
                ];
            }
        }
        
        // Sort by score and return top recommendations
        usort($recommendations, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        return array_slice($recommendations, 0, 5);
    }
    
    /**
     * Analyze user behavior patterns from history
     */
    private function analyzeUserPatterns($userHistory)
    {
        $patterns = [
            'facility_frequency' => [],
            'day_frequency' => [],
            'time_frequency' => [],
            'typical_duration' => 2, // Default 2 hours
            'typical_attendees' => 20, // Default 20
            'preferred_facility' => null,
            'preferred_day' => null,
            'preferred_time' => null,
            'total_reservations' => count($userHistory)
        ];
        
        // Count facility frequency
        foreach ($userHistory as $reservation) {
            $facilityId = $reservation['facility_id'];
            if (!isset($patterns['facility_frequency'][$facilityId])) {
                $patterns['facility_frequency'][$facilityId] = 0;
            }
            $patterns['facility_frequency'][$facilityId]++;
        }
        
        // Find most frequent facility
        if (!empty($patterns['facility_frequency'])) {
            arsort($patterns['facility_frequency']);
            $patterns['preferred_facility'] = array_key_first($patterns['facility_frequency']);
        }
        
        // Count day of week frequency
        foreach ($userHistory as $reservation) {
            $dayOfWeek = date('l', strtotime($reservation['reservation_date']));
            if (!isset($patterns['day_frequency'][$dayOfWeek])) {
                $patterns['day_frequency'][$dayOfWeek] = 0;
            }
            $patterns['day_frequency'][$dayOfWeek]++;
        }
        
        // Find most frequent day
        if (!empty($patterns['day_frequency'])) {
            arsort($patterns['day_frequency']);
            $patterns['preferred_day'] = array_key_first($patterns['day_frequency']);
        }
        
        // Count time slot frequency
        foreach ($userHistory as $reservation) {
            $timeSlot = $reservation['time_slot'];
            if (!isset($patterns['time_frequency'][$timeSlot])) {
                $patterns['time_frequency'][$timeSlot] = 0;
            }
            $patterns['time_frequency'][$timeSlot]++;
        }
        
        // Find most frequent time slot
        if (!empty($patterns['time_frequency'])) {
            arsort($patterns['time_frequency']);
            $patterns['preferred_time'] = array_key_first($patterns['time_frequency']);
        }
        
        // Calculate typical duration from time slots
        $durations = [];
        foreach ($userHistory as $reservation) {
            $duration = $this->extractDuration($reservation['time_slot']);
            if ($duration > 0) {
                $durations[] = $duration;
            }
        }
        if (!empty($durations)) {
            $patterns['typical_duration'] = round(array_sum($durations) / count($durations));
        }
        
        // Calculate typical attendees
        $attendees = [];
        foreach ($userHistory as $reservation) {
            if ($reservation['expected_attendees'] > 0) {
                $attendees[] = $reservation['expected_attendees'];
            }
        }
        if (!empty($attendees)) {
            $patterns['typical_attendees'] = round(array_sum($attendees) / count($attendees));
        }
        
        return $patterns;
    }
    
    /**
     * Extract duration in hours from time slot
     */
    private function extractDuration($timeSlot)
    {
        // Parse time slot like "14:00-16:00" or "2:00 PM - 4:00 PM"
        if (preg_match('/(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})/', $timeSlot, $matches)) {
            $startHour = (int)$matches[1];
            $endHour = (int)$matches[3];
            return $endHour - $startHour;
        }
        return 0;
    }
    
    /**
     * Calculate recommendation score for a facility
     */
    private function calculateFacilityScore($facility, $patterns, $userHistory)
    {
        $score = 0;
        
        // Facility frequency score (35%)
        $facilityId = $facility['id'];
        $facilityCount = $patterns['facility_frequency'][$facilityId] ?? 0;
        $facilityScore = ($facilityCount / $patterns['total_reservations']) * 35;
        $score += $facilityScore;
        
        // If this is their preferred facility, add bonus
        if ($patterns['preferred_facility'] == $facilityId) {
            $score += 15;
        }
        
        // Recency factor (10%) - more recent reservations get higher score
        $lastReservation = $userHistory[0] ?? null;
        if ($lastReservation && $lastReservation['facility_id'] == $facilityId) {
            $daysSinceLast = (strtotime(date('Y-m-d')) - strtotime($lastReservation['reservation_date'])) / 86400;
            $recencyScore = max(0, 10 - ($daysSinceLast / 18)); // Decay over 180 days
            $score += $recencyScore;
        }
        
        // Capacity match (5%)
        if ($patterns['typical_attendees'] > 0 && $facility['capacity']) {
            $capacity = (int)$facility['capacity'];
            if ($capacity >= $patterns['typical_attendees']) {
                $score += 5;
            }
        }
        
        return min(100, round($score));
    }
    
    /**
     *生成推荐理由
     */
    private function generateReasons($facility, $patterns, $score)
    {
        $reasons = [];
        
        $facilityId = $facility['id'];
        $facilityCount = $patterns['facility_frequency'][$facilityId] ?? 0;
        
        if ($facilityCount > 0) {
            $reasons[] = "✓ Reserved {$facilityCount} time(s)";
        }
        
        if ($patterns['preferred_facility'] == $facilityId) {
            $reasons[] = "✓ Your most reserved facility";
        }
        
        if ($patterns['preferred_day']) {
            $reasons[] = "✓ Preferred {$patterns['preferred_day']}";
        }
        
        if ($patterns['preferred_time']) {
            $reasons[] = "✓ Preferred time slot";
        }
        
        if ($score >= 80) {
            $reasons[] = "✓ Highly recommended based on your history";
        }
        
        return $reasons;
    }
    
    /**
     * Suggest best date based on user patterns
     */
    private function suggestDate($patterns)
    {
        if ($patterns['preferred_day']) {
            $today = new DateTime('next ' . $patterns['preferred_day']);
            return $today->format('Y-m-d');
        }
        
        // Default to next Saturday
        return date('Y-m-d', strtotime('next Saturday'));
    }
    
    /**
     * Suggest best time based on user patterns
     */
    private function suggestTime($patterns)
    {
        if ($patterns['preferred_time']) {
            return $patterns['preferred_time'];
        }
        
        // Default to afternoon
        return '14:00-16:00';
    }
    
    /**
     * Get available facilities
     */
    private function getAvailableFacilities()
    {
        $sql = "SELECT id, name, capacity, status 
                FROM facilities 
                WHERE status = 'available' 
                ORDER BY name ASC";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Generate fallback recommendations for new users
     */
    private function generateFallbackRecommendations($userId)
    {
        // Get most popular facilities
        $popularFacilities = $this->getPopularFacilities();
        
        // Get least crowded time slots
        $quietTimes = $this->getQuietTimeSlots();
        
        // Get trending reservations
        $trending = $this->getTrendingReservations();
        
        $recommendations = [];
        
        // Combine into recommendations
        foreach ($popularFacilities as $facility) {
            $recommendations[] = [
                'facility_id' => $facility['id'],
                'facility_name' => $facility['name'],
                'score' => 75, // Base score for popular facilities
                'reasons' => [
                    '✓ Popular choice among users',
                    '✓ High availability',
                    '✓ Well-rated facility'
                ],
                'suggested_date' => date('Y-m-d', strtotime('next Saturday')),
                'suggested_time' => $quietTimes[0] ?? '14:00-16:00',
                'suggested_duration' => 2,
                'suggested_attendees' => 20,
                'is_fallback' => true
            ];
        }
        
        return array_slice($recommendations, 0, 3);
    }
    
    /**
     * Get most popular facilities
     */
    private function getPopularFacilities()
    {
        $sql = "SELECT f.id, f.name, COUNT(r.id) as reservation_count
                FROM facilities f
                JOIN reservations r ON f.id = r.facility_id
                WHERE r.status = 'approved'
                    AND r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                GROUP BY f.id, f.name
                ORDER BY reservation_count DESC
                LIMIT 5";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get least crowded time slots
     */
    private function getQuietTimeSlots()
    {
        $sql = "SELECT time_slot, COUNT(*) as count
                FROM reservations
                WHERE status = 'approved'
                    AND reservation_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                GROUP BY time_slot
                ORDER BY count ASC
                LIMIT 5";
        
        $result = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_column($result, 'time_slot');
    }
    
    /**
     * Get trending reservations
     */
    private function getTrendingReservations()
    {
        $sql = "SELECT facility_id, COUNT(*) as count
                FROM reservations
                WHERE status = 'approved'
                    AND reservation_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                GROUP BY facility_id
                ORDER BY count DESC
                LIMIT 5";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get alternative suggestions if preferred slot is unavailable
     */
    public function getAlternativeSuggestions($userId, $facilityId, $preferredDate, $preferredTime)
    {
        $alternatives = [];
        
        // Suggest closest available time on same day
        $sameDayAlternatives = $this->getSameDayAlternatives($facilityId, $preferredDate, $preferredTime);
        if (!empty($sameDayAlternatives)) {
            $alternatives = array_merge($alternatives, $sameDayAlternatives);
        }
        
        // Suggest nearest available day
        $nearestDayAlternatives = $this->getNearestDayAlternatives($userId, $facilityId, $preferredDate);
        if (!empty($nearestDayAlternatives)) {
            $alternatives = array_merge($alternatives, $nearestDayAlternatives);
        }
        
        // Suggest similar facilities
        $similarFacilities = $this->getSimilarFacilities($userId, $facilityId);
        if (!empty($similarFacilities)) {
            $alternatives = array_merge($alternatives, $similarFacilities);
        }
        
        return array_slice($alternatives, 0, 5);
    }
    
    /**
     * Get same day alternatives
     */
    private function getSameDayAlternatives($facilityId, $date, $preferredTime)
    {
        $commonTimeSlots = [
            '08:00-10:00', '09:00-11:00', '10:00-12:00',
            '13:00-十五:00', '14:00-16:00', '15:00-17:00',
            '16:00-18:00', '17:00-19:00', '18:00-20:00',
            '19:00-21:00', '20:00-22:00'
        ];
        
        $alternatives = [];
        foreach ($commonTimeSlots as $timeSlot) {
            if ($timeSlot !== $preferredTime) {
                $alternatives[] = [
                    'type' => 'same_day',
                    'date' => $date,
                    'time_slot' => $timeSlot,
                    'reason' => 'Same day, different time'
                ];
            }
        }
        
        return $alternatives;
    }
    
    /**
     * Get nearest day alternatives
     */
    private function getNearestDayAlternatives($userId, $facilityId, $preferredDate)
    {
        $alternatives = [];
        
        // Check next 7 days
        for ($i = 1; $i <= 7; $i++) {
            $date = date('Y-m-d', strtotime("+$i days", strtotime($preferredDate)));
            $alternatives[] = [
                'type' => 'nearest_day',
                'date' => $date,
                'time_slot' => '14:00-16:00', // Default time
                'reason' => 'Nearest available day'
            ];
        }
        
        return $alternatives;
    }
    
    /**
     * Get similar facilities
     */
    private function getSimilarFacilities($userId, $facilityId)
    {
        // Get current facility details
        $stmt = $this->pdo->prepare("SELECT * FROM facilities WHERE id = ?");
        $stmt->execute([$facilityId]);
        $currentFacility = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentFacility) {
            return [];
        }
        
        // Get similar facilities based on capacity
        $capacity = (int)$currentFacility['capacity'];
        $sql = "SELECT id, name, capacity 
                FROM facilities 
                WHERE id != ? 
                    AND status = 'available'
                    AND capacity BETWEEN ? AND ?
                ORDER BY ABS(capacity - ?) ASC
                LIMIT 3";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$facilityId, $capacity * 0.8, $capacity * 1.2, $capacity]);
        
        $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $alternatives = [];
        foreach ($facilities as $facility) {
            $alternatives[] = [
                'type' => 'similar_facility',
                'facility_id' => $facility['id'],
                'facility_name' => $facility['name'],
                'date' => date('Y-m-d', strtotime('next Saturday')),
                'time_slot' => '14:00-16:00',
                'reason' => 'Similar capacity facility'
            ];
        }
        
        return $alternatives;
    }
}
