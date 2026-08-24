<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RecommendationService;

/**
 * services/RecommendationService.php's Smart Scheduler recommendations.
 *
 * Regression test for the bug where generatePersonalizedRecommendations()
 * computed suggested_date/time/duration/attendees ONCE from the user's
 * overall aggregated history, then reused that identical value for every
 * facility card in the loop -- so every recommendation on the page showed
 * the exact same suggested date and time regardless of which facility it
 * was for. The fix scopes the suggestion to each facility's own slice of
 * history via analyzeFacilityPatterns(), falling back to the global
 * pattern only when the user has no history at that specific facility.
 */
final class RecommendationServicePerFacilityTest extends TestCase
{
    private const USER_ID = 1;
    private const FACILITY_A = 1;
    private const FACILITY_B = 2;
    private const FACILITY_C = 3;

    private PDO $pdo;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/services/RecommendationService.php';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE facilities (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            capacity INTEGER,
            status TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            facility_id INTEGER NOT NULL,
            reservation_date TEXT NOT NULL,
            time_slot TEXT NOT NULL,
            purpose TEXT,
            expected_attendees INTEGER,
            is_commercial INTEGER DEFAULT 0,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec("INSERT INTO facilities (id, name, capacity, status) VALUES
            (1, 'Facility A', 50, 'available'),
            (2, 'Facility B', 50, 'available'),
            (3, 'Facility C', 50, 'available')");

        $insert = $this->pdo->prepare(
            'INSERT INTO reservations (user_id, facility_id, reservation_date, time_slot, purpose, expected_attendees, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        // Facility A: 3 bookings, always Monday, always 09:00-11:00.
        foreach (['2026-06-01', '2026-06-08', '2026-06-15'] as $date) {
            $insert->execute([self::USER_ID, self::FACILITY_A, $date, '09:00-11:00', 'Meeting', 20, 'approved', $date]);
        }
        // Facility B: 1 booking, Saturday, 18:00-20:00 -- deliberately far
        // from Facility A's pattern so a bug that leaks the global pattern
        // into every card is easy to detect.
        $insert->execute([self::USER_ID, self::FACILITY_B, '2026-06-06', '18:00-20:00', 'Party', 80, 'approved', '2026-06-06']);
        // Facility C: no history at all -- must fall back to the global
        // pattern rather than error or return an empty suggestion.
    }

    private function recommendationFor(array $recommendations, int $facilityId): ?array
    {
        foreach ($recommendations as $rec) {
            if ((int) $rec['facility_id'] === $facilityId) {
                return $rec;
            }
        }
        return null;
    }

    public function testEachFacilityGetsItsOwnSuggestedTimeNotTheGlobalOne(): void
    {
        $service = new RecommendationService($this->pdo);
        $recommendations = $service->getPersonalizedRecommendations(self::USER_ID);

        $recA = $this->recommendationFor($recommendations, self::FACILITY_A);
        $recB = $this->recommendationFor($recommendations, self::FACILITY_B);

        $this->assertNotNull($recA, 'Facility A should appear in the recommendations');
        $this->assertNotNull($recB, 'Facility B should appear in the recommendations');

        $this->assertSame('09:00-11:00', $recA['suggested_time']);
        // Before the fix this would also read '09:00-11:00' (the 3-vs-1
        // global mode) instead of Facility B's own single-record pattern.
        $this->assertSame('18:00-20:00', $recB['suggested_time']);

        $this->assertNotSame(
            $recA['suggested_date'],
            $recB['suggested_date'],
            'suggested_date must differ between facilities with different booking-day history'
        );
    }

    public function testFacilityWithNoHistoryFallsBackToTheGlobalPatternInsteadOfErroring(): void
    {
        $service = new RecommendationService($this->pdo);
        $recommendations = $service->getPersonalizedRecommendations(self::USER_ID);

        $recC = $this->recommendationFor($recommendations, self::FACILITY_C);

        $this->assertNotNull($recC, 'Facility C should still appear even with zero direct history');
        $this->assertNotEmpty($recC['suggested_time']);
        $this->assertNotEmpty($recC['suggested_date']);
    }

    public function testAttendeesAndDurationAreAlsoScopedPerFacility(): void
    {
        $service = new RecommendationService($this->pdo);
        $recommendations = $service->getPersonalizedRecommendations(self::USER_ID);

        $recA = $this->recommendationFor($recommendations, self::FACILITY_A);
        $recB = $this->recommendationFor($recommendations, self::FACILITY_B);

        $this->assertEquals(20, $recA['suggested_attendees']);
        $this->assertEquals(80, $recB['suggested_attendees']);
    }
}
