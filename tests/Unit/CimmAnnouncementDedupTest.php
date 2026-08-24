<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config/cimm_maintenance_announcements.php's facility+window overlap guard
 * -- the fix for CIMM's report feed re-emitting a new id for the same
 * ongoing issue, which previously slipped past the exact-id dedup and
 * produced duplicate public announcements.
 */
final class CimmAnnouncementDedupTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/cimm_maintenance_announcements.php';
    }

    private function stateEntry(int $facilityId, string $start, string $end): array
    {
        return [
            'CIMM-R-1' => [
                'notification_id' => 1,
                'facility_id' => $facilityId,
                'created_at' => date('c'),
                'start_date' => $start,
                'end_date' => $end,
            ],
        ];
    }

    public function testIdenticalWindowOnSameFacilityIsDetectedAsOverlap(): void
    {
        $state = $this->stateEntry(4, '2026-08-16', '2026-08-16');
        $this->assertTrue(
            frs_cimm_state_has_overlapping_window($state, 4, '2026-08-16', '2026-08-16')
        );
    }

    public function testPartiallyOverlappingWindowIsDetected(): void
    {
        $state = $this->stateEntry(4, '2026-08-10', '2026-08-20');
        $this->assertTrue(
            frs_cimm_state_has_overlapping_window($state, 4, '2026-08-18', '2026-08-25')
        );
    }

    public function testWindowsWithinGraceDaysAreTreatedAsTheSameIssue(): void
    {
        $state = $this->stateEntry(4, '2026-08-01', '2026-08-05');
        // 2 days after the existing window ends -- inside the default 3-day grace.
        $this->assertTrue(
            frs_cimm_state_has_overlapping_window($state, 4, '2026-08-07', '2026-08-09')
        );
    }

    public function testWindowsFarApartAreNotOverlapping(): void
    {
        $state = $this->stateEntry(4, '2026-08-01', '2026-08-05');
        // 10 days after the existing window ends -- well outside the grace period.
        $this->assertFalse(
            frs_cimm_state_has_overlapping_window($state, 4, '2026-08-15', '2026-08-16')
        );
    }

    public function testSameWindowOnDifferentFacilityIsNotAMatch(): void
    {
        $state = $this->stateEntry(4, '2026-08-16', '2026-08-16');
        $this->assertFalse(
            frs_cimm_state_has_overlapping_window($state, 9, '2026-08-16', '2026-08-16')
        );
    }

    public function testEmptyStateNeverMatches(): void
    {
        $this->assertFalse(
            frs_cimm_state_has_overlapping_window([], 4, '2026-08-16', '2026-08-16')
        );
    }

    public function testEmptyStartDateNeverMatches(): void
    {
        $state = $this->stateEntry(4, '2026-08-16', '2026-08-16');
        $this->assertFalse(
            frs_cimm_state_has_overlapping_window($state, 4, '', '')
        );
    }

    public function testLegacyStateEntryWithNoStoredWindowIsIgnored(): void
    {
        // Entries created before this fix have no start_date/end_date -- they
        // must not false-positive-block every future sync for that facility.
        $state = [
            'CIMM-S-1' => [
                'notification_id' => 1,
                'facility_id' => 4,
                'created_at' => date('c'),
            ],
        ];
        $this->assertFalse(
            frs_cimm_state_has_overlapping_window($state, 4, '2026-08-16', '2026-08-16')
        );
    }
}
