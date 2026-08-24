<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * config/mobile_jwt.php -- the Resident Companion mobile API's bearer token.
 * Covers the fail-loud-when-unconfigured fix (no more hardcoded fallback
 * secret) plus the actual encode/decode contract it protects.
 */
final class MobileJwtTest extends TestCase
{
    private ?string $originalEnvValue;
    private ?string $originalServerValue;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/mobile_jwt.php';
        $this->originalEnvValue = $_ENV['MOBILE_JWT_SECRET'] ?? null;
        $this->originalServerValue = $_SERVER['MOBILE_JWT_SECRET'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->setSecret($this->originalEnvValue, $this->originalServerValue);
    }

    private function setSecret(?string $envValue, ?string $serverValue = null): void
    {
        $serverValue ??= $envValue;
        if ($envValue === null) {
            unset($_ENV['MOBILE_JWT_SECRET']);
        } else {
            $_ENV['MOBILE_JWT_SECRET'] = $envValue;
        }
        if ($serverValue === null) {
            unset($_SERVER['MOBILE_JWT_SECRET']);
        } else {
            $_SERVER['MOBILE_JWT_SECRET'] = $serverValue;
        }
        putenv($envValue === null ? 'MOBILE_JWT_SECRET' : 'MOBILE_JWT_SECRET=' . $envValue);
    }

    public function testThrowsWhenSecretIsNotConfigured(): void
    {
        $this->setSecret(null);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MOBILE_JWT_SECRET is not configured');
        frs_mobile_jwt_secret();
    }

    public function testEncodeDecodeRoundTripPreservesPayload(): void
    {
        $this->setSecret('unit-test-secret-value');
        $token = frs_mobile_jwt_encode(['sub' => 42, 'role' => 'Resident'], 900);
        $decoded = frs_mobile_jwt_decode($token);

        $this->assertIsArray($decoded);
        $this->assertSame(42, $decoded['sub']);
        $this->assertSame('Resident', $decoded['role']);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->setSecret('unit-test-secret-value');
        $token = frs_mobile_jwt_encode(['sub' => 1], -10);
        $this->assertNull(frs_mobile_jwt_decode($token));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $this->setSecret('unit-test-secret-value');
        $token = frs_mobile_jwt_encode(['sub' => 1], 900);
        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);
        $this->assertNull(frs_mobile_jwt_decode(implode('.', $parts)));
    }

    public function testTokenSignedWithDifferentSecretIsRejected(): void
    {
        $this->setSecret('secret-a');
        $token = frs_mobile_jwt_encode(['sub' => 1], 900);

        $this->setSecret('secret-b');
        $this->assertNull(frs_mobile_jwt_decode($token));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->setSecret('unit-test-secret-value');
        $this->assertNull(frs_mobile_jwt_decode('not-a-jwt'));
        $this->assertNull(frs_mobile_jwt_decode('only.two-parts'));
    }
}
