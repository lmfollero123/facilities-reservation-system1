<?php
/**
 * HolidayService - Philippines Holiday Detection (PHP Version)
 * 
 * PHP-based holiday detection as fallback when Python is not available
 * Detects Philippines national holidays and special non-working days
 */

class HolidayService
{
    /**
     * Get Philippines holidays for a given year
     * 
     * @param int $year Year to get holidays for
     * @return array Array of holidays with date, name, and type
     */
    public function getHolidaysForYear($year)
    {
        $holidays = [];
        
        // Fixed Date Holidays
        $fixedHolidays = [
            "01-01" => ["name" => "New Year's Day", "type" => "Regular Holiday"],
            "04-09" => ["name" => "Araw ng Kagitingan", "type" => "Regular Holiday"],
            "05-01" => ["name" => "Labor Day", "type" => "Regular Holiday"],
            "06-12" => ["name" => "Independence Day", "type" => "Regular Holiday"],
            "11-01" => ["name" => "All Saints' Day", "type" => "Special Non-Working Holiday"],
            "11-02" => ["name" => "All Souls' Day", "type" => "Special Non-Working Holiday"],
            "11-30" => ["name" => "Bonifacio Day", "type" => "Regular Holiday"],
            "12-25" => ["name" => "Christmas Day", "type" => "Regular Holiday"],
            "12-30" => ["name" => "Rizal Day", "type" => "Regular Holiday"],
        ];
        
        foreach ($fixedHolidays as $dateStr => $holiday) {
            $fullDate = sprintf('%04d-%s', $year, $dateStr);
            $holidays[$fullDate] = array_merge($holiday, ['date' => $fullDate]);
        }
        
        // Moveable Holidays
        // Maundy Thursday and Good Friday (varies by year)
        $easterSunday = $this->calculateEasterSunday($year);
        if ($easterSunday) {
            $maundyThursday = clone $easterSunday;
            $maundyThursday->modify('-3 days');
            
            $goodFriday = clone $easterSunday;
            $goodFriday->modify('-2 days');
            
            $holidays[$maundyThursday->format('Y-m-d')] = [
                'name' => 'Maundy Thursday',
                'type' => 'Regular Holiday',
                'date' => $maundyThursday->format('Y-m-d')
            ];
            
            $holidays[$goodFriday->format('Y-m-d')] = [
                'name' => 'Good Friday',
                'type' => 'Regular Holiday',
                'date' => $goodFriday->format('Y-m-d')
            ];
        }
        
        // National Heroes Day (Last Monday of August)
        $lastMondayAugust = $this->getLastMondayOfMonth($year, 8);
        if ($lastMondayAugust) {
            $holidays[$lastMondayAugust->format('Y-m-d')] = [
                'name' => 'National Heroes Day',
                'type' => 'Regular Holiday',
                'date' => $lastMondayAugust->format('Y-m-d')
            ];
        }
        
        // Additional Special Non-Working Holidays
        $additionalHolidays = [
            "02-14" => ["name" => "Valentine's Day", "type" => "Special Non-Working Holiday"],
            "02-25" => ["name" => "EDSA People Power Revolution Anniversary", "type" => "Special Non-Working Holiday"],
            "08-21" => ["name" => "Ninoy Aquino Day", "type" => "Special Non-Working Holiday"],
            "12-24" => ["name" => "Christmas Eve", "type" => "Special Non-Working Holiday"],
            "12-31" => ["name" => "New Year's Eve", "type" => "Special Non-Working Holiday"],
        ];
        
        foreach ($additionalHolidays as $dateStr => $holiday) {
            $fullDate = sprintf('%04d-%s', $year, $dateStr);
            $holidays[$fullDate] = array_merge($holiday, ['date' => $fullDate]);
        }
        
        return $holidays;
    }
    
    /**
     * Get holidays within a date range
     * 
     * @param string $startDate Start date in Y-m-d format
     * @param string $endDate End date in Y-m-d format
     * @return array Array of holidays in the range
     */
    public function getHolidaysInRange($startDate, $endDate)
    {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $year = (int)$start->format('Y');
        
        $allHolidays = $this->getHolidaysForYear($year);
        
        $holidaysInRange = [];
        foreach ($allHolidays as $date => $holiday) {
            $holidayDate = new DateTime($date);
            if ($holidayDate >= $start && $holidayDate <= $end) {
                $holidaysInRange[] = $holiday;
            }
        }
        
        return $holidaysInRange;
    }
    
    /**
     * Check if a specific date is a holiday
     * 
     * @param string $date Date in Y-m-d format
     * @return array|null Holiday info if holiday, null otherwise
     */
    public function isHoliday($date)
    {
        $year = (int)substr($date, 0, 4);
        $holidays = $this->getHolidaysForYear($year);
        
        return $holidays[$date] ?? null;
    }
    
    /**
     * Calculate Easter Sunday for a given year
     * Uses the Computus algorithm
     * 
     * @param int $year Year to calculate Easter for
     * @return DateTime|null Easter Sunday date
     */
    private function calculateEasterSunday($year)
    {
        try {
            // Anonymous Gregorian algorithm
            $a = $year % 19;
            $b = floor($year / 100);
            $c = $year % 100;
            $d = floor($b / 4);
            $e = $b % 4;
            $f = floor(($b + 8) / 25);
            $g = floor(($b - $f + 1) / 3);
            $h = (19 * $a + $b - $d - $g + 15) % 30;
            $i = floor($c / 4);
            $k = $c % 4;
            $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
            $m = floor(($a + 11 * $h + 22 * $l) / 451);
            $month = floor(($h + $l - 7 * $m + 114) / 31);
            $day = (($h + $l - 7 * $m + 114) % 31) + 1;
            
            return new DateTime("$year-$month-$day");
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Find the last Monday of a given month and year
     * 
     * @param int $year Year
     * @param int $month Month (1-12)
     * @return DateTime|null Last Monday of the month
     */
    private function getLastMondayOfMonth($year, $month)
    {
        try {
            // Start from the last day of the month
            $lastDay = new DateTime("$year-$month-01");
            $lastDay->modify('last day of this month');
            
            // Find the last Monday
            $daysBack = ($lastDay->format('w') - 1 + 7) % 7; // Monday is 1
            $lastMonday = clone $lastDay;
            $lastMonday->modify("-$daysBack days");
            
            return $lastMonday;
        } catch (Exception $e) {
            return null;
        }
    }
}
