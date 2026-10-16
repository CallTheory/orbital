<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function cases(): array
    {
        return [
            'bare ten digits' => ['5551234567', '+15551234567'],
            'formatted' => ['(555) 123-4567', '+15551234567'],
            'eleven with 1' => ['1 555 123 4567', '+15551234567'],
            'e164 already' => ['+15551234567', '+15551234567'],
            'international' => ['+44 20 7946 0958', '+442079460958'],
            'dots' => ['555.123.4567', '+15551234567'],
            'no digits' => ['abc', null],
            'null' => [null, null],
            'empty' => ['', null],
            'plus ten digits kept' => ['+4155551234', '+4155551234'],
        ];
    }

    #[DataProvider('cases')]
    public function test_to_e164(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::toE164($raw));
    }
}
