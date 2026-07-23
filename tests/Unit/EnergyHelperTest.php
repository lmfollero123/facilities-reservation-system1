<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnergyHelperTest extends TestCase
{
    public function test_compute_consumption_returns_difference(): void
    {
        $this->assertSame(120.0, frs_energy_compute_consumption(500, 620));
        $this->assertSame(0.0, frs_energy_compute_consumption('500', '500'));
    }

    public function test_compute_consumption_rejects_invalid_input(): void
    {
        $this->assertNull(frs_energy_compute_consumption(500, 400)); // rollback impossible
        $this->assertNull(frs_energy_compute_consumption(null, 400));
        $this->assertNull(frs_energy_compute_consumption('abc', 400));
        $this->assertNull(frs_energy_compute_consumption(-1, 400)); // negative meter value
    }

    public function test_suggest_match_prefers_exact_name(): void
    {
        $remote = [
            ['id' => 1, 'name' => 'Culiat Covered Court'],
            ['id' => 2, 'name' => 'Barangay Hall'],
        ];

        $match = frs_energy_suggest_match('  barangay   hall ', $remote);
        $this->assertSame(2, $match['id']);
        $this->assertSame(100, $match['score']);
    }

    public function test_suggest_match_falls_back_to_substring(): void
    {
        $remote = [['id' => 7, 'name' => 'Culiat Covered Court (Main)']];

        $match = frs_energy_suggest_match('Culiat Covered Court', $remote);
        $this->assertSame(7, $match['id']);
        $this->assertSame(80, $match['score']);
    }

    public function test_suggest_match_returns_null_below_threshold(): void
    {
        $remote = [['id' => 3, 'name' => 'Water Pumping Station']];

        $this->assertNull(frs_energy_suggest_match('Multipurpose Hall', $remote));
    }

    public function test_build_reading_payload_maps_local_row(): void
    {
        $reading = [
            'id' => 42,
            'year' => 2026,
            'month' => 7,
            'reading_date' => '2026-07-21',
            'previous_reading_kwh' => '500.00',
            'current_reading_kwh' => '620.00',
            'notes' => 'July reading',
            'recorded_by_name' => 'Juan Dela Cruz',
        ];

        $payload = frs_energy_build_reading_payload($reading, 9);

        $this->assertSame(9, $payload['facility_id']);
        $this->assertSame(2026, $payload['year']);
        $this->assertSame(7, $payload['month']);
        $this->assertSame(500.0, $payload['previous_reading_kwh']);
        $this->assertSame(620.0, $payload['current_reading_kwh']);
        $this->assertSame('2026-07-21', $payload['reading_date']);
        $this->assertSame('CPRF-42', $payload['external_ref']);
        $this->assertSame('July reading', $payload['notes']);
        $this->assertSame('Juan Dela Cruz', $payload['recorded_by_name']);
    }
}
