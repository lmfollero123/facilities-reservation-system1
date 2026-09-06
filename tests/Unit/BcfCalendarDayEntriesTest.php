<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config/booking_calendar_status.php: frs_bcf_calendar_day_entries() — the
 * pure per-day formatter feeding the React calendar island's JSON endpoint.
 * Mirrors the tone/chip/pickability logic that used to live inline in
 * book_facility.php's day-cell render loop.
 */
final class BcfCalendarDayEntriesTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/booking_calendar_status.php';
    }

    public function testPastDayIsMarkedPastAndNotPickableEvenIfToneIsGreen(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-01' => 'green'], [], []);
        $day1 = $entries[0];
        $this->assertSame('2026-09-01', $day1['date']);
        $this->assertSame('past', $day1['tone']);
        $this->assertFalse($day1['is_pickable']);
        $this->assertSame('', $day1['chip_label']);
    }

    public function testOpenDayGetsApprovedStatusAndIsPickable(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-15' => 'green'], [], []);
        $day15 = $entries[14];
        $this->assertSame('2026-09-15', $day15['date']);
        $this->assertSame('green', $day15['tone']);
        $this->assertSame('status-approved', $day15['status_class']);
        $this->assertSame('Open', $day15['chip_label']);
        $this->assertTrue($day15['is_pickable']);
    }

    public function testFullyBookedDayIsStillPickable(): void
    {
        // Faithfully preserves book_facility.php's original behavior: 'red'
        // (fully booked) days remain clickable — the calendar tone is a
        // coarse hint, checkConflict() makes the real-time call.
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-20' => 'red'], [], []);
        $day20 = $entries[19];
        $this->assertSame('status-denied', $day20['status_class']);
        $this->assertSame('Full', $day20['chip_label']);
        $this->assertTrue($day20['is_pickable']);
    }

    public function testBlackoutDayIsNotPickable(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-20' => 'blackout'], [], []);
        $day20 = $entries[19];
        $this->assertSame('status-blackout', $day20['status_class']);
        $this->assertSame('Blackout', $day20['chip_label']);
        $this->assertFalse($day20['is_pickable']);
    }

    public function testHolidayAndDemandDataAttachToTheRightDay(): void
    {
        $holidays = ['2026-09-21' => ['date' => '2026-09-21', 'name' => 'Test Holiday', 'type' => 'regular']];
        $demand = ['2026-09-21' => ['score' => 80, 'classification' => 'Very High']];
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-21' => 'green'], $demand, $holidays);
        $day21 = $entries[20];
        $this->assertSame('Test Holiday', $day21['holiday_name']);
        $this->assertSame('regular', $day21['holiday_type']);
        $this->assertSame('Very High', $day21['demand_classification']);
        $this->assertSame(80, $day21['demand_score']);
    }

    public function testDemandIsIgnoredForPastDaysEvenIfPresentInMatrix(): void
    {
        $demand = ['2026-09-01' => ['score' => 80, 'classification' => 'Very High']];
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, ['2026-09-01' => 'green'], $demand, []);
        $this->assertNull($entries[0]['demand_classification']);
        $this->assertNull($entries[0]['demand_score']);
    }

    public function testReturnsOneEntryPerDayInMonth(): void
    {
        $entries = frs_bcf_calendar_day_entries('2026-09-10', 2026, 9, [], [], []);
        $this->assertCount(30, $entries); // September has 30 days
        $entriesFeb = frs_bcf_calendar_day_entries('2026-02-10', 2026, 2, [], [], []);
        $this->assertCount(28, $entriesFeb); // 2026 is not a leap year
    }
}
