<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationPreferencesTest extends TestCase
{
    public function test_default_preferences_enable_core_channels(): void
    {
        $defaults = frs_default_notification_preferences();
        $this->assertTrue($defaults['booking_in_app']);
        $this->assertTrue($defaults['booking_email']);
        $this->assertTrue($defaults['booking_sms']);
        $this->assertTrue($defaults['reminder_in_app']);
        $this->assertTrue($defaults['reminder_email']);
        $this->assertFalse($defaults['reminder_sms']);
    }

    public function test_user_wants_notification_defaults_when_no_user(): void
    {
        $this->assertTrue(frs_user_wants_notification(0, 'booking', 'in_app'));
    }
}
