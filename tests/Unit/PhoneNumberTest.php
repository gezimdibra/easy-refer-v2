<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protocol Section 2.1 (validation): every accepted format collapses to
 * one canonical E.164 identity (spec Section 3.1).
 */
class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function formats(): array
    {
        return [
            'e164 plain' => ['+12125550147', '+12125550147'],
            'parenthesized' => ['(212) 555-0147', '+12125550147'],
            'dashes' => ['212-555-0147', '+12125550147'],
            'dots' => ['212.555.0147', '+12125550147'],
            'bare ten digits' => ['2125550147', '+12125550147'],
            'leading one' => ['1-212-555-0147', '+12125550147'],
            'plus with spaces' => ['+1 212 555 0147', '+12125550147'],
            'international kept' => ['+44 20 7946 0958', '+442079460958'],
            '001 prefix us' => ['0012125550147', '+12125550147'],
            '001 prefix international' => ['0044 20 7946 0958', '+442079460958'],
        ];
    }

    #[DataProvider('formats')]
    public function test_formats_collapse_to_one_identity(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalizeToE164($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'too short' => ['123'],
            'seven digits' => ['555-0147'],
            'letters' => ['not a phone'],
            'plus only' => ['+'],
            'double plus' => ['++1212'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_inputs_are_rejected(string $input): void
    {
        $this->assertNull(PhoneNumber::normalizeToE164($input));
    }

    public function test_null_is_rejected(): void
    {
        $this->assertNull(PhoneNumber::normalizeToE164(null));
    }
}
