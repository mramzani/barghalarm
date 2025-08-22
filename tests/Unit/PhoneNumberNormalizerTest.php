<?php

namespace Tests\Unit;

use App\Services\Telegram\PhoneNumberNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    public function test_normalizes_plus_98_format(): void
    {
        $n = new PhoneNumberNormalizer();
        $this->assertSame('+989121234567', $n->normalizeIranMobile('+989121234567'));
    }

    public function test_normalizes_leading_zero_format(): void
    {
        $n = new PhoneNumberNormalizer();
        $this->assertSame('+989121234567', $n->normalizeIranMobile('09121234567'));
    }

    public function test_rejects_invalid_numbers(): void
    {
        $n = new PhoneNumberNormalizer();
        $this->assertNull($n->normalizeIranMobile('+971501234567'));
        $this->assertNull($n->normalizeIranMobile('12345'));
        $this->assertNull($n->normalizeIranMobile(''));
    }
}


