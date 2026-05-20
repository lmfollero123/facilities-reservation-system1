<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SmsHelperTest extends TestCase
{
    public function test_normalize_philippine_mobile_from_09_format(): void
    {
        $this->assertSame('639171234567', normalizePhilippineMobileNumber('09171234567'));
    }

    public function test_normalize_philippine_mobile_from_63_format(): void
    {
        $this->assertSame('639171234567', normalizePhilippineMobileNumber('639171234567'));
    }

    public function test_normalize_rejects_invalid(): void
    {
        $this->assertNull(normalizePhilippineMobileNumber('123'));
        $this->assertNull(normalizePhilippineMobileNumber(null));
    }
}
