<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_it_normalizes_brazilian_phone_numbers(): void
    {
        $this->assertSame('11999999999', PhoneNumber::normalize('(11) 99999-9999'));
        $this->assertSame('11999999999', PhoneNumber::normalize('+55 (11) 99999-9999'));
        $this->assertSame('1133334444', PhoneNumber::normalize('(11) 3333-4444'));
        $this->assertSame('', PhoneNumber::normalize(null));
        $this->assertSame('', PhoneNumber::normalize(['11999999999']));
    }

    public function test_it_validates_landline_and_mobile_numbers(): void
    {
        $this->assertTrue(PhoneNumber::isValid('1133334444'));
        $this->assertTrue(PhoneNumber::isValid('11999999999'));
        $this->assertFalse(PhoneNumber::isValid('01999999999'));
        $this->assertFalse(PhoneNumber::isValid('119999999'));
        $this->assertFalse(PhoneNumber::isValid(''));
    }

    public function test_it_formats_numbers_for_display(): void
    {
        $this->assertSame('(11) 99999-9999', PhoneNumber::format('11999999999'));
        $this->assertSame('(11) 3333-4444', PhoneNumber::format('1133334444'));
        $this->assertSame('123', PhoneNumber::format('123'));
    }
}
