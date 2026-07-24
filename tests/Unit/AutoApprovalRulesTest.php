<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The eight auto-approval conditions (config/auto_approval_rules.php).
 * These are the barangay-defined rules the thesis documents in section 5.4;
 * each test flips exactly one condition against a known-good baseline.
 */
final class AutoApprovalRulesTest extends TestCase
{
    private const TODAY = '2026-07-24';

    /** A facility/request combination that passes every condition. */
    private function evaluate(array $overrides = []): array
    {
        $args = array_merge([
            'facility' => [
                'auto_approve' => 1,
                'capacity_threshold' => 100,
                'max_duration_hours' => 8.0,
                'capacity' => '200',
                'status' => 'available',
            ],
            'blackout' => null,
            'existingApprovedSlots' => [],
            'hasRecentSevereViolations' => false,
            'user' => ['is_verified' => 1, 'role' => 'Resident'],
            'reservationDate' => '2026-07-30',
            'timeSlot' => '08:00 - 12:00',
            'expectedAttendees' => 50,
            'isCommercial' => false,
            'advanceBookingWindowDays' => 60,
            'today' => self::TODAY,
        ], $overrides);

        return frs_auto_approval_rules(
            $args['facility'],
            $args['blackout'],
            $args['existingApprovedSlots'],
            $args['hasRecentSevereViolations'],
            $args['user'],
            $args['reservationDate'],
            $args['timeSlot'],
            $args['expectedAttendees'],
            $args['isCommercial'],
            $args['advanceBookingWindowDays'],
            $args['today']
        );
    }

    public function testAllConditionsPassAutoApproves(): void
    {
        $result = $this->evaluate();
        $this->assertTrue($result['eligible']);
        $this->assertTrue($result['auto_approve']);
        $this->assertSame('All conditions met for auto-approval', $result['reason']);
        foreach ($result['conditions'] as $key => $condition) {
            $this->assertTrue($condition['passed'], "condition {$key} should pass");
        }
    }

    public function testFacilityNotFound(): void
    {
        $result = $this->evaluate(['facility' => null]);
        $this->assertFalse($result['eligible']);
        $this->assertSame('Facility not found', $result['reason']);
    }

    public function testUnavailableFacilityShortCircuits(): void
    {
        $result = $this->evaluate(['facility' => ['status' => 'maintenance', 'auto_approve' => 1]]);
        $this->assertFalse($result['eligible']);
        $this->assertSame('Facility is not available', $result['reason']);
        $this->assertSame([], $result['conditions']);
    }

    public function testCondition1FacilityFlagOff(): void
    {
        $result = $this->evaluate(['facility' => [
            'auto_approve' => 0, 'capacity_threshold' => 100,
            'max_duration_hours' => 8.0, 'capacity' => '200', 'status' => 'available',
        ]]);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['auto_approve']);
        $this->assertFalse($result['conditions']['facility_auto_approve_enabled']['passed']);
        $this->assertSame('Facility does not allow auto-approval', $result['reason']);
    }

    public function testCondition2BlackoutDate(): void
    {
        $result = $this->evaluate(['blackout' => ['id' => 7, 'reason' => 'Barangay fiesta']]);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['not_in_blackout']['passed']);
        $this->assertStringContainsString('Barangay fiesta', $result['conditions']['not_in_blackout']['message']);
        $this->assertSame('Reservation date is blacked out', $result['reason']);
    }

    public function testCondition3DurationOverLimit(): void
    {
        $result = $this->evaluate(['timeSlot' => '08:00 - 18:00']); // 10h > 8h limit
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['duration_within_limit']['passed']);
        $this->assertSame('Reservation duration exceeds allowed limit', $result['reason']);
    }

    public function testCondition3NoLimitConfiguredAlwaysPasses(): void
    {
        $result = $this->evaluate(['facility' => [
            'auto_approve' => 1, 'capacity_threshold' => 100,
            'max_duration_hours' => null, 'capacity' => '200', 'status' => 'available',
        ], 'timeSlot' => '06:00 - 22:00']);
        $this->assertTrue($result['conditions']['duration_within_limit']['passed']);
    }

    public function testCondition4AttendeesOverThreshold(): void
    {
        $result = $this->evaluate(['expectedAttendees' => 150]); // threshold 100
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['attendees_within_capacity']['passed']);
        $this->assertSame('Expected attendees exceed capacity threshold', $result['reason']);
    }

    public function testCondition4UnspecifiedAttendeesPass(): void
    {
        $result = $this->evaluate(['expectedAttendees' => null]);
        $this->assertTrue($result['conditions']['attendees_within_capacity']['passed']);
    }

    public function testCondition5CommercialRequiresManualReview(): void
    {
        $result = $this->evaluate(['isCommercial' => true]);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['non_commercial']['passed']);
        $this->assertSame('Commercial reservations require manual approval', $result['reason']);
    }

    public function testCondition6OverlappingApprovedBookingConflicts(): void
    {
        $result = $this->evaluate(['existingApprovedSlots' => ['10:00 - 14:00']]);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['no_conflict']['passed']);
        $this->assertSame('Conflicts with existing approved reservation', $result['reason']);
    }

    public function testCondition6BackToBackBookingDoesNotConflict(): void
    {
        $result = $this->evaluate(['existingApprovedSlots' => ['12:00 - 16:00']]);
        $this->assertTrue($result['conditions']['no_conflict']['passed']);
        $this->assertTrue($result['auto_approve']);
    }

    public function testCondition7SevereViolationsBlock(): void
    {
        $result = $this->evaluate(['hasRecentSevereViolations' => true]);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['no_violations']['passed']);
        $this->assertSame('User has previous violations requiring manual review', $result['reason']);
    }

    public function testUnverifiedResidentBlocked(): void
    {
        $result = $this->evaluate(['user' => ['is_verified' => 0, 'role' => 'Resident']]);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['user_verified']['passed']);
    }

    public function testStaffAutoVerifiedEvenWithoutId(): void
    {
        $result = $this->evaluate(['user' => ['is_verified' => 0, 'role' => 'Staff']]);
        $this->assertTrue($result['conditions']['user_verified']['passed']);
        $this->assertTrue($result['auto_approve']);
    }

    public function testCondition8PastDateOutsideWindow(): void
    {
        $result = $this->evaluate(['reservationDate' => '2026-07-20']); // before today
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['conditions']['within_advance_window']['passed']);
        $this->assertSame('Reservation date is outside advance booking window', $result['reason']);
    }

    public function testCondition8TooFarAheadOutsideWindow(): void
    {
        $result = $this->evaluate(['reservationDate' => '2026-09-23']); // 61 days out
        $this->assertFalse($result['conditions']['within_advance_window']['passed']);
    }

    public function testCondition8WindowBoundariesInclusive(): void
    {
        $this->assertTrue($this->evaluate(['reservationDate' => self::TODAY])['eligible']);
        $this->assertTrue($this->evaluate(['reservationDate' => '2026-09-22'])['eligible']); // exactly +60
    }

    public function testFirstFailureWinsAsPrimaryReason(): void
    {
        // Blackout (condition 2) and commercial (condition 5) both fail:
        // the earlier condition's reason must be reported.
        $result = $this->evaluate([
            'blackout' => ['id' => 1, 'reason' => 'Holiday'],
            'isCommercial' => true,
        ]);
        $this->assertSame('Reservation date is blacked out', $result['reason']);
        $this->assertFalse($result['conditions']['not_in_blackout']['passed']);
        $this->assertFalse($result['conditions']['non_commercial']['passed']);
    }
}
