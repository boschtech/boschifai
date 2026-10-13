<?php

namespace Tests\Unit\Support;

use App\Support\Billing\MicroUsd;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MicroUsdTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'whole dollars' => ['12', 12_000_000],
            'int' => [3, 3_000_000],
            'cents' => ['0.10', 100_000],
            'four places (the CLI cost column)' => ['0.1234', 123_400],
            'six places' => ['1.000001', 1_000_001],
            'beyond six places is cut, not rounded through a float' => ['0.1234569', 123_456],
            'negative' => ['-1.5', -1_500_000],
            'surrounding space' => [' 2.5 ', 2_500_000],
            'float cost from the CLI' => [0.2817, 281_700],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_parses_decimal_amounts_exactly(string|int|float $amount, int $micro): void
    {
        $this->assertSame($micro, MicroUsd::parse($amount));
    }

    public function test_it_rejects_anything_that_is_not_a_plain_decimal(): void
    {
        foreach (['', 'abc', '1,5', '1e3', '$5', '1.'] as $bad) {
            try {
                MicroUsd::parse($bad);
                $this->fail("Expected \"{$bad}\" to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unset_values_stay_null(): void
    {
        $this->assertNull(MicroUsd::parseOptional(null));
        $this->assertNull(MicroUsd::parseOptional(''));
        $this->assertSame(500_000, MicroUsd::parseOptional('0.5'));
    }

    public function test_multiplying_rounds_half_up_to_the_micro_dollar(): void
    {
        $this->assertSame(1_500_000, MicroUsd::multiply(1_000_000, 1_500_000));
        $this->assertSame(2, MicroUsd::multiply(3, 500_000), '1.5 micro rounds up');
        $this->assertSame(1, MicroUsd::multiply(3, 333_333), '0.999999 micro rounds up');
        $this->assertSame(0, MicroUsd::multiply(1, 400_000));
        $this->assertSame(0, MicroUsd::multiply(0, 2_000_000));

        $this->expectException(InvalidArgumentException::class);
        MicroUsd::multiply(-1, 1_000_000);
    }

    public function test_it_formats_amounts(): void
    {
        $this->assertSame('12.345600', MicroUsd::format(12_345_600));
        $this->assertSame('0.05', MicroUsd::format(50_000, 2));
        $this->assertSame('-1.50', MicroUsd::format(-1_500_000, 2));
        $this->assertSame('0.0001', MicroUsd::format(123, 4));
        $this->assertSame('7', MicroUsd::format(7_000_000, 0));
    }
}
