<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FlashHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        unset($_SERVER['HTTP_X_FRS_PARTIAL'], $_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    public function test_flash_set_and_take_round_trip(): void
    {
        frs_flash_success('Saved!');
        $this->assertSame(['message' => 'Saved!', 'type' => 'success'], frs_flash_take());
    }

    public function test_take_clears_the_slot(): void
    {
        frs_flash_error('Nope');
        frs_flash_take();
        $this->assertNull(frs_flash_take());
    }

    public function test_last_write_wins(): void
    {
        frs_flash_success('first');
        frs_flash_error('second');
        $this->assertSame(['message' => 'second', 'type' => 'error'], frs_flash_take());
    }

    public function test_build_toast_header_encodes_json(): void
    {
        $value = frs_flash_build_toast_header(['message' => 'Réservation saved — 100%', 'type' => 'success']);
        $decoded = json_decode(rawurldecode($value), true);
        $this->assertSame('Réservation saved — 100%', $decoded['message']);
        $this->assertSame('success', $decoded['type']);
    }

    public function test_ajax_form_request_detection(): void
    {
        $this->assertFalse(frs_flash_is_ajax_form_request());
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'FRSAjaxForm';
        $this->assertTrue(frs_flash_is_ajax_form_request());
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $_SERVER['HTTP_X_FRS_PARTIAL'] = 'some-region';
        $this->assertTrue(frs_flash_is_ajax_form_request());
    }
}
