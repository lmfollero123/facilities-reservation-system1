<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the SMS-as-a-login-OTP-channel feature: users can
 * now enable email and/or SMS independently (plus TOTP for Admin/Staff), so
 * the gating helpers in config/security.php need to OR across all three
 * instead of assuming email is the only non-TOTP channel.
 */
final class SmsOtpChannelTest extends TestCase
{
    private function resident(array $overrides = []): array
    {
        return array_merge([
            'role' => 'Resident',
            'enable_otp' => 1,
            'sms_otp_enabled' => 0,
            'totp_enabled' => 0,
            'totp_secret' => null,
            'mobile' => '639171234567',
        ], $overrides);
    }

    public function testSmsOtpNotEnabledWithoutMobileOnFile(): void
    {
        $user = $this->resident(['sms_otp_enabled' => 1, 'mobile' => '']);
        $this->assertFalse(frs_user_sms_otp_enabled($user));
    }

    public function testSmsOtpEnabledWithFlagAndMobile(): void
    {
        $user = $this->resident(['sms_otp_enabled' => 1]);
        $this->assertTrue(frs_user_sms_otp_enabled($user));
    }

    public function testSecondFactorRequiredWhenOnlySmsEnabled(): void
    {
        $user = $this->resident(['enable_otp' => 0, 'sms_otp_enabled' => 1]);
        $this->assertTrue(frs_login_requires_second_factor($user));
    }

    public function testSecondFactorNotRequiredWhenAllChannelsDisabled(): void
    {
        $user = $this->resident(['enable_otp' => 0, 'sms_otp_enabled' => 0]);
        $this->assertFalse(frs_login_requires_second_factor($user));
    }

    public function testAdminMaySatisfyRequiredSecondFactorWithSmsAlone(): void
    {
        $admin = $this->resident([
            'role' => 'Admin',
            'enable_otp' => 0,
            'sms_otp_enabled' => 1,
        ]);
        $this->assertTrue(frs_user_has_required_second_factor($admin));
    }

    public function testAdminFailsRequiredSecondFactorWithNoChannelsAndNoTotp(): void
    {
        $admin = $this->resident([
            'role' => 'Admin',
            'enable_otp' => 0,
            'sms_otp_enabled' => 0,
        ]);
        $this->assertFalse(frs_user_has_required_second_factor($admin));
    }

    public function testSmsTotpRecoveryOfferedOnlyWhenTotpActiveAndSmsNotAlreadyPrimary(): void
    {
        $admin = $this->resident([
            'role' => 'Admin',
            'enable_otp' => 0,
            'sms_otp_enabled' => 0,
            'totp_enabled' => 1,
            'totp_secret' => 'ABCDEFGHIJKLMNOP',
        ]);
        $this->assertTrue(frs_login_can_request_sms_totp_recovery($admin));

        // If SMS is already the normal enabled channel, it's not "recovery" -- it's primary.
        $admin['sms_otp_enabled'] = 1;
        $this->assertFalse(frs_login_can_request_sms_totp_recovery($admin));
    }

    public function testMayVerifyOtpCodeCoversEitherChannel(): void
    {
        $emailOnly = $this->resident(['enable_otp' => 1, 'sms_otp_enabled' => 0]);
        $this->assertTrue(frs_login_may_verify_otp_code($emailOnly));

        $smsOnly = $this->resident(['enable_otp' => 0, 'sms_otp_enabled' => 1]);
        $this->assertTrue(frs_login_may_verify_otp_code($smsOnly));

        $neither = $this->resident(['enable_otp' => 0, 'sms_otp_enabled' => 0]);
        $this->assertFalse(frs_login_may_verify_otp_code($neither));
    }
}
