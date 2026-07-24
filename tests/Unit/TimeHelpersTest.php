<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Conflict-detection core: time slot parsing, duration, and overlap logic
 * (config/time_helpers.php). Every booking conflict check in the system
 * ultimately goes through timeSlotsOverlap().
 */
final class TimeHelpersTest extends TestCase
{
    // ---- parseTimeSlot -------------------------------------------------

    public function testParsesModernFormat(): void
    {
        $parsed = parseTimeSlot('08:00 - 12:00');
        $this->assertNotNull($parsed);
        $this->assertSame('08:00', $parsed['start']->format('H:i'));
        $this->assertSame('12:00', $parsed['end']->format('H:i'));
    }

    public function testParsesLegacyAmPmFormat(): void
    {
        $parsed = parseTimeSlot('Morning (8AM - 12PM)');
        $this->assertNotNull($parsed);
        $this->assertSame('08:00', $parsed['start']->format('H:i'));
        $this->assertSame('12:00', $parsed['end']->format('H:i'));
    }

    public function testParsesLegacyNoonAndMidnightEdges(): void
    {
        $parsed = parseTimeSlot('12PM - 5PM');
        $this->assertNotNull($parsed);
        $this->assertSame('12:00', $parsed['start']->format('H:i'));
        $this->assertSame('17:00', $parsed['end']->format('H:i'));
    }

    public function testRejectsEndBeforeStart(): void
    {
        $this->assertNull(parseTimeSlot('14:00 - 09:00'));
    }

    public function testRejectsGarbage(): void
    {
        $this->assertNull(parseTimeSlot('whole day'));
        $this->assertNull(parseTimeSlot(''));
    }

    // ---- getDurationHoursFromSlot --------------------------------------

    public function testDurationForWholeHours(): void
    {
        $this->assertSame(4.0, getDurationHoursFromSlot('08:00 - 12:00'));
    }

    public function testDurationForHalfHours(): void
    {
        $this->assertSame(2.5, getDurationHoursFromSlot('13:00 - 15:30'));
    }

    public function testDurationZeroWhenUnparseable(): void
    {
        $this->assertSame(0.0, getDurationHoursFromSlot('n/a'));
    }

    // ---- timeSlotsOverlap ----------------------------------------------

    public function testDetectsPartialOverlap(): void
    {
        $this->assertTrue(timeSlotsOverlap('08:00 - 12:00', '11:00 - 14:00'));
        $this->assertTrue(timeSlotsOverlap('11:00 - 14:00', '08:00 - 12:00'));
    }

    public function testDetectsContainment(): void
    {
        $this->assertTrue(timeSlotsOverlap('08:00 - 18:00', '10:00 - 11:00'));
    }

    public function testBackToBackSlotsDoNotOverlap(): void
    {
        // end == start must NOT be a conflict (the whole point of flexible slots)
        $this->assertFalse(timeSlotsOverlap('08:00 - 12:00', '12:00 - 16:00'));
    }

    public function testDisjointSlotsDoNotOverlap(): void
    {
        $this->assertFalse(timeSlotsOverlap('08:00 - 10:00', '13:00 - 15:00'));
    }

    public function testLegacyVsModernFormatsCompare(): void
    {
        $this->assertTrue(timeSlotsOverlap('Morning (8AM - 12PM)', '11:00 - 13:00'));
        $this->assertFalse(timeSlotsOverlap('Morning (8AM - 12PM)', '13:00 - 15:00'));
    }

    public function testUnparseableFallsBackToExactStringMatch(): void
    {
        $this->assertTrue(timeSlotsOverlap('Whole Day', 'Whole Day'));
        $this->assertFalse(timeSlotsOverlap('Whole Day', 'Half Day'));
    }

    // ---- slot lifecycle (injected clock) --------------------------------

    public function testSlotHasPassedUsesInjectedNow(): void
    {
        $now = new \DateTime('2026-07-24 13:00:00');
        $this->assertTrue(frs_reservation_slot_has_passed('2026-07-23', '08:00 - 12:00', $now));
        $this->assertTrue(frs_reservation_slot_has_passed('2026-07-24', '08:00 - 12:00', $now));
        $this->assertFalse(frs_reservation_slot_has_passed('2026-07-24', '14:00 - 16:00', $now));
        $this->assertFalse(frs_reservation_slot_has_passed('2026-07-25', '08:00 - 12:00', $now));
    }

    public function testSlotIsOngoingUsesInjectedNow(): void
    {
        $now = new \DateTime('2026-07-24 10:00:00');
        $this->assertTrue(frs_reservation_slot_is_ongoing('2026-07-24', '08:00 - 12:00', $now));
        $this->assertFalse(frs_reservation_slot_is_ongoing('2026-07-24', '13:00 - 15:00', $now));
    }
}
