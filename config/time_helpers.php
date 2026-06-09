<?php
/**
 * Time Range Helper Functions
 * 
 * Utility functions for working with time ranges in reservation system
 * 
 * @package FacilitiesReservation
 */

/**
 * Parses a time slot string to extract start and end times
 * 
 * Supports formats:
 * - "HH:MM - HH:MM" (e.g., "08:00 - 12:00")
 * - "Morning (8AM - 12PM)" (legacy format)
 * 
 * @param string $timeSlot Time slot string
 * @return array|null Array with 'start' and 'end' as DateTime objects, or null if parsing fails
 */
function parseTimeSlot(string $timeSlot): ?array {
    // Try new format first: "HH:MM - HH:MM"
    if (preg_match('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $timeSlot, $matches)) {
        $startHour = (int)$matches[1];
        $startMin = (int)$matches[2];
        $endHour = (int)$matches[3];
        $endMin = (int)$matches[4];
        
        if ($startHour >= 0 && $startHour <= 23 && $startMin >= 0 && $startMin <= 59 &&
            $endHour >= 0 && $endHour <= 23 && $endMin >= 0 && $endMin <= 59) {
            $start = DateTime::createFromFormat('H:i', sprintf('%02d:%02d', $startHour, $startMin));
            $end = DateTime::createFromFormat('H:i', sprintf('%02d:%02d', $endHour, $endMin));
            
            if ($start && $end && $end > $start) {
                return ['start' => $start, 'end' => $end];
            }
        }
    }
    
    // Try legacy format: "Morning (8AM - 12PM)"
    if (preg_match('/(\d{1,2})(AM|PM)\s*-\s*(\d{1,2})(AM|PM)/i', $timeSlot, $matches)) {
        $startHour = (int)$matches[1];
        $startPeriod = strtoupper($matches[2]);
        $endHour = (int)$matches[3];
        $endPeriod = strtoupper($matches[4]);
        
        // Convert to 24-hour format
        if ($startPeriod === 'PM' && $startHour !== 12) {
            $startHour += 12;
        } elseif ($startPeriod === 'AM' && $startHour === 12) {
            $startHour = 0;
        }
        
        if ($endPeriod === 'PM' && $endHour !== 12) {
            $endHour += 12;
        } elseif ($endPeriod === 'AM' && $endHour === 12) {
            $endHour = 0;
        }
        
        $start = DateTime::createFromFormat('H:i', sprintf('%02d:00', $startHour));
        $end = DateTime::createFromFormat('H:i', sprintf('%02d:00', $endHour));
        
        if ($start && $end && $end > $start) {
            return ['start' => $start, 'end' => $end];
        }
    }
    
    return null;
}

/**
 * Calculates duration in hours from a time slot string
 * 
 * @param string $timeSlot Time slot string (e.g., "08:00 - 12:00" or "Morning (8AM - 12PM)")
 * @return float Duration in hours, or 0 if parsing fails
 */
function getDurationHoursFromSlot(string $timeSlot): float {
    $parsed = parseTimeSlot($timeSlot);
    
    if ($parsed) {
        $diff = $parsed['start']->diff($parsed['end']);
        return $diff->h + ($diff->i / 60) + ($diff->s / 3600);
    }
    
    return 0.0;
}

/**
 * Checks if two time ranges overlap
 * 
 * @param string $slot1 First time slot string
 * @param string $slot2 Second time slot string
 * @return bool True if the time ranges overlap
 */
function timeSlotsOverlap(string $slot1, string $slot2): bool {
    $parsed1 = parseTimeSlot($slot1);
    $parsed2 = parseTimeSlot($slot2);
    
    if (!$parsed1 || !$parsed2) {
        // If we can't parse, fall back to exact string match (backward compatibility)
        return $slot1 === $slot2;
    }
    
    // Two time ranges overlap if: start1 < end2 AND start2 < end1
    return $parsed1['start'] < $parsed2['end'] && $parsed2['start'] < $parsed1['end'];
}

/**
 * Formats a time slot string for display
 * 
 * @param string $timeSlot Time slot string (e.g., "08:00 - 12:00")
 * @return string Formatted string (e.g., "8:00 AM - 12:00 PM")
 */
function formatTimeSlotForDisplay(string $timeSlot): string {
    $parsed = parseTimeSlot($timeSlot);
    
    if ($parsed) {
        $startFormatted = $parsed['start']->format('g:i A');
        $endFormatted = $parsed['end']->format('g:i A');
        return $startFormatted . ' - ' . $endFormatted;
    }
    
    // Return original if we can't parse
    return $timeSlot;
}

/**
 * Application timezone (Philippines).
 */
function frs_app_timezone(): DateTimeZone
{
    $name = date_default_timezone_get();
    if ($name === '' || $name === 'UTC') {
        return new DateTimeZone('Asia/Manila');
    }
    return new DateTimeZone($name);
}

/**
 * Minutes from midnight for HH:MM (24h).
 */
function frs_hhmm_to_minutes(string $hhmm): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
        return 0;
    }
    return ((int)$m[1]) * 60 + (int)$m[2];
}

/**
 * Format minutes-from-midnight as HH:MM.
 */
function frs_minutes_to_hhmm(int $minutes): string
{
    $minutes = max(0, min(24 * 60 - 1, $minutes));
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/**
 * Earliest bookable start today: current local time rounded up to the next 30-minute slot.
 */
function frs_earliest_bookable_start_minutes(?DateTimeInterface $now = null): int
{
    if ($now === null) {
        $now = new DateTime('now', frs_app_timezone());
    }
    $total = (int)$now->format('H') * 60 + (int)$now->format('i');
    return (int)(ceil($total / 30) * 30);
}

/**
 * Whether a start time on the given date is already in the past (local TZ).
 */
function frs_is_start_time_past_for_date(string $dateYmd, string $startHi, ?DateTimeInterface $now = null): bool
{
    if ($now === null) {
        $now = new DateTime('now', frs_app_timezone());
    }
    if ($dateYmd !== $now->format('Y-m-d')) {
        return false;
    }
    return frs_hhmm_to_minutes($startHi) < frs_earliest_bookable_start_minutes($now);
}

/**
 * Whether a reservation's time slot has fully ended (date in past, or today after slot end).
 */
function frs_reservation_slot_has_passed(string $reservationDate, string $timeSlot, ?DateTimeInterface $now = null): bool
{
    if ($now === null) {
        $now = new DateTime('now', frs_app_timezone());
    }
    $today = $now->format('Y-m-d');
    if ($reservationDate < $today) {
        return true;
    }
    if ($reservationDate > $today) {
        return false;
    }

    $parsed = parseTimeSlot($timeSlot);
    if ($parsed) {
        $endMinutes = (int)$parsed['end']->format('H') * 60 + (int)$parsed['end']->format('i');
        $nowMinutes = (int)$now->format('H') * 60 + (int)$now->format('i');
        return $nowMinutes >= $endMinutes;
    }

    $hour = (int)$now->format('H');
    if (stripos($timeSlot, 'Morning') !== false) {
        return $hour >= 12;
    }
    if (stripos($timeSlot, 'Afternoon') !== false) {
        return $hour >= 17;
    }
    if (stripos($timeSlot, 'Evening') !== false) {
        return $hour >= 21;
    }

    return false;
}

/**
 * Whether a reservation is currently in progress (today, between slot start and end).
 */
function frs_reservation_slot_is_ongoing(string $reservationDate, string $timeSlot, ?DateTimeInterface $now = null): bool
{
    if ($now === null) {
        $now = new DateTime('now', frs_app_timezone());
    }
    if ($reservationDate !== $now->format('Y-m-d')) {
        return $reservationDate < $now->format('Y-m-d');
    }

    $parsed = parseTimeSlot($timeSlot);
    if ($parsed) {
        $startMinutes = (int)$parsed['start']->format('H') * 60 + (int)$parsed['start']->format('i');
        $endMinutes = (int)$parsed['end']->format('H') * 60 + (int)$parsed['end']->format('i');
        $nowMinutes = (int)$now->format('H') * 60 + (int)$now->format('i');
        return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
    }

    $hour = (int)$now->format('H');
    if (stripos($timeSlot, 'Morning') !== false) {
        return $hour >= 8 && $hour < 12;
    }
    if (stripos($timeSlot, 'Afternoon') !== false) {
        return $hour >= 12 && $hour < 17;
    }
    if (stripos($timeSlot, 'Evening') !== false) {
        return $hour >= 17 && $hour < 21;
    }

    return false;
}






