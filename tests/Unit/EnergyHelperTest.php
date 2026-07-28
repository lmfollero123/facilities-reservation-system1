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
            'consumption_kwh' => '120.00',
            'rate_per_kwh' => '12.35',
            'notes' => 'July reading',
            'recorded_by_name' => 'Juan Dela Cruz',
            'recorded_by_email' => 'juan@example.test',
        ];

        $payload = frs_energy_build_reading_payload($reading, 9);

        $this->assertSame(9, $payload['facility_id']);
        $this->assertSame(2026, $payload['year']);
        $this->assertSame(7, $payload['month']);
        $this->assertSame(500.0, $payload['previous_reading_kwh']);
        $this->assertSame(620.0, $payload['current_reading_kwh']);
        $this->assertSame(12.35, $payload['rate_per_kwh']);
        $this->assertSame(1482.0, $payload['energy_cost']);
        $this->assertSame('2026-07-21', $payload['reading_date']);
        $this->assertSame('CPRF-42', $payload['external_ref']);
        $this->assertSame('July reading', $payload['notes']);
        $this->assertSame('Juan Dela Cruz', $payload['recorded_by_name']);
        $this->assertSame('juan@example.test', $payload['recorded_by_email']);
    }

    public function test_recommendation_pull_rebuilds_an_empty_cache(): void
    {
        $this->assertFalse(frs_energy_should_use_recommendation_watermark('2026-07-26 03:51:41', 0));
        $this->assertFalse(frs_energy_should_use_recommendation_watermark(null, 2));
        $this->assertTrue(frs_energy_should_use_recommendation_watermark('2026-07-26 03:51:41', 2));
    }

    public function test_recommendation_progress_input_is_normalized(): void
    {
        $parsed = frs_energy_parse_recommendation_progress([
            'implementation_status' => 'implemented',
            'actual_savings_kwh' => '84.567',
            'implementation_notes' => '  Lighting schedule corrected.  ',
        ]);

        $this->assertSame('implemented', $parsed['implementation_status']);
        $this->assertSame(84.57, $parsed['actual_savings_kwh']);
        $this->assertSame('Lighting schedule corrected.', $parsed['implementation_notes']);
    }

    public function test_recommendation_progress_rejects_verification_from_facilities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        frs_energy_parse_recommendation_progress([
            'implementation_status' => 'verified',
        ]);
    }

    public function test_parse_profile_row_maps_all_fields(): void
    {
        $row = [
            'facility_external_ref' => 501,
            'energy_facility_id' => 14,
            'main_meter_name' => 'Bernardo Court Main Meter',
            'electric_meter_no' => 'MTR-0042',
            'utility_provider' => 'Meralco',
            'contract_account_no' => '1234-5678',
            'main_energy_source' => 'Grid',
            'backup_power' => 'Generator',
            'transformer_capacity' => '75 kVA',
            'number_of_meters' => 3,
            'baseline_kwh' => '7820.00',
            'engineer_approved' => true,
            'baseline_locked' => true,
            'baseline_source' => 'Manual entry',
            'updated_at' => '2026-07-26T08:00:00+00:00',
        ];

        $parsed = frs_energy_parse_profile_row($row);

        $this->assertSame(501, $parsed['facility_id']);
        $this->assertSame('Bernardo Court Main Meter', $parsed['main_meter_name']);
        $this->assertSame('MTR-0042', $parsed['electric_meter_no']);
        $this->assertSame('Meralco', $parsed['utility_provider']);
        $this->assertSame('1234-5678', $parsed['contract_account_no']);
        $this->assertSame('Grid', $parsed['main_energy_source']);
        $this->assertSame('Generator', $parsed['backup_power']);
        $this->assertSame('75 kVA', $parsed['transformer_capacity']);
        $this->assertSame(3, $parsed['number_of_meters']);
        $this->assertSame(7820.0, $parsed['baseline_kwh']);
        $this->assertTrue($parsed['engineer_approved']);
        $this->assertTrue($parsed['baseline_locked']);
        $this->assertSame('Manual entry', $parsed['baseline_source']);
        // Input is UTC (+00:00 offset); this app converts all stored timestamps to
        // Manila-local (UTC+8) per its global date_default_timezone_set('Asia/Manila')
        // convention, matching how every other timestamp in this codebase is stored/
        // displayed — so 08:00 UTC becomes 16:00 local.
        $this->assertSame('2026-07-26 16:00:00', $parsed['energy_updated_at']);
    }

    public function test_parse_profile_row_handles_null_optional_fields(): void
    {
        $row = [
            'facility_external_ref' => 501,
            'main_meter_name' => null,
            'electric_meter_no' => null,
            'utility_provider' => null,
            'contract_account_no' => null,
            'main_energy_source' => null,
            'backup_power' => null,
            'transformer_capacity' => null,
            'number_of_meters' => null,
            'baseline_kwh' => null,
            'engineer_approved' => false,
            'baseline_locked' => false,
            'baseline_source' => null,
            'updated_at' => null,
        ];

        $parsed = frs_energy_parse_profile_row($row);

        $this->assertSame(501, $parsed['facility_id']);
        $this->assertNull($parsed['main_meter_name']);
        $this->assertNull($parsed['electric_meter_no']);
        $this->assertNull($parsed['baseline_kwh']);
        $this->assertFalse($parsed['engineer_approved']);
        $this->assertFalse($parsed['baseline_locked']);
        $this->assertNull($parsed['energy_updated_at']);
    }

    public function test_parse_profile_row_returns_null_without_facility_external_ref(): void
    {
        $this->assertNull(frs_energy_parse_profile_row(['utility_provider' => 'Meralco']));
        $this->assertNull(frs_energy_parse_profile_row(['facility_external_ref' => 'not-a-number']));
    }

    public function test_parse_profile_row_handles_malformed_updated_at(): void
    {
        $row = ['facility_external_ref' => 501, 'updated_at' => 'not a valid date'];
        $parsed = frs_energy_parse_profile_row($row);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['energy_updated_at']);
    }
}
