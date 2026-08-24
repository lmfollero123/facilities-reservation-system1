<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * config/reservation_helpers.php: frs_active_booking_statuses_sql() and its
 * effect on frs_validate_resident_booking_limits(). Regression test for the
 * bug where "postponed" reservations -- which still hold a slot per the
 * status lookup metadata (blocks_booking:true) -- were silently excluded
 * from the resident booking-limit counts, letting a resident with a
 * postponed reservation book past their configured limits.
 *
 * frs_active_booking_statuses_sql() caches its result in a function-static
 * for the process lifetime, so every test in this file shares one PDO whose
 * schema introspection query (a MySQL-only "SHOW COLUMNS ... LIKE" syntax)
 * SQLite can't execute -- it hits the catch block and falls back to
 * including every status, which is the deliberately safe default this test
 * locks in.
 */
final class ActiveBookingStatusesTest extends TestCase
{
    private const RESIDENT_ID = 1;

    private PDO $pdo;
    private string $activeSql;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/reservation_helpers.php';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            reservation_date TEXT NOT NULL,
            status TEXT NOT NULL
        )');
        $this->pdo->exec("INSERT INTO users (id, role) VALUES (1, 'Resident')");

        $this->activeSql = frs_active_booking_statuses_sql($this->pdo);
    }

    public function testSchemaIntrospectionFailureFallsBackToIncludingEveryBlockingStatus(): void
    {
        $this->assertStringContainsString('pending', $this->activeSql);
        $this->assertStringContainsString('approved', $this->activeSql);
        $this->assertStringContainsString('pending_payment', $this->activeSql);
        $this->assertStringContainsString('postponed', $this->activeSql);
    }

    public function testPostponedReservationCountsTowardThePerDayLimit(): void
    {
        $date = date('Y-m-d', strtotime('-10 days'));
        $perDay = frs_resident_booking_limit_config()['per_day'];

        // Fill the per-day quota entirely with "postponed" rows -- before the
        // fix these were invisible to the limit check.
        $stmt = $this->pdo->prepare('INSERT INTO reservations (user_id, reservation_date, status) VALUES (?, ?, ?)');
        for ($i = 0; $i < $perDay; $i++) {
            $stmt->execute([self::RESIDENT_ID, $date, 'postponed']);
        }

        $result = frs_validate_resident_booking_limits($this->pdo, self::RESIDENT_ID, $date, $this->activeSql);

        $this->assertFalse($result['ok'], 'postponed reservations must count toward the per-day limit');
        $this->assertStringContainsString('per day', $result['message']);
    }

    public function testDeniedAndCancelledStillDoNotCountWithTheRealHelper(): void
    {
        $date = date('Y-m-d', strtotime('-10 days'));
        $stmt = $this->pdo->prepare('INSERT INTO reservations (user_id, reservation_date, status) VALUES (?, ?, ?)');
        $stmt->execute([self::RESIDENT_ID, $date, 'denied']);
        $stmt->execute([self::RESIDENT_ID, $date, 'cancelled']);

        $result = frs_validate_resident_booking_limits($this->pdo, self::RESIDENT_ID, $date, $this->activeSql);

        $this->assertTrue($result['ok']);
    }
}
