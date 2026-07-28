<?php
/**
 * PredictionService - AI-powered Reservation Demand Prediction
 * 
 * This service predicts reservation demand for facilities based on historical data.
 * Designed to be extensible - the rule-based algorithm can be replaced with ML models
 * without changing the public API.
 * 
 * @package LGU Facilities Reservation System
 */

class PredictionService
{
    private $pdo;
    private $lookbackMonths = 6;
    private $minDataThreshold = 3; // Minimum historical records for reliable predictions
    
    // Holiday calendar (Philippines + local events)
    private $holidays = [];
    
    // Time slot categories
    private $peakHours = ['16:00-18:00', '18:00-20:00', '19:00-21:00', '20:00-22:00'];
    private $offPeakHours = ['08:00-10:00', '09:00-11:00', '10:00-12:00', '14:00-16:00'];
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->loadHolidays();
    }
    
    /**
     * Load holiday calendar for current and next year
     */
    private function loadHolidays()
    {
        $yearNow = (int)date('Y');
        $years = [$yearNow, $yearNow + 1];
        
        foreach ($years as $yr) {
            $this->holidays["$yr-01-01"] = 'New Year\'s Day';
            $this->holidays["$yr-02-25"] = 'EDSA People Power Anniversary';
            $this->holidays["$yr-04-09"] = 'Araw ng Kagitingan';
            $this->holidays[date('Y-m-d', strtotime("second sunday of May $yr"))] = 'Mother\'s Day';
            $this->holidays[date('Y-m-d', strtotime("second sunday of June $yr"))] = 'Father\'s Day';
            $this->holidays["$yr-06-12"] = 'Independence Day';
            $this->holidays["$yr-08-21"] = 'Ninoy Aquino Day';
            $this->holidays["$yr-08-26"] = 'National Heroes Day';
            $this->holidays["$yr-11-01"] = 'All Saints\' Day';
            $this->holidays["$yr-11-02"] = 'All Souls\' Day';
            $this->holidays["$yr-11-30"] = 'Bonifacio Day';
            $this->holidays["$yr-12-25"] = 'Christmas Day';
            $this->holidays["$yr-12-30"] = 'Rizal Day';
            
            // Local events (adjust as needed)
            $this->holidays["$yr-09-08"] = 'Barangay Culiat Fiesta';
            $this->holidays["$yr-02-11"] = 'Barangay Culiat Founding Day';
        }
    }
    
    /**
     * Predict demand score (0-100) for a specific facility, date, and time slot
     * 
     * @param int $facilityId Facility ID
     * @param string $date Date in Y-m-d format
     * @param string $timeSlot Time slot (e.g., "14:00-16:00")
     * @return array ['score' => 0-100, 'classification' => 'Low|Medium|High|Very High', 'confidence' => 0-100, 'factors' => []]
     */
    public function predictDemand($facilityId, $date, $timeSlot)
    {
        $factors = [];
        $baseScore = 0;
        
        // Get historical data
        $historical = $this->getHistoricalData($facilityId, $date, $timeSlot);
        
        // Check if we have sufficient data
        $hasSufficientData = $historical['total_count'] >= $this->minDataThreshold;
        
        // Calculate base score from historical frequency
        if ($hasSufficientData) {
            $baseScore = $this->calculateBaseScore($historical);
            $factors[] = [
                'factor' => 'Historical Demand',
                'value' => $historical['avg_bookings'],
                'impact' => $baseScore * 0.4 // 40% weight
            ];
        } else {
            // Fallback to simple heuristics
            $baseScore = 30; // Default medium-low
            $factors[] = [
                'factor' => 'Historical Demand',
                'value' => 'Insufficient data',
                'impact' => 0
            ];
        }
        
        // Apply holiday/event boost
        $holidayBoost = $this->getHolidayBoost($date);
        if ($holidayBoost > 0) {
            $factors[] = [
                'factor' => 'Holiday/Event',
                'value' => $this->holidays[$date] ?? 'Special Event',
                'impact' => $holidayBoost
            ];
        }
        
        // Apply weekend boost
        $weekendBoost = $this->getWeekendBoost($date);
        if ($weekendBoost > 0) {
            $factors[] = [
                'factor' => 'Weekend',
                'value' => date('l', strtotime($date)),
                'impact' => $weekendBoost
            ];
        }
        
        // Apply time slot boost
        $timeSlotBoost = $this->getTimeSlotBoost($timeSlot);
        if ($timeSlotBoost > 0) {
            $factors[] = [
                'factor' => 'Peak Hours',
                'value' => $timeSlot,
                'impact' => $timeSlotBoost
            ];
        }
        
        // Apply seasonal boost (month-based)
        $seasonalBoost = $this->getSeasonalBoost($date);
        if ($seasonalBoost > 0) {
            $factors[] = [
                'factor' => 'Seasonal Pattern',
                'value' => date('F', strtotime($date)),
                'impact' => $seasonalBoost
            ];
        }
        
        // Apply recent trend adjustment
        if ($hasSufficientData) {
            $trendAdjustment = $this->getTrendAdjustment($facilityId, $date, $timeSlot);
            if ($trendAdjustment !== 0) {
                $factors[] = [
                    'factor' => 'Recent Trend',
                    'value' => $trendAdjustment > 0 ? 'Increasing' : 'Decreasing',
                    'impact' => $trendAdjustment
                ];
            }
        }
        
        // Calculate final score
        $finalScore = $baseScore + $holidayBoost + $weekendBoost + $timeSlotBoost + $seasonalBoost;
        if ($hasSufficientData) {
            $finalScore += $trendAdjustment;
        }
        
        // Normalize to 0-100
        $finalScore = max(0, min(100, $finalScore));
        
        // Calculate confidence based on data availability
        $confidence = $hasSufficientData ? min(100, ($historical['total_count'] / 20) * 100) : 50;
        
        return [
            'score' => round($finalScore),
            'classification' => $this->classifyDemand($finalScore),
            'confidence' => round($confidence),
            'factors' => $factors,
            'has_sufficient_data' => $hasSufficientData
        ];
    }
    
    /**
     * Extract an ['start' => int, 'end' => int] hour range (0-23, may exceed
     * 23 when the parsed end is treated as past midnight) from a stored
     * time_slot value. Real bookings are free-form ranges typed via the
     * facility booking form (e.g. "16:30 - 18:00", "08:00 - 20:00") or older
     * preset labels (e.g. "Morning (8AM - 12PM)") -- never the fixed
     * "HH:MM-HH:MM" slot strings this service predicts against -- so historical
     * matching below is done by overlapping hour ranges rather than requiring
     * an exact string match, which real data would almost never satisfy.
     * Returns null when no two time markers can be parsed out.
     */
    private function extractHourRange(string $timeSlot): ?array
    {
        if (preg_match_all('/(\d{1,2}):(\d{2})/', $timeSlot, $m) && count($m[1]) >= 2) {
            $startMinutes = ((int)$m[1][0]) * 60 + (int)$m[2][0];
            $endMinutes = ((int)$m[1][1]) * 60 + (int)$m[2][1];
        } elseif (preg_match_all('/(\d{1,2})\s*(AM|PM)/i', $timeSlot, $m) && count($m[1]) >= 2) {
            $to24 = static function (string $hour, string $meridiem): int {
                $h = ((int)$hour) % 12;
                return strtoupper($meridiem) === 'PM' ? $h + 12 : $h;
            };
            $startMinutes = $to24($m[1][0], $m[2][0]) * 60;
            $endMinutes = $to24($m[1][1], $m[2][1]) * 60;
        } else {
            return null;
        }

        // Compare full minutes (not truncated hours) before deciding this is an
        // overnight/malformed range -- a same-hour booking like "08:00 - 08:30"
        // must not be mistaken for a wrap spanning the entire rest of the day.
        if ($endMinutes <= $startMinutes) {
            $endMinutes += 24 * 60;
        }

        return [
            'start' => (int)floor($startMinutes / 60),
            'end' => (int)ceil($endMinutes / 60),
        ];
    }

    /** Whether two hour ranges overlap at all. */
    private function hourRangesOverlap(array $a, array $b): bool
    {
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    /**
     * Get historical booking data for a specific slot
     */
    private function getHistoricalData($facilityId, $date, $timeSlot)
    {
        $dayOfWeek = date('l', strtotime($date));
        $month = date('m', strtotime($date));

        $windowStart = date('Y-m-d', strtotime("-{$this->lookbackMonths} months"));
        $windowEnd = date('Y-m-d');

        $targetRange = $this->extractHourRange($timeSlot);
        if ($targetRange === null) {
            return ['total_count' => 0, 'avg_bookings' => 0.0, 'max_bookings' => 0];
        }

        $sql = "SELECT reservation_date, time_slot
                FROM reservations
                WHERE facility_id = :facility_id
                    AND reservation_date BETWEEN :start AND :end
                    AND status = 'approved'
                    AND (DAYNAME(reservation_date) = :day_of_week OR MONTH(reservation_date) = :month)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'facility_id' => $facilityId,
            'start' => $windowStart,
            'end' => $windowEnd,
            'day_of_week' => $dayOfWeek,
            'month' => $month
        ]);

        $perDay = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $range = $this->extractHourRange((string)$row['time_slot']);
            if ($range === null || !$this->hourRangesOverlap($range, $targetRange)) {
                continue;
            }
            $perDay[$row['reservation_date']] = ($perDay[$row['reservation_date']] ?? 0) + 1;
        }

        $counts = array_values($perDay);

        return [
            'total_count' => count($counts),
            'avg_bookings' => $counts !== [] ? array_sum($counts) / count($counts) : 0.0,
            'max_bookings' => $counts !== [] ? max($counts) : 0
        ];
    }
    
    /**
     * Calculate base score from historical data
     */
    private function calculateBaseScore($historical)
    {
        $avg = $historical['avg_bookings'];
        
        // Normalize average bookings to 0-40 scale (40% weight)
        // Assuming max reasonable average is 5 bookings per slot
        return min(40, ($avg / 5) * 40);
    }
    
    /**
     * Get holiday/event boost
     */
    private function getHolidayBoost($date)
    {
        if (isset($this->holidays[$date])) {
            return 25; // Significant boost for holidays
        }
        return 0;
    }
    
    /**
     * Get weekend boost
     */
    private function getWeekendBoost($date)
    {
        $dayOfWeek = date('w', strtotime($date)); // 0 = Sunday, 6 = Saturday
        if ($dayOfWeek == 0 || $dayOfWeek == 6) {
            return 15; // Moderate boost for weekends
        }
        return 0;
    }
    
    /**
     * Get time slot boost
     */
    private function getTimeSlotBoost($timeSlot)
    {
        if (in_array($timeSlot, $this->peakHours)) {
            return 20; // Boost for peak hours
        }
        if (in_array($timeSlot, $this->offPeakHours)) {
            return 5; // Small boost for off-peak but still popular
        }
        return 0;
    }
    
    /**
     * Get seasonal boost based on month
     */
    private function getSeasonalBoost($date)
    {
        $month = (int)date('m', strtotime($date));
        
        // December (holiday season)
        if ($month == 12) {
            return 20;
        }
        // May (summer/fiesta season in Philippines)
        if ($month == 5) {
            return 15;
        }
        // January (post-holiday events)
        if ($month == 1) {
            return 10;
        }
        
        return 0;
    }
    
    /**
     * Get recent trend adjustment
     */
    private function getTrendAdjustment($facilityId, $date, $timeSlot)
    {
        $dayOfWeek = date('l', strtotime($date));

        // Last 30 days
        $recentStart = date('Y-m-d', strtotime('-30 days'));
        $recentEnd = date('Y-m-d');

        // Previous 30-60 days
        $previousStart = date('Y-m-d', strtotime('-60 days'));

        $targetRange = $this->extractHourRange($timeSlot);
        if ($targetRange === null) {
            return 0;
        }

        $sql = "SELECT reservation_date, time_slot
                FROM reservations
                WHERE facility_id = :facility_id
                    AND reservation_date BETWEEN :previous_start AND :recent_end
                    AND status = 'approved'
                    AND DAYNAME(reservation_date) = :day_of_week";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'facility_id' => $facilityId,
            'previous_start' => $previousStart,
            'recent_end' => $recentEnd,
            'day_of_week' => $dayOfWeek
        ]);

        $recent = 0;
        $previous = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $range = $this->extractHourRange((string)$row['time_slot']);
            if ($range === null || !$this->hourRangesOverlap($range, $targetRange)) {
                continue;
            }
            $rowDate = (string)$row['reservation_date'];
            if ($rowDate >= $recentStart) {
                $recent++;
            } elseif ($rowDate >= $previousStart && $rowDate < $recentStart) {
                $previous++;
            }
        }

        if ($previous == 0) {
            return 0;
        }
        
        // Calculate percentage change
        $change = (($recent - $previous) / $previous) * 100;
        
        // Cap adjustment at +/- 15
        return max(-15, min(15, $change * 0.3));
    }
    
    /**
     * Classify demand score into category
     */
    private function classifyDemand($score)
    {
        if ($score >= 76) {
            return 'Very High';
        } elseif ($score >= 51) {
            return 'High';
        } elseif ($score >= 26) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }
    
    /**
     * Get alternative time slot suggestions for high demand periods
     * 
     * @param int $facilityId Facility ID
     * @param string $date Date in Y-m-d format
     * @param string $timeSlot Current time slot
     * @return array Array of alternative slots with demand predictions
     */
    public function getAlternativeSlots($facilityId, $date, $timeSlot)
    {
        $alternatives = [];
        
        // Common time slots to check
        $commonSlots = [
            '08:00-10:00', '09:00-11:00', '10:00-12:00',
            '13:00-15:00', '14:00-16:00', '15:00-17:00',
            '16:00-18:00', '17:00-19:00', '18:00-20:00',
            '19:00-21:00', '20:00-22:00'
        ];
        
        // Check same day, different time slots
        foreach ($commonSlots as $slot) {
            if ($slot === $timeSlot) continue;
            
            $prediction = $this->predictDemand($facilityId, $date, $slot);
            
            if ($prediction['score'] < 50) { // Only suggest low/medium demand
                $alternatives[] = [
                    'date' => $date,
                    'time_slot' => $slot,
                    'score' => $prediction['score'],
                    'classification' => $prediction['classification'],
                    'reason' => $this->generateSuggestionReason($prediction)
                ];
            }
        }
        
        // If not enough alternatives on same day, check adjacent days
        if (count($alternatives) < 3) {
            for ($i = -2; $i <= 2; $i++) {
                if ($i == 0) continue;
                
                $altDate = date('Y-m-d', strtotime($date . " $i days"));
                if (strtotime($altDate) < strtotime('today')) continue;
                
                foreach ($commonSlots as $slot) {
                    $prediction = $this->predictDemand($facilityId, $altDate, $slot);
                    
                    if ($prediction['score'] < 50) {
                        $alternatives[] = [
                            'date' => $altDate,
                            'time_slot' => $slot,
                            'score' => $prediction['score'],
                            'classification' => $prediction['classification'],
                            'reason' => $this->generateSuggestionReason($prediction)
                        ];
                    }
                }
            }
        }
        
        // Sort by score (lowest first) and limit to top 5
        usort($alternatives, function($a, $b) {
            return $a['score'] <=> $b['score'];
        });
        
        return array_slice($alternatives, 0, 5);
    }
    
    /**
     * Generate human-readable suggestion reason
     */
    private function generateSuggestionReason($prediction)
    {
        $reasons = [];
        
        foreach ($prediction['factors'] as $factor) {
            if ($factor['impact'] < 0) {
                $reasons[] = strtolower($factor['factor']) . ' is favorable';
            }
        }
        
        if (empty($reasons)) {
            return 'Lower predicted demand';
        }
        
        return implode(', ', $reasons);
    }
    
    /**
     * Get demand forecast for a specific facility
     * 
     * @param int $facilityId Facility ID
     * @param int $daysAhead Number of days to forecast
     * @return array Array of daily forecasts
     */
    public function getFacilityDemandForecast($facilityId, $daysAhead = 14)
    {
        $forecast = [];
        $commonSlots = [
            '08:00-10:00', '09:00-11:00', '10:00-12:00',
            '14:00-16:00', '16:00-18:00', '18:00-20:00'
        ];
        
        for ($i = 0; $i < $daysAhead; $i++) {
            $date = date('Y-m-d', strtotime("+$i days"));
            
            $dayForecast = [
                'date' => $date,
                'day_of_week' => date('l', strtotime($date)),
                'slots' => []
            ];
            
            foreach ($commonSlots as $slot) {
                $prediction = $this->predictDemand($facilityId, $date, $slot);
                $dayForecast['slots'][] = [
                    'time_slot' => $slot,
                    'score' => $prediction['score'],
                    'classification' => $prediction['classification']
                ];
            }
            
            $forecast[] = $dayForecast;
        }
        
        return $forecast;
    }
    
    /**
     * Get overall system-wide demand forecast
     * 
     * @param int $daysAhead Number of days to forecast
     * @return array Array of daily forecasts for all facilities
     */
    public function getOverallDemandForecast($daysAhead = 14)
    {
        // Get all active facilities
        $facilities = $this->pdo->query(
            "SELECT id, name FROM facilities WHERE status = 'available' ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        $forecast = [];
        
        foreach ($facilities as $facility) {
            $forecast[] = [
                'facility_id' => $facility['id'],
                'facility_name' => $facility['name'],
                'forecast' => $this->getFacilityDemandForecast($facility['id'], $daysAhead)
            ];
        }
        
        return $forecast;
    }
}
