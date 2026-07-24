<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Resident booking limits (config/reservation_helpers.php) against an
 * in-memory SQLite database: per-day, per-week, per-month, per-year,
 * upcoming-active cap, and the Staff/Admin exemption.
 */
final class ResidentBookingLimitsTest extends TestCase
{
    private const ACTIVE_SQL = '"pending","approved"';
    private const RESIDENT_ID = 1;
    private const STAFF_ID = 2;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            reservation_date TEXT NOT NULL,
            status TEXT NOT NULL
        )');
        $this->pdo->exec("INSERT INTO users (id, role) VALUES (1, 'Resident'), (2, 'Staff')");
    }

    private function seedReservation(int $userId, string $date, string $status = 'approved'): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO reservations (user_id, reservation_date, status) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $date, $status]);
    }

    private function validate(int $userId, string $date): array
    {
        return frs_validate_resident_booking_limits($this->pdo, $userId, $date, self::ACTIVE_SQL);
    }

    /** A past date, so seeded rows never trip the upcoming-active cap. */
    private function pastDate(string $offset): string
    {
        return date('Y-m-d', strtotime($offset));
    }

    public function testCleanResidentPasses(): void
    {
        $result = $this->validate(self::RESIDENT_ID, $this->pastDate('-10 days'));
        $this->assertTrue($result['ok']);
    }

    public function testUpcomingActiveCap(): void
    {
        $cap = frs_resident_booking_limit_config()['max_upcoming_active'];
        for ($i = 1; $i <= $cap; $i++) {
            $this->seedReservation(self::RESIDENT_ID, date('Y-m-d', strtotime("+{$i} days")), 'pending');
        }
        $result = $this->validate(self::RESIDENT_ID, date('Y-m-d', strtotime('+30 days')));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('active upcoming', $result['message']);
    }

    public function testPerDayLimit(): void
    {
        $date = $this->pastDate('-10 days');
        for ($i = 0; $i < frs_resident_booking_limit_config()['per_day']; $i++) {
            $this->seedReservation(self::RESIDENT_ID, $date);
        }
        $result = $this->validate(self::RESIDENT_ID, $date);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('per day', $result['message']);
    }

    public function testDeniedReservationsDoNotCountTowardLimits(): void
    {
        $date = $this->pastDate('-10 days');
        $this->seedReservation(self::RESIDENT_ID, $date, 'denied');
        $this->seedReservation(self::RESIDENT_ID, $date, 'cancelled');
        $result = $this->validate(self::RESIDENT_ID, $date);
        $this->assertTrue($result['ok'], 'denied/cancelled must not consume the per-day limit');
    }

    public function testPerWeekLimit(): void
    {
        $requestDate = $this->pastDate('-30 days');
        $perWeek = frs_resident_booking_limit_config()['per_week'];
        // Distinct days inside the rolling 7-day window (requestDate-6 .. requestDate),
        // none on the request date itself so per-day passes first.
        for ($i = 1; $i <= $perWeek; $i++) {
            $this->seedReservation(self::RESIDENT_ID, date('Y-m-d', strtotime($requestDate . " -{$i} days")));
        }
        $result = $this->validate(self::RESIDENT_ID, $requestDate);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('per week', $result['message']);
    }

    public function testPerMonthLimit(): void
    {
        $requestDate = $this->pastDate('-60 days');
        $perMonth = frs_resident_booking_limit_config()['per_month'];
        // Inside the rolling 30-day window but outside the 7-day window
        // (requestDate-29 .. requestDate-7) so only the month check trips.
        for ($i = 0; $i < $perMonth; $i++) {
            $this->seedReservation(self::RESIDENT_ID, date('Y-m-d', strtotime($requestDate . ' -' . (7 + $i) . ' days')));
        }
        $result = $this->validate(self::RESIDENT_ID, $requestDate);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('per month', $result['message']);
    }

    public function testPerYearLimit(): void
    {
        $lastYear = (int)date('Y') - 1;
        $requestDate = $lastYear . '-12-31';
        $perYear = frs_resident_booking_limit_config()['per_year'];
        // Spread across Jan–Nov of that year, leaving December empty so the
        // day/week/month windows around the request date stay clear.
        for ($i = 0; $i < $perYear; $i++) {
            $month = ($i % 11) + 1;
            $day = intdiv($i, 11) + 1;
            $this->seedReservation(self::RESIDENT_ID, sprintf('%d-%02d-%02d', $lastYear, $month, $day));
        }
        $result = $this->validate(self::RESIDENT_ID, $requestDate);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('per year', $result['message']);
    }

    public function testStaffAreExemptFromLimits(): void
    {
        $date = date('Y-m-d', strtotime('+3 days'));
        for ($i = 0; $i < 10; $i++) {
            $this->seedReservation(self::STAFF_ID, date('Y-m-d', strtotime("+{$i} days")));
        }
        $result = $this->validate(self::STAFF_ID, $date);
        $this->assertTrue($result['ok']);
    }

    public function testLimitsApplyHelperByRole(): void
    {
        $this->assertTrue(frs_booking_limits_apply_to_user($this->pdo, self::RESIDENT_ID));
        $this->assertFalse(frs_booking_limits_apply_to_user($this->pdo, self::STAFF_ID));
        $this->assertFalse(frs_booking_limits_apply_to_user($this->pdo, 0));
    }
}
