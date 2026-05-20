<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecurityHelpersTest extends TestCase
{
    public function test_csrf_token_generation_and_verify(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = generateCSRFToken();
        $this->assertNotEmpty($token);
        $this->assertTrue(verifyCSRFToken($token));
        $this->assertFalse(verifyCSRFToken('invalid-token'));
    }

    public function test_password_validation_requires_length(): void
    {
        $errors = validatePassword('short');
        $this->assertNotEmpty($errors);
    }
}
